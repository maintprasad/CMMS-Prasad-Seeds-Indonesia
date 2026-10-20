<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__, 2));

if (PHP_SAPI !== 'cli') {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
}

require __DIR__ . '/Env.php';
require __DIR__ . '/Http.php';
require __DIR__ . '/Database.php';
require __DIR__ . '/SqliteDriver.php';
require __DIR__ . '/TursoDriver.php';
require __DIR__ . '/Migrator.php';
require __DIR__ . '/Auth.php';

// Skrip CLI bisa memilih file env lain lewat konstanta APP_ENV_FILE (file yang dimuat lebih dulu menang).
if (defined('APP_ENV_FILE')) {
    Env::load(APP_ENV_FILE);
}
Env::load(APP_ROOT . '/.env');
date_default_timezone_set(Env::get('APP_TIMEZONE', 'Asia/Jakarta'));

/** Waktu lokal untuk disimpan di database: "YYYY-MM-DD HH:MM:SS". */
function now(): string
{
    return date('Y-m-d H:i:s');
}

foreach (glob(__DIR__ . '/../modules/*.php') ?: [] as $module) {
    require $module;
}
