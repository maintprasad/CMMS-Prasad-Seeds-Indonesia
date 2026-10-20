import { api } from '../../core/api.js';
import { h, table, dt } from '../../core/ui.js';
import { rp, num, trxBadge, trxWhere } from './shared.js';

const bars = (rows, label, value, fmt = num, color = 'var(--green)') => {
  const max = Math.max(1, ...rows.map((r) => Number(r[value])));
  return rows.length ? rows.map((r) => h('div', { class: 'bar-row' }, h('span', {}, label(r)), h('b', { class: 'mono' }, fmt(r[value])),
    h('div', { class: 'bar' }, h('i', { style: `width:${(r[value] / max) * 100}%;background:${color}` })))) : h('p', { class: 'muted' }, 'Belum ada data.');
};

export default async function render(root, { go }) {
  const d = await api.get('mw/dashboard');
  const s = d.summary;
  const stat = (label, val, cls, to, q) => h('div', { class: `card stat ${cls}`, style: to ? 'cursor:pointer' : '', onclick: to ? () => go(to, q) : null }, h('b', {}, val), h('span', {}, label));
  root.append(h('div', { class: 'page' },
    h('div', { class: 'page-head' }, h('h1', {}, 'Dashboard MaintWare')),
    h('div', { class: 'grid g4 keep2' },
      stat('Total item', num(s.items), '', 'mw/items'), stat('Nilai stok', rp(s.value), 'c-green'),
      stat('Stok di bawah minimum', s.low, 'c-orange', 'mw/items', { low: '1' }), stat('Stok habis', s.empty, 'c-red')),
    h('div', { class: 'grid g2', style: 'margin-top:14px' },
      stat(`IN bulan ini · ${s.in_tx} transaksi`, num(s.in_qty), 'c-green', 'mw/log'), stat(`OUT bulan ini · ${s.out_tx} transaksi`, num(s.out_qty), 'c-red', 'mw/log')),
    h('div', { class: 'grid g2', style: 'margin-top:14px' },
      h('div', { class: 'card' }, h('h2', {}, 'Nilai stok per type'), h('div', { style: 'margin-top:8px' }, bars(d.by_type, (r) => `${r.label} (${r.items} item)`, 'value', rp, 'var(--blue)'))),
      h('div', { class: 'card' }, h('h2', {}, 'Item per class'), h('div', { style: 'margin-top:8px' }, bars(d.by_class, (r) => r.label, 'items')))),
    h('div', { class: 'grid g2', style: 'margin-top:14px' },
      h('div', { class: 'card' }, h('h2', {}, 'Paling banyak keluar · 90 hari'), h('div', { style: 'margin-top:8px' }, bars(d.top_out, (r) => `${r.id} · ${r.name}`, 'qty', num, 'var(--red)'))),
      h('div', { class: 'card' }, h('h2', {}, 'Pemakaian per equipment · 90 hari'),
        d.by_equipment.length ? table([{ label: 'Equipment', render: (r) => h('div', {}, h('b', {}, r.name), h('div', { class: 'muted' }, r.area)) }, { label: 'Transaksi', key: 'tx', cls: 'num' }, { label: 'Qty', cls: 'num', render: (r) => num(r.qty) }],
          d.by_equipment, { onRow: (r) => go('eq/list', { id: r.id }) }) : h('p', { class: 'muted' }, 'Belum ada OUT yang dikaitkan ke equipment. Pilih equipment saat Stock OUT, atau pakai part dari Work Order.'))),
    h('div', { class: 'grid g2', style: 'margin-top:14px' },
      h('div', { class: 'card' }, h('h2', {}, 'Perlu reorder'), d.low_items.length ? table([{ label: 'Item', render: (r) => h('span', {}, h('span', { class: 'mono' }, r.id), ' ', r.name) }, { label: 'Stok', cls: 'num', render: (r) => `${num(r.qty)} / ${num(r.min_stock)}` }], d.low_items, { onRow: () => go('mw/reorder') })
        : h('p', { class: 'muted' }, 'Semua stok aman.')),
      h('div', { class: 'card' }, h('h2', {}, 'Transaksi terbaru'), table([
        { label: 'Waktu', render: (t) => dt(t.trx_date) }, { label: 'Item', render: (t) => `${t.item_id} · ${t.item_name}` }, { label: 'TX', render: (t) => trxBadge(t.trx_type) },
        { label: 'Qty', cls: 'num', render: (t) => num(t.qty) }], d.recent, { onRow: () => go('mw/log') })))));
}
