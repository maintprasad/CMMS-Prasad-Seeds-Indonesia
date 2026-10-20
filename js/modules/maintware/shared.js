import { api } from '../../core/api.js';
import { h, attempt, modal, form, dt } from '../../core/ui.js';

export const rp = (v) => 'Rp ' + Math.round(Number(v) || 0).toLocaleString('id-ID');
export const num = (v) => Number(v ?? 0).toLocaleString('id-ID', { maximumFractionDigits: 2 });
export const isLow = (i) => Number(i.qty) < Number(i.min_stock);
export const MONTHS = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

export const stockBadge = (i) => h('span', { class: `badge ${isLow(i) ? 'prio-critical' : 'done'}` }, isLow(i) ? '⚠ LOW' : 'OK');
export const trxBadge = (t) => h('span', { class: `badge ${t === 'IN' ? 'done' : 'prio-critical'}` }, t);
export const movingBadge = (m) => h('span', { class: `badge ${m === 'Fast Moving' ? 'prog' : ''}` }, m === 'Fast Moving' ? '↑ Fast' : m ? '↓ Slow' : '—');

let metaCache;
export async function mwMeta(force = false) {
  if (!metaCache || force) metaCache = await api.get('mw/meta');
  return metaCache;
}

/** Dialog tambah/edit item. Stok awal hanya saat membuat; perubahan stok lewat Stock IN/OUT agar tercatat. */
export async function itemDialog(item, { units, user }, done) {
  const m = await mwMeta();
  const isEdit = !!item?.id;
  const opt = (arr) => arr.map((v) => ({ value: v, label: v }));
  const f = form([
    { name: 'name', label: 'Nama item', required: true, wide: true },
    { name: 'brand', label: 'Brand' }, { name: 'spec', label: 'Spesifikasi' },
    { name: 'item_type', label: 'Type', type: 'select', options: opt(m.types), required: true },
    { name: 'item_class', label: 'Class', type: 'select', options: opt(m.classes), required: true },
    { name: 'moving_type', label: 'Moving', type: 'select', options: opt(m.moving), required: true },
    { name: 'uom', label: 'Satuan (UOM)', type: 'select', options: opt(m.uoms) },
    { name: 'price', label: 'Harga beli (Rp)', type: 'number', min: 0, step: 'any' },
    { name: 'min_stock', label: 'Min stok', type: 'number', min: 0, step: 'any' },
    ...(isEdit ? [] : [{ name: 'qty', label: 'Stok awal', type: 'number', min: 0, step: 'any', default: 0 }]),
    { name: 'unit_id', label: 'Unit gudang', type: 'select', optional: true, options: units.map((u) => ({ value: u.id, label: u.name })) },
    { name: 'pic', label: 'PIC', required: true, default: user.name },
    { name: 'notes', label: 'Catatan', type: 'textarea', wide: true },
  ], { moving_type: 'Fast Moving', uom: 'pcs', ...(item || {}) });
  modal(isEdit ? `Edit item ${item.id}` : 'Tambah item', f.node, { wide: true, actions: [
    { label: 'Batal', run: () => {} },
    { label: isEdit ? 'Simpan' : 'Tambah item', kind: 'primary', run: async () => {
      const r = await attempt(() => api.post('mw/item/save', { id: item?.id, ...f.values() }), isEdit ? 'Item diperbarui.' : 'Item ditambahkan.');
      if (!r) return false;
      await mwMeta(true);
      done?.(r.id);
    } },
  ] });
}

/** Dialog Stock IN/OUT. OUT dapat dikaitkan ke Unit → Area → Equipment dari Equipment Database. */
export function stockDialog(item, { units, areas, user }, done, type = 'IN') {
  let cur = item;
  const search = h('input', { type: 'search', placeholder: 'Cari item (kode / nama)…' });
  const pick = h('select', {});
  const info = h('div', { class: 'note' });
  const typeSel = h('select', {}, h('option', { value: 'IN' }, 'IN — barang masuk'), h('option', { value: 'OUT' }, 'OUT — barang keluar'));
  typeSel.value = type;
  const qty = h('input', { type: 'number', min: '0', step: 'any', placeholder: '0' });
  const pic = h('input', { value: user.name });
  const notes = h('textarea', { rows: 2, placeholder: 'Catatan / keperluan' });
  const unit = h('select', {}, h('option', { value: '' }, '— Unit —'), units.map((u) => h('option', { value: u.id }, u.name)));
  const area = h('select', {}, h('option', { value: '' }, '— Area —'));
  const eq = h('select', {}, h('option', { value: '' }, '— Equipment —'));
  const preview = h('div', { class: 'muted' });
  const outBox = h('div', { class: 'form-grid' },
    h('label', { class: 'field' }, h('span', {}, 'Unit'), unit), h('label', { class: 'field' }, h('span', {}, 'Area'), area),
    h('label', { class: 'field wide' }, h('span', {}, 'Equipment (Equipment Database)'), eq));

  const showInfo = () => {
    info.replaceChildren(cur ? h('span', {}, h('b', {}, `${cur.id} — ${cur.name}`), h('div', { class: 'muted' }, `${[cur.brand, cur.spec].filter(Boolean).join(' · ') || '—'} · stok ${num(cur.qty)} ${cur.uom}`)) : h('span', { class: 'muted' }, 'Pilih item dulu.'));
    upd();
  };
  const upd = () => {
    outBox.style.display = typeSel.value === 'OUT' ? '' : 'none';
    const q = Number(qty.value) || 0;
    if (!cur || !q) { preview.textContent = ''; return; }
    const after = typeSel.value === 'IN' ? Number(cur.qty) + q : Number(cur.qty) - q;
    preview.textContent = after < 0 ? `⚠ Stok tidak cukup. Maks OUT: ${num(cur.qty)}` : `Stok menjadi: ${num(cur.qty)} → ${num(after)} ${cur.uom}`;
    preview.style.color = after < 0 ? 'var(--red)' : after < Number(cur.min_stock) ? 'var(--orange)' : 'var(--green)';
  };
  let t;
  const doSearch = async () => {
    const r = await api.get('mw/items', { q: search.value, limit: 40 });
    pick.replaceChildren(h('option', { value: '' }, `— Pilih item (${r.total}) —`), ...r.rows.map((i) => h('option', { value: i.id, disabled: false }, `${i.id} · ${i.name}${i.brand ? ` [${i.brand}]` : ''} (${num(i.qty)} ${i.uom})`)));
    pick._rows = r.rows;
  };
  search.oninput = () => { clearTimeout(t); t = setTimeout(doSearch, 250); };
  pick.onchange = () => { cur = pick._rows.find((i) => i.id === pick.value) || null; showInfo(); };
  unit.onchange = () => { area.replaceChildren(h('option', { value: '' }, '— Area —'), ...areas.filter((a) => a.unit_id == unit.value).map((a) => h('option', { value: a.id }, a.name))); eq.replaceChildren(h('option', { value: '' }, '— Equipment —')); };
  area.onchange = async () => {
    const r = area.value ? await api.get('equipment', { area_id: area.value, limit: 300 }) : { rows: [] };
    eq.replaceChildren(h('option', { value: '' }, '— Equipment —'), ...r.rows.map((e) => h('option', { value: e.id }, e.name)));
  };
  typeSel.onchange = upd;
  qty.oninput = upd;

  const body = h('div', {},
    item ? null : h('div', { class: 'toolbar' }, search, pick),
    info, h('div', { style: 'height:12px' }),
    h('div', { class: 'form-grid' }, h('label', { class: 'field' }, h('span', {}, 'Tipe'), typeSel), h('label', { class: 'field' }, h('span', {}, 'Jumlah *'), qty),
      h('label', { class: 'field' }, h('span', {}, 'PIC *'), pic), h('label', { class: 'field' }, h('span', {}, 'Catatan'), notes)),
    h('div', { style: 'height:12px' }), outBox, h('div', { style: 'margin-top:10px' }, preview));
  modal(item ? `Update stok · ${item.id}` : 'Stock IN / OUT', body, { wide: true, actions: [
    { label: 'Batal', run: () => {} },
    { label: 'Konfirmasi', kind: 'primary', run: async () => {
      if (!cur) { await attempt(() => { throw new Error('Pilih item terlebih dahulu.'); }); return false; }
      const r = await attempt(() => api.post('mw/stock', { item_id: cur.id, type: typeSel.value, qty: Number(qty.value), pic: pic.value, notes: notes.value, equipment_id: Number(eq.value) || undefined, area_id: Number(area.value) || undefined }),
        `Stock ${typeSel.value} ${qty.value} ${cur.uom} tercatat.`);
      if (!r) return false;
      done?.();
    } },
  ] });
  showInfo();
  if (!item) doSearch();
}

export const trxWhere = (t) => [t.unit_name, t.area_name, t.equipment_name].filter(Boolean).join(' › ');
export { dt };
