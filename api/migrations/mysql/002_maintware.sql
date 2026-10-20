-- 002_maintware.sql (MySQL/MariaDB) — dibuat otomatis oleh scripts/make-mysql-ddl.php dari versi SQLite;

ALTER TABLE "mw_items" ADD COLUMN "brand" LONGTEXT;

ALTER TABLE "mw_items" ADD COLUMN "item_type" VARCHAR(190);

ALTER TABLE "mw_items" ADD COLUMN "item_class" LONGTEXT;

ALTER TABLE "mw_items" ADD COLUMN "moving_type" LONGTEXT;

ALTER TABLE "mw_items" ADD COLUMN "pic" LONGTEXT;

CREATE INDEX idx_mw_items_type ON "mw_items" ("item_type");

CREATE TABLE "mw_transactions" (
  "id" BIGINT AUTO_INCREMENT PRIMARY KEY,
  "trx_date" VARCHAR(190) NOT NULL,
  "item_id" VARCHAR(190) NOT NULL,
  "trx_type" VARCHAR(190) NOT NULL,
  "qty" DOUBLE NOT NULL,
  "stock_after" DOUBLE,
  "pic" LONGTEXT,
  "notes" LONGTEXT,
  "unit_id" BIGINT,
  "area_id" BIGINT,
  "equipment_id" BIGINT,
  "wo_id" VARCHAR(190),
  "source" VARCHAR(1000) NOT NULL DEFAULT 'APP',
  "created_by" LONGTEXT,
  "created_at" LONGTEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_mwt_date ON "mw_transactions" ("trx_date");

CREATE INDEX idx_mwt_item ON "mw_transactions" ("item_id", "trx_type");

CREATE INDEX idx_mwt_equipment ON "mw_transactions" ("equipment_id");

CREATE INDEX idx_mwt_wo ON "mw_transactions" ("wo_id");

CREATE TABLE "mw_ro" (
  "ro_no" VARCHAR(190) PRIMARY KEY,
  "ro_date" LONGTEXT NOT NULL,
  "pic" LONGTEXT NOT NULL,
  "dept" LONGTEXT NOT NULL,
  "notes" LONGTEXT,
  "priority" VARCHAR(1000) NOT NULL DEFAULT 'Normal',
  "needed_date" LONGTEXT,
  "status" VARCHAR(190) NOT NULL DEFAULT 'Open',
  "total_est" DOUBLE NOT NULL DEFAULT 0,
  "created_by" LONGTEXT,
  "created_at" LONGTEXT NOT NULL,
  "updated_at" LONGTEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_mwro_status ON "mw_ro" ("status");

CREATE TABLE "mw_ro_items" (
  "id" BIGINT AUTO_INCREMENT PRIMARY KEY,
  "ro_no" VARCHAR(190) NOT NULL,
  "item_id" LONGTEXT,
  "name" LONGTEXT NOT NULL,
  "spec" LONGTEXT,
  "qty" DOUBLE NOT NULL DEFAULT 1,
  "unit_price" DOUBLE NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_mwroi_ro ON "mw_ro_items" ("ro_no");
