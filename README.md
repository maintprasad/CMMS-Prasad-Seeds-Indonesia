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

## Struktur
- `api/_lib/` — Env, Http, Database (+ driver SQLite/Turso), Migrator, Auth
- `api/modules/` — Equipment.php, Users.php, WorkOrder.php (satu file per modul; daftar rute di `api/index.php`)
- `api/migrations/` — skema bertahap
- `js/core/` — api, ui, menu (definisi menu bertingkat + peta halaman)
- `js/modules/<modul>/` — halaman per modul
- Riwayat perubahan: `Revision Notes.md`
