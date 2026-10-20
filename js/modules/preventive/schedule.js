import { api } from '../../core/api.js';
import { h, attempt, modal, form, confirmBox, table, dt } from '../../core/ui.js';
import { stBadge, dueText, equipmentPicker, PERIODS, MONTHS } from './shared.js';

/** Schedule PM: daftar jadwal (tab 1) dan kalender bulanan (tab 2). Jadwal menunjuk equipment di Equipment Database. */
export default async function render(root, ctx) {
  const { meta, user, go, query } = ctx;
  const manage = ['admin', 'spv'].includes(user.role);
  let tab = query.tab === 'kalender' ? 'kalender' : 'daftar';
  const f = { unit_id: '', area_id: '', status: query.status || '', periode: '', q: '' };
  const body = h('div');
  const tabs = h('div', { class: 'tabs' }, [['daftar', 'Daftar Jadwal'], ['kalender', 'Kalender']].map(([k, l]) => h('button', { class: `tab${tab === k ? ' on' : ''}`, 'data-t': k, onclick: () => { tab = k; tabs.querySelectorAll('.tab').forEach((t) => t.classList.toggle('on', t.dataset.t === k)); show(); } }, l)));

  const areaSel = h('select', { 'aria-label': 'Area', onchange: (e) => { f.area_id = e.target.value; show(); } });
  const fillAreas = () => areaSel.replaceChildren(h('option', { value: '' }, 'Semua area'), ...meta.areas.filter((a) => !f.unit_id || a.unit_id == f.unit_id).map((a) => h('option', { value: a.id }, a.name)));
  const unitSel = h('select', { 'aria-label': 'Unit', onchange: (e) => { f.unit_id = e.target.value; f.area_id = ''; fillAreas(); show(); } }, h('option', { value: '' }, 'Semua unit'), meta.units.map((u) => h('option', { value: u.id }, u.name)));
  const statusSel = h('select', { 'aria-label': 'Status', onchange: (e) => { f.status = e.target.value; show(); } }, [['', 'Semua status'], ['overdue', 'Terlambat'], ['upcoming', 'Akan datang'], ['done', 'Selesai']].map(([v, l]) => h('option', { value: v }, l)));
  statusSel.value = f.status;
  fillAreas();
  let t;

  const list = async () => {
    const r = await api.get('pm/schedules', f);
    return h('div', {}, h('div', { class: 'chips' }, h('span', { class: 'chip' }, 'Selesai', h('b', {}, r.counts.done)), h('span', { class: 'chip' }, 'Akan datang', h('b', {}, r.counts.upcoming)), h('span', { class: 'chip' }, 'Terlambat', h('b', {}, r.counts.overdue))),
      table([
        { label: 'ID', render: (s) => h('span', { class: 'mono' }, s.id) },
        { label: 'Equipment', render: (s) => h('div', {}, h('b', {}, s.equipment_name), h('div', { class: 'muted' }, `${s.unit_name} › ${s.area_name}${s.equipment_code ? ' · ' + s.equipment_code : ''}`)) },
        { label: 'Periode', render: (s) => `${s.periode}${s.month ? ` · ${MONTHS[s.month - 1].slice(0, 3)} M${s.week ?? 1}` : ''}` },
        { label: 'PIC', render: (s) => h('span', {}, s.pic || '—', s.takeover_at ? h('small', { class: 'muted', title: s.takeover_reason }, ' (takeover)') : null) },
        { label: 'Terakhir', render: (s) => (s.last_done ? dt(s.last_done, false) : h('span', { class: 'muted' }, 'belum pernah')) },
        { label: 'Jatuh tempo', render: (s) => dueText(s) }, { label: 'Status', render: (s) => stBadge(s.status) },
        { label: '', render: (s) => h('div', { style: 'display:flex;gap:4px' }, h('button', { class: 'btn sm primary', onclick: (e) => { e.stopPropagation(); go('pm/form', { schedule_id: s.id }); } }, 'Eksekusi'),
          manage ? h('button', { class: 'btn sm', onclick: (e) => { e.stopPropagation(); scheduleDialog(meta, s, () => show()); } }, 'Edit') : null) },
      ], r.rows, { empty: 'Belum ada jadwal PM yang cocok.' }));
  };

  const calendar = async () => {
    const st = (calendar.st ??= { year: new Date().getFullYear(), month: new Date().getMonth() + 1 });
    const d = await api.get('pm/calendar', { ...f, year: st.year, month: st.month });
    const days = new Date(st.year, st.month, 0).getDate();
    const weeks = Math.ceil(days / 7);
    const weekOf = (date) => Math.ceil(Number(date.slice(8, 10)) / 7);
    const col = (w) => {
      const cards = [
        ...d.planned.filter((p) => p.week === w).map((p) => h('div', { class: 'note', style: 'cursor:pointer', onclick: () => go('pm/form', { schedule_id: p.schedule_id }) },
          h('div', {}, stBadge(p.status), ' ', h('b', {}, p.equipment_name)), h('small', {}, `${dt(p.date, false)} · ${p.area_name} · ${p.periode}${p.pic ? ' · ' + p.pic : ''}`))),
        ...d.done.filter((x) => weekOf(x.report_date) === w).map((x) => h('div', { class: 'note', style: 'border-left:3px solid var(--green);cursor:pointer', onclick: () => go('pm/list', { id: x.id }) },
          h('div', {}, h('span', { class: 'badge done' }, 'Dikerjakan'), ' ', h('b', {}, x.equipment_name)), h('small', {}, `${dt(x.report_date, false)} · ${x.teknisi}`))),
      ];
      return h('div', { class: 'card', style: 'margin-bottom:0' }, h('h3', {}, `Minggu ${w}`, h('small', { class: 'muted' }, ` · tgl ${(w - 1) * 7 + 1}–${Math.min(w * 7, days)}`)),
        h('div', { style: 'margin-top:8px' }, cards.length ? cards : h('p', { class: 'muted' }, '—')));
    };
    const shift = (n) => { st.month += n; if (st.month > 12) { st.month = 1; st.year++; } if (st.month < 1) { st.month = 12; st.year--; } show(); };
    return h('div', {}, h('div', { class: 'toolbar' }, h('button', { class: 'btn sm', onclick: () => shift(-1) }, '‹'), h('b', { style: 'min-width:150px;text-align:center' }, `${MONTHS[st.month - 1]} ${st.year}`), h('button', { class: 'btn sm', onclick: () => shift(1) }, '›'),
      h('span', { class: 'muted' }, `${d.planned.length} terjadwal · ${d.done.length} sudah dikerjakan`)),
    h('div', { class: 'grid g3' }, Array.from({ length: weeks }, (_, i) => col(i + 1))));
  };

  const show = async () => { body.replaceChildren(h('div', { class: 'loading' }, 'Memuat…')); body.replaceChildren(await (tab === 'daftar' ? list() : calendar())); };
  root.append(h('div', { class: 'page' }, h('div', { class: 'page-head' }, h('h1', {}, 'Schedule PM'), h('span', { class: 'sp' }), manage ? h('button', { class: 'btn primary', onclick: () => scheduleDialog(meta, {}, () => show()) }, '+ Jadwal') : null),
    h('div', { class: 'toolbar' }, h('input', { class: 'grow', type: 'search', placeholder: 'Cari equipment, kode, PIC…', oninput: (e) => { clearTimeout(t); t = setTimeout(() => { f.q = e.target.value; show(); }, 300); } }), unitSel, areaSel, statusSel,
      h('select', { 'aria-label': 'Periode', onchange: (e) => { f.periode = e.target.value; show(); } }, h('option', { value: '' }, 'Semua periode'), PERIODS.map((p) => h('option', {}, p)))),
    tabs, body));
  await show();
}

export function scheduleDialog(meta, s, done) {
  let eq = s.equipment_id ? { id: s.equipment_id, name: s.equipment_name } : null;
  const picker = s.id ? h('p', {}, h('b', {}, s.equipment_name), h('span', { class: 'muted' }, ` ${s.unit_name} › ${s.area_name}`)) : equipmentPicker(meta, (e) => { eq = e; });
  const f = form([
    { name: 'periode', label: 'Periode', type: 'select', options: PERIODS }, { name: 'month', label: 'Bulan jadwal awal', type: 'select', optional: true, options: MONTHS.map((m, i) => ({ value: i + 1, label: m })) },
    { name: 'week', label: 'Minggu ke-', type: 'select', optional: true, options: [1, 2, 3, 4, 5].map((n) => ({ value: n, label: `Minggu ${n}` })) }, { name: 'start_date', label: 'Atau tanggal mulai', type: 'date' },
    { name: 'pic', label: 'PIC' }, { name: 'mp', label: 'Man power', type: 'number', min: 1 }, { name: 'hari', label: 'Estimasi hari', type: 'number', min: 1 }, { name: 'notes', label: 'Catatan', type: 'textarea', wide: true },
  ], { periode: 'Annual', ...s });
  modal(s.id ? `Edit jadwal ${s.id}` : 'Tambah jadwal PM', h('div', {}, picker, h('div', { style: 'height:12px' }), f.node), { wide: true, actions: [
    ...(s.id ? [{ label: 'Hapus', kind: 'danger', run: async () => { if (!(await confirmBox('Hapus jadwal', `Hapus jadwal ${s.id}? Laporan lama tetap tersimpan.`))) return false; return (await attempt(async () => { await api.post('pm/schedule/delete', { id: s.id }); await done(); }, 'Jadwal dihapus.')) ? true : false; } }] : []),
    { label: 'Simpan', kind: 'primary', run: async () => {
      if (!eq) { await attempt(() => { throw new Error('Pilih equipment.'); }); return false; }
      return (await attempt(async () => { await api.post('pm/schedule/save', { id: s.id, equipment_id: Number(eq.id), ...f.values() }); await done(); }, 'Jadwal disimpan.')) ? true : false;
    } },
  ] });
}
