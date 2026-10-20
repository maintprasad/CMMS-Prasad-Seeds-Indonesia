import { api } from '../../core/api.js';
import { h, attempt, modal, table, dt } from '../../core/ui.js';
import { stBadge, dueText } from './shared.js';

/** Dialog takeover: ganti PIC satu/beberapa jadwal dengan alasan (tercatat di riwayat). */
export function takeoverDialog(schedules, done) {
  const pic = h('input', { list: 'tk-names', placeholder: 'Nama PIC pengganti' });
  const names = h('datalist', { id: 'tk-names' });
  api.get('meta').then(({ technicians }) => names.append(...technicians.filter((t) => t.active).map((t) => h('option', { value: t.name }))));
  const reason = h('textarea', { rows: 3, placeholder: 'Alasan takeover (mis. PIC cuti, beban kerja)…' });
  modal(`Takeover ${schedules.length} jadwal`, h('div', {},
    h('div', { style: 'max-height:140px;overflow:auto;margin-bottom:12px' }, schedules.map((s) => h('div', { class: 'note' }, h('b', {}, s.equipment_name), h('small', { class: 'muted' }, ` PIC saat ini: ${s.pic || '—'}`)))),
    h('label', { class: 'field' }, h('span', {}, 'PIC pengganti *'), pic, names), h('label', { class: 'field', style: 'margin-top:12px' }, h('span', {}, 'Alasan *'), reason)), { wide: true, actions: [
    { label: 'Batal', run: () => {} },
    { label: 'Takeover', kind: 'primary', run: async () => (await attempt(async () => { await api.post('pm/takeover', { ids: schedules.map((s) => s.id), to_pic: pic.value, reason: reason.value }); await done(); }, 'PIC diganti.')) ? true : false }] });
}

/** Takeover PM: pilih beberapa jadwal outstanding untuk diambil alih sekaligus + riwayat takeover. */
export default async function render(root, { meta }) {
  const f = { unit_id: '', status: 'outstanding', q: '' };
  const picked = new Set();
  const host = h('div'), hist = h('div');
  const unitSel = h('select', { 'aria-label': 'Unit', onchange: (e) => { f.unit_id = e.target.value; load(); } }, h('option', { value: '' }, 'Semua unit'), meta.units.map((u) => h('option', { value: u.id }, u.name)));
  const bulk = h('button', { class: 'btn primary', onclick: () => takeoverDialog(window.__tkRows.filter((s) => picked.has(s.id)), () => { picked.clear(); load(); }) }, 'Takeover terpilih');
  const load = async () => {
    const [r, hs] = await Promise.all([api.get('pm/schedules', f), api.get('pm/takeovers')]);
    window.__tkRows = r.rows;
    bulk.textContent = `Takeover terpilih (${picked.size})`; bulk.disabled = !picked.size;
    host.replaceChildren(table([
      { label: '', render: (s) => h('input', { type: 'checkbox', checked: picked.has(s.id), 'aria-label': `pilih ${s.equipment_name}`, onchange: (e) => { e.target.checked ? picked.add(s.id) : picked.delete(s.id); bulk.textContent = `Takeover terpilih (${picked.size})`; bulk.disabled = !picked.size; } }) },
      { label: 'Equipment', render: (s) => h('div', {}, h('b', {}, s.equipment_name), h('div', { class: 'muted' }, `${s.unit_name} › ${s.area_name}`)) },
      { label: 'PIC', render: (s) => h('span', {}, s.pic || '—', s.takeover_at ? h('small', { class: 'muted' }, ` ← ${s.takeover_from || '—'}`) : null) }, { label: 'Seharusnya', render: (s) => dueText(s) }, { label: 'Status', render: (s) => stBadge(s.status) },
    ], r.rows, { empty: 'Tidak ada jadwal outstanding.' }));
    hist.replaceChildren(h('div', { class: 'card', style: 'margin-top:1.5rem' }, h('h2', {}, 'Riwayat takeover'), table([
      { label: 'Waktu', render: (x) => dt(x.at) }, { label: 'Equipment', render: (x) => h('div', {}, h('b', {}, x.equipment_name), h('div', { class: 'muted' }, x.area_name)) },
      { label: 'Dari → ke', render: (x) => `${x.from_pic || '—'} → ${x.to_pic}` }, { label: 'Alasan', key: 'reason' }, { label: 'Oleh', key: 'by_user' },
    ], hs.rows, { empty: 'Belum ada takeover.' })));
  };
  let t;
  root.append(h('div', { class: 'page' }, h('div', { class: 'page-head' }, h('h1', {}, 'Takeover PM'), h('span', { class: 'sp' }), bulk),
    h('div', { class: 'toolbar' }, h('input', { class: 'grow', type: 'search', placeholder: 'Cari equipment / PIC…', oninput: (e) => { clearTimeout(t); t = setTimeout(() => { f.q = e.target.value; load(); }, 300); } }), unitSel), host, hist));
  await load();
}
