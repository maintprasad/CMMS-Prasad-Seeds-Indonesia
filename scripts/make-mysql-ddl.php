<?php
declare(strict_types=1);

/**
 * Membuat draf migrasi MySQL/MariaDB dari migrasi SQLite (api/migrations/*.sql → api/migrations/mysql/*.sql).
 *
 *   php scripts/make-mysql-ddl.php            # semua file
 *   php scripts/make-mysql-ddl.php 007_x.sql  # hanya file tertentu (kolom terindeks tetap dihitung dari semua file)
 *
 * Aturan konversi:
 *  - INTEGER → BIGINT; INTEGER PRIMARY KEY (± AUTOINCREMENT) → BIGINT AUTO_INCREMENT PRIMARY KEY; REAL → DOUBLE
 *  - TEXT yang menjadi kunci/indeks/UNIQUE → VARCHAR(190); TEXT ber-DEFAULT → VARCHAR(1000); TEXT DEFAULT CURRENT_TIMESTAMP → DATETIME;
 *    TEXT lain → LONGTEXT (foto/tanda tangan base64 tersimpan di database)
 *  - REFERENCES / FOREIGN KEY dibuang (SQLite lokal pun tidak menegakkannya)
 * Setelah dibuat, periksa hasilnya sebelum dipakai; migrasi baru sebaiknya ditulis untuk kedua dialek.
 */
$dir = dirname(__DIR__) . '/api/migrations';
$only = $argv[1] ?? null;

$clean = fn (string $sql): string => preg_replace('~--[^\r\n]*~', '', $sql);
$statementsOf = function (string $file) use ($clean): array {
    $out = [];
    foreach (explode(';', $clean((string) file_get_contents($file))) as $p) {
        if (trim($p) !== '') {
            $out[] = trim($p);
        }
    }
    return $out;
};
$files = glob($dir . '/*.sql');
sort($files);

// pass 1: kolom yang dipakai sebagai kunci / indeks / UNIQUE → harus VARCHAR
$keyCols = []; // "tabel.kolom" => true
$splitTop = function (string $s): array {
    $parts = [];
    $depth = 0;
    $cur = '';
    $q = '';
    for ($i = 0, $n = strlen($s); $i < $n; $i++) {
        $c = $s[$i];
        if ($q !== '') {
            $cur .= $c;
            if ($c === $q) {
                $q = '';
            }
            continue;
        }
        if ($c === "'" || $c === '"') {
            $q = $c;
        } elseif ($c === '(') {
            $depth++;
        } elseif ($c === ')') {
            $depth--;
        } elseif ($c === ',' && $depth === 0) {
            $parts[] = trim($cur);
            $cur = '';
            continue;
        }
        $cur .= $c;
    }
    if (trim($cur) !== '') {
        $parts[] = trim($cur);
    }
    return $parts;
};
foreach ($files as $f) {
    foreach ($statementsOf($f) as $st) {
        if (preg_match('~^CREATE\s+(?:UNIQUE\s+)?INDEX\s+\S+\s+ON\s+(\w+)\s*\((.+)\)$~is', $st, $m)) {
            foreach (explode(',', $m[2]) as $c) {
                $keyCols[$m[1] . '.' . strtolower(trim(preg_replace('~\s+(ASC|DESC)$~i', '', trim($c))))] = true;
            }
        } elseif (preg_match('~^CREATE\s+TABLE\s+(\w+)\s*\((.*)\)$~is', $st, $m)) {
            foreach ($splitTop($m[2]) as $item) {
                if (preg_match('~^(?:CONSTRAINT\s+\w+\s+)?(?:PRIMARY\s+KEY|UNIQUE)\s*\(([^)]*)\)~i', $item, $k)) {
                    foreach (explode(',', $k[1]) as $c) {
                        $keyCols[$m[1] . '.' . strtolower(trim($c))] = true;
                    }
                } elseif (preg_match('~^(\w+)\s+\w+.*\b(PRIMARY\s+KEY|UNIQUE)\b~is', $item, $k)) {
                    $keyCols[$m[1] . '.' . strtolower($k[1])] = true;
                }
            }
        }
    }
}

$mapType = function (string $table, string $col, string $type, string $rest) use ($keyCols): array {
    $t = strtoupper($type);
    $isKey = isset($keyCols["$table." . strtolower($col)]);
    $hasDefault = (bool) preg_match('~\bDEFAULT\b~i', $rest);
    if ($t === 'INTEGER' || $t === 'INT') {
        return ['BIGINT', $rest];
    }
    if ($t === 'REAL' || $t === 'FLOAT' || $t === 'DOUBLE') {
        return ['DOUBLE', $rest];
    }
    if ($t === 'TEXT') {
        if (preg_match('~\bDEFAULT\s+\(?CURRENT_TIMESTAMP\)?~i', $rest)) {
            return ['DATETIME', preg_replace('~DEFAULT\s+\(?CURRENT_TIMESTAMP\)?~i', 'DEFAULT CURRENT_TIMESTAMP', $rest)];
        }
        if ($isKey) {
            return ['VARCHAR(190)', $rest];
        }
        return [$hasDefault ? 'VARCHAR(1000)' : 'LONGTEXT', $rest];
    }
    return [$type, $rest];
};

@mkdir($dir . '/mysql', 0775, true);
foreach ($files as $f) {
    $base = basename($f);
    if ($only && $only !== $base) {
        continue;
    }
    $out = ["-- " . $base . " (MySQL/MariaDB) — dibuat otomatis oleh scripts/make-mysql-ddl.php dari versi SQLite"];
    foreach ($statementsOf($f) as $st) {
        if (preg_match('~^CREATE\s+TABLE\s+(\w+)\s*\((.*)\)$~is', $st, $m)) {
            [$table, $body] = [$m[1], $m[2]];
            $defs = [];
            foreach ($splitTop($body) as $item) {
                if (preg_match('~^(?:CONSTRAINT\s+\w+\s+)?FOREIGN\s+KEY~i', $item)) {
                    continue;
                }
                if (preg_match('~^(?:CONSTRAINT\s+\w+\s+)?(PRIMARY\s+KEY|UNIQUE)\s*\(~i', $item)) {
                    $defs[] = '  ' . $item;
                    continue;
                }
                if (!preg_match('~^(\w+)\s+(\w+)\s*(.*)$~s', $item, $c)) {
                    throw new RuntimeException("Tidak bisa membaca kolom: $item");
                }
                [, $col, $type, $rest] = $c;
                $rest = preg_replace('~\s*REFERENCES\s+\w+\s*\([^)]*\)(\s+ON\s+(DELETE|UPDATE)\s+(CASCADE|SET\s+NULL|RESTRICT|NO\s+ACTION))*~i', '', $rest);
                if (preg_match('~^\s*PRIMARY\s+KEY(\s+AUTOINCREMENT)?~i', $rest) && strtoupper($type) === 'INTEGER') {
                    $defs[] = "  \"$col\" BIGINT AUTO_INCREMENT PRIMARY KEY" . preg_replace('~^\s*PRIMARY\s+KEY(\s+AUTOINCREMENT)?~i', '', $rest);
                    continue;
                }
                [$mt, $rest] = $mapType($table, $col, $type, $rest);
                $defs[] = "  \"$col\" $mt" . (trim($rest) !== '' ? ' ' . trim($rest) : '');
            }
            $out[] = "CREATE TABLE \"$table\" (\n" . implode(",\n", $defs) . "\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        } elseif (preg_match('~^ALTER\s+TABLE\s+(\w+)\s+ADD\s+COLUMN\s+(\w+)\s+(\w+)\s*(.*)$~is', $st, $m)) {
            $rest = preg_replace('~\s*REFERENCES\s+\w+\s*\([^)]*\)~i', '', $m[4]);
            [$mt, $rest] = $mapType($m[1], $m[2], $m[3], $rest);
            $out[] = "ALTER TABLE \"{$m[1]}\" ADD COLUMN \"{$m[2]}\" $mt" . (trim($rest) !== '' ? ' ' . trim($rest) : '');
        } elseif (preg_match('~^CREATE\s+(UNIQUE\s+)?INDEX\s+(\w+)\s+ON\s+(\w+)\s*\((.+)\)$~is', $st, $m)) {
            $cols = implode(', ', array_map(fn ($c) => preg_replace('~^(\w+)~', '"$1"', trim($c)), explode(',', $m[4])));
            $out[] = "CREATE {$m[1]}INDEX {$m[2]} ON \"{$m[3]}\" ($cols)";
        } else {
            $out[] = $st; // INSERT/UPDATE dll dipakai apa adanya — periksa manual
        }
    }
    file_put_contents($dir . '/mysql/' . $base, implode(";\n\n", $out) . ";\n");
    echo "mysql/$base\n";
}
