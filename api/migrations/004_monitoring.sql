-- 004 · Daily Monitoring. Seluruh data bertumpu pada Equipment Database:
--   - parameter monitoring melekat di equipment (equipment_params) → diatur di menu Equipment Database
--   - template parameter per "tipe monitoring" = pengaturan dasar parameter (dm_param_templates)
--   - sesi monitoring (dm_sessions/dm_items) menunjuk equipment_id; temuan bisa dijadikan Work Order.

ALTER TABLE equipment ADD COLUMN monitor_type TEXT;

CREATE TABLE equipment_params (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  equipment_id INTEGER NOT NULL REFERENCES equipment(id),
  label        TEXT NOT NULL,
  unit         TEXT NOT NULL DEFAULT '',
  section      TEXT NOT NULL DEFAULT '',
  type         TEXT NOT NULL DEFAULT 'numeric',   -- numeric | status
  normal_min   REAL,
  normal_max   REAL,
  warn_min     REAL,
  warn_max     REAL,
  options      TEXT,                              -- JSON array untuk type status (opsi pertama = OK, terakhir = ALERT)
  seq          INTEGER NOT NULL DEFAULT 0,
  legacy_id    TEXT                               -- ID parameter di aplikasi Daily Monitoring lama (untuk impor ulang)
);
CREATE INDEX idx_eqparams_equipment ON equipment_params(equipment_id, seq);
CREATE INDEX idx_eqparams_legacy ON equipment_params(legacy_id);

CREATE TABLE dm_monitor_types (
  code   TEXT PRIMARY KEY,
  label  TEXT NOT NULL,
  seq    INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE dm_param_templates (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  monitor_type TEXT NOT NULL REFERENCES dm_monitor_types(code),
  label        TEXT NOT NULL,
  unit         TEXT NOT NULL DEFAULT '',
  section      TEXT NOT NULL DEFAULT '',
  type         TEXT NOT NULL DEFAULT 'numeric',
  normal_min   REAL,
  normal_max   REAL,
  warn_min     REAL,
  warn_max     REAL,
  options      TEXT,
  seq          INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX idx_dmtpl_type ON dm_param_templates(monitor_type, seq);

CREATE TABLE dm_sessions (
  id           TEXT PRIMARY KEY,                  -- DM/<kode unit>/<YYYYMMDD>/<urut>; impor lama memakai ID lama
  session_date TEXT NOT NULL,                     -- YYYY-MM-DD
  equipment_id INTEGER NOT NULL REFERENCES equipment(id),
  unit_id      INTEGER REFERENCES units(id),
  area_id      INTEGER REFERENCES areas(id),
  pic          TEXT NOT NULL DEFAULT '',          -- nama-nama dipisah koma
  start_time   TEXT,
  end_time     TEXT,
  status       TEXT NOT NULL DEFAULT 'OK',        -- terburuk dari item: OK | WARNING | ALERT | PENDING
  catatan      TEXT,
  tindakan     TEXT,
  wo_id        TEXT,                              -- Work Order yang dibuat dari temuan sesi ini
  source       TEXT NOT NULL DEFAULT 'app',       -- app | import
  created_by   TEXT,
  created_at   TEXT NOT NULL,
  updated_at   TEXT NOT NULL
);
CREATE INDEX idx_dms_date ON dm_sessions(session_date);
CREATE INDEX idx_dms_equipment ON dm_sessions(equipment_id, session_date);
CREATE INDEX idx_dms_unit ON dm_sessions(unit_id, area_id);

-- Nilai tiap parameter. label/unit/section disalin saat sesi dibuat (snapshot) supaya riwayat tetap utuh
-- walau parameter di Equipment Database diubah atau dihapus.
CREATE TABLE dm_items (
  session_id TEXT NOT NULL REFERENCES dm_sessions(id) ON DELETE CASCADE,
  seq        INTEGER NOT NULL,
  param_id   INTEGER,
  label      TEXT NOT NULL,
  unit       TEXT NOT NULL DEFAULT '',
  section    TEXT NOT NULL DEFAULT '',
  value      TEXT NOT NULL DEFAULT '',
  status     TEXT NOT NULL DEFAULT '',            -- OK | WARNING | ALERT | ''
  note       TEXT,
  PRIMARY KEY (session_id, seq)
);
CREATE INDEX idx_dmi_status ON dm_items(status);
CREATE INDEX idx_dmi_param ON dm_items(param_id);
