<?php
declare(strict_types=1);
/**
 * Impor Preventive Maintenance lama (cadangan MySQL `pm_backup` dari app PM/Turso) ke CMMS.
 *   php scripts/import-pm.php [--mysql=pm_backup] [--env=.env.turso] [--dry-run]
 *
 * - Tipe & template checklist: bawaan app lama (database/snapshot/pm_defaults.json) ditimpa template kustom di tabel `templates`.
 * - Jadwal → pm_schedules (ID lama dipertahankan). Equipment dicocokkan di Equipment DB lewat unit + area + nama
 *   (spasi/tanda baca diabaikan); bila belum ada dibuat baru (tag lama disimpan di kode & atribut pm_tag).
 * - Laporan → pm_reports (+ butir checklist, sparepart, PPE); ID lama dipertahankan, laporan hasil impor ditulis ulang tiap run.
 * - last_done jadwal diisi dari laporan terbaru equipment-nya.
 * Catatan: cadangan MySQL berisi data sampai saat salinan terakhir dari Turso (lihat serverAt); data live di Turso tidak terbaca dari sini.
 */
require __DIR__ . '/_cli.php';

$dbName = 'pm_backup';
foreach ($argv as $a) {
    if (str_starts_with($a, '--mysql=')) {
        $dbName = substr($a, 8);
    }
}
$dry = cliFlag('dry-run');
$my = new PDO("mysql:host=127.0.0.1;dbname=$dbName;charset=utf8mb4", 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
Migrator::run();
$norm = fn (string $s) => preg_replace('~[^a-z0-9]+~', '', strtolower($s));
$write = function (array $st) use ($dry) {
    if (!$dry) {
        foreach (array_chunk($st, 200) as $c) {
            Db::transaction($c);
        }
    }
};

// ── tipe & template ──
$def = json_decode((string) @file_get_contents(dirname(__DIR__) . '/database/snapshot/pm_defaults.json'), true) ?: ['labels' => [], 'templates' => []];
$labels = $def['labels'];
$items = $def['templates'];
foreach ($my->query('SELECT equipmentType, items FROM templates') as $t) {
    $list = json_decode((string) $t['items'], true);
    if (is_array($list) && $list) {
        $items[$t['equipmentType']] = $list;
    }
}
$used = array_merge(
    $my->query("SELECT DISTINCT equipmentType t FROM schedules WHERE equipmentType != ''")->fetchAll(PDO::FETCH_COLUMN),
    $my->query("SELECT DISTINCT equipmentType t FROM reports WHERE equipmentType != ''")->fetchAll(PDO::FETCH_COLUMN)
);
foreach ($used as $code) {
    $labels[$code] ??= ucwords(str_replace('_', ' ', $code));
}
$haveTypes = array_column(Db::query('SELECT code FROM pm_types'), 'code');
$st = [];
foreach ($labels as $code => $label) {
    if (in_array($code, $haveTypes, true)) {
        continue; // tipe yang sudah ada tidak ditimpa (template bisa sudah Anda ubah)
    }
    $st[] = ['INSERT INTO pm_types (code, label, seq) VALUES (?,?,0)', [$code, $label]];
    foreach ($items[$code] ?? [] as $i => $text) {
        $st[] = ['INSERT INTO pm_template_items (pm_type, seq, text) VALUES (?,?,?)', [$code, $i, (string) $text]];
    }
}
$write($st);
echo 'Tipe PM baru: ' . count(array_diff(array_keys($labels), $haveTypes)) . "\n";

// ── pemetaan unit/area/equipment ──
$unitId = function (string $name): int {
    if (preg_match('~(\d+)~', $name, $m)) {
        $r = Db::query('SELECT id FROM units WHERE code = ?', ['P' . $m[1]])[0] ?? null;
        if ($r) {
            return (int) $r['id'];
        }
    }
    $r = Db::query('SELECT id FROM units WHERE lower(name) = lower(?)', [$name])[0] ?? null;
    return $r ? (int) $r['id'] : throw new RuntimeException("Unit “$name” tidak ketemu — jalankan impor Equipment DB dulu.");
};
$areaCache = [];
$newAreas = 0;
$areaId = function (int $unit, string $name) use (&$areaCache, &$newAreas, $norm, $dry): int {
    $key = $unit . '|' . $norm($name);
    if (isset($areaCache[$key])) {
        return $areaCache[$key];
    }
    foreach (Db::query('SELECT id, name FROM areas WHERE unit_id = ?', [$unit]) as $a) {
        $areaCache[$unit . '|' . $norm($a['name'])] = (int) $a['id'];
    }
    if (!isset($areaCache[$key])) {
        $newAreas++;
        if ($dry) {
            return 0;
        }
        Db::transaction([['INSERT INTO areas (unit_id, name) VALUES (?, ?)', [$unit, $name]]]);
        $areaCache[$key] = (int) Db::query('SELECT MAX(id) m FROM areas')[0]['m'];
    }
    return $areaCache[$key];
};
$eqCache = [];
$newEq = 0;
$matchedEq = 0;
$equipmentId = function (int $area, string $name, string $tag, string $type) use (&$eqCache, &$newEq, &$matchedEq, $norm, $dry): ?int {
    $key = $area . '|' . $norm($name);
    if (isset($eqCache[$key])) {
        return $eqCache[$key];
    }
    foreach (Db::query('SELECT id, name, code, attrs FROM equipment WHERE area_id = ?', [$area]) as $e) {
        if ($norm($e['name']) === $norm($name)) {
            $matchedEq++;
            $attrs = $e['attrs'] ? (json_decode((string) $e['attrs'], true) ?: []) : [];
            if ($tag !== '' && !$dry) {
                Db::transaction([['UPDATE equipment SET pm_type = COALESCE(NULLIF(pm_type, \'\'), ?), code = COALESCE(NULLIF(code, \'\'), ?), attrs = ? WHERE id = ?',
                    [$type ?: null, $tag, json_encode($attrs + ['pm_tag' => $tag], JSON_UNESCAPED_UNICODE), $e['id']]]]);
            }
            return $eqCache[$key] = (int) $e['id'];
        }
    }
    $newEq++;
    if ($dry) {
        return null;
    }
    Db::transaction([['INSERT INTO equipment (area_id, name, code, category, status, attrs, pm_type, updated_at) VALUES (?,?,?,?,?,?,?,?)',
        [$area, $name, $tag ?: null, $type ?: null, 'Active', json_encode(['pm_tag' => $tag], JSON_UNESCAPED_UNICODE), $type ?: null, now()]]]);
    return $eqCache[$key] = (int) Db::query('SELECT MAX(id) m FROM equipment')[0]['m'];
};

// ── jadwal ──
$schedEq = [];   // tagNo → equipment_id
$schedId = [];   // tagNo → schedule id
$st = [];
$ns = 0;
foreach ($my->query("SELECT * FROM schedules WHERE deletedAt IS NULL OR deletedAt = '' ORDER BY id") as $s) {
    $u = $unitId($s['unit']);
    $a = $areaId($u, $s['area']);
    $eq = $equipmentId($a, $s['equipment'], (string) $s['tagNo'], (string) $s['equipmentType']);
    if (!$eq) {
        continue;
    }
    $schedEq[$s['tagNo']] = $eq;
    $schedId[$s['tagNo']] = $s['id'];
    $ns++;
    $nz = fn ($v) => ($v === null || $v === '') ? null : $v;
    $st[] = ['INSERT INTO pm_schedules (id, equipment_id, periode, month, week, pic, mp, hari, notes, start_date, takeover_at, takeover_from, takeover_reason, created_by, created_at, updated_at)
              VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
              ON CONFLICT(id) DO UPDATE SET equipment_id=excluded.equipment_id, periode=excluded.periode, month=excluded.month, week=excluded.week, pic=excluded.pic, mp=excluded.mp, hari=excluded.hari,
                notes=excluded.notes, start_date=excluded.start_date',
        [$s['id'], $eq, $s['periode'] ?: 'Annual', $nz($s['month']), $nz($s['week']), $nz($s['pic']), $nz($s['mp']), $nz($s['hari']), $nz($s['notes']), $nz($s['startDate']),
            $nz($s['takeoverAt']), $nz($s['takeoverFrom']), $nz($s['takeoverReason']), 'import', now(), now()]];
}
$write($st);
echo "Jadwal: $ns\n";

// ── laporan ──
$st = [["DELETE FROM pm_report_items WHERE report_id IN (SELECT id FROM pm_reports WHERE source = 'import')"], ["DELETE FROM pm_report_parts WHERE report_id IN (SELECT id FROM pm_reports WHERE source = 'import')"],
    ["DELETE FROM pm_reports WHERE source = 'import'"]];
$nr = 0;
$nItems = 0;
$skipped = 0;
foreach ($my->query("SELECT * FROM reports WHERE deletedAt IS NULL OR deletedAt = '' ORDER BY date, id") as $r) {
    $u = $unitId($r['unit'] ?: 'Unit 1');
    $eq = $schedEq[$r['tagNo']] ?? null;
    if (!$eq) {
        $eq = $equipmentId($areaId($u, $r['area'] ?: 'Lainnya'), $r['equipmentName'], (string) $r['tagNo'], (string) $r['equipmentType']);
    }
    if (!$eq || !preg_match('~^\d{4}-\d{2}-\d{2}$~', (string) $r['date'])) {
        $skipped++;
        continue;
    }
    $akt = json_decode((string) $r['aktivitas'], true) ?: [];
    $parts = json_decode((string) $r['spareParts'], true) ?: [];
    $ok = count(array_filter($akt, fn ($a) => !empty($a['yes']) && empty($a['no_checked'])));
    $no = count(array_filter($akt, fn ($a) => !empty($a['no_checked'])));
    $area = $areaId($u, $r['area'] ?: 'Lainnya');
    $ts = $r['submittedAt'] ?: ($r['date'] . ' 00:00:00');
    $st[] = ['INSERT INTO pm_reports (id, schedule_id, equipment_id, unit_id, area_id, report_date, periode, week, month, pm_type, teknisi, approval, notes, ppe, items_total, items_ok, items_no, source, created_by, created_at, updated_at)
              VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
        [$r['id'], $schedId[$r['tagNo']] ?? null, $eq, $u, $area ?: null, $r['date'], $r['periode'] ?: null, $r['week'] ?: null, $r['month'] ?: (int) substr($r['date'], 5, 2), $r['equipmentType'] ?: null,
            $r['teknisi'] ?: '-', $r['approval'] ?: null, $r['notes'] ?: null, $r['ppe'] ?: '{}', count($akt), $ok, $no, 'import', 'import', $ts, $r['updatedAt'] ?: $ts]];
    foreach ($akt as $i => $a) {
        $st[] = ['INSERT INTO pm_report_items (report_id, seq, text, yes, no, note) VALUES (?,?,?,?,?,?)',
            [$r['id'], $i, (string) ($a['uraian'] ?? ''), !empty($a['yes']) && empty($a['no_checked']) ? 1 : 0, !empty($a['no_checked']) ? 1 : 0, ($a['keterangan'] ?? '') !== '' ? $a['keterangan'] : null]];
        $nItems++;
    }
    foreach ($parts as $i => $p) {
        if (!empty($p['uraian'])) {
            $st[] = ['INSERT INTO pm_report_parts (report_id, seq, name, spec, qty, uom) VALUES (?,?,?,?,?,?)', [$r['id'], $i, $p['uraian'], ($p['spesifikasi'] ?? '') ?: null, (float) ($p['jumlah'] ?? 1) ?: 1, 'pcs']];
        }
    }
    $nr++;
}
$write($st);
if (!$dry) {
    Db::transaction([['UPDATE pm_schedules SET last_done = (SELECT MAX(r.report_date) FROM pm_reports r WHERE r.equipment_id = pm_schedules.equipment_id) WHERE active = 1']]);
}
echo "Laporan: $nr ($nItems butir checklist), $skipped dilewati\nEquipment: $matchedEq cocok, $newEq dibuat baru; area baru: $newAreas\n";
if (!$dry) {
    foreach (['pm_types', 'pm_template_items', 'pm_schedules', 'pm_reports', 'pm_report_items', 'pm_report_parts', 'equipment', 'areas'] as $t) {
        echo str_pad($t, 20) . Db::query("SELECT COUNT(*) c FROM $t")[0]['c'] . "\n";
    }
}
