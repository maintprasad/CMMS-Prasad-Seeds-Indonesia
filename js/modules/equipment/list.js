import { api } from '../../core/api.js';
import { h, table } from '../../core/ui.js';
import { editDialog, bulkOwnersDialog } from './dialogs.js';
import detail from './detail.js';

export default async function render(root, ctx) {
  if (ctx.query.id) return detail(root, ctx);
  const { meta, user, go, query } = ctx;
  const canEdit = ['admin', 'spv'].includes(user.role);
  const f = { unit_id: '', area_id: query.area_id || '', q: '', pj: query.pj || '' };
  f.unit_id = meta.areas.find((a) => a.id == f.area_id)?.unit_id ?? '';
  let page = 0, last = { total: 0 };
  const PAGE = 50;
  const host = h('div');
  const pager = h('div', { class: 'toolbar' });
  const banner = h('div');
  const areaSel = h('select', { 'aria-label': 'Area', onchange: (e) => { f.area_id = e.target.value; reload(); } });
  const unitSel = h('select', { 'aria-label': 'Unit', onchange: (e) => { f.unit_id = e.target.value; f.area_id = ''; fillAreas(); reload(); } }, h('option', { value: '' }, 'Semua unit'), meta.units.map((u) => h('option', { value: u.id }, u.name)));
  const pjSel = h('select', { 'aria-label': 'Penanggung jawab', onchange: (e) => { f.pj = e.target.value; reload(); } }, h('option', { value: '' }, 'Semua PJ'), h('option', { value: 'missing' }, 'PJ belum lengkap'));
  const fillAreas = () => areaSel.replaceChildren(h('option', { value: '' }, 'Semua area'), ...meta.areas.filter((a) => !f.unit_id || a.unit_id == f.unit_id).map((a) => h('option', { value: a.id }, a.name)));
  const pj = (name) => (name || h('span', { class: 'late' }, 'belum diisi'));

  const load = async () => {
    const r = await api.get('equipment', { ...f, limit: PAGE, offset: page * PAGE });
    last = r;
    banner.replaceChildren(r.pj_missing ? h('div', { class: 'note', style: 'border-color:var(--orange)' },
      `⚠ ${r.pj_missing} equipment belum punya penanggung jawab lengkap (Produksi + Maintenance).`, ' ',
      h('button', { class: 'btn sm', onclick: () => { f.pj = 'missing'; pjSel.value = 'missing'; reload(); } }, 'Tampilkan'),
      canEdit ? [' ', h('button', { class: 'btn sm primary', onclick: () => bulkOwnersDialog(meta, { ...f, pj: 'missing' }, r.pj_missing, load) }, 'Isi massal')] : null) : null);
    host.replaceChildren(table([
      { label: 'Equipment', render: (e) => h('div', {}, h('b', {}, e.name), e.code ? h('small', { class: 'muted mono' }, ` ${e.code}`) : null) },
      { label: 'Unit', key: 'unit_name' }, { label: 'Area', key: 'area_name' },
      { label: 'Merk / Model', render: (e) => [e.brand, e.model].filter(Boolean).join(' ') || '—' },
      { label: 'PJ Produksi', render: (e) => pj(e.pj_production) }, { label: 'PJ Maintenance', render: (e) => pj(e.pj_maintenance) },
      { label: 'Status', key: 'status' }, { label: 'Param.', render: (e) => (e.param_count ? h('span', { class: 'badge prog' }, e.param_count) : h('span', { class: 'muted' }, '—')), cls: 'num' }, { label: 'WO', key: 'wo_count', cls: 'num' },
    ], r.rows, { empty: 'Tidak ada equipment yang cocok.', onRow: (e) => go('eq/list', { id: e.id }) }));
    const pages = Math.max(1, Math.ceil(r.total / PAGE));
    pager.replaceChildren(h('span', { class: 'muted' }, `${r.total} equipment · halaman ${page + 1}/${pages}`),
      h('button', { class: 'btn sm', disabled: page === 0, onclick: () => { page--; load(); } }, '‹'),
      h('button', { class: 'btn sm', disabled: page + 1 >= pages, onclick: () => { page++; load(); } }, '›'));
  };
  const reload = () => { page = 0; load(); };
  let t;
  fillAreas();
  areaSel.value = f.area_id;
  unitSel.value = f.unit_id;
  pjSel.value = f.pj;
  root.append(h('div', { class: 'page' },
    h('div', { class: 'page-head' }, h('h1', {}, 'Equipment Database'), h('span', { class: 'sp' }),
      canEdit ? [h('button', { class: 'btn', onclick: () => bulkOwnersDialog(meta, f, last.total, load) }, 'Set PJ massal (hasil filter)'),
        h('button', { class: 'btn primary', onclick: () => editDialog(meta, {}, (id) => go('eq/list', { id })) }, '+ Equipment')] : null),
    banner,
    h('div', { class: 'toolbar' },
      h('input', { class: 'grow', type: 'search', placeholder: 'Cari nama, kode, merk, serial…', oninput: (e) => { clearTimeout(t); t = setTimeout(() => { f.q = e.target.value; reload(); }, 300); } }),
      unitSel, areaSel, pjSel),
    host, pager));
  await load();
}
