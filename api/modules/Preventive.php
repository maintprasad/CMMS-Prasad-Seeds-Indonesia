<?php
declare(strict_types=1);

/**
 * Modul Preventive Maintenance: jadwal (pm_schedules), laporan + checklist + sparepart + tanda tangan (pm_reports*),
 * template checklist per tipe (pm_types/pm_template_items). Semua menunjuk equipment di Equipment Database.
 */
final class PreventiveApi
{
    private const STAFF = ['admin', 'spv', 'maintenance'];
    private const MANAGE = ['admin', 'spv'];
    public const PERIODS = ['Daily' => 1, 'Weekly' => 7, 'Monthly' => 30, 'Quarterly' => 90, 'Semi-Annual' => 180, 'Annual' => 365];
    private const PPE = ['helm', 'masker', 'sarungTangan', 'earPlug', 'sepatu', 'harness', 'lain'];

    // ───────────────────────── jadwal & status ─────────────────────────

    /** Tanggal acuan jadwal: start_date bila ada, selain itu bulan + minggu ke-n pada tahun berjalan. */
    private static function anchor(array $s, int $year): string
    {
        if (!empty($s['start_date'])) {
            return (string) $s['start_date'];
        }
        $m = min(12, max(1, (int) ($s['month'] ?: date('n'))));
        $w = min(6, max(1, (int) ($s['week'] ?: 1)));
        $day = min(1 + ($w - 1) * 7, (int) date('t', strtotime(sprintf('%04d-%02d-01', $year, $m))));
        return sprintf('%04d-%02d-%02d', $year, $m, $day);
    }

    /**
     * Status jadwal. Jatuh tempo = PM terakhir + periode (atau tanggal acuan bila belum pernah).
     * done = sudah dikerjakan dan belum mendekati jatuh tempo berikutnya; overdue = lewat > 6 hari; selain itu upcoming.
     * @return array{0:string,1:string} [status, next_due]
     */
    public static function statusOf(array $s, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $days = self::PERIODS[$s['periode']] ?? 30;
        $due = !empty($s['last_done'])
            ? date('Y-m-d', strtotime($s['last_done'] . " +$days days"))
            : self::anchor($s, (int) substr($today, 0, 4));
        $lead = $days <= 7 ? 0 : (int) min(30, round($days * 0.2));
        $diff = (int) round((strtotime($due) - strtotime($today)) / 86400);
        if ($diff < -6) {
            return ['overdue', $due];
        }
        if (!empty($s['last_done']) && $diff > $lead) {
            return ['done', $due];
        }
        return ['upcoming', $due];
    }

    private const SCHED_SELECT = "SELECT s.*, e.name AS equipment_name, e.code AS equipment_code, e.pm_type, a.id AS area_id, a.name AS area_name, a.unit_id, u.name AS unit_name,
            (SELECT COUNT(*) FROM pm_reports r WHERE r.equipment_id = s.equipment_id) AS report_count
        FROM pm_schedules s JOIN equipment e ON e.id = s.equipment_id JOIN areas a ON a.id = e.area_id JOIN units u ON u.id = a.unit_id";

    /** @return array<int,array> jadwal aktif + status terhitung, sesuai filter query. */
    private static function schedules(array $src): array
    {
        $where = ['s.active = 1'];
        $p = [];
        foreach (['unit_id' => 'a.unit_id', 'area_id' => 'e.area_id'] as $k => $col) {
            if (($v = (int) ($src[$k] ?? 0)) > 0) {
                $where[] = "$col = ?";
                $p[] = $v;
            }
        }
        foreach (['pm_type' => 'e.pm_type', 'periode' => 's.periode'] as $k => $col) {
            if (($v = (string) ($src[$k] ?? '')) !== '') {
                $where[] = "$col = ?";
                $p[] = $v;
            }
        }
        if (($q = trim((string) ($src['q'] ?? ''))) !== '') {
            $where[] = '(e.name LIKE ? OR e.code LIKE ? OR s.pic LIKE ? OR s.id LIKE ?)';
            array_push($p, "%$q%", "%$q%", "%$q%", "%$q%");
        }
        $rows = Db::query(self::SCHED_SELECT . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY u.id, a.name, e.name', $p);
        $today = date('Y-m-d');
        foreach ($rows as &$r) {
            [$r['status'], $r['next_due']] = self::statusOf($r, $today);
        }
        return $rows;
    }

    /** Jadwal aktif + status (dipakai modul Report & notifikasi). */
    public static function scheduleRows(array $filters): array
    {
        return self::schedules($filters);
    }

    /** Jadwal PM aktif satu equipment + status (untuk detail equipment). */
    public static function forEquipment(int $equipmentId): array
    {
        $rows = Db::query(self::SCHED_SELECT . ' WHERE s.equipment_id = ? AND s.active = 1 ORDER BY s.id', [$equipmentId]);
        foreach ($rows as &$r) {
            [$r['status'], $r['next_due']] = self::statusOf($r);
        }
        return $rows;
    }

    public static function scheduleList(): array
    {
        Auth::requireRole(...self::STAFF);
        $rows = self::schedules($_GET);
        $counts = ['done' => 0, 'upcoming' => 0, 'overdue' => 0];
        foreach ($rows as $r) {
            $counts[$r['status']]++;
        }
        $st = (string) Http::query('status', '');
        if ($st === 'outstanding') {
            $rows = array_values(array_filter($rows, fn ($r) => $r['status'] !== 'done'));
        } elseif ($st !== '') {
            $rows = array_values(array_filter($rows, fn ($r) => $r['status'] === $st));
        }
        return ['rows' => $rows, 'counts' => $counts, 'total' => count($rows)];
    }

    public static function scheduleSave(): array
    {
        $me = Auth::requireRole(...self::MANAGE);
        $in = Http::body();
        $eq = (int) ($in['equipment_id'] ?? 0);
        if (!Db::query('SELECT 1 FROM equipment WHERE id = ?', [$eq])) {
            throw new HttpError('Equipment wajib dipilih.');
        }
        $periode = (string) ($in['periode'] ?? 'Annual');
        if (!isset(self::PERIODS[$periode])) {
            throw new HttpError('Periode tidak valid.');
        }
        $num = fn (string $k, int $lo, int $hi) => ($v = (int) ($in[$k] ?? 0)) >= $lo && $v <= $hi ? $v : null;
        $tech = ($t = (int) ($in['pic_tech_id'] ?? 0)) > 0 ? (Db::query('SELECT id, name FROM technicians WHERE id = ?', [$t])[0] ?? null) : null;
        $pic = trim((string) ($in['pic'] ?? '')) ?: ($tech['name'] ?? null);
        $vals = [$eq, $periode, $num('month', 1, 12), $num('week', 1, 6), $pic, $tech['id'] ?? null, ($mp = (int) ($in['mp'] ?? 0)) > 0 ? $mp : null,
            ($hr = (int) ($in['hari'] ?? 0)) > 0 ? $hr : null, trim((string) ($in['notes'] ?? '')) ?: null, preg_match('~^\d{4}-\d{2}-\d{2}$~', (string) ($in['start_date'] ?? '')) ? $in['start_date'] : null, now()];
        $id = (string) ($in['id'] ?? '');
        if ($id !== '') {
            if (!Db::query('SELECT 1 FROM pm_schedules WHERE id = ?', [$id])) {
                throw new HttpError('Jadwal tidak ditemukan.', 404);
            }
            Db::transaction([['UPDATE pm_schedules SET equipment_id=?, periode=?, month=?, week=?, pic=?, pic_tech_id=?, mp=?, hari=?, notes=?, start_date=?, updated_at=? WHERE id=?', [...$vals, $id]]]);
            return ['id' => $id];
        }
        $max = 0;
        foreach (Db::query("SELECT id FROM pm_schedules WHERE id LIKE 'SCH-%'") as $r) {
            $max = max($max, (int) substr((string) $r['id'], 4));
        }
        $id = 'SCH-' . str_pad((string) ($max + 1), 3, '0', STR_PAD_LEFT);
        Db::transaction([['INSERT INTO pm_schedules (equipment_id, periode, month, week, pic, pic_tech_id, mp, hari, notes, start_date, updated_at, id, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [...$vals, $id, $me['name'], now()]]]);
        return ['id' => $id];
    }

    public static function scheduleDelete(): array
    {
        Auth::requireRole(...self::MANAGE);
        Db::transaction([['UPDATE pm_schedules SET active = 0, updated_at = ? WHERE id = ?', [now(), (string) (Http::body()['id'] ?? '')]]]);
        return [];
    }

    // ───────────────────────── takeover ─────────────────────────

    public static function takeover(): array
    {
        $me = Auth::requireRole(...self::STAFF);
        $in = Http::body();
        $ids = array_values(array_unique(array_filter(array_map('strval', (array) ($in['ids'] ?? [$in['id'] ?? ''])))));
        $to = trim((string) ($in['to_pic'] ?? ''));
        $reason = trim((string) ($in['reason'] ?? ''));
        if (!$ids || $to === '' || $reason === '') {
            throw new HttpError('Pilih jadwal, PIC pengganti, dan alasan takeover.');
        }
        $ts = now();
        $st = [];
        foreach ($ids as $id) {
            $s = Db::query('SELECT id, pic FROM pm_schedules WHERE id = ? AND active = 1', [$id])[0] ?? throw new HttpError("Jadwal $id tidak ditemukan.", 404);
            $tech = Db::query('SELECT id FROM technicians WHERE lower(name) = lower(?)', [$to])[0]['id'] ?? null;
            $st[] = ['UPDATE pm_schedules SET pic = ?, pic_tech_id = ?, takeover_at = ?, takeover_from = ?, takeover_reason = ?, updated_at = ? WHERE id = ?', [$to, $tech, $ts, $s['pic'], $reason, $ts, $id]];
            $st[] = ['INSERT INTO pm_takeovers (schedule_id, at, from_pic, to_pic, reason, by_user) VALUES (?,?,?,?,?,?)', [$id, $ts, $s['pic'], $to, $reason, $me['name']]];
        }
        Db::transaction($st);
        return ['updated' => count($ids)];
    }

    public static function takeovers(): array
    {
        Auth::requireRole(...self::STAFF);
        return ['rows' => Db::query(
            'SELECT t.*, e.name AS equipment_name, a.name AS area_name FROM pm_takeovers t JOIN pm_schedules s ON s.id = t.schedule_id
             JOIN equipment e ON e.id = s.equipment_id JOIN areas a ON a.id = e.area_id ORDER BY t.at DESC, t.id DESC LIMIT 200'
        )];
    }

    // ───────────────────────── template ─────────────────────────

    public static function types(): array
    {
        Auth::user();
        return ['types' => Db::query(
            'SELECT t.code, t.label, (SELECT COUNT(*) FROM pm_template_items i WHERE i.pm_type = t.code) AS items,
                    (SELECT COUNT(*) FROM equipment e WHERE e.pm_type = t.code) AS equipment FROM pm_types t ORDER BY t.label'
        ), 'periods' => array_keys(self::PERIODS)];
    }

    public static function templates(): array
    {
        Auth::requireRole(...self::STAFF);
        $items = [];
        foreach (Db::query('SELECT pm_type, text FROM pm_template_items ORDER BY pm_type, seq, id') as $r) {
            $items[$r['pm_type']][] = $r['text'];
        }
        return self::types() + ['items' => (object) $items];
    }

    /** Ganti seluruh butir checklist suatu tipe. */
    public static function templateSave(): array
    {
        Auth::requireRole(...self::MANAGE);
        $in = Http::body();
        $type = (string) ($in['pm_type'] ?? '');
        if (!Db::query('SELECT 1 FROM pm_types WHERE code = ?', [$type])) {
            throw new HttpError('Tipe PM tidak ditemukan.');
        }
        $items = array_values(array_filter(array_map(fn ($t) => mb_substr(trim((string) $t), 0, 300), (array) ($in['items'] ?? [])), fn ($t) => $t !== ''));
        $st = [['DELETE FROM pm_template_items WHERE pm_type = ?', [$type]]];
        foreach ($items as $i => $t) {
            $st[] = ['INSERT INTO pm_template_items (pm_type, seq, text) VALUES (?,?,?)', [$type, $i, $t]];
        }
        Db::transaction($st);
        return ['items' => count($items)];
    }

    public static function typeSave(): array
    {
        Auth::requireRole(...self::MANAGE);
        $in = Http::body();
        $code = strtolower(preg_replace('~[^A-Za-z0-9]+~', '_', trim((string) ($in['code'] ?? ''))) ?? '');
        $label = trim((string) ($in['label'] ?? ''));
        if ($code === '' || $label === '' || strlen($code) > 40) {
            throw new HttpError('Kode (huruf/angka) dan nama tipe wajib diisi.');
        }
        Db::transaction([['INSERT INTO pm_types (code, label, seq) VALUES (?,?,0) ON CONFLICT(code) DO UPDATE SET label = excluded.label', [$code, $label]]]);
        return ['code' => $code];
    }

    public static function typeDelete(): array
    {
        Auth::requireRole('admin');
        $code = (string) (Http::body()['code'] ?? '');
        if ((int) Db::query('SELECT COUNT(*) c FROM equipment WHERE pm_type = ?', [$code])[0]['c'] > 0) {
            throw new HttpError('Tipe masih dipakai equipment.', 409);
        }
        Db::transaction([['DELETE FROM pm_template_items WHERE pm_type = ?', [$code]], ['DELETE FROM pm_types WHERE code = ?', [$code]]]);
        return [];
    }

    // ───────────────────────── form & laporan ─────────────────────────

    public static function form(): array
    {
        Auth::requireRole(...self::STAFF);
        $rid = (string) Http::query('id', '');
        $report = $rid !== '' ? self::findReport($rid) : null;
        $sid = $report ? ($report['schedule_id'] ?? '') : (string) Http::query('schedule_id', '');
        $schedule = $sid !== '' ? (Db::query(self::SCHED_SELECT . ' WHERE s.id = ?', [$sid])[0] ?? null) : null;
        $eqId = $report ? (int) $report['equipment_id'] : ($schedule ? (int) $schedule['equipment_id'] : (int) Http::query('equipment_id', '0'));
        $eq = Db::query(
            'SELECT e.id, e.name, e.code, e.pm_type, a.name AS area_name, a.unit_id, u.name AS unit_name FROM equipment e JOIN areas a ON a.id = e.area_id JOIN units u ON u.id = a.unit_id WHERE e.id = ?',
            [$eqId]
        )[0] ?? throw new HttpError('Equipment tidak ditemukan.', 404);
        if ($schedule) {
            [$schedule['status'], $schedule['next_due']] = self::statusOf($schedule);
        }
        $type = $report['pm_type'] ?? $eq['pm_type'];
        $template = $type ? array_column(Db::query('SELECT text FROM pm_template_items WHERE pm_type = ? ORDER BY seq, id', [$type]), 'text') : [];
        $last = Db::query('SELECT id, report_date, teknisi, items_no FROM pm_reports WHERE equipment_id = ? AND id != ? ORDER BY report_date DESC LIMIT 1', [$eqId, $rid])[0] ?? null;
        return [
            'equipment' => $eq, 'schedule' => $schedule, 'template' => $template, 'last' => $last, 'ppe_keys' => self::PPE,
            'report' => $report ? self::reportDetail($report) : null,
            'types' => Db::query('SELECT code, label FROM pm_types ORDER BY label'),
        ];
    }

    private static function findReport(string $id): array
    {
        return Db::query('SELECT * FROM pm_reports WHERE id = ?', [$id])[0] ?? throw new HttpError('Laporan PM tidak ditemukan.', 404);
    }

    private static function reportDetail(array $r): array
    {
        $r['ppe'] = $r['ppe'] ? (json_decode((string) $r['ppe'], true) ?: (object) []) : (object) [];
        $r['items'] = Db::query('SELECT seq, text, yes, no, note FROM pm_report_items WHERE report_id = ? ORDER BY seq', [$r['id']]);
        $r['parts'] = Db::query('SELECT p.seq, p.item_id, p.name, p.spec, p.qty, p.uom FROM pm_report_parts p WHERE p.report_id = ? ORDER BY p.seq', [$r['id']]);
        $r['signature'] = Db::query('SELECT * FROM pm_signatures WHERE report_id = ?', [$r['id']])[0] ?? null;
        return $r;
    }

    /** Tambahan sisa stok MaintWare di dalam satu transaksi (pembalikan lalu pemakaian baru memakai angka stok berjalan). */
    private static function stockPlan(array $oldParts, array $newParts, string $reportId, array $eq, array $me, string $date): array
    {
        $st = [];
        $stock = [];
        $load = function (string $id) use (&$stock): ?array {
            if (!isset($stock[$id])) {
                $stock[$id] = Db::query('SELECT * FROM mw_items WHERE id = ?', [$id])[0] ?? null;
            }
            return $stock[$id];
        };
        $apply = function (array $part, string $type, string $note) use (&$st, &$stock, $load, $reportId, $eq, $me): void {
            $item = $load((string) $part['item_id']);
            if (!$item) {
                return;
            }
            foreach (MaintWareApi::stockStatements($item, $type, (float) $part['qty'], ['pic' => $me['name'], 'notes' => $note, 'by' => $me['name'], 'unit_id' => $eq['unit_id'],
                'area_id' => $eq['area_id'], 'equipment_id' => $eq['id'], 'pm_id' => $reportId, 'source' => 'PM']) as $s) {
                $st[] = $s;
            }
            $stock[$item['id']]['qty'] = (float) $item['qty'] + ($type === 'IN' ? 1 : -1) * (float) $part['qty'];
        };
        foreach ($oldParts as $p) {
            if (!empty($p['item_id'])) {
                $apply($p, 'IN', "Koreksi laporan PM $reportId");
            }
        }
        foreach ($newParts as $p) {
            if (!empty($p['item_id'])) {
                $apply($p, 'OUT', "PM $reportId ($date)");
            }
        }
        return $st;
    }

    public static function reportSave(): array
    {
        $me = Auth::requireRole(...self::STAFF);
        $in = Http::body();
        $rid = (string) ($in['id'] ?? '');
        $old = $rid !== '' ? self::findReport($rid) : null;
        if ($old && $old['created_by'] !== $me['name'] && !in_array($me['role'], self::MANAGE, true)) {
            throw new HttpError('Hanya pembuat laporan atau SPV/Admin yang bisa mengedit.', 403);
        }
        $eqId = $old ? (int) $old['equipment_id'] : (int) ($in['equipment_id'] ?? 0);
        $eq = Db::query('SELECT e.id, e.area_id, e.pm_type, a.unit_id FROM equipment e JOIN areas a ON a.id = e.area_id WHERE e.id = ?', [$eqId])[0] ?? throw new HttpError('Equipment tidak ditemukan.', 404);
        $date = (string) ($in['date'] ?? '');
        if (!preg_match('~^\d{4}-\d{2}-\d{2}$~', $date) || $date > date('Y-m-d')) {
            throw new HttpError('Tanggal PM tidak valid (tidak boleh di masa depan).');
        }
        $tek = $in['teknisi'] ?? '';
        $tek = trim(is_array($tek) ? implode(', ', array_map('trim', $tek)) : (string) $tek);
        if ($tek === '') {
            throw new HttpError('Teknisi pelaksana wajib diisi.');
        }
        $periode = isset(self::PERIODS[(string) ($in['periode'] ?? '')]) ? $in['periode'] : 'Annual';
        $items = [];
        foreach ((array) ($in['items'] ?? []) as $i => $it) {
            $text = mb_substr(trim((string) ($it['text'] ?? '')), 0, 300);
            if ($text === '') {
                continue;
            }
            $yes = !empty($it['yes']) && empty($it['no']);
            $no = !empty($it['no']);
            $items[] = [count($items), $text, (int) $yes, (int) $no, trim((string) ($it['note'] ?? '')) ?: null];
        }
        if (!array_filter($items, fn ($i) => $i[2] || $i[3])) {
            throw new HttpError('Tandai minimal satu butir checklist (Ya / Tidak).');
        }
        $parts = [];
        foreach ((array) ($in['parts'] ?? []) as $p) {
            $name = trim((string) ($p['name'] ?? ''));
            $item = ($iid = trim((string) ($p['item_id'] ?? ''))) !== '' ? (Db::query('SELECT id, name, uom FROM mw_items WHERE id = ?', [$iid])[0] ?? throw new HttpError("Item MaintWare $iid tidak ditemukan.")) : null;
            $name = $item['name'] ?? $name;
            $qty = (float) ($p['qty'] ?? 0);
            if ($name === '' || $qty <= 0) {
                continue;
            }
            $parts[] = ['seq' => count($parts), 'item_id' => $item['id'] ?? null, 'name' => $name, 'spec' => trim((string) ($p['spec'] ?? '')) ?: null, 'qty' => $qty,
                'uom' => $item['uom'] ?? (trim((string) ($p['uom'] ?? '')) ?: 'pcs')];
        }
        $ppe = [];
        foreach (self::PPE as $k) {
            $ppe[$k] = !empty($in['ppe'][$k]);
        }
        $sid = $old ? ($old['schedule_id'] ?? null) : (trim((string) ($in['schedule_id'] ?? '')) ?: null);
        if ($sid === null) { // tanpa jadwal eksplisit: pakai jadwal aktif equipment ini (periode sama dulu)
            $sid = Db::query('SELECT id FROM pm_schedules WHERE equipment_id = ? AND active = 1 ORDER BY (periode = ?) DESC, id LIMIT 1', [$eqId, $periode])[0]['id'] ?? null;
        }
        $ok = count(array_filter($items, fn ($i) => $i[2]));
        $no = count(array_filter($items, fn ($i) => $i[3]));
        $ts = now();
        $month = (int) substr($date, 5, 2);
        $week = max(1, min(6, (int) ceil((int) substr($date, 8, 2) / 7)));
        $id = $rid !== '' ? $rid : self::nextId((int) $eq['unit_id'], $date);

        $oldParts = $old ? Db::query('SELECT item_id, qty FROM pm_report_parts WHERE report_id = ?', [$id]) : [];
        $st = self::stockPlan($oldParts, $parts, $id, $eq + ['id' => $eqId], $me, $date);
        $common = [$sid, $date, $periode, $week, $month, $old['pm_type'] ?? $eq['pm_type'], $tek, trim((string) ($in['approval'] ?? '')) ?: null, trim((string) ($in['notes'] ?? '')) ?: null,
            json_encode($ppe), count($items), $ok, $no, $ts];
        if ($old) {
            $st[] = ['UPDATE pm_reports SET schedule_id=?, report_date=?, periode=?, week=?, month=?, pm_type=?, teknisi=?, approval=?, notes=?, ppe=?, items_total=?, items_ok=?, items_no=?, updated_at=? WHERE id=?', [...$common, $id]];
            $st[] = ['DELETE FROM pm_report_items WHERE report_id = ?', [$id]];
            $st[] = ['DELETE FROM pm_report_parts WHERE report_id = ?', [$id]];
        } else {
            $st[] = ['INSERT INTO pm_reports (schedule_id, report_date, periode, week, month, pm_type, teknisi, approval, notes, ppe, items_total, items_ok, items_no, updated_at, id, equipment_id, unit_id, area_id, source, created_by, created_at)
                      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', [...$common, $id, $eqId, $eq['unit_id'], $eq['area_id'], 'app', $me['name'], $ts]];
        }
        foreach ($items as [$seq, $text, $yes, $nn, $note]) {
            $st[] = ['INSERT INTO pm_report_items (report_id, seq, text, yes, no, note) VALUES (?,?,?,?,?,?)', [$id, $seq, $text, $yes, $nn, $note]];
        }
        foreach ($parts as $p) {
            $st[] = ['INSERT INTO pm_report_parts (report_id, seq, item_id, name, spec, qty, uom) VALUES (?,?,?,?,?,?,?)', [$id, $p['seq'], $p['item_id'], $p['name'], $p['spec'], $p['qty'], $p['uom']]];
        }
        Db::transaction($st);
        self::refreshLastDone($sid);
        return ['id' => $id, 'items_ok' => $ok, 'items_no' => $no, 'items_total' => count($items)];
    }

    /** last_done jadwal = tanggal laporan terbaru equipment-nya (bukan hanya laporan jadwal ini, karena jadwal bisa diganti). */
    private static function refreshLastDone(?string $scheduleId): void
    {
        if (!$scheduleId) {
            return;
        }
        $last = Db::query('SELECT MAX(r.report_date) d FROM pm_reports r JOIN pm_schedules s ON s.equipment_id = r.equipment_id WHERE s.id = ?', [$scheduleId])[0]['d'] ?? null;
        Db::transaction([['UPDATE pm_schedules SET last_done = ?, updated_at = ? WHERE id = ?', [$last, now(), $scheduleId]]]);
    }

    private static function nextId(int $unitId, string $date): string
    {
        $code = Db::query('SELECT code FROM units WHERE id = ?', [$unitId])[0]['code'] ?? 'XX';
        $prefix = 'PM/' . $code . '/' . substr(str_replace('-', '', $date), 0, 6) . '/';
        $max = Db::query('SELECT MAX(id) m FROM pm_reports WHERE id LIKE ?', [$prefix . '%'])[0]['m'] ?? null;
        return $prefix . str_pad((string) ($max ? ((int) substr((string) $max, strlen($prefix))) + 1 : 1), 4, '0', STR_PAD_LEFT);
    }

    public static function reportDelete(): array
    {
        $me = Auth::requireRole(...self::MANAGE);
        $r = self::findReport((string) (Http::body()['id'] ?? ''));
        $eq = Db::query('SELECT e.id, e.area_id, a.unit_id FROM equipment e JOIN areas a ON a.id = e.area_id WHERE e.id = ?', [$r['equipment_id']])[0];
        $st = self::stockPlan(Db::query('SELECT item_id, qty FROM pm_report_parts WHERE report_id = ?', [$r['id']]), [], $r['id'], $eq, $me, (string) $r['report_date']);
        array_push($st, ['DELETE FROM pm_report_items WHERE report_id = ?', [$r['id']]], ['DELETE FROM pm_report_parts WHERE report_id = ?', [$r['id']]],
            ['DELETE FROM pm_signatures WHERE report_id = ?', [$r['id']]], ['DELETE FROM pm_reports WHERE id = ?', [$r['id']]]);
        Db::transaction($st);
        self::refreshLastDone($r['schedule_id']);
        return [];
    }

    public static function reports(): array
    {
        Auth::requireRole(...self::STAFF);
        $where = [];
        $p = [];
        foreach (['unit_id' => 'r.unit_id', 'area_id' => 'r.area_id', 'equipment_id' => 'r.equipment_id'] as $k => $col) {
            if (($v = (int) Http::query($k, '0')) > 0) {
                $where[] = "$col = ?";
                $p[] = $v;
            }
        }
        foreach (['pm_type' => 'r.pm_type', 'periode' => 'r.periode'] as $k => $col) {
            if (($v = Http::query($k, '')) !== '') {
                $where[] = "$col = ?";
                $p[] = $v;
            }
        }
        if (($y = (int) Http::query('year', '0')) > 0) {
            $where[] = 'substr(r.report_date,1,4) = ?';
            $p[] = (string) $y;
        }
        if (($m = (int) Http::query('month', '0')) > 0) {
            $where[] = 'r.month = ?';
            $p[] = $m;
        }
        if (($v = Http::query('from', '')) !== '') {
            $where[] = 'r.report_date >= ?';
            $p[] = $v;
        }
        if (($v = Http::query('to', '')) !== '') {
            $where[] = 'r.report_date <= ?';
            $p[] = $v;
        }
        if (Http::query('findings') === '1') {
            $where[] = 'r.items_no > 0';
        }
        if (($q = trim((string) Http::query('q', ''))) !== '') {
            $where[] = '(e.name LIKE ? OR e.code LIKE ? OR r.teknisi LIKE ? OR r.id LIKE ?)';
            array_push($p, "%$q%", "%$q%", "%$q%", "%$q%");
        }
        $w = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $limit = min(500, max(1, (int) Http::query('limit', '50')));
        $offset = max(0, (int) Http::query('offset', '0'));
        $from = 'FROM pm_reports r JOIN equipment e ON e.id = r.equipment_id LEFT JOIN areas a ON a.id = r.area_id LEFT JOIN units u ON u.id = r.unit_id LEFT JOIN pm_types t ON t.code = r.pm_type';
        $total = (int) Db::query("SELECT COUNT(*) c $from $w", $p)[0]['c'];
        return ['total' => $total, 'rows' => Db::query(
            "SELECT r.id, r.report_date, r.periode, r.teknisi, r.approval, r.items_total, r.items_ok, r.items_no, r.wo_id, r.pm_type, t.label AS type_label,
                    e.id AS equipment_id, e.name AS equipment_name, e.code AS equipment_code, a.name AS area_name, u.name AS unit_name,
                    (SELECT COUNT(*) FROM pm_signatures s WHERE s.report_id = r.id AND s.spv_signature IS NOT NULL AND s.spv_signature != '') AS signed_spv,
                    (SELECT COUNT(*) FROM pm_signatures s WHERE s.report_id = r.id AND s.leader_signature IS NOT NULL AND s.leader_signature != '') AS signed_leader
             $from $w ORDER BY r.report_date DESC, r.id DESC LIMIT $limit OFFSET $offset",
            $p
        )];
    }

    public static function report(): array
    {
        Auth::requireRole(...self::STAFF);
        $r = self::reportDetail(self::findReport((string) Http::query('id', '')));
        $eq = Db::query('SELECT e.id, e.name, e.code, a.name AS area_name, u.name AS unit_name, t.label AS type_label FROM equipment e JOIN areas a ON a.id = e.area_id JOIN units u ON u.id = a.unit_id
                         LEFT JOIN pm_types t ON t.code = ? WHERE e.id = ?', [$r['pm_type'], $r['equipment_id']])[0] ?? null;
        $wo = $r['wo_id'] ? (Db::query('SELECT id, status FROM work_orders WHERE id = ?', [$r['wo_id']])[0] ?? null) : null;
        return ['report' => $r, 'equipment' => $eq, 'wo' => $wo];
    }

    public static function sign(): array
    {
        $me = Auth::requireRole(...self::MANAGE);
        $in = Http::body();
        $r = self::findReport((string) ($in['report_id'] ?? ''));
        $role = (string) ($in['role'] ?? '');
        $sig = (string) ($in['signature'] ?? '');
        if (!in_array($role, ['spv', 'leader'], true) || !preg_match('~^data:image/png;base64,[A-Za-z0-9+/=]+$~', $sig) || strlen($sig) > 150000) {
            throw new HttpError('Tanda tangan tidak valid (PNG, maks ±100 KB).');
        }
        $name = trim((string) ($in['name'] ?? '')) ?: $me['name'];
        $ts = now();
        Db::transaction([
            ['INSERT INTO pm_signatures (report_id, updated_at) VALUES (?, ?) ON CONFLICT(report_id) DO NOTHING', [$r['id'], $ts]],
            ["UPDATE pm_signatures SET {$role}_name = ?, {$role}_signature = ?, {$role}_signed_at = ?, updated_at = ? WHERE report_id = ?", [$name, $sig, $ts, $ts, $r['id']]],
        ]);
        return [];
    }

    public static function createWo(): array
    {
        $me = Auth::requireRole(...self::STAFF);
        $r = self::findReport((string) (Http::body()['report_id'] ?? ''));
        if ($r['wo_id']) {
            throw new HttpError('Laporan ini sudah punya WO: ' . $r['wo_id'], 409);
        }
        $bad = Db::query('SELECT text, note FROM pm_report_items WHERE report_id = ? AND no = 1 ORDER BY seq', [$r['id']]);
        if (!$bad) {
            throw new HttpError('Tidak ada butir bertanda "Tidak" pada laporan ini.', 409);
        }
        $eqName = Db::query('SELECT name FROM equipment WHERE id = ?', [$r['equipment_id']])[0]['name'];
        $lines = array_map(fn ($b) => '• ' . $b['text'] . ($b['note'] ? " — {$b['note']}" : ''), $bad);
        $res = WorkOrderApi::make([
            'title' => "[PM] $eqName: " . count($bad) . ' butir tidak OK', 'equipment_id' => (int) $r['equipment_id'], 'type' => 'Corrective', 'priority' => 'Medium',
            'description' => "Temuan Preventive Maintenance {$r['report_date']} oleh {$r['teknisi']} (laporan {$r['id']}):\n" . implode("\n", $lines),
            'source' => 'pm', 'source_ref' => $r['id'], 'tasks' => array_map(fn ($b) => $b['text'], $bad),
        ], $me, fn (string $woId) => [['UPDATE pm_reports SET wo_id = ?, updated_at = ? WHERE id = ?', [$woId, now(), $r['id']]]]);
        return ['wo_id' => $res['id']];
    }

    // ───────────────────────── dashboard, resume, kalender ─────────────────────────

    public static function dashboard(): array
    {
        Auth::requireRole(...self::STAFF);
        $rows = self::schedules($_GET);
        $c = ['done' => 0, 'upcoming' => 0, 'overdue' => 0];
        $byUnit = [];
        $week = [];
        $today = date('Y-m-d');
        $endWeek = date('Y-m-d', strtotime('+6 days'));
        foreach ($rows as $r) {
            $c[$r['status']]++;
            $k = $r['unit_name'] . ' › ' . $r['area_name'];
            $byUnit[$k] ??= ['label' => $k, 'done' => 0, 'upcoming' => 0, 'overdue' => 0];
            $byUnit[$k][$r['status']]++;
            if ($r['status'] !== 'done' && $r['next_due'] <= $endWeek && $r['next_due'] >= date('Y-m-d', strtotime('-6 days'))) {
                $week[] = $r;
            }
        }
        $total = count($rows);
        $year = (string) (int) Http::query('year', date('Y'));
        $perMonth = array_fill(1, 12, 0);
        foreach (Db::query('SELECT month, COUNT(*) c FROM pm_reports WHERE substr(report_date,1,4) = ? GROUP BY month', [$year]) as $r) {
            $perMonth[(int) $r['month']] = (int) $r['c'];
        }
        $overdue = array_values(array_filter($rows, fn ($r) => $r['status'] === 'overdue'));
        usort($overdue, fn ($a, $b) => strcmp((string) $a['next_due'], (string) $b['next_due']));
        $s = fn (string $sql, array $p = []) => (int) Db::query($sql, $p)[0]['c'];
        return [
            'kpi' => ['total' => $total, 'done' => $c['done'], 'upcoming' => $c['upcoming'], 'overdue' => $c['overdue'], 'compliance' => $total ? round($c['done'] / $total * 100) : 0,
                'reports_year' => $s('SELECT COUNT(*) c FROM pm_reports WHERE substr(report_date,1,4) = ?', [$year]), 'reports_month' => $s('SELECT COUNT(*) c FROM pm_reports WHERE substr(report_date,1,7) = ?', [date('Y-m')]),
                'findings' => $s('SELECT COUNT(*) c FROM pm_reports WHERE items_no > 0 AND wo_id IS NULL'), 'week' => count($week)],
            'by_area' => array_values($byUnit), 'per_month' => array_map(fn ($m, $n) => ['period' => sprintf('%s-%02d', $year, $m), 'laporan' => $n], array_keys($perMonth), $perMonth),
            'overdue' => array_slice($overdue, 0, 10), 'this_week' => array_slice($week, 0, 10), 'year' => (int) $year,
            'recent' => Db::query('SELECT r.id, r.report_date, r.teknisi, r.items_no, e.name AS equipment_name, a.name AS area_name FROM pm_reports r JOIN equipment e ON e.id = r.equipment_id LEFT JOIN areas a ON a.id = r.area_id ORDER BY r.report_date DESC, r.id DESC LIMIT 8'),
        ];
    }

    public static function resume(): array
    {
        Auth::requireRole(...self::STAFF);
        $where = [];
        $p = [];
        if (($y = (int) Http::query('year', date('Y'))) > 0) {
            $where[] = 'substr(r.report_date,1,4) = ?';
            $p[] = (string) $y;
        }
        if (($m = (int) Http::query('month', '0')) > 0) {
            $where[] = 'r.month = ?';
            $p[] = $m;
        }
        foreach (['unit_id' => 'r.unit_id', 'pm_type' => 'r.pm_type', 'periode' => 'r.periode'] as $k => $col) {
            if (($v = Http::query($k, '')) !== '' && $v !== '0') {
                $where[] = "$col = ?";
                $p[] = $v;
            }
        }
        $w = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $tot = Db::query("SELECT COUNT(*) reports, COALESCE(SUM(items_total),0) items, COALESCE(SUM(items_ok),0) ok, COALESCE(SUM(items_no),0) no, COUNT(DISTINCT equipment_id) equipment FROM pm_reports r $w", $p)[0];
        $sched = self::schedules(['unit_id' => Http::query('unit_id', ''), 'pm_type' => Http::query('pm_type', ''), 'periode' => Http::query('periode', '')]);
        $done = count(array_filter($sched, fn ($s) => $s['status'] === 'done'));
        return [
            'totals' => $tot + ['schedules' => count($sched), 'schedules_done' => $done],
            'by_type' => Db::query("SELECT COALESCE(t.label, r.pm_type, '—') label, COUNT(*) reports, SUM(r.items_total) items, SUM(r.items_ok) ok, SUM(r.items_no) no,
                    (SELECT COUNT(*) FROM pm_report_parts x JOIN pm_reports rr ON rr.id = x.report_id WHERE (rr.pm_type = r.pm_type OR (rr.pm_type IS NULL AND r.pm_type IS NULL))) parts
                    FROM pm_reports r LEFT JOIN pm_types t ON t.code = r.pm_type $w GROUP BY r.pm_type ORDER BY reports DESC", $p),
            'by_unit' => Db::query("SELECT COALESCE(u.name, '—') label, COUNT(*) reports, COUNT(DISTINCT r.equipment_id) equipment FROM pm_reports r LEFT JOIN units u ON u.id = r.unit_id $w GROUP BY r.unit_id ORDER BY reports DESC", $p),
            'by_month' => Db::query("SELECT r.month, COUNT(*) reports FROM pm_reports r $w GROUP BY r.month ORDER BY r.month", $p),
            'by_teknisi' => Db::query("SELECT r.teknisi label, COUNT(*) reports FROM pm_reports r $w GROUP BY r.teknisi ORDER BY reports DESC LIMIT 10", $p),
            'years' => array_column(Db::query('SELECT DISTINCT substr(report_date,1,4) y FROM pm_reports ORDER BY y DESC'), 'y'),
        ];
    }

    /** Kalender bulanan: jadwal terencana (hasil proyeksi periode) + laporan yang sudah dibuat. */
    public static function calendar(): array
    {
        Auth::requireRole(...self::STAFF);
        $y = (int) Http::query('year', date('Y'));
        $m = (int) Http::query('month', date('n'));
        $from = sprintf('%04d-%02d-01', $y, $m);
        $to = date('Y-m-t', strtotime($from));
        $planned = [];
        foreach (self::schedules($_GET) as $s) {
            $days = self::PERIODS[$s['periode']] ?? 30;
            $d = !empty($s['last_done']) ? date('Y-m-d', strtotime($s['last_done'] . " +$days days")) : self::anchor($s, $y);
            for ($i = 0; $i < 400 && $d <= $to; $i++, $d = date('Y-m-d', strtotime("$d +$days days"))) {
                if ($d >= $from) {
                    $planned[] = ['schedule_id' => $s['id'], 'equipment_id' => $s['equipment_id'], 'equipment_name' => $s['equipment_name'], 'area_name' => $s['area_name'], 'unit_name' => $s['unit_name'],
                        'date' => $d, 'week' => (int) ceil((int) substr($d, 8, 2) / 7), 'periode' => $s['periode'], 'pic' => $s['pic'], 'status' => $s['status']];
                }
            }
        }
        usort($planned, fn ($a, $b) => strcmp($a['date'], $b['date']));
        $done = Db::query('SELECT r.id, r.report_date, r.teknisi, r.items_no, e.name AS equipment_name FROM pm_reports r JOIN equipment e ON e.id = r.equipment_id WHERE r.report_date BETWEEN ? AND ? ORDER BY r.report_date', [$from, $to]);
        return ['year' => $y, 'month' => $m, 'planned' => $planned, 'done' => $done];
    }
}
