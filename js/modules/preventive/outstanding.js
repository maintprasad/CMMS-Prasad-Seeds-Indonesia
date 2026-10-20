import { api } from '../../core/api.js';
import { h, table, dt } from '../../core/ui.js';
import { stBadge, dueText } from './shared.js';
import { takeoverDialog } from './takeover.js';

/** Outstanding PM: jadwal yang belum dikerjakan (terlambat / akan datang), dengan aksi Eksekusi dan Takeover. */
export default async function render(root, { meta, go }) {
  const f = { unit_id: '', area_id: '', status: 'overdue', q: '' };
  const host = h('div');
  const areaSel = h('select', { 'aria-label': 'Area', onchange: (e) => { f.area_id = e.target.value; load(); } });
  const fillAreas = () => areaSel.replaceChildren(h('option', { value: '' }, 'Semua area'), ...meta.areas.filter((a) => !f.unit_id || a.unit_id == f.unit_id).map((a) => h('option', { value: a.id }, a.name)));
  const unitSel = h('select', { 'aria-label': 'Unit', onchange: (e) => { f.unit_id = e.target.value; f.area_id = ''; fillAreas(); load(); } }, h('option', { value: '' }, 'Semua unit'), meta.units.map((u) => h('option', { value: u.id }, u.name)));
  const statusSel = h('select', { 'aria-label': 'Status', onchange: (e) => { f.status = e.target.value; load(); } }, [['overdue', 'Terlambat'], ['outstanding', 'Terlambat + akan datang'], ['upcoming', 'Akan datang']].map(([v, l]) => h('option', { value: v }, l)));
  fillAreas();
  const load = async () => {
    const r = await api.get('pm/schedules', f);
    host.replaceChildren(h('div', { class: 'chips' }, h('span', { class: 'chip' }, 'Terlambat', h('b', {}, r.counts.overdue)), h('span', { class: 'chip' }, 'Akan datang', h('b', {}, r.counts.upcoming)), h('span', { class: 'chip' }, 'Selesai', h('b', {}, r.counts.done))),
      table([
        { label: 'Equipment', render: (s) => h('div', {}, h('b', {}, s.equipment_name), h('div', { class: 'muted' }, `${s.unit_name} › ${s.area_name}`)) }, { label: 'Periode', key: 'periode' },
        { label: 'PIC', render: (s) => s.pic || h('span', { class: 'late' }, 'belum ada PIC') }, { label: 'Terakhir', render: (s) => (s.last_done ? dt(s.last_done, false) : '—') },
        { label: 'Seharusnya', render: (s) => dueText(s) }, { label: 'Status', render: (s) => stBadge(s.status) },
        { label: '', render: (s) => h('div', { style: 'display:flex;gap:4px' }, h('button', { class: 'btn sm primary', onclick: () => go('pm/form', { schedule_id: s.id }) }, 'Eksekusi'),
          h('button', { class: 'btn sm', onclick: () => takeoverDialog([s], () => load()) }, 'Takeover')) },
      ], r.rows, { empty: '✅ Tidak ada PM outstanding untuk filter ini.' }));
  };
  let t;
  root.append(h('div', { class: 'page' }, h('div', { class: 'page-head' }, h('h1', {}, 'Outstanding PM')),
    h('div', { class: 'toolbar' }, h('input', { class: 'grow', type: 'search', placeholder: 'Cari equipment / PIC…', oninput: (e) => { clearTimeout(t); t = setTimeout(() => { f.q = e.target.value; load(); }, 300); } }), unitSel, areaSel, statusSel), host));
  await load();
}
