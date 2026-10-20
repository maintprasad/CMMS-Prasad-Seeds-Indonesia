<?php
declare(strict_types=1);
// Cek koneksi database aktif: php scripts/db-check.php [--env=.env.turso]
require __DIR__ . '/_cli.php';

try {
    $t = microtime(true);
    Db::query('SELECT 1');
    $ms = round((microtime(true) - $t) * 1000);
    echo 'Driver   : ' . Db::driverName() . "\nKoneksi  : OK ({$ms} ms)\n";
    $tables = Db::driverName() === 'mysql'
        ? array_column(Db::query('SELECT table_name AS name FROM information_schema.tables WHERE table_schema = DATABASE() ORDER BY table_name'), 'name')
        : array_column(Db::query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name"), 'name');
    echo 'Tabel    : ' . count($tables) . "\n";
    foreach (['units', 'equipment', 'users', 'work_orders', 'mw_items', 'pm_reports', 'dm_sessions'] as $x) {
        if (in_array($x, $tables, true)) {
            printf("  %-14s %d\n", $x, Db::query("SELECT COUNT(*) c FROM $x")[0]['c']);
        }
    }
    $v = array_column(Db::query('SELECT version FROM schema_migrations ORDER BY version'), 'version');
    echo 'Migrasi  : ' . ($v ? implode(', ', $v) : '(belum ada)') . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'GAGAL: ' . $e->getMessage() . "\n");
    exit(1);
}
