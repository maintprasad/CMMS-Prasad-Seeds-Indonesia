import { api } from '../../core/api.js';
import { h, dt } from '../../core/ui.js';
import { stBadge, today } from './shared.js';

/** Papan Monitor: equipment berparameter per unit/area dengan status pembacaan terakhir. Klik kartu → form monitoring. */
export default async function render(root, { meta, go, query }) {
  const f = { unit_id: query.unit_id || '', area_id: '', q: '' };
  const host = h('div');
  const areaSel = h('select', { 'aria-label': 'Area', onchange: (e) => { f.area_id = e.target.value; load(); } });
  const fillAreas = () => areaSel.replaceChildren(h('option', { value: '' }, 'Semua area'), ...meta.areas.filter((a) => !f.unit_id || a.unit_id == f.unit_id).map((a) => h('option', { value: a.id }, a.name)));

  const load = async () => {
    const { rows, today: td } = await api.get('dm/board', f);
    if (!rows.length) {
      host.replaceChildren(h('div', { class: 'empty' }, 'Tidak ada equipment dengan parameter monitoring. Atur parameter di Equipment Database → buka equipment → “Parameter Monitoring”.',
        h('div', { style: 'margin-top:10px' }, h('a', { class: 'btn', href: '#/eq/list' }, 'Buka Equipment Database'))));
      return;
    }
    const groups = new Map();
    rows.forEach((e) => { const k = `${e.unit_name} › ${e.area_name}`; if (!groups.has(k)) groups.set(k, []); groups.get(k).push(e); });
    host.replaceChildren(...[...groups].map(([title, eqs]) => h('div', { class: 'card', style: 'margin-bottom:14px' },
      h('div', { class: 'page-head' }, h('h2', {}, title), h('span', { class: 'muted' }, `${eqs.length} equipment`)),
      h('div', { class: 'tile-grid' }, eqs.map((e) => {
        const days = e.last_date ? Math.floor((new Date(td) - new Date(e.last_date)) / 86400000) : null;
        const cls = { OK: 't-ok', WARNING: 't-warn', ALERT: 't-alert' }[e.last_status] || 't-pend';
        return h('button', { class: `tile ${cls}`, onclick: () => go('dm/entry', { equipment_id: e.id }) },
          h('b', {}, e.name), h('div', { class: 'muted' }, `${e.params} parameter`),
          h('div', { class: 'tile-foot' }, e.last_date ? [stBadge(e.last_status), ' ', h('span', { class: days > 7 ? 'late' : 'muted' }, days === 0 ? `hari ini ${e.last_time ?? ''}` : `${dt(e.last_date, false)} (${days} hari lalu)`)]
            : h('span', { class: 'late' }, '⚠ Belum pernah dimonitor')));
      })))));
  };
  const unitSel = h('select', { 'aria-label': 'Unit', onchange: (e) => { f.unit_id = e.target.value; f.area_id = ''; fillAreas(); load(); } }, h('option', { value: '' }, 'Semua unit'), meta.units.map((u) => h('option', { value: u.id }, u.name)));
  unitSel.value = f.unit_id;
  fillAreas();
  let t;
  root.append(h('div', { class: 'page' }, h('div', { class: 'page-head' }, h('h1', {}, 'Monitor'), h('span', { class: 'muted' }, `Pilih equipment untuk mulai monitoring · ${dt(today(), false)}`)),
    h('div', { class: 'toolbar' }, h('input', { class: 'grow', type: 'search', placeholder: 'Cari equipment…', oninput: (e) => { clearTimeout(t); t = setTimeout(() => { f.q = e.target.value; load(); }, 300); } }), unitSel, areaSel), host));
  await load();
}
