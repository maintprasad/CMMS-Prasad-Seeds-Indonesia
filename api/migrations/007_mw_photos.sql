-- 007 · Foto item MaintWare (satu foto per item, JPEG/PNG/WebP hasil kompres di browser, disimpan base64 di database).
-- Dipisah dari mw_items supaya daftar item tetap ringan.
CREATE TABLE mw_item_photos (
  item_id    TEXT PRIMARY KEY REFERENCES mw_items(id) ON DELETE CASCADE,
  mime       TEXT NOT NULL,
  data       TEXT NOT NULL,                 -- base64
  updated_at TEXT NOT NULL
);
