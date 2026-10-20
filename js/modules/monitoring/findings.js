import { api } from '../../core/api.js';
import { h, attempt, table, dt } from '../../core/ui.js';
import { stBadge, sessionDialog } from './shared.js';

/** Finding / Alarm: item WARNING/ALERT dari Daily Monitoring. Aktif sampai ada pembacaan lebih baru atau WO-nya selesai. */
export default async function render(root, { meta, user, go }) {
  const f = { state: 'active', status: '', unit_id: '', q: '' };
  const host = h('div');
  const count = h('span', { class: 'muted' });

  const load = async () => {
    const { rows } = await api.get('dm/findings', f);
    count.textContent = `${rows.length} temuan`;
    // kelompokkan per sesi supaya satu WO bisa dibuat untuk seluruh temuan sesi itu
    const groups = new Map();
    rows.forEach((r) => { if (!groups.has(r.session_id)) groups.set(r.session_id, []); groups.get(r.session_id).push(r); });
    if (!groups.size) { host.replaceChildren(h('div', { class: 'empty' }, f.state === 'active' ? '✅ Tidak ada temuan aktif.' : 'Tidak ada temuan.')); return; }
    host.replaceChildren(...[...groups].map(([sid, items]) => {
      const g = items[0];
      return h('div', { class: 'card', style: 'margin-bottom:12px' },
        h('div', { class: 'page-head' }, h('b', {}, g.equipment_name), h('span', { class: 'muted' }, `${[g.unit_name, g.area_name].filter(Boolean).join(' › ')} · ${dt(g.session_date, false)} ${g.start_time ?? ''}`), h('span', { class: 'sp' }),
          g.wo_id ? h('a', { class: 'btn sm', href: `#/wo/detail?id=${encodeURIComponent(g.wo_id)}` }, `🔧 ${g.wo_id} · ${g.wo_status ?? ''}`)
            : h('button', { class: 'btn sm primary', onclick: async () => { const r = await attempt(() => api.post('dm/wo', { session_id: sid }), 'Work Order dibuat.'); if (r) go('wo/detail', { id: r.wo_id }); } }, '🔧 Buat WO'),
          h('button', { class: 'btn sm', onclick: () => sessionDialog(sid, { user, go, onChange: load }) }, 'Sesi')),
        table([
          { label: 'Parameter', key: 'label' }, { label: 'Nilai', cls: 'num', render: (i) => `${i.value} ${i.unit || ''}` }, { label: 'Status', render: (i) => stBadge(i.status) },
          { label: 'Keterangan', render: (i) => h('span', { class: i.active ? '' : 'muted' }, i.active ? (i.note || 'Aktif') : `✓ ${i.resolved}`) },
        ], items));
    }));
  };
  const sel = (k, opts) => h('select', { 'aria-label': k, onchange: (e) => { f[k] = e.target.value; load(); } }, opts.map(([v, l]) => h('option', { value: v }, l)));
  let t;
  root.append(h('div', { class: 'page' }, h('div', { class: 'page-head' }, h('h1', {}, '🚨 Finding / Alarm'), count),
    h('div', { class: 'toolbar' },
      h('input', { class: 'grow', type: 'search', placeholder: 'Cari equipment / parameter…', oninput: (e) => { clearTimeout(t); t = setTimeout(() => { f.q = e.target.value; load(); }, 300); } }),
      sel('state', [['active', '🔴 Aktif'], ['resolved', '✓ Selesai'], ['all', 'Semua']]), sel('status', [['', 'ALERT & WARNING'], ['ALERT', 'ALERT'], ['WARNING', 'WARNING']]),
      sel('unit_id', [['', 'Semua unit'], ...meta.units.map((u) => [u.id, u.name])])),
    host));
  await load();
}
