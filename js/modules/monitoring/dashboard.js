import { api } from '../../core/api.js';
import { h, table, dt, barChart } from '../../core/ui.js';
import { stBadge, sessionDialog } from './shared.js';

const bars = (rows, label, key) => {
  const max = Math.max(1, ...rows.map((r) => Number(r[key])));
  return rows.length ? rows.map((r) => h('div', { class: 'bar-row' }, h('span', {}, label(r)), h('b', { class: 'mono' }, r[key]), h('div', { class: 'bar' }, h('i', { style: `width:${(r[key] / max) * 100}%;background:var(--orange)` }))))
    : h('p', { class: 'muted' }, 'Belum ada temuan.');
};

export default async function render(root, { user, go }) {
  const d = await api.get('dm/dashboard');
  const k = d.kpi;
  const stat = (label, val, cls, to, q) => h('div', { class: `card stat ${cls}`, style: to ? 'cursor:pointer' : '', onclick: to ? () => go(to, q) : null }, h('b', {}, val), h('span', {}, label));
  const coverage = k.monitorable ? Math.round((k.covered_7d / k.monitorable) * 100) : 0;
  root.append(h('div', { class: 'page' }, h('div', { class: 'page-head' }, h('h1', {}, 'Dashboard Daily Monitoring')),
    h('div', { class: 'grid g4 keep2' },
      stat('Sesi hari ini', k.today, 'c-green', 'dm/monitor'), stat('Sesi bulan ini', k.month, '', 'dm/history'),
      stat('Finding aktif', k.findings_active, 'c-orange', 'dm/findings'), stat('Finding ALERT aktif', k.findings_alert, 'c-red', 'dm/findings')),
    h('div', { class: 'grid g4 keep2', style: 'margin-top:14px' },
      stat(`Cakupan 7 hari (${k.covered_7d}/${k.monitorable} equipment)`, `${coverage}%`, 'c-green', 'dm/monitor'), stat('Equipment belum pernah dimonitor', k.never, 'c-purple', 'dm/monitor'),
      stat('Nilai OK / WARNING / ALERT (seluruh riwayat)', `${k.ok} / ${k.warning} / ${k.alert}`, ''), stat('Total sesi', k.total, '', 'dm/history')),
    h('div', { class: 'card', style: 'margin-top:14px' }, h('h2', {}, 'Sesi monitoring · 30 hari'), barChart(d.trend.map((t) => ({ date: t.date, sesi: t.sessions })), { keys: ['sesi'], colors: ['#1478f0'] })),
    h('div', { class: 'grid g2', style: 'margin-top:14px' },
      h('div', { class: 'card' }, h('h2', {}, 'Status sesi per unit'), d.by_unit.length ? table([{ label: 'Unit', key: 'label' }, { label: 'OK', key: 'ok', cls: 'num' }, { label: 'WARNING', key: 'warning', cls: 'num' }, { label: 'ALERT', key: 'alert', cls: 'num' }], d.by_unit) : h('p', { class: 'muted' }, 'Belum ada sesi.')),
      h('div', { class: 'card' }, h('h2', {}, 'Perlu dimonitor (> 7 hari / belum pernah)'), d.overdue.length ? table([
        { label: 'Equipment', render: (r) => h('div', {}, h('b', {}, r.name), h('div', { class: 'muted' }, r.area)) }, { label: 'Terakhir', render: (r) => (r.last_date ? dt(r.last_date, false) : h('span', { class: 'late' }, 'belum pernah')) },
      ], d.overdue, { onRow: (r) => go('dm/entry', { equipment_id: r.id }) }) : h('p', { class: 'muted' }, 'Semua equipment sudah dimonitor dalam 7 hari terakhir.'))),
    h('div', { class: 'grid g2', style: 'margin-top:14px' },
      h('div', { class: 'card' }, h('h2', {}, 'Equipment paling banyak temuan'), h('div', { style: 'margin-top:8px' }, bars(d.top_equipment, (r) => `${r.name} · ${r.area}`, 'c'))),
      h('div', { class: 'card' }, h('h2', {}, 'Parameter paling sering bermasalah'), h('div', { style: 'margin-top:8px' }, bars(d.top_params, (r) => r.label, 'c')))),
    h('div', { class: 'card', style: 'margin-top:14px' }, h('h2', {}, 'Sesi terbaru'), table([
      { label: 'Tanggal', render: (s) => `${dt(s.session_date, false)} ${s.start_time ?? ''}` }, { label: 'Equipment', render: (s) => h('div', {}, h('b', {}, s.equipment_name), h('div', { class: 'muted' }, s.area_name)) },
      { label: 'PIC', key: 'pic' }, { label: 'Status', render: (s) => stBadge(s.status) },
    ], d.recent, { onRow: (s) => sessionDialog(s.id, { user, go }) }))));
}
