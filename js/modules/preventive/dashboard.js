import { api } from '../../core/api.js';
import { h, table, dt, barChart } from '../../core/ui.js';
import { stBadge, dueText, reportDialog, MONTHS } from './shared.js';

export default async function render(root, { meta, user, go }) {
  const f = { unit_id: '' };
  const host = h('div');
  const load = async () => {
    const d = await api.get('pm/dashboard', f);
    const k = d.kpi;
    const stat = (label, val, cls, to, q) => h('div', { class: `card stat ${cls}`, style: to ? 'cursor:pointer' : '', onclick: to ? () => go(to, q) : null }, h('b', {}, val), h('span', {}, label));
    const maxA = Math.max(1, ...d.by_area.map((a) => a.done + a.upcoming + a.overdue));
    host.replaceChildren(
      h('div', { class: 'grid g4 keep2' },
        stat('Jadwal PM aktif', k.total, '', 'pm/schedule'), stat('Sudah dikerjakan', k.done, 'c-green', 'pm/schedule', { status: 'done' }),
        stat('Akan datang', k.upcoming, '', 'pm/schedule', { status: 'upcoming' }), stat('Terlambat (outstanding)', k.overdue, 'c-red', 'pm/outstanding')),
      h('div', { class: 'grid g4 keep2', style: 'margin-top:1.5rem' },
        stat('Kepatuhan jadwal', `${k.compliance}%`, k.compliance >= 80 ? 'c-green' : 'c-orange'), stat(`Laporan ${d.year}`, k.reports_year, '', 'pm/list'),
        stat('Laporan bulan ini', k.reports_month, '', 'pm/list'), stat('Temuan belum jadi WO', k.findings, 'c-orange', 'pm/list', { findings: '1' })),
      h('div', { class: 'grid g2', style: 'margin-top:1.5rem' },
        h('div', { class: 'card' }, h('h2', {}, `Laporan PM per bulan · ${d.year}`), barChart(d.per_month.map((m) => ({ period: m.period, laporan: m.laporan })), { keys: ['laporan'], colors: ['#1478f0'] })),
        h('div', { class: 'card' }, h('h2', {}, 'Status jadwal per area'), h('div', { style: 'margin-top:8px;max-height:260px;overflow:auto' }, d.by_area.length ? d.by_area.map((a) => h('div', { class: 'bar-row' },
          h('span', {}, a.label), h('b', {}, `${a.done}/${a.done + a.upcoming + a.overdue}`),
          h('div', { class: 'bar', style: 'display:flex' }, h('i', { style: `width:${a.done / maxA * 100}%;background:var(--green)` }), h('i', { style: `width:${a.upcoming / maxA * 100}%;background:var(--blue)` }), h('i', { style: `width:${a.overdue / maxA * 100}%;background:var(--red)` })))) : h('p', { class: 'muted' }, 'Belum ada jadwal.')),
        h('div', { class: 'legend', style: 'margin-top:8px' }, h('span', {}, h('i', { style: 'background:var(--green)' }), 'Selesai'), h('span', {}, h('i', { style: 'background:var(--blue)' }), 'Akan datang'), h('span', {}, h('i', { style: 'background:var(--red)' }), 'Terlambat')))),
      h('div', { class: 'grid g2', style: 'margin-top:1.5rem' },
        h('div', { class: 'card' }, h('h2', {}, `Tugas minggu ini (${k.week})`), d.this_week.length ? table([
          { label: 'Equipment', render: (r) => h('div', {}, h('b', {}, r.equipment_name), h('div', { class: 'muted' }, r.area_name)) }, { label: 'Jatuh tempo', render: (r) => dueText(r) }, { label: 'PIC', key: 'pic' },
        ], d.this_week, { onRow: (r) => go('pm/form', { schedule_id: r.id }) }) : h('p', { class: 'muted' }, 'Tidak ada tugas jatuh tempo minggu ini.')),
        h('div', { class: 'card' }, h('h2', {}, 'Paling terlambat'), d.overdue.length ? table([
          { label: 'Equipment', render: (r) => h('div', {}, h('b', {}, r.equipment_name), h('div', { class: 'muted' }, r.area_name)) }, { label: 'Seharusnya', render: (r) => dueText(r) }, { label: '', render: (r) => stBadge(r.status) },
        ], d.overdue, { onRow: (r) => go('pm/form', { schedule_id: r.id }) }) : h('p', { class: 'muted' }, '✅ Tidak ada jadwal terlambat.'))),
      h('div', { class: 'card', style: 'margin-top:1.5rem' }, h('h2', {}, 'Laporan terbaru'), table([
        { label: 'Tanggal', render: (r) => dt(r.report_date, false) }, { label: 'Equipment', render: (r) => h('div', {}, h('b', {}, r.equipment_name), h('div', { class: 'muted' }, r.area_name)) }, { label: 'Teknisi', key: 'teknisi' },
        { label: 'Temuan', render: (r) => (r.items_no ? h('span', { class: 'badge prio-critical' }, `${r.items_no} tidak OK`) : h('span', { class: 'badge done' }, 'OK')) },
      ], d.recent, { onRow: (r) => reportDialog(r.id, { user, go, onChange: load }) })));
  };
  root.append(h('div', { class: 'page' }, h('div', { class: 'page-head' }, h('h1', {}, 'Dashboard PM'),
    h('select', { 'aria-label': 'Unit', onchange: (e) => { f.unit_id = e.target.value; load(); } }, h('option', { value: '' }, 'Semua unit'), meta.units.map((u) => h('option', { value: u.id }, u.name)))), host));
  await load();
}
