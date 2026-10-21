-- 008_mw_unit.sql (MySQL/MariaDB) — dibuat otomatis oleh scripts/make-mysql-ddl.php dari versi SQLite;

ALTER TABLE "mw_ro" ADD COLUMN "unit_id" BIGINT;

CREATE INDEX idx_mwro_unit ON "mw_ro" ("unit_id");
