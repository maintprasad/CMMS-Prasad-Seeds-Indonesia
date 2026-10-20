-- 001_core.sql (MySQL/MariaDB) — dibuat otomatis oleh scripts/make-mysql-ddl.php dari versi SQLite;

CREATE TABLE "units" (
  "id" BIGINT AUTO_INCREMENT PRIMARY KEY,
  "code" VARCHAR(190) NOT NULL UNIQUE,
  "name" LONGTEXT NOT NULL,
  "created_at" DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE "areas" (
  "id" BIGINT AUTO_INCREMENT PRIMARY KEY,
  "unit_id" BIGINT NOT NULL,
  "name" LONGTEXT NOT NULL,
  "created_at" DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_areas_unit ON "areas" ("unit_id");

CREATE TABLE "equipment" (
  "id" BIGINT AUTO_INCREMENT PRIMARY KEY,
  "area_id" BIGINT NOT NULL,
  "code" LONGTEXT,
  "name" VARCHAR(190) NOT NULL,
  "brand" LONGTEXT,
  "model" LONGTEXT,
  "serial_no" LONGTEXT,
  "category" LONGTEXT,
  "status" VARCHAR(1000) NOT NULL DEFAULT 'Active',
  "install_date" LONGTEXT,
  "spec" LONGTEXT,
  "notes" LONGTEXT,
  "attrs" LONGTEXT,
  "created_at" DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  "updated_at" DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_equipment_area ON "equipment" ("area_id");

CREATE INDEX idx_equipment_name ON "equipment" ("name");

CREATE TABLE "equipment_spareparts" (
  "id" BIGINT AUTO_INCREMENT PRIMARY KEY,
  "equipment_id" BIGINT NOT NULL,
  "name" LONGTEXT NOT NULL,
  "specification" LONGTEXT,
  "price" DOUBLE,
  "qty" DOUBLE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_spare_equipment ON "equipment_spareparts" ("equipment_id");

CREATE TABLE "users" (
  "id" BIGINT AUTO_INCREMENT PRIMARY KEY,
  "username" VARCHAR(190) NOT NULL UNIQUE,
  "name" LONGTEXT NOT NULL,
  "password_hash" LONGTEXT NOT NULL,
  "role" VARCHAR(1000) NOT NULL DEFAULT 'user',
  "unit_id" BIGINT,
  "department" LONGTEXT,
  "jabatan" LONGTEXT,
  "whatsapp" LONGTEXT,
  "active" BIGINT NOT NULL DEFAULT 1,
  "created_at" DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE "auth_tokens" (
  "token_hash" VARCHAR(190) PRIMARY KEY,
  "user_id" BIGINT NOT NULL,
  "expires_at" BIGINT NOT NULL,
  "created_at" DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE "technicians" (
  "id" BIGINT AUTO_INCREMENT PRIMARY KEY,
  "user_id" BIGINT,
  "name" LONGTEXT NOT NULL,
  "spec" LONGTEXT,
  "shift" LONGTEXT,
  "phone" LONGTEXT,
  "active" BIGINT NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE "mw_items" (
  "id" VARCHAR(190) PRIMARY KEY,
  "name" LONGTEXT NOT NULL,
  "spec" LONGTEXT,
  "category" LONGTEXT,
  "uom" VARCHAR(1000) NOT NULL DEFAULT 'pcs',
  "unit_id" BIGINT,
  "qty" DOUBLE NOT NULL DEFAULT 0,
  "min_stock" DOUBLE NOT NULL DEFAULT 0,
  "price" DOUBLE,
  "notes" LONGTEXT,
  "updated_at" DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_mw_items_unit ON "mw_items" ("unit_id");

CREATE TABLE "work_orders" (
  "id" VARCHAR(190) PRIMARY KEY,
  "title" LONGTEXT NOT NULL,
  "type" VARCHAR(1000) NOT NULL DEFAULT 'Corrective',
  "priority" VARCHAR(1000) NOT NULL DEFAULT 'Medium',
  "status" VARCHAR(190) NOT NULL DEFAULT 'Open',
  "unit_id" BIGINT,
  "area_id" BIGINT,
  "equipment_id" BIGINT,
  "requestor_id" BIGINT,
  "requestor_name" LONGTEXT,
  "department" LONGTEXT,
  "description" LONGTEXT,
  "due_date" LONGTEXT,
  "source" VARCHAR(1000) NOT NULL DEFAULT 'wo',
  "source_ref" LONGTEXT,
  "created_at" VARCHAR(190) NOT NULL,
  "started_at" LONGTEXT,
  "finished_at" LONGTEXT,
  "closed_at" LONGTEXT,
  "work_seconds" BIGINT NOT NULL DEFAULT 0,
  "closing_note" LONGTEXT,
  "rating" BIGINT,
  "rating_comment" LONGTEXT,
  "updated_at" LONGTEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_wo_status ON "work_orders" ("status");

CREATE INDEX idx_wo_equipment ON "work_orders" ("equipment_id");

CREATE INDEX idx_wo_unit ON "work_orders" ("unit_id");

CREATE INDEX idx_wo_created ON "work_orders" ("created_at");

CREATE TABLE "wo_techs" (
  "wo_id" VARCHAR(190) NOT NULL,
  "tech_id" BIGINT NOT NULL,
  PRIMARY KEY (wo_id, tech_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE "wo_sessions" (
  "id" BIGINT AUTO_INCREMENT PRIMARY KEY,
  "wo_id" VARCHAR(190) NOT NULL,
  "started_at" LONGTEXT NOT NULL,
  "ended_at" LONGTEXT,
  "end_reason" LONGTEXT,
  "note" LONGTEXT,
  "by_user" LONGTEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_wos_wo ON "wo_sessions" ("wo_id");

CREATE TABLE "wo_parts" (
  "id" BIGINT AUTO_INCREMENT PRIMARY KEY,
  "wo_id" VARCHAR(190) NOT NULL,
  "item_id" LONGTEXT,
  "name" LONGTEXT NOT NULL,
  "qty" DOUBLE NOT NULL DEFAULT 1,
  "uom" VARCHAR(1000) NOT NULL DEFAULT 'pcs',
  "unit_id" BIGINT,
  "added_by" LONGTEXT,
  "added_at" LONGTEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_wop_wo ON "wo_parts" ("wo_id");

CREATE TABLE "wo_notes" (
  "id" BIGINT AUTO_INCREMENT PRIMARY KEY,
  "wo_id" VARCHAR(190) NOT NULL,
  "author" LONGTEXT NOT NULL,
  "note" LONGTEXT NOT NULL,
  "created_at" LONGTEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_won_wo ON "wo_notes" ("wo_id");

CREATE TABLE "wo_tasks" (
  "id" BIGINT AUTO_INCREMENT PRIMARY KEY,
  "wo_id" VARCHAR(190) NOT NULL,
  "position" BIGINT NOT NULL DEFAULT 0,
  "text" LONGTEXT NOT NULL,
  "done" BIGINT NOT NULL DEFAULT 0,
  "done_by" LONGTEXT,
  "done_at" LONGTEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_wot_wo ON "wo_tasks" ("wo_id");

CREATE TABLE "wo_history" (
  "wo_id" VARCHAR(190) PRIMARY KEY,
  "what_text" LONGTEXT,
  "when_text" LONGTEXT,
  "where_text" LONGTEXT,
  "who_text" LONGTEXT,
  "how_text" LONGTEXT,
  "why_text" LONGTEXT,
  "updated_by" LONGTEXT,
  "updated_at" LONGTEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE "wo_events" (
  "id" BIGINT AUTO_INCREMENT PRIMARY KEY,
  "wo_id" VARCHAR(190) NOT NULL,
  "type" LONGTEXT NOT NULL,
  "message" LONGTEXT NOT NULL,
  "actor" LONGTEXT,
  "created_at" LONGTEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_woe_wo ON "wo_events" ("wo_id");

CREATE TABLE "settings" (
  "key" VARCHAR(190) PRIMARY KEY,
  "value" LONGTEXT,
  "updated_at" DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
