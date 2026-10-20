import { api } from '../../core/api.js';
import { h, table, dt, downloadCsv } from '../../core/ui.js';
import { reportDialog, PERIODS, MONTHS } from './shared.js';

const PAGE = 50;

/** Daftar Laporan PM: filter, pagination, rincian (dialog), ekspor CSV. ?id=<laporan> membuka rinciannya langsung. */
export default async function render(root, { meta, user, go, query }) {
  const types = (await api.get('pm/types')).types;
  const f = { unit_id: '', area_id: '', pm_type: '', periode: '', year: '', month: '', q: '', findings: query.findings || '' };
  let page = 0;
  const host = h('div'), pager = h('div', { class: 'toolbar' }), count = h('span', { class: 'muted' });
  const areaSel = h('select', { 'aria-label': 'Area', onchange: (e) => { f.area_id = e.target.value; reload(); } });
  const fillAreas = () => areaSel.replaceChildren(h('option', { value: '' }, 'Semua area'), ...meta.areas.filter((a) => !f.unit_id || a.unit_id == f.unit_id).map((a) => h('option', { value: a.id }, a.name)));
  const unitSel = h('select', { 'aria-label': 'Unit', onchange: (e) => { f.unit_id = e.target.value; f.area_id = ''; fillAreas(); reload(); } }, h('option', { value: '' }, 'Semua unit'), meta.units.map((u) => h('option', { value: u.id }, u.name)));
  fillAreas();
  const sel = (key, label, opts) => h('select', { 'aria-label': label, onchange: (e) => { f[key] = e.target.value; reload(); } }, h('option', { value: '' }, label), opts.map(([v, l]) => h('option', { value: v }, l)));
  const year = new Date().getFullYear();

  const load = async () => {
    const r = await api.get('pm/reports', { ...f, limit: PAGE, offset: page * PAGE });
    count.textContent = `${r.total} laporan`;
    host.replaceChildren(table([
      { label: 'Tanggal', render: (x) => dt(x.report_date, false) }, { label: 'No.', render: (x) => h('span', { class: 'mono' }, x.id.length > 18 ? x.id.slice(-14) : x.id) },
      { label: 'Equipment', render: (x) => h('div', {}, h('b', {}, x.equipment_name), h('div', { class: 'muted' }, `${x.unit_name ?? ''} › ${x.area_name ?? ''}`)) },
      { label: 'Tipe', render: (x) => x.type_label ?? '—' }, { label: 'Periode', key: 'periode' }, { label: 'Teknisi', key: 'teknisi' },
      { label: 'Hasil', render: (x) => h('span', {}, `${x.items_ok}/${x.items_total}`, x.items_no ? h('span', { class: 'badge prio-critical', style: 'margin-left:6px' }, `${x.items_no} ✗`) : null) },
      { label: 'TTD', render: (x) => h('span', { class: x.signed_leader && x.signed_spv ? 'badge done' : 'badge', title: 'Leader / Supervisor' }, `${x.signed_leader ? '✔' : '·'} ${x.signed_spv ? '✔' : '·'}`) },
      { label: 'WO', render: (x) => (x.wo_id ? h('a', { class: 'mono', href: `#/wo/detail?id=${encodeURIComponent(x.wo_id)}`, onclick: (e) => e.stopPropagation() }, 'WO') : '—') },
    ], r.rows, { empty: 'Belum ada laporan PM yang cocok.', onRow: (x) => reportDialog(x.id, { user, go, onChange: load }) }));
    const pages = Math.max(1, Math.ceil(r.total / PAGE));
    pager.replaceChildren(h('span', { class: 'muted' }, `halaman ${page + 1}/${pages}`), h('button', { class: 'btn sm', disabled: page === 0, onclick: () => { page--; load(); } }, '‹'), h('button', { class: 'btn sm', disabled: page + 1 >= pages, onclick: () => { page++; load(); } }, '›'));
  };
  const reload = () => { page = 0; load(); };
  const exportCsv = async () => {
    const r = await api.get('pm/reports', { ...f, limit: 500 });
    downloadCsv('laporan-pm.csv', ['No', 'Tanggal', 'Unit', 'Area', 'Equipment', 'Kode', 'Tipe', 'Periode', 'Teknisi', 'Disetujui', 'Butir', 'OK', 'Tidak OK', 'WO'],
      r.rows.map((x) => [x.id, x.report_date, x.unit_name, x.area_name, x.equipment_name, x.equipment_code, x.type_label, x.periode, x.teknisi, x.approval, x.items_total, x.items_ok, x.items_no, x.wo_id]));
  };
  let t;
  const findChip = h('button', { class: `chip${f.findings ? ' on' : ''}`, onclick: () => { f.findings = f.findings ? '' : '1'; findChip.classList.toggle('on', !!f.findings); reload(); } }, 'Ada temuan');
  root.append(h('div', { class: 'page' }, h('div', { class: 'page-head' }, h('h1', {}, 'Daftar Laporan'), count, h('span', { class: 'sp' }), h('button', { class: 'btn', onclick: exportCsv }, '⬇ CSV')),
    h('div', { class: 'toolbar' }, h('input', { class: 'grow', type: 'search', placeholder: 'Cari equipment, teknisi, nomor…', oninput: (e) => { clearTimeout(t); t = setTimeout(() => { f.q = e.target.value; reload(); }, 300); } }), unitSel, areaSel,
      sel('pm_type', 'Semua tipe', types.map((x) => [x.code, x.label])), sel('periode', 'Semua periode', PERIODS.map((p) => [p, p])),
      sel('year', 'Semua tahun', [year, year - 1, year - 2].map((y) => [y, y])), sel('month', 'Semua bulan', MONTHS.map((m, i) => [i + 1, m])), findChip), host, pager));
  await load();
  if (query.id) reportDialog(query.id, { user, go, onChange: load });
}
