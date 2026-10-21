import { api } from '../../core/api.js';
import { h, table, toast } from '../../core/ui.js';
import { rp, num, trxBadge } from './shared.js';
import { roDialog } from './purchase.js';

export default async function render(root, { user }) {
  const { rows } = await api.get('mw/reorder');
  const est = rows.reduce((a, i) => a + i.gap * (Number(i.price) || 0), 0);
  const createRO = () => {
    // jumlah beli = selisih ke min stok
    const items = rows.map((i) => ({ item_id: i.id, name: i.name, spec: i.spec || '', qty: Math.ceil(i.gap), unit_price: Number(i.price) || 0 }));
    roDialog(null, () => toast('RO dibuat — lihat menu Purchase Request.'), user, items);
  };
  root.append(h('div', { class: 'page' },
    h('div', { class: 'page-head' }, h('h1', {}, 'Reorder List'), h('span', { class: 'muted' }, 'item dengan stok di bawah minimum'), h('span', { class: 'sp' }),
      rows.length ? h('button', { class: 'btn primary', onclick: createRO }, '🛍 Buat RO dari daftar ini') : null),
    h('div', { class: 'grid g2' }, h('div', { class: 'card stat c-red' }, h('b', {}, rows.length), h('span', {}, 'Item perlu reorder')),
      h('div', { class: 'card stat' }, h('b', {}, rp(est)), h('span', {}, 'Estimasi biaya (selisih ke min stok × harga)'))),
    h('div', { style: 'height:14px' }),
    table([
      { label: 'Kode', render: (i) => h('span', { class: 'mono' }, i.id) },
      { label: 'Unit', render: (i) => i.unit_name || '—' },
      { label: 'Item', render: (i) => h('div', {}, h('b', {}, i.name), h('div', { class: 'muted' }, [i.brand, i.spec].filter(Boolean).join(' · ') || '—')) },
      { label: 'Type', key: 'item_type' },
      { label: 'Stok', cls: 'num', render: (i) => h('span', { style: `color:var(--red);font-weight:700` }, `${num(i.qty)} ${i.uom}`) },
      { label: 'Min', cls: 'num', render: (i) => num(i.min_stock) },
      { label: 'Kurang', cls: 'num', render: (i) => num(i.gap) },
      { label: 'Est. biaya', cls: 'num', render: (i) => rp(i.gap * (Number(i.price) || 0)) },
      { label: 'Kondisi', render: (i) => (Number(i.qty) <= 0 ? h('span', { class: 'badge prio-critical' }, 'HABIS') : h('span', { class: 'badge open' }, 'Menipis')) },
    ], rows, { empty: '✅ Semua stok aman — tidak ada item di bawah minimum.' })));
}
