-- 007_mw_photos.sql (MySQL/MariaDB) — dibuat otomatis oleh scripts/make-mysql-ddl.php dari versi SQLite;

CREATE TABLE "mw_item_photos" (
  "item_id" VARCHAR(190) PRIMARY KEY,
  "mime" LONGTEXT NOT NULL,
  "data" LONGTEXT NOT NULL,
  "updated_at" LONGTEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
