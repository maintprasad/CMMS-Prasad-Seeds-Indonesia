import { api } from '../../core/api.js';
import { h, barChart, dur, table } from '../../core/ui.js';

export default async function render(root, { go }) {
  const s = await api.get('wo/stats');
  const n = (k) => Number(s.by_status[k] || 0);
  const total = Object.values(s.by_status).reduce((a, b) => a + Number(b), 0);
  const stat = (label, val, cls, status) => h('div', { class: `card stat ${cls}`, style: 'cursor:pointer', onclick: () => go('wo/list', status ? { status } : {}) }, h('b', {}, val), h('span', {}, label));
  const maxType = Math.max(1, ...s.by_type.map((t) => Number(t.c)));

  root.append(h('div', { class: 'page' },
    h('div', { class: 'page-head' }, h('h1', {}, 'Dashboard Work Order')),
    h('div', { class: 'grid g4 keep2' },
      stat('Total WO', total, ''), stat('Open', n('Open'), 'c-orange', 'Open'),
      stat('In Progress', n('In Progress'), '', 'In Progress'), stat('Menunggu verifikasi', n('Pending Verification'), 'c-purple', 'Pending Verification')),
    h('div', { class: 'grid g4 keep2', style: 'margin-top:14px' },
      stat('Closed', n('Closed'), 'c-green', 'Closed'), stat('Dibatalkan', n('Cancelled'), 'c-red', 'Cancelled'),
      h('div', { class: 'card stat' }, h('b', {}, s.avg_seconds ? dur(s.avg_seconds) : '—'), h('span', {}, 'Rata-rata durasi kerja per WO'))),
    h('div', { class: 'card', style: 'margin-top:14px' },
      h('div', { class: 'page-head' }, h('h2', {}, 'WO masuk vs selesai · 30 hari'), h('span', { class: 'sp' }),
        h('div', { class: 'legend' }, h('span', {}, h('i', { style: 'background:#1478f0' }), 'Masuk'), h('span', {}, h('i', { style: 'background:#30cf46' }), 'Selesai'))),
      barChart(s.series, { keys: ['in', 'out'], colors: ['#1478f0', '#30cf46'] })),
    h('div', { class: 'grid g2', style: 'margin-top:14px' },
      h('div', { class: 'card' }, h('h2', {}, 'Berdasarkan tipe'), s.by_type.map((t) => h('div', { class: 'bar-row' }, h('span', {}, t.type), h('b', { class: 'mono' }, t.c), h('div', { class: 'bar' }, h('i', { style: `width:${(t.c / maxType) * 100}%` }))))),
      h('div', { class: 'card' }, h('h2', {}, 'Equipment paling sering WO'), table([
        { label: 'Equipment', key: 'name' }, { label: 'WO', key: 'c', cls: 'num' },
      ], s.top_equipment, { onRow: (r) => go('eq/list', { id: r.id }) })))));
}
