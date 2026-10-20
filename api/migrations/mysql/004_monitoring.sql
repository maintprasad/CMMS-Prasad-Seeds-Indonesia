-- 004_monitoring.sql (MySQL/MariaDB) — dibuat otomatis oleh scripts/make-mysql-ddl.php dari versi SQLite;

ALTER TABLE "equipment" ADD COLUMN "monitor_type" LONGTEXT;

CREATE TABLE "equipment_params" (
  "id" BIGINT AUTO_INCREMENT PRIMARY KEY,
  "equipment_id" BIGINT NOT NULL,
  "label" LONGTEXT NOT NULL,
  "unit" VARCHAR(1000) NOT NULL DEFAULT '',
  "section" VARCHAR(1000) NOT NULL DEFAULT '',
  "type" VARCHAR(1000) NOT NULL DEFAULT 'numeric',
  "normal_min" DOUBLE,
  "normal_max" DOUBLE,
  "warn_min" DOUBLE,
  "warn_max" DOUBLE,
  "options" LONGTEXT,
  "seq" BIGINT NOT NULL DEFAULT 0,
  "legacy_id" VARCHAR(190)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_eqparams_equipment ON "equipment_params" ("equipment_id", "seq");

CREATE INDEX idx_eqparams_legacy ON "equipment_params" ("legacy_id");

CREATE TABLE "dm_monitor_types" (
  "code" VARCHAR(190) PRIMARY KEY,
  "label" LONGTEXT NOT NULL,
  "seq" BIGINT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE "dm_param_templates" (
  "id" BIGINT AUTO_INCREMENT PRIMARY KEY,
  "monitor_type" VARCHAR(190) NOT NULL,
  "label" LONGTEXT NOT NULL,
  "unit" VARCHAR(1000) NOT NULL DEFAULT '',
  "section" VARCHAR(1000) NOT NULL DEFAULT '',
  "type" VARCHAR(1000) NOT NULL DEFAULT 'numeric',
  "normal_min" DOUBLE,
  "normal_max" DOUBLE,
  "warn_min" DOUBLE,
  "warn_max" DOUBLE,
  "options" LONGTEXT,
  "seq" BIGINT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_dmtpl_type ON "dm_param_templates" ("monitor_type", "seq");

CREATE TABLE "dm_sessions" (
  "id" VARCHAR(190) PRIMARY KEY,
  "session_date" VARCHAR(190) NOT NULL,
  "equipment_id" BIGINT NOT NULL,
  "unit_id" BIGINT,
  "area_id" BIGINT,
  "pic" VARCHAR(1000) NOT NULL DEFAULT '',
  "start_time" LONGTEXT,
  "end_time" LONGTEXT,
  "status" VARCHAR(1000) NOT NULL DEFAULT 'OK',
  "catatan" LONGTEXT,
  "tindakan" LONGTEXT,
  "wo_id" LONGTEXT,
  "source" VARCHAR(1000) NOT NULL DEFAULT 'app',
  "created_by" LONGTEXT,
  "created_at" LONGTEXT NOT NULL,
  "updated_at" LONGTEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_dms_date ON "dm_sessions" ("session_date");

CREATE INDEX idx_dms_equipment ON "dm_sessions" ("equipment_id", "session_date");

CREATE INDEX idx_dms_unit ON "dm_sessions" ("unit_id", "area_id");

CREATE TABLE "dm_items" (
  "session_id" VARCHAR(190) NOT NULL,
  "seq" BIGINT NOT NULL,
  "param_id" BIGINT,
  "label" LONGTEXT NOT NULL,
  "unit" VARCHAR(1000) NOT NULL DEFAULT '',
  "section" VARCHAR(1000) NOT NULL DEFAULT '',
  "value" VARCHAR(1000) NOT NULL DEFAULT '',
  "status" VARCHAR(190) NOT NULL DEFAULT '',
  "note" LONGTEXT,
  PRIMARY KEY (session_id, seq)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_dmi_status ON "dm_items" ("status");

CREATE INDEX idx_dmi_param ON "dm_items" ("param_id");
