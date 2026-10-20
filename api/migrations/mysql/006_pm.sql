-- 006_pm.sql (MySQL/MariaDB) — dibuat otomatis oleh scripts/make-mysql-ddl.php dari versi SQLite;

ALTER TABLE "equipment" ADD COLUMN "pm_type" LONGTEXT;

ALTER TABLE "mw_transactions" ADD COLUMN "pm_id" VARCHAR(190);

CREATE INDEX idx_mwt_pm ON "mw_transactions" ("pm_id");

CREATE TABLE "pm_types" (
  "code" VARCHAR(190) PRIMARY KEY,
  "label" LONGTEXT NOT NULL,
  "seq" BIGINT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE "pm_template_items" (
  "id" BIGINT AUTO_INCREMENT PRIMARY KEY,
  "pm_type" VARCHAR(190) NOT NULL,
  "seq" BIGINT NOT NULL DEFAULT 0,
  "text" LONGTEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_pmti_type ON "pm_template_items" ("pm_type", "seq");

CREATE TABLE "pm_schedules" (
  "id" VARCHAR(190) PRIMARY KEY,
  "equipment_id" BIGINT NOT NULL,
  "periode" VARCHAR(1000) NOT NULL DEFAULT 'Annual',
  "month" BIGINT,
  "week" BIGINT,
  "pic" LONGTEXT,
  "pic_tech_id" BIGINT,
  "last_done" LONGTEXT,
  "mp" BIGINT,
  "hari" BIGINT,
  "notes" LONGTEXT,
  "start_date" LONGTEXT,
  "takeover_at" LONGTEXT,
  "takeover_from" LONGTEXT,
  "takeover_reason" LONGTEXT,
  "active" BIGINT NOT NULL DEFAULT 1,
  "created_by" LONGTEXT,
  "created_at" LONGTEXT NOT NULL,
  "updated_at" LONGTEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_pms_equipment ON "pm_schedules" ("equipment_id");

CREATE TABLE "pm_takeovers" (
  "id" BIGINT AUTO_INCREMENT PRIMARY KEY,
  "schedule_id" VARCHAR(190) NOT NULL,
  "at" LONGTEXT NOT NULL,
  "from_pic" LONGTEXT,
  "to_pic" LONGTEXT NOT NULL,
  "reason" LONGTEXT,
  "by_user" LONGTEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_pmtk_sched ON "pm_takeovers" ("schedule_id");

CREATE TABLE "pm_reports" (
  "id" VARCHAR(190) PRIMARY KEY,
  "schedule_id" VARCHAR(190),
  "equipment_id" BIGINT NOT NULL,
  "unit_id" BIGINT,
  "area_id" BIGINT,
  "report_date" VARCHAR(190) NOT NULL,
  "periode" LONGTEXT,
  "week" BIGINT,
  "month" BIGINT,
  "pm_type" LONGTEXT,
  "teknisi" VARCHAR(1000) NOT NULL DEFAULT '',
  "approval" LONGTEXT,
  "notes" LONGTEXT,
  "ppe" LONGTEXT,
  "items_total" BIGINT NOT NULL DEFAULT 0,
  "items_ok" BIGINT NOT NULL DEFAULT 0,
  "items_no" BIGINT NOT NULL DEFAULT 0,
  "wo_id" LONGTEXT,
  "source" VARCHAR(1000) NOT NULL DEFAULT 'app',
  "created_by" LONGTEXT,
  "created_at" LONGTEXT NOT NULL,
  "updated_at" LONGTEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_pmr_date ON "pm_reports" ("report_date");

CREATE INDEX idx_pmr_equipment ON "pm_reports" ("equipment_id", "report_date");

CREATE INDEX idx_pmr_schedule ON "pm_reports" ("schedule_id");

CREATE TABLE "pm_report_items" (
  "report_id" VARCHAR(190) NOT NULL,
  "seq" BIGINT NOT NULL,
  "text" LONGTEXT NOT NULL,
  "yes" BIGINT NOT NULL DEFAULT 0,
  "no" BIGINT NOT NULL DEFAULT 0,
  "note" LONGTEXT,
  PRIMARY KEY (report_id, seq)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE "pm_report_parts" (
  "id" BIGINT AUTO_INCREMENT PRIMARY KEY,
  "report_id" VARCHAR(190) NOT NULL,
  "seq" BIGINT NOT NULL DEFAULT 0,
  "item_id" LONGTEXT,
  "name" LONGTEXT NOT NULL,
  "spec" LONGTEXT,
  "qty" DOUBLE NOT NULL DEFAULT 1,
  "uom" LONGTEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_pmrp_report ON "pm_report_parts" ("report_id");

CREATE TABLE "pm_signatures" (
  "report_id" VARCHAR(190) PRIMARY KEY,
  "spv_name" LONGTEXT,
  "spv_signature" LONGTEXT,
  "spv_signed_at" LONGTEXT,
  "leader_name" LONGTEXT,
  "leader_signature" LONGTEXT,
  "leader_signed_at" LONGTEXT,
  "updated_at" LONGTEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
