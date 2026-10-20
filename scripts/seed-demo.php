<?php
declare(strict_types=1);
/**
 * Data contoh untuk pengembangan/demo (user per role, teknisi, item MaintWare per unit).
 * JANGAN dijalankan di production: password semua akun contoh = "demo1234".
 *   php scripts/seed-demo.php
 */
require __DIR__ . '/_cli.php';

Migrator::run();
Auth::seedAdmin();
$units = array_column(Db::query('SELECT id, code FROM units'), 'id', 'code');
if (!$units) {
    exit("Impor Equipment Database dulu: php scripts/import-equipment.php\n");
}
$p1 = $units['P1'] ?? array_values($units)[0];
$hash = password_hash('demo1234', PASSWORD_DEFAULT);
$people = [
    ['leader.budi', 'Budi Santoso', 'spv', 'Leader Maintenance'],
    ['tek.andi', 'Andi Prasetyo', 'maintenance', 'Teknisi Mekanik'],
    ['tek.wawan', 'Wawan Hidayat', 'maintenance', 'Teknisi Listrik'],
    ['op.sari', 'Sari Wulandari', 'user', 'Operator Produksi'],
];
foreach ($people as [$u, $n, $r, $j]) {
    if (!Db::query('SELECT 1 FROM users WHERE username = ?', [$u])) {
        Db::transaction([['INSERT INTO users (username, name, password_hash, role, unit_id, department, jabatan) VALUES (?,?,?,?,?,?,?)',
            [$u, $n, $hash, $r, $p1, $r === 'user' ? 'Produksi' : 'Maintenance', $j]]]);
    }
    if ($r === 'maintenance') {
        $id = (int) Db::query('SELECT id FROM users WHERE username = ?', [$u])[0]['id'];
        if (!Db::query('SELECT 1 FROM technicians WHERE user_id = ?', [$id])) {
            Db::transaction([['INSERT INTO technicians (user_id, name, spec, shift) VALUES (?,?,?,?)', [$id, $n, $j, 'Pagi']]]);
        }
    }
}
$items = [['Bearing 6205 2RS', 'SKF', 'pcs'], ['Bearing 6308', 'SKF', 'pcs'], ['V-Belt B52', 'Gates', 'pcs'], ['V-Belt A38', 'Gates', 'pcs'],
    ['Oli Gearbox ISO VG 220', 'Pertamina', 'liter'], ['Grease EP2', 'Shell', 'kg'], ['Kontaktor 3P 25A', 'Schneider', 'pcs'],
    ['Overload relay 9-13A', 'Schneider', 'pcs'], ['Baut M12x60', 'Galvanis', 'pcs'], ['Seal Oil 45x62x8', 'NOK', 'pcs']];
$n = 0;
foreach ($units as $code => $uid) {
    foreach ($items as $i => [$name, $brand, $uom]) {
        $id = "DEMO-$code-" . str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT);
        Db::transaction([['INSERT INTO mw_items (id, name, spec, category, uom, unit_id, qty, min_stock, notes) VALUES (?,?,?,?,?,?,?,?,?)
            ON CONFLICT(id) DO NOTHING', [$id, $name, $brand, 'Sparepart', $uom, $uid, 10 + $i * 3, 5, 'data contoh']]]);
        $n++;
    }
}
echo "Seed demo selesai: " . count($people) . " akun (password demo1234), $n item MaintWare contoh.\n";
