import { h } from '../../core/ui.js';

/** Admin Panel WO: pintasan laporan. User, teknisi, dan Equipment Database dikelola di menu Setting. */
export default async function render(root, { go }) {
  const card = (icon, title, text, path) => h('div', { class: 'card', style: 'cursor:pointer', onclick: () => go(path) }, h('div', { class: 'big-ic' }, icon), h('h3', {}, title), h('p', { class: 'muted' }, text));
  root.append(h('div', { class: 'page' }, h('div', { class: 'page-head' }, h('h1', {}, 'Admin Panel')),
    h('div', { class: 'grid g3' },
      card('🏭', 'Equipment Database', 'Unit, area, equipment, parameter monitoring (Setting)', 'eq/list'),
      card('🔧', 'Equip. History', 'Riwayat perbaikan per equipment', 'wo/equip-history'),
      card('⏱', 'MTTR / MTBF', 'Keandalan equipment dari Work Order', 'wo/mttr'),
      card('⭐', 'Rating Teknisi', 'Penilaian dari verifikasi WO', 'wo/rating'))));
}
