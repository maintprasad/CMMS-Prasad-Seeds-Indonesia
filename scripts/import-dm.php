<?php
declare(strict_types=1);
/**
 * Impor Daily Monitoring lama (SQLite aplikasi dailymonitoring) ke Equipment Database + modul Daily Monitoring CMMS.
 *   php scripts/import-dm.php [--dm=<path .db>] [--env=.env.turso] [--dry-run]
 *
 * - Template parameter per tipe monitoring (database/snapshot/dm_templates.json) → dm_monitor_types + dm_param_templates
 *   (hanya untuk tipe yang belum ada, supaya perubahan Anda tidak tertimpa saat impor ulang).
 * - Hierarki lama dipetakan ke Equipment Database: unit/area dicocokkan lewat nama (spasi/tanda hubung diabaikan);
 *   equipment dicocokkan lewat nama di area yang sama, bila tidak ada dibuat baru (atribut dm_id menyimpan ID lama).
 * - Parameter lama → equipment_params (legacy_id mencegah duplikat saat impor ulang).
 * - Sesi + nilai → dm_sessions/dm_items (ID lama dipertahankan; sesi hasil impor ditulis ulang tiap kali dijalankan).
 * - PIC lama → technicians (bila belum ada nama yang sama).
 */
require __DIR__ . '/_cli.php';

$dmPath = dirname(__DIR__, 2) . '/dailymonitoring/database/dailymonitoring.db';
foreach ($argv as $a) {
    if (str_starts_with($a, '--dm=')) {
        $dmPath = substr($a, 5);
    }
}
if (!is_file($dmPath)) {
    exit("Database Daily Monitoring tidak ditemukan: $dmPath\n");
}
$dry = cliFlag('dry-run');
$dm = new PDO('sqlite:' . $dmPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
Migrator::run();

$norm = fn (string $s) => preg_replace('~[^a-z0-9]+~', '', strtolower($s));
$write = function (array $st) use ($dry) {
    if (!$dry) {
        foreach (array_chunk($st, 200) as $c) {
            Db::transaction($c);
        }
    }
};

// ── 1. template & tipe monitoring ──
$snap = json_decode((string) @file_get_contents(dirname(__DIR__) . '/database/snapshot/dm_templates.json'), true);
$have = array_column(Db::query('SELECT code FROM dm_monitor_types'), 'code');
$st = [];
$seq = 0;
$newTypes = 0;
foreach ($snap['labels'] ?? [] as $code => $label) {
    $seq++;
    if (in_array($code, $have, true)) {
        continue;
    }
    $newTypes++;
    $st[] = ['INSERT INTO dm_monitor_types (code, label, seq) VALUES (?,?,?)', [$code, trim((string) preg_replace('~^[^\p{L}\p{N}]+~u', '', $label)), $seq]];
    foreach ($snap['templates'][$code] ?? [] as $i => $t) {
        $st[] = ['INSERT INTO dm_param_templates (monitor_type, label, unit, section, type, normal_min, normal_max, warn_min, warn_max, options, seq) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
            [$code, $t['label'], $t['unit'] ?? '', $t['section'] ?? '', $t['type'] ?? 'numeric', $t['normalMin'] ?? null, $t['normalMax'] ?? null, $t['warnMin'] ?? null, $t['warnMax'] ?? null,
                isset($t['options']) ? json_encode($t['options'], JSON_UNESCAPED_UNICODE) : null, $i]];
    }
}
$write($st);
echo "Tipe monitoring baru: $newTypes\n";

// ── 2. unit & area ──
$unitMap = []; // id DM → id CMMS
foreach ($dm->query('SELECT * FROM units') as $u) {
    $row = Db::query('SELECT id FROM units WHERE lower(name) = ? OR lower(code) = ?', [strtolower($u['name']), strtolower($u['name'])])[0]
        ?? Db::query('SELECT id FROM units WHERE replace(replace(lower(name), \' \', \'\'), \'-\', \'\') LIKE ?', ['%' . $norm($u['name'])])[0] ?? null;
    if (!$row && preg_match('~(\d+)~', $u['name'], $m)) {
        $row = Db::query('SELECT id FROM units WHERE code = ?', ['P' . $m[1]])[0] ?? null;
    }
    if (!$row) {
        exit("Unit DM “{$u['name']}” tidak ketemu di Equipment Database. Impor Equipment DB dulu (scripts/import-equipment.php).\n");
    }
    $unitMap[$u['id']] = (int) $row['id'];
}
$areaMap = [];
$newAreas = 0;
foreach ($dm->query('SELECT * FROM areas') as $a) {
    $cu = $unitMap[$a['unit_id']];
    $hit = null;
    foreach (Db::query('SELECT id, name FROM areas WHERE unit_id = ?', [$cu]) as $r) {
        if ($norm($r['name']) === $norm($a['name'])) {
            $hit = (int) $r['id'];
        }
    }
    if (!$hit) {
        $newAreas++;
        if (!$dry) {
            Db::transaction([['INSERT INTO areas (unit_id, name) VALUES (?, ?)', [$cu, $a['name']]]]);
            $hit = (int) Db::query('SELECT MAX(id) m FROM areas')[0]['m'];
        }
    }
    $areaMap[$a['unit_id'] . '|' . $a['id']] = $hit;
}
echo "Area baru dibuat: $newAreas\n";

// ── 3. equipment ──
$eqMap = [];
$matched = 0;
$created = 0;
foreach ($dm->query('SELECT * FROM equipments ORDER BY unit_id, area_id, seq') as $e) {
    $area = $areaMap[$e['unit_id'] . '|' . $e['area_id']];
    $attrs = ['dm_id' => $e['id']];
    $hit = null;
    if ($area) {
        foreach (Db::query('SELECT id, name, code, attrs FROM equipment WHERE area_id = ?', [$area]) as $r) {
            if ($norm($r['name']) === $norm($e['name'])) {
                $hit = $r;
                break;
            }
        }
    }
    if ($hit) {
        $matched++;
        $old = $hit['attrs'] ? (json_decode((string) $hit['attrs'], true) ?: []) : [];
        $write([['UPDATE equipment SET monitor_type = ?, attrs = ?, code = COALESCE(NULLIF(code, \'\'), ?) WHERE id = ?',
            [$e['type'] ?: null, json_encode($old + $attrs, JSON_UNESCAPED_UNICODE), $e['tag'] ?: null, $hit['id']]]]);
        $eqMap[$e['unit_id'] . '|' . $e['id']] = (int) $hit['id'];
    } else {
        $created++;
        if (!$dry) {
            Db::transaction([['INSERT INTO equipment (area_id, name, code, brand, model, category, status, attrs, monitor_type, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)',
                [$area, $e['name'], $e['tag'] ?: null, $e['brand'] ?: null, $e['model'] ?: null, $e['type'] ?: null, 'Active', json_encode($attrs), $e['type'] ?: null, now()]]]);
            $eqMap[$e['unit_id'] . '|' . $e['id']] = (int) Db::query('SELECT MAX(id) m FROM equipment')[0]['m'];
        }
    }
}
echo "Equipment: $matched cocok dengan Equipment DB, $created dibuat baru\n";

// ── 4. parameter ──
$paramMap = [];
$known = array_column(Db::query('SELECT id, legacy_id FROM equipment_params WHERE legacy_id IS NOT NULL'), 'id', 'legacy_id');
$st = [];
$newParams = 0;
foreach ($dm->query('SELECT * FROM equipment_params ORDER BY unit_id, equip_id, seq') as $p) {
    $key = $p['unit_id'] . '|' . $p['equip_id'] . '|' . $p['id'];
    $eq = $eqMap[$p['unit_id'] . '|' . $p['equip_id']] ?? null;
    if (isset($known[$key])) {
        $paramMap[$key] = (int) $known[$key];
        continue;
    }
    if (!$eq) {
        continue;
    }
    $newParams++;
    $st[] = ['INSERT INTO equipment_params (equipment_id, label, unit, section, type, normal_min, normal_max, warn_min, warn_max, options, seq, legacy_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
        [$eq, $p['label'], $p['unit'], $p['section'], $p['type'] === 'status' ? 'status' : 'numeric', $p['normal_min'], $p['normal_max'], $p['warn_min'], $p['warn_max'], $p['options'], (int) $p['seq'], $key]];
}
$write($st);
foreach (Db::query('SELECT id, legacy_id FROM equipment_params WHERE legacy_id IS NOT NULL') as $r) {
    $paramMap[$r['legacy_id']] = (int) $r['id'];
}
echo "Parameter baru: $newParams\n";

// ── 5. PIC → teknisi ──
$techs = array_map('strtolower', array_column(Db::query('SELECT name FROM technicians'), 'name'));
$st = [];
$newPics = 0;
foreach ($dm->query('SELECT name FROM pics ORDER BY seq') as $p) {
    if (!in_array(strtolower($p['name']), $techs, true)) {
        $newPics++;
        $st[] = ['INSERT INTO technicians (name, spec) VALUES (?, ?)', [$p['name'], 'Impor Daily Monitoring']];
    }
}
$write($st);
echo "PIC → teknisi baru: $newPics\n";

// ── 6. sesi & nilai ──
$st = [["DELETE FROM dm_items WHERE session_id IN (SELECT id FROM dm_sessions WHERE source = 'import')"], ["DELETE FROM dm_sessions WHERE source = 'import'"]];
$itemsBySession = [];
foreach ($dm->query('SELECT * FROM session_items ORDER BY session_id, seq') as $i) {
    $itemsBySession[$i['session_id']][] = $i;
}
$nSess = 0;
$nItems = 0;
$skipped = 0;
foreach ($dm->query('SELECT * FROM sessions ORDER BY tanggal, start_time') as $s) {
    $eq = $eqMap[$s['unit_id'] . '|' . $s['equip_id']] ?? null;
    if (!$eq && !$dry) {
        $skipped++;
        continue;
    }
    $items = $itemsBySession[$s['id']] ?? [];
    $sts = array_filter(array_column($items, 'status'));
    $overall = in_array('ALERT', $sts, true) ? 'ALERT' : (in_array('WARNING', $sts, true) ? 'WARNING' : ($sts ? 'OK' : 'PENDING'));
    $cu = $unitMap[$s['unit_id']];
    $ca = $areaMap[$s['unit_id'] . '|' . $s['area_id']];
    $created = $s['created_at'] ?: ($s['tanggal'] . ' 00:00:00');
    $st[] = ['INSERT INTO dm_sessions (id, session_date, equipment_id, unit_id, area_id, pic, start_time, end_time, status, catatan, tindakan, source, created_by, created_at, updated_at)
              VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
        [$s['id'], $s['tanggal'], $eq, $cu, $ca, $s['pic'], $s['start_time'] ?: null, $s['end_time'] ?: null, $overall, $s['catatan'] ?: null, $s['tindakan'] ?: null, 'import', 'import', $created, $s['updated_at'] ?: $created]];
    foreach ($items as $i) {
        $st[] = ['INSERT INTO dm_items (session_id, seq, param_id, label, unit, section, value, status, note) VALUES (?,?,?,?,?,?,?,?,?)',
            [$s['id'], (int) $i['seq'], $paramMap[$s['unit_id'] . '|' . $s['equip_id'] . '|' . $i['param_id']] ?? null, $i['label'], $i['unit'], $i['section'], $i['value'], $i['status'], $i['note'] ?: null]];
        $nItems++;
    }
    $nSess++;
}
$write($st);
echo "Sesi monitoring: $nSess ($nItems nilai)" . ($skipped ? ", $skipped dilewati (equipment tak terpetakan)" : '') . "\n";
if ($dry) {
    echo "--dry-run: tidak ada yang ditulis.\n";
    exit;
}
foreach (['dm_monitor_types', 'dm_param_templates', 'equipment_params', 'dm_sessions', 'dm_items', 'equipment', 'areas', 'technicians'] as $t) {
    echo str_pad($t, 20) . Db::query("SELECT COUNT(*) c FROM $t")[0]['c'] . "\n";
}
