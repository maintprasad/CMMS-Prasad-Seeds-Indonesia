-- 003_wo_files.sql (MySQL/MariaDB) — dibuat otomatis oleh scripts/make-mysql-ddl.php dari versi SQLite;

CREATE TABLE "wo_files" (
  "id" BIGINT AUTO_INCREMENT PRIMARY KEY,
  "wo_id" VARCHAR(190) NOT NULL,
  "category" VARCHAR(190) NOT NULL,
  "name" LONGTEXT NOT NULL,
  "mime" LONGTEXT NOT NULL,
  "size" BIGINT NOT NULL,
  "data" LONGTEXT NOT NULL,
  "uploaded_by" LONGTEXT,
  "uploaded_id" BIGINT,
  "created_at" LONGTEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_wof_wo ON "wo_files" ("wo_id", "category");
