-- 005_equipment_detail.sql (MySQL/MariaDB) — dibuat otomatis oleh scripts/make-mysql-ddl.php dari versi SQLite;

ALTER TABLE "equipment" ADD COLUMN "pj_production_id" BIGINT;

ALTER TABLE "equipment" ADD COLUMN "pj_maintenance_id" BIGINT;

CREATE INDEX idx_equipment_pj_prod ON "equipment" ("pj_production_id");

CREATE INDEX idx_equipment_pj_mtc ON "equipment" ("pj_maintenance_id");

ALTER TABLE "equipment_spareparts" ADD COLUMN "item_id" VARCHAR(190);

CREATE INDEX idx_spare_item ON "equipment_spareparts" ("item_id");
