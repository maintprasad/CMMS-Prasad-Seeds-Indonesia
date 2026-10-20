<?php
declare(strict_types=1);

/** Modul Equipment Database: unit → area → equipment (+ sparepart). Dikonsumsi WO, DM, PM. */
final class EquipmentApi
{
    /** Data referensi untuk dropdown semua modul. */
    public static function meta(): array
    {
        Auth::user();
        return [
            'units'       => Db::query('SELECT id, code, name FROM units ORDER BY id'),
            'areas'       => Db::query('SELECT a.id, a.unit_id, a.name, (SELECT COUNT(*) FROM equipment e WHERE e.area_id = a.id) AS equipment_count FROM areas a ORDER BY a.name'),
            'technicians' => Db::query('SELECT id, user_id, name, spec, shift, phone, active FROM technicians ORDER BY name'),
            'owners'      => Db::query('SELECT id, name, role, department FROM users WHERE active = 1 ORDER BY name'),
            'roles'       => Auth::ROLES,
            'wo_types'    => WorkOrderApi::TYPES,
            'priorities'  => WorkOrderApi::PRIORITIES,
            'statuses'    => WorkOrderApi::STATUSES,
        ];
    }

    /**
     * Klausa WHERE untuk daftar equipment (dipakai daftar dan aksi massal penanggung jawab).
     * @return array{0:string,1:array}
     */
    private static function scope(array $src): array
    {
        $where = [];
        $p = [];
        if (($unit = (int) ($src['unit_id'] ?? 0)) > 0) {
            $where[] = 'a.unit_id = ?';
            $p[] = $unit;
        }
        if (($area = (int) ($src['area_id'] ?? 0)) > 0) {
            $where[] = 'e.area_id = ?';
            $p[] = $area;
        }
        if (($q = trim((string) ($src['q'] ?? ''))) !== '') {
            $where[] = '(e.name LIKE ? OR e.code LIKE ? OR e.brand LIKE ? OR e.serial_no LIKE ?)';
            array_push($p, "%$q%", "%$q%", "%$q%", "%$q%");
        }
        if (($src['pj'] ?? '') === 'missing') {
            $where[] = '(e.pj_production_id IS NULL OR e.pj_maintenance_id IS NULL)';
        }
        return [$where ? 'WHERE ' . implode(' AND ', $where) : '', $p];
    }

    public static function list(): array
    {
        Auth::user();
        [$w, $p] = self::scope($_GET);
        $limit = min(500, max(1, (int) Http::query('limit', '100')));
        $offset = max(0, (int) Http::query('offset', '0'));
        $total = (int) Db::query("SELECT COUNT(*) c FROM equipment e JOIN areas a ON a.id = e.area_id $w", $p)[0]['c'];
        $rows = Db::query(
            "SELECT e.id, e.code, e.name, e.brand, e.model, e.serial_no, e.category, e.status, e.area_id, e.monitor_type, e.pm_type,
                    up.name AS pj_production, um.name AS pj_maintenance,
                    (SELECT COUNT(*) FROM equipment_params p WHERE p.equipment_id = e.id) AS param_count,
                    a.name AS area_name, a.unit_id, u.name AS unit_name,
                    (SELECT COUNT(*) FROM work_orders w WHERE w.equipment_id = e.id) AS wo_count
             FROM equipment e JOIN areas a ON a.id = e.area_id JOIN units u ON u.id = a.unit_id
             LEFT JOIN users up ON up.id = e.pj_production_id LEFT JOIN users um ON um.id = e.pj_maintenance_id
             $w ORDER BY u.id, a.name, e.name LIMIT $limit OFFSET $offset",
            $p
        );
        $missing = (int) Db::query('SELECT COUNT(*) c FROM equipment WHERE pj_production_id IS NULL OR pj_maintenance_id IS NULL')[0]['c'];
        return ['total' => $total, 'rows' => $rows, 'pj_missing' => $missing];
    }
    public static function get(): array
    {
        Auth::user();
        $id = (int) Http::query('id', '0');
        $eq = Db::query(
            'SELECT e.*, a.name AS area_name, a.unit_id, u.name AS unit_name, up.name AS pj_production, um.name AS pj_maintenance
             FROM equipment e JOIN areas a ON a.id = e.area_id JOIN units u ON u.id = a.unit_id
             LEFT JOIN users up ON up.id = e.pj_production_id LEFT JOIN users um ON um.id = e.pj_maintenance_id WHERE e.id = ?',
            [$id]
        )[0] ?? null;
        if (!$eq) {
            throw new HttpError('Equipment tidak ditemukan.', 404);
        }
        $eq['attrs'] = $eq['attrs'] ? json_decode((string) $eq['attrs'], true) : (object) [];
        return [
            'equipment'   => $eq,
            'params'      => MonitoringApi::paramsOf($id),
            'pm'          => PreventiveApi::forEquipment($id),
            'spareparts'  => Db::query('SELECT s.*, i.name AS item_name, i.qty AS stock, i.min_stock, i.uom, i.price AS item_price FROM equipment_spareparts s LEFT JOIN mw_items i ON i.id = s.item_id WHERE s.equipment_id = ? ORDER BY s.name', [$id]),
            'reliability' => WorkOrderApi::reliability($id),
            'recent_wo'   => Db::query(
                'SELECT id, title, type, status, created_at, work_seconds FROM work_orders
                 WHERE equipment_id = ? ORDER BY created_at DESC LIMIT 10',
                [$id]
            ),
        ];
    }

    public static function save(): array
    {
        Auth::requireRole('admin', 'spv');
        $in = Http::body();
        $name = trim((string) ($in['name'] ?? ''));
        $area = (int) ($in['area_id'] ?? 0);
        if ($name === '' || !Db::query('SELECT 1 FROM areas WHERE id = ?', [$area])) {
            throw new HttpError('Nama equipment dan area wajib diisi.');
        }
        $attrs = isset($in['attrs']) && is_array($in['attrs']) && $in['attrs'] ? json_encode($in['attrs'], JSON_UNESCAPED_UNICODE) : null;
        $f = ['code', 'brand', 'model', 'serial_no', 'category', 'install_date', 'spec', 'notes'];
        $vals = array_map(fn ($k) => ($v = trim((string) ($in[$k] ?? ''))) === '' ? null : $v, $f);
        $status = in_array($in['status'] ?? '', ['Active', 'Inactive', 'Under Repair'], true) ? $in['status'] : 'Active';
        $mt = trim((string) ($in['monitor_type'] ?? '')) ?: null;
        if ($mt !== null && !Db::query('SELECT 1 FROM dm_monitor_types WHERE code = ?', [$mt])) {
            throw new HttpError('Tipe monitoring tidak dikenal.');
        }
        [$pjP, $pjM] = self::owners($in, true);
        $pt = trim((string) ($in['pm_type'] ?? '')) ?: null;
        if ($pt !== null && !Db::query('SELECT 1 FROM pm_types WHERE code = ?', [$pt])) {
            throw new HttpError('Tipe PM tidak dikenal.');
        }
        $id = (int) ($in['id'] ?? 0);
        if ($id > 0) {
            Db::transaction([[
                'UPDATE equipment SET area_id=?, name=?, code=?, brand=?, model=?, serial_no=?, category=?, install_date=?, spec=?, notes=?,
                        status=?, attrs=?, monitor_type=?, pm_type=?, pj_production_id=?, pj_maintenance_id=?, updated_at=? WHERE id=?',
                [$area, $name, ...$vals, $status, $attrs, $mt, $pt, $pjP, $pjM, now(), $id],
            ]]);
        } else {
            Db::transaction([[
                'INSERT INTO equipment (area_id, name, code, brand, model, serial_no, category, install_date, spec, notes, status, attrs, monitor_type, pm_type, pj_production_id, pj_maintenance_id, updated_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [$area, $name, ...$vals, $status, $attrs, $mt, $pt, $pjP, $pjM, now()],
            ]]);
            $id = (int) Db::query('SELECT MAX(id) m FROM equipment')[0]['m'];
        }
        return ['id' => $id];
    }

    public static function delete(): array
    {
        Auth::requireRole('admin');
        $id = (int) (Http::body()['id'] ?? 0);
        if ((int) Db::query('SELECT (SELECT COUNT(*) FROM work_orders WHERE equipment_id = ?) + (SELECT COUNT(*) FROM dm_sessions WHERE equipment_id = ?) c', [$id, $id])[0]['c'] > 0) {
            throw new HttpError('Equipment sudah dipakai Work Order atau riwayat monitoring — nonaktifkan saja, jangan dihapus.', 409);
        }
        Db::transaction([['DELETE FROM equipment_params WHERE equipment_id = ?', [$id]], ['DELETE FROM equipment_spareparts WHERE equipment_id = ?', [$id]], ['DELETE FROM equipment WHERE id = ?', [$id]]]);
        return [];
    }

    public static function saveSparepart(): array
    {
        Auth::requireRole('admin', 'spv');
        $in = Http::body();
        $eq = (int) ($in['equipment_id'] ?? 0);
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '' || !Db::query('SELECT 1 FROM equipment WHERE id = ?', [$eq])) {
            throw new HttpError('Nama sparepart dan equipment wajib diisi.');
        }
        $item = ($iid = trim((string) ($in['item_id'] ?? ''))) !== '' ? (Db::query('SELECT id FROM mw_items WHERE id = ?', [$iid])[0] ?? throw new HttpError('Item MaintWare tidak ditemukan.')) : null;
        $vals = [$name, trim((string) ($in['specification'] ?? '')) ?: null,
            isset($in['price']) && $in['price'] !== '' ? (float) $in['price'] : null,
            isset($in['qty']) && $in['qty'] !== '' ? (float) $in['qty'] : null, $item['id'] ?? null];
        if (($id = (int) ($in['id'] ?? 0)) > 0) {
            Db::transaction([['UPDATE equipment_spareparts SET name=?, specification=?, price=?, qty=?, item_id=? WHERE id=?', [...$vals, $id]]]);
        } else {
            Db::transaction([['INSERT INTO equipment_spareparts (equipment_id, name, specification, price, qty, item_id) VALUES (?,?,?,?,?,?)', [$eq, ...$vals]]]);
        }
        return [];
    }

    public static function deleteSparepart(): array
    {
        Auth::requireRole('admin', 'spv');
        Db::transaction([['DELETE FROM equipment_spareparts WHERE id = ?', [(int) (Http::body()['id'] ?? 0)]]]);
        return [];
    }

    /**
     * Penanggung jawab mesin: satu dari produksi (role User) dan satu dari maintenance (Maintenance/SPV). Keduanya wajib.
     * @return array{0:?int,1:?int} [produksi, maintenance]; $required=false mengizinkan salah satu kosong (aksi massal parsial)
     */
    private static function owners(array $in, bool $required): array
    {
        $check = function (string $key, array $roles, string $label) use ($in, $required): ?int {
            $id = (int) ($in[$key] ?? 0);
            if ($id <= 0) {
                if ($required) {
                    throw new HttpError("Penanggung jawab $label wajib diisi.");
                }
                return null;
            }
            $u = Db::query('SELECT role, active FROM users WHERE id = ?', [$id])[0] ?? null;
            if (!$u || (int) $u['active'] !== 1 || !in_array($u['role'], $roles, true)) {
                throw new HttpError("Penanggung jawab $label harus user aktif dengan role " . implode('/', $roles) . '.');
            }
            return $id;
        };
        return [$check('pj_production_id', ['user'], 'Produksi'), $check('pj_maintenance_id', ['maintenance', 'spv'], 'Maintenance')];
    }

    /** Set penanggung jawab massal untuk semua equipment hasil filter (unit/area/cari/belum lengkap) — Admin/SPV. */
    public static function setOwners(): array
    {
        Auth::requireRole('admin', 'spv');
        $in = Http::body();
        [$pjP, $pjM] = self::owners($in, false);
        if ($pjP === null && $pjM === null) {
            throw new HttpError('Pilih minimal satu penanggung jawab.');
        }
        [$w, $p] = self::scope((array) ($in['filter'] ?? []));
        if (!empty($in['only_missing'])) {
            $w .= ($w ? ' AND ' : 'WHERE ') . '(e.pj_production_id IS NULL OR e.pj_maintenance_id IS NULL)';
        }
        $ids = array_column(Db::query("SELECT e.id FROM equipment e JOIN areas a ON a.id = e.area_id $w", $p), 'id');
        $sets = [];
        $vals = [];
        if ($pjP !== null) {
            $sets[] = 'pj_production_id = ?';
            $vals[] = $pjP;
        }
        if ($pjM !== null) {
            $sets[] = 'pj_maintenance_id = ?';
            $vals[] = $pjM;
        }
        $st = [];
        foreach (array_chunk($ids, 200) as $chunk) {
            $st[] = ['UPDATE equipment SET ' . implode(', ', $sets) . ', updated_at = ? WHERE id IN (' . implode(',', array_map('intval', $chunk)) . ')', [...$vals, now()]];
        }
        Db::transaction($st);
        return ['updated' => count($ids)];
    }

    /** Equipment History: gabungan Work Order (dan nanti Preventive Maintenance) untuk satu equipment, lengkap dengan detail penelusuran. */
    public static function history(): array
    {
        Auth::user();
        $id = (int) Http::query('id', '0');
        $wos = Db::query(
            'SELECT w.id, w.title, w.type, w.priority, w.status, w.created_at, w.started_at, w.finished_at, w.closed_at, w.work_seconds, w.closing_note, w.description, w.source, w.source_ref, w.requestor_name,
                    h.what_text, h.when_text, h.where_text, h.who_text, h.how_text, h.why_text
             FROM work_orders w LEFT JOIN wo_history h ON h.wo_id = w.id WHERE w.equipment_id = ? ORDER BY w.created_at DESC LIMIT 300',
            [$id]
        );
        $techs = [];
        foreach (Db::query('SELECT t.wo_id, te.name FROM wo_techs t JOIN technicians te ON te.id = t.tech_id WHERE t.wo_id IN (SELECT id FROM work_orders WHERE equipment_id = ?)', [$id]) as $r) {
            $techs[$r['wo_id']][] = $r['name'];
        }
        $parts = [];
        foreach (Db::query('SELECT wo_id, item_id, name, qty, uom FROM wo_parts WHERE wo_id IN (SELECT id FROM work_orders WHERE equipment_id = ?) ORDER BY id', [$id]) as $r) {
            $parts[$r['wo_id']][] = $r;
        }
        $entries = array_map(fn ($w) => ['kind' => 'WO', 'date' => $w['created_at'], 'techs' => $techs[$w['id']] ?? [], 'parts' => $parts[$w['id']] ?? []] + $w, $wos);
        foreach (Db::query("SELECT r.id, r.report_date, r.periode, r.teknisi, r.approval, r.notes, r.items_total, r.items_ok, r.items_no, r.wo_id, t.label AS type_label FROM pm_reports r LEFT JOIN pm_types t ON t.code = r.pm_type WHERE r.equipment_id = ? ORDER BY r.report_date DESC LIMIT 300", [$id]) as $r) {
            $parts = Db::query('SELECT item_id, name, qty, uom FROM pm_report_parts WHERE report_id = ? ORDER BY seq', [$r['id']]);
            $entries[] = ['kind' => 'PM', 'date' => $r['report_date'], 'id' => $r['id'], 'title' => 'PM ' . ($r['periode'] ?: '') . ': ' . $r['items_ok'] . '/' . $r['items_total'] . ' butir OK' . ($r['items_no'] ? ', ' . $r['items_no'] . ' tidak OK' : ''),
                'type' => $r['type_label'] ?: 'Preventive Maintenance', 'status' => $r['items_no'] ? 'Ada temuan' : 'Selesai', 'priority' => null, 'work_seconds' => 0, 'techs' => array_values(array_filter(array_map('trim', explode(',', (string) $r['teknisi'])))),
                'parts' => $parts, 'closing_note' => $r['notes'], 'description' => $r['approval'] ? 'Disetujui: ' . $r['approval'] : null, 'wo_id' => $r['wo_id'], 'source' => 'pm'];
        }
        usort($entries, fn ($a, $b) => strcmp((string) $b['date'], (string) $a['date']));
        return ['entries' => $entries, 'pm_ready' => true];
    }

    /** Sparepart yang benar-benar dipakai di equipment ini: transaksi OUT MaintWare + part bebas dari WO. */
    public static function partsUsed(): array
    {
        Auth::user();
        $id = (int) Http::query('id', '0');
        $summary = Db::query(
            "SELECT i.id, i.name, i.uom, i.qty AS stock, i.min_stock, SUM(CASE WHEN t.trx_type = 'OUT' THEN t.qty ELSE -t.qty END) AS total,
                    SUM(CASE WHEN t.trx_type = 'OUT' THEN 1 ELSE -1 END) AS times, MAX(t.trx_date) AS last_date
             FROM mw_transactions t JOIN mw_items i ON i.id = t.item_id WHERE t.equipment_id = ? AND (t.trx_type = 'OUT' OR t.source IN ('WO','PM')) GROUP BY i.id HAVING total > 0 ORDER BY total DESC",
            [$id]
        );
        $detail = Db::query(
            "SELECT t.id, t.trx_date, t.item_id, i.name AS item_name, i.uom, t.qty, t.trx_type, t.pic, t.wo_id, t.notes, t.source
             FROM mw_transactions t JOIN mw_items i ON i.id = t.item_id WHERE t.equipment_id = ? AND (t.trx_type = 'OUT' OR t.source IN ('WO','PM')) ORDER BY t.trx_date DESC, t.id DESC LIMIT 300",
            [$id]
        );
        $free = Db::query(
            "SELECT p.name, p.uom, SUM(p.qty) AS total, COUNT(*) AS times, MAX(p.added_at) AS last_date FROM wo_parts p JOIN work_orders w ON w.id = p.wo_id
             WHERE w.equipment_id = ? AND p.item_id IS NULL GROUP BY p.name ORDER BY total DESC",
            [$id]
        );
        return ['summary' => $summary, 'detail' => $detail, 'free' => $free];
    }

    public static function saveArea(): array
    {
        Auth::requireRole('admin');
        $in = Http::body();
        $name = trim((string) ($in['name'] ?? ''));
        $unit = (int) ($in['unit_id'] ?? 0);
        if ($name === '' || !Db::query('SELECT 1 FROM units WHERE id = ?', [$unit])) {
            throw new HttpError('Nama area dan unit wajib diisi.');
        }
        if (($id = (int) ($in['id'] ?? 0)) > 0) {
            Db::transaction([['UPDATE areas SET name = ?, unit_id = ? WHERE id = ?', [$name, $unit, $id]]]);
        } else {
            Db::transaction([['INSERT INTO areas (unit_id, name) VALUES (?, ?)', [$unit, $name]]]);
        }
        return [];
    }

    public static function deleteArea(): array
    {
        Auth::requireRole('admin');
        $id = (int) (Http::body()['id'] ?? 0);
        if ((int) Db::query('SELECT COUNT(*) c FROM equipment WHERE area_id = ?', [$id])[0]['c'] > 0) {
            throw new HttpError('Area masih punya equipment.', 409);
        }
        Db::transaction([['DELETE FROM areas WHERE id = ?', [$id]]]);
        return [];
    }

    public static function saveUnit(): array
    {
        Auth::requireRole('admin');
        $in = Http::body();
        $name = trim((string) ($in['name'] ?? ''));
        $code = strtoupper(trim((string) ($in['code'] ?? '')));
        if ($name === '' || !preg_match('~^[A-Z0-9]{1,6}$~', $code)) {
            throw new HttpError('Nama unit dan kode (1–6 huruf/angka) wajib diisi.');
        }
        if (($id = (int) ($in['id'] ?? 0)) > 0) {
            Db::transaction([['UPDATE units SET name = ?, code = ? WHERE id = ?', [$name, $code, $id]]]);
        } else {
            Db::transaction([['INSERT INTO units (code, name) VALUES (?, ?)', [$code, $name]]]);
        }
        return [];
    }
}
