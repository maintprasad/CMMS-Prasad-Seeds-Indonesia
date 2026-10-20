-- 003 · Foto & lampiran Work Order.
-- File disimpan di database (base64) agar sama di XAMPP/SQLite dan Vercel/Turso (Vercel tidak punya disk permanen).
-- Foto dikompres di browser sebelum diunggah; batas ukuran dijaga di API.

CREATE TABLE wo_files (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  wo_id       TEXT NOT NULL REFERENCES work_orders(id) ON DELETE CASCADE,
  category    TEXT NOT NULL,                 -- before | after | verification | attachment
  name        TEXT NOT NULL,
  mime        TEXT NOT NULL,
  size        INTEGER NOT NULL,              -- byte asli
  data        TEXT NOT NULL,                 -- base64
  uploaded_by TEXT,
  uploaded_id INTEGER,
  created_at  TEXT NOT NULL
);
CREATE INDEX idx_wof_wo ON wo_files(wo_id, category);
