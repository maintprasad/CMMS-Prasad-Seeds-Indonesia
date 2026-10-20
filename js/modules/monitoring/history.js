import { api } from '../../core/api.js';
import { h, table, dt } from '../../core/ui.js';
import { stBadge, sessionDialog } from './shared.js';

const PAGE = 50;

export default async function render(root, { meta, user, go, query }) {
  const f = { unit_id: '', status: query.status || '', q: '', from: '', to: '', equipment_id: query.equipment_id || '' };
  let page = 0;
  const host = h('div');
  const pager = h('div', { class: 'toolbar' });
  const count = h('span', { class: 'muted' });

  const load = async () => {
    const r = await api.get('dm/history', { ...f, limit: PAGE, offset: page * PAGE });
    count.textContent = `${r.total} sesi`;
    host.replaceChildren(table([
      { label: 'Tanggal', render: (s) => h('div', {}, dt(s.session_date, false), h('div', { class: 'muted' }, [s.start_time, s.end_time].filter(Boolean).join(' – '))) },
      { label: 'Equipment', render: (s) => h('div', {}, h('b', {}, s.equipment_name), h('div', { class: 'muted' }, [s.unit_name, s.area_name].filter(Boolean).join(' › '))) },
      { label: 'PIC', key: 'pic' }, { label: 'Status', render: (s) => stBadge(s.status) },
      { label: 'Item', cls: 'num', render: (s) => `${s.items}${s.alerts ? ` · ${s.alerts}A` : ''}${s.warnings ? ` · ${s.warnings}W` : ''}` },
      { label: 'WO', render: (s) => (s.wo_id ? h('a', { class: 'mono', href: `#/wo/detail?id=${encodeURIComponent(s.wo_id)}`, onclick: (e) => e.stopPropagation() }, s.wo_id) : '—') },
      { label: 'Catatan', render: (s) => h('span', { class: 'muted' }, (s.catatan || '').slice(0, 60)) },
    ], r.rows, { empty: 'Belum ada sesi monitoring yang cocok.', onRow: (s) => sessionDialog(s.id, { user, go, onChange: load }) }));
    const pages = Math.max(1, Math.ceil(r.total / PAGE));
    pager.replaceChildren(h('span', { class: 'muted' }, `halaman ${page + 1}/${pages}`),
      h('button', { class: 'btn sm', disabled: page === 0, onclick: () => { page--; load(); } }, '‹'),
      h('button', { class: 'btn sm', disabled: page + 1 >= pages, onclick: () => { page++; load(); } }, '›'));
  };
  const reload = () => { page = 0; load(); };
  const inp = (k) => h('input', { type: 'date', 'aria-label': k, onchange: (e) => { f[k] = e.target.value; reload(); } });
  let t;
  root.append(h('div', { class: 'page' }, h('div', { class: 'page-head' }, h('h1', {}, 'Riwayat'), count),
    h('div', { class: 'toolbar' },
      h('input', { class: 'grow', type: 'search', placeholder: 'Cari equipment, PIC, catatan, ID sesi…', oninput: (e) => { clearTimeout(t); t = setTimeout(() => { f.q = e.target.value; reload(); }, 300); } }),
      h('select', { 'aria-label': 'Unit', onchange: (e) => { f.unit_id = e.target.value; reload(); } }, h('option', { value: '' }, 'Semua unit'), meta.units.map((u) => h('option', { value: u.id }, u.name))),
      (() => { const s = h('select', { 'aria-label': 'Status', onchange: (e) => { f.status = e.target.value; reload(); } }, h('option', { value: '' }, 'Semua status'), ['OK', 'WARNING', 'ALERT', 'PENDING'].map((x) => h('option', {}, x))); s.value = f.status; return s; })(),
      inp('from'), '–', inp('to')),
    host, pager));
  await load();
}
