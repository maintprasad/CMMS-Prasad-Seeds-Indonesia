import { api } from '../../core/api.js';
import { h, table, dt, dur, hours, barChart } from '../../core/ui.js';

const today = () => new Date().toISOString().slice(0, 10);
const monthsAgo = (n) => { const d = new Date(); d.setMonth(d.getMonth() - n, 1); return d.toISOString().slice(0, 10); };

/** Kerangka halaman laporan: filter tanggal/unit + area hasil yang dimuat ulang saat filter berubah. */
function reportPage(root, title, { defaultFrom = monthsAgo(5), withGroup = false, withUnit = true, meta, draw, extra }) {
  const f = { from: defaultFrom, to: today(), unit_id: '', group: 'month' };
  const out = h('div');
  const run = async () => out.replaceChildren(...[].concat(await draw({ ...f })));
  const inp = (k, type) => h('input', { type, value: f[k], onchange: (e) => { f[k] = e.target.value; run(); }, 'aria-label': k });
  root.append(h('div', { class: 'page' }, h('div', { class: 'page-head' }, h('h1', {}, title), h('span', { class: 'sp' }), extra?.(f)),
    h('div', { class: 'toolbar' }, h('span', { class: 'muted' }, 'Periode'), inp('from', 'date'), '–', inp('to', 'date'),
      withUnit ? h('select', { onchange: (e) => { f.unit_id = e.target.value; run(); } }, h('option', { value: '' }, 'Semua unit'), meta.units.map((u) => h('option', { value: u.id }, u.name))) : null,
      withGroup ? h('select', { onchange: (e) => { f.group = e.target.value; run(); } }, h('option', { value: 'month' }, 'Per bulan'), h('option', { value: 'day' }, 'Per hari')) : null), out));
  return run();
}

/** in / out: WO masuk vs keluar. */
export const comparison = (root, { meta }) => reportPage(root, 'in / out — WO masuk vs selesai', {
  meta, withGroup: true,
  draw: async (f) => {
    const { rows } = await api.get('wo/comparison', f);
    if (!rows.length) return h('div', { class: 'empty' }, 'Tidak ada data pada periode ini.');
    const sum = (k) => rows.reduce((a, r) => a + r[k], 0);
    return [
      h('div', { class: 'card' }, h('div', { class: 'legend', style: 'margin-bottom:8px' }, h('span', {}, h('i', { style: 'background:#1478f0' }), `Masuk (${sum('in')})`), h('span', {}, h('i', { style: 'background:#30cf46' }), `Selesai (${sum('out')})`)),
        barChart(rows, { keys: ['in', 'out'], colors: ['#1478f0', '#30cf46'] })),
      h('div', { style: 'height:14px' }),
      table([{ label: 'Periode', key: 'period' }, { label: 'Masuk', key: 'in', cls: 'num' }, { label: 'Selesai', key: 'out', cls: 'num' },
        { label: 'Selisih', render: (r) => r.in - r.out, cls: 'num' }], rows.slice().reverse()),
    ];
  },
});

/** MTTR / MTBF per equipment. */
export const mttr = (root, { meta, go }) => reportPage(root, 'MTTR / MTBF', {
  meta,
  draw: async (f) => {
    const { rows } = await api.get('wo/mttr', f);
    return [h('p', { class: 'muted' }, 'Dihitung dari WO bertipe Troubleshooting dan Corrective. MTTR = rata-rata durasi kerja; MTBF = rata-rata jarak antar kerusakan (butuh ≥ 2 kerusakan).'),
      table([
        { label: 'Equipment', render: (r) => h('div', {}, h('b', {}, r.equipment_name), h('div', { class: 'muted' }, r.area_name)) },
        { label: 'Kerusakan', key: 'failures', cls: 'num' }, { label: 'Selesai', key: 'repaired', cls: 'num' },
        { label: 'MTTR', render: (r) => hours(r.mttr_hours), cls: 'num' }, { label: 'MTBF', render: (r) => hours(r.mtbf_hours), cls: 'num' },
        { label: 'Total perbaikan', render: (r) => hours(r.downtime_hours), cls: 'num' },
      ], rows, { empty: 'Belum ada WO kerusakan pada periode ini.', onRow: (r) => go('eq/list', { id: r.equipment_id }) })];
  },
});

/** Equipment History lintas WO (apa/kapan/di mana/siapa/bagaimana/kenapa). */
export async function equipHistory(root, { go }) {
  const list = h('div');
  const load = async (q = '') => {
    const { rows } = await api.get('wo/history', { q });
    list.replaceChildren(rows.length ? h('div', {}, rows.map((r) => h('div', { class: 'card' },
      h('div', { class: 'page-head' }, h('b', {}, r.equipment_name ?? '—'), h('a', { href: `#/wo/detail?id=${encodeURIComponent(r.wo_id)}`, class: 'mono' }, r.wo_id), h('span', { class: 'sp' }), h('small', { class: 'muted' }, `diisi ${r.updated_by} · ${dt(r.updated_at)}`)),
      h('dl', { class: 'kv' }, [['Apa', r.what_text], ['Kapan', r.when_text], ['Di mana', r.where_text], ['Siapa', r.who_text], ['Bagaimana', r.how_text], ['Kenapa', r.why_text]]
        .filter(([, v]) => v).map(([k, v]) => [h('dt', {}, k), h('dd', {}, v)]))))) : h('div', { class: 'empty' }, 'Belum ada Equipment History. Leader/SPV mengisinya dari tab “Equipment History” di detail WO.'));
  };
  let t;
  root.append(h('div', { class: 'page' }, h('div', { class: 'page-head' }, h('h1', {}, 'Equipment History')),
    h('div', { class: 'toolbar' }, h('input', { class: 'grow', type: 'search', placeholder: 'Cari equipment / masalah…', oninput: (e) => { clearTimeout(t); t = setTimeout(() => load(e.target.value), 300); } })), list));
  await load();
}

/** Rating teknisi. */
export const rating = (root, { meta }) => reportPage(root, 'Rating Teknisi', {
  meta, withUnit: false, defaultFrom: monthsAgo(11),
  draw: async (f) => {
    const { rows } = await api.get('wo/technicians', f);
    const sorted = rows.filter((r) => Number(r.rated) > 0).sort((a, b) => b.avg_rating - a.avg_rating);
    return table([
      { label: '#', render: (r) => sorted.indexOf(r) + 1 }, { label: 'Teknisi', render: (r) => h('b', {}, r.name) },
      { label: 'Rating', render: (r) => h('span', { class: 'stars' }, '★'.repeat(Math.round(r.avg_rating)) + '☆'.repeat(5 - Math.round(r.avg_rating)), h('small', { class: 'muted', style: 'letter-spacing:0' }, ` ${Number(r.avg_rating).toFixed(2)}`)) },
      { label: 'Dinilai', key: 'rated', cls: 'num' }, { label: 'WO closed', key: 'closed', cls: 'num' },
    ], sorted, { empty: 'Belum ada WO yang dinilai pada periode ini.' });
  },
});

