-- 006 · Preventive Maintenance. Jadwal & laporan menunjuk equipment_id di Equipment Database; checklist berasal dari template per tipe PM.

ALTER TABLE equipment ADD COLUMN pm_type TEXT;
ALTER TABLE mw_transactions ADD COLUMN pm_id TEXT;
CREATE INDEX idx_mwt_pm ON mw_transactions(pm_id);

CREATE TABLE pm_types (
  code  TEXT PRIMARY KEY,
  label TEXT NOT NULL,
  seq   INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE pm_template_items (
  id      INTEGER PRIMARY KEY AUTOINCREMENT,
  pm_type TEXT NOT NULL REFERENCES pm_types(code),
  seq     INTEGER NOT NULL DEFAULT 0,
  text    TEXT NOT NULL
);
CREATE INDEX idx_pmti_type ON pm_template_items(pm_type, seq);

CREATE TABLE pm_schedules (
  id              TEXT PRIMARY KEY,                -- SCH-001 dst
  equipment_id    INTEGER NOT NULL REFERENCES equipment(id),
  periode         TEXT NOT NULL DEFAULT 'Annual',  -- Daily | Weekly | Monthly | Quarterly | Semi-Annual | Annual
  month           INTEGER,                         -- bulan jadwal awal (1-12)
  week            INTEGER,                         -- minggu ke- dalam bulan (1-6)
  pic             TEXT,
  pic_tech_id     INTEGER REFERENCES technicians(id),
  last_done       TEXT,                            -- tanggal PM terakhir (diisi otomatis dari laporan)
  mp              INTEGER,                         -- jumlah man power
  hari            INTEGER,                         -- estimasi hari kerja
  notes           TEXT,
  start_date      TEXT,
  takeover_at     TEXT,
  takeover_from   TEXT,
  takeover_reason TEXT,
  active          INTEGER NOT NULL DEFAULT 1,
  created_by      TEXT,
  created_at      TEXT NOT NULL,
  updated_at      TEXT NOT NULL
);
CREATE INDEX idx_pms_equipment ON pm_schedules(equipment_id);

CREATE TABLE pm_takeovers (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  schedule_id TEXT NOT NULL REFERENCES pm_schedules(id),
  at          TEXT NOT NULL,
  from_pic    TEXT,
  to_pic      TEXT NOT NULL,
  reason      TEXT,
  by_user     TEXT
);
CREATE INDEX idx_pmtk_sched ON pm_takeovers(schedule_id);

CREATE TABLE pm_reports (
  id           TEXT PRIMARY KEY,                   -- PM/<kode unit>/<YYYYMM>/<urut>; impor lama memakai ID lama
  schedule_id  TEXT REFERENCES pm_schedules(id),
  equipment_id INTEGER NOT NULL REFERENCES equipment(id),
  unit_id      INTEGER REFERENCES units(id),
  area_id      INTEGER REFERENCES areas(id),
  report_date  TEXT NOT NULL,
  periode      TEXT,
  week         INTEGER,
  month        INTEGER,
  pm_type      TEXT,
  teknisi      TEXT NOT NULL DEFAULT '',
  approval     TEXT,
  notes        TEXT,
  ppe          TEXT,                               -- JSON {helm:true,...}
  items_total  INTEGER NOT NULL DEFAULT 0,
  items_ok     INTEGER NOT NULL DEFAULT 0,
  items_no     INTEGER NOT NULL DEFAULT 0,
  wo_id        TEXT,
  source       TEXT NOT NULL DEFAULT 'app',        -- app | import
  created_by   TEXT,
  created_at   TEXT NOT NULL,
  updated_at   TEXT NOT NULL
);
CREATE INDEX idx_pmr_date ON pm_reports(report_date);
CREATE INDEX idx_pmr_equipment ON pm_reports(equipment_id, report_date);
CREATE INDEX idx_pmr_schedule ON pm_reports(schedule_id);

CREATE TABLE pm_report_items (
  report_id TEXT NOT NULL REFERENCES pm_reports(id) ON DELETE CASCADE,
  seq       INTEGER NOT NULL,
  text      TEXT NOT NULL,
  yes       INTEGER NOT NULL DEFAULT 0,
  no        INTEGER NOT NULL DEFAULT 0,
  note      TEXT,
  PRIMARY KEY (report_id, seq)
);

CREATE TABLE pm_report_parts (
  id        INTEGER PRIMARY KEY AUTOINCREMENT,
  report_id TEXT NOT NULL REFERENCES pm_reports(id) ON DELETE CASCADE,
  seq       INTEGER NOT NULL DEFAULT 0,
  item_id   TEXT REFERENCES mw_items(id),          -- tertaut MaintWare → stok otomatis berkurang
  name      TEXT NOT NULL,
  spec      TEXT,
  qty       REAL NOT NULL DEFAULT 1,
  uom       TEXT
);
CREATE INDEX idx_pmrp_report ON pm_report_parts(report_id);

CREATE TABLE pm_signatures (
  report_id        TEXT PRIMARY KEY REFERENCES pm_reports(id) ON DELETE CASCADE,
  spv_name         TEXT,
  spv_signature    TEXT,                           -- PNG data URL
  spv_signed_at    TEXT,
  leader_name      TEXT,
  leader_signature TEXT,
  leader_signed_at TEXT,
  updated_at       TEXT NOT NULL
);
