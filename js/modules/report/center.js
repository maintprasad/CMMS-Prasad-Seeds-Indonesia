// Pusat Laporan: pilih modul (satu, kombinasi, atau semuanya) + periode + unit → pratinjau → unduh Excel / PowerPoint / PDF.
import { api } from '../../core/api.js';
import { h, table, toast } from '../../core/ui.js';
import { exportXlsx, exportPptx, printReport } from '../../core/export.js';

const MODS = [
  { key: 'wo', title: 'Work Order', icon: 'ti-clipboard-list', desc: 'Status, tipe, prioritas, durasi, daftar WO & sparepart' },
  { key: 'mw', title: 'MaintWare', icon: 'ti-packages', desc: 'Stok masuk/keluar, top item, reorder, detail transaksi' },
  { key: 'pm', title: 'Preventive Maintenance', icon: 'ti-tool', desc: 'Laporan PM, kepatuhan jadwal, temuan, jadwal terlambat' },
  { key: 'dm', title: 'Daily Monitoring', icon: 'ti-heartbeat', desc: 'Sesi monitoring, temuan WARNING/ALERT per equipment' },
];
const iso = (d) => { const z = new Date(d.getTime() - d.getTimezoneOffset() * 60000); return z.toISOString().slice(0, 10); };
const PRESETS = [
  ['Bulan ini', () => { const n = new Date(); return [iso(new Date(n.getFullYear(), n.getMonth(), 1)), iso(n)]; }],
  ['Bulan lalu', () => { const n = new Date(); return [iso(new Date(n.getFullYear(), n.getMonth() - 1, 1)), iso(new Date(n.getFullYear(), n.getMonth(), 0))]; }],
  ['3 bulan', () => { const n = new Date(); return [iso(new Date(n.getFullYear(), n.getMonth() - 2, 1)), iso(n)]; }],
  ['Tahun ini', () => { const n = new Date(); return [iso(new Date(n.getFullYear(), 0, 1)), iso(n)]; }],
];
const PREVIEW_ROWS = 10;

export default async function render(root, { meta, query }) {
  const sel = new Set((query.modules || '').split(',').filter((m) => MODS.some((x) => x.key === m)));
  if (!sel.size) MODS.forEach((m) => sel.add(m.key));
  const [d0, d1] = PRESETS[0][1]();
  const f = { from: query.from || d0, to: query.to || d1, unit_id: query.unit_id || '' };
  let report = null;

  const cards = h('div', { class: 'grid g4 rp-mods' });
  const result = h('div', { class: 'rp-result' });
  const from = h('input', { type: 'date', value: f.from, 'aria-label': 'Dari', onchange: (e) => { f.from = e.target.value; mark(); } });
  const to = h('input', { type: 'date', value: f.to, 'aria-label': 'Sampai', onchange: (e) => { f.to = e.target.value; mark(); } });
  const unit = h('select', { 'aria-label': 'Unit', onchange: (e) => { f.unit_id = e.target.value; } }, h('option', { value: '' }, 'Semua unit'), meta.units.map((u) => h('option', { value: u.id }, u.name)));
  unit.value = f.unit_id;
  const mark = () => presets.querySelectorAll('.chip').forEach((c) => c.classList.toggle('on', c.dataset.from === f.from && c.dataset.to === f.to));
  const presets = h('div', { class: 'chips', style: 'margin:0' }, PRESETS.map(([label, fn]) => {
    const [a, b] = fn();
    return h('button', { class: 'chip', type: 'button', 'data-from': a, 'data-to': b, onclick: () => { f.from = from.value = a; f.to = to.value = b; mark(); } }, label);
  }));
  const gen = h('button', { class: 'btn primary', type: 'button', onclick: () => run() }, h('i', { class: 'ti ti-file-analytics me-1' }), 'Buat laporan');
  const allBtn = h('button', { class: 'btn sm', type: 'button', onclick: () => { MODS.forEach((m) => sel.add(m.key)); drawCards(); } }, 'Pilih semua');

  const drawCards = () => {
    cards.replaceChildren(...MODS.map((m) => {
      const on = sel.has(m.key);
      return h('button', { type: 'button', class: `card rp-mod${on ? ' on' : ''}`, 'aria-pressed': String(on), onclick: () => { on ? sel.delete(m.key) : sel.add(m.key); drawCards(); } },
        h('span', { class: 'rp-check' }, h('i', { class: `ti ${on ? 'ti-circle-check-filled' : 'ti-circle'}` })),
        h('i', { class: `ti ${m.icon} rp-ico` }), h('b', {}, m.title), h('small', { class: 'muted' }, m.desc));
    }));
    gen.disabled = !sel.size;
    allBtn.hidden = sel.size === MODS.length;
  };

  const exportBtn = (label, icon, fn) => h('button', { class: 'btn', type: 'button', onclick: async (e) => {
    const b = e.currentTarget; b.disabled = true;
    try { await fn(report); } catch (ex) { toast(ex.message, 'err'); } finally { b.disabled = false; }
  } }, h('i', { class: `ti ${icon} me-1` }), label);

  const preview = () => {
    const tabs = h('div', { class: 'tabs' });
    const body = h('div');
    const show = (s) => {
      tabs.querySelectorAll('.tab').forEach((t) => t.classList.toggle('on', t.dataset.key === s.key));
      body.replaceChildren(
        h('div', { class: `grid g${Math.min(5, s.kpis.length) === 5 ? 5 : s.kpis.length} rp-kpis` }, s.kpis.map(([k, v]) => h('div', { class: 'card stat' }, h('b', {}, v), h('span', {}, k)))),
        ...s.tables.map((t) => h('div', { class: 'card', style: 'margin-top:14px' },
          h('div', { class: 'page-head', style: 'margin-bottom:8px' }, h('h2', {}, t.name), h('span', { class: 'sp' }), h('span', { class: 'muted' }, `${t.total} baris`)),
          t.rows.length ? [table(t.head.map((c, i) => ({ label: c, render: (r) => r[i] ?? '—', cls: typeof t.rows[0][i] === 'number' ? 'num' : '' })), t.rows.slice(0, PREVIEW_ROWS)),
            t.total > PREVIEW_ROWS ? h('div', { class: 'muted', style: 'margin-top:8px' }, `Menampilkan ${PREVIEW_ROWS} dari ${t.total} baris — file hasil unduhan memuat semuanya${t.truncated ? ' (maks. 5.000 baris per tabel)' : ''}.`) : null] : h('div', { class: 'empty' }, 'Tidak ada data pada periode ini.'))));
    };
    report.sections.forEach((s) => tabs.append(h('button', { class: 'tab', type: 'button', 'data-key': s.key, onclick: () => show(s) }, s.title)));
    result.replaceChildren(
      h('div', { class: 'card rp-head' },
        h('div', {}, h('h2', { style: 'margin:0' }, 'Laporan siap'), h('span', { class: 'muted' }, `${report.meta.from} s/d ${report.meta.to} · ${report.meta.unit} · ${report.sections.map((s) => s.title).join(', ')}`)),
        h('span', { class: 'sp' }),
        h('div', { class: 'rp-actions' }, exportBtn('Excel', 'ti-file-spreadsheet', exportXlsx), exportBtn('PowerPoint', 'ti-presentation', exportPptx), exportBtn('PDF / Cetak', 'ti-printer', async (r) => printReport(r)))),
      tabs, body);
    show(report.sections[0]);
  };

  async function run() {
    if (!sel.size) return toast('Pilih minimal satu modul.', 'err');
    gen.disabled = true;
    result.replaceChildren(h('div', { class: 'empty' }, h('span', { class: 'spinner-border spinner-border-sm me-2' }), 'Menyusun laporan…'));
    try {
      report = await api.get('report/build', { modules: [...sel].join(','), from: f.from, to: f.to, unit_id: f.unit_id });
      preview();
    } catch (e) { result.replaceChildren(h('div', { class: 'empty err' }, e.message)); } finally { gen.disabled = !sel.size; }
  }

  root.append(h('div', { class: 'page' },
    h('div', { class: 'page-head' }, h('h1', {}, 'Generate Report'), h('span', { class: 'sp' }), allBtn),
    h('p', { class: 'muted', style: 'margin-top:-8px' }, 'Pilih satu modul, kombinasi, atau semuanya — satu laporan untuk Work Order, MaintWare, Preventive Maintenance, dan Daily Monitoring.'),
    cards,
    h('div', { class: 'rp-filters' }, h('span', { class: 'muted' }, 'Periode'), from, '–', to, unit, gen),
    presets, h('div', { style: 'height:16px' }), result));
  drawCards();
  mark();
  if (query.auto) run();
}
