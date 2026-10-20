<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}
// Opsi: --env=<file> memilih file env lain (mis. .env.turso)
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--env=')) {
        $f = substr($arg, 6);
        define('APP_ENV_FILE', preg_match('~^([a-zA-Z]:)?[\\/]~', $f) ? $f : dirname(__DIR__) . '/' . $f);
    }
}
require dirname(__DIR__) . '/api/_lib/bootstrap.php';

function cliFlag(string $name): bool
{
    return in_array("--$name", $GLOBALS['argv'], true);
}
