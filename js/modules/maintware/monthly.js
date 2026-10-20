import { api } from '../../core/api.js';
import { h, table, dt, barChart, downloadCsv } from '../../core/ui.js';
import { num, trxBadge, trxWhere, MONTHS } from './shared.js';

export default async function render(root) {
  const now = new Date();
  const st = { year: now.getFullYear(), month: now.getMonth() + 1, tx: '', rank: 'OUT' };
  const out = h('div');
  let rows = [];

  const shift = (d) => { st.month += d; if (st.month > 12) { st.month = 1; st.year++; } if (st.month < 1) { st.month = 12; st.year--; } load(); };
  const load = async () => {
    if (![...ysel.options].some((o) => Number(o.value) === st.year)) ysel.append(h('option', {}, st.year));
    msel.value = st.month; ysel.value = st.year;
    const r = await api.get('mw/monthly', { year: st.year, month: st.month });
    rows = st.tx ? r.rows.filter((t) => t.trx_type === st.tx) : r.rows;
    draw();
  };
  const sum = (tx) => rows.filter((t) => t.trx_type === tx).reduce((a, t) => a + Number(t.qty), 0);
  const cnt = (tx) => rows.filter((t) => t.trx_type === tx).length;

  const draw = () => {
    const days = new Date(st.year, st.month, 0).getDate();
    const daily = Array.from({ length: days }, (_, i) => ({ period: `${String(st.month).padStart(2, '0')}-${String(i + 1).padStart(2, '0')}`, in: 0, out: 0 }));
    rows.forEach((t) => { const d = Number(t.trx_date.slice(8, 10)) - 1; daily[d][t.trx_type === 'IN' ? 'in' : 'out'] += Number(t.qty); });
    const byType = {};
    rows.filter((t) => t.trx_type === 'OUT').forEach((t) => { const k = t.item_type || '—'; byType[k] = (byType[k] || 0) + Number(t.qty); });
    const rank = {};
    rows.filter((t) => t.trx_type === st.rank).forEach((t) => { (rank[t.item_id] ||= { id: t.item_id, name: t.item_name, qty: 0 }).qty += Number(t.qty); });
    const top = Object.values(rank).sort((a, b) => b.qty - a.qty).slice(0, 10);
    const max = Math.max(1, ...top.map((r) => r.qty));
    const maxType = Math.max(1, ...Object.values(byType));
    out.replaceChildren(
      h('div', { class: 'grid g3' },
        h('div', { class: 'card stat c-green' }, h('b', {}, num(sum('IN'))), h('span', {}, `Total IN · ${cnt('IN')} transaksi`)),
        h('div', { class: 'card stat c-red' }, h('b', {}, num(sum('OUT'))), h('span', {}, `Total OUT · ${cnt('OUT')} transaksi`)),
        h('div', { class: 'card stat' }, h('b', {}, new Set(rows.map((t) => t.item_id)).size), h('span', {}, 'Item aktif'))),
      h('div', { class: 'grid g2', style: 'margin-top:14px' },
        h('div', { class: 'card' }, h('div', { class: 'page-head' }, h('h2', {}, 'Pergerakan harian'), h('span', { class: 'sp' }), h('div', { class: 'legend' }, h('span', {}, h('i', { style: 'background:#30cf46' }), 'IN'), h('span', {}, h('i', { style: 'background:#f83f32' }), 'OUT'))),
          barChart(daily, { keys: ['in', 'out'], colors: ['#30cf46', '#f83f32'] })),
        h('div', { class: 'card' }, h('h2', {}, 'OUT per type'), Object.keys(byType).length ? Object.entries(byType).map(([k, v]) => h('div', { class: 'bar-row' }, h('span', {}, k), h('b', { class: 'mono' }, num(v)), h('div', { class: 'bar' }, h('i', { style: `width:${v / maxType * 100}%;background:var(--blue)` })))) : h('p', { class: 'muted' }, 'Tidak ada OUT.'))),
      h('div', { class: 'card', style: 'margin-top:14px' },
        h('div', { class: 'page-head' }, h('h2', {}, 'Top 10 item'), h('span', { class: 'sp' }),
          ['OUT', 'IN'].map((k) => h('button', { class: `chip${st.rank === k ? ' on' : ''}`, onclick: () => { st.rank = k; draw(); } }, k))),
        top.length ? top.map((r, i) => h('div', { class: 'bar-row' }, h('span', {}, `${i + 1}. `, h('span', { class: 'mono' }, r.id), ' ', r.name), h('b', { class: 'mono' }, num(r.qty)),
          h('div', { class: 'bar' }, h('i', { style: `width:${r.qty / max * 100}%;background:var(--${st.rank === 'OUT' ? 'red' : 'green'})` })))) : h('p', { class: 'muted' }, `Tidak ada transaksi ${st.rank} bulan ini.`)),
      h('h2', { style: 'margin:18px 0 8px' }, `Detail transaksi (${rows.length})`),
      table([
        { label: 'Waktu', render: (t) => h('span', { class: 'mono' }, dt(t.trx_date)) }, { label: 'Kode', render: (t) => h('span', { class: 'mono' }, t.item_id) }, { label: 'Item', key: 'item_name' },
        { label: 'Type', key: 'item_type' }, { label: 'TX', render: (t) => trxBadge(t.trx_type) }, { label: 'Qty', cls: 'num', render: (t) => `${num(t.qty)} ${t.uom}` },
        { label: 'PIC', key: 'pic' }, { label: 'Dipakai di', render: (t) => trxWhere(t) || '—' }, { label: 'Catatan', render: (t) => h('span', { class: 'muted' }, t.notes || '—') },
      ], rows, { empty: 'Tidak ada transaksi bulan ini.' }));
  };

  const msel = h('select', { 'aria-label': 'Bulan', onchange: (e) => { st.month = Number(e.target.value); load(); } }, MONTHS.map((m, i) => h('option', { value: i + 1 }, m)));
  const ysel = h('select', { 'aria-label': 'Tahun', onchange: (e) => { st.year = Number(e.target.value); load(); } }, [0, 1, 2, 3].map((k) => h('option', {}, now.getFullYear() + 1 - k)));
  msel.value = st.month; ysel.value = st.year;
  root.append(h('div', { class: 'page', id: 'monthly-print' },
    h('div', { class: 'page-head' }, h('h1', {}, 'Monthly Report'), h('span', { class: 'sp' }),
      h('button', { class: 'btn', onclick: () => downloadCsv(`maintware-${st.year}-${String(st.month).padStart(2, '0')}.csv`, ['Waktu', 'Kode', 'Item', 'Type', 'TX', 'Qty', 'UOM', 'Sisa', 'PIC', 'Unit', 'Area', 'Equipment', 'WO', 'Catatan'],
        rows.map((t) => [t.trx_date, t.item_id, t.item_name, t.item_type, t.trx_type, t.qty, t.uom, t.stock_after, t.pic, t.unit_name, t.area_name, t.equipment_name, t.wo_id, t.notes])) }, '⬇ CSV'),
      h('a', { class: 'btn', href: `#/rp/center?modules=mw&auto=1&from=${st.year}-${String(st.month).padStart(2, '0')}-01&to=${st.year}-${String(st.month).padStart(2, '0')}-${String(new Date(st.year, st.month, 0).getDate()).padStart(2, '0')}` }, '📊 Excel / PowerPoint'),
      h('button', { class: 'btn', onclick: () => window.print() }, '🖨 Cetak / PDF')),
    h('div', { class: 'toolbar' }, h('button', { class: 'btn sm', onclick: () => shift(-1) }, '‹'), msel, ysel, h('button', { class: 'btn sm', onclick: () => shift(1) }, '›'),
      h('select', { 'aria-label': 'Tipe', onchange: (e) => { st.tx = e.target.value; load(); } }, h('option', { value: '' }, 'IN & OUT'), h('option', {}, 'IN'), h('option', {}, 'OUT'))),
    out));
  await load();
}
