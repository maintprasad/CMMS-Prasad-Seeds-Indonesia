import { api } from '../../core/api.js';
import { h, modal } from '../../core/ui.js';
import { stBadge, dueText, equipmentPicker } from './shared.js';

/** Input PM: pilih jadwal (atau equipment tanpa jadwal) lalu isi laporan. */
export default async function render(root, { meta, go }) {
  const f = { unit_id: '', area_id: '', status: 'outstanding', q: '' };
  const host = h('div');
  const areaSel = h('select', { 'aria-label': 'Area', onchange: (e) => { f.area_id = e.target.value; load(); } });
  const fillAreas = () => areaSel.replaceChildren(h('option', { value: '' }, 'Semua area'), ...meta.areas.filter((a) => !f.unit_id || a.unit_id == f.unit_id).map((a) => h('option', { value: a.id }, a.name)));
  const unitSel = h('select', { 'aria-label': 'Unit', onchange: (e) => { f.unit_id = e.target.value; f.area_id = ''; fillAreas(); load(); } }, h('option', { value: '' }, 'Semua unit'), meta.units.map((u) => h('option', { value: u.id }, u.name)));
  const statusSel = h('select', { 'aria-label': 'Tampilkan', onchange: (e) => { f.status = e.target.value; load(); } }, [['outstanding', 'Belum dikerjakan'], ['overdue', 'Terlambat saja'], ['', 'Semua jadwal']].map(([v, l]) => h('option', { value: v }, l)));
  fillAreas();

  const load = async () => {
    const r = await api.get('pm/schedules', f);
    if (!r.rows.length) { host.replaceChildren(h('div', { class: 'empty' }, f.status ? '✅ Tidak ada jadwal outstanding untuk filter ini.' : 'Belum ada jadwal.')); return; }
    const groups = new Map();
    r.rows.forEach((s) => { const k = `${s.unit_name} › ${s.area_name}`; if (!groups.has(k)) groups.set(k, []); groups.get(k).push(s); });
    host.replaceChildren(...[...groups].map(([title, rows]) => h('div', { class: 'card' }, h('div', { class: 'page-head' }, h('h2', {}, title), h('span', { class: 'muted' }, `${rows.length} equipment`)),
      h('div', { class: 'tile-grid' }, rows.map((s) => h('button', { class: `tile ${s.status === 'overdue' ? 't-alert' : s.status === 'done' ? 't-ok' : 't-pend'}`, onclick: () => go('pm/form', { schedule_id: s.id }) },
        h('b', {}, s.equipment_name), h('div', { class: 'muted' }, `${s.periode} · ${s.pic || 'tanpa PIC'}${s.pm_type ? '' : ' · tipe PM belum diatur'}`),
        h('div', { class: 'tile-foot' }, stBadge(s.status), ' ', h('span', { class: s.status === 'overdue' ? 'late' : 'muted' }, dueText(s)))))))));
  };
  const adhoc = () => {
    let eq = null;
    modal('PM tanpa jadwal', h('div', {}, h('p', { class: 'muted' }, 'Pilih equipment dari Equipment Database. Tipe PM equipment menentukan checklist.'), equipmentPicker(meta, (e) => { eq = e; })), { wide: true, actions: [
      { label: 'Batal', run: () => {} }, { label: 'Lanjut isi laporan', kind: 'primary', run: () => { if (!eq) return false; go('pm/form', { equipment_id: eq.id }); } }] });
  };
  let t;
  root.append(h('div', { class: 'page' }, h('div', { class: 'page-head' }, h('h1', {}, 'Input PM'), h('span', { class: 'muted' }, 'pilih jadwal untuk dikerjakan'), h('span', { class: 'sp' }), h('button', { class: 'btn', onclick: adhoc }, '+ PM tanpa jadwal')),
    h('div', { class: 'toolbar' }, h('input', { class: 'grow', type: 'search', placeholder: 'Cari equipment / PIC…', oninput: (e) => { clearTimeout(t); t = setTimeout(() => { f.q = e.target.value; load(); }, 300); } }), unitSel, areaSel, statusSel), host));
  await load();
}
