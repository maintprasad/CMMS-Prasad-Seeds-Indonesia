<?php
declare(strict_types=1);

/**
 * Pusat laporan lintas modul + pencarian global + notifikasi topbar.
 * Laporan dikembalikan sebagai bagian generik (kpis + tabel) supaya klien bisa menampilkan,
 * mengekspor ke XLSX/PPTX/CSV, atau mencetak PDF tanpa tahu isi tiap modul.
 */
final class ReportApi
{
    private const STAFF = ['admin', 'spv', 'maintenance'];
    private const MODULES = ['wo', 'mw', 'pm', 'dm'];
    private const LIMIT = 5000;

    // ───────────────────────── Pencarian global ─────────────────────────

    public static function search(): array
    {
        $me = Auth::requireRole('admin', 'spv', 'maintenance', 'user');
        $q = trim((string) Http::query('q', ''));
        if (mb_strlen($q) < 2) {
            return ['groups' => []];
        }
        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
        $staff = in_array($me['role'], self::STAFF, true);
        $groups = [];

        $eq = Db::query("SELECT e.id, e.name, e.code, u.name AS unit, a.name AS area FROM equipment e JOIN areas a ON a.id = e.area_id JOIN units u ON u.id = a.unit_id
            WHERE e.name LIKE ? ESCAPE '\\' OR e.code LIKE ? ESCAPE '\\' OR e.serial_no LIKE ? ESCAPE '\\' ORDER BY e.name LIMIT 6", [$like, $like, $like]);
        $groups[] = ['key' => 'eq', 'title' => 'Equipment', 'icon' => 'ti-building-factory-2', 'rows' => array_map(fn ($r) => [
            'label' => $r['name'], 'sub' => trim(($r['code'] ? $r['code'] . ' · ' : '') . $r['unit'] . ' › ' . $r['area']), 'href' => '#/eq/list?id=' . $r['id']], $eq)];

        $w = ["(w.id LIKE ? ESCAPE '\\' OR w.title LIKE ? ESCAPE '\\')"];
        $p = [$like, $like];
        if ($me['role'] === 'user') {
            $w[] = 'w.requestor_id = ?';
            $p[] = $me['id'];
        }
        $wo = Db::query('SELECT w.id, w.title, w.status FROM work_orders w WHERE ' . implode(' AND ', $w) . ' ORDER BY w.created_at DESC LIMIT 6', $p);
        $groups[] = ['key' => 'wo', 'title' => 'Work Order', 'icon' => 'ti-clipboard-list', 'rows' => array_map(fn ($r) => [
            'label' => $r['title'], 'sub' => $r['id'] . ' · ' . $r['status'], 'href' => '#/wo/detail?id=' . rawurlencode($r['id'])], $wo)];

        if ($staff) {
            $it = Db::query("SELECT id, name, qty, uom FROM mw_items WHERE id LIKE ? ESCAPE '\\' OR name LIKE ? ESCAPE '\\' OR brand LIKE ? ESCAPE '\\' ORDER BY name LIMIT 6", [$like, $like, $like]);
            $groups[] = ['key' => 'mw', 'title' => 'MaintWare', 'icon' => 'ti-packages', 'rows' => array_map(fn ($r) => [
                'label' => $r['name'], 'sub' => $r['id'] . ' · stok ' . (float) $r['qty'] . ' ' . $r['uom'], 'href' => '#/mw/items?q=' . rawurlencode($r['id'])], $it)];

            $pm = Db::query("SELECT r.id, r.report_date, e.name FROM pm_reports r JOIN equipment e ON e.id = r.equipment_id
                WHERE r.id LIKE ? ESCAPE '\\' OR e.name LIKE ? ESCAPE '\\' ORDER BY r.report_date DESC LIMIT 5", [$like, $like]);
            $groups[] = ['key' => 'pm', 'title' => 'Laporan PM', 'icon' => 'ti-tool', 'rows' => array_map(fn ($r) => [
                'label' => $r['name'], 'sub' => $r['id'] . ' · ' . $r['report_date'], 'href' => '#/pm/list?id=' . rawurlencode($r['id'])], $pm)];
        }
        return ['groups' => array_values(array_filter($groups, fn ($g) => $g['rows']))];
    }

    // ───────────────────────── Notifikasi ─────────────────────────

    public static function notifications(): array
    {
        $me = Auth::requireRole('admin', 'spv', 'maintenance', 'user');
        $n = fn (string $sql, array $p = []) => (int) Db::query($sql, $p)[0]['c'];
        $today = date('Y-m-d');
        $items = [];
        $add = function (string $level, string $icon, int $count, string $text, string $href) use (&$items) {
            if ($count > 0) {
                $items[] = ['level' => $level, 'icon' => $icon, 'count' => $count, 'text' => $text, 'href' => $href];
            }
        };

        if ($me['role'] === 'user') {
            $add('info', 'ti-checkup-list', $n("SELECT COUNT(*) c FROM work_orders WHERE requestor_id = ? AND status = 'Pending Verification'", [$me['id']]), 'WO Anda menunggu verifikasi', '#/wo/list');
            $add('info', 'ti-progress', $n("SELECT COUNT(*) c FROM work_orders WHERE requestor_id = ? AND status IN ('Open','In Progress','Hold')", [$me['id']]), 'WO Anda masih berjalan', '#/wo/list');
            return ['items' => $items, 'total' => array_sum(array_column($items, 'count'))];
        }

        $add('warn', 'ti-checkup-list', $n("SELECT COUNT(*) c FROM work_orders WHERE status = 'Pending Verification'"), 'WO menunggu verifikasi', '#/wo/list');
        $add('danger', 'ti-alarm', $n("SELECT COUNT(*) c FROM work_orders WHERE status NOT IN ('Closed','Cancelled') AND due_date IS NOT NULL AND due_date <> '' AND substr(due_date,1,10) < ?", [$today]), 'WO melewati tenggat', '#/wo/list');
        $add('info', 'ti-inbox', $n("SELECT COUNT(*) c FROM work_orders WHERE status = 'Open' AND id NOT IN (SELECT wo_id FROM wo_techs)"), 'WO Open belum ada teknisi', '#/wo/list');
        $add('danger', 'ti-box-off', $n('SELECT COUNT(*) c FROM mw_items WHERE qty < min_stock'), 'Item MaintWare di bawah stok minimum', '#/mw/reorder');
        $pm = PreventiveApi::scheduleRows([]);
        $add('danger', 'ti-calendar-exclamation', count(array_filter($pm, fn ($r) => $r['status'] === 'overdue')), 'Jadwal PM terlambat', '#/pm/outstanding');
        $add('warn', 'ti-clipboard-x', $n('SELECT COUNT(*) c FROM pm_reports WHERE items_no > 0 AND wo_id IS NULL'), 'Temuan PM belum dibuat WO', '#/pm/list');
        $since = date('Y-m-d', strtotime('-3 days'));
        $add('danger', 'ti-alert-triangle', $n("SELECT COUNT(*) c FROM dm_sessions WHERE status = 'ALERT' AND wo_id IS NULL AND session_date >= ?", [$since]), 'Monitoring ALERT 3 hari terakhir tanpa WO', '#/dm/findings');
        return ['items' => $items, 'total' => array_sum(array_column($items, 'count'))];
    }

    // ───────────────────────── Pusat laporan ─────────────────────────

    public static function build(): array
    {
        Auth::requireRole(...self::STAFF);
        $mods = array_values(array_intersect(self::MODULES, array_filter(explode(',', (string) Http::query('modules', '')))));
        if (!$mods) {
            throw new HttpError('Pilih minimal satu modul untuk laporan.');
        }
        $from = (string) Http::query('from', date('Y-m-01'));
        $to = (string) Http::query('to', date('Y-m-d'));
        foreach ([$from, $to] as $d) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                throw new HttpError('Format tanggal harus YYYY-MM-DD.');
            }
        }
        if ($from > $to) {
            throw new HttpError('Tanggal awal tidak boleh setelah tanggal akhir.');
        }
        $unit = (int) Http::query('unit_id', '0');
        $unitName = $unit ? (Db::query('SELECT name FROM units WHERE id = ?', [$unit])[0]['name'] ?? '') : 'Semua unit';
        $sections = [];
        foreach ($mods as $m) {
            $sections[] = match ($m) {
                'wo' => self::sectionWO($from, $to, $unit),
                'mw' => self::sectionMW($from, $to, $unit),
                'pm' => self::sectionPM($from, $to, $unit),
                'dm' => self::sectionDM($from, $to, $unit),
            };
        }
        return ['meta' => ['from' => $from, 'to' => $to, 'unit' => $unitName, 'generated_at' => date('Y-m-d H:i'), 'modules' => $mods], 'sections' => $sections];
    }

    private static function table(string $name, array $head, array $rows): array
    {
        $cut = count($rows) > self::LIMIT;
        return ['name' => $name, 'head' => $head, 'rows' => $cut ? array_slice($rows, 0, self::LIMIT) : $rows, 'total' => count($rows), 'truncated' => $cut];
    }

    /** Hitung kemunculan nilai kolom → [[nilai, jumlah], ...] urut terbanyak. */
    private static function group(array $rows, string $key): array
    {
        $o = [];
        foreach ($rows as $r) {
            $k = (string) ($r[$key] ?? '') ?: '—';
            $o[$k] = ($o[$k] ?? 0) + 1;
        }
        return self::pairs($o);
    }

    private static function pairs(array $map, int $top = 0): array
    {
        arsort($map);
        if ($top) {
            $map = array_slice($map, 0, $top, true);
        }
        $out = [];
        foreach ($map as $k => $v) {
            $out[] = [(string) $k, $v];
        }
        return $out;
    }

    private static function sectionWO(string $from, string $to, int $unit): array
    {
        $w = ['substr(w.created_at,1,10) BETWEEN ? AND ?'];
        $p = [$from, $to];
        if ($unit) {
            $w[] = 'w.unit_id = ?';
            $p[] = $unit;
        }
        $rows = Db::query("SELECT w.id, w.title, w.type, w.priority, w.status, u.name AS unit_name, e.name AS equipment_name, w.requestor_name, w.source, w.created_at, w.finished_at, w.closed_at, w.work_seconds,
              (SELECT GROUP_CONCAT(te.name, ', ') FROM wo_techs t JOIN technicians te ON te.id = t.tech_id WHERE t.wo_id = w.id) AS techs
            FROM work_orders w LEFT JOIN units u ON u.id = w.unit_id LEFT JOIN equipment e ON e.id = w.equipment_id WHERE " . implode(' AND ', $w) . ' ORDER BY w.created_at DESC', $p);
        $closed = array_filter($rows, fn ($r) => $r['status'] === 'Closed');
        $avg = $closed ? array_sum(array_column($closed, 'work_seconds')) / count($closed) / 3600 : 0;
        $byEq = [];
        foreach ($rows as $r) {
            if ($r['equipment_name']) {
                $byEq[$r['equipment_name']] = ($byEq[$r['equipment_name']] ?? 0) + 1;
            }
        }
        $parts = Db::query('SELECT p.wo_id, p.item_id, p.name, p.qty, p.uom FROM wo_parts p JOIN work_orders w ON w.id = p.wo_id WHERE ' . implode(' AND ', $w) . ' ORDER BY p.wo_id', $p);
        return [
            'key' => 'wo', 'title' => 'Work Order',
            'kpis' => [['Total WO', count($rows)], ['Closed', count($closed)], ['Berjalan', count(array_filter($rows, fn ($r) => in_array($r['status'], ['Open', 'In Progress', 'Hold'], true)))],
                ['Menunggu verifikasi', count(array_filter($rows, fn ($r) => $r['status'] === 'Pending Verification'))], ['Rata-rata durasi kerja (jam)', round($avg, 1)]],
            'tables' => [
                self::table('WO per status', ['Status', 'Jumlah'], self::group($rows, 'status')),
                self::table('WO per tipe', ['Tipe', 'Jumlah'], self::group($rows, 'type')),
                self::table('WO per prioritas', ['Prioritas', 'Jumlah'], self::group($rows, 'priority')),
                self::table('Equipment terbanyak WO', ['Equipment', 'Jumlah WO'], self::pairs($byEq, 10)),
                self::table('Daftar WO', ['ID', 'Judul', 'Tipe', 'Prioritas', 'Status', 'Unit', 'Equipment', 'Pemohon', 'Asal', 'Teknisi', 'Dibuat', 'Selesai', 'Durasi (jam)'],
                    array_map(fn ($r) => [$r['id'], $r['title'], $r['type'], $r['priority'], $r['status'], $r['unit_name'], $r['equipment_name'], $r['requestor_name'], $r['source'], $r['techs'],
                        $r['created_at'], $r['finished_at'] ?: $r['closed_at'], round((int) $r['work_seconds'] / 3600, 2)], $rows)),
                self::table('Sparepart dipakai di WO', ['WO', 'Kode', 'Sparepart', 'Qty', 'UOM'], array_map(fn ($r) => [$r['wo_id'], $r['item_id'], $r['name'], (float) $r['qty'], $r['uom']], $parts)),
            ],
        ];
    }

    private static function sectionMW(string $from, string $to, int $unit): array
    {
        $w = ['substr(t.trx_date,1,10) BETWEEN ? AND ?'];
        $p = [$from, $to];
        if ($unit) {
            $w[] = 't.unit_id = ?';
            $p[] = $unit;
        }
        $rows = Db::query('SELECT t.trx_date, t.item_id, i.name AS item_name, i.item_type, i.uom, t.trx_type, t.qty, t.stock_after, t.pic, t.source, t.wo_id, u.name AS unit_name, a.name AS area_name, e.name AS equipment_name, t.notes
            FROM mw_transactions t JOIN mw_items i ON i.id = t.item_id LEFT JOIN units u ON u.id = t.unit_id LEFT JOIN areas a ON a.id = t.area_id LEFT JOIN equipment e ON e.id = t.equipment_id
            WHERE ' . implode(' AND ', $w) . ' ORDER BY t.trx_date DESC, t.id DESC', $p);
        $sum = fn (string $tx) => array_sum(array_map(fn ($r) => (float) $r['qty'], array_filter($rows, fn ($r) => $r['trx_type'] === $tx)));
        $byType = [];
        $rank = [];
        $bySource = [];
        foreach ($rows as $r) {
            $bySource[$r['source'] ?: '—'] = ($bySource[$r['source'] ?: '—'] ?? 0) + 1;
            if ($r['trx_type'] !== 'OUT') {
                continue;
            }
            $byType[$r['item_type'] ?: '—'] = ($byType[$r['item_type'] ?: '—'] ?? 0) + (float) $r['qty'];
            $rank[$r['item_id']] = ['name' => $r['item_name'], 'qty' => ($rank[$r['item_id']]['qty'] ?? 0) + (float) $r['qty'], 'uom' => $r['uom']];
        }
        uasort($rank, fn ($a, $b) => $b['qty'] <=> $a['qty']);
        $top = [];
        foreach (array_slice($rank, 0, 10, true) as $id => $v) {
            $top[] = [(string) $id, $v['name'], $v['qty'], $v['uom']];
        }
        $reorder = Db::query('SELECT id, name, brand, item_type, uom, qty, min_stock, price FROM mw_items WHERE qty < min_stock ORDER BY (min_stock - qty) DESC, name');
        return [
            'key' => 'mw', 'title' => 'MaintWare',
            'kpis' => [['Total IN', $sum('IN')], ['Total OUT', $sum('OUT')], ['Transaksi', count($rows)], ['Item di bawah stok minimum', count($reorder)]],
            'tables' => [
                self::table('OUT per tipe item', ['Tipe', 'Qty OUT'], self::pairs($byType)),
                self::table('Top 10 item OUT', ['Kode', 'Item', 'Qty', 'UOM'], $top),
                self::table('Transaksi per sumber', ['Sumber', 'Jumlah'], self::pairs($bySource)),
                self::table('Detail transaksi', ['Waktu', 'Kode', 'Item', 'Tipe', 'TX', 'Qty', 'UOM', 'Sisa', 'PIC', 'Sumber', 'WO', 'Unit', 'Area', 'Equipment', 'Catatan'],
                    array_map(fn ($r) => [$r['trx_date'], $r['item_id'], $r['item_name'], $r['item_type'], $r['trx_type'], (float) $r['qty'], $r['uom'], (float) $r['stock_after'], $r['pic'], $r['source'], $r['wo_id'], $r['unit_name'], $r['area_name'], $r['equipment_name'], $r['notes']], $rows)),
                self::table('Reorder list (saat ini)', ['Kode', 'Item', 'Merek', 'Tipe', 'Stok', 'Min', 'Kurang', 'UOM', 'Harga'],
                    array_map(fn ($r) => [$r['id'], $r['name'], $r['brand'], $r['item_type'], (float) $r['qty'], (float) $r['min_stock'], (float) $r['min_stock'] - (float) $r['qty'], $r['uom'], $r['price']], $reorder)),
            ],
        ];
    }

    private static function sectionPM(string $from, string $to, int $unit): array
    {
        $w = ['r.report_date BETWEEN ? AND ?'];
        $p = [$from, $to];
        if ($unit) {
            $w[] = 'r.unit_id = ?';
            $p[] = $unit;
        }
        $rows = Db::query('SELECT r.id, r.report_date, r.periode, r.pm_type, r.teknisi, r.approval, r.items_total, r.items_ok, r.items_no, r.wo_id, e.name AS equipment_name, a.name AS area_name, u.name AS unit_name
            FROM pm_reports r JOIN equipment e ON e.id = r.equipment_id LEFT JOIN areas a ON a.id = r.area_id LEFT JOIN units u ON u.id = r.unit_id WHERE ' . implode(' AND ', $w) . ' ORDER BY r.report_date DESC, r.id DESC', $p);
        $sched = PreventiveApi::scheduleRows($unit ? ['unit_id' => $unit] : []);
        $cnt = ['done' => 0, 'upcoming' => 0, 'overdue' => 0];
        foreach ($sched as $s) {
            $cnt[$s['status']]++;
        }
        $overdue = array_values(array_filter($sched, fn ($s) => $s['status'] === 'overdue'));
        usort($overdue, fn ($a, $b) => strcmp((string) $a['next_due'], (string) $b['next_due']));
        $byArea = [];
        foreach ($rows as $r) {
            $k = ($r['unit_name'] ?: '—') . ' › ' . ($r['area_name'] ?: '—');
            $byArea[$k] = ($byArea[$k] ?? 0) + 1;
        }
        $total = array_sum(array_column($rows, 'items_total'));
        $ok = array_sum(array_column($rows, 'items_ok'));
        $findings = Db::query('SELECT i.report_id, e.name AS equipment_name, i.text AS label, i.note FROM pm_report_items i JOIN pm_reports r ON r.id = i.report_id JOIN equipment e ON e.id = r.equipment_id
            WHERE i.no = 1 AND ' . implode(' AND ', $w) . ' ORDER BY r.report_date DESC', $p);
        return [
            'key' => 'pm', 'title' => 'Preventive Maintenance',
            'kpis' => [['Laporan PM (periode)', count($rows)], ['Kepatuhan jadwal', ($sched ? round($cnt['done'] / count($sched) * 100) : 0) . '%'], ['Jadwal terlambat', $cnt['overdue']],
                ['Butir OK', $total ? round($ok / $total * 100) . '%' : '—'], ['Butir tidak OK', array_sum(array_column($rows, 'items_no'))]],
            'tables' => [
                self::table('Status jadwal (saat ini)', ['Status', 'Jumlah'], [['Selesai', $cnt['done']], ['Akan datang', $cnt['upcoming']], ['Terlambat', $cnt['overdue']]]),
                self::table('Laporan per area', ['Unit › Area', 'Laporan'], self::pairs($byArea)),
                self::table('Daftar laporan PM', ['ID', 'Tanggal', 'Equipment', 'Unit', 'Area', 'Periode', 'Tipe', 'Teknisi', 'Persetujuan', 'Butir', 'OK', 'Tidak OK', 'WO'],
                    array_map(fn ($r) => [$r['id'], $r['report_date'], $r['equipment_name'], $r['unit_name'], $r['area_name'], $r['periode'], $r['pm_type'], $r['teknisi'], $r['approval'], (int) $r['items_total'], (int) $r['items_ok'], (int) $r['items_no'], $r['wo_id']], $rows)),
                self::table('Temuan (butir tidak OK)', ['Laporan', 'Equipment', 'Butir', 'Keterangan'], array_map(fn ($r) => [$r['report_id'], $r['equipment_name'], $r['label'], $r['note']], $findings)),
                self::table('Jadwal PM terlambat (saat ini)', ['Jadwal', 'Equipment', 'Unit', 'Area', 'Periode', 'Jatuh tempo', 'PIC'],
                    array_map(fn ($s) => [$s['id'], $s['equipment_name'], $s['unit_name'], $s['area_name'], $s['periode'], $s['next_due'], $s['pic']], $overdue)),
            ],
        ];
    }

    private static function sectionDM(string $from, string $to, int $unit): array
    {
        $w = ['s.session_date BETWEEN ? AND ?'];
        $p = [$from, $to];
        if ($unit) {
            $w[] = 's.unit_id = ?';
            $p[] = $unit;
        }
        $rows = Db::query('SELECT s.id, s.session_date, s.status, s.pic, s.catatan, s.tindakan, s.wo_id, e.name AS equipment_name, a.name AS area_name, u.name AS unit_name
            FROM dm_sessions s JOIN equipment e ON e.id = s.equipment_id LEFT JOIN areas a ON a.id = s.area_id LEFT JOIN units u ON u.id = s.unit_id WHERE ' . implode(' AND ', $w) . ' ORDER BY s.session_date DESC, s.id DESC', $p);
        $find = Db::query("SELECT s.session_date, s.id, e.name AS equipment_name, i.label, i.value, i.unit, i.status, i.note FROM dm_items i JOIN dm_sessions s ON s.id = i.session_id JOIN equipment e ON e.id = s.equipment_id
            WHERE i.status IN ('WARNING','ALERT') AND " . implode(' AND ', $w) . " ORDER BY CASE i.status WHEN 'ALERT' THEN 0 ELSE 1 END, s.session_date DESC", $p);
        $by = fn (string $s) => count(array_filter($rows, fn ($r) => $r['status'] === $s));
        $worst = [];
        foreach ($find as $f) {
            $worst[$f['equipment_name']] = ($worst[$f['equipment_name']] ?? 0) + 1;
        }
        return [
            'key' => 'dm', 'title' => 'Daily Monitoring',
            'kpis' => [['Sesi monitoring', count($rows)], ['Equipment dimonitor', count(array_unique(array_column($rows, 'equipment_name')))], ['Sesi WARNING', $by('WARNING')], ['Sesi ALERT', $by('ALERT')], ['Temuan (parameter)', count($find)]],
            'tables' => [
                self::table('Sesi per status', ['Status', 'Jumlah'], self::group($rows, 'status')),
                self::table('Equipment dengan temuan terbanyak', ['Equipment', 'Temuan'], self::pairs($worst, 10)),
                self::table('Temuan WARNING / ALERT', ['Tanggal', 'Sesi', 'Equipment', 'Parameter', 'Nilai', 'Satuan', 'Status', 'Catatan'],
                    array_map(fn ($f) => [$f['session_date'], $f['id'], $f['equipment_name'], $f['label'], $f['value'], $f['unit'], $f['status'], $f['note']], $find)),
                self::table('Daftar sesi', ['Sesi', 'Tanggal', 'Equipment', 'Unit', 'Area', 'Status', 'PIC', 'Catatan', 'Tindakan', 'WO'],
                    array_map(fn ($r) => [$r['id'], $r['session_date'], $r['equipment_name'], $r['unit_name'], $r['area_name'], $r['status'], $r['pic'], $r['catatan'], $r['tindakan'], $r['wo_id']], $rows)),
            ],
        ];
    }
}
