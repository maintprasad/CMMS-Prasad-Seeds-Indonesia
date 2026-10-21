<?php
declare(strict_types=1);

/**
 * Modul MaintWare: stok sparepart/consumable, transaksi IN/OUT, laporan bulanan, reorder, Purchase Request.
 * Pemakaian part di Work Order otomatis menjadi transaksi OUT (lihat stockStatements).
 */
final class MaintWareApi
{
    private const STAFF = ['admin', 'spv', 'maintenance'];
    private const PREFIX = ['Mechanical' => 'MECH', 'Electrical' => 'ELEC', 'Civil' => 'CIVIL', 'Others' => 'ITEM'];
    private const MOVING = ['Fast Moving', 'Slow Moving'];
    private const RO_STATUS = ['Draft', 'Open', 'Approved', 'Sent to Purchasing', 'Done', 'Cancelled'];

    // ───────────────────────── master item ─────────────────────────

    public static function meta(): array
    {
        Auth::requireRole(...self::STAFF);
        $distinct = fn (string $col) => array_column(Db::query("SELECT DISTINCT $col v FROM mw_items WHERE $col IS NOT NULL AND $col != '' ORDER BY v"), 'v');
        return [
            'types'   => array_values(array_unique(array_merge(array_keys(self::PREFIX), $distinct('item_type')))),
            'classes' => array_values(array_unique(array_merge(['Consumable', 'Spare Part', 'Tools', 'Material'], $distinct('item_class')))),
            'moving'  => self::MOVING,
            'uoms'    => array_values(array_unique(array_merge(['pcs', 'meter', 'box', 'pack', 'roll', 'set', 'kg', 'liter'], $distinct('uom')))),
            'pics'    => array_column(Db::query("SELECT DISTINCT pic v FROM mw_transactions WHERE pic IS NOT NULL AND pic != '' ORDER BY v"), 'v'),
        ];
    }

    /** Daftar item. Dipakai juga oleh pemilih "Parts Used" di Work Order (unit_id + q). */
    public static function items(): array
    {
        Auth::user();
        $where = [];
        $p = [];
        if (($unit = (int) Http::query('unit_id', '0')) > 0) {
            $where[] = '(unit_id = ? OR unit_id IS NULL)'; // item tanpa unit = gudang bersama
            $p[] = $unit;
        }
        foreach (['item_type' => 'type', 'item_class' => 'class', 'moving_type' => 'moving'] as $col => $param) {
            if (($v = Http::query($param, '')) !== '') {
                $where[] = "$col = ?";
                $p[] = $v;
            }
        }
        if (Http::query('low') === '1') {
            $where[] = 'qty < min_stock';
        }
        if (Http::query('photo') === 'none') {
            $where[] = 'id NOT IN (SELECT item_id FROM mw_item_photos)';
        }
        if (($q = trim((string) Http::query('q', ''))) !== '') {
            $where[] = '(name LIKE ? OR id LIKE ? OR spec LIKE ? OR brand LIKE ? OR pic LIKE ? OR notes LIKE ?)';
            array_push($p, ...array_fill(0, 6, "%$q%"));
        }
        $w = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $sort = in_array(Http::query('sort', 'id'), ['id', 'name', 'qty', 'price', 'item_type', 'updated_at'], true) ? Http::query('sort', 'id') : 'id';
        $dir = Http::query('dir', 'asc') === 'desc' ? 'DESC' : 'ASC';
        $limit = min(500, max(1, (int) Http::query('limit', '60')));
        $offset = max(0, (int) Http::query('offset', '0'));
        $total = (int) Db::query("SELECT COUNT(*) c FROM mw_items $w", $p)[0]['c'];
        $rows = Db::query(
            "SELECT id, name, brand, spec, item_type, item_class, moving_type, uom, unit_id, qty, min_stock, price, pic, notes, updated_at,
                    (SELECT COUNT(*) FROM mw_item_photos ph WHERE ph.item_id = mw_items.id) AS has_photo
             FROM mw_items $w ORDER BY $sort $dir, id LIMIT $limit OFFSET $offset",
            $p
        );
        return ['total' => $total, 'rows' => $rows];
    }

    public static function item(): array
    {
        Auth::requireRole(...self::STAFF);
        $item = self::find((string) Http::query('id', ''));
        $item['has_photo'] = Db::query('SELECT 1 FROM mw_item_photos WHERE item_id = ?', [$item['id']]) ? 1 : 0;
        return [
            'item' => $item,
            'history' => Db::query(
                'SELECT t.*, e.name AS equipment_name, a.name AS area_name FROM mw_transactions t
                 LEFT JOIN equipment e ON e.id = t.equipment_id LEFT JOIN areas a ON a.id = t.area_id
                 WHERE t.item_id = ? ORDER BY t.trx_date DESC, t.id DESC LIMIT 20',
                [$item['id']]
            ),
            // equipment yang memakai item ini: sparepart standar (tertaut) + pemakaian nyata (transaksi OUT)
            'equipment' => Db::query(
                "SELECT e.id, e.name, a.name AS area_name, u.name AS unit_name,
                        (SELECT s.qty FROM equipment_spareparts s WHERE s.equipment_id = e.id AND s.item_id = ? LIMIT 1) AS standard_qty,
                        (SELECT COALESCE(SUM(CASE WHEN t.trx_type = 'OUT' THEN t.qty ELSE -t.qty END), 0) FROM mw_transactions t WHERE t.equipment_id = e.id AND t.item_id = ? AND (t.trx_type = 'OUT' OR t.source IN ('WO','PM'))) AS used_qty,
                        (SELECT MAX(t.trx_date) FROM mw_transactions t WHERE t.equipment_id = e.id AND t.item_id = ? AND t.trx_type = 'OUT') AS last_used
                 FROM equipment e JOIN areas a ON a.id = e.area_id JOIN units u ON u.id = a.unit_id
                 WHERE e.id IN (SELECT equipment_id FROM equipment_spareparts WHERE item_id = ?) OR e.id IN (SELECT equipment_id FROM mw_transactions WHERE item_id = ? AND equipment_id IS NOT NULL)
                 ORDER BY used_qty DESC, e.name LIMIT 100",
                [$item['id'], $item['id'], $item['id'], $item['id'], $item['id']]
            ),
        ];
    }

    public static function saveItem(): array
    {
        $me = Auth::requireRole(...self::STAFF);
        $in = Http::body();
        $name = trim((string) ($in['name'] ?? ''));
        $type = trim((string) ($in['item_type'] ?? ''));
        $class = trim((string) ($in['item_class'] ?? ''));
        $moving = (string) ($in['moving_type'] ?? '');
        $pic = trim((string) ($in['pic'] ?? ''));
        if ($name === '' || $type === '' || $class === '' || $pic === '' || !in_array($moving, self::MOVING, true)) {
            throw new HttpError('Nama, type, class, moving type, dan PIC wajib diisi.');
        }
        $vals = [$name, trim((string) ($in['brand'] ?? '')) ?: null, trim((string) ($in['spec'] ?? '')) ?: null, $type, $class, $moving,
            trim((string) ($in['uom'] ?? '')) ?: 'pcs', max(0, (float) ($in['price'] ?? 0)), max(0, (float) ($in['min_stock'] ?? 0)),
            $pic, trim((string) ($in['notes'] ?? '')) ?: null, ($u = (int) ($in['unit_id'] ?? 0)) > 0 ? $u : null, now()];
        $id = (string) ($in['id'] ?? '');
        $photo = self::photoStatement((string) ($in['photo'] ?? ''), (string) ($in['photo_mime'] ?? ''));
        if ($id !== '') {
            self::find($id);
            Db::transaction(array_values(array_filter([['UPDATE mw_items SET name=?, brand=?, spec=?, item_type=?, item_class=?, moving_type=?, uom=?, price=?, min_stock=?, pic=?, notes=?, unit_id=?, updated_at=? WHERE id=?', [...$vals, $id]], ...($photo ? self::photoWrite($id, $photo) : [])])));
            return ['id' => $id];
        }
        if (!$photo) {
            throw new HttpError('Foto item wajib diisi untuk material / sparepart baru.');
        }
        $id = self::nextCode($type);
        $qty = max(0, (float) ($in['qty'] ?? 0));
        $st = [['INSERT INTO mw_items (name, brand, spec, item_type, item_class, moving_type, uom, price, min_stock, pic, notes, unit_id, updated_at, id, qty) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,0)', [...$vals, $id]]];
        if ($qty > 0) {
            $st = array_merge($st, self::stockStatements(['id' => $id, 'qty' => 0], 'IN', $qty, ['pic' => $pic, 'notes' => 'Stok awal', 'by' => $me['name']]));
        }
        Db::transaction([...$st, ...self::photoWrite($id, $photo)]);
        return ['id' => $id];
    }

    /** Validasi foto (base64 tanpa prefix): JPEG/PNG/WebP asli (cek magic bytes), maks 600 KB. null bila kosong. */
    private static function photoStatement(string $b64, string $mime): ?array
    {
        if ($b64 === '') {
            return null;
        }
        $raw = base64_decode($b64, true);
        if ($raw === false || $raw === '') {
            throw new HttpError('Foto tidak valid.');
        }
        if (strlen($raw) > 600000) {
            throw new HttpError('Foto terlalu besar (maks 600 KB setelah dikompres).');
        }
        $real = match (true) {
            str_starts_with($raw, "\xFF\xD8\xFF") => 'image/jpeg',
            str_starts_with($raw, "\x89PNG\r\n\x1a\n") => 'image/png',
            str_starts_with($raw, 'RIFF') && substr($raw, 8, 4) === 'WEBP' => 'image/webp',
            default => null,
        };
        if ($real === null) {
            throw new HttpError('Foto harus berupa JPEG, PNG, atau WebP.');
        }
        return ['mime' => $real, 'data' => $b64];
    }

    private static function photoWrite(string $id, array $photo): array
    {
        return [
            ['DELETE FROM mw_item_photos WHERE item_id = ?', [$id]],
            ['INSERT INTO mw_item_photos (item_id, mime, data, updated_at) VALUES (?,?,?,?)', [$id, $photo['mime'], $photo['data'], now()]],
        ];
    }

    public static function photo(): never
    {
        Auth::requireRole(...self::STAFF);
        $p = Db::query('SELECT mime, data FROM mw_item_photos WHERE item_id = ?', [(string) Http::query('id', '')])[0] ?? null;
        if (!$p) {
            throw new HttpError('Item ini belum punya foto.', 404);
        }
        $raw = (string) base64_decode((string) $p['data']);
        http_response_code(200);
        header('Content-Type: ' . $p['mime']);
        header('Content-Length: ' . strlen($raw));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=3600');
        echo $raw;
        exit;
    }

    public static function deleteItem(): array
    {
        Auth::requireRole('admin');
        $id = (string) (Http::body()['id'] ?? '');
        self::find($id);
        if (Db::query('SELECT 1 FROM wo_parts WHERE item_id = ? LIMIT 1', [$id])) {
            throw new HttpError('Item sudah dipakai di Work Order — tidak bisa dihapus.', 409);
        }
        Db::transaction([['DELETE FROM mw_transactions WHERE item_id = ?', [$id]], ['DELETE FROM mw_item_photos WHERE item_id = ?', [$id]], ['DELETE FROM mw_items WHERE id = ?', [$id]]]);
        return [];
    }

    // ───────────────────────── stok ─────────────────────────

    public static function stock(): array
    {
        $me = Auth::requireRole(...self::STAFF);
        $in = Http::body();
        $item = self::find((string) ($in['item_id'] ?? ''));
        $type = (string) ($in['type'] ?? '');
        $qty = (float) ($in['qty'] ?? 0);
        $pic = trim((string) ($in['pic'] ?? ''));
        if (!in_array($type, ['IN', 'OUT'], true) || $qty <= 0 || $pic === '') {
            throw new HttpError('Tipe, jumlah (> 0), dan PIC wajib diisi.');
        }
        $ctx = ['pic' => $pic, 'notes' => trim((string) ($in['notes'] ?? '')) ?: null, 'by' => $me['name']];
        if ($type === 'OUT') {
            $eq = (int) ($in['equipment_id'] ?? 0);
            if ($eq > 0) {
                $row = Db::query('SELECT e.id, e.area_id, a.unit_id FROM equipment e JOIN areas a ON a.id = e.area_id WHERE e.id = ?', [$eq])[0] ?? null;
                if (!$row) {
                    throw new HttpError('Equipment tidak ditemukan.');
                }
                $ctx += ['equipment_id' => $eq, 'area_id' => (int) $row['area_id'], 'unit_id' => (int) $row['unit_id']];
            } elseif (($a = (int) ($in['area_id'] ?? 0)) > 0) {
                $ctx += ['area_id' => $a, 'unit_id' => (int) (Db::query('SELECT unit_id FROM areas WHERE id = ?', [$a])[0]['unit_id'] ?? 0) ?: null];
            }
        }
        $st = self::stockStatements($item, $type, $qty, $ctx);
        Db::transaction($st);
        return ['qty' => (float) $item['qty'] + ($type === 'IN' ? $qty : -$qty)];
    }

    /**
     * Pernyataan SQL untuk satu perubahan stok (update item + catat transaksi). Dipakai form Stock IN/OUT,
     * stok awal item, dan pemakaian part di Work Order. Menolak OUT bila stok tidak cukup.
     * @param array{id:string,qty:mixed} $item
     * @param array{pic?:?string,notes?:?string,by?:?string,unit_id?:?int,area_id?:?int,equipment_id?:?int,wo_id?:?string,pm_id?:?string,source?:string} $ctx
     */
    public static function stockStatements(array $item, string $type, float $qty, array $ctx): array
    {
        $cur = (float) $item['qty'];
        if ($type === 'OUT' && $qty > $cur) {
            throw new HttpError("Stok tidak cukup (tersedia $cur).", 409);
        }
        $after = $type === 'IN' ? $cur + $qty : $cur - $qty;
        $ts = now();
        return [
            ['UPDATE mw_items SET qty = ?, updated_at = ? WHERE id = ?', [$after, $ts, $item['id']]],
            ['INSERT INTO mw_transactions (trx_date, item_id, trx_type, qty, stock_after, pic, notes, unit_id, area_id, equipment_id, wo_id, source, created_by, created_at, pm_id)
              VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [$ts, $item['id'], $type, $qty, $after, $ctx['pic'] ?? null, $ctx['notes'] ?? null, $ctx['unit_id'] ?? null, $ctx['area_id'] ?? null,
                    $ctx['equipment_id'] ?? null, $ctx['wo_id'] ?? null, $ctx['source'] ?? 'APP', $ctx['by'] ?? null, $ts, $ctx['pm_id'] ?? null]],
        ];
    }

    public static function find(string $id): array
    {
        $row = Db::query('SELECT * FROM mw_items WHERE id = ?', [$id])[0] ?? null;
        if (!$row) {
            throw new HttpError('Item tidak ditemukan.', 404);
        }
        return $row;
    }

    private static function nextCode(string $type): string
    {
        $prefix = self::PREFIX[$type] ?? 'ITEM';
        $max = 0;
        foreach (Db::query('SELECT id FROM mw_items WHERE id LIKE ?', [$prefix . '-%']) as $r) {
            $max = max($max, (int) substr((string) $r['id'], strlen($prefix) + 1));
        }
        return $prefix . '-' . str_pad((string) ($max + 1), 3, '0', STR_PAD_LEFT);
    }

    // ───────────────────────── log & laporan ─────────────────────────

    private const LOG_SELECT = 'SELECT t.id, t.trx_date, t.item_id, i.name AS item_name, i.brand, i.item_type, i.item_class, i.uom, t.trx_type, t.qty, t.stock_after,
            t.pic, t.notes, t.wo_id, t.source, t.unit_id, u.name AS unit_name, a.name AS area_name, e.name AS equipment_name
        FROM mw_transactions t JOIN mw_items i ON i.id = t.item_id
        LEFT JOIN units u ON u.id = t.unit_id LEFT JOIN areas a ON a.id = t.area_id LEFT JOIN equipment e ON e.id = t.equipment_id';

    public static function log(): array
    {
        Auth::requireRole(...self::STAFF);
        $where = [];
        $p = [];
        if (($tx = Http::query('tx', '')) !== '') {
            $where[] = 't.trx_type = ?';
            $p[] = $tx;
        }
        if (($pic = Http::query('pic', '')) !== '') {
            $where[] = 't.pic = ?';
            $p[] = $pic;
        }
        if (($item = Http::query('item_id', '')) !== '') {
            $where[] = 't.item_id = ?';
            $p[] = $item;
        }
        if (($from = Http::query('from', '')) !== '') {
            $where[] = 't.trx_date >= ?';
            $p[] = $from . ' 00:00:00';
        }
        if (($to = Http::query('to', '')) !== '') {
            $where[] = 't.trx_date <= ?';
            $p[] = $to . ' 23:59:59';
        }
        if (($q = trim((string) Http::query('q', ''))) !== '') {
            $where[] = '(t.item_id LIKE ? OR i.name LIKE ? OR t.pic LIKE ? OR t.notes LIKE ? OR e.name LIKE ?)';
            array_push($p, ...array_fill(0, 5, "%$q%"));
        }
        $w = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $limit = min(2000, max(1, (int) Http::query('limit', '100')));
        $offset = max(0, (int) Http::query('offset', '0'));
        $total = (int) Db::query('SELECT COUNT(*) c FROM mw_transactions t JOIN mw_items i ON i.id = t.item_id LEFT JOIN equipment e ON e.id = t.equipment_id' . $w, $p)[0]['c'];
        return ['total' => $total, 'rows' => Db::query(self::LOG_SELECT . $w . " ORDER BY t.trx_date DESC, t.id DESC LIMIT $limit OFFSET $offset", $p)];
    }

    public static function dashboard(): array
    {
        Auth::requireRole(...self::STAFF);
        $s = Db::query('SELECT COUNT(*) items, COALESCE(SUM(qty * COALESCE(price,0)),0) value, SUM(CASE WHEN qty < min_stock THEN 1 ELSE 0 END) low,
                               SUM(CASE WHEN qty <= 0 THEN 1 ELSE 0 END) empty FROM mw_items')[0];
        $month = date('Y-m');
        $mt = array_column(Db::query('SELECT trx_type t, COUNT(*) n, COALESCE(SUM(qty),0) q FROM mw_transactions WHERE substr(trx_date,1,7) = ? GROUP BY trx_type', [$month]), null, 't');
        $since = date('Y-m-d', strtotime('-89 days')) . ' 00:00:00';
        return [
            'summary' => ['items' => (int) $s['items'], 'value' => (float) $s['value'], 'low' => (int) $s['low'], 'empty' => (int) $s['empty'],
                'in_qty' => (float) ($mt['IN']['q'] ?? 0), 'in_tx' => (int) ($mt['IN']['n'] ?? 0), 'out_qty' => (float) ($mt['OUT']['q'] ?? 0), 'out_tx' => (int) ($mt['OUT']['n'] ?? 0)],
            'by_type'   => Db::query("SELECT COALESCE(item_type,'—') label, COUNT(*) items, COALESCE(SUM(qty * COALESCE(price,0)),0) value FROM mw_items GROUP BY label ORDER BY value DESC"),
            'by_class'  => Db::query("SELECT COALESCE(item_class,'—') label, COUNT(*) items FROM mw_items GROUP BY label ORDER BY items DESC"),
            'top_out'   => Db::query("SELECT i.id, i.name, SUM(t.qty) qty, i.uom FROM mw_transactions t JOIN mw_items i ON i.id = t.item_id WHERE t.trx_type = 'OUT' AND t.trx_date >= ? GROUP BY i.id ORDER BY qty DESC LIMIT 8", [$since]),
            'by_equipment' => Db::query("SELECT e.id, e.name, a.name AS area, COUNT(*) tx, SUM(t.qty) qty FROM mw_transactions t JOIN equipment e ON e.id = t.equipment_id JOIN areas a ON a.id = e.area_id WHERE t.trx_type = 'OUT' AND t.trx_date >= ? GROUP BY e.id ORDER BY tx DESC LIMIT 8", [$since]),
            'by_area'   => Db::query("SELECT a.name label, SUM(t.qty) qty FROM mw_transactions t JOIN areas a ON a.id = t.area_id WHERE t.trx_type = 'OUT' AND t.trx_date >= ? GROUP BY a.id ORDER BY qty DESC LIMIT 8", [$since]),
            'low_items' => Db::query('SELECT id, name, qty, min_stock, uom FROM mw_items WHERE qty < min_stock ORDER BY (min_stock - qty) DESC LIMIT 8'),
            'recent'    => Db::query(self::LOG_SELECT . ' ORDER BY t.trx_date DESC, t.id DESC LIMIT 8'),
        ];
    }

    public static function monthly(): array
    {
        Auth::requireRole(...self::STAFF);
        $y = (int) Http::query('year', date('Y'));
        $m = (int) Http::query('month', date('n'));
        $from = sprintf('%04d-%02d-01 00:00:00', $y, $m);
        $to = date('Y-m-t 23:59:59', strtotime($from));
        $rows = Db::query(self::LOG_SELECT . ' WHERE t.trx_date BETWEEN ? AND ? ORDER BY t.trx_date DESC, t.id DESC LIMIT 3000', [$from, $to]);
        return ['year' => $y, 'month' => $m, 'rows' => $rows];
    }

    public static function reorder(): array
    {
        Auth::requireRole(...self::STAFF);
        return ['rows' => Db::query(
            'SELECT id, name, brand, spec, item_type, uom, qty, min_stock, price, (min_stock - qty) AS gap FROM mw_items WHERE qty < min_stock ORDER BY (min_stock - qty) DESC, name'
        )];
    }

    public static function config(): array
    {
        Auth::requireRole(...self::STAFF);
        $n = fn (string $t) => (int) Db::query("SELECT COUNT(*) c FROM $t")[0]['c'];
        return [
            'driver' => Db::driverName(),
            'counts' => ['items' => $n('mw_items'), 'transactions' => $n('mw_transactions'), 'ro' => $n('mw_ro'), 'equipment' => $n('equipment')],
            'sources' => Db::query('SELECT source, COUNT(*) n FROM mw_transactions GROUP BY source ORDER BY n DESC'),
            'migrations' => array_column(Db::query('SELECT version FROM schema_migrations ORDER BY version'), 'version'),
        ];
    }

    // ───────────────────────── Purchase Request (RO) ─────────────────────────

    public static function roList(): array
    {
        Auth::requireRole(...self::STAFF);
        $status = Http::query('status', '');
        $rows = Db::query('SELECT * FROM mw_ro' . ($status !== '' ? ' WHERE status = ?' : '') . ' ORDER BY created_at DESC', $status !== '' ? [$status] : []);
        $items = [];
        foreach (Db::query('SELECT * FROM mw_ro_items ORDER BY id') as $it) {
            $items[$it['ro_no']][] = $it;
        }
        foreach ($rows as &$r) {
            $r['items'] = $items[$r['ro_no']] ?? [];
        }
        $counts = array_column(Db::query('SELECT status, COUNT(*) c FROM mw_ro GROUP BY status'), 'c', 'status');
        return ['rows' => $rows, 'counts' => $counts, 'total_est' => (float) (Db::query("SELECT COALESCE(SUM(total_est),0) t FROM mw_ro WHERE status NOT IN ('Cancelled','Draft')")[0]['t'])];
    }

    public static function roSave(): array
    {
        $me = Auth::requireRole(...self::STAFF);
        $in = Http::body();
        $pic = trim((string) ($in['pic'] ?? ''));
        $dept = trim((string) ($in['dept'] ?? ''));
        if ($pic === '' || $dept === '') {
            throw new HttpError('PIC dan departemen wajib diisi.');
        }
        $items = [];
        foreach ((array) ($in['items'] ?? []) as $it) {
            $name = trim((string) ($it['name'] ?? ''));
            if ($name === '') {
                throw new HttpError('Nama item tidak boleh kosong.');
            }
            $items[] = [$name, trim((string) ($it['item_id'] ?? '')) ?: null, trim((string) ($it['spec'] ?? '')) ?: null,
                max(0.01, (float) ($it['qty'] ?? 1)), max(0, (float) ($it['unit_price'] ?? 0))];
        }
        if (!$items) {
            throw new HttpError('Minimal 1 item.');
        }
        $total = array_sum(array_map(fn ($i) => $i[3] * $i[4], $items));
        $ts = now();
        $no = (string) ($in['ro_no'] ?? '');
        $draft = ($in['status'] ?? '') === 'Draft';
        $st = [];
        $vals = [trim((string) ($in['date'] ?? '')) ?: date('Y-m-d'), $pic, $dept, trim((string) ($in['notes'] ?? '')) ?: null,
            in_array($in['priority'] ?? '', ['Normal', 'Urgent', 'Critical'], true) ? $in['priority'] : 'Normal', trim((string) ($in['needed_date'] ?? '')) ?: null, $total];
        if ($no !== '') {
            $cur = Db::query('SELECT status FROM mw_ro WHERE ro_no = ?', [$no])[0] ?? null;
            if (!$cur) {
                throw new HttpError('RO tidak ditemukan.', 404);
            }
            if (!in_array($cur['status'], ['Draft', 'Open'], true)) {
                throw new HttpError('RO yang sudah disetujui tidak bisa diedit.', 409);
            }
            $st[] = ['UPDATE mw_ro SET ro_date=?, pic=?, dept=?, notes=?, priority=?, needed_date=?, total_est=?, status=?, updated_at=? WHERE ro_no=?',
                [...$vals, $draft ? 'Draft' : 'Open', $ts, $no]];
            $st[] = ['DELETE FROM mw_ro_items WHERE ro_no = ?', [$no]];
        } else {
            $no = self::nextRoNo();
            $st[] = ['INSERT INTO mw_ro (ro_date, pic, dept, notes, priority, needed_date, total_est, status, created_by, created_at, updated_at, ro_no) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
                [...$vals, $draft ? 'Draft' : 'Open', $me['name'], $ts, $ts, $no]];
        }
        foreach ($items as [$name, $iid, $spec, $qty, $price]) {
            $st[] = ['INSERT INTO mw_ro_items (ro_no, item_id, name, spec, qty, unit_price) VALUES (?,?,?,?,?,?)', [$no, $iid, $name, $spec, $qty, $price]];
        }
        Db::transaction($st);
        return ['ro_no' => $no];
    }

    public static function roStatus(): array
    {
        Auth::requireRole('spv', 'admin');
        $in = Http::body();
        $no = (string) ($in['ro_no'] ?? '');
        $status = (string) ($in['status'] ?? '');
        if (!in_array($status, self::RO_STATUS, true) || !Db::query('SELECT 1 FROM mw_ro WHERE ro_no = ?', [$no])) {
            throw new HttpError('RO atau status tidak valid.');
        }
        Db::transaction([['UPDATE mw_ro SET status = ?, updated_at = ? WHERE ro_no = ?', [$status, now(), $no]]]);
        return [];
    }

    private static function nextRoNo(): string
    {
        $prefix = 'RO-' . date('ym') . '-';
        $max = 0;
        foreach (Db::query('SELECT ro_no FROM mw_ro WHERE ro_no LIKE ?', [$prefix . '%']) as $r) {
            $max = max($max, (int) substr((string) $r['ro_no'], strlen($prefix)));
        }
        return $prefix . str_pad((string) ($max + 1), 3, '0', STR_PAD_LEFT);
    }
}
