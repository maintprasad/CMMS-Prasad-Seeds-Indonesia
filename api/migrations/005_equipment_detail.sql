-- 005 · Detail Equipment: penanggung jawab (produksi + maintenance, wajib) dan tautan sparepart ke MaintWare.

ALTER TABLE equipment ADD COLUMN pj_production_id INTEGER REFERENCES users(id);
ALTER TABLE equipment ADD COLUMN pj_maintenance_id INTEGER REFERENCES users(id);
CREATE INDEX idx_equipment_pj_prod ON equipment(pj_production_id);
CREATE INDEX idx_equipment_pj_mtc ON equipment(pj_maintenance_id);

-- Sparepart standar mesin dapat ditautkan ke item MaintWare (stok, harga, dan riwayat pemakaian jadi terlihat dari equipment).
ALTER TABLE equipment_spareparts ADD COLUMN item_id TEXT REFERENCES mw_items(id);
CREATE INDEX idx_spare_item ON equipment_spareparts(item_id);
