<?php
declare(strict_types=1);
/**
 * Impor Equipment Database dari MySQL XAMPP (equipment_db: units, areas, equipment, spareparts)
 * ke database CMMS (SQLite/Turso). Idempoten: ID asli dipertahankan, baris yang sudah ada di-update.
 *   php scripts/import-equipment.php [--env=.env.turso] [--mysql=equipment_db]
 */
require __DIR__ . '/_cli.php';

Migrator::run();
$dbName = 'equipment_db';
foreach ($argv as $a) {
    if (str_starts_with($a, '--mysql=')) {
        $dbName = substr($a, 8);
    }
}
$my = new PDO('mysql:host=127.0.0.1;dbname=' . $dbName . ';charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

// Nama unit sumber → nama & kode di CMMS
$unitMap = ['Unit - 1' => ['P1', 'Pasuruan 1'], 'Unit - 2' => ['P2', 'Pasuruan 2'], 'Unit Malang' => ['MLG', 'Malang']];

$st = [];
foreach ($my->query('SELECT id, name FROM units') as $u) {
    [$code, $name] = $unitMap[$u['name']] ?? ['U' . $u['id'], $u['name']];
    $st[] = ['INSERT INTO units (id, code, name) VALUES (?,?,?) ON CONFLICT(id) DO UPDATE SET code=excluded.code, name=excluded.name', [(int) $u['id'], $code, $name]];
}
foreach ($my->query('SELECT id, unit_id, name FROM areas') as $a) {
    $st[] = ['INSERT INTO areas (id, unit_id, name) VALUES (?,?,?) ON CONFLICT(id) DO UPDATE SET unit_id=excluded.unit_id, name=excluded.name', [(int) $a['id'], (int) $a['unit_id'], $a['name']]];
}
Db::transaction($st);

$n = 0;
$batch = [];
foreach ($my->query('SELECT id, area_id, name FROM equipment') as $e) {
    $batch[] = ['INSERT INTO equipment (id, area_id, name) VALUES (?,?,?) ON CONFLICT(id) DO UPDATE SET area_id=excluded.area_id, name=excluded.name', [(int) $e['id'], (int) $e['area_id'], $e['name']]];
    if (count($batch) >= 200) {
        Db::transaction($batch);
        $n += count($batch);
        $batch = [];
    }
}
Db::transaction($batch);
$n += count($batch);

$batch = [];
$s = 0;
foreach ($my->query('SELECT id, equipment_id, name, specification, price, qty FROM spareparts') as $p) {
    $batch[] = ['INSERT INTO equipment_spareparts (id, equipment_id, name, specification, price, qty) VALUES (?,?,?,?,?,?)
                 ON CONFLICT(id) DO UPDATE SET name=excluded.name, specification=excluded.specification, price=excluded.price, qty=excluded.qty',
        [(int) $p['id'], (int) $p['equipment_id'], $p['name'], $p['specification'], $p['price'] !== null ? (float) $p['price'] : null, $p['qty'] !== null ? (float) $p['qty'] : null]];
    if (count($batch) >= 200) {
        Db::transaction($batch);
        $s += count($batch);
        $batch = [];
    }
}
Db::transaction($batch);
$s += count($batch);

echo "Impor selesai ({$n} equipment, {$s} sparepart).\n";
foreach (['units', 'areas', 'equipment', 'equipment_spareparts'] as $t) {
    echo str_pad($t, 22) . Db::query("SELECT COUNT(*) c FROM $t")[0]['c'] . "\n";
}
