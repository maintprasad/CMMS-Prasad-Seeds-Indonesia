<?php
declare(strict_types=1);

/**
 * Migrasi skema bertahap: setiap file api/migrations/NNN_nama.sql dijalankan sekali
 * (urut nama) dan dicatat di tabel schema_migrations. Aman dijalankan ulang.
 */
final class Migrator
{
    /** @return string[] path file migrasi, urut */
    private static function files(): array
    {
        $files = glob(APP_ROOT . '/api/migrations/*.sql') ?: [];
        sort($files);
        return $files;
    }

    /** @return string[] versi (nama file tanpa .sql) yang sudah terpasang */
    private static function applied(): array
    {
        try {
            return array_column(Db::driver()->queryMany([['SELECT version FROM schema_migrations']])[0], 'version');
        } catch (Throwable) {
            return [];
        }
    }

    public static function hasPending(): bool
    {
        $done = self::applied();
        foreach (self::files() as $f) {
            if (!in_array(basename($f, '.sql'), $done, true)) {
                return true;
            }
        }
        return false;
    }

    /** @return string[] versi yang baru dipasang */
    public static function run(): array
    {
        Db::driver()->transaction([[
            'CREATE TABLE IF NOT EXISTS schema_migrations (version TEXT PRIMARY KEY, applied_at TEXT NOT NULL)',
        ]]);
        $done = self::applied();
        $ran = [];
        foreach (self::files() as $file) {
            $version = basename($file, '.sql');
            if (in_array($version, $done, true)) {
                continue;
            }
            // buang komentar "-- ..." (termasuk di akhir baris, yang bisa berisi titik koma)
            $sql = preg_replace('~--[^\r\n]*~', '', (string) file_get_contents($file));
            $statements = [];
            foreach (explode(';', (string) $sql) as $part) {
                if (trim($part) !== '') {
                    $statements[] = [trim($part)];
                }
            }
            $statements[] = ['INSERT INTO schema_migrations (version, applied_at) VALUES (?, ?)', [$version, date('c')]];
            Db::driver()->transaction($statements);
            $ran[] = $version;
        }
        return $ran;
    }
}
