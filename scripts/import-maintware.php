<?php
declare(strict_types=1);
/**
 * Impor data MaintWare lama (Google Sheets lewat Apps Script) ke database CMMS.
 *   php scripts/import-maintware.php [--env=.env.turso] [--url=<Apps Script /exec>] [--dry-run]
 * Tanpa --url, dibaca dari database/snapshot/mw_getItems.json, mw_getStockLog.json, mw_getRO.json.
 * Dengan --url, ketiganya diunduh (hanya GET getItems/getStockLog/getRO) dan snapshot diperbarui.
 *
 * Aturan:
 *  - ID item = itemCode lama. Stok = kolom stock di ITEMS_DB (sumber kebenaran stok), BUKAN hasil hitung ulang log.
 *  - Log stok di Sheets sebagian bergeser kolom. Baris dipulihkan sebagai berikut:
 *      baris normal (txType IN/OUT)                       → source SHEETS
 *      txType berisi nama item, quantity > 0, PIC nama    → OUT, source SHEETS-R1 (kolom: nama, qty, sisa, sisa, PIC, catatan)
 *      txType berisi nama item, quantity = 0, PIC angka   → qty = kolom stockBefore, sebelum = kolom pic, sesudah = kolom notes;
 *                                                           IN/OUT dari selisih, source SHEETS-R2 (PIC & catatan asli tidak tersimpan di Sheets)
 *  - Kode item yang ada di log tetapi tidak ada di ITEMS_DB dibuatkan item stub (stok 0) agar riwayat tidak hilang.
 *  - Idempoten: item di-upsert, transaksi/RO impor lama (source SHEETS*) dihapus lalu ditulis ulang.
 */
require __DIR__ . '/_cli.php';

$dry = cliFlag('dry-run');
$url = null;
foreach ($argv as $a) {
    if (str_starts_with($a, '--url=')) {
        $url = substr($a, 6);
    }
}
$dir = dirname(__DIR__) . '/database/snapshot';
@mkdir($dir, 0775, true);
if ($url) {
    foreach (['getItems', 'getStockLog', 'getRO'] as $fn) {
        $ch = curl_init("$url?action=$fn");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 90]);
        $body = (string) curl_exec($ch);
        if (curl_getinfo($ch, CURLINFO_HTTP_CODE) !== 200 || !json_decode($body, true)['success']) {
            exit("Gagal mengunduh $fn\n");
        }
        file_put_contents("$dir/mw_$fn.json", $body);
        echo "Unduh $fn: " . strlen($body) . " byte\n";
    }
}
$load = fn (string $f) => json_decode((string) @file_get_contents("$dir/mw_$f.json"), true)['data'] ?? exit("Snapshot $f tidak ada — jalankan dengan --url=\n");
$items = $load('getItems');
$log = $load('getStockLog');
$ros = $load('getRO');

Migrator::run();

// ── normalisasi item ──
$uomMap = ['pcs' => 'pcs', 'meter' => 'meter', 'pack' => 'pack', 'box' => 'box', 'roll' => 'roll', 'kg' => 'kg', 'set' => 'set'];
$st = [];
$known = [];
foreach ($items as $i) {
    $code = trim((string) $i['itemCode']);
    $uom = strtolower(trim((string) $i['uom']));
    $uom = $uomMap[$uom] ?? 'pcs'; // satu-dua sel uom di Sheets berisi tanggal (salah ketik) → pcs
    $known[$code] = $i['itemName'];
    $st[] = ['INSERT INTO mw_items (id, name, brand, spec, item_type, item_class, moving_type, uom, qty, min_stock, price, pic, notes, updated_at)
              VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
              ON CONFLICT(id) DO UPDATE SET name=excluded.name, brand=excluded.brand, spec=excluded.spec, item_type=excluded.item_type, item_class=excluded.item_class,
                moving_type=excluded.moving_type, uom=excluded.uom, qty=excluded.qty, min_stock=excluded.min_stock, price=excluded.price, pic=excluded.pic,
                notes=excluded.notes, updated_at=excluded.updated_at',
        [$code, $i['itemName'], nz($i['brand'] ?? null), nz($i['spec'] ?? null), nz($i['itemType']), nz($i['itemClass']), nz($i['movingType']), $uom,
            (float) $i['stock'], (float) ($i['minStock'] ?? 0), (float) ($i['purchasePrice'] ?? 0), nz($i['pic'] ?? null), nz($i['notes'] ?? null),
            dateTime($i['lastUpdate'] ?? '') ?? now()]];
}

// ── pemulihan log stok ──
$trx = [];
$skipped = 0;
$stubs = [];
foreach ($log as $r) {
    $code = trim((string) $r['itemCode']);
    $ts = dateTime($r['timestamp'] ?? '');
    if ($code === '' || !$ts) {
        $skipped++;
        continue;
    }
    $t = (string) $r['txType'];
    if ($t === 'IN' || $t === 'OUT') {
        $row = [$t, (float) $r['quantity'], (float) $r['stockAfter'], nz($r['pic']), nz($r['notes']), 'SHEETS', nz($r['woId'] ?? null)];
        $name = $r['itemName'] ?: null;
    } elseif ((float) $r['quantity'] > 0 && !is_numeric($r['pic'])) {
        $isIn = (bool) preg_match('~^\s*(add |restock|tambah|pembelian|beli|barang masuk)~i', (string) $r['notes']);
        $row = [$isIn ? 'IN' : 'OUT', (float) $r['quantity'], (float) $r['stockAfter'], nz($r['pic']), nz($r['notes']), 'SHEETS-R1', null];
        $name = $t;
    } elseif (is_numeric($r['pic']) && is_numeric($r['notes'])) {
        [$before, $after] = [(float) $r['pic'], (float) $r['notes']];
        if ($before === $after || abs($before - $after) != (float) $r['stockBefore']) {
            $skipped++;
            continue;
        }
        $row = [$after > $before ? 'IN' : 'OUT', abs($after - $before), $after, 'Import Sheets', 'PIC & catatan asli tidak tersimpan di Sheets', 'SHEETS-R2', null];
        $name = $t;
    } else {
        $skipped++;
        continue;
    }
    if (!isset($known[$code])) {
        $known[$code] = $stubs[$code] = $name ?: $code;
        $st[] = ['INSERT INTO mw_items (id, name, qty, uom, notes, updated_at) VALUES (?,?,0,?,?,?) ON CONFLICT(id) DO NOTHING',
            [$code, $stubs[$code], 'pcs', 'Item stub: tidak ada di ITEMS_DB Sheets, dibuat dari riwayat log', now()]];
    }
    $trx[] = [$ts, $code, ...$row];
}

echo count($items) . ' item, ' . count($trx) . ' transaksi dipulihkan, ' . $skipped . ' baris log tidak bisa dipulihkan (dilewati), ' . count($stubs) . " item stub, " . count($ros) . " RO\n";
if ($dry) {
    exit("--dry-run: tidak ada yang ditulis.\n");
}

$st[] = ["DELETE FROM mw_transactions WHERE source LIKE 'SHEETS%'"];
foreach ($trx as [$ts, $code, $type, $qty, $after, $pic, $notes, $src, $wo]) {
    $st[] = ['INSERT INTO mw_transactions (trx_date, item_id, trx_type, qty, stock_after, pic, notes, wo_id, source, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
        [$ts, $code, $type, $qty, $after, $pic, $notes, $wo, $src, 'import', now()]];
}
// RO lama dihapus-tulis ulang hanya yang berasal dari impor (dibuat_oleh = import)
$st[] = ["DELETE FROM mw_ro_items WHERE ro_no IN (SELECT ro_no FROM mw_ro WHERE created_by = 'import')"];
$st[] = ["DELETE FROM mw_ro WHERE created_by = 'import'"];
foreach ($ros as $ro) {
    $created = roTime((string) ($ro['createdAt'] ?? '')) ?? now();
    $st[] = ['INSERT INTO mw_ro (ro_no, ro_date, pic, dept, notes, priority, needed_date, status, total_est, created_by, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
              ON CONFLICT(ro_no) DO NOTHING',
        [$ro['roNo'], $ro['date'] ?: substr($created, 0, 10), $ro['pic'] ?: '-', $ro['dept'] ?: '-', nz($ro['notes'] ?? null), $ro['priority'] ?: 'Normal', nz($ro['neededDate'] ?? null),
            $ro['status'] ?: 'Open', (float) ($ro['totalEst'] ?? 0), 'import', $created, roTime((string) ($ro['updatedAt'] ?? '')) ?? $created]];
    foreach ($ro['items'] ?? [] as $it) {
        $st[] = ['INSERT INTO mw_ro_items (ro_no, item_id, name, spec, qty, unit_price) SELECT ?,?,?,?,?,? WHERE EXISTS (SELECT 1 FROM mw_ro WHERE ro_no = ? AND created_by = ?)',
            [$ro['roNo'], nz($it['itemCode'] ?? null), $it['name'] ?: '-', nz($it['spec'] ?? null), (float) ($it['qty'] ?? 1), (float) ($it['unitPrice'] ?? 0), $ro['roNo'], 'import']];
    }
}
// Item contoh dari seed-demo tidak berguna lagi setelah data asli masuk
$st[] = ["DELETE FROM mw_items WHERE id LIKE 'DEMO-%'"];
foreach (array_chunk($st, 150) as $chunk) {
    Db::transaction($chunk);
}
foreach (['mw_items', 'mw_transactions', 'mw_ro', 'mw_ro_items'] as $t) {
    echo str_pad($t, 18) . Db::query("SELECT COUNT(*) c FROM $t")[0]['c'] . "\n";
}

function nz(mixed $v): ?string
{
    $v = trim((string) $v);
    return ($v === '' || $v === '-') ? null : $v;
}
function dateTime(string $s): ?string
{
    $t = strtotime($s);
    return $t ? date('Y-m-d H:i:s', $t) : null;
}
/** "29/6/2026, 09.19.10" (id-ID) → "2026-06-29 09:19:10" */
function roTime(string $s): ?string
{
    return preg_match('~(\d{1,2})/(\d{1,2})/(\d{4}),?\s*(\d{1,2})[.:](\d{2})[.:](\d{2})~', $s, $m)
        ? sprintf('%04d-%02d-%02d %02d:%02d:%02d', $m[3], $m[2], $m[1], $m[4], $m[5], $m[6]) : null;
}
