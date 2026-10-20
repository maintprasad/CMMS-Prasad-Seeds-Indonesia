<?php
declare(strict_types=1);

/**
 * Pembaca konfigurasi dari environment variable.
 * - Lokal (XAMPP): dibaca dari file .env di root project.
 * - Vercel: diset lewat Project Settings → Environment Variables.
 * Nilai yang sudah ada di environment tidak ditimpa oleh file .env.
 */
final class Env
{
    private static array $values = [];

    public static function load(string $file): void
    {
        if (!is_file($file) || !is_readable($file)) {
            return;
        }
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = array_map('trim', explode('=', $line, 2));
            if ($key === '') {
                continue;
            }
            $len = strlen($value);
            if ($len >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[$len - 1] === $value[0]) {
                $value = substr($value, 1, -1);
            }
            // File yang dimuat lebih dulu menang (mis. --env=.env.turso sebelum .env)
            if (self::fromProcess($key) === null && !array_key_exists($key, self::$values)) {
                self::$values[$key] = $value;
            }
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $value = self::fromProcess($key) ?? (self::$values[$key] ?? null);
        return ($value === null || $value === '') ? $default : $value;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);
        if ($value === null) {
            return $default;
        }
        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Kredensial Turso. Integrasi Turso di Vercel Marketplace bisa memberi prefix kustom
     * (mis. STORAGE_TURSO_DATABASE_URL) — nama tanpa prefix tetap diutamakan.
     * @return array{0:?string,1:string} [url, token]
     */
    public static function turso(): array
    {
        $url = self::get('TURSO_DATABASE_URL');
        if ($url !== null) {
            return [$url, (string) self::get('TURSO_AUTH_TOKEN', '')];
        }
        $all = array_merge(self::$values, $_SERVER, $_ENV, getenv() ?: []);
        foreach ($all as $key => $value) {
            if (is_string($key) && is_string($value) && $value !== ''
                && preg_match('~^(.+_)TURSO_DATABASE_URL$~', $key, $m)) {
                return [$value, (string) self::get($m[1] . 'TURSO_AUTH_TOKEN', '')];
            }
        }
        return [null, ''];
    }

    private static function fromProcess(string $key): ?string
    {
        $value = getenv($key);
        if ($value !== false && $value !== '') {
            return $value;
        }
        return $_ENV[$key] ?? $_SERVER[$key] ?? null;
    }
}
