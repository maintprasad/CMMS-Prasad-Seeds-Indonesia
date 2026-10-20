<?php
declare(strict_types=1);

/**
 * Modul Work Order.
 * Alur: Open → In Progress (timer jalan) ⇄ Hold/Continue → Pending Verification (timer berhenti) → Closed.
 * Durasi WO = jumlah sesi kerja (Start/Continue sampai Hold/Finish).
 */
final class WorkOrderApi
{
    public const TYPES = ['Troubleshooting', 'Corrective', 'Improvement', 'Fabrication/Modification', 'Preventive Maintenance'];
    public const PRIORITIES = ['Low', 'Medium', 'High', 'Critical'];
    public const STATUSES = ['Open', 'In Progress', 'Pending Verification', 'Closed', 'Cancelled'];
    /** Tipe yang dianggap kerusakan untuk MTTR/MTBF. */
    private const BREAKDOWN = ['Troubleshooting', 'Corrective'];
    private const WORKERS = ['maintenance', 'spv', 'admin'];
    private const LEADERS = ['spv', 'admin'];

    // ───────────────────────── daftar & detail ─────────────────────────

    public static function list(): array
    {
        $me = Auth::user();
        $where = [];
        $p = [];
        if ($me['role'] === 'user') {
            $where[] = 'w.requestor_id = ?';
            $p[] = $me['id'];
        }
        if (Http::query('mine') === '1') {
            $where[] = '(w.requestor_id = ? OR w.id IN (SELECT wo_id FROM wo_techs t JOIN technicians te ON te.id = t.tech_id WHERE te.user_id = ?))';
            array_push($p, $me['id'], $me['id']);
        }
        foreach (['status', 'type', 'priority'] as $f) {
            if (($v = Http::query($f, '')) !== '') {
                $where[] = "w.$f = ?";
                $p[] = $v;
            }
        }
        foreach (['unit_id', 'equipment_id'] as $f) {
            if (($v = (int) Http::query($f, '0')) > 0) {
                $where[] = "w.$f = ?";
                $p[] = $v;
            }
        }
        if (($t = (int) Http::query('tech_id', '0')) > 0) {
            $where[] = 'w.id IN (SELECT wo_id FROM wo_techs WHERE tech_id = ?)';
            $p[] = $t;
        }
        if (($from = Http::query('from', '')) !== '') {
            $where[] = 'w.created_at >= ?';
            $p[] = $from . ' 00:00:00';
        }
        if (($to = Http::query('to', '')) !== '') {
            $where[] = 'w.created_at <= ?';
            $p[] = $to . ' 23:59:59';
        }
        if (($q = trim((string) Http::query('q', ''))) !== '') {
            $where[] = '(w.id LIKE ? OR w.title LIKE ? OR e.name LIKE ? OR w.requestor_name LIKE ?)';
            array_push($p, "%$q%", "%$q%", "%$q%", "%$q%");
        }
        $w = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $limit = min(500, max(1, (int) Http::query('limit', '100')));
        $offset = max(0, (int) Http::query('offset', '0'));
        $from = 'FROM work_orders w LEFT JOIN equipment e ON e.id = w.equipment_id
                 LEFT JOIN areas a ON a.id = w.area_id LEFT JOIN units u ON u.id = w.unit_id';
        $total = (int) Db::query("SELECT COUNT(*) c $from $w", $p)[0]['c'];
        $rows = Db::query(
            "SELECT w.id, w.title, w.type, w.priority, w.status, w.unit_id, w.equipment_id, w.requestor_name, w.created_at,
                    w.started_at, w.finished_at, w.closed_at, w.due_date, w.work_seconds, w.rating, w.source,
                    e.name AS equipment_name, a.name AS area_name, u.name AS unit_name,
                    (SELECT started_at FROM wo_sessions s WHERE s.wo_id = w.id AND s.ended_at IS NULL LIMIT 1) AS running_since,
                    (SELECT GROUP_CONCAT(te.name, ', ') FROM wo_techs t JOIN technicians te ON te.id = t.tech_id WHERE t.wo_id = w.id) AS techs
             $from $w ORDER BY CASE w.status WHEN 'Open' THEN 0 WHEN 'In Progress' THEN 1 WHEN 'Pending Verification' THEN 2 ELSE 3 END,
                    w.created_at DESC LIMIT $limit OFFSET $offset",
            $p
        );
        foreach ($rows as &$r) {
            $r['work_seconds'] = self::liveSeconds($r);
        }
        $counts = Db::query("SELECT w.status, COUNT(*) c $from " . ($me['role'] === 'user' ? 'WHERE w.requestor_id = ' . (int) $me['id'] : '') . ' GROUP BY w.status');
        return ['total' => $total, 'rows' => $rows, 'counts' => array_column($counts, 'c', 'status')];
    }

    public static function get(): array
    {
        $me = Auth::user();
        $id = (string) Http::query('id', '');
        $wo = self::find($id);
        if ($me['role'] === 'user' && (int) $wo['requestor_id'] !== $me['id']) {
            throw new HttpError('Work Order ini bukan permintaan Anda.', 403);
        }
        $wo['equipment'] = $wo['equipment_id'] ? (Db::query(
            'SELECT e.id, e.name, e.code, e.brand, e.model, e.serial_no, e.category, a.name AS area_name, u.name AS unit_name, up.name AS pj_production, um.name AS pj_maintenance
             FROM equipment e JOIN areas a ON a.id = e.area_id JOIN units u ON u.id = a.unit_id
             LEFT JOIN users up ON up.id = e.pj_production_id LEFT JOIN users um ON um.id = e.pj_maintenance_id WHERE e.id = ?',
            [$wo['equipment_id']]
        )[0] ?? null) : null;
        $sessions = Db::query('SELECT * FROM wo_sessions WHERE wo_id = ? ORDER BY id', [$id]);
        $running = null;
        foreach ($sessions as $s) {
            if ($s['ended_at'] === null) {
                $running = $s['started_at'];
            }
        }
        $wo['running_since'] = $running;
        $wo['work_seconds'] = self::liveSeconds($wo + ['running_since' => $running]);
        $wo['unit_name'] = Db::query('SELECT name FROM units WHERE id = ?', [$wo['unit_id']])[0]['name'] ?? null;
        return [
            'wo'          => $wo,
            'techs'       => Db::query('SELECT te.id, te.name, te.spec FROM wo_techs t JOIN technicians te ON te.id = t.tech_id WHERE t.wo_id = ?', [$id]),
            'sessions'    => $sessions,
            'parts'       => Db::query('SELECT * FROM wo_parts WHERE wo_id = ? ORDER BY id', [$id]),
            'notes'       => Db::query('SELECT * FROM wo_notes WHERE wo_id = ? ORDER BY id DESC', [$id]),
            'tasks'       => Db::query('SELECT * FROM wo_tasks WHERE wo_id = ? ORDER BY position, id', [$id]),
            'history'     => Db::query('SELECT * FROM wo_history WHERE wo_id = ?', [$id])[0] ?? null,
            'events'      => Db::query('SELECT * FROM wo_events WHERE wo_id = ? ORDER BY id', [$id]),
            'files'       => Db::query('SELECT id, category, name, mime, size, uploaded_by, uploaded_id, created_at FROM wo_files WHERE wo_id = ? ORDER BY id', [$id]),
            'reliability' => $wo['equipment_id'] ? self::reliability((int) $wo['equipment_id']) : null,
            'equipment_wo' => $wo['equipment_id'] ? Db::query(
                'SELECT id, title, type, status, created_at, finished_at, work_seconds FROM work_orders
                 WHERE equipment_id = ? ORDER BY created_at DESC LIMIT 30',
                [$wo['equipment_id']]
            ) : [],
        ];
    }

    // ───────────────────────── buat & ubah status ─────────────────────────

    public static function create(): array
    {
        return self::make(Http::body(), Auth::user());
    }

    /**
     * Membuat WO. Dipakai form WO dan modul lain (mis. Daily Monitoring mengubah temuan jadi WO).
     * $in['tasks'] = daftar teks untuk Tasklist; $extra = pernyataan SQL tambahan (atau closure fn(string $woId): array) yang ikut satu transaksi.
     */
    public static function make(array $in, array $me, callable|array $extra = []): array
    {
        $title = trim((string) ($in['title'] ?? ''));
        $eqId = (int) ($in['equipment_id'] ?? 0);
        $eq = Db::query('SELECT e.id, e.area_id, a.unit_id FROM equipment e JOIN areas a ON a.id = e.area_id WHERE e.id = ?', [$eqId])[0] ?? null;
        if ($title === '' || !$eq) {
            throw new HttpError('Judul dan equipment wajib diisi.');
        }
        $type = in_array($in['type'] ?? '', self::TYPES, true) ? $in['type'] : 'Troubleshooting';
        $priority = in_array($in['priority'] ?? '', self::PRIORITIES, true) ? $in['priority'] : 'Medium';
        $source = in_array($in['source'] ?? '', ['wo', 'dm', 'pm'], true) ? $in['source'] : 'wo';
        $id = self::nextId((int) $eq['unit_id']);
        $ts = now();
        Db::transaction([
            ['INSERT INTO work_orders (id, title, type, priority, status, unit_id, area_id, equipment_id, requestor_id, requestor_name,
                    department, description, due_date, source, source_ref, created_at, updated_at)
              VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [$id, $title, $type, $priority, 'Open', $eq['unit_id'], $eq['area_id'], $eqId, $me['id'],
                    trim((string) ($in['requestor_name'] ?? '')) ?: $me['name'], trim((string) ($in['department'] ?? '')) ?: $me['department'],
                    trim((string) ($in['description'] ?? '')) ?: null, trim((string) ($in['due_date'] ?? '')) ?: null,
                    $source, trim((string) ($in['source_ref'] ?? '')) ?: null, $ts, $ts]],
            self::event($id, 'status', 'WO dibuat oleh ' . $me['name'] . ' (Open)', $me['name'], $ts),
            ...array_map(fn ($t, $i) => ['INSERT INTO wo_tasks (wo_id, position, text) VALUES (?,?,?)', [$id, $i + 1, mb_substr((string) $t, 0, 300)]], (array) ($in['tasks'] ?? []), array_keys((array) ($in['tasks'] ?? []))),
            ...(is_callable($extra) ? $extra($id) : $extra),
        ]);
        return ['id' => $id];
    }

    public static function action(): array
    {
        $me = Auth::user();
        $in = Http::body();
        $id = (string) ($in['id'] ?? '');
        $wo = self::find($id);
        $act = (string) ($in['action'] ?? '');
        $ts = now();
        $st = [];
        $running = Db::query('SELECT id, started_at FROM wo_sessions WHERE wo_id = ? AND ended_at IS NULL', [$id])[0] ?? null;
        $isOwner = (int) $wo['requestor_id'] === $me['id'];
        $status = $wo['status'];

        switch ($act) {
            case 'assign':
                Auth::requireRole(...self::LEADERS);
                self::mustNotBeFinal($wo);
                $ids = array_values(array_unique(array_map('intval', (array) ($in['tech_ids'] ?? []))));
                $st[] = ['DELETE FROM wo_techs WHERE wo_id = ?', [$id]];
                foreach ($ids as $t) {
                    $st[] = ['INSERT INTO wo_techs (wo_id, tech_id) VALUES (?, ?)', [$id, $t]];
                }
                $names = $ids ? array_column(Db::query('SELECT name FROM technicians WHERE id IN (' . implode(',', $ids) . ')'), 'name') : [];
                $st[] = self::event($id, 'assign', 'Teknisi ditugaskan: ' . ($names ? implode(', ', $names) : '(kosong)'), $me['name'], $ts);
                break;

            case 'start':
                Auth::requireRole(...self::WORKERS);
                self::need($status === 'Open', 'WO hanya bisa dimulai dari status Open.');
                $st[] = ['UPDATE work_orders SET status = ?, started_at = COALESCE(started_at, ?) WHERE id = ?', ['In Progress', $ts, $id]];
                $st[] = ['INSERT INTO wo_sessions (wo_id, started_at, by_user) VALUES (?,?,?)', [$id, $ts, $me['name']]];
                $st = array_merge($st, self::autoAssign($id, $me));
                $st[] = self::event($id, 'status', 'Pengerjaan dimulai (In Progress) — timer berjalan', $me['name'], $ts);
                break;

            case 'hold':
                Auth::requireRole(...self::WORKERS);
                self::need($status === 'In Progress' && $running, 'Hold hanya bisa saat timer berjalan.');
                $note = trim((string) ($in['note'] ?? ''));
                self::need($note !== '', 'Tulis pekerjaan hari ini sebelum Hold.');
                $st[] = ['UPDATE wo_sessions SET ended_at = ?, end_reason = ?, note = ? WHERE id = ?', [$ts, 'hold', $note, $running['id']]];
                $st[] = ['INSERT INTO wo_notes (wo_id, author, note, created_at) VALUES (?,?,?,?)', [$id, $me['name'], "[Hold] $note", $ts]];
                $st[] = self::addSeconds($id, $running['started_at'], $ts);
                $st[] = self::event($id, 'hold', 'Hold — timer dihentikan. ' . $note, $me['name'], $ts);
                break;

            case 'continue':
                Auth::requireRole(...self::WORKERS);
                self::need($status === 'In Progress' && !$running, 'Continue hanya bisa saat WO sedang di-Hold.');
                $st[] = ['INSERT INTO wo_sessions (wo_id, started_at, by_user) VALUES (?,?,?)', [$id, $ts, $me['name']]];
                $st = array_merge($st, self::autoAssign($id, $me));
                $st[] = self::event($id, 'continue', 'Continue — timer berjalan lagi', $me['name'], $ts);
                break;

            case 'finish':
                Auth::requireRole(...self::WORKERS);
                self::need($status === 'In Progress', 'WO belum In Progress.');
                $note = trim((string) ($in['note'] ?? ''));
                self::need($note !== '', 'Catatan penutupan wajib diisi.');
                if ($running) {
                    $st[] = ['UPDATE wo_sessions SET ended_at = ?, end_reason = ?, note = ? WHERE id = ?', [$ts, 'finish', $note, $running['id']]];
                    $st[] = self::addSeconds($id, $running['started_at'], $ts);
                }
                $st[] = ['UPDATE work_orders SET status = ?, finished_at = ?, closing_note = ? WHERE id = ?', ['Pending Verification', $ts, $note, $id]];
                $st[] = self::event($id, 'status', 'Pekerjaan selesai — menunggu verifikasi user. ' . $note, $me['name'], $ts);
                break;

            case 'verify':
                self::need($status === 'Pending Verification', 'WO belum menunggu verifikasi.');
                self::need($isOwner || in_array($me['role'], self::LEADERS, true), 'Hanya pemohon WO (atau SPV/Admin) yang bisa verifikasi.');
                $st[] = ['UPDATE work_orders SET status = ?, closed_at = ? WHERE id = ?', ['Closed', $ts, $id]];
                $rating = (int) ($in['rating'] ?? 0);
                if ($rating >= 1 && $rating <= 5) {
                    $st[] = ['UPDATE work_orders SET rating = ?, rating_comment = ? WHERE id = ?', [$rating, trim((string) ($in['rating_comment'] ?? '')) ?: null, $id]];
                }
                $st[] = self::event($id, 'status', 'Diverifikasi — WO Closed' . ($rating ? " (rating $rating/5)" : ''), $me['name'], $ts);
                break;

            case 'reject':
                self::need($status === 'Pending Verification', 'WO belum menunggu verifikasi.');
                self::need($isOwner || in_array($me['role'], self::LEADERS, true), 'Hanya pemohon WO (atau SPV/Admin) yang bisa menolak.');
                $reason = trim((string) ($in['note'] ?? ''));
                self::need($reason !== '', 'Alasan penolakan wajib diisi.');
                $st[] = ['UPDATE work_orders SET status = ?, finished_at = NULL WHERE id = ?', ['In Progress', $id]];
                $st[] = ['INSERT INTO wo_notes (wo_id, author, note, created_at) VALUES (?,?,?,?)', [$id, $me['name'], "[Verifikasi ditolak] $reason", $ts]];
                $st[] = self::event($id, 'status', 'Verifikasi ditolak, kembali In Progress (Hold). ' . $reason, $me['name'], $ts);
                break;

            case 'cancel':
                self::need($status === 'Open' || in_array($me['role'], self::LEADERS, true), 'WO yang sudah berjalan hanya bisa dibatalkan SPV/Admin.');
                self::need($isOwner || in_array($me['role'], self::LEADERS, true), 'Anda tidak berhak membatalkan WO ini.');
                self::mustNotBeFinal($wo);
                if ($running) {
                    $st[] = ['UPDATE wo_sessions SET ended_at = ?, end_reason = ? WHERE id = ?', [$ts, 'cancel', $running['id']]];
                    $st[] = self::addSeconds($id, $running['started_at'], $ts);
                }
                $st[] = ['UPDATE work_orders SET status = ?, closed_at = ? WHERE id = ?', ['Cancelled', $ts, $id]];
                $st[] = self::event($id, 'status', 'WO dibatalkan. ' . trim((string) ($in['note'] ?? '')), $me['name'], $ts);
                break;

            default:
                throw new HttpError('Aksi tidak dikenal.');
        }
        $st[] = ['UPDATE work_orders SET updated_at = ? WHERE id = ?', [$ts, $id]];
        Db::transaction($st);
        return ['status' => Db::query('SELECT status FROM work_orders WHERE id = ?', [$id])[0]['status']];
    }

    // ───────────────────────── detail: notes, parts, tasks, history ─────────────────────────

    public static function addNote(): array
    {
        $me = Auth::user();
        $in = Http::body();
        $id = (string) ($in['id'] ?? '');
        $wo = self::find($id);
        $note = trim((string) ($in['note'] ?? ''));
        self::need($note !== '', 'Catatan tidak boleh kosong.');
        self::need($me['role'] !== 'user' || (int) $wo['requestor_id'] === $me['id'], 'Bukan WO Anda.');
        Db::transaction([['INSERT INTO wo_notes (wo_id, author, note, created_at) VALUES (?,?,?,?)', [$id, $me['name'], $note, now()]]]);
        return [];
    }

    /** Part dari MaintWare otomatis mengurangi stok (transaksi OUT terhubung ke WO & equipment). */
    public static function addPart(): array
    {
        $me = Auth::requireRole(...self::WORKERS);
        $in = Http::body();
        $id = (string) ($in['id'] ?? '');
        $wo = self::find($id);
        self::mustNotBeFinal($wo);
        $qty = (float) ($in['qty'] ?? 0);
        self::need($qty > 0, 'Qty harus lebih dari 0.');
        $item = ($iid = (string) ($in['item_id'] ?? '')) !== '' ? (Db::query('SELECT * FROM mw_items WHERE id = ?', [$iid])[0] ?? null) : null;
        $name = $item['name'] ?? trim((string) ($in['name'] ?? ''));
        self::need($name !== '', 'Pilih item dari MaintWare atau isi nama part.');
        $ts = now();
        $st = [];
        if ($item) {
            $st = MaintWareApi::stockStatements($item, 'OUT', $qty, ['pic' => $me['name'], 'notes' => "WO {$id}: " . $wo['title'], 'by' => $me['name'],
                'unit_id' => $wo['unit_id'], 'area_id' => $wo['area_id'], 'equipment_id' => $wo['equipment_id'], 'wo_id' => $id, 'source' => 'WO']);
        }
        $st[] = ['INSERT INTO wo_parts (wo_id, item_id, name, qty, uom, unit_id, added_by, added_at) VALUES (?,?,?,?,?,?,?,?)',
            [$id, $item['id'] ?? null, $name, $qty, $item['uom'] ?? (trim((string) ($in['uom'] ?? '')) ?: 'pcs'), $item['unit_id'] ?? null, $me['name'], $ts]];
        $st[] = self::event($id, 'parts', "Part dipakai: $name × $qty" . ($item ? ' (stok MaintWare berkurang)' : ''), $me['name'], $ts);
        Db::transaction($st);
        return [];
    }

    public static function removePart(): array
    {
        $me = Auth::requireRole(...self::WORKERS);
        $in = Http::body();
        $part = Db::query('SELECT * FROM wo_parts WHERE id = ?', [(int) ($in['part_id'] ?? 0)])[0] ?? null;
        self::need((bool) $part, 'Part tidak ditemukan.');
        $wo = self::find($part['wo_id']);
        self::mustNotBeFinal($wo);
        $st = [];
        $item = $part['item_id'] ? (Db::query('SELECT * FROM mw_items WHERE id = ?', [$part['item_id']])[0] ?? null) : null;
        if ($item) { // kembalikan stok
            $st = MaintWareApi::stockStatements($item, 'IN', (float) $part['qty'], ['pic' => $me['name'], 'notes' => "Batal pemakaian WO {$wo['id']}", 'by' => $me['name'],
                'unit_id' => $wo['unit_id'], 'area_id' => $wo['area_id'], 'equipment_id' => $wo['equipment_id'], 'wo_id' => $wo['id'], 'source' => 'WO']);
        }
        $st[] = ['DELETE FROM wo_parts WHERE id = ?', [$part['id']]];
        $st[] = self::event($part['wo_id'], 'parts', "Part dihapus: {$part['name']}" . ($item ? ' (stok dikembalikan)' : ''), $me['name'], now());
        Db::transaction($st);
        return [];
    }

    // ───────────────────────── foto & lampiran ─────────────────────────

    private const PHOTO_MAX = 2_000_000;   // byte, setelah dikompres di browser
    private const ATTACH_MAX = 3_000_000;  // body request Vercel ±4,5 MB → base64 3 MB masih muat
    private const FILES_MAX = 40;
    private const CATEGORIES = ['before', 'after', 'verification', 'attachment'];
    private const DOC_MIME = [
        'application/pdf' => 'pdf', 'text/plain' => 'txt', 'text/csv' => 'csv',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/msword' => 'doc', 'application/vnd.ms-excel' => 'xls',
    ];

    /** Siapa boleh mengunggah ke kategori mana (WO belum Closed/Cancelled). */
    private static function canUpload(string $category, array $wo, array $me): bool
    {
        $owner = (int) $wo['requestor_id'] === $me['id'];
        $worker = in_array($me['role'], self::WORKERS, true);
        return match ($category) {
            'before', 'attachment' => $owner || $worker,
            'after' => $worker,
            'verification' => $owner || in_array($me['role'], self::LEADERS, true),
            default => false,
        };
    }

    public static function uploadFile(): array
    {
        $me = Auth::user();
        $in = Http::body();
        $wo = self::find((string) ($in['id'] ?? ''));
        self::mustNotBeFinal($wo);
        $cat = (string) ($in['category'] ?? '');
        self::need(in_array($cat, self::CATEGORIES, true), 'Kategori file tidak valid.');
        if (!self::canUpload($cat, $wo, $me)) {
            throw new HttpError('Anda tidak boleh mengunggah ke kategori ini.', 403);
        }
        self::need((int) Db::query('SELECT COUNT(*) c FROM wo_files WHERE wo_id = ?', [$wo['id']])[0]['c'] < self::FILES_MAX, 'Jumlah file WO ini sudah mencapai batas (' . self::FILES_MAX . ').');

        $b64 = (string) ($in['data'] ?? '');
        $raw = base64_decode($b64, true);
        self::need($raw !== false && $raw !== '', 'Data file tidak valid.');
        $size = strlen($raw);
        // Jenis file ditentukan dari isi file, bukan dari nama/mime kiriman klien
        $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($raw) ?: 'application/octet-stream';
        $isImage = in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true);
        if ($cat === 'attachment') {
            self::need($isImage || isset(self::DOC_MIME[$mime]) || $mime === 'application/zip', 'Jenis file tidak didukung (pakai gambar, PDF, Word, Excel, TXT, CSV).');
            if ($mime === 'application/zip') { // docx/xlsx terdeteksi sebagai zip bila finfo tidak mengenali — cek ekstensi
                $ext = strtolower(pathinfo((string) ($in['name'] ?? ''), PATHINFO_EXTENSION));
                self::need(in_array($ext, ['docx', 'xlsx'], true), 'Jenis file tidak didukung.');
            }
            self::need($size <= self::ATTACH_MAX, 'Ukuran lampiran maksimal ' . (self::ATTACH_MAX / 1_000_000) . ' MB.');
        } else {
            self::need($isImage, 'Foto harus berformat JPG, PNG, atau WebP.');
            self::need($size <= self::PHOTO_MAX, 'Ukuran foto maksimal ' . (self::PHOTO_MAX / 1_000_000) . ' MB.');
        }
        $name = preg_replace('~[^\w.\- ()]+~u', '_', basename((string) ($in['name'] ?? 'file'))) ?: 'file';
        $name = mb_substr($name, 0, 120);
        $ts = now();
        $label = ['before' => 'foto Before', 'after' => 'foto After', 'verification' => 'foto Verifikasi', 'attachment' => 'lampiran'][$cat];
        Db::transaction([
            ['INSERT INTO wo_files (wo_id, category, name, mime, size, data, uploaded_by, uploaded_id, created_at) VALUES (?,?,?,?,?,?,?,?,?)',
                [$wo['id'], $cat, $name, $mime, $size, base64_encode($raw), $me['name'], $me['id'], $ts]],
            self::event($wo['id'], 'file', "Unggah $label: $name", $me['name'], $ts),
        ]);
        return ['id' => (int) Db::query('SELECT MAX(id) m FROM wo_files WHERE wo_id = ?', [$wo['id']])[0]['m']];
    }

    /** Mengirim isi file (bukan JSON). Browser mengambilnya dengan fetch + token, lalu membuat blob URL. */
    public static function downloadFile(): never
    {
        $me = Auth::user();
        $f = Db::query('SELECT * FROM wo_files WHERE id = ?', [(int) Http::query('id', '0')])[0] ?? null;
        if (!$f) {
            throw new HttpError('File tidak ditemukan.', 404);
        }
        $wo = self::find($f['wo_id']);
        if ($me['role'] === 'user' && (int) $wo['requestor_id'] !== $me['id']) {
            throw new HttpError('Work Order ini bukan permintaan Anda.', 403);
        }
        $raw = (string) base64_decode((string) $f['data']);
        $inline = str_starts_with((string) $f['mime'], 'image/');
        http_response_code(200);
        header('Content-Type: ' . $f['mime']);
        header('Content-Length: ' . strlen($raw));
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . str_replace('"', '', (string) $f['name']) . '"');
        header('Cache-Control: private, max-age=3600');
        echo $raw;
        exit;
    }

    public static function deleteFile(): array
    {
        $me = Auth::user();
        $f = Db::query('SELECT id, wo_id, name, uploaded_id FROM wo_files WHERE id = ?', [(int) (Http::body()['file_id'] ?? 0)])[0] ?? null;
        self::need((bool) $f, 'File tidak ditemukan.');
        self::mustNotBeFinal(self::find($f['wo_id']));
        if ((int) $f['uploaded_id'] !== $me['id'] && !in_array($me['role'], self::LEADERS, true)) {
            throw new HttpError('Hanya pengunggah atau SPV/Admin yang bisa menghapus file ini.', 403);
        }
        Db::transaction([
            ['DELETE FROM wo_files WHERE id = ?', [$f['id']]],
            self::event($f['wo_id'], 'file', "File dihapus: {$f['name']}", $me['name'], now()),
        ]);
        return [];
    }

    public static function addTask(): array
    {
        $me = Auth::requireRole(...self::LEADERS);
        $in = Http::body();
        $id = (string) ($in['id'] ?? '');
        self::find($id);
        $text = trim((string) ($in['text'] ?? ''));
        self::need($text !== '', 'Isi task tidak boleh kosong.');
        $pos = (int) Db::query('SELECT COALESCE(MAX(position), 0) + 1 p FROM wo_tasks WHERE wo_id = ?', [$id])[0]['p'];
        Db::transaction([
            ['INSERT INTO wo_tasks (wo_id, position, text) VALUES (?,?,?)', [$id, $pos, $text]],
            self::event($id, 'task', "Task ditambah: $text", $me['name'], now()),
        ]);
        return [];
    }

    public static function toggleTask(): array
    {
        $me = Auth::requireRole(...self::WORKERS);
        $task = Db::query('SELECT * FROM wo_tasks WHERE id = ?', [(int) (Http::body()['task_id'] ?? 0)])[0] ?? null;
        self::need((bool) $task, 'Task tidak ditemukan.');
        self::mustNotBeFinal(self::find($task['wo_id']));
        $done = (int) $task['done'] === 1 ? 0 : 1;
        Db::transaction([['UPDATE wo_tasks SET done = ?, done_by = ?, done_at = ? WHERE id = ?', [$done, $done ? $me['name'] : null, $done ? now() : null, $task['id']]]]);
        return ['done' => $done];
    }

    public static function removeTask(): array
    {
        Auth::requireRole(...self::LEADERS);
        Db::transaction([['DELETE FROM wo_tasks WHERE id = ?', [(int) (Http::body()['task_id'] ?? 0)]]]);
        return [];
    }

    /** Equipment History (apa/kapan/di mana/siapa/bagaimana/kenapa) — Leader/SPV, opsional. */
    public static function saveHistory(): array
    {
        $me = Auth::requireRole(...self::LEADERS);
        $in = Http::body();
        $id = (string) ($in['id'] ?? '');
        self::find($id);
        $v = fn ($k) => trim((string) ($in[$k] ?? '')) ?: null;
        $ts = now();
        Db::transaction([
            ['DELETE FROM wo_history WHERE wo_id = ?', [$id]],
            ['INSERT INTO wo_history (wo_id, what_text, when_text, where_text, who_text, how_text, why_text, updated_by, updated_at)
              VALUES (?,?,?,?,?,?,?,?,?)', [$id, $v('what'), $v('when'), $v('where'), $v('who'), $v('how'), $v('why'), $me['name'], $ts]],
            self::event($id, 'history', 'Equipment History diperbarui', $me['name'], $ts),
        ]);
        return [];
    }

    public static function historyList(): array
    {
        Auth::requireRole('admin', 'spv', 'maintenance');
        $where = [];
        $p = [];
        if (($e = (int) Http::query('equipment_id', '0')) > 0) {
            $where[] = 'w.equipment_id = ?';
            $p[] = $e;
        }
        if (($q = trim((string) Http::query('q', ''))) !== '') {
            $where[] = '(e.name LIKE ? OR w.title LIKE ? OR h.what_text LIKE ? OR h.why_text LIKE ?)';
            array_push($p, "%$q%", "%$q%", "%$q%", "%$q%");
        }
        $w = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        return ['rows' => Db::query(
            "SELECT h.*, w.title, w.status, w.created_at AS wo_created, e.name AS equipment_name
             FROM wo_history h JOIN work_orders w ON w.id = h.wo_id LEFT JOIN equipment e ON e.id = w.equipment_id
             $w ORDER BY h.updated_at DESC LIMIT 200",
            $p
        )];
    }

    // ───────────────────────── laporan & statistik ─────────────────────────

    public static function stats(): array
    {
        $me = Auth::user();
        $scope = $me['role'] === 'user' ? ' WHERE requestor_id = ' . (int) $me['id'] : '';
        $and = $scope ? str_replace('WHERE', 'AND', $scope) : '';
        $byStatus = array_column(Db::query("SELECT status, COUNT(*) c FROM work_orders$scope GROUP BY status"), 'c', 'status');
        $since = date('Y-m-d', strtotime('-29 days'));
        $created = array_column(Db::query("SELECT substr(created_at,1,10) d, COUNT(*) c FROM work_orders WHERE created_at >= ? $and GROUP BY d", [$since]), 'c', 'd');
        $closed = array_column(Db::query("SELECT substr(finished_at,1,10) d, COUNT(*) c FROM work_orders WHERE finished_at >= ? $and GROUP BY d", [$since]), 'c', 'd');
        $series = [];
        for ($i = 29; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-$i days"));
            $series[] = ['date' => $d, 'in' => (int) ($created[$d] ?? 0), 'out' => (int) ($closed[$d] ?? 0)];
        }
        return [
            'by_status'    => $byStatus,
            'by_type'      => Db::query("SELECT type, COUNT(*) c FROM work_orders$scope GROUP BY type ORDER BY c DESC"),
            'by_unit'      => Db::query("SELECT u.name, COUNT(*) c FROM work_orders w JOIN units u ON u.id = w.unit_id" . ($scope ? ' WHERE w.requestor_id = ' . (int) $me['id'] : '') . ' GROUP BY u.name'),
            'series'       => $series,
            'avg_seconds'  => (int) (Db::query("SELECT AVG(work_seconds) a FROM work_orders WHERE status IN ('Pending Verification','Closed') AND work_seconds > 0 $and")[0]['a'] ?? 0),
            'top_equipment' => Db::query("SELECT e.id, e.name, COUNT(*) c FROM work_orders w JOIN equipment e ON e.id = w.equipment_id" . ($scope ? ' WHERE w.requestor_id = ' . (int) $me['id'] : '') . ' GROUP BY e.id, e.name ORDER BY c DESC LIMIT 8'),
        ];
    }

    /** Menu "in / out": WO masuk (dibuat) vs keluar (selesai) per hari atau bulan. */
    public static function comparison(): array
    {
        Auth::user();
        $group = Http::query('group', 'month') === 'day' ? 10 : 7;
        $from = Http::query('from', date('Y-m-01', strtotime('-5 months')));
        $to = Http::query('to', date('Y-m-d'));
        $unit = (int) Http::query('unit_id', '0');
        $u = $unit > 0 ? ' AND unit_id = ' . $unit : '';
        $in = array_column(Db::query("SELECT substr(created_at,1,$group) k, COUNT(*) c FROM work_orders WHERE created_at BETWEEN ? AND ? $u GROUP BY k", [$from . ' 00:00:00', $to . ' 23:59:59']), 'c', 'k');
        $out = array_column(Db::query("SELECT substr(finished_at,1,$group) k, COUNT(*) c FROM work_orders WHERE finished_at BETWEEN ? AND ? $u GROUP BY k", [$from . ' 00:00:00', $to . ' 23:59:59']), 'c', 'k');
        $keys = array_unique(array_merge(array_keys($in), array_keys($out)));
        sort($keys);
        $rows = array_map(fn ($k) => ['period' => $k, 'in' => (int) ($in[$k] ?? 0), 'out' => (int) ($out[$k] ?? 0)], $keys);
        return ['rows' => $rows];
    }

    /** Laporan MTTR/MTBF per equipment. */
    public static function mttrReport(): array
    {
        Auth::user();
        $where = ["w.type IN ('Troubleshooting','Corrective')", "w.status != 'Cancelled'"];
        $p = [];
        if (($unit = (int) Http::query('unit_id', '0')) > 0) {
            $where[] = 'w.unit_id = ?';
            $p[] = $unit;
        }
        if (($from = Http::query('from', '')) !== '') {
            $where[] = 'w.created_at >= ?';
            $p[] = $from . ' 00:00:00';
        }
        if (($to = Http::query('to', '')) !== '') {
            $where[] = 'w.created_at <= ?';
            $p[] = $to . ' 23:59:59';
        }
        $rows = Db::query(
            'SELECT w.equipment_id, e.name AS equipment_name, a.name AS area_name, w.created_at, w.work_seconds, w.status
             FROM work_orders w JOIN equipment e ON e.id = w.equipment_id JOIN areas a ON a.id = e.area_id
             WHERE ' . implode(' AND ', $where) . ' ORDER BY w.equipment_id, w.created_at',
            $p
        );
        $by = [];
        foreach ($rows as $r) {
            $by[$r['equipment_id']]['name'] = $r['equipment_name'];
            $by[$r['equipment_id']]['area'] = $r['area_name'];
            $by[$r['equipment_id']]['items'][] = $r;
        }
        $out = [];
        foreach ($by as $eid => $d) {
            $m = self::metrics($d['items']);
            $out[] = ['equipment_id' => (int) $eid, 'equipment_name' => $d['name'], 'area_name' => $d['area']] + $m;
        }
        usort($out, fn ($a, $b) => $b['failures'] <=> $a['failures']);
        return ['rows' => $out];
    }

    /** Kinerja teknisi + rating (menu Teknisi, Reports, Rating Teknisi). */
    public static function techReport(): array
    {
        Auth::requireRole('admin', 'spv', 'maintenance');
        $from = Http::query('from', '') ?: '0000-01-01';
        $to = Http::query('to', '') ?: '9999-12-31';
        $rows = Db::query(
            "SELECT te.id, te.name, te.spec, te.shift, te.active,
                    COUNT(w.id) AS total, SUM(CASE WHEN w.status = 'Closed' THEN 1 ELSE 0 END) AS closed,
                    SUM(CASE WHEN w.status = 'In Progress' THEN 1 ELSE 0 END) AS in_progress,
                    COALESCE(SUM(w.work_seconds), 0) AS seconds, AVG(w.rating) AS avg_rating, COUNT(w.rating) AS rated
             FROM technicians te
             LEFT JOIN wo_techs t ON t.tech_id = te.id
             LEFT JOIN work_orders w ON w.id = t.wo_id AND w.created_at BETWEEN ? AND ?
             GROUP BY te.id ORDER BY total DESC, te.name",
            [$from . ' 00:00:00', $to . ' 23:59:59']
        );
        return ['rows' => $rows];
    }

    // ───────────────────────── perhitungan ─────────────────────────

    /** MTTR/MTBF satu equipment dari seluruh WO kerusakannya. */
    public static function reliability(int $equipmentId): array
    {
        $rows = Db::query(
            "SELECT created_at, work_seconds, status FROM work_orders
             WHERE equipment_id = ? AND type IN ('Troubleshooting','Corrective') AND status != 'Cancelled' ORDER BY created_at",
            [$equipmentId]
        );
        return self::metrics($rows);
    }

    /**
     * MTTR = rata-rata durasi kerja WO yang sudah selesai.
     * MTBF = rentang waktu antar kerusakan pertama–terakhir ÷ (jumlah kerusakan − 1).
     * @return array{failures:int, repaired:int, mttr_hours:?float, mtbf_hours:?float, downtime_hours:float}
     */
    private static function metrics(array $rows): array
    {
        $done = array_filter($rows, fn ($r) => in_array($r['status'], ['Pending Verification', 'Closed'], true));
        $secs = array_sum(array_map(fn ($r) => (int) $r['work_seconds'], $done));
        $n = count($rows);
        $mtbf = null;
        if ($n >= 2) {
            $span = strtotime((string) $rows[$n - 1]['created_at']) - strtotime((string) $rows[0]['created_at']);
            $mtbf = round($span / ($n - 1) / 3600, 1);
        }
        return [
            'failures'       => $n,
            'repaired'       => count($done),
            'mttr_hours'     => $done ? round($secs / count($done) / 3600, 2) : null,
            'mtbf_hours'     => $mtbf,
            'downtime_hours' => round($secs / 3600, 2),
        ];
    }

    private static function liveSeconds(array $wo): int
    {
        $s = (int) $wo['work_seconds'];
        if (!empty($wo['running_since'])) {
            $s += max(0, time() - strtotime((string) $wo['running_since']));
        }
        return $s;
    }

    private static function addSeconds(string $id, string $from, string $to): array
    {
        return ['UPDATE work_orders SET work_seconds = work_seconds + ? WHERE id = ?', [max(0, strtotime($to) - strtotime($from)), $id]];
    }

    /** Teknisi yang menekan Start/Continue otomatis tercatat sebagai penanggung jawab WO. */
    private static function autoAssign(string $id, array $me): array
    {
        $t = Db::query('SELECT id FROM technicians WHERE user_id = ?', [$me['id']])[0] ?? null;
        return $t ? [['INSERT OR IGNORE INTO wo_techs (wo_id, tech_id) VALUES (?, ?)', [$id, $t['id']]]] : [];
    }

    private static function event(string $id, string $type, string $msg, string $actor, string $ts): array
    {
        return ['INSERT INTO wo_events (wo_id, type, message, actor, created_at) VALUES (?,?,?,?,?)', [$id, $type, $msg, $actor, $ts]];
    }

    private static function nextId(int $unitId): string
    {
        $code = Db::query('SELECT code FROM units WHERE id = ?', [$unitId])[0]['code'] ?? 'XX';
        $prefix = 'WO/' . $code . '/' . date('Ym') . '/';
        $max = Db::query('SELECT MAX(id) m FROM work_orders WHERE id LIKE ?', [$prefix . '%'])[0]['m'] ?? null;
        $seq = $max ? ((int) substr((string) $max, strlen($prefix))) + 1 : 1;
        return $prefix . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }

    private static function find(string $id): array
    {
        $wo = Db::query('SELECT * FROM work_orders WHERE id = ?', [$id])[0] ?? null;
        if (!$wo) {
            throw new HttpError('Work Order tidak ditemukan.', 404);
        }
        return $wo;
    }

    private static function mustNotBeFinal(array $wo): void
    {
        self::need(!in_array($wo['status'], ['Closed', 'Cancelled'], true), 'WO sudah ' . $wo['status'] . ' dan tidak bisa diubah.');
    }

    private static function need(mixed $cond, string $msg): void
    {
        if (!$cond) {
            throw new HttpError($msg, 409);
        }
    }
}
