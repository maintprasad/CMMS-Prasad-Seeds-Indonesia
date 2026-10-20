import { api } from '../../core/api.js';
import { h, table, barChart } from '../../core/ui.js';
import { PERIODS, MONTHS } from './shared.js';

export default async function render(root, { meta }) {
  const types = (await api.get('pm/types')).types;
  const f = { year: String(new Date().getFullYear()), month: '', unit_id: '', pm_type: '', periode: '' };
  const host = h('div');
  const pct = (a, b) => (b ? Math.round((a / b) * 100) : 0);
  const load = async () => {
    const d = await api.get('pm/resume', f);
    const t = d.totals;
    const stat = (label, val, cls = '') => h('div', { class: `card stat ${cls}` }, h('b', {}, val), h('span', {}, label));
    const months = Array.from({ length: 12 }, (_, i) => ({ period: MONTHS[i].slice(0, 3), laporan: Number(d.by_month.find((m) => Number(m.month) === i + 1)?.reports ?? 0) }));
    host.replaceChildren(
      h('div', { class: 'grid g4 keep2' }, stat('Laporan PM', t.reports), stat('Equipment dikerjakan', t.equipment, 'c-green'), stat('Butir checklist OK', `${pct(t.ok, t.items)}%`, 'c-green'), stat('Butir tidak OK', t.no, t.no ? 'c-red' : '')),
      h('div', { class: 'grid g2', style: 'margin-top:1.5rem' }, stat('Jadwal PM selesai', `${t.schedules_done}/${t.schedules}`, 'c-purple'), stat('Kepatuhan jadwal', `${pct(t.schedules_done, t.schedules)}%`, pct(t.schedules_done, t.schedules) >= 80 ? 'c-green' : 'c-orange')),
      h('div', { class: 'card', style: 'margin-top:1.5rem' }, h('h2', {}, `Laporan per bulan · ${f.year || 'semua tahun'}`), barChart(months, { keys: ['laporan'], colors: ['#1478f0'] })),
      h('div', { class: 'grid g2', style: 'margin-top:1.5rem' },
        h('div', { class: 'card' }, h('h2', {}, 'Per tipe equipment'), table([{ label: 'Tipe', key: 'label' }, { label: 'Laporan', key: 'reports', cls: 'num' }, { label: 'Checklist OK', cls: 'num', render: (r) => `${pct(r.ok, r.items)}%` }, { label: 'Tidak OK', key: 'no', cls: 'num' }, { label: 'Sparepart', key: 'parts', cls: 'num' }], d.by_type, { empty: 'Tidak ada laporan.' })),
        h('div', {}, h('div', { class: 'card' }, h('h2', {}, 'Per unit'), table([{ label: 'Unit', key: 'label' }, { label: 'Laporan', key: 'reports', cls: 'num' }, { label: 'Equipment', key: 'equipment', cls: 'num' }], d.by_unit)),
          h('div', { class: 'card' }, h('h2', {}, 'Teknisi tersering'), table([{ label: 'Teknisi', key: 'label' }, { label: 'Laporan', key: 'reports', cls: 'num' }], d.by_teknisi)))));
  };
  const sel = (key, label, opts, def = '') => { const s = h('select', { 'aria-label': label, onchange: (e) => { f[key] = e.target.value; load(); } }, h('option', { value: '' }, label), opts.map(([v, l]) => h('option', { value: v }, l))); s.value = def; return s; };
  const years = (await api.get('pm/resume', f)).years;
  root.append(h('div', { class: 'page' }, h('div', { class: 'page-head' }, h('h1', {}, 'Resume Laporan')),
    h('div', { class: 'toolbar' }, sel('year', 'Semua tahun', [...new Set([f.year, ...years])].map((y) => [y, y]), f.year), sel('month', 'Semua bulan', MONTHS.map((m, i) => [i + 1, m])),
      sel('unit_id', 'Semua unit', meta.units.map((u) => [u.id, u.name])), sel('pm_type', 'Semua tipe', types.map((x) => [x.code, x.label])), sel('periode', 'Semua periode', PERIODS.map((p) => [p, p]))), host));
  await load();
}
