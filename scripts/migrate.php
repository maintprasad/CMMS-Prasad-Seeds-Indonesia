<?php
declare(strict_types=1);
require __DIR__ . '/_cli.php';

$ran = Migrator::run();
Auth::seedAdmin();
echo 'Driver: ' . Db::driverName() . "\n";
echo $ran ? 'Migrasi dipasang: ' . implode(', ', $ran) . "\n" : "Skema sudah terbaru.\n";
