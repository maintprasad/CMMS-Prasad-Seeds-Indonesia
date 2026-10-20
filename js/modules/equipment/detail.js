import { api } from '../../core/api.js';
import { h, attempt, confirmBox, table, statusBadge, hours, dt, dur } from '../../core/ui.js';
import { createDialog } from '../wo/list.js';
import { equipmentParamCard } from './params.js';
import { editDialog, spareDialog } from './dialogs.js';
import { stBadge, dueText } from '../preventive/shared.js';

const TABS = ['Ringkasan', 'Equipment History', 'Sparepart', 'Parameter Monitoring'];
const num = (v) => Number(v ?? 0).toLocaleString('id-ID', { maximumFractionDigits: 2 });

/** Detail equipment: ringkasan + penanggung jawab, history (WO & PM), sparepart (standar & terpakai), parameter monitoring. */
export default async function detail(root, { query, meta, user, go }) {
  const canEdit = ['admin', 'spv'].includes(user.role);
  let tab = query.tab && TABS.includes(query.tab) ? query.tab : 'Ringkasan';
  const host = h('div', { class: 'page' });
  root.append(host);

  const load = async () => {
    const [d, { types }] = await Promise.all([api.get('equipment/get', { id: query.id }), api.get('dm/types')]);
    const e = d.equipment;
    const body = h('div');
    const panels = {
      'Ringkasan': () => summary(d, e),
      'Equipment History': () => historyPanel(e),
      'Sparepart': () => sparePanel(d, e, canEdit, load),
      'Parameter Monitoring': () => paramPanel(d, e, canEdit, types, load, go),
    };
    const show = async () => {
      tabs.querySelectorAll('.tab').forEach((t) => t.classList.toggle('on', t.dataset.t === tab));
      body.replaceChildren(h('div', { class: 'loading' }, 'Memuat…'));
      body.replaceChildren(await panels[tab]());
    };
    const count = (t) => ({ 'Sparepart': d.spareparts.length, 'Parameter Monitoring': d.params.length }[t]);
    const tabs = h('div', { class: 'tabs', role: 'tablist' }, TABS.map((t) => h('button', { class: 'tab', role: 'tab', 'data-t': t, onclick: () => { tab = t; show(); } }, t, count(t) ? h('b', { class: 'muted', style: 'margin-left:6px' }, count(t)) : null)));
    host.replaceChildren(
      h('div', { class: 'page-head' }, h('a', { href: '#/eq/list', class: 'btn sm' }, '← Equipment'), h('h1', {}, e.name), h('span', { class: 'sp' }),
        h('button', { class: 'btn', onclick: () => createDialog(meta, user, go, { title: `${e.name}: ` }) }, '+ Buat WO'),
        canEdit ? h('button', { class: 'btn', onclick: () => editDialog(meta, e, load) }, 'Edit') : null,
        user.role === 'admin' ? h('button', { class: 'btn danger', onclick: async () => {
          if (await confirmBox('Hapus equipment', `Hapus “${e.name}” beserta sparepart dan parameternya?`)) { if (await attempt(() => api.post('equipment/delete', { id: e.id }), 'Equipment dihapus.')) go('eq/list'); }
        } }, 'Hapus') : null),
      h('div', { class: 'muted', style: 'margin:-6px 0 4px' }, [e.unit_name, e.area_name, e.code].filter(Boolean).join(' › ')),
      tabs, body);
    await show();
  };
  await load();
}

function summary(d, e) {
  const rel = d.reliability;
  const attrs = Object.entries(e.attrs || {}).filter(([k]) => k !== 'dm_id');
  const owner = (label, name) => h('div', { class: 'note' }, h('small', { class: 'muted' }, label), h('div', {}, name ? h('b', {}, name) : h('span', { class: 'late' }, 'belum diisi')));
  return h('div', { class: 'grid g2' },
    h('div', { class: 'card' }, h('h2', {}, 'Data equipment'), h('dl', { class: 'kv', style: 'margin-top:12px' },
      [['Unit', e.unit_name], ['Area', e.area_name], ['Kode', e.code], ['Merk', e.brand], ['Model', e.model], ['No. seri', e.serial_no], ['Kategori', e.category],
        ['Status', e.status], ['Tgl. pasang', e.install_date], ['Spesifikasi', e.spec], ['Catatan', e.notes], ...attrs.map(([k, v]) => [k, v])]
        .filter(([, v]) => v).map(([k, v]) => [h('dt', {}, k), h('dd', {}, String(v))]))),
    h('div', {},
      h('div', { class: 'card' }, h('h2', {}, 'Penanggung jawab mesin'), h('div', { class: 'grid g2', style: 'margin-top:10px' }, owner('Produksi', e.pj_production), owner('Maintenance', e.pj_maintenance))),
      h('div', { class: 'card' }, h('div', { class: 'page-head' }, h('h2', {}, 'Preventive Maintenance'), h('span', { class: 'muted' }, e.pm_type ? `tipe: ${e.pm_type}` : 'tipe PM belum diatur')),
        d.pm.length ? d.pm.map((s) => h('div', { class: 'note' }, stBadge(s.status), ' ', h('b', {}, s.periode), h('small', { class: 'muted' }, ` · terakhir ${s.last_done ? dt(s.last_done, false) : 'belum pernah'} · PIC ${s.pic || '—'}`), h('div', { class: 'muted' }, dueText(s)),
          h('a', { class: 'btn sm', style: 'margin-top:6px', href: `#/pm/form?schedule_id=${s.id}` }, 'Eksekusi PM'))) : h('p', { class: 'muted', style: 'margin:0' }, 'Belum ada jadwal PM untuk equipment ini (Schedule PM → + Jadwal).')),
      h('div', { class: 'card' }, h('h2', {}, 'Keandalan (dari Work Order)'), h('div', { class: 'rel', style: 'margin-top:12px' },
        ...[[rel.failures, 'Kerusakan'], [hours(rel.mttr_hours), 'MTTR'], [hours(rel.mtbf_hours), 'MTBF'], [hours(rel.downtime_hours), 'Total perbaikan']].map(([v, l]) => h('div', {}, h('b', {}, v), h('span', {}, l)))))));
}

/** Gabungan WO (dan PM) — setiap entri bisa dibuka rinciannya dan ditelusuri ke dokumen aslinya. */
async function historyPanel(e) {
  const d = await api.get('equipment/history', { id: e.id });
  let kind = '';
  const list = h('div');
  const draw = () => {
    const rows = d.entries.filter((x) => !kind || x.kind === kind);
    list.replaceChildren(...(rows.length ? rows.map(entry) : [h('div', { class: 'empty' }, kind === 'PM' ? 'Belum ada data Preventive Maintenance — modul PM belum dibangun.' : 'Belum ada riwayat untuk equipment ini.')]));
  };
  const entry = (x) => {
    const more = h('div', { style: 'display:none;margin-top:10px' },
      x.description ? h('div', {}, h('b', {}, 'Masalah: '), x.description) : null,
      x.closing_note ? h('div', {}, h('b', {}, 'Penyelesaian: '), x.closing_note) : null,
      x.what_text || x.why_text || x.how_text ? h('dl', { class: 'kv', style: 'margin-top:8px' }, [['Apa', x.what_text], ['Kenapa', x.why_text], ['Bagaimana', x.how_text], ['Siapa', x.who_text], ['Di mana', x.where_text]].filter(([, v]) => v).map(([k, v]) => [h('dt', {}, k), h('dd', {}, v)])) : null,
      h('div', { style: 'margin-top:8px' }, h('b', {}, 'Sparepart dipakai: '), x.parts.length ? x.parts.map((p) => h('span', { class: 'badge', style: 'margin:0 4px 4px 0' }, `${p.name} × ${num(p.qty)} ${p.uom}`)) : h('span', { class: 'muted' }, 'tidak ada')),
      h('div', { style: 'margin-top:8px;display:flex;gap:8px;flex-wrap:wrap' }, x.kind === 'PM' ? h('a', { class: 'btn sm', href: `#/pm/list?id=${encodeURIComponent(x.id)}` }, 'Buka laporan PM →') : h('a', { class: 'btn sm', href: `#/wo/detail?id=${encodeURIComponent(x.id)}` }, 'Buka Work Order →'),
        x.kind === 'PM' && x.wo_id ? h('a', { class: 'btn sm', href: `#/wo/detail?id=${encodeURIComponent(x.wo_id)}` }, `WO temuan: ${x.wo_id}`) : null));
    return h('div', { class: 'card', style: 'margin-bottom:10px' },
      h('div', { style: 'display:flex;gap:10px;align-items:center;flex-wrap:wrap' },
        h('span', { class: 'badge prog' }, x.kind), statusBadge(x.status), h('b', {}, x.title), h('span', { class: 'sp', style: 'flex:1' }),
        h('small', { class: 'muted' }, `${dt(x.date, false)} · ${x.type} · ${dur(x.work_seconds)}${x.techs.length ? ' · ' + x.techs.join(', ') : ''}`),
        h('button', { class: 'btn sm', onclick: (ev) => { const open = more.style.display === 'none'; more.style.display = open ? '' : 'none'; ev.currentTarget.textContent = open ? 'Tutup' : 'Rincian'; } }, 'Rincian')),
      x.source === 'dm' ? h('small', { class: 'muted' }, `Berasal dari Daily Monitoring ${x.source_ref ?? ''}`) : null, more);
  };
  const chip = (label, k) => h('button', { class: `chip${kind === k ? ' on' : ''}`, onclick: (ev) => { kind = k; ev.currentTarget.parentNode.querySelectorAll('.chip').forEach((c) => c.classList.remove('on')); ev.currentTarget.classList.add('on'); draw(); } }, label);
  draw();
  return h('div', {},
    h('div', { class: 'chips' }, chip('Semua', ''), chip(`Work Order (${d.entries.filter((x) => x.kind === 'WO').length})`, 'WO'), chip(`Preventive Maintenance (${d.entries.filter((x) => x.kind === 'PM').length})`, 'PM')),
    list);
}

/** Sparepart standar mesin (tertaut MaintWare) + pemakaian nyata dari MaintWare dan Work Order. */
async function sparePanel(d, e, canEdit, reload) {
  const u = await api.get('equipment/parts-used', { id: e.id });
  const low = (s) => s.item_id && Number(s.stock) < Number(s.min_stock);
  return h('div', {},
    h('div', { class: 'card' },
      h('div', { class: 'page-head' }, h('h2', {}, `Sparepart standar mesin (${d.spareparts.length})`), h('span', { class: 'muted' }, 'daftar part yang seharusnya tersedia; tautkan ke MaintWare untuk melihat stok'), h('span', { class: 'sp' }),
        canEdit ? h('button', { class: 'btn sm primary', onclick: () => spareDialog(e.id, {}, reload) }, '+ Sparepart') : null),
      table([
        { label: 'Nama', render: (s) => h('div', {}, h('b', {}, s.name), s.specification ? h('div', { class: 'muted' }, s.specification) : null) },
        { label: 'Jumlah/mesin', cls: 'num', render: (s) => (s.qty != null ? num(s.qty) : '—') },
        { label: 'MaintWare', render: (s) => (s.item_id ? h('span', {}, h('span', { class: 'mono' }, s.item_id), ' ', h('span', { class: low(s) ? 'late' : '' }, `stok ${num(s.stock)} ${s.uom}`), low(s) ? h('span', { class: 'badge prio-critical', style: 'margin-left:6px' }, 'LOW') : null) : h('span', { class: 'muted' }, 'belum ditautkan')) },
        { label: '', render: (s) => (canEdit ? h('button', { class: 'btn sm', onclick: (ev) => { ev.stopPropagation(); spareDialog(e.id, s, reload); } }, 'Edit') : null) },
      ], d.spareparts, { empty: 'Belum ada sparepart standar.' })),
    h('div', { class: 'card', style: 'margin-top:14px' }, h('h2', {}, 'Sparepart yang pernah dipakai'), h('p', { class: 'muted', style: 'margin:4px 0 8px' }, 'Dari transaksi OUT MaintWare (termasuk pemakaian lewat Work Order) pada equipment ini.'),
      table([
        { label: 'Item', render: (r) => h('div', {}, h('b', {}, r.name), h('div', { class: 'muted mono' }, r.id)) },
        { label: 'Total dipakai', cls: 'num', render: (r) => `${num(r.total)} ${r.uom}` }, { label: 'Kali', key: 'times', cls: 'num' },
        { label: 'Terakhir', render: (r) => dt(r.last_date, false) }, { label: 'Stok kini', cls: 'num', render: (r) => `${num(r.stock)} ${r.uom}` },
      ], u.summary, { empty: 'Belum ada pemakaian sparepart dari MaintWare.' }),
      u.free.length ? h('div', { style: 'margin-top:14px' }, h('h3', {}, 'Part di luar MaintWare (diisi bebas di WO)'), table([
        { label: 'Part', key: 'name' }, { label: 'Total', cls: 'num', render: (r) => `${num(r.total)} ${r.uom}` }, { label: 'Kali', key: 'times', cls: 'num' }, { label: 'Terakhir', render: (r) => dt(r.last_date, false) },
      ], u.free)) : null),
    u.detail.length ? h('div', { class: 'card', style: 'margin-top:14px' }, h('h2', {}, `Rincian pemakaian (${u.detail.length})`), table([
      { label: 'Tanggal', render: (r) => dt(r.trx_date) }, { label: 'Item', render: (r) => `${r.item_id} · ${r.item_name}` },
      { label: 'Qty', cls: 'num', render: (r) => h('span', { style: `color:var(--${r.trx_type === 'OUT' ? 'red' : 'green'})` }, `${r.trx_type === 'OUT' ? '−' : '+'}${num(r.qty)} ${r.uom}`) },
      { label: 'PIC', key: 'pic' }, { label: 'Work Order', render: (r) => (r.wo_id ? h('a', { class: 'mono', href: `#/wo/detail?id=${encodeURIComponent(r.wo_id)}` }, r.wo_id) : '—') },
      { label: 'Catatan', render: (r) => h('span', { class: 'muted' }, r.notes || '') },
    ], u.detail)) : null);
}

async function paramPanel(d, e, canEdit, types, reload, go) {
  return equipmentParamCard(e, d.params, { canEdit, types, reload, go });
}
