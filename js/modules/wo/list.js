import { api } from '../../core/api.js';
import { h, attempt, modal, form, table, statusBadge, prioBadge, liveTimer, dt } from '../../core/ui.js';

const STATUSES = ['Open', 'In Progress', 'Pending Verification', 'Closed', 'Cancelled'];

export default async function render(root, { query, user, meta, go }) {
  const f = { status: query.status || '', q: '', unit_id: '', type: '' };
  let page = 0;
  const PAGE = 50;

  const list = h('div');
  const chips = h('div', { class: 'chips' });
  const pager = h('div', { class: 'toolbar' });

  const load = async () => {
    const r = await api.get('wo', { ...f, limit: PAGE, offset: page * PAGE, mine: query.mine });
    const total = Object.values(r.counts).reduce((a, b) => a + Number(b), 0);
    chips.replaceChildren(
      chip('Semua', '', total),
      ...STATUSES.map((s) => chip(s, s, r.counts[s] || 0)));
    list.replaceChildren(table([
      { label: 'ID', render: (w) => h('span', { class: 'mono' }, w.id) },
      { label: 'Judul', render: (w) => h('div', {}, h('b', {}, w.title), h('div', { class: 'muted' }, `${w.equipment_name ?? '—'} · ${w.unit_name ?? ''}`)) },
      { label: 'Tipe', key: 'type' },
      { label: 'Prioritas', render: (w) => prioBadge(w.priority) },
      { label: 'Status', render: (w) => statusBadge(w.status, w.status === 'In Progress' && !w.running_since) },
      { label: 'Teknisi', key: 'techs' },
      { label: 'Durasi', render: (w) => (w.started_at ? liveTimer(w.work_seconds - elapsed(w), w.running_since) : '—') },
      { label: 'Dibuat', render: (w) => dt(w.created_at) },
    ], r.rows, { empty: 'Belum ada Work Order yang cocok.', onRow: (w) => go('wo/detail', { id: w.id }) }));
    const pages = Math.max(1, Math.ceil(r.total / PAGE));
    pager.replaceChildren(
      h('span', { class: 'muted' }, `${r.total} WO · halaman ${page + 1}/${pages}`),
      h('button', { class: 'btn sm', disabled: page === 0, onclick: () => { page--; load(); } }, '‹'),
      h('button', { class: 'btn sm', disabled: page + 1 >= pages, onclick: () => { page++; load(); } }, '›'));
  };
  // work_seconds dari API sudah termasuk sesi berjalan; liveTimer butuh dasar tanpa sesi berjalan
  const elapsed = (w) => (w.running_since ? Math.max(0, Math.floor((Date.now() - new Date(w.running_since.replace(' ', 'T')).getTime()) / 1000)) : 0);
  const chip = (label, val, n) => h('button', { class: `chip${f.status === val ? ' on' : ''}`, onclick: () => { f.status = val; page = 0; load(); } }, label, h('b', {}, n));
  const reload = () => { page = 0; load(); };

  let timer;
  root.append(h('div', { class: 'page' },
    h('div', { class: 'page-head' }, h('h1', {}, query.mine ? 'WO Saya' : 'Progress WO'), h('span', { class: 'sp' }),
      h('button', { class: 'btn primary', onclick: () => createDialog(meta, user, go) }, '+ Buat Work Order')),
    h('div', { class: 'toolbar' },
      h('input', { class: 'grow', type: 'search', placeholder: 'Cari ID, judul, equipment, pemohon…', oninput: (e) => { clearTimeout(timer); timer = setTimeout(() => { f.q = e.target.value; reload(); }, 300); } }),
      h('select', { onchange: (e) => { f.unit_id = e.target.value; reload(); }, 'aria-label': 'Unit' }, h('option', { value: '' }, 'Semua unit'), meta.units.map((u) => h('option', { value: u.id }, u.name))),
      h('select', { onchange: (e) => { f.type = e.target.value; reload(); }, 'aria-label': 'Tipe' }, h('option', { value: '' }, 'Semua tipe'), meta.wo_types.map((t) => h('option', {}, t)))),
    chips, list, pager));
  await load();
}

/** Form pembuatan WO: pilih Unit → Area → Equipment dari Equipment Database. */
export function createDialog(meta, user, go, preset = {}) {
  const unit = h('select', {}, meta.units.map((u) => h('option', { value: u.id }, u.name)));
  const area = h('select', {});
  const eq = h('select', {}, h('option', { value: '' }, 'Pilih area dulu…'));
  const eqSearch = h('input', { type: 'search', placeholder: 'Cari equipment…' });
  if (user.unit_id) unit.value = user.unit_id;

  const fillAreas = () => {
    area.replaceChildren(...meta.areas.filter((a) => a.unit_id == unit.value).map((a) => h('option', { value: a.id }, a.name)));
    fillEq();
  };
  const fillEq = async () => {
    if (!area.value) return eq.replaceChildren(h('option', { value: '' }, 'Tidak ada area'));
    const searching = eqSearch.value.trim() !== ''; // saat mencari, cari di seluruh area pada unit terpilih
    const r = await api.get('equipment', searching ? { unit_id: unit.value, q: eqSearch.value, limit: 100 } : { area_id: area.value, limit: 300 });
    eq.replaceChildren(...(r.rows.length ? r.rows.map((e) => h('option', { value: e.id }, searching ? `${e.name} · ${e.area_name}` : e.name)) : [h('option', { value: '' }, 'Tidak ada equipment')]));
  };
  unit.onchange = fillAreas;
  area.onchange = fillEq;
  let t;
  eqSearch.oninput = () => { clearTimeout(t); t = setTimeout(fillEq, 250); };
  fillAreas();

  const f = form([
    { name: 'title', label: 'Judul / masalah', required: true, wide: true, default: preset.title },
    { name: 'type', label: 'Tipe', type: 'select', options: meta.wo_types, default: preset.type },
    { name: 'priority', label: 'Prioritas', type: 'select', options: meta.priorities, default: 'Medium' },
    { name: 'description', label: 'Deskripsi masalah', type: 'textarea', wide: true, default: preset.description },
    { name: 'due_date', label: 'Target selesai', type: 'date' },
    { name: 'department', label: 'Departemen', default: user.department || '' },
  ]);
  const body = h('div', {},
    h('div', { class: 'form-grid' },
      h('label', { class: 'field' }, h('span', {}, 'Unit *'), unit),
      h('label', { class: 'field' }, h('span', {}, 'Area *'), area),
      h('label', { class: 'field wide' }, h('span', {}, 'Equipment * (dari Equipment Database)'), eqSearch, eq)),
    h('div', { style: 'height:12px' }), f.node);

  modal('Buat Work Order', body, { wide: true, actions: [
    { label: 'Batal', run: () => {} },
    { label: 'Kirim ke Maintenance', kind: 'primary', run: async () => {
      const v = f.values();
      if (!v.title.trim() || !eq.value) { attempt(() => { throw new Error('Judul dan equipment wajib diisi.'); }); return false; }
      const r = await attempt(() => api.post('wo/create', { ...v, equipment_id: Number(eq.value), source: preset.source, source_ref: preset.source_ref }), 'Work Order dibuat.');
      if (!r) return false;
      go('wo/detail', { id: r.id });
    } },
  ] });
}
