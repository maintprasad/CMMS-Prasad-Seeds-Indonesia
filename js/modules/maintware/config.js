import { api } from '../../core/api.js';
import { h, table } from '../../core/ui.js';

const SRC = {
  APP: 'Dicatat langsung di aplikasi', WO: 'Otomatis dari pemakaian part di Work Order', SHEETS: 'Impor Google Sheets (baris normal)',
  'SHEETS-R1': 'Impor Sheets — dipulihkan (kolom bergeser, qty > 0)', 'SHEETS-R2': 'Impor Sheets — dipulihkan (qty dihitung dari sisa stok)',
};

export default async function render(root) {
  const c = await api.get('mw/config');
  root.append(h('div', { class: 'page' },
    h('div', { class: 'page-head' }, h('h1', {}, 'Config MaintWare')),
    h('div', { class: 'grid g4 keep2' }, ...[['Item', c.counts.items], ['Transaksi', c.counts.transactions], ['Purchase Request', c.counts.ro], ['Equipment (Equipment DB)', c.counts.equipment]]
      .map(([l, v]) => h('div', { class: 'card stat' }, h('b', {}, v), h('span', {}, l)))),
    h('div', { class: 'card', style: 'margin-top:14px' }, h('h2', {}, 'Koneksi & skema'),
      h('dl', { class: 'kv', style: 'margin-top:10px' }, h('dt', {}, 'Database'), h('dd', {}, `${c.driver} (SQL — tidak lagi memakai Google Sheets/Apps Script)`),
        h('dt', {}, 'Migrasi terpasang'), h('dd', {}, c.migrations.join(', ')),
        h('dt', {}, 'Impor ulang'), h('dd', {}, h('span', { class: 'mono' }, 'php scripts/import-maintware.php --url=<Apps Script /exec>')))),
    h('div', { class: 'card' }, h('h2', {}, 'Asal data transaksi'), table([{ label: 'Sumber', render: (r) => h('span', { class: 'mono' }, r.source) }, { label: 'Keterangan', render: (r) => SRC[r.source] || '—' }, { label: 'Jumlah', key: 'n', cls: 'num' }], c.sources)),
    h('div', { class: 'card' }, h('h2', {}, 'Catatan'), h('ul', { class: 'muted' },
      h('li', {}, 'Stok item = nilai di ITEMS_DB Sheets saat impor; setelah itu berubah hanya lewat transaksi (Stock IN/OUT atau pemakaian part di Work Order).'),
      h('li', {}, 'Kode item baru: MECH / ELEC / CIVIL / ITEM sesuai type, nomor urut berikutnya.'),
      h('li', {}, 'Transaksi OUT dapat dikaitkan ke Unit → Area → Equipment sehingga Dashboard menampilkan pemakaian per equipment.')))));
}
