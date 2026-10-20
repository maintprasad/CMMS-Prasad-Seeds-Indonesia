<?php
declare(strict_types=1);

/**
 * Menyalin seluruh isi database SQLite lokal ke database tujuan (Turso) — satu arah, lokal → tujuan.
 *
 *   php scripts/copy-db.php --env=.env.turso                       # sumber: database/cmms.db
 *   php scripts/copy-db.php --env=.env.turso --from=database/x.db
 *   php scripts/copy-db.php --env=.env.turso --force               # timpa data yang sudah ada di tujuan
 *
 * Skema tujuan dipasang lewat migrasi lebih dulu. auth_tokens & schema_migrations tidak disalin
 * (semua user login ulang dengan password yang sama seperti di lokal).
 */
require __DIR__ . '/_cli.php';

const SKIP = ['auth_tokens', 'schema_migrations'];
const BATCH_BYTES = 900000; // ±0,9 MB per transaksi HTTP (wo_files berisi base64)

function out(string $s): void
{
    echo $s . PHP_EOL;
}

try {
    if (Db::driverName() !== 'turso' && !cliFlag('allow-sqlite')) {
        throw new RuntimeException('Tujuan bukan Turso. Jalankan dengan --env=.env.turso (isi TURSO_DATABASE_URL & TURSO_AUTH_TOKEN).');
    }
    $from = 'database/cmms.db';
    foreach (array_slice($argv, 1) as $a) {
        if (str_starts_with($a, '--from=')) {
            $from = substr($a, 7);
        }
    }
    if (!preg_match('~^([a-zA-Z]:)?[\\/]~', $from)) {
        $from = APP_ROOT . '/' . $from;
    }
    if (!is_file($from)) {
        throw new RuntimeException("Database sumber tidak ditemukan: $from");
    }
    $src = new SqliteDriver($from);
    out("Sumber : $from");
    out('Tujuan : ' . Db::driverName());

    $ran = Migrator::run();
    Auth::seedAdmin();
    out('Skema  : ' . ($ran ? 'dipasang ' . implode(', ', $ran) : 'sudah terbaru'));

    // urutan tabel menurut dependensi foreign key (induk dulu)
    $names = array_column($src->queryMany([["SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'"]])[0], 'name');
    $names = array_values(array_diff($names, SKIP));
    $deps = [];
    foreach ($names as $t) {
        $deps[$t] = array_values(array_unique(array_filter(array_column($src->queryMany([["SELECT \"table\" FROM pragma_foreign_key_list('$t')"]])[0], 'table'), fn ($p) => $p !== $t && in_array($p, $names, true))));
    }
    $order = [];
    while (count($order) < count($names)) {
        $progress = false;
        foreach ($names as $t) {
            if (!in_array($t, $order, true) && !array_diff($deps[$t], $order)) {
                $order[] = $t;
                $progress = true;
            }
        }
        if (!$progress) {
            $order = array_merge($order, array_diff($names, $order)); // siklus FK: sisanya apa adanya
            break;
        }
    }

    // tujuan sudah berisi data nyata? (database baru hanya berisi admin hasil seedAdmin)
    $existing = (int) Db::query('SELECT COUNT(*) c FROM units')[0]['c'] + (int) Db::query('SELECT COUNT(*) c FROM equipment')[0]['c'] + (int) Db::query('SELECT COUNT(*) c FROM work_orders')[0]['c'];
    if ($existing > 0 && !cliFlag('force')) {
        throw new RuntimeException("Tujuan sudah berisi data ($existing baris di units/equipment/work_orders). Tambahkan --force untuk menimpa SEMUA isinya dengan data lokal.");
    }
    // kosongkan tujuan (database baru hanya berisi admin hasil seedAdmin) supaya tidak bentrok dengan data lokal
    foreach (array_reverse($order) as $t) {
        Db::transaction([["DELETE FROM \"$t\""]]);
    }
    out($existing > 0 ? 'Tujuan dikosongkan (--force).' : 'Tujuan kosong, siap diisi.');

    $total = 0;
    foreach ($order as $t) {
        $dst = array_column(Db::query("SELECT name FROM pragma_table_info('$t')"), 'name');
        $cols = array_values(array_intersect(array_column($src->queryMany([["SELECT name FROM pragma_table_info('$t')"]])[0], 'name'), $dst));
        if (!$cols) {
            out(sprintf('  %-22s dilewati (tabel tidak ada di tujuan)', $t));
            continue;
        }
        $rows = $src->queryMany([['SELECT "' . implode('","', $cols) . "\" FROM \"$t\""]])[0];
        $sql = "INSERT INTO \"$t\" (\"" . implode('","', $cols) . '") VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')';
        $batch = [];
        $bytes = 0;
        foreach ($rows as $r) {
            $params = array_map(fn ($c) => $r[$c], $cols);
            $batch[] = [$sql, $params];
            $bytes += array_sum(array_map(fn ($v) => is_string($v) ? strlen($v) + 8 : 16, $params));
            if ($bytes >= BATCH_BYTES || count($batch) >= 400) {
                Db::transaction($batch);
                $batch = [];
                $bytes = 0;
            }
        }
        if ($batch) {
            Db::transaction($batch);
        }
        out(sprintf('  %-22s %6d baris', $t, count($rows)));
        $total += count($rows);
    }

    // verifikasi jumlah baris
    $bad = [];
    foreach ($order as $t) {
        $a = (int) $src->queryMany([["SELECT COUNT(*) c FROM \"$t\""]])[0][0]['c'];
        $b = (int) Db::query("SELECT COUNT(*) c FROM \"$t\"")[0]['c'];
        if ($a !== $b) {
            $bad[] = "$t (lokal $a ≠ tujuan $b)";
        }
    }
    if ($bad) {
        throw new RuntimeException('Jumlah baris tidak cocok: ' . implode('; ', $bad));
    }
    out("Selesai : $total baris disalin dan jumlah tiap tabel terverifikasi sama.");
} catch (Throwable $e) {
    fwrite(STDERR, 'GAGAL: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
