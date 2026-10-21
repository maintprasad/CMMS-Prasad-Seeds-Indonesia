import { api } from '../../core/api.js';
import { h, table, dt, downloadCsv } from '../../core/ui.js';
import { num, trxBadge, trxWhere, mwMeta } from './shared.js';

const PAGE = 50;

export default async function render(root) {
  const m = await mwMeta();
  const f = { q: '', tx: '', pic: '', from: '', to: '' };
  let page = 0, last = [];
  const host = h('div');
  const pager = h('div', { class: 'toolbar' });
  const count = h('span', { class: 'muted' });

  const load = async () => {
    const r = await api.get('mw/log', { ...f, limit: PAGE, offset: page * PAGE });
    last = r.rows;
    count.textContent = `${r.total} transaksi`;
    host.replaceChildren(table([
      { label: 'Waktu', render: (t) => h('span', { class: 'mono' }, dt(t.trx_date)) },
      { label: 'Unit', render: (t) => t.unit_name || '—' },
      { label: 'Kode', render: (t) => h('span', { class: 'mono' }, t.item_id) },
      { label: 'Item', render: (t) => h('div', {}, t.item_name, h('div', { class: 'muted' }, [t.brand, t.item_type].filter(Boolean).join(' · '))) },
      { label: 'TX', render: (t) => trxBadge(t.trx_type) },
      { label: 'Qty', cls: 'num', render: (t) => h('span', { style: `color:var(--${t.trx_type === 'IN' ? 'green' : 'red'});font-weight:600` }, `${t.trx_type === 'IN' ? '+' : '−'}${num(t.qty)} ${t.uom}`) },
      { label: 'Sisa', cls: 'num', render: (t) => num(t.stock_after) },
      { label: 'PIC', key: 'pic' },
      { label: 'Dipakai di', render: (t) => trxWhere(t) || '—' },
      { label: 'Catatan', render: (t) => h('span', { class: 'muted' }, [t.wo_id, t.notes].filter(Boolean).join(' · ') || '—') },
      { label: 'Sumber', render: (t) => h('span', { class: 'muted', title: t.source.startsWith('SHEETS-R') ? 'Dipulihkan dari baris Sheets yang kolomnya bergeser' : '' }, t.source) },
    ], r.rows, { empty: 'Tidak ada transaksi.' }));
    const pages = Math.max(1, Math.ceil(r.total / PAGE));
    pager.replaceChildren(h('span', { class: 'muted' }, `halaman ${page + 1}/${pages}`),
      h('button', { class: 'btn sm', disabled: page === 0, onclick: () => { page--; load(); } }, '‹'),
      h('button', { class: 'btn sm', disabled: page + 1 >= pages, onclick: () => { page++; load(); } }, '›'));
  };
  const reload = () => { page = 0; load(); };
  const exportCsv = async () => {
    const r = await api.get('mw/log', { ...f, limit: 2000 });
    downloadCsv('stock-log-maintware.csv', ['Waktu', 'Kode', 'Item', 'Brand', 'Type', 'TX', 'Qty', 'UOM', 'Sisa', 'PIC', 'Unit', 'Area', 'Equipment', 'WO', 'Catatan'],
      r.rows.map((t) => [t.trx_date, t.item_id, t.item_name, t.brand, t.item_type, t.trx_type, t.qty, t.uom, t.stock_after, t.pic, t.unit_name, t.area_name, t.equipment_name, t.wo_id, t.notes]));
  };
  let t;
  const inp = (k, type) => h('input', { type, 'aria-label': k, onchange: (e) => { f[k] = e.target.value; reload(); } });
  root.append(h('div', { class: 'page' },
    h('div', { class: 'page-head' }, h('h1', {}, 'Stock Log'), count, h('span', { class: 'sp' }), h('button', { class: 'btn', onclick: exportCsv }, '⬇ CSV')),
    h('div', { class: 'toolbar' },
      h('input', { class: 'grow', type: 'search', placeholder: 'Cari kode, item, PIC, catatan, equipment…', oninput: (e) => { clearTimeout(t); t = setTimeout(() => { f.q = e.target.value; reload(); }, 300); } }),
      h('select', { 'aria-label': 'Tipe', onchange: (e) => { f.tx = e.target.value; reload(); } }, h('option', { value: '' }, 'IN & OUT'), h('option', {}, 'IN'), h('option', {}, 'OUT')),
      h('select', { 'aria-label': 'PIC', onchange: (e) => { f.pic = e.target.value; reload(); } }, h('option', { value: '' }, 'Semua PIC'), m.pics.map((p) => h('option', {}, p))),
      inp('from', 'date'), '–', inp('to', 'date')),
    host, pager));
  await load();
}
