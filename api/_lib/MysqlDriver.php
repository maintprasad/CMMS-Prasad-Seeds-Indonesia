<?php
declare(strict_types=1);

/**
 * Driver MySQL / MariaDB (PDO) untuk server sendiri.
 * Aplikasi ditulis dengan SQL ala SQLite; driver ini menerjemahkan beberapa idiom saat dijalankan
 * (ON CONFLICT → ON DUPLICATE KEY, INSERT OR IGNORE, GROUP_CONCAT(x, 'sep'), ESCAPE '\') dan
 * memakai sql_mode ANSI_QUOTES + PIPES_AS_CONCAT supaya "kolom" dan a || b berperilaku sama.
 */
final class MysqlDriver implements DbDriver
{
    private ?PDO $pdo = null;
    /** @var array<string,string> */
    private static array $cache = [];

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $database,
        private readonly string $user,
        private readonly string $password,
        private readonly string $socket = '',
    ) {
        if (!extension_loaded('pdo_mysql')) {
            throw new RuntimeException('Ekstensi pdo_mysql belum aktif di php.ini');
        }
    }

    public function name(): string
    {
        return 'mysql';
    }

    private function pdo(): PDO
    {
        if ($this->pdo === null) {
            $dsn = $this->socket !== ''
                ? "mysql:unix_socket={$this->socket};dbname={$this->database};charset=utf8mb4"
                : "mysql:host={$this->host};port={$this->port};dbname={$this->database};charset=utf8mb4";
            $this->pdo = new PDO($dsn, $this->user, $this->password, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_TIMEOUT            => 10,
            ]);
            $this->pdo->exec("SET SESSION sql_mode = 'ANSI_QUOTES,PIPES_AS_CONCAT,STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'");
            $this->pdo->exec("SET SESSION time_zone = '" . date('P') . "'");
        }
        return $this->pdo;
    }

    public function queryMany(array $statements): array
    {
        $results = [];
        foreach ($statements as $stmt) {
            $results[] = $this->withRetry(fn () => $this->run($stmt[0], $stmt[1] ?? [], true));
        }
        return $results;
    }

    public function transaction(array $statements): void
    {
        $this->withRetry(function () use ($statements) {
            $pdo = $this->pdo();
            $pdo->beginTransaction();
            try {
                foreach ($statements as $stmt) {
                    $this->run($stmt[0], $stmt[1] ?? [], false);
                }
                if ($pdo->inTransaction()) { // DDL meng-commit sendiri
                    $pdo->commit();
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
        });
    }

    /** Sambungan putus ("server has gone away") → sambung ulang sekali. */
    private function withRetry(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (PDOException $e) {
            $code = (int) ($e->errorInfo[1] ?? 0);
            if (in_array($code, [2006, 2013], true)) {
                $this->pdo = null;
                return $fn();
            }
            throw $e;
        }
    }

    private function run(string $sql, array $params, bool $fetch): array
    {
        $stmt = $this->pdo()->prepare(self::translate($sql));
        foreach (array_values($params) as $i => $value) {
            $type = match (true) {
                $value === null => PDO::PARAM_NULL,
                is_int($value), is_bool($value) => PDO::PARAM_INT,
                default => PDO::PARAM_STR,
            };
            $stmt->bindValue($i + 1, is_bool($value) ? (int) $value : $value, $type);
        }
        $stmt->execute();
        if (!$fetch || $stmt->columnCount() === 0) {
            return [];
        }
        $rows = $stmt->fetchAll();
        if ($rows) {
            // SUM/AVG atas integer menghasilkan DECIMAL (string di PHP) — kembalikan ke angka seperti SQLite
            foreach (range(0, $stmt->columnCount() - 1) as $i) {
                $meta = $stmt->getColumnMeta($i);
                if (in_array($meta['native_type'] ?? '', ['NEWDECIMAL', 'DECIMAL'], true)) {
                    $col = $meta['name'];
                    foreach ($rows as &$r) {
                        if ($r[$col] !== null) {
                            $r[$col] = str_contains((string) $r[$col], '.') ? (float) $r[$col] : (int) $r[$col];
                        }
                    }
                    unset($r);
                }
            }
        }
        return $rows;
    }

    /** SQL ala SQLite → MySQL/MariaDB. */
    public static function translate(string $sql): string
    {
        if (isset(self::$cache[$sql])) {
            return self::$cache[$sql];
        }
        $out = $sql;
        $out = preg_replace('~\bINSERT\s+OR\s+IGNORE\b~i', 'INSERT IGNORE', $out);
        $out = preg_replace('~\bINSERT\s+OR\s+REPLACE\b~i', 'REPLACE', $out);
        // ON CONFLICT(kolom) DO NOTHING → INSERT IGNORE
        if (preg_match('~\bON\s+CONFLICT\s*\([^)]*\)\s*DO\s+NOTHING\b~i', $out)) {
            $out = preg_replace('~\bON\s+CONFLICT\s*\([^)]*\)\s*DO\s+NOTHING\b~i', '', $out);
            $out = preg_replace('~^\s*INSERT\s+INTO\b~i', 'INSERT IGNORE INTO', $out);
        }
        // ON CONFLICT(kolom) DO UPDATE SET a = excluded.a → ON DUPLICATE KEY UPDATE a = VALUES(a)
        if (preg_match('~\bON\s+CONFLICT\s*\([^)]*\)\s*DO\s+UPDATE\s+SET\b~i', $out)) {
            $out = preg_replace('~\bON\s+CONFLICT\s*\([^)]*\)\s*DO\s+UPDATE\s+SET\b~i', 'ON DUPLICATE KEY UPDATE', $out);
            $out = preg_replace('~\bexcluded\.("?)(\w+)\1~i', 'VALUES("$2")', $out);
        }
        // GROUP_CONCAT(x, ', ') → GROUP_CONCAT(x SEPARATOR ', ')
        $out = preg_replace('~GROUP_CONCAT\(\s*([^()]+?)\s*,\s*(\'[^\']*\')\s*\)~i', 'GROUP_CONCAT($1 SEPARATOR $2)', $out);
        // LIKE ... ESCAPE '\' : backslash sudah menjadi escape bawaan MySQL
        $out = preg_replace("~\\s+ESCAPE\\s+'\\\\'~i", '', $out);
        return self::$cache[$sql] = $out;
    }
}
