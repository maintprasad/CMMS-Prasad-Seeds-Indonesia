import { h } from '../core/ui.js';

// Halaman untuk menu yang strukturnya sudah ada, tetapi modulnya dibangun di tahap berikutnya.
export default function pending(root, { item, path }) {
  const group = item?.group.title ?? path;
  root.replaceChildren(h('div', { class: 'page' },
    h('div', { class: 'page-head' }, h('h1', {}, item?.label ?? path), h('span', { class: 'muted' }, group)),
    h('div', { class: 'card pending' },
      h('div', { class: 'big-ic' }, h('i', { class: `ti ${item?.icon ?? 'ti-barrier-block'}` })),
      h('h3', {}, 'Menu ini belum dibangun'),
      h('p', {}, `Modul ${group} dikerjakan di tahap berikutnya. Menunya sengaja dipertahankan sama dengan aplikasi lama, `
        + 'dan akan membaca Equipment Database yang sama seperti Work Order.'))));
}
