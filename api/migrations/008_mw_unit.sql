-- 008 · Purchase Request (RO) mencatat Unit pemohon sebagai identitas tempat utama.
ALTER TABLE mw_ro ADD COLUMN unit_id INTEGER REFERENCES units(id);
CREATE INDEX idx_mwro_unit ON mw_ro(unit_id);
