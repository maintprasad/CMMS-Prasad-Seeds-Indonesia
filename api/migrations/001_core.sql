-- 001 · Inti CMMS: master Equipment Database, user, teknisi, MaintWare (stok), Work Order.
-- Dialek SQLite (juga dipakai Turso/libSQL). Migrasi berikutnya (DM, PM, MaintWare) cukup
-- menambah file 00N_*.sql — skema tumbuh bersama modul dan data yang masuk.

CREATE TABLE units (
  id          INTEGER PRIMARY KEY,
  code        TEXT NOT NULL UNIQUE,
  name        TEXT NOT NULL,
  created_at  TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE areas (
  id          INTEGER PRIMARY KEY,
  unit_id     INTEGER NOT NULL REFERENCES units(id),
  name        TEXT NOT NULL,
  created_at  TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_areas_unit ON areas(unit_id);

-- Equipment Database: satu-satunya sumber data equipment untuk WO, DM, PM.
-- attrs = JSON bebas supaya atribut baru dari modul lain tidak perlu mengubah tabel.
CREATE TABLE equipment (
  id           INTEGER PRIMARY KEY,
  area_id      INTEGER NOT NULL REFERENCES areas(id),
  code         TEXT,
  name         TEXT NOT NULL,
  brand        TEXT,
  model        TEXT,
  serial_no    TEXT,
  category     TEXT,
  status       TEXT NOT NULL DEFAULT 'Active',
  install_date TEXT,
  spec         TEXT,
  notes        TEXT,
  attrs        TEXT,
  created_at   TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_equipment_area ON equipment(area_id);
CREATE INDEX idx_equipment_name ON equipment(name);

CREATE TABLE equipment_spareparts (
  id            INTEGER PRIMARY KEY,
  equipment_id  INTEGER NOT NULL REFERENCES equipment(id) ON DELETE CASCADE,
  name          TEXT NOT NULL,
  specification TEXT,
  price         REAL,
  qty           REAL
);
CREATE INDEX idx_spare_equipment ON equipment_spareparts(equipment_id);

CREATE TABLE users (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  username      TEXT NOT NULL UNIQUE,
  name          TEXT NOT NULL,
  password_hash TEXT NOT NULL,
  role          TEXT NOT NULL DEFAULT 'user',      -- admin | spv | maintenance | user
  unit_id       INTEGER REFERENCES units(id),
  department    TEXT,
  jabatan       TEXT,
  whatsapp      TEXT,
  active        INTEGER NOT NULL DEFAULT 1,
  created_at    TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE auth_tokens (
  token_hash  TEXT PRIMARY KEY,
  user_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  expires_at  INTEGER NOT NULL,
  created_at  TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE technicians (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id     INTEGER REFERENCES users(id),
  name        TEXT NOT NULL,
  spec        TEXT,
  shift       TEXT,
  phone       TEXT,
  active      INTEGER NOT NULL DEFAULT 1
);

-- MaintWare (stok sparepart). Work Order membaca tabel ini untuk "Parts Used".
CREATE TABLE mw_items (
  id         TEXT PRIMARY KEY,
  name       TEXT NOT NULL,
  spec       TEXT,
  category   TEXT,
  uom        TEXT NOT NULL DEFAULT 'pcs',
  unit_id    INTEGER REFERENCES units(id),
  qty        REAL NOT NULL DEFAULT 0,
  min_stock  REAL NOT NULL DEFAULT 0,
  price      REAL,
  notes      TEXT,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_mw_items_unit ON mw_items(unit_id);

CREATE TABLE work_orders (
  id             TEXT PRIMARY KEY,                 -- WO/<kode unit>/<YYYYMM>/<urut>
  title          TEXT NOT NULL,
  type           TEXT NOT NULL DEFAULT 'Corrective',
  priority       TEXT NOT NULL DEFAULT 'Medium',
  status         TEXT NOT NULL DEFAULT 'Open',     -- Open | In Progress | Pending Verification | Closed | Cancelled
  unit_id        INTEGER REFERENCES units(id),
  area_id        INTEGER REFERENCES areas(id),
  equipment_id   INTEGER REFERENCES equipment(id),
  requestor_id   INTEGER REFERENCES users(id),
  requestor_name TEXT,
  department     TEXT,
  description    TEXT,
  due_date       TEXT,
  source         TEXT NOT NULL DEFAULT 'wo',       -- wo | dm | pm  (asal permintaan)
  source_ref     TEXT,
  created_at     TEXT NOT NULL,
  started_at     TEXT,                             -- pertama kali In Progress
  finished_at    TEXT,                             -- masuk Pending Verification
  closed_at      TEXT,
  work_seconds   INTEGER NOT NULL DEFAULT 0,       -- akumulasi sesi kerja (di luar Hold)
  closing_note   TEXT,
  rating         INTEGER,
  rating_comment TEXT,
  updated_at     TEXT NOT NULL
);
CREATE INDEX idx_wo_status    ON work_orders(status);
CREATE INDEX idx_wo_equipment ON work_orders(equipment_id);
CREATE INDEX idx_wo_unit      ON work_orders(unit_id);
CREATE INDEX idx_wo_created   ON work_orders(created_at);

CREATE TABLE wo_techs (
  wo_id    TEXT NOT NULL REFERENCES work_orders(id) ON DELETE CASCADE,
  tech_id  INTEGER NOT NULL REFERENCES technicians(id),
  PRIMARY KEY (wo_id, tech_id)
);

-- Sesi kerja: Start/Continue membuka sesi, Hold/Finish menutupnya. Durasi WO = jumlah sesi.
CREATE TABLE wo_sessions (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  wo_id      TEXT NOT NULL REFERENCES work_orders(id) ON DELETE CASCADE,
  started_at TEXT NOT NULL,
  ended_at   TEXT,
  end_reason TEXT,                                 -- hold | finish
  note       TEXT,                                 -- catatan pekerjaan hari itu
  by_user    TEXT
);
CREATE INDEX idx_wos_wo ON wo_sessions(wo_id);

CREATE TABLE wo_parts (
  id        INTEGER PRIMARY KEY AUTOINCREMENT,
  wo_id     TEXT NOT NULL REFERENCES work_orders(id) ON DELETE CASCADE,
  item_id   TEXT,
  name      TEXT NOT NULL,
  qty       REAL NOT NULL DEFAULT 1,
  uom       TEXT NOT NULL DEFAULT 'pcs',
  unit_id   INTEGER,
  added_by  TEXT,
  added_at  TEXT NOT NULL
);
CREATE INDEX idx_wop_wo ON wo_parts(wo_id);

CREATE TABLE wo_notes (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  wo_id      TEXT NOT NULL REFERENCES work_orders(id) ON DELETE CASCADE,
  author     TEXT NOT NULL,
  note       TEXT NOT NULL,
  created_at TEXT NOT NULL
);
CREATE INDEX idx_won_wo ON wo_notes(wo_id);

CREATE TABLE wo_tasks (
  id        INTEGER PRIMARY KEY AUTOINCREMENT,
  wo_id     TEXT NOT NULL REFERENCES work_orders(id) ON DELETE CASCADE,
  position  INTEGER NOT NULL DEFAULT 0,
  text      TEXT NOT NULL,
  done      INTEGER NOT NULL DEFAULT 0,
  done_by   TEXT,
  done_at   TEXT
);
CREATE INDEX idx_wot_wo ON wo_tasks(wo_id);

-- Equipment History (apa, kapan, di mana, siapa, bagaimana, kenapa) — diisi Leader/SPV, opsional.
CREATE TABLE wo_history (
  wo_id       TEXT PRIMARY KEY REFERENCES work_orders(id) ON DELETE CASCADE,
  what_text   TEXT,
  when_text   TEXT,
  where_text  TEXT,
  who_text    TEXT,
  how_text    TEXT,
  why_text    TEXT,
  updated_by  TEXT,
  updated_at  TEXT
);

-- Jejak aktivitas WO (sumber tab Timeline).
CREATE TABLE wo_events (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  wo_id      TEXT NOT NULL REFERENCES work_orders(id) ON DELETE CASCADE,
  type       TEXT NOT NULL,
  message    TEXT NOT NULL,
  actor      TEXT,
  created_at TEXT NOT NULL
);
CREATE INDEX idx_woe_wo ON wo_events(wo_id);

CREATE TABLE settings (
  key        TEXT PRIMARY KEY,
  value      TEXT,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
