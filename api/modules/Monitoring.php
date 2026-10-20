<?php
declare(strict_types=1);

/**
 * Modul Daily Monitoring.
 * - Parameter monitoring melekat di Equipment Database (equipment_params) dan diatur dari menu Equipment Database.
 * - Template parameter per tipe monitoring = pengaturan dasar parameter (dm_param_templates).
 * - Sesi monitoring (dm_sessions/dm_items) menunjuk equipment_id; temuan WARNING/ALERT dapat dijadikan Work Order.
 */
final class MonitoringApi
{
    private const STAFF = ['admin', 'spv', 'maintenance'];
    private const MANAGE = ['admin', 'spv'];
    private const PARAM_COLS = 'label, unit, section, type, normal_min, normal_max, warn_min, warn_max, options, seq';

    // ───────────────────────── evaluasi status ─────────────────────────

    /** Angka strict: "28", "28,7", "28.7", "-5,5", ",5". Format ambigu ("1.234,5", "11.20.26", "1/5") ditolak. */
    public static function parseNumber(string $raw): ?float
    {
        $s = str_replace(' ', '', trim($raw));
        if (!preg_match('~^[-+]?(\d+([.,]\d*)?|[.,]\d+)$~', $s)) {
            return null;
        }
        return (float) str_replace(',', '.', $s);
    }

    /** ALERT bila melewati batas warn, WARNING bila melewati batas normal, selain itu OK. */
    public static function numericStatus(array $p, float $v): string
    {
        $has = fn ($k) => $p[$k] !== null && $p[$k] !== '';
        if (($has('warn_max') && $v > (float) $p['warn_max']) || ($has('warn_min') && $v < (float) $p['warn_min'])) {
            return 'ALERT';
        }
        if (($has('normal_max') && $v > (float) $p['normal_max']) || ($has('normal_min') && $v < (float) $p['normal_min'])) {
            return 'WARNING';
        }
        return 'OK';
    }

    /** Opsi pertama = OK, opsi terakhir = ALERT, di antaranya WARNING. */
    public static function optionStatus(array $options, string $value): ?string
    {
        $i = array_search($value, $options, true);
        if ($i === false) {
            return null;
        }
        return $i === 0 ? 'OK' : ($i === count($options) - 1 ? 'ALERT' : 'WARNING');
    }

    private static function worst(array $statuses): string
    {
        foreach (['ALERT', 'WARNING', 'OK'] as $s) {
            if (in_array($s, $statuses, true)) {
                return $s;
            }
        }
        return 'PENDING';
    }

    // ───────────────────────── parameter (equipment & template) ─────────────────────────

    public static function types(): array
    {
        Auth::user();
        return ['types' => Db::query(
            'SELECT t.code, t.label, (SELECT COUNT(*) FROM dm_param_templates p WHERE p.monitor_type = t.code) AS templates,
                    (SELECT COUNT(*) FROM equipment e WHERE e.monitor_type = t.code) AS equipment
             FROM dm_monitor_types t ORDER BY t.seq, t.label'
        )];
    }

    public static function templates(): array
    {
        Auth::user();
        $rows = Db::query('SELECT * FROM dm_param_templates ORDER BY monitor_type, seq, id');
        return self::types() + ['templates' => array_map([self::class, 'decode'], $rows)];
    }

    public static function equipmentParams(): array
    {
        Auth::user();
        return ['params' => self::paramsOf((int) Http::query('equipment_id', '0'))];
    }

    public static function paramsOf(int $equipmentId): array
    {
        return array_map([self::class, 'decode'], Db::query('SELECT * FROM equipment_params WHERE equipment_id = ? ORDER BY seq, id', [$equipmentId]));
    }

    private static function decode(array $r): array
    {
        $r['options'] = $r['options'] ? (json_decode((string) $r['options'], true) ?: []) : [];
        return $r;
    }

    /** Validasi & normalisasi satu parameter dari body. @return array<int,mixed> sesuai PARAM_COLS (tanpa seq) */
    private static function paramValues(array $in): array
    {
        $label = trim((string) ($in['label'] ?? ''));
        $type = (string) ($in['type'] ?? 'numeric');
        if ($label === '' || !in_array($type, ['numeric', 'status'], true)) {
            throw new HttpError('Nama parameter dan tipe (numeric/status) wajib diisi.');
        }
        $base = [$label, trim((string) ($in['unit'] ?? '')), trim((string) ($in['section'] ?? ''))];
        if ($type === 'status') {
            $opts = $in['options'] ?? [];
            if (is_string($opts)) {
                $opts = preg_split('~[\r\n,]+~', $opts);
            }
            $opts = array_values(array_unique(array_filter(array_map(fn ($o) => trim((string) $o), (array) $opts), fn ($o) => $o !== '')));
            if (count($opts) < 2 || count($opts) > 8) {
                throw new HttpError('Parameter status butuh 2–8 opsi. Opsi pertama = OK, opsi terakhir = ALERT.');
            }
            return [...$base, 'status', null, null, null, null, json_encode($opts, JSON_UNESCAPED_UNICODE)];
        }
        $n = [];
        foreach (['normal_min', 'normal_max', 'warn_min', 'warn_max'] as $k) {
            $raw = trim((string) ($in[$k] ?? ''));
            if ($raw === '') {
                $n[$k] = null;
                continue;
            }
            $n[$k] = self::parseNumber($raw);
            if ($n[$k] === null) {
                throw new HttpError("Batas “{$k}” harus berupa angka.");
            }
        }
        if (($n['normal_min'] !== null && $n['normal_max'] !== null && $n['normal_min'] > $n['normal_max'])
            || ($n['warn_min'] !== null && $n['normal_min'] !== null && $n['warn_min'] > $n['normal_min'])
            || ($n['warn_max'] !== null && $n['normal_max'] !== null && $n['warn_max'] < $n['normal_max'])) {
            throw new HttpError('Batas tidak masuk akal: warn_min ≤ normal_min ≤ normal_max ≤ warn_max.');
        }
        return [...$base, 'numeric', $n['normal_min'], $n['normal_max'], $n['warn_min'], $n['warn_max'], null];
    }

    /** Simpan parameter ke tabel equipment_params ($table='equipment_params', owner=equipment_id) atau dm_param_templates (owner=monitor_type). */
    private static function saveParam(string $table, string $ownerCol, mixed $owner, array $in): array
    {
        $v = self::paramValues($in);
        $id = (int) ($in['id'] ?? 0);
        if ($id > 0) {
            if (!Db::query("SELECT 1 FROM $table WHERE id = ? AND $ownerCol = ?", [$id, $owner])) {
                throw new HttpError('Parameter tidak ditemukan.', 404);
            }
            Db::transaction([["UPDATE $table SET label=?, unit=?, section=?, type=?, normal_min=?, normal_max=?, warn_min=?, warn_max=?, options=? WHERE id=?", [...$v, $id]]]);
            return ['id' => $id];
        }
        $seq = (int) Db::query("SELECT COALESCE(MAX(seq),-1) + 1 s FROM $table WHERE $ownerCol = ?", [$owner])[0]['s'];
        Db::transaction([["INSERT INTO $table ($ownerCol, label, unit, section, type, normal_min, normal_max, warn_min, warn_max, options, seq) VALUES (?,?,?,?,?,?,?,?,?,?,?)", [$owner, ...$v, $seq]]]);
        return ['id' => (int) Db::query("SELECT MAX(id) m FROM $table WHERE $ownerCol = ?", [$owner])[0]['m']];
    }

    private static function reorder(string $table, string $ownerCol, mixed $owner, array $ids): void
    {
        $st = [];
        foreach (array_values($ids) as $i => $id) {
            $st[] = ["UPDATE $table SET seq = ? WHERE id = ? AND $ownerCol = ?", [$i, (int) $id, $owner]];
        }
        Db::transaction($st);
    }

    private static function equipmentOrFail(int $id): array
    {
        $e = Db::query('SELECT * FROM equipment WHERE id = ?', [$id])[0] ?? null;
        if (!$e) {
            throw new HttpError('Equipment tidak ditemukan.', 404);
        }
        return $e;
    }

    public static function paramSave(): array
    {
        Auth::requireRole(...self::MANAGE);
        $in = Http::body();
        self::equipmentOrFail((int) ($in['equipment_id'] ?? 0));
        return self::saveParam('equipment_params', 'equipment_id', (int) $in['equipment_id'], $in);
    }

    public static function paramDelete(): array
    {
        Auth::requireRole(...self::MANAGE);
        Db::transaction([['DELETE FROM equipment_params WHERE id = ?', [(int) (Http::body()['id'] ?? 0)]]]);
        return [];
    }

    public static function paramReorder(): array
    {
        Auth::requireRole(...self::MANAGE);
        $in = Http::body();
        self::reorder('equipment_params', 'equipment_id', (int) ($in['equipment_id'] ?? 0), (array) ($in['ids'] ?? []));
        return [];
    }

    /** Salin daftar parameter ke satu/lebih equipment (dari template tipe atau dari equipment lain). */
    private static function copyParams(array $rows, array $targetIds, string $mode): int
    {
        if ($mode !== 'replace' && $mode !== 'append') {
            throw new HttpError('Mode harus replace atau append.');
        }
        $targetIds = array_values(array_unique(array_map('intval', $targetIds)));
        if (!$targetIds || count($targetIds) > 200) {
            throw new HttpError('Pilih 1–200 equipment tujuan.');
        }
        $st = [];
        foreach ($targetIds as $tid) {
            self::equipmentOrFail($tid);
            $seq = 0;
            $have = [];
            if ($mode === 'replace') {
                $st[] = ['DELETE FROM equipment_params WHERE equipment_id = ?', [$tid]];
            } else {
                foreach (Db::query('SELECT label, section, seq FROM equipment_params WHERE equipment_id = ?', [$tid]) as $r) {
                    $have[$r['section'] . '|' . $r['label']] = 1;
                    $seq = max($seq, (int) $r['seq'] + 1);
                }
            }
            foreach ($rows as $r) {
                if (isset($have[$r['section'] . '|' . $r['label']])) {
                    continue;
                }
                $st[] = ['INSERT INTO equipment_params (equipment_id, label, unit, section, type, normal_min, normal_max, warn_min, warn_max, options, seq) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
                    [$tid, $r['label'], $r['unit'], $r['section'], $r['type'], $r['normal_min'], $r['normal_max'], $r['warn_min'], $r['warn_max'], $r['options'], $seq++]];
            }
        }
        foreach (array_chunk($st, 200) as $chunk) {
            Db::transaction($chunk);
        }
        return count($targetIds);
    }

    public static function paramApplyTemplate(): array
    {
        Auth::requireRole(...self::MANAGE);
        $in = Http::body();
        $type = (string) ($in['monitor_type'] ?? '');
        if (!Db::query('SELECT 1 FROM dm_monitor_types WHERE code = ?', [$type])) {
            throw new HttpError('Tipe monitoring tidak ditemukan.');
        }
        $rows = Db::query('SELECT * FROM dm_param_templates WHERE monitor_type = ? ORDER BY seq, id', [$type]);
        if (!$rows) {
            throw new HttpError('Template tipe ini masih kosong.', 409);
        }
        $ids = !empty($in['all_of_type'])
            ? array_map('intval', array_column(Db::query('SELECT id FROM equipment WHERE monitor_type = ?', [$type]), 'id'))
            : array_map('intval', (array) ($in['equipment_ids'] ?? [$in['equipment_id'] ?? 0]));
        $n = self::copyParams($rows, $ids, (string) ($in['mode'] ?? 'replace'));
        Db::transaction(array_map(fn ($id) => ['UPDATE equipment SET monitor_type = ?, updated_at = ? WHERE id = ?', [$type, now(), $id]], $ids));
        return ['equipment' => $n, 'params' => count($rows)];
    }

    public static function paramCopy(): array
    {
        Auth::requireRole(...self::MANAGE);
        $in = Http::body();
        $from = (int) ($in['from_equipment_id'] ?? 0);
        $rows = Db::query('SELECT * FROM equipment_params WHERE equipment_id = ? ORDER BY seq, id', [$from]);
        if (!$rows) {
            throw new HttpError('Equipment sumber belum punya parameter.', 409);
        }
        $ids = array_values(array_filter(array_map('intval', (array) ($in['equipment_ids'] ?? [])), fn ($i) => $i !== $from));
        return ['equipment' => self::copyParams($rows, $ids, (string) ($in['mode'] ?? 'append')), 'params' => count($rows)];
    }

    // template (pengaturan dasar parameter)

    public static function templateSave(): array
    {
        Auth::requireRole(...self::MANAGE);
        $in = Http::body();
        $type = (string) ($in['monitor_type'] ?? '');
        if (!Db::query('SELECT 1 FROM dm_monitor_types WHERE code = ?', [$type])) {
            throw new HttpError('Tipe monitoring tidak ditemukan.');
        }
        return self::saveParam('dm_param_templates', 'monitor_type', $type, $in);
    }

    public static function templateDelete(): array
    {
        Auth::requireRole(...self::MANAGE);
        Db::transaction([['DELETE FROM dm_param_templates WHERE id = ?', [(int) (Http::body()['id'] ?? 0)]]]);
        return [];
    }

    public static function templateReorder(): array
    {
        Auth::requireRole(...self::MANAGE);
        $in = Http::body();
        self::reorder('dm_param_templates', 'monitor_type', (string) ($in['monitor_type'] ?? ''), (array) ($in['ids'] ?? []));
        return [];
    }

    public static function typeSave(): array
    {
        Auth::requireRole(...self::MANAGE);
        $in = Http::body();
        $code = strtoupper(preg_replace('~[^A-Za-z0-9]+~', '_', trim((string) ($in['code'] ?? ''))) ?? '');
        $label = trim((string) ($in['label'] ?? ''));
        if ($code === '' || $label === '' || strlen($code) > 30) {
            throw new HttpError('Kode (huruf/angka, maks 30) dan nama tipe wajib diisi.');
        }
        $seq = (int) Db::query('SELECT COALESCE(MAX(seq),0) + 1 s FROM dm_monitor_types')[0]['s'];
        Db::transaction([['INSERT INTO dm_monitor_types (code, label, seq) VALUES (?,?,?) ON CONFLICT(code) DO UPDATE SET label = excluded.label', [$code, $label, $seq]]]);
        return ['code' => $code];
    }

    public static function typeDelete(): array
    {
        Auth::requireRole('admin');
        $code = (string) (Http::body()['code'] ?? '');
        if ((int) Db::query('SELECT COUNT(*) c FROM equipment WHERE monitor_type = ?', [$code])[0]['c'] > 0) {
            throw new HttpError('Tipe masih dipakai equipment.', 409);
        }
        Db::transaction([['DELETE FROM dm_param_templates WHERE monitor_type = ?', [$code]], ['DELETE FROM dm_monitor_types WHERE code = ?', [$code]]]);
        return [];
    }

    // ───────────────────────── papan Monitor & form ─────────────────────────

    public static function board(): array
    {
        Auth::requireRole(...self::STAFF);
        $where = ['EXISTS (SELECT 1 FROM equipment_params p WHERE p.equipment_id = e.id)'];
        $p = [];
        if (($u = (int) Http::query('unit_id', '0')) > 0) {
            $where[] = 'a.unit_id = ?';
            $p[] = $u;
        }
        if (($a = (int) Http::query('area_id', '0')) > 0) {
            $where[] = 'e.area_id = ?';
            $p[] = $a;
        }
        if (($q = trim((string) Http::query('q', ''))) !== '') {
            $where[] = '(e.name LIKE ? OR e.code LIKE ?)';
            array_push($p, "%$q%", "%$q%");
        }
        $rows = Db::query(
            'SELECT e.id, e.name, e.code, e.monitor_type, e.area_id, a.name AS area_name, a.unit_id, u.name AS unit_name,
                    (SELECT COUNT(*) FROM equipment_params p WHERE p.equipment_id = e.id) AS params,
                    (SELECT s.id FROM dm_sessions s WHERE s.equipment_id = e.id ORDER BY s.session_date DESC, s.start_time DESC, s.id DESC LIMIT 1) AS last_id,
                    (SELECT s.session_date FROM dm_sessions s WHERE s.equipment_id = e.id ORDER BY s.session_date DESC, s.start_time DESC, s.id DESC LIMIT 1) AS last_date,
                    (SELECT s.start_time FROM dm_sessions s WHERE s.equipment_id = e.id ORDER BY s.session_date DESC, s.start_time DESC, s.id DESC LIMIT 1) AS last_time,
                    (SELECT s.status FROM dm_sessions s WHERE s.equipment_id = e.id ORDER BY s.session_date DESC, s.start_time DESC, s.id DESC LIMIT 1) AS last_status
             FROM equipment e JOIN areas a ON a.id = e.area_id JOIN units u ON u.id = a.unit_id
             WHERE ' . implode(' AND ', $where) . ' ORDER BY u.id, a.name, e.name',
            $p
        );
        return ['rows' => $rows, 'today' => date('Y-m-d')];
    }

    /** Data form entry: equipment + parameter + (opsional) nilai sesi yang diedit + pembacaan sebelumnya. */
    public static function form(): array
    {
        Auth::requireRole(...self::STAFF);
        $sid = (string) Http::query('id', '');
        $session = null;
        $items = [];
        if ($sid !== '') {
            $session = self::findSession($sid);
            $items = Db::query('SELECT * FROM dm_items WHERE session_id = ? ORDER BY seq', [$sid]);
            $eqId = (int) $session['equipment_id'];
        } else {
            $eqId = (int) Http::query('equipment_id', '0');
        }
        $eq = Db::query(
            'SELECT e.id, e.name, e.code, e.monitor_type, a.name AS area_name, a.unit_id, u.name AS unit_name
             FROM equipment e JOIN areas a ON a.id = e.area_id JOIN units u ON u.id = a.unit_id WHERE e.id = ?',
            [$eqId]
        )[0] ?? null;
        if (!$eq) {
            throw new HttpError('Equipment tidak ditemukan.', 404);
        }
        $params = self::paramsOf($eqId);
        if (!$params) {
            throw new HttpError('Equipment ini belum punya parameter monitoring. Atur dulu di Equipment Database.', 409);
        }
        // pembacaan terakhir per label (sebelum sesi yang sedang diedit)
        $prev = Db::query(
            'SELECT i.label, i.value, i.status, s.session_date FROM dm_items i JOIN dm_sessions s ON s.id = i.session_id
             WHERE s.equipment_id = ? AND i.value != \'\' AND s.id != ? ORDER BY s.session_date DESC, s.start_time DESC, s.id DESC LIMIT 400',
            [$eqId, $sid]
        );
        $last = [];
        foreach ($prev as $r) {
            $last[$r['label']] ??= ['value' => $r['value'], 'status' => $r['status'], 'date' => $r['session_date']];
        }
        return ['equipment' => $eq, 'params' => $params, 'session' => $session, 'items' => $items, 'last' => (object) $last];
    }

    // ───────────────────────── simpan & hapus sesi ─────────────────────────

    public static function save(): array
    {
        $me = Auth::requireRole(...self::STAFF);
        $in = Http::body();
        $sid = (string) ($in['id'] ?? '');
        $old = $sid !== '' ? self::findSession($sid) : null;
        if ($old && (string) $old['created_by'] !== $me['name'] && !in_array($me['role'], self::MANAGE, true)) {
            throw new HttpError('Hanya pembuat sesi atau SPV/Admin yang bisa mengedit.', 403);
        }
        $eqId = $old ? (int) $old['equipment_id'] : (int) ($in['equipment_id'] ?? 0);
        $eq = Db::query('SELECT e.id, e.area_id, a.unit_id FROM equipment e JOIN areas a ON a.id = e.area_id WHERE e.id = ?', [$eqId])[0] ?? null;
        if (!$eq) {
            throw new HttpError('Equipment tidak ditemukan.', 404);
        }
        $date = (string) ($in['date'] ?? date('Y-m-d'));
        if (!preg_match('~^\d{4}-\d{2}-\d{2}$~', $date) || $date > date('Y-m-d')) {
            throw new HttpError('Tanggal tidak valid (tidak boleh di masa depan).');
        }
        $pic = $in['pic'] ?? '';
        $pic = trim(is_array($pic) ? implode(', ', array_map('trim', $pic)) : (string) $pic);
        if ($pic === '') {
            throw new HttpError('PIC wajib diisi.');
        }
        foreach (['start_time', 'end_time'] as $k) {
            if (($in[$k] ?? '') !== '' && !preg_match('~^\d{2}:\d{2}$~', (string) $in[$k])) {
                throw new HttpError('Format jam harus HH:MM.');
            }
        }

        $params = array_column(self::paramsOf($eqId), null, 'id');
        $given = [];
        foreach ((array) ($in['items'] ?? []) as $it) {
            $given[(int) ($it['param_id'] ?? 0)] = $it;
        }
        $items = [];
        $seq = 0;
        foreach ($params as $pid => $p) {
            $it = $given[$pid] ?? null;
            $raw = trim((string) ($it['value'] ?? ''));
            $note = trim((string) ($it['note'] ?? '')) ?: null;
            if ($raw === '' && $note === null) {
                continue;
            }
            $value = $raw;
            $status = '';
            if ($raw !== '') {
                if ($p['type'] === 'numeric') {
                    $num = self::parseNumber($raw);
                    if ($num === null) {
                        throw new HttpError("“{$p['label']}”: isi angka saja, contoh 28,7 (nilai “{$raw}” tidak valid).");
                    }
                    $value = rtrim(rtrim(number_format($num, 6, '.', ''), '0'), '.');
                    $value = $value === '' || $value === '-' ? '0' : $value;
                    $status = self::numericStatus($p, $num);
                } else {
                    $status = self::optionStatus($p['options'], $raw)
                        ?? throw new HttpError("“{$p['label']}”: pilihan “{$raw}” tidak ada di opsi parameter.");
                }
            }
            $items[] = [$seq++, $pid, $p['label'], $p['unit'], $p['section'], $value, $status, $note];
        }
        if (!array_filter($items, fn ($i) => $i[6] !== '')) {
            throw new HttpError('Isi minimal satu nilai parameter.');
        }
        $overall = self::worst(array_column($items, 6));
        $ts = now();
        $st = [];
        if ($old) {
            $id = $sid;
            $st[] = ['UPDATE dm_sessions SET session_date=?, pic=?, start_time=?, end_time=?, status=?, catatan=?, tindakan=?, updated_at=? WHERE id=?',
                [$date, $pic, $in['start_time'] ?: null, $in['end_time'] ?: null, $overall, trim((string) ($in['catatan'] ?? '')) ?: null, trim((string) ($in['tindakan'] ?? '')) ?: null, $ts, $id]];
            $st[] = ['DELETE FROM dm_items WHERE session_id = ?', [$id]];
        } else {
            $id = self::nextId((int) $eq['unit_id'], $date);
            $st[] = ['INSERT INTO dm_sessions (id, session_date, equipment_id, unit_id, area_id, pic, start_time, end_time, status, catatan, tindakan, source, created_by, created_at, updated_at)
                      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [$id, $date, $eqId, $eq['unit_id'], $eq['area_id'], $pic, $in['start_time'] ?? null ?: null, $in['end_time'] ?? null ?: null, $overall,
                    trim((string) ($in['catatan'] ?? '')) ?: null, trim((string) ($in['tindakan'] ?? '')) ?: null, 'app', $me['name'], $ts, $ts]];
        }
        foreach ($items as [$s, $pid, $label, $unit, $section, $value, $status, $note]) {
            $st[] = ['INSERT INTO dm_items (session_id, seq, param_id, label, unit, section, value, status, note) VALUES (?,?,?,?,?,?,?,?,?)', [$id, $s, $pid, $label, $unit, $section, $value, $status, $note]];
        }
        Db::transaction($st);
        return ['id' => $id, 'status' => $overall, 'warnings' => count(array_filter($items, fn ($i) => $i[6] === 'WARNING')), 'alerts' => count(array_filter($items, fn ($i) => $i[6] === 'ALERT'))];
    }

    public static function delete(): array
    {
        Auth::requireRole(...self::MANAGE);
        $id = (string) (Http::body()['id'] ?? '');
        self::findSession($id);
        Db::transaction([['DELETE FROM dm_items WHERE session_id = ?', [$id]], ['DELETE FROM dm_sessions WHERE id = ?', [$id]]]);
        return [];
    }

    private static function findSession(string $id): array
    {
        $s = Db::query('SELECT * FROM dm_sessions WHERE id = ?', [$id])[0] ?? null;
        if (!$s) {
            throw new HttpError('Sesi monitoring tidak ditemukan.', 404);
        }
        return $s;
    }

    private static function nextId(int $unitId, string $date): string
    {
        $code = Db::query('SELECT code FROM units WHERE id = ?', [$unitId])[0]['code'] ?? 'XX';
        $prefix = 'DM/' . $code . '/' . str_replace('-', '', $date) . '/';
        $max = Db::query('SELECT MAX(id) m FROM dm_sessions WHERE id LIKE ?', [$prefix . '%'])[0]['m'] ?? null;
        return $prefix . str_pad((string) ($max ? ((int) substr((string) $max, strlen($prefix))) + 1 : 1), 3, '0', STR_PAD_LEFT);
    }

    // ───────────────────────── riwayat ─────────────────────────

    public static function history(): array
    {
        Auth::requireRole(...self::STAFF);
        $where = [];
        $p = [];
        foreach (['unit_id' => 's.unit_id', 'area_id' => 's.area_id', 'equipment_id' => 's.equipment_id'] as $param => $col) {
            if (($v = (int) Http::query($param, '0')) > 0) {
                $where[] = "$col = ?";
                $p[] = $v;
            }
        }
        if (($st = Http::query('status', '')) !== '') {
            $where[] = 's.status = ?';
            $p[] = $st;
        }
        if (($from = Http::query('from', '')) !== '') {
            $where[] = 's.session_date >= ?';
            $p[] = $from;
        }
        if (($to = Http::query('to', '')) !== '') {
            $where[] = 's.session_date <= ?';
            $p[] = $to;
        }
        if (($q = trim((string) Http::query('q', ''))) !== '') {
            $where[] = '(e.name LIKE ? OR s.pic LIKE ? OR s.catatan LIKE ? OR s.id LIKE ?)';
            array_push($p, "%$q%", "%$q%", "%$q%", "%$q%");
        }
        $w = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $limit = min(500, max(1, (int) Http::query('limit', '50')));
        $offset = max(0, (int) Http::query('offset', '0'));
        $from = 'FROM dm_sessions s JOIN equipment e ON e.id = s.equipment_id LEFT JOIN areas a ON a.id = s.area_id LEFT JOIN units u ON u.id = s.unit_id';
        $total = (int) Db::query("SELECT COUNT(*) c $from $w", $p)[0]['c'];
        $rows = Db::query(
            "SELECT s.id, s.session_date, s.start_time, s.end_time, s.pic, s.status, s.wo_id, s.catatan, s.created_by, e.id AS equipment_id, e.name AS equipment_name,
                    a.name AS area_name, u.name AS unit_name,
                    (SELECT COUNT(*) FROM dm_items i WHERE i.session_id = s.id AND i.status = 'WARNING') AS warnings,
                    (SELECT COUNT(*) FROM dm_items i WHERE i.session_id = s.id AND i.status = 'ALERT') AS alerts,
                    (SELECT COUNT(*) FROM dm_items i WHERE i.session_id = s.id) AS items
             $from $w ORDER BY s.session_date DESC, s.start_time DESC, s.id DESC LIMIT $limit OFFSET $offset",
            $p
        );
        return ['total' => $total, 'rows' => $rows];
    }

    public static function session(): array
    {
        Auth::requireRole(...self::STAFF);
        $s = self::findSession((string) Http::query('id', ''));
        $eq = Db::query('SELECT e.id, e.name, a.name AS area_name, u.name AS unit_name FROM equipment e JOIN areas a ON a.id = e.area_id JOIN units u ON u.id = a.unit_id WHERE e.id = ?', [$s['equipment_id']])[0] ?? null;
        $wo = $s['wo_id'] ? (Db::query('SELECT id, status FROM work_orders WHERE id = ?', [$s['wo_id']])[0] ?? null) : null;
        return ['session' => $s, 'equipment' => $eq, 'items' => Db::query('SELECT * FROM dm_items WHERE session_id = ? ORDER BY seq', [$s['id']]), 'wo' => $wo];
    }

    // ───────────────────────── finding / alarm ─────────────────────────

    /**
     * Finding = item WARNING/ALERT. Aktif sampai: ada pembacaan lebih baru untuk parameter yang sama di equipment yang sama,
     * atau Work Order dari sesi tersebut Closed/Cancelled.
     */
    public static function findings(): array
    {
        Auth::requireRole(...self::STAFF);
        return ['rows' => self::findingRows((string) Http::query('state', 'active'), (int) Http::query('unit_id', '0'), (string) Http::query('status', ''), trim((string) Http::query('q', '')))];
    }

    private static function findingRows(string $state, int $unit, string $status, string $q): array
    {
        $where = ["i.status IN ('WARNING','ALERT')"];
        $p = [];
        if ($unit > 0) {
            $where[] = 's.unit_id = ?';
            $p[] = $unit;
        }
        if ($status !== '') {
            $where[] = 'i.status = ?';
            $p[] = $status;
        }
        if ($q !== '') {
            $where[] = '(e.name LIKE ? OR i.label LIKE ?)';
            array_push($p, "%$q%", "%$q%");
        }
        $rows = Db::query(
            "SELECT i.session_id, i.seq, i.label, i.value, i.unit, i.status, i.note, s.session_date, s.start_time, s.wo_id, s.equipment_id,
                    e.name AS equipment_name, a.name AS area_name, u.name AS unit_name, w.status AS wo_status,
                    (s.session_date || ' ' || COALESCE(s.start_time,'') || ' ' || s.id) AS ord
             FROM dm_items i JOIN dm_sessions s ON s.id = i.session_id JOIN equipment e ON e.id = s.equipment_id
             LEFT JOIN areas a ON a.id = s.area_id LEFT JOIN units u ON u.id = s.unit_id LEFT JOIN work_orders w ON w.id = s.wo_id
             WHERE " . implode(' AND ', $where) . ' ORDER BY s.session_date DESC, s.start_time DESC, s.id DESC LIMIT 1500',
            $p
        );
        $latest = [];
        foreach (Db::query("SELECT s.equipment_id, i.label, MAX(s.session_date || ' ' || COALESCE(s.start_time,'') || ' ' || s.id) AS ord
                            FROM dm_items i JOIN dm_sessions s ON s.id = i.session_id WHERE i.status != '' GROUP BY s.equipment_id, i.label") as $r) {
            $latest[$r['equipment_id'] . '|' . $r['label']] = $r['ord'];
        }
        $out = [];
        foreach ($rows as $r) {
            $resolved = '';
            if (in_array($r['wo_status'], ['Closed', 'Cancelled'], true)) {
                $resolved = 'WO selesai';
            } elseif (($latest[$r['equipment_id'] . '|' . $r['label']] ?? $r['ord']) > $r['ord']) {
                $resolved = 'Ada pengecekan lebih baru';
            }
            $r['resolved'] = $resolved;
            $r['active'] = $resolved === '';
            unset($r['ord']);
            if ($state === 'all' || ($state === 'active') === $r['active']) {
                $out[] = $r;
            }
        }
        usort($out, fn ($a, $b) => ($a['status'] === 'ALERT' ? 0 : 1) <=> ($b['status'] === 'ALERT' ? 0 : 1));
        return $out;
    }

    /** Buat Work Order dari temuan satu sesi; tiap temuan jadi item Tasklist. */
    public static function createWo(): array
    {
        $me = Auth::requireRole(...self::STAFF);
        $s = self::findSession((string) (Http::body()['session_id'] ?? ''));
        if ($s['wo_id']) {
            throw new HttpError('Sesi ini sudah punya WO: ' . $s['wo_id'], 409);
        }
        $findings = Db::query("SELECT * FROM dm_items WHERE session_id = ? AND status IN ('WARNING','ALERT') ORDER BY seq", [$s['id']]);
        if (!$findings) {
            throw new HttpError('Sesi ini tidak punya temuan WARNING/ALERT.', 409);
        }
        $eq = Db::query('SELECT name FROM equipment WHERE id = ?', [$s['equipment_id']])[0]['name'];
        $alerts = count(array_filter($findings, fn ($f) => $f['status'] === 'ALERT'));
        $lines = array_map(fn ($f) => "• {$f['label']}: {$f['value']} {$f['unit']} [{$f['status']}]" . ($f['note'] ? " — {$f['note']}" : ''), $findings);
        $desc = "Temuan Daily Monitoring {$s['session_date']} oleh {$s['pic']} (sesi {$s['id']}):\n" . implode("\n", $lines) . ($s['catatan'] ? "\n\nCatatan: {$s['catatan']}" : '');
        $res = WorkOrderApi::make([
            'title' => "[DM] $eq: " . count($findings) . ' temuan' . ($alerts ? " ($alerts ALERT)" : ''),
            'equipment_id' => (int) $s['equipment_id'], 'type' => 'Troubleshooting', 'priority' => $alerts ? 'High' : 'Medium',
            'description' => $desc, 'source' => 'dm', 'source_ref' => $s['id'],
            'tasks' => array_map(fn ($f) => "{$f['label']}: {$f['value']} {$f['unit']} [{$f['status']}]", $findings),
        ], $me, fn (string $woId) => [['UPDATE dm_sessions SET wo_id = ?, updated_at = ? WHERE id = ?', [$woId, now(), $s['id']]]]);
        return ['wo_id' => $res['id']];
    }

    // ───────────────────────── dashboard ─────────────────────────

    public static function dashboard(): array
    {
        Auth::requireRole(...self::STAFF);
        $today = date('Y-m-d');
        $month = date('Y-m');
        $c = fn (string $sql, array $p = []) => (int) Db::query($sql, $p)[0]['c'];
        $monitorable = $c('SELECT COUNT(DISTINCT equipment_id) c FROM equipment_params');
        $since7 = date('Y-m-d', strtotime('-6 days'));
        $items = array_column(Db::query("SELECT status, COUNT(*) c FROM dm_items WHERE status != '' GROUP BY status"), 'c', 'status');
        $series = array_column(Db::query('SELECT session_date d, COUNT(*) c FROM dm_sessions WHERE session_date >= ? GROUP BY d', [date('Y-m-d', strtotime('-29 days'))]), 'c', 'd');
        $trend = [];
        for ($i = 29; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-$i days"));
            $trend[] = ['date' => $d, 'sessions' => (int) ($series[$d] ?? 0)];
        }
        $f = self::findingsSummary();
        return [
            'kpi' => [
                'today' => $c('SELECT COUNT(*) c FROM dm_sessions WHERE session_date = ?', [$today]),
                'month' => $c('SELECT COUNT(*) c FROM dm_sessions WHERE substr(session_date,1,7) = ?', [$month]),
                'total' => $c('SELECT COUNT(*) c FROM dm_sessions'),
                'monitorable' => $monitorable,
                'covered_7d' => $c('SELECT COUNT(DISTINCT equipment_id) c FROM dm_sessions WHERE session_date >= ?', [$since7]),
                'never' => $c('SELECT COUNT(*) c FROM (SELECT DISTINCT equipment_id FROM equipment_params) p WHERE NOT EXISTS (SELECT 1 FROM dm_sessions s WHERE s.equipment_id = p.equipment_id)'),
                'findings_active' => $f['active'], 'findings_alert' => $f['alert'],
                'ok' => (int) ($items['OK'] ?? 0), 'warning' => (int) ($items['WARNING'] ?? 0), 'alert' => (int) ($items['ALERT'] ?? 0),
            ],
            'by_unit' => Db::query("SELECT u.name label, SUM(CASE WHEN s.status='OK' THEN 1 ELSE 0 END) ok, SUM(CASE WHEN s.status='WARNING' THEN 1 ELSE 0 END) warning, SUM(CASE WHEN s.status='ALERT' THEN 1 ELSE 0 END) alert
                                    FROM dm_sessions s JOIN units u ON u.id = s.unit_id GROUP BY u.id ORDER BY u.id"),
            'trend' => $trend,
            'top_equipment' => Db::query("SELECT e.id, e.name, a.name AS area, COUNT(*) c FROM dm_items i JOIN dm_sessions s ON s.id = i.session_id JOIN equipment e ON e.id = s.equipment_id JOIN areas a ON a.id = e.area_id
                                          WHERE i.status IN ('WARNING','ALERT') GROUP BY e.id ORDER BY c DESC LIMIT 5"),
            'top_params' => Db::query("SELECT i.label, COUNT(*) c FROM dm_items i WHERE i.status IN ('WARNING','ALERT') GROUP BY i.label ORDER BY c DESC LIMIT 5"),
            'overdue' => Db::query("SELECT e.id, e.name, a.name AS area, MAX(s.session_date) AS last_date FROM (SELECT DISTINCT equipment_id FROM equipment_params) p
                                    JOIN equipment e ON e.id = p.equipment_id JOIN areas a ON a.id = e.area_id LEFT JOIN dm_sessions s ON s.equipment_id = e.id
                                    GROUP BY e.id HAVING MAX(s.session_date) IS NULL OR MAX(s.session_date) < ? ORDER BY MAX(s.session_date) IS NOT NULL, MAX(s.session_date) LIMIT 8", [$since7]),
            'recent' => Db::query("SELECT s.id, s.session_date, s.start_time, s.pic, s.status, e.name AS equipment_name, a.name AS area_name
                                   FROM dm_sessions s JOIN equipment e ON e.id = s.equipment_id LEFT JOIN areas a ON a.id = s.area_id
                                   ORDER BY s.session_date DESC, s.start_time DESC, s.id DESC LIMIT 8"),
        ];
    }

    private static function findingsSummary(): array
    {
        $rows = self::findingRows('active', 0, '', '');
        return ['active' => count($rows), 'alert' => count(array_filter($rows, fn ($r) => $r['status'] === 'ALERT'))];
    }
}
