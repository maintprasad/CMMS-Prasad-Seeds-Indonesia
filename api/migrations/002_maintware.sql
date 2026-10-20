-- 002 · MaintWare: master item lengkap, transaksi stok (IN/OUT, terhubung ke Equipment DB & WO), Purchase Request.
-- mw_items sudah dibuat di 001 (dibaca WO untuk Parts Used); di sini dilengkapi dengan kolom dari aplikasi MaintWare lama.

ALTER TABLE mw_items ADD COLUMN brand TEXT;
ALTER TABLE mw_items ADD COLUMN item_type TEXT;
ALTER TABLE mw_items ADD COLUMN item_class TEXT;
ALTER TABLE mw_items ADD COLUMN moving_type TEXT;
ALTER TABLE mw_items ADD COLUMN pic TEXT;
CREATE INDEX idx_mw_items_type ON mw_items(item_type);

-- Setiap perubahan stok = satu baris transaksi. area/equipment memakai Equipment Database,
-- wo_id menghubungkan pemakaian part ke Work Order.
CREATE TABLE mw_transactions (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  trx_date     TEXT NOT NULL,
  item_id      TEXT NOT NULL REFERENCES mw_items(id),
  trx_type     TEXT NOT NULL,                 -- IN | OUT
  qty          REAL NOT NULL,
  stock_after  REAL,
  pic          TEXT,
  notes        TEXT,
  unit_id      INTEGER REFERENCES units(id),
  area_id      INTEGER REFERENCES areas(id),
  equipment_id INTEGER REFERENCES equipment(id),
  wo_id        TEXT,
  source       TEXT NOT NULL DEFAULT 'APP',   -- APP | WO | SHEETS | SHEETS-R1 | SHEETS-R2 (hasil pemulihan impor)
  created_by   TEXT,
  created_at   TEXT NOT NULL
);
CREATE INDEX idx_mwt_date ON mw_transactions(trx_date);
CREATE INDEX idx_mwt_item ON mw_transactions(item_id, trx_type);
CREATE INDEX idx_mwt_equipment ON mw_transactions(equipment_id);
CREATE INDEX idx_mwt_wo ON mw_transactions(wo_id);

CREATE TABLE mw_ro (
  ro_no        TEXT PRIMARY KEY,              -- RO-YYMM-NNN
  ro_date      TEXT NOT NULL,
  pic          TEXT NOT NULL,
  dept         TEXT NOT NULL,
  notes        TEXT,
  priority     TEXT NOT NULL DEFAULT 'Normal',
  needed_date  TEXT,
  status       TEXT NOT NULL DEFAULT 'Open',  -- Draft | Open | Approved | Sent to Purchasing | Done | Cancelled
  total_est    REAL NOT NULL DEFAULT 0,
  created_by   TEXT,
  created_at   TEXT NOT NULL,
  updated_at   TEXT NOT NULL
);
CREATE INDEX idx_mwro_status ON mw_ro(status);

CREATE TABLE mw_ro_items (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  ro_no       TEXT NOT NULL REFERENCES mw_ro(ro_no) ON DELETE CASCADE,
  item_id     TEXT,
  name        TEXT NOT NULL,
  spec        TEXT,
  qty         REAL NOT NULL DEFAULT 1,
  unit_price  REAL NOT NULL DEFAULT 0
);
CREATE INDEX idx_mwroi_ro ON mw_ro_items(ro_no);
