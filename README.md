# CMMS

Computerized Maintenance Management System — gabungan Work Order, Daily Monitoring, Preventive Maintenance, Equipment Database, dan MaintWare. Semua modul membaca satu Equipment Database.

```
Database (SQLite / Turso)  ←  Backend PHP (api/index.php?r=…)  →  Frontend (index.html + js/ ES modules)
```

## Menjalankan di XAMPP
1. Salin `.env.example` → `.env` (isi `ADMIN_PASSWORD`).
2. `php scripts/migrate.php` — membuat skema + admin pertama.
3. `php scripts/import-equipment.php` — impor Equipment Database dari MySQL `equipment_db` (opsional: `--mysql=nama_db`).
4. (Hanya dev) `php scripts/seed-demo.php` — akun contoh (password `demo1234`) & item MaintWare contoh.
5. Buka `http://localhost/cmms/cmms/`.

## Tes
`php scripts/test-e2e.php` — tes end-to-end dengan data acak pada database sementara.

## Turso / Vercel
1. Buat database di https://turso.tech (dashboard → Create Database), lalu ambil **URL** (`libsql://…turso.io`) dan buat **token** (Database → Create Token, read & write).
2. Isi `.env.turso` (salinan `.env.turso.example`, tidak ikut git): `TURSO_DATABASE_URL`, `TURSO_AUTH_TOKEN`, `ADMIN_PASSWORD`.
3. Cek koneksi: `php scripts/db-check.php --env=.env.turso`
4. Pasang skema + salin data lokal: `php scripts/copy-db.php --env=.env.turso` (tambah `--force` bila tujuan sudah berisi data; jumlah baris tiap tabel diverifikasi). Hanya skema: `php scripts/migrate.php --env=.env.turso`.
5. Jalankan lokal ke Turso (opsional): `.env.turso` bisa dipakai sebagai `.env` sementara.
6. Deploy Vercel: import repo di Vercel (Framework: Other), isi Environment Variables `DB_DRIVER=turso`, `TURSO_DATABASE_URL`, `TURSO_AUTH_TOKEN`, `ADMIN_USERNAME`, `ADMIN_PASSWORD`, `APP_TIMEZONE=Asia/Jakarta`. `vercel.json` memakai runtime `vercel-php`; `.vercelignore` mengecualikan database/ dan scripts/. Foto & lampiran WO tersimpan di database (base64), jadi tidak butuh disk Vercel.

Catatan: akun demo (`seed-demo.php`) ikut tersalin bila ada di lokal — hapus/ubah passwordnya sebelum dipakai produksi.

## MySQL / MariaDB (server sendiri)
1. Buat database kosong: `CREATE DATABASE cmms CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;` dan user khusus (hak penuh atas database itu).
2. Salin `.env.mysql.example` → `.env.mysql` (atau isi `.env`): `DB_DRIVER=mysql`, `MYSQL_HOST`, `MYSQL_PORT`, `MYSQL_DATABASE`, `MYSQL_USER`, `MYSQL_PASSWORD`, `ADMIN_PASSWORD`. Butuh ekstensi PHP `pdo_mysql` dan MySQL ≥ 5.7 / MariaDB ≥ 10.3.
3. Cek koneksi: `php scripts/db-check.php --env=.env.mysql`; pasang skema: `php scripts/migrate.php --env=.env.mysql`.
4. Salin data dari SQLite lokal (opsional): `php scripts/copy-db.php --env=.env.mysql` (`--force` bila tujuan sudah berisi).
5. Uji: `php scripts/test-e2e.php --mysql` (memakai database `cmms_test`, dibuat ulang tiap kali — jangan arahkan ke database berisi data).

Catatan teknis: skema MySQL ada di `api/migrations/mysql/` (dibuat dari versi SQLite dengan `php scripts/make-mysql-ddl.php`). **Setiap migrasi baru harus punya pasangan di folder mysql** — Migrator menolak jalan bila ada yang kurang. `MysqlDriver` menerjemahkan idiom SQLite (`ON CONFLICT`, `INSERT OR IGNORE`, `GROUP_CONCAT(x, ', ')`, `ESCAPE`) dan memakai `sql_mode` ANSI_QUOTES + PIPES_AS_CONCAT. Di MySQL, pengelompokan teks (`GROUP BY`) tidak membedakan huruf besar/kecil (collation `_ci`), dan urutan baris yang nilai urutnya sama bisa berbeda dari SQLite.

## Struktur
- `api/_lib/` — Env, Http, Database (+ driver SQLite/Turso), Migrator, Auth
- `api/modules/` — Equipment.php, Users.php, WorkOrder.php (satu file per modul; daftar rute di `api/index.php`)
- `api/migrations/` — skema bertahap
- `js/core/` — api, ui, menu (definisi menu bertingkat + peta halaman)
- `js/modules/<modul>/` — halaman per modul
- Riwayat perubahan: `Revision Notes.md`
