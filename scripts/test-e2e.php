<?php
declare(strict_types=1);
/**
 * Tes end-to-end dengan data acak, lewat HTTP ke API (php -S) pada database SQLite sementara:
 *   php scripts/test-e2e.php
 * Alur: admin buat user → user buat WO → SPV assign → teknisi Start/Hold/Continue/Part/Note → Finish
 *       → user verifikasi + rating → statistik, MTTR/MTBF, in/out, history, pembatasan role.
 */
$root = dirname(__DIR__);
$dbFile = $root . '/database/test-e2e.db';
// Tambahkan --mysql untuk menguji pada MySQL/MariaDB (database cmms_test, dibuat ulang; env MYSQL_* bisa menimpa)
$useMysql = in_array('--mysql', $argv, true);
foreach (['', '-wal', '-shm'] as $s) {
    @unlink($dbFile . $s);
}
if ($useMysql) {
    $my = fn (string $k, string $d) => getenv($k) !== false && getenv($k) !== '' ? getenv($k) : $d;
    $pdo = new PDO('mysql:host=' . $my('MYSQL_HOST', '127.0.0.1') . ';port=' . $my('MYSQL_PORT', '3306') . ';charset=utf8mb4', $my('MYSQL_USER', 'root'), $my('MYSQL_PASSWORD', ''));
    $pdo->exec('DROP DATABASE IF EXISTS cmms_test');
    $pdo->exec('CREATE DATABASE cmms_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    putenv('DB_DRIVER=mysql');
    putenv('MYSQL_DATABASE=cmms_test');
} else {
    putenv('SQLITE_PATH=database/test-e2e.db');
    putenv('DB_DRIVER=sqlite');
}
putenv('ADMIN_USERNAME=admin');
putenv('ADMIN_PASSWORD=admin-test-1');
$_ENV['SQLITE_PATH'] = 'database/test-e2e.db';

// Bootstrap lokal untuk menyiapkan data awal & memanipulasi waktu sesi
require __DIR__ . '/_cli.php';
Migrator::run();
Auth::seedAdmin();
Db::transaction([
    ['INSERT INTO units (id, code, name) VALUES (1,?,?), (2,?,?)', ['P1', 'Pasuruan 1', 'MLG', 'Malang']],
    ['INSERT INTO areas (id, unit_id, name) VALUES (1,1,?), (2,2,?)', ['Pakan', 'Gudang']],
    ['INSERT INTO equipment (id, area_id, name) VALUES (1,1,?), (2,2,?)', ['Hammer Mill ' . random_int(1, 99), 'Conveyor ' . random_int(1, 99)]],
    ['INSERT INTO mw_items (id, name, uom, unit_id, qty) VALUES (?,?,?,?,?), (?,?,?,?,?)',
        ['MW-1', 'Bearing 6205', 'pcs', 1, 10, 'MW-2', 'V-Belt B52', 'pcs', 2, 4]],
]);

$port = random_int(8300, 8999);
$proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $root], [['file', 'NUL', 'r'], ['file', 'NUL', 'w'], ['file', 'NUL', 'w']], $pipes);
register_shutdown_function(function () use ($proc) {
    proc_terminate($proc);
});
for ($i = 0; $i < 50; $i++) {
    if (@fsockopen('127.0.0.1', $port)) {
        break;
    }
    usleep(100000);
}

$fail = 0;
$pass = 0;
function call(string $method, string $route, array $q = [], ?array $body = null, ?string $token = null): array
{
    global $port;
    $url = "http://127.0.0.1:$port/api/index.php?r=" . $route . ($q ? '&' . http_build_query($q) : '');
    $ch = curl_init($url);
    $h = ['Content-Type: application/json'];
    if ($token) {
        $h[] = 'X-Auth-Token: ' . $token;
    }
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $h,
        CURLOPT_POSTFIELDS => $body !== null ? json_encode($body) : null]);
    $res = json_decode((string) curl_exec($ch), true) ?: ['ok' => false, 'error' => 'respons bukan JSON'];
    $res['_status'] = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    return $res;
}
function check(string $label, bool $ok, string $extra = ''): void
{
    global $fail, $pass;
    $ok ? $pass++ : $fail++;
    echo ($ok ? '  ok   ' : '  GAGAL') . " $label" . ($ok ? '' : " — $extra") . "\n";
}

echo "== Login & user\n";
$r = call('POST', 'auth/login', [], ['username' => 'admin', 'password' => 'salah']);
check('login salah ditolak (401)', $r['_status'] === 401);
$admin = call('POST', 'auth/login', [], ['username' => 'admin', 'password' => 'admin-test-1']);
check('login admin', ($admin['ok'] ?? false) && !empty($admin['token']));
$A = $admin['token'];

$suffix = random_int(100, 999);
$accounts = [];
foreach (['spv' => 'Leader Budi', 'maintenance' => 'Teknisi Andi', 'user' => 'Operator Sari'] as $role => $name) {
    $u = "$role$suffix";
    $r = call('POST', 'users/save', [], ['username' => $u, 'password' => 'pass1234', 'name' => $name, 'role' => $role, 'unit_id' => 1, 'department' => 'Produksi'], $A);
    check("admin membuat user $role", $r['ok'] ?? false, $r['error'] ?? '');
    $accounts[$role] = call('POST', 'auth/login', [], ['username' => $u, 'password' => 'pass1234'])['token'];
}
[$S, $T, $U] = [$accounts['spv'], $accounts['maintenance'], $accounts['user']];
check('user biasa tidak bisa kelola user (403)', call('POST', 'users/save', [], ['username' => 'x1234', 'password' => 'abcdef', 'name' => 'x', 'role' => 'user'], $U)['_status'] === 403);

$meta = call('GET', 'meta', [], null, $T);
check('meta berisi teknisi otomatis untuk role Maintenance', count($meta['technicians'] ?? []) === 1);
$techId = (int) $meta['technicians'][0]['id'];

echo "== Equipment Database\n";
$eq = call('GET', 'equipment', ['q' => 'Hammer'], null, $U);
check('cari equipment', ($eq['total'] ?? 0) === 1);
$uidOf = fn (string $role) => (int) array_values(array_filter(call('GET', 'users', [], null, $A)['rows'], fn ($u) => $u['role'] === $role))[0]['id'];
[$pjProd, $pjMtc] = [$uidOf('user'), $uidOf('maintenance')];
check('equipment tanpa penanggung jawab ditolak (wajib)', call('POST', 'equipment/save', [], ['area_id' => 1, 'name' => 'Tanpa PJ'], $S)['_status'] === 400);
check('PJ Produksi harus role User (teknisi ditolak)', call('POST', 'equipment/save', [], ['area_id' => 1, 'name' => 'PJ salah', 'pj_production_id' => $pjMtc, 'pj_maintenance_id' => $pjMtc], $S)['_status'] === 400);
$r = call('POST', 'equipment/save', [], ['area_id' => 1, 'name' => 'Pompa ' . $suffix, 'brand' => 'Grundfos', 'attrs' => ['daya' => '5.5 kW'], 'pj_production_id' => $pjProd, 'pj_maintenance_id' => $pjMtc], $S);
check('SPV menambah equipment (+ atribut JSON)', $r['ok'] ?? false, $r['error'] ?? '');
check('user biasa tidak bisa tambah equipment', call('POST', 'equipment/save', [], ['area_id' => 1, 'name' => 'x'], $U)['_status'] === 403);
$eqId = 1;

echo "== Work Order: alur penuh\n";
$r = call('POST', 'wo/create', [], ['title' => 'Motor panas ' . $suffix, 'equipment_id' => $eqId, 'type' => 'Troubleshooting', 'priority' => 'High', 'description' => 'Motor overheat'], $U);
check('user membuat WO', $r['ok'] ?? false, $r['error'] ?? '');
$wo = $r['id'];
check('format ID WO', (bool) preg_match('~^WO/P1/\d{6}/0001$~', $wo), $wo);
$d = call('GET', 'wo/get', ['id' => $wo], null, $U);
check('status awal Open', $d['wo']['status'] === 'Open');
check('unit & area terisi dari equipment', (int) $d['wo']['unit_id'] === 1 && (int) $d['wo']['area_id'] === 1);

check('user tidak bisa Start (403)', call('POST', 'wo/action', [], ['id' => $wo, 'action' => 'start'], $U)['_status'] === 403);
check('Hold sebelum Start ditolak (409)', call('POST', 'wo/action', [], ['id' => $wo, 'action' => 'hold', 'note' => 'x'], $T)['_status'] === 409);
$r = call('POST', 'wo/action', [], ['id' => $wo, 'action' => 'assign', 'tech_ids' => [$techId]], $S);
check('SPV assign teknisi', $r['ok'] ?? false, $r['error'] ?? '');
$r = call('POST', 'wo/action', [], ['id' => $wo, 'action' => 'start'], $T);
check('teknisi Start → In Progress', ($r['status'] ?? '') === 'In Progress', $r['error'] ?? '');

// Mundurkan sesi berjalan 2 jam agar durasi terukur
Db::transaction([['UPDATE wo_sessions SET started_at = ? WHERE wo_id = ? AND ended_at IS NULL', [date('Y-m-d H:i:s', time() - 7200), $wo]]]);
$list = call('GET', 'wo', ['q' => $wo], null, $T);
check('timer berjalan (≈2 jam)', abs($list['rows'][0]['work_seconds'] - 7200) < 5 && $list['rows'][0]['running_since'] !== null);
check('Hold tanpa catatan ditolak', call('POST', 'wo/action', [], ['id' => $wo, 'action' => 'hold'], $T)['_status'] === 409);
$r = call('POST', 'wo/action', [], ['id' => $wo, 'action' => 'hold', 'note' => 'Bongkar motor, tunggu bearing'], $T);
check('Hold dengan catatan', $r['ok'] ?? false, $r['error'] ?? '');
$d = call('GET', 'wo/get', ['id' => $wo], null, $T);
check('timer berhenti saat Hold (7200 dtk)', abs($d['wo']['work_seconds'] - 7200) < 5 && $d['wo']['running_since'] === null, json_encode($d['wo']['work_seconds']));
check('Hold otomatis jadi catatan', count($d['notes']) === 1);
check('Hold dua kali ditolak', call('POST', 'wo/action', [], ['id' => $wo, 'action' => 'hold', 'note' => 'x'], $T)['_status'] === 409);

$r = call('POST', 'wo/action', [], ['id' => $wo, 'action' => 'continue'], $T);
check('Continue hari berikutnya', $r['ok'] ?? false, $r['error'] ?? '');
Db::transaction([['UPDATE wo_sessions SET started_at = ? WHERE wo_id = ? AND ended_at IS NULL', [date('Y-m-d H:i:s', time() - 3600), $wo]]]);

$items = call('GET', 'mw/items', ['unit_id' => 1], null, $T);
check('Parts Used: filter unit', count($items['rows']) === 1 && $items['rows'][0]['id'] === 'MW-1');
check('tambah part dari MaintWare', call('POST', 'wo/part', [], ['id' => $wo, 'item_id' => 'MW-1', 'qty' => 2], $T)['ok'] ?? false);
check('stok MaintWare berkurang otomatis (10 → 8)', (float) Db::query("SELECT qty FROM mw_items WHERE id = 'MW-1'")[0]['qty'] === 8.0);
$tx = Db::query("SELECT * FROM mw_transactions WHERE item_id = 'MW-1' AND wo_id = ?", [$wo]);
check('transaksi OUT tercatat dengan WO & equipment', count($tx) === 1 && $tx[0]['trx_type'] === 'OUT' && (int) $tx[0]['equipment_id'] === 1 && $tx[0]['source'] === 'WO');
check('part melebihi stok ditolak (409)', call('POST', 'wo/part', [], ['id' => $wo, 'item_id' => 'MW-1', 'qty' => 100], $T)['_status'] === 409);
$pid = call('GET', 'wo/get', ['id' => $wo], null, $T)['parts'][0]['id'];
check('hapus part mengembalikan stok', (call('POST', 'wo/part/delete', [], ['part_id' => $pid], $T)['ok'] ?? false) && (float) Db::query("SELECT qty FROM mw_items WHERE id = 'MW-1'")[0]['qty'] === 10.0);
check('tambah part lagi', call('POST', 'wo/part', [], ['id' => $wo, 'item_id' => 'MW-1', 'qty' => 2], $T)['ok'] ?? false);
check('user tidak bisa tambah part', call('POST', 'wo/part', [], ['id' => $wo, 'item_id' => 'MW-1', 'qty' => 1], $U)['_status'] === 403);
check('tambah catatan', call('POST', 'wo/note', [], ['id' => $wo, 'note' => 'Bearing diganti'], $T)['ok'] ?? false);
check('SPV buat tasklist', call('POST', 'wo/task', [], ['id' => $wo, 'text' => 'Ganti bearing'], $S)['ok'] ?? false);
$d = call('GET', 'wo/get', ['id' => $wo], null, $T);
check('teknisi centang task', call('POST', 'wo/task/toggle', [], ['task_id' => $d['tasks'][0]['id']], $T)['done'] === 1);

echo "== Foto & lampiran WO\n";
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
$pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF";
$up = fn (string $tok, string $cat, string $name, string $bytes) => call('POST', 'wo/file', [], ['id' => $wo, 'category' => $cat, 'name' => $name, 'data' => base64_encode($bytes)], $tok);
$r = $up($U, 'before', 'sebelum.png', $png);
check('pemohon unggah foto Before', $r['ok'] ?? false, $r['error'] ?? '');
$beforeId = $r['id'] ?? 0;
check('pemohon tidak boleh unggah foto After (403)', $up($U, 'after', 'x.png', $png)['_status'] === 403);
check('teknisi unggah foto After', $up($T, 'after', 'sesudah.png', $png)['ok'] ?? false);
check('file teks berpura-pura PNG ditolak', $up($T, 'after', 'palsu.png', 'ini bukan gambar')['_status'] === 409);
check('file berbahaya (.exe) sebagai lampiran ditolak', $up($T, 'attachment', 'virus.pdf', "MZ\x90\x00\x03\x00\x00\x00" . str_repeat("\0", 200))['_status'] === 409);
check('foto > 2 MB ditolak', $up($T, 'after', 'besar.png', $png . str_repeat("\0", 2_100_000))['_status'] === 409);
$r = $up($T, 'attachment', 'laporan.pdf', $pdf);
check('teknisi unggah lampiran PDF', $r['ok'] ?? false, $r['error'] ?? '');
$pdfId = $r['id'] ?? 0;
$d = call('GET', 'wo/get', ['id' => $wo], null, $U);
check('detail WO memuat daftar file (tanpa isi)', count($d['files']) === 3 && !isset($d['files'][0]['data']));
$ch = curl_init("http://127.0.0.1:$port/api/index.php?r=wo/file&id=$beforeId");
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['X-Auth-Token: ' . $U]]);
$body = curl_exec($ch);
check('unduh foto: isi identik & content-type image/png', $body === $png && str_starts_with((string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE), 'image/png'));
$ch = curl_init("http://127.0.0.1:$port/api/index.php?r=wo/file&id=$beforeId");
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true]);
curl_exec($ch);
check('unduh tanpa login ditolak (401)', curl_getinfo($ch, CURLINFO_HTTP_CODE) === 401);
check('pemohon tidak bisa hapus file teknisi (403)', call('POST', 'wo/file/delete', [], ['file_id' => $pdfId], $U)['_status'] === 403);
check('SPV bisa hapus file', call('POST', 'wo/file/delete', [], ['file_id' => $pdfId], $S)['ok'] ?? false);
check('foto verifikasi oleh pemohon', $up($U, 'verification', 'bukti.png', $png)['ok'] ?? false);

check('Finish tanpa catatan ditolak', call('POST', 'wo/action', [], ['id' => $wo, 'action' => 'finish'], $T)['_status'] === 409);
$r = call('POST', 'wo/action', [], ['id' => $wo, 'action' => 'finish', 'note' => 'Bearing diganti, motor normal'], $T);
check('Finish → Pending Verification', ($r['status'] ?? '') === 'Pending Verification', $r['error'] ?? '');
$d = call('GET', 'wo/get', ['id' => $wo], null, $U);
check('durasi berhenti di Pending (≈3 jam)', abs($d['wo']['work_seconds'] - 10800) < 5, (string) $d['wo']['work_seconds']);
check('2 sesi kerja tercatat', count($d['sessions']) === 2);
check('part tersimpan', count($d['parts']) === 1 && (float) $d['parts'][0]['qty'] === 2.0);
$r = call('POST', 'wo/action', [], ['id' => $wo, 'action' => 'verify', 'rating' => 5, 'rating_comment' => 'Cepat'], $U);
check('pemohon verifikasi → Closed', ($r['status'] ?? '') === 'Closed', $r['error'] ?? '');
check('WO Closed tidak bisa diubah', call('POST', 'wo/part', [], ['id' => $wo, 'item_id' => 'MW-1', 'qty' => 1], $T)['_status'] === 409);
check('unggah ke WO Closed ditolak (409)', $up($T, 'after', 'telat.png', $png)['_status'] === 409);
check('Equipment History oleh SPV', call('POST', 'wo/history', [], ['id' => $wo, 'what' => 'Ganti bearing', 'why' => 'Aus', 'how' => 'Bongkar'], $S)['ok'] ?? false);
check('Equipment History ditolak untuk user', call('POST', 'wo/history', [], ['id' => $wo, 'what' => 'x'], $U)['_status'] === 403);

echo "== WO kedua (ditolak verifikasi) & WO ketiga (kerusakan lagi untuk MTBF)\n";
$wo2 = call('POST', 'wo/create', [], ['title' => 'Conveyor macet', 'equipment_id' => 2, 'type' => 'Improvement'], $U)['id'];
check('ID urut per unit/bulan', str_ends_with($wo2, '/0001') && str_contains($wo2, 'WO/MLG/'), $wo2);
call('POST', 'wo/action', [], ['id' => $wo2, 'action' => 'start'], $T);
call('POST', 'wo/action', [], ['id' => $wo2, 'action' => 'finish', 'note' => 'selesai'], $T);
$r = call('POST', 'wo/action', [], ['id' => $wo2, 'action' => 'reject'], $U);
check('tolak tanpa alasan ditolak', $r['_status'] === 409);
$r = call('POST', 'wo/action', [], ['id' => $wo2, 'action' => 'reject', 'note' => 'Masih macet'], $U);
check('verifikasi ditolak → In Progress', ($r['status'] ?? '') === 'In Progress', $r['error'] ?? '');
$d = call('GET', 'wo/get', ['id' => $wo2], null, $T);
check('setelah ditolak timer tidak berjalan (menunggu Continue)', $d['wo']['running_since'] === null);
check('batalkan WO (SPV)', (call('POST', 'wo/action', [], ['id' => $wo2, 'action' => 'cancel', 'note' => 'dobel'], $S)['status'] ?? '') === 'Cancelled');

$wo3 = call('POST', 'wo/create', [], ['title' => 'Motor panas lagi', 'equipment_id' => $eqId, 'type' => 'Corrective'], $U)['id'];
Db::transaction([['UPDATE work_orders SET created_at = ? WHERE id = ?', [date('Y-m-d H:i:s', time() - 10 * 86400), $wo]]]); // WO#1 10 hari lalu
call('POST', 'wo/action', [], ['id' => $wo3, 'action' => 'start'], $T);
Db::transaction([['UPDATE wo_sessions SET started_at = ? WHERE wo_id = ? AND ended_at IS NULL', [date('Y-m-d H:i:s', time() - 3600), $wo3]]]);
call('POST', 'wo/action', [], ['id' => $wo3, 'action' => 'finish', 'note' => 'ok'], $T);

echo "== Laporan\n";
$rel = call('GET', 'wo/get', ['id' => $wo3], null, $T)['reliability'];
check('MTTR = (3 jam + 1 jam) / 2 = 2 jam', abs($rel['mttr_hours'] - 2.0) < 0.01, json_encode($rel));
check('MTBF ≈ 240 jam (10 hari antar 2 kerusakan)', abs($rel['mtbf_hours'] - 240.0) < 0.2, json_encode($rel));
$m = call('GET', 'wo/mttr', [], null, $S)['rows'];
check('laporan MTTR/MTBF per equipment', count($m) === 1 && $m[0]['failures'] === 2);
$st = call('GET', 'wo/stats', [], null, $S);
check('statistik dashboard', ($st['by_status']['Closed'] ?? 0) === 1 && ($st['by_status']['Cancelled'] ?? 0) === 1 && count($st['series']) === 30);
check('in/out per bulan', array_sum(array_column(call('GET', 'wo/comparison', ['from' => date('Y-m-01', strtotime('-1 month'))], null, $S)['rows'], 'in')) === 3);
$tr = call('GET', 'wo/technicians', [], null, $S)['rows'];
check('rating teknisi', abs((float) $tr[0]['avg_rating'] - 5.0) < 0.01 && (int) $tr[0]['closed'] === 1, json_encode($tr[0]));
check('daftar Equipment History', count(call('GET', 'wo/history', [], null, $S)['rows']) === 1);
check('user hanya melihat WO sendiri', call('GET', 'wo', [], null, call('POST', 'auth/login', [], ['username' => 'admin', 'password' => 'admin-test-1'])['token'])['total'] === 3);
$ev = call('GET', 'wo/get', ['id' => $wo], null, $T)['events'];
check('timeline mencatat semua kejadian (≥ 9)', count($ev) >= 9, (string) count($ev));
check('equipment get: reliability & recent_wo', count(call('GET', 'equipment/get', ['id' => $eqId], null, $T)['recent_wo']) === 2);
check('equipment berWO tidak bisa dihapus', call('POST', 'equipment/delete', [], ['id' => $eqId], $A)['_status'] === 409);

echo "== MaintWare\n";
// PNG 1x1 valid (magic bytes asli) untuk foto item
$png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
check('user biasa tidak bisa akses MaintWare (403)', call('GET', 'mw/dashboard', [], null, $U)['_status'] === 403);
check('user biasa tidak bisa Stock IN (403)', call('POST', 'mw/stock', [], ['item_id' => 'MW-1', 'type' => 'IN', 'qty' => 1, 'pic' => 'x'], $U)['_status'] === 403);
$r = call('POST', 'mw/item/save', [], ['name' => 'Gearbox seal ' . $suffix, 'item_type' => 'Mechanical', 'item_class' => 'Spare Part', 'moving_type' => 'Slow Moving', 'uom' => 'pcs', 'price' => 50000, 'qty' => 5, 'min_stock' => 3, 'pic' => 'Andi', 'photo' => $png], $T);
check('item baru dapat kode MECH-001', ($r['id'] ?? '') === 'MECH-001', json_encode($r));
$nf = call('POST', 'mw/item/save', [], ['name' => 'Kabel', 'item_type' => 'Electrical', 'item_class' => 'Consumable', 'moving_type' => 'Fast Moving', 'pic' => 'Andi'], $T);
check('item baru tanpa foto ditolak (400)', $nf['_status'] === 400);
$r2 = call('POST', 'mw/item/save', [], ['name' => 'Kabel', 'item_type' => 'Electrical', 'item_class' => 'Consumable', 'moving_type' => 'Fast Moving', 'pic' => 'Andi', 'photo' => $png], $T);
check('prefix per type (ELEC-001)', ($r2['id'] ?? '') === 'ELEC-001');
check('foto bukan gambar ditolak (400)', call('POST', 'mw/item/save', [], ['name' => 'x', 'item_type' => 'Mechanical', 'item_class' => 'Tools', 'moving_type' => 'Fast Moving', 'pic' => 'A', 'photo' => base64_encode('bukan gambar')], $T)['_status'] === 400);
check('foto terlalu besar ditolak (400)', call('POST', 'mw/item/save', [], ['name' => 'x', 'item_type' => 'Mechanical', 'item_class' => 'Tools', 'moving_type' => 'Fast Moving', 'pic' => 'A', 'photo' => base64_encode("\xFF\xD8\xFF" . str_repeat('a', 700000))], $T)['_status'] === 400);
$ph = (function () use ($port, $T) { $ch = curl_init("http://127.0.0.1:$port/api/index.php?r=mw/item/photo&id=MECH-001"); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['X-Auth-Token: ' . $T]]); $b = curl_exec($ch); return [curl_getinfo($ch, CURLINFO_RESPONSE_CODE), curl_getinfo($ch, CURLINFO_CONTENT_TYPE), $b]; })();
check('foto item dapat diunduh (image/png)', $ph[0] === 200 && $ph[1] === 'image/png' && str_starts_with((string) $ph[2], "\x89PNG"));
check('has_photo di daftar & detail item', (int) (call('GET', 'mw/items', ['q' => 'MECH-001'], null, $T)['rows'][0]['has_photo'] ?? 0) === 1 && (int) (call('GET', 'mw/item', ['id' => 'MECH-001'], null, $T)['item']['has_photo'] ?? 0) === 1);
$none = array_column(call('GET', 'mw/items', ['photo' => 'none'], null, $T)['rows'], 'id');
check('filter tanpa foto', in_array('MW-1', $none, true) && !in_array('MECH-001', $none, true));
check('foto item tanpa login ditolak (401)', call('GET', 'mw/item/photo', ['id' => 'MECH-001'])['_status'] === 401);
check('item tanpa PIC ditolak', call('POST', 'mw/item/save', [], ['name' => 'x', 'item_type' => 'Mechanical', 'item_class' => 'Tools', 'moving_type' => 'Fast Moving'], $T)['_status'] === 400);
check('stok awal tercatat sebagai transaksi IN', (int) Db::query("SELECT COUNT(*) c FROM mw_transactions WHERE item_id = 'MECH-001' AND trx_type = 'IN'")[0]['c'] === 1);
$r = call('POST', 'mw/stock', [], ['item_id' => 'MECH-001', 'type' => 'OUT', 'qty' => 3, 'pic' => 'Andi', 'equipment_id' => 2, 'notes' => 'ganti seal'], $T);
check('Stock OUT ke equipment (5 → 2)', (float) ($r['qty'] ?? -1) === 2.0, json_encode($r));
$t = Db::query("SELECT * FROM mw_transactions WHERE item_id = 'MECH-001' AND trx_type = 'OUT'")[0];
check('OUT menyimpan unit/area dari Equipment DB', (int) $t['unit_id'] === 2 && (int) $t['area_id'] === 2);
check('OUT melebihi stok ditolak', call('POST', 'mw/stock', [], ['item_id' => 'MECH-001', 'type' => 'OUT', 'qty' => 3, 'pic' => 'Andi'], $T)['_status'] === 409);
check('Stock IN', (float) (call('POST', 'mw/stock', [], ['item_id' => 'MECH-001', 'type' => 'IN', 'qty' => 10, 'pic' => 'Andi', 'notes' => 'beli'], $T)['qty'] ?? 0) === 12.0);
$re = call('GET', 'mw/reorder', [], null, $T)['rows'];
check('Reorder: item dengan min stok 0 tidak muncul', count(array_filter($re, fn ($x) => $x['id'] === 'ELEC-001')) === 0);
call('POST', 'mw/item/save', [], ['id' => 'MECH-001', 'name' => 'Gearbox seal', 'item_type' => 'Mechanical', 'item_class' => 'Spare Part', 'moving_type' => 'Slow Moving', 'min_stock' => 20, 'pic' => 'Andi'], $T);
check('Reorder: muncul setelah min stok dinaikkan', in_array('MECH-001', array_column(call('GET', 'mw/reorder', [], null, $T)['rows'], 'id'), true));
$d = call('GET', 'mw/dashboard', [], null, $T);
check('dashboard MaintWare', $d['summary']['items'] === 4 && $d['summary']['low'] >= 1 && $d['summary']['out_tx'] >= 2 && count($d['by_equipment']) >= 1, json_encode($d['summary']));
$mo = call('GET', 'mw/monthly', ['year' => (int) date('Y'), 'month' => (int) date('n')], null, $T);
check('laporan bulanan memuat transaksi', count($mo['rows']) >= 4);
check('log: filter OUT', count(call('GET', 'mw/log', ['tx' => 'OUT'], null, $T)['rows']) >= 2);
check('cari item untuk Parts Used (filter unit)', count(call('GET', 'mw/items', ['unit_id' => 2, 'q' => 'Gearbox'], null, $U)['rows']) === 1);
$ro = call('POST', 'mw/ro/save', [], ['pic' => 'Andi', 'dept' => 'Maintenance', 'priority' => 'Urgent', 'items' => [['item_id' => 'MECH-001', 'name' => 'Gearbox seal', 'qty' => 10, 'unit_price' => 50000], ['name' => 'Oli manual', 'qty' => 2, 'unit_price' => 100000]]], $T);
check('RO dibuat (nomor RO-YYMM-001)', (bool) preg_match('~^RO-\d{4}-001$~', $ro['ro_no'] ?? ''), json_encode($ro));
$rl = call('GET', 'mw/ro', [], null, $T);
check('RO total estimasi = 700.000', (float) $rl['rows'][0]['total_est'] === 700000.0 && count($rl['rows'][0]['items']) === 2);
check('teknisi tidak bisa ubah status RO (403)', call('POST', 'mw/ro/status', [], ['ro_no' => $ro['ro_no'], 'status' => 'Approved'], $T)['_status'] === 403);
check('SPV menyetujui RO', call('POST', 'mw/ro/status', [], ['ro_no' => $ro['ro_no'], 'status' => 'Approved'], $S)['ok'] ?? false);
check('RO Approved tidak bisa diedit (409)', call('POST', 'mw/ro/save', [], ['ro_no' => $ro['ro_no'], 'pic' => 'a', 'dept' => 'b', 'items' => [['name' => 'x']]], $T)['_status'] === 409);
check('item terpakai WO tidak bisa dihapus', call('POST', 'mw/item/delete', [], ['id' => 'MW-1'], $A)['_status'] === 409);
check('item baru bisa dihapus admin', call('POST', 'mw/item/delete', [], ['id' => 'ELEC-001'], $A)['ok'] ?? false);

echo "== Daily Monitoring (parameter di Equipment Database)\n";
$r = call('POST', 'dm/type/save', [], ['code' => 'mcc', 'label' => 'MCC Panel'], $S);
check('SPV membuat tipe monitoring', ($r['code'] ?? '') === 'MCC', json_encode($r));
check('teknisi tidak bisa mengatur tipe/template (403)', call('POST', 'dm/type/save', [], ['code' => 'X', 'label' => 'x'], $T)['_status'] === 403);
$tpl = [
    ['label' => 'Suhu Panel', 'unit' => '°C', 'section' => 'Kondisi', 'type' => 'numeric', 'normal_min' => 0, 'normal_max' => 50, 'warn_max' => 60],
    ['label' => 'Arus R', 'unit' => 'A', 'section' => 'Arus', 'type' => 'numeric', 'normal_min' => 0, 'normal_max' => 400],
    ['label' => 'Status Breaker', 'unit' => '', 'section' => 'Status', 'type' => 'status', 'options' => ['ON', 'OFF', 'TRIP']],
];
foreach ($tpl as $t) {
    $ok = call('POST', 'dm/template/save', [], ['monitor_type' => 'MCC'] + $t, $S)['ok'] ?? false;
    if (!$ok) { break; }
}
check('template parameter tipe MCC (3 baris)', (int) Db::query("SELECT COUNT(*) c FROM dm_param_templates WHERE monitor_type = 'MCC'")[0]['c'] === 3);
check('batas tidak masuk akal ditolak (warn_max < normal_max)', call('POST', 'dm/template/save', [], ['monitor_type' => 'MCC', 'label' => 'X', 'type' => 'numeric', 'normal_max' => 50, 'warn_max' => 40], $S)['_status'] === 400);
check('parameter status < 2 opsi ditolak', call('POST', 'dm/template/save', [], ['monitor_type' => 'MCC', 'label' => 'X', 'type' => 'status', 'options' => ['ON']], $S)['_status'] === 400);
check('user biasa tidak bisa terapkan template (403)', call('POST', 'eqparam/template', [], ['equipment_id' => 1, 'monitor_type' => 'MCC'], $U)['_status'] === 403);
$r = call('POST', 'eqparam/template', [], ['equipment_id' => 1, 'monitor_type' => 'MCC', 'mode' => 'replace'], $S);
check('terapkan template ke equipment', ($r['params'] ?? 0) === 3, json_encode($r));
$g = call('GET', 'equipment/get', ['id' => 1], null, $U);
check('equipment/get memuat parameter & monitor_type', count($g['params']) === 3 && $g['equipment']['monitor_type'] === 'MCC');
check('tambah parameter manual di equipment', call('POST', 'eqparam/save', [], ['equipment_id' => 1, 'label' => 'Kebersihan', 'type' => 'status', 'section' => 'Fisik', 'options' => "BERSIH\nKOTOR\nBERAT"], $S)['ok'] ?? false);
$ps = call('GET', 'eqparam', ['equipment_id' => 1], null, $T)['params'];
check('4 parameter, opsi status tersimpan', count($ps) === 4 && $ps[3]['options'] === ['BERSIH', 'KOTOR', 'BERAT']);
$ids = array_column($ps, 'id');
check('urutkan ulang parameter', (call('POST', 'eqparam/reorder', [], ['equipment_id' => 1, 'ids' => array_reverse($ids)], $S)['ok'] ?? false) && call('GET', 'eqparam', ['equipment_id' => 1], null, $T)['params'][0]['label'] === 'Kebersihan');
check('hapus parameter', call('POST', 'eqparam/delete', [], ['id' => $ids[3]], $S)['ok'] ?? false);
$ps = call('GET', 'eqparam', ['equipment_id' => 1], null, $T)['params'];
check('3 parameter tersisa', count($ps) === 3);
$byLabel = array_column($ps, 'id', 'label');

check('Monitor board memuat equipment berparameter', count(call('GET', 'dm/board', [], null, $T)['rows']) === 1);
check('user biasa tidak bisa akses Monitor (403)', call('GET', 'dm/board', [], null, $U)['_status'] === 403);
$f = call('GET', 'dm/form', ['equipment_id' => 1], null, $T);
check('form monitoring memuat parameter', count($f['params']) === 3 && $f['equipment']['name'] !== '');
check('equipment tanpa parameter ditolak (409)', call('GET', 'dm/form', ['equipment_id' => 2], null, $T)['_status'] === 409);

$today = date('Y-m-d');
$sv = fn (array $items, array $extra = [], ?string $tok = null) => call('POST', 'dm/save', [], $extra + ['equipment_id' => 1, 'date' => $today, 'pic' => ['Andi', 'Wawan'], 'start_time' => '08:00', 'end_time' => '08:20',
    'items' => array_map(fn ($k, $v) => ['param_id' => $byLabel[$k], 'value' => $v], array_keys($items), $items)], $tok ?? $T);
check('angka ambigu "1.234,5" ditolak', $sv(['Suhu Panel' => '1.234,5'])['_status'] === 400);
check('pilihan status di luar opsi ditolak', $sv(['Status Breaker' => 'MATI'])['_status'] === 400);
check('sesi kosong ditolak', $sv([])['_status'] === 400);
check('tanggal masa depan ditolak', $sv(['Suhu Panel' => '30'], ['date' => date('Y-m-d', strtotime('+2 days'))])['_status'] === 400);
check('PIC wajib', $sv(['Suhu Panel' => '30'], ['pic' => ''])['_status'] === 400);
$r = $sv(['Suhu Panel' => '65,5', 'Arus R' => '300', 'Status Breaker' => 'OFF'], ['catatan' => 'panel panas']);
check('simpan sesi: status ALERT, 1 alert + 1 warning', ($r['status'] ?? '') === 'ALERT' && $r['alerts'] === 1 && $r['warnings'] === 1, json_encode($r));
$sid = $r['id'];
check('ID sesi DM/<unit>/<tanggal>/001', (bool) preg_match('~^DM/P1/' . date('Ymd') . '/001$~', $sid), $sid);
$ss = call('GET', 'dm/session', ['id' => $sid], null, $T);
$st = array_column($ss['items'], 'status', 'label');
check('status tiap item dihitung server', $st == ['Suhu Panel' => 'ALERT', 'Arus R' => 'OK', 'Status Breaker' => 'WARNING'], json_encode($st));
check('nilai desimal dinormalkan (65,5 → 65.5)', array_column($ss['items'], 'value', 'label')['Suhu Panel'] === '65.5');
$fd = call('GET', 'dm/findings', ['state' => 'active'], null, $T)['rows'];
check('finding aktif: 2 (ALERT dulu)', count($fd) === 2 && $fd[0]['status'] === 'ALERT');

$w = call('POST', 'dm/wo', [], ['session_id' => $sid], $T);
check('buat WO dari temuan', (bool) ($w['ok'] ?? false), $w['error'] ?? '');
$wd = call('GET', 'wo/get', ['id' => $w['wo_id']], null, $T);
check('WO source=dm, Tasklist = temuan, prioritas High (ada ALERT)', $wd['wo']['source'] === 'dm' && $wd['wo']['source_ref'] === $sid && count($wd['tasks']) === 2 && $wd['wo']['priority'] === 'High');
check('sesi tertaut ke WO; WO ganda ditolak (409)', call('GET', 'dm/session', ['id' => $sid], null, $T)['session']['wo_id'] === $w['wo_id'] && call('POST', 'dm/wo', [], ['session_id' => $sid], $T)['_status'] === 409);

$r2 = $sv(['Suhu Panel' => '40', 'Arus R' => '310']);
check('sesi ke-2 mendapat nomor 002 & status OK', str_ends_with($r2['id'], '/002') && $r2['status'] === 'OK');
$fd = call('GET', 'dm/findings', ['state' => 'active'], null, $T)['rows'];
check('finding Suhu resolved oleh pembacaan baru; Status Breaker masih aktif', count($fd) === 1 && $fd[0]['label'] === 'Status Breaker');
$all = call('GET', 'dm/findings', ['state' => 'resolved'], null, $T)['rows'];
check('finding resolved berisi alasan', count($all) === 1 && $all[0]['resolved'] === 'Ada pengecekan lebih baru');
call('POST', 'wo/action', [], ['id' => $w['wo_id'], 'action' => 'cancel', 'note' => 'uji'], $S);
check('WO dibatalkan → finding terkait selesai (0 aktif)', count(call('GET', 'dm/findings', ['state' => 'active'], null, $T)['rows']) === 0);

check('edit sesi oleh pembuat', ($sv(['Suhu Panel' => '45', 'Arus R' => '320'], ['id' => $r2['id']])['status'] ?? '') === 'OK');
check('edit sesi oleh SPV', ($sv(['Suhu Panel' => '55'], ['id' => $r2['id']], $S)['status'] ?? '') === 'WARNING');
$h = call('GET', 'dm/history', ['status' => 'ALERT'], null, $T);
check('riwayat: filter status', $h['total'] === 1 && $h['rows'][0]['alerts'] === 1);
check('hapus sesi: SPV boleh, teknisi tidak', call('POST', 'dm/delete', [], ['id' => $r2['id']], $T)['_status'] === 403 && (call('POST', 'dm/delete', [], ['id' => $r2['id']], $S)['ok'] ?? false));
$cp = call('POST', 'eqparam/copy', [], ['from_equipment_id' => 1, 'equipment_ids' => [2], 'mode' => 'append'], $S);
check('salin parameter ke equipment lain', ($cp['equipment'] ?? 0) === 1 && count(call('GET', 'eqparam', ['equipment_id' => 2], null, $T)['params']) === 3);
$d = call('GET', 'dm/dashboard', [], null, $T);
check('dashboard DM', $d['kpi']['today'] === 1 && $d['kpi']['monitorable'] === 2 && $d['kpi']['alert'] === 1 && count($d['trend']) === 30 && count($d['overdue']) === 1, json_encode($d['kpi']));
check('equipment ber-riwayat monitoring tidak bisa dihapus (409)', call('POST', 'equipment/delete', [], ['id' => 1], $A)['_status'] === 409);
check('tipe monitoring terpakai tidak bisa dihapus (409)', call('POST', 'dm/type/delete', [], ['code' => 'MCC'], $A)['_status'] === 409);


echo "== Detail equipment: penanggung jawab, history, sparepart\n";
$before = call('GET', 'equipment', ['pj' => 'missing'], null, $S)['total'];
check('daftar equipment bisa difilter "PJ belum lengkap"', $before >= 1);
check('teknisi tidak bisa set PJ massal (403)', call('POST', 'equipment/owners', [], ['filter' => ['area_id' => 1], 'pj_production_id' => $pjProd], $T)['_status'] === 403);
check('PJ massal: role salah ditolak', call('POST', 'equipment/owners', [], ['filter' => ['area_id' => 1], 'pj_production_id' => $pjMtc], $S)['_status'] === 400);
$r = call('POST', 'equipment/owners', [], ['filter' => ['area_id' => 1], 'only_missing' => true, 'pj_production_id' => $pjProd, 'pj_maintenance_id' => $pjMtc], $S);
check('PJ massal untuk satu area', ($r['updated'] ?? 0) >= 1, json_encode($r));
$g = call('GET', 'equipment/get', ['id' => 1], null, $U);
check('detail equipment memuat nama PJ produksi & maintenance', $g['equipment']['pj_production'] !== null && $g['equipment']['pj_maintenance'] !== null);
check('WO detail menampilkan PJ equipment', call('GET', 'wo/get', ['id' => $wo], null, $T)['wo']['equipment']['pj_maintenance'] !== null);

$h = call('GET', 'equipment/history', ['id' => 1], null, $U);
$eh = array_values(array_filter($h['entries'], fn ($e) => $e['id'] === $wo))[0] ?? null;
check('Equipment History memuat WO equipment ini (terbaru dulu)', count($h['entries']) >= 2 && $h['entries'][0]['date'] >= $h['entries'][count($h['entries']) - 1]['date']);
check('entri history: teknisi, part terpakai, catatan penutupan, Equipment History SPV', $eh && in_array('Teknisi Andi', $eh['techs'], true) && $eh['parts'][0]['name'] === 'Bearing 6205' && (float) $eh['parts'][0]['qty'] === 2.0 && $eh['what_text'] === 'Ganti bearing', json_encode($eh));
check('history menandai PM tersedia (pm_ready=true)', $h['pm_ready'] === true);

$pu = call('GET', 'equipment/parts-used', ['id' => 1], null, $U);
check('sparepart terpakai: ringkasan per item dari MaintWare', count($pu['summary']) === 1 && $pu['summary'][0]['id'] === 'MW-1' && (float) $pu['summary'][0]['total'] === 2.0 && (int) $pu['summary'][0]['times'] === 1);
check('sparepart terpakai: detail tertaut ke WO', $pu['detail'][0]['wo_id'] === $wo && $pu['detail'][0]['source'] === 'WO');
check('tambah part bebas (non-MaintWare) ke WO', call('POST', 'wo/part', [], ['id' => $wo3, 'name' => 'Baut M8 bebas', 'qty' => 4], $T)['ok'] ?? false);
$free = call('GET', 'equipment/parts-used', ['id' => 1], null, $U)['free'];
check('part bebas WO ikut tercatat di sparepart terpakai', count($free) === 1 && $free[0]['name'] === 'Baut M8 bebas' && (float) $free[0]['total'] === 4.0);

$sp = call('POST', 'sparepart/save', [], ['equipment_id' => 1, 'name' => 'V-Belt cadangan', 'item_id' => 'MW-2', 'qty' => 2], $S);
check('sparepart standar ditautkan ke item MaintWare', $sp['ok'] ?? false);
$sps = call('GET', 'equipment/get', ['id' => 1], null, $U)['spareparts'];
check('detail sparepart memuat stok MaintWare', count($sps) === 1 && $sps[0]['item_name'] === 'V-Belt B52' && (float) $sps[0]['stock'] === 4.0);
check('tautan ke item yang tidak ada ditolak', call('POST', 'sparepart/save', [], ['equipment_id' => 1, 'name' => 'x', 'item_id' => 'NOPE-1'], $S)['_status'] === 400);
$mi = call('GET', 'mw/item', ['id' => 'MW-2'], null, $T);
check('item MaintWare menampilkan equipment yang memakainya (standar)', count($mi['equipment']) === 1 && (int) $mi['equipment'][0]['id'] === 1 && (float) $mi['equipment'][0]['standard_qty'] === 2.0);
$mi = call('GET', 'mw/item', ['id' => 'MW-1'], null, $T);
check('item MaintWare menampilkan pemakaian nyata per equipment', count($mi['equipment']) === 1 && (float) $mi['equipment'][0]['used_qty'] === 2.0);

echo "== Preventive Maintenance\n";
check('teknisi tidak boleh membuat tipe PM (403)', call('POST', 'pm/type/save', [], ['code' => 'x', 'label' => 'x'], $T)['_status'] === 403);
check('SPV membuat tipe PM', (call('POST', 'pm/type/save', [], ['code' => 'Belt Conveyor', 'label' => 'Belt Conveyor'], $S)['code'] ?? '') === 'belt_conveyor');
check('SPV mengisi template checklist (3 butir)', (call('POST', 'pm/template/save', [], ['pm_type' => 'belt_conveyor', 'items' => ['Bersihkan belt', 'Cek bearing', 'Cek motor', '  ']], $S)['items'] ?? 0) === 3);
Db::transaction([['UPDATE equipment SET pm_type = ? WHERE id = 2', ['belt_conveyor']]]);
check('equipment/save menolak tipe PM tak dikenal', call('POST', 'equipment/save', [], ['id' => 2, 'area_id' => 2, 'name' => 'Conveyor', 'pm_type' => 'nope', 'pj_production_id' => $pjProd, 'pj_maintenance_id' => $pjMtc], $S)['_status'] === 400);

$m2 = (int) date('n') - 2 < 1 ? 1 : (int) date('n') - 2;
$sc = call('POST', 'pm/schedule/save', [], ['equipment_id' => 2, 'periode' => 'Annual', 'month' => $m2, 'week' => 1, 'pic' => 'Andi', 'mp' => 2, 'hari' => 1], $S);
check('SPV membuat jadwal PM (SCH-001)', ($sc['id'] ?? '') === 'SCH-001', json_encode($sc));
check('teknisi tidak boleh membuat jadwal (403)', call('POST', 'pm/schedule/save', [], ['equipment_id' => 2, 'periode' => 'Annual'], $T)['_status'] === 403);
check('periode tidak valid ditolak', call('POST', 'pm/schedule/save', [], ['equipment_id' => 2, 'periode' => 'Hourly'], $S)['_status'] === 400);
check('user biasa tidak bisa lihat PM (403)', call('GET', 'pm/schedules', [], null, $U)['_status'] === 403);
$sl = call('GET', 'pm/schedules', [], null, $T);
check('jadwal belum dikerjakan & lewat bulan → overdue', $sl['rows'][0]['status'] === 'overdue' && $sl['counts']['overdue'] === 1, json_encode($sl['counts']));
check('filter status outstanding', count(call('GET', 'pm/schedules', ['status' => 'outstanding'], null, $T)['rows']) === 1 && count(call('GET', 'pm/schedules', ['status' => 'done'], null, $T)['rows']) === 0);

$tk = call('POST', 'pm/takeover', [], ['ids' => ['SCH-001'], 'to_pic' => 'Wawan', 'reason' => 'Andi cuti'], $T);
check('takeover PIC oleh teknisi', ($tk['updated'] ?? 0) === 1 && call('GET', 'pm/takeovers', [], null, $T)['rows'][0]['from_pic'] === 'Andi');
check('takeover tanpa alasan ditolak', call('POST', 'pm/takeover', [], ['ids' => ['SCH-001'], 'to_pic' => 'X', 'reason' => ''], $T)['_status'] === 400);

$f = call('GET', 'pm/form', ['schedule_id' => 'SCH-001'], null, $T);
check('form PM memuat checklist template & jadwal', $f['template'] === ['Bersihkan belt', 'Cek bearing', 'Cek motor'] && $f['schedule']['status'] === 'overdue');
$items = [['text' => 'Bersihkan belt', 'yes' => true], ['text' => 'Cek bearing', 'no' => true, 'note' => 'aus'], ['text' => 'Cek motor', 'yes' => true]];
$pm = fn (array $o = [], ?string $tok = null) => call('POST', 'pm/report/save', [], $o + ['equipment_id' => 2, 'schedule_id' => 'SCH-001', 'date' => date('Y-m-d'), 'periode' => 'Annual', 'teknisi' => ['Andi', 'Wawan'], 'approval' => 'Budi', 'items' => $items,
    'ppe' => ['helm' => true, 'sepatu' => true], 'parts' => [['item_id' => 'MW-2', 'qty' => 2], ['name' => 'Lem luar', 'qty' => 1]]], $tok ?? $T);
check('PM tanpa teknisi ditolak', $pm(['teknisi' => ''])['_status'] === 400);
check('PM tanggal masa depan ditolak', $pm(['date' => date('Y-m-d', strtotime('+3 days'))])['_status'] === 400);
check('PM tanpa jawaban checklist ditolak', $pm(['items' => [['text' => 'Cek']]])['_status'] === 400);
check('sparepart melebihi stok MaintWare ditolak (409), stok utuh', $pm(['parts' => [['item_id' => 'MW-2', 'qty' => 99]]])['_status'] === 409 && (float) Db::query("SELECT qty FROM mw_items WHERE id = 'MW-2'")[0]['qty'] === 4.0);
$r = $pm();
check('simpan laporan PM (ID PM/MLG/yyyymm/0001)', (bool) preg_match('~^PM/MLG/\d{6}/0001$~', $r['id'] ?? '') && $r['items_ok'] === 2 && $r['items_no'] === 1, json_encode($r));
$pid = $r['id'];
check('stok MaintWare berkurang karena sparepart PM (4 → 2)', (float) Db::query("SELECT qty FROM mw_items WHERE id = 'MW-2'")[0]['qty'] === 2.0);
$tx = Db::query("SELECT * FROM mw_transactions WHERE pm_id = ?", [$pid]);
check('transaksi OUT tertaut ke laporan PM & equipment', count($tx) === 1 && $tx[0]['source'] === 'PM' && (int) $tx[0]['equipment_id'] === 2);
$sl = call('GET', 'pm/schedules', [], null, $T);
check('jadwal berstatus done setelah PM & last_done terisi', $sl['rows'][0]['status'] === 'done' && $sl['rows'][0]['last_done'] === date('Y-m-d'));

$r2 = $pm(['id' => $pid, 'parts' => [['item_id' => 'MW-2', 'qty' => 1]], 'notes' => 'revisi']);
check('edit laporan: stok dikoreksi (2 → 3)', ($r2['id'] ?? '') === $pid && (float) Db::query("SELECT qty FROM mw_items WHERE id = 'MW-2'")[0]['qty'] === 3.0);
$d = call('GET', 'pm/report', ['id' => $pid], null, $T);
check('detail laporan: butir, sparepart, PPE', count($d['report']['items']) === 3 && count($d['report']['parts']) === 1 && $d['report']['ppe']['helm'] === true && $d['report']['notes'] === 'revisi');

check('teknisi tidak boleh tanda tangan (403)', call('POST', 'pm/sign', [], ['report_id' => $pid, 'role' => 'spv', 'signature' => 'data:image/png;base64,iVBORw0KGgo='], $T)['_status'] === 403);
check('tanda tangan non-PNG ditolak', call('POST', 'pm/sign', [], ['report_id' => $pid, 'role' => 'spv', 'signature' => 'data:text/html;base64,PHNjcmlwdD4='], $S)['_status'] === 400);
check('SPV menandatangani (SPV & Leader)', (call('POST', 'pm/sign', [], ['report_id' => $pid, 'role' => 'spv', 'signature' => 'data:image/png;base64,iVBORw0KGgo='], $S)['ok'] ?? false) && (call('POST', 'pm/sign', [], ['report_id' => $pid, 'role' => 'leader', 'signature' => 'data:image/png;base64,iVBORw0KGgo=', 'name' => 'Budi'], $S)['ok'] ?? false)
    && call('GET', 'pm/report', ['id' => $pid], null, $T)['report']['signature']['leader_name'] === 'Budi');

$w = call('POST', 'pm/wo', [], ['report_id' => $pid], $T);
$wd = call('GET', 'wo/get', ['id' => $w['wo_id'] ?? 'x'], null, $T);
check('WO dari butir "Tidak": source pm, Tasklist = butir bermasalah', ($w['ok'] ?? false) && $wd['wo']['source'] === 'pm' && $wd['wo']['source_ref'] === $pid && count($wd['tasks']) === 1 && $wd['tasks'][0]['text'] === 'Cek bearing');
check('WO ganda dari laporan yang sama ditolak (409)', call('POST', 'pm/wo', [], ['report_id' => $pid], $T)['_status'] === 409);

$rep = call('GET', 'pm/reports', ['equipment_id' => 2], null, $T);
check('Daftar laporan: filter equipment & hitung tanda tangan', $rep['total'] === 1 && $rep['rows'][0]['signed_spv'] === 1 && $rep['rows'][0]['signed_leader'] === 1 && $rep['rows'][0]['items_no'] === 1);
check('Daftar laporan: filter temuan', call('GET', 'pm/reports', ['findings' => 1], null, $T)['total'] === 1);
$dsh = call('GET', 'pm/dashboard', [], null, $S);
check('dashboard PM', $dsh['kpi']['total'] === 1 && $dsh['kpi']['done'] === 1 && $dsh['kpi']['compliance'] === 100 && $dsh['kpi']['reports_year'] === 1 && count($dsh['per_month']) === 12, json_encode($dsh['kpi']));
$rs = call('GET', 'pm/resume', [], null, $S);
check('resume PM', $rs['totals']['reports'] === 1 && (int) $rs['totals']['items'] === 3 && $rs['by_type'][0]['label'] === 'Belt Conveyor', json_encode($rs['totals']));
$cal = call('GET', 'pm/calendar', ['year' => (int) date('Y'), 'month' => (int) date('n')], null, $T);
check('kalender: laporan bulan ini tampil', count($cal['done']) === 1);
$calM = call('GET', 'pm/calendar', ['year' => (int) date('Y') + 1, 'month' => (int) date('n')], null, $T);
check('kalender: jadwal tahunan terproyeksi ke tahun depan', count($calM['planned']) === 1 && $calM['planned'][0]['date'] === date('Y-m-d', strtotime('+365 days')) || count($calM['planned']) <= 1);

$eh = call('GET', 'equipment/history', ['id' => 2], null, $T);
$pmE = array_values(array_filter($eh['entries'], fn ($e) => $e['kind'] === 'PM'));
check('Equipment History memuat entri PM (+ sparepart & tautan WO)', count($pmE) === 1 && $pmE[0]['id'] === $pid && $pmE[0]['wo_id'] === $w['wo_id'] && $pmE[0]['parts'][0]['name'] === 'V-Belt B52' && $eh['pm_ready'] === true);
$eg = call('GET', 'equipment/get', ['id' => 2], null, $T);
check('detail equipment memuat jadwal PM + statusnya', count($eg['pm']) === 1 && $eg['pm'][0]['status'] === 'done');
$pu = call('GET', 'equipment/parts-used', ['id' => 2], null, $T);
$pm2 = array_values(array_filter($pu['summary'], fn ($x) => $x['id'] === 'MW-2'));
check('sparepart terpakai equipment memuat pemakaian dari PM', count($pm2) === 1 && (float) $pm2[0]['total'] === 1.0, json_encode($pu['summary']));

check('hapus laporan: hanya SPV/Admin (teknisi 403)', call('POST', 'pm/report/delete', [], ['id' => $pid], $T)['_status'] === 403);
check('hapus laporan mengembalikan stok & last_done', (call('POST', 'pm/report/delete', [], ['id' => $pid], $S)['ok'] ?? false) && (float) Db::query("SELECT qty FROM mw_items WHERE id = 'MW-2'")[0]['qty'] === 4.0
    && call('GET', 'pm/schedules', [], null, $T)['rows'][0]['status'] === 'overdue');
check('hapus jadwal (SPV)', (call('POST', 'pm/schedule/delete', [], ['id' => 'SCH-001'], $S)['ok'] ?? false) && count(call('GET', 'pm/schedules', [], null, $T)['rows']) === 0);
check('tipe PM terpakai equipment tidak bisa dihapus (409)', call('POST', 'pm/type/delete', [], ['code' => 'belt_conveyor'], $A)['_status'] === 409);
echo "== Setting: user, teknisi, akun\n";
$ul = call('GET', 'users', [], null, $A);
check('admin melihat semua user', count($ul['rows']) >= 4);
$adminId = (int) array_values(array_filter($ul['rows'], fn ($u) => $u['username'] === 'admin'))[0]['id'];
check('admin tidak bisa menonaktifkan akun sendiri (409)', call('POST', 'users/save', [], ['id' => $adminId, 'username' => 'admin', 'name' => 'Administrator', 'role' => 'admin', 'active' => 0], $A)['_status'] === 409);
check('admin terakhir tidak bisa diturunkan role (409)', call('POST', 'users/save', [], ['id' => $adminId, 'username' => 'admin', 'name' => 'Administrator', 'role' => 'spv'], $A)['_status'] === 409);
check('SPV tidak bisa mengubah user (403)', call('POST', 'users/save', [], ['username' => 'baru123', 'name' => 'x', 'role' => 'user', 'password' => 'abcdef'], $S)['_status'] === 403);
$op = array_values(array_filter($ul['rows'], fn ($u) => $u['role'] === 'user'))[0];
$r = call('POST', 'users/save', [], ['id' => $op['id'], 'username' => $op['username'], 'name' => $op['name'], 'role' => 'user', 'active' => 0], $A);
check('nonaktifkan user mencabut token & mencegah login', ($r['ok'] ?? false) && call('GET', 'auth/me', [], null, $U)['_status'] === 401 && call('POST', 'auth/login', [], ['username' => $op['username'], 'password' => 'pass1234'])['_status'] === 403);
call('POST', 'users/save', [], ['id' => $op['id'], 'username' => $op['username'], 'name' => $op['name'], 'role' => 'user', 'active' => 1, 'password' => 'baru-9876'], $A);
$U = call('POST', 'auth/login', [], ['username' => $op['username'], 'password' => 'baru-9876'])['token'] ?? '';
check('reset password oleh admin → login dengan password baru', $U !== '');
$r = call('POST', 'auth/profile', [], ['name' => 'Sari W.', 'whatsapp' => '0812', 'department' => 'QC', 'jabatan' => 'Operator'], $U);
check('user mengubah profil sendiri (role tidak ikut berubah)', ($r['user']['name'] ?? '') === 'Sari W.' && $r['user']['role'] === 'user' && $r['user']['whatsapp'] === '0812');
check('profil tanpa nama ditolak', call('POST', 'auth/profile', [], ['name' => ''], $U)['_status'] === 400);
$T2 = call('POST', 'auth/login', [], ['username' => $op['username'], 'password' => 'baru-9876'])['token'];
check('keluar dari semua perangkat', (call('POST', 'auth/logout-all', [], [], $U)['ok'] ?? false) && call('GET', 'auth/me', [], null, $U)['_status'] === 401 && call('GET', 'auth/me', [], null, $T2)['_status'] === 401);
$U = call('POST', 'auth/login', [], ['username' => $op['username'], 'password' => 'baru-9876'])['token'];
check('daftar teknisi untuk SPV; user biasa ditolak', count(call('GET', 'technicians', [], null, $S)['rows']) >= 1 && call('GET', 'technicians', [], null, $U)['_status'] === 403);
$mu = call('POST', 'users/save', [], ['username' => 'tek-baru' . $suffix, 'name' => 'Teknisi Baru', 'role' => 'maintenance', 'password' => 'abcdef', 'unit_id' => 1], $A);
$tl = call('GET', 'technicians', [], null, $A)['rows'];
$nt = array_values(array_filter($tl, fn ($t) => (int) $t['user_id'] === (int) $mu['id']));
check('user Maintenance baru otomatis punya teknisi', count($nt) === 1);
$pic = call('POST', 'technicians/save', [], ['name' => 'PIC Lepas', 'spec' => 'Mekanik'], $S);
check('SPV menambah teknisi tanpa akun', $pic['ok'] ?? false);
check('satu akun tidak boleh terhubung ke dua teknisi (409)', call('POST', 'technicians/save', [], ['name' => 'PIC Lepas', 'user_id' => $mu['id']], $S)['_status'] === 409);
check('hubungkan teknisi ke akun lain', call('POST', 'technicians/save', [], ['id' => $pic['id'], 'name' => 'PIC Lepas', 'user_id' => $ul['rows'][0]['id'] == $mu['id'] ? 0 : $adminId], $A)['ok'] ?? false);
check('teknisi bertugas di WO tidak bisa dihapus (409)', call('POST', 'technicians/delete', [], ['id' => $techId], $A)['_status'] === 409);
check('teknisi baru yang belum dipakai bisa dihapus admin; SPV tidak (403)', call('POST', 'technicians/delete', [], ['id' => $pic['id']], $S)['_status'] === 403 && (call('POST', 'technicians/delete', [], ['id' => $pic['id']], $A)['ok'] ?? false));

// ── Pusat laporan, pencarian global, notifikasi ──
$rep = call('GET', 'report/build', ['modules' => 'wo,mw,pm,dm', 'from' => date('Y-01-01'), 'to' => date('Y-m-d')], null, $A);
check('report: 4 modul sekaligus', ($rep['ok'] ?? false) && array_column($rep['sections'], 'key') === ['wo', 'mw', 'pm', 'dm']);
$woSec = $rep['sections'][0] ?? [];
$woTotal = (int) ($woSec['kpis'][0][1] ?? -1);
$woList = array_values(array_filter($woSec['tables'] ?? [], fn ($t) => $t['name'] === 'Daftar WO'))[0] ?? [];
check('report: KPI WO sama dengan jumlah baris Daftar WO', $woTotal > 0 && $woTotal === (int) ($woList['total'] ?? -2));
check('report: satu modul saja', count(call('GET', 'report/build', ['modules' => 'mw', 'from' => date('Y-01-01'), 'to' => date('Y-m-d')], null, $A)['sections'] ?? []) === 1);
check('report: kombinasi dua modul', array_column(call('GET', 'report/build', ['modules' => 'pm,dm', 'from' => date('Y-01-01'), 'to' => date('Y-m-d')], null, $A)['sections'] ?? [], 'key') === ['pm', 'dm']);
check('report: tanpa modul ditolak (400)', call('GET', 'report/build', ['modules' => '', 'from' => '2026-01-01', 'to' => '2026-12-31'], null, $A)['_status'] === 400);
check('report: tanggal terbalik ditolak (400)', call('GET', 'report/build', ['modules' => 'wo', 'from' => '2026-12-31', 'to' => '2026-01-01'], null, $A)['_status'] === 400);
check('report: user biasa ditolak (403)', call('GET', 'report/build', ['modules' => 'wo', 'from' => '2026-01-01', 'to' => '2026-12-31'], null, $U)['_status'] === 403);
check('report: filter unit menyempit', (int) (call('GET', 'report/build', ['modules' => 'wo', 'from' => date('Y-01-01'), 'to' => date('Y-m-d'), 'unit_id' => 999999], null, $A)['sections'][0]['kpis'][0][1] ?? -1) === 0);
$srch = call('GET', 'search', ['q' => substr((string) ($woList['rows'][0][1] ?? 'WO'), 0, 4)], null, $A);
check('search: menemukan WO berdasar judul', in_array('wo', array_column($srch['groups'] ?? [], 'key'), true));
check('search: kurang dari 2 huruf kosong', (call('GET', 'search', ['q' => 'a'], null, $A)['groups'] ?? [1]) === []);
$srU = call('GET', 'search', ['q' => 'a%'], null, $U);
check('search: user biasa tidak melihat MaintWare/PM', !array_intersect(['mw', 'pm'], array_column($srU['groups'] ?? [], 'key')));
$nt = call('GET', 'notifications', [], null, $A);
check('notifikasi: staf menerima ringkasan', ($nt['ok'] ?? false) && isset($nt['total']) && $nt['total'] === array_sum(array_column($nt['items'], 'count')));
check('notifikasi: user biasa hanya WO miliknya', !array_filter(call('GET', 'notifications', [], null, $U)['items'] ?? [], fn ($i) => str_contains($i['href'], 'mw/') || str_contains($i['href'], 'pm/')));

echo "\n$pass lulus, $fail gagal\n";
foreach (['', '-wal', '-shm'] as $s) {
    @unlink($dbFile . $s);
}
exit($fail ? 1 : 0);
