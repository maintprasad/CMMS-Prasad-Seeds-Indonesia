<?php
declare(strict_types=1);

/** Driver file SQLite lokal via PDO — dipakai saat testing di XAMPP. */
final class SqliteDriver implements DbDriver
{
    private PDO $pdo;

    public function __construct(private readonly string $path)
    {
        if (!extension_loaded('pdo_sqlite')) {
            throw new RuntimeException('Ekstensi pdo_sqlite belum aktif di php.ini');
        }
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Folder database tidak bisa dibuat: $dir");
        }
        $this->pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->pdo->exec('PRAGMA busy_timeout = 5000');
        $this->pdo->exec('PRAGMA journal_mode = WAL');
        $this->pdo->exec('PRAGMA synchronous = NORMAL');
    }

    public function name(): string
    {
        return 'sqlite';
    }

    public function queryMany(array $statements): array
    {
        $results = [];
        foreach ($statements as $stmt) {
            $results[] = $this->run($stmt[0], $stmt[1] ?? [])->fetchAll();
        }
        return $results;
    }

    public function transaction(array $statements): void
    {
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            foreach ($statements as $stmt) {
                $this->run($stmt[0], $stmt[1] ?? []);
            }
            $this->pdo->exec('COMMIT');
        } catch (Throwable $e) {
            $this->pdo->exec('ROLLBACK');
            throw $e;
        }
    }

    private function run(string $sql, array $params): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        foreach (array_values($params) as $i => $value) {
            $type = match (true) {
                $value === null  => PDO::PARAM_NULL,
                is_int($value)   => PDO::PARAM_INT,
                is_bool($value)  => PDO::PARAM_INT,
                default          => PDO::PARAM_STR,
            };
            $stmt->bindValue($i + 1, is_bool($value) ? (int) $value : $value, $type);
        }
        $stmt->execute();
        return $stmt;
    }
}
