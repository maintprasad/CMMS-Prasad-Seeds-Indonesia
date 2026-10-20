import { api } from '../../core/api.js';
import { h, attempt, modal, form, toast } from '../../core/ui.js';

const ownersOf = (meta, kind) => meta.owners.filter((u) => (kind === 'production' ? u.role === 'user' : ['maintenance', 'spv'].includes(u.role)));
const ownerOptions = (meta, kind) => ownersOf(meta, kind).map((u) => ({ value: u.id, label: `${u.name}${u.department ? ' · ' + u.department : ''}` }));

/** Dua select penanggung jawab (Produksi + Maintenance), keduanya wajib. */
export function ownerFields(meta, init = {}, required = true) {
  const mk = (kind, label, value) => {
    const sel = h('select', { required }, h('option', { value: '' }, required ? '— pilih —' : '— tidak diubah —'), ownerOptions(meta, kind).map((o) => h('option', { value: o.value }, o.label)));
    sel.value = value ?? '';
    return { sel, node: h('label', { class: 'field' }, h('span', {}, `${label}${required ? ' *' : ''}`), sel) };
  };
  const prod = mk('production', 'PJ Produksi', init.pj_production_id);
  const mtc = mk('maintenance', 'PJ Maintenance', init.pj_maintenance_id);
  return { nodes: [prod.node, mtc.node], values: () => ({ pj_production_id: Number(prod.sel.value) || 0, pj_maintenance_id: Number(mtc.sel.value) || 0 }),
    hint: (!ownersOf(meta, 'production').length || !ownersOf(meta, 'maintenance').length) ? h('p', { class: 'muted' }, 'Belum ada akun untuk salah satu peran. Buat dulu di Setting → User (role User untuk Produksi, Maintenance/SPV untuk Maintenance).') : null };
}

export async function editDialog(meta, e, done) {
  const [{ types }, { types: pmTypes }] = await Promise.all([api.get('dm/types'), api.get('pm/types')]);
  const unitOf = (aid) => meta.areas.find((a) => a.id == aid)?.unit_id;
  const unit = h('select', {}, meta.units.map((u) => h('option', { value: u.id }, u.name)));
  const area = h('select', {});
  unit.value = unitOf(e.area_id) ?? meta.units[0].id;
  const fill = () => area.replaceChildren(...meta.areas.filter((a) => a.unit_id == unit.value).map((a) => h('option', { value: a.id }, a.name)));
  unit.onchange = fill; fill(); if (e.area_id) area.value = e.area_id;
  const f = form([
    { name: 'name', label: 'Nama equipment', required: true, wide: true }, { name: 'code', label: 'Kode / tag' }, { name: 'category', label: 'Kategori' },
    { name: 'brand', label: 'Merk' }, { name: 'model', label: 'Model' }, { name: 'serial_no', label: 'No. seri' }, { name: 'install_date', label: 'Tgl. pasang', type: 'date' },
    { name: 'status', label: 'Status', type: 'select', options: ['Active', 'Inactive', 'Under Repair'] },
    { name: 'monitor_type', label: 'Tipe monitoring (Daily Monitoring)', type: 'select', optional: true, options: types.map((t) => ({ value: t.code, label: t.label })) },
    { name: 'pm_type', label: 'Tipe PM (Preventive Maintenance)', type: 'select', optional: true, options: pmTypes.map((t) => ({ value: t.code, label: t.label })) },
    { name: 'spec', label: 'Spesifikasi', type: 'textarea', wide: true }, { name: 'notes', label: 'Catatan', type: 'textarea', wide: true },
  ], e);
  const own = ownerFields(meta, e);
  const extra = h('textarea', { rows: 2, placeholder: 'Atribut tambahan, satu per baris: nama: nilai  (mis. Daya: 5.5 kW)' });
  extra.value = Object.entries(e.attrs || {}).map(([k, v]) => `${k}: ${v}`).join('\n');
  modal(e.id ? 'Edit equipment' : 'Tambah equipment', h('div', {}, h('div', { class: 'form-grid' },
    h('label', { class: 'field' }, h('span', {}, 'Unit'), unit), h('label', { class: 'field' }, h('span', {}, 'Area *'), area)), h('div', { style: 'height:12px' }), f.node,
  h('h3', { style: 'margin:16px 0 8px' }, 'Penanggung jawab mesin (wajib)'), h('div', { class: 'form-grid' }, ...own.nodes), own.hint,
  h('label', { class: 'field', style: 'margin-top:12px' }, h('span', {}, 'Atribut tambahan'), extra)), { wide: true, actions: [
    { label: 'Batal', run: () => {} },
    { label: 'Simpan', kind: 'primary', run: async () => {
      const attrs = Object.fromEntries(extra.value.split('\n').map((l) => l.split(/:(.*)/s).map((s) => s.trim())).filter(([k, v]) => k && v));
      const r = await attempt(() => api.post('equipment/save', { id: e.id, ...f.values(), ...own.values(), area_id: Number(area.value), attrs }), 'Equipment disimpan.');
      if (!r) return false;
      done(r.id);
    } },
  ] });
}

/** Set penanggung jawab massal untuk hasil filter yang sedang tampil (Admin/SPV). */
export function bulkOwnersDialog(meta, filter, total, done) {
  const own = ownerFields(meta, {}, false);
  const only = h('input', { type: 'checkbox', checked: true, style: 'width:auto;margin-right:8px' });
  modal('Set penanggung jawab massal', h('div', {},
    h('p', {}, `Berlaku untuk ${total} equipment hasil filter saat ini${filter.pj === 'missing' ? ' (yang belum lengkap)' : ''}.`),
    h('div', { class: 'form-grid' }, ...own.nodes), own.hint,
    h('label', { style: 'display:block;margin-top:12px' }, only, 'Hanya equipment yang PJ-nya belum lengkap'),
    h('p', { class: 'muted' }, 'Kosongkan salah satu untuk tidak mengubahnya.')), { wide: true, actions: [
    { label: 'Batal', run: () => {} },
    { label: 'Terapkan', kind: 'primary', run: async () => {
      const r = await attempt(() => api.post('equipment/owners', { filter, only_missing: only.checked, ...own.values() }));
      if (!r) return false;
      toast(`${r.updated} equipment diperbarui.`);
      done();
    } },
  ] });
}

/** Sparepart standar mesin; dapat ditautkan ke item MaintWare (stok & pemakaian jadi terlihat). */
export function spareDialog(equipmentId, s, done) {
  const f = form([{ name: 'name', label: 'Nama', required: true, wide: true }, { name: 'specification', label: 'Spesifikasi', type: 'textarea', wide: true },
    { name: 'qty', label: 'Jumlah per mesin', type: 'number', step: 'any' }, { name: 'price', label: 'Harga', type: 'number', step: 'any' }], s);
  let itemId = s.item_id || '';
  const linked = h('div', { class: 'note' });
  const search = h('input', { type: 'search', placeholder: 'Cari item MaintWare (kode / nama / spesifikasi)…' });
  const results = h('div', { style: 'max-height:200px;overflow:auto;margin-top:6px' });
  const showLinked = (it) => {
    linked.replaceChildren(itemId ? h('span', {}, '🔗 ', h('b', {}, it ? `${it.id} — ${it.name}` : s.item_name ? `${itemId} — ${s.item_name}` : itemId),
      ' ', h('button', { class: 'btn sm', onclick: () => { itemId = ''; showLinked(); } }, 'Lepas tautan')) : h('span', { class: 'muted' }, 'Belum ditautkan ke MaintWare — cari item di bawah.'));
  };
  const doSearch = async () => {
    if (!search.value.trim()) return results.replaceChildren();
    const r = await api.get('mw/items', { q: search.value, limit: 20 });
    results.replaceChildren(...(r.rows.length ? r.rows.map((i) => h('div', { class: 'note', style: 'cursor:pointer', onclick: () => {
      itemId = i.id;
      if (!f.inputs.name.value.trim()) f.inputs.name.value = i.name;
      if (!f.inputs.specification.value.trim() && i.spec) f.inputs.specification.value = [i.brand, i.spec].filter(Boolean).join(' ');
      if (!f.inputs.price.value && i.price) f.inputs.price.value = i.price;
      showLinked(i);
      results.replaceChildren();
    } }, h('b', {}, i.name), h('small', { class: 'muted' }, ` ${i.id} · stok ${i.qty} ${i.uom}`), i.spec ? h('div', { class: 'muted' }, [i.brand, i.spec].filter(Boolean).join(' · ')) : null))
      : [h('p', { class: 'muted' }, 'Tidak ada item cocok di MaintWare.')]));
  };
  let t;
  search.oninput = () => { clearTimeout(t); t = setTimeout(doSearch, 250); };
  showLinked();
  modal(s.id ? 'Edit sparepart' : 'Tambah sparepart', h('div', {},
    h('h3', { style: 'margin-bottom:6px' }, 'Tautan MaintWare'), linked, search, results, h('div', { style: 'height:12px' }), f.node), { wide: true, actions: [
    ...(s.id ? [{ label: 'Hapus', kind: 'danger', run: async () => (await attempt(async () => { await api.post('sparepart/delete', { id: s.id }); await done(); }, 'Sparepart dihapus.')) ? true : false }] : []),
    { label: 'Simpan', kind: 'primary', run: async () => (await attempt(async () => { await api.post('sparepart/save', { id: s.id, equipment_id: equipmentId, item_id: itemId, ...f.values() }); await done(); }, 'Sparepart disimpan.')) ? true : false },
  ] });
}
