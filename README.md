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
Isi `TURSO_DATABASE_URL` dan `TURSO_AUTH_TOKEN` (Environment Variables di Vercel atau `.env`), lalu jalankan `php scripts/migrate.php` dan `php scripts/import-equipment.php`. Tambahkan `--env=.env.turso` untuk memakai file env lain.

## Struktur
- `api/_lib/` — Env, Http, Database (+ driver SQLite/Turso), Migrator, Auth
- `api/modules/` — Equipment.php, Users.php, WorkOrder.php (satu file per modul; daftar rute di `api/index.php`)
- `api/migrations/` — skema bertahap
- `js/core/` — api, ui, menu (definisi menu bertingkat + peta halaman)
- `js/modules/<modul>/` — halaman per modul
- Riwayat perubahan: `Revision Notes.md`
