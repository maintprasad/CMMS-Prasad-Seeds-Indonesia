<?php
declare(strict_types=1);

/**
 * Kontrak driver database. Statement ditulis sebagai [sql, params] dengan placeholder "?".
 * Semua penulisan dilakukan lewat transaction() supaya bisa dikirim sebagai satu batch
 * atomik — penting untuk Turso yang diakses lewat HTTP (stateless).
 */
interface DbDriver
{
    public function name(): string;

    /**
     * Jalankan beberapa query baca berurutan (satu round-trip untuk Turso).
     * @param array<int, array{0:string,1?:array}> $statements
     * @return array<int, array<int, array<string, mixed>>> baris per statement
     */
    public function queryMany(array $statements): array;

    /**
     * Jalankan statement secara atomik (semua sukses atau semua dibatalkan).
     * @param array<int, array{0:string,1?:array}> $statements
     */
    public function transaction(array $statements): void;
}

final class Db
{
    private static ?DbDriver $driver = null;

    public static function driver(): DbDriver
    {
        if (self::$driver === null) {
            self::$driver = self::create();
        }
        return self::$driver;
    }

    public static function driverName(): string
    {
        $explicit = strtolower((string) Env::get('DB_DRIVER', ''));
        if ($explicit !== '') {
            return $explicit;
        }
        return Env::turso()[0] !== null ? 'turso' : 'sqlite';
    }

    private static function create(): DbDriver
    {
        $name = self::driverName();
        if ($name === 'turso') {
            [$url, $token] = Env::turso();
            if (!$url) {
                throw new RuntimeException('TURSO_DATABASE_URL belum diset');
            }
            return new TursoDriver($url, $token);
        }
        if ($name !== 'sqlite') {
            throw new RuntimeException("DB_DRIVER tidak dikenal: $name (pakai sqlite atau turso)");
        }
        $path = Env::get('SQLITE_PATH', 'database/cmms.db');
        if (!preg_match('~^([a-zA-Z]:)?[\\\\/]~', $path)) {
            $path = APP_ROOT . '/' . $path;
        }
        return new SqliteDriver($path);
    }

    /** @return array<int, array<string, mixed>> */
    public static function query(string $sql, array $params = []): array
    {
        return self::queryMany([[$sql, $params]])[0];
    }

    public static function queryMany(array $statements): array
    {
        return self::withSchema(fn () => self::driver()->queryMany($statements));
    }

    public static function transaction(array $statements): void
    {
        if (!$statements) {
            return;
        }
        self::withSchema(fn () => self::driver()->transaction($statements));
    }

    /**
     * Database baru (file SQLite kosong / database Turso baru) otomatis dibuatkan
     * tabelnya saat query pertama gagal karena tabel belum ada.
     */
    private static function withSchema(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (Throwable $e) {
            $msg = strtolower($e->getMessage());
            // Tabel/kolom belum ada = database kosong atau masih skema versi lama
            if (!str_contains($msg, 'no such table') && !str_contains($msg, 'no such column')) {
                throw $e;
            }
            if (!Migrator::hasPending()) {
                throw $e; // skema sudah terbaru → ini memang error query
            }
            Migrator::run();
            return $fn();
        }
    }

    /**
     * INSERT multi-baris, dipecah per $chunk baris agar tidak melewati batas variabel SQLite.
     * @param array<int, array<int, mixed>> $rows
     * @param array<string, string> $rawColumns kolom yang nilainya ekspresi SQL (bukan parameter)
     * @return array<int, array{0:string,1:array}>
     */
    public static function insertRows(string $table, array $columns, array $rows, array $rawColumns = [], int $chunk = 40): array
    {
        $statements = [];
        $allColumns = array_merge($columns, array_keys($rawColumns));
        $placeholder = '(' . implode(',', array_merge(
            array_fill(0, count($columns), '?'),
            array_values($rawColumns)
        )) . ')';
        foreach (array_chunk($rows, $chunk) as $part) {
            $params = [];
            foreach ($part as $row) {
                array_push($params, ...$row);
            }
            $statements[] = [
                "INSERT INTO $table (" . implode(',', $allColumns) . ') VALUES '
                    . implode(',', array_fill(0, count($part), $placeholder)),
                $params,
            ];
        }
        return $statements;
    }
}
