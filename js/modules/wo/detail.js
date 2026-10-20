import { api } from '../../core/api.js';
import { filesTab } from './files.js';
import { h, attempt, modal, form, confirmBox, statusBadge, prioBadge, liveTimer, dt, dur, hours } from '../../core/ui.js';

const TABS = ['Detail', 'Parts Used', 'Notes', 'Foto & Lampiran', 'Tasklist', 'Equipment History', 'Timeline'];
const WORKERS = ['maintenance', 'spv', 'admin'];
const LEADERS = ['spv', 'admin'];

export default async function render(root, ctx) {
  const { query, user, meta, go } = ctx;
  let tab = query.tab || 'Detail';
  const id = query.id;
  if (!id) throw new Error('ID Work Order tidak ada.');

  const host = h('div', { class: 'page' });
  root.append(host);

  const load = async () => {
    const d = await api.get('wo/get', { id });
    draw(d);
  };

  const act = (action, extra = {}, okMsg) => attempt(async () => {
    await api.post('wo/action', { id, action, ...extra });
    await load();
  }, okMsg);

  const ask = (title, label, run, { required = true, kind = 'primary', btn = 'Simpan' } = {}) => {
    const ta = h('textarea', { rows: 4, placeholder: label });
    modal(title, h('div', {}, h('label', { class: 'field' }, h('span', {}, label), ta)), { actions: [
      { label: 'Batal', run: () => {} },
      { label: btn, kind, run: async () => {
        if (required && !ta.value.trim()) { await attempt(() => { throw new Error(`${label} wajib diisi.`); }); return false; }
        return (await run(ta.value.trim())) ? true : false;
      } },
    ] });
  };

  function draw(d) {
    const { wo } = d;
    const running = !!wo.running_since;
    const isOwner = wo.requestor_id === user.id;
    const isWorker = WORKERS.includes(user.role);
    const isLeader = LEADERS.includes(user.role);
    const final = ['Closed', 'Cancelled'].includes(wo.status);
    const base = wo.work_seconds - (running ? Math.max(0, Math.floor((Date.now() - new Date(wo.running_since.replace(' ', 'T')).getTime()) / 1000)) : 0);

    const actions = [];
    if (wo.status === 'Open') {
      if (isLeader) actions.push(btn('Assign teknisi', () => assignDialog(d, load)));
      if (isWorker) actions.push(btn('▶ Start', () => act('start', {}, 'Pengerjaan dimulai.'), 'primary'));
      if (isOwner || isLeader) actions.push(btn('Batalkan', () => ask('Batalkan WO', 'Alasan pembatalan', (note) => act('cancel', { note }, 'WO dibatalkan.'), { required: false, kind: 'danger', btn: 'Batalkan WO' }), 'danger'));
    }
    if (wo.status === 'In Progress' && isWorker) {
      if (running) actions.push(btn('⏸ Hold', () => ask('Hold — hentikan timer', 'Pekerjaan yang dilakukan hari ini', (note) => act('hold', { note }, 'Timer dihentikan (Hold).'), { btn: 'Hold' }), 'warn'));
      else actions.push(btn('▶ Continue', () => act('continue', {}, 'Timer berjalan lagi.'), 'primary'));
      actions.push(btn('✔ Selesai', () => ask('Selesaikan pekerjaan', 'Catatan penutupan WO (apa yang dikerjakan / hasil)', (note) => act('finish', { note }, 'Menunggu verifikasi user.'), { btn: 'Kirim ke verifikasi' }), 'primary'));
    }
    if (wo.status === 'Pending Verification' && (isOwner || isLeader)) {
      actions.push(btn('✔ Verifikasi & Tutup', () => verifyDialog(), 'primary'));
      actions.push(btn('Tolak', () => ask('Tolak hasil pekerjaan', 'Alasan penolakan', (note) => act('reject', { note }, 'Dikembalikan ke teknisi.'), { kind: 'danger', btn: 'Tolak' }), 'danger'));
    }

    const verifyDialog = () => {
      let stars = 0;
      const row = h('div', { class: 'star-pick' });
      const draw5 = () => row.replaceChildren(...[1, 2, 3, 4, 5].map((n) => h('button', { type: 'button', class: n <= stars ? 'on' : '', 'aria-label': `${n} bintang`, onclick: () => { stars = n; draw5(); } }, '★')));
      draw5();
      const c = h('textarea', { rows: 2, placeholder: 'Komentar (opsional)' });
      modal('Verifikasi WO', h('div', {}, h('p', {}, 'Pekerjaan sudah sesuai? WO akan ditutup. Beri penilaian untuk teknisi:'), row, h('div', { style: 'height:8px' }), c), { actions: [
        { label: 'Batal', run: () => {} },
        { label: 'Tutup WO', kind: 'primary', run: async () => (await act('verify', { rating: stars, rating_comment: c.value }, 'WO ditutup.')) ? true : false },
      ] });
    };

    const panels = {
      'Detail': () => detailTab(d),
      'Parts Used': () => partsTab(d, { id, isWorker, final, meta, reload: load }),
      'Notes': () => notesTab(d, { id, user, final, reload: load }),
      'Foto & Lampiran': () => filesTab(d, { id, user, final, reload: load }),
      'Tasklist': () => tasksTab(d, { isLeader, isWorker, final, reload: load, id }),
      'Equipment History': () => historyTab(d, { isLeader, id, reload: load }),
      'Timeline': () => timelineTab(d),
    };
    const body = h('div');
    const showTab = () => {
      tabs.querySelectorAll('.tab').forEach((t) => t.classList.toggle('on', t.dataset.t === tab));
      body.replaceChildren(panels[tab]());
    };
    const tabs = h('div', { class: 'tabs', role: 'tablist' }, TABS.map((t) =>
      h('button', { class: 'tab', role: 'tab', 'data-t': t, onclick: () => { tab = t; showTab(); } }, t, tabCount(t, d))));

    host.replaceChildren(
      h('div', { class: 'page-head' },
        h('a', { href: '#/wo/list', class: 'btn sm' }, '← Work Orders'),
        h('span', { class: 'mono muted' }, wo.id)),
      h('div', { class: 'card' },
        h('h1', {}, wo.title),
        h('div', { class: 'status-bar', style: 'margin-top:10px' },
          statusBadge(wo.status, wo.status === 'In Progress' && !running), prioBadge(wo.priority),
          h('span', {}, 'Durasi: ', liveTimer(base, wo.running_since)),
          h('div', { class: 'actions' }, actions))),
      tabs, body);
    showTab();
  }

  await load();
}

const btn = (label, fn, kind = '') => h('button', { class: `btn ${kind}`, onclick: fn }, label);
const tabCount = (t, d) => {
  const n = { 'Parts Used': d.parts.length, 'Notes': d.notes.length, 'Foto & Lampiran': d.files.length, 'Tasklist': d.tasks.length }[t];
  return n ? h('b', { class: 'muted', style: 'margin-left:6px' }, n) : null;
};

function detailTab(d) {
  const { wo } = d;
  const eq = wo.equipment;
  const rel = d.reliability;
  return h('div', { class: 'grid g2' },
    h('div', { class: 'card' }, h('h2', {}, 'Informasi WO'), h('dl', { class: 'kv', style: 'margin-top:12px' },
      kv('Tipe', wo.type), kv('Unit', wo.unit_name), kv('Equipment', eq ? `${eq.name} (${eq.area_name})` : '—'),
      kv('Pemohon', `${wo.requestor_name ?? '—'}${wo.department ? ' · ' + wo.department : ''}`),
      kv('Teknisi', d.techs.length ? d.techs.map((t) => t.name).join(', ') : 'Belum ditugaskan'),
      kv('Dibuat', dt(wo.created_at)), kv('Target selesai', wo.due_date ? dt(wo.due_date, false) : '—'),
      kv('Mulai dikerjakan', dt(wo.started_at)), kv('Selesai dikerjakan', dt(wo.finished_at)), kv('Ditutup', dt(wo.closed_at)),
      wo.source !== 'wo' ? kv('Asal', `${wo.source.toUpperCase()} ${wo.source_ref ?? ''}`) : null)),
    h('div', {},
      h('div', { class: 'card' }, h('h2', {}, 'Deskripsi masalah'), h('p', { style: 'white-space:pre-wrap' }, wo.description || '—'),
        wo.closing_note ? h('div', {}, h('h2', { style: 'margin-top:14px' }, 'Catatan penutupan'), h('p', { style: 'white-space:pre-wrap' }, wo.closing_note)) : null,
        wo.rating ? h('p', {}, 'Rating: ', h('span', { class: 'stars' }, '★'.repeat(wo.rating) + '☆'.repeat(5 - wo.rating)), wo.rating_comment ? ` “${wo.rating_comment}”` : '') : null),
      h('div', { class: 'card' }, h('h2', {}, 'Sesi kerja (timer)'),
        d.sessions.length ? h('div', { style: 'margin-top:8px' }, d.sessions.map((s) => h('div', { class: 'note' },
          h('b', {}, `${dt(s.started_at)} → ${s.ended_at ? dt(s.ended_at) : 'berjalan'}`), ' ',
          s.ended_at ? h('span', { class: 'muted' }, `(${dur((new Date(s.ended_at.replace(' ', 'T')) - new Date(s.started_at.replace(' ', 'T'))) / 1000)})`) : null,
          s.note ? h('div', {}, s.note) : null))) : h('p', { class: 'muted' }, 'Belum ada sesi kerja.')),
      rel ? h('div', { class: 'card' }, h('h2', {}, 'Keandalan equipment ini'), h('div', { class: 'rel', style: 'margin-top:10px' },
        relCell(rel.failures, 'Kerusakan'), relCell(hours(rel.mttr_hours), 'MTTR'), relCell(hours(rel.mtbf_hours), 'MTBF'), relCell(hours(rel.downtime_hours), 'Total perbaikan'))) : null));
}
const kv = (k, v) => (v == null ? null : [h('dt', {}, k), h('dd', {}, v)]);
const relCell = (v, l) => h('div', {}, h('b', {}, v), h('span', {}, l));

function partsTab(d, { id, isWorker, final, meta, reload }) {
  const wrap = h('div', { class: 'card' });
  const rows = d.parts.length ? h('div', { class: 'tbl-wrap' }, h('table', { class: 'tbl' },
    h('thead', {}, h('tr', {}, ['Part', 'Qty', 'Oleh', 'Waktu', ''].map((c) => h('th', {}, c)))),
    h('tbody', {}, d.parts.map((p) => h('tr', {},
      h('td', { 'data-label': 'Part' }, p.name, p.item_id ? h('small', { class: 'muted' }, ` · ${p.item_id}`) : null),
      h('td', { 'data-label': 'Qty', class: 'num' }, `${p.qty} ${p.uom}`), h('td', { 'data-label': 'Oleh' }, p.added_by), h('td', { 'data-label': 'Waktu' }, dt(p.added_at)),
      h('td', {}, isWorker && !final ? h('button', { class: 'btn sm danger', onclick: () => attempt(async () => { await api.post('wo/part/delete', { part_id: p.id }); await reload(); }) }, 'Hapus') : null)))))) : h('p', { class: 'muted' }, 'Belum ada part terpakai.');
  wrap.append(h('div', { class: 'page-head' }, h('h2', {}, 'Parts Used'), h('span', { class: 'sp' }),
    isWorker && !final ? h('button', { class: 'btn primary sm', onclick: () => partPicker(id, meta, reload) }, '+ Tambah part dari MaintWare') : null), rows);
  return wrap;
}

function partPicker(id, meta, reload) {
  const unit = h('select', {}, h('option', { value: '' }, 'Semua unit'), meta.units.map((u) => h('option', { value: u.id }, u.name)));
  const q = h('input', { type: 'search', placeholder: 'Cari part / kode / spesifikasi…' });
  const qty = h('input', { type: 'number', min: '0.01', step: 'any', value: '1' });
  const res = h('div', { style: 'margin-top:10px;max-height:300px;overflow:auto' });
  let picked = null;
  const search = async () => {
    const r = await api.get('mw/items', { unit_id: unit.value, q: q.value });
    res.replaceChildren(...(r.rows.length ? r.rows.map((i) => h('div', { class: `note click${picked?.id === i.id ? ' on' : ''}`, style: `cursor:pointer;${picked?.id === i.id ? 'border-color:var(--green);' : ''}`,
      onclick: () => { picked = i; search(); } },
    h('b', {}, i.name), h('small', { class: 'muted' }, ` ${i.id} · stok ${i.qty} ${i.uom} · ${meta.units.find((u) => u.id == i.unit_id)?.name ?? '—'}`), i.spec ? h('div', { class: 'muted' }, i.spec) : null)) : [h('div', { class: 'empty' }, 'Item tidak ditemukan di MaintWare.')]));
  };
  let t;
  q.oninput = () => { clearTimeout(t); t = setTimeout(search, 250); };
  unit.onchange = search;
  modal('Tambah Part (MaintWare)', h('div', {}, h('div', { class: 'toolbar' }, unit, q), res,
    h('label', { class: 'field', style: 'margin-top:10px' }, h('span', {}, 'Qty dipakai'), qty)), { wide: true, actions: [
    { label: 'Batal', run: () => {} },
    { label: 'Tambahkan', kind: 'primary', run: async () => {
      if (!picked) { await attempt(() => { throw new Error('Pilih item dulu.'); }); return false; }
      return (await attempt(async () => { await api.post('wo/part', { id, item_id: picked.id, qty: Number(qty.value) }); await reload(); }, 'Part ditambahkan.')) ? true : false;
    } },
  ] });
  search();
}

function notesTab(d, { id, user, final, reload }) {
  const ta = h('textarea', { rows: 3, placeholder: 'Catatan reparasi / maintenance…' });
  return h('div', { class: 'card' },
    final ? null : h('div', {}, ta, h('button', { class: 'btn primary sm', style: 'margin:8px 0 14px', onclick: async () => {
      if (!ta.value.trim()) return;
      await attempt(async () => { await api.post('wo/note', { id, note: ta.value }); await reload(); }, 'Catatan disimpan.');
    } }, 'Simpan catatan')),
    d.notes.length ? d.notes.map((n) => h('div', { class: 'note' }, h('div', { style: 'white-space:pre-wrap' }, n.note), h('small', {}, `${n.author} · ${dt(n.created_at)}`))) : h('p', { class: 'muted' }, 'Belum ada catatan.'));
}

function tasksTab(d, { isLeader, isWorker, final, reload, id }) {
  const input = h('input', { placeholder: 'Milestone / task baru…' });
  const done = d.tasks.filter((t) => t.done).length;
  return h('div', { class: 'card' },
    h('div', { class: 'page-head' }, h('h2', {}, 'Tasklist'), h('span', { class: 'muted' }, d.tasks.length ? `${done}/${d.tasks.length} selesai` : 'opsional — dibuat Leader/SPV')),
    isLeader && !final ? h('div', { class: 'toolbar' }, input, h('button', { class: 'btn primary sm', onclick: async () => {
      if (!input.value.trim()) return;
      await attempt(async () => { await api.post('wo/task', { id, text: input.value }); await reload(); });
    } }, '+ Tambah')) : null,
    d.tasks.length ? d.tasks.map((t) => h('div', { class: `task${t.done ? ' done' : ''}` },
      h('input', { type: 'checkbox', checked: !!t.done, disabled: !isWorker || final, 'aria-label': t.text, onchange: () => attempt(async () => { await api.post('wo/task/toggle', { task_id: t.id }); await reload(); }) }),
      h('span', {}, t.text), h('span', { class: 'sp' }), t.done_by ? h('small', { class: 'muted' }, `${t.done_by} · ${dt(t.done_at)}`) : null,
      isLeader && !final ? h('button', { class: 'icon-btn', 'aria-label': 'Hapus task', onclick: () => attempt(async () => { await api.post('wo/task/delete', { task_id: t.id }); await reload(); }) }, '✕') : null))
      : h('p', { class: 'muted' }, 'Belum ada task.'));
}

function historyTab(d, { isLeader, id, reload }) {
  const hs = d.history;
  const fields = [['what', 'Apa — masalah & perbaikan yang dilakukan'], ['when', 'Kapan'], ['where', 'Di mana'], ['who', 'Siapa'], ['how', 'Bagaimana'], ['why', 'Kenapa — akar penyebab']];
  const f = form(fields.map(([name, label]) => ({ name, label, type: 'textarea', rows: 2, wide: true })),
    hs ? Object.fromEntries(fields.map(([k]) => [k, hs[`${k}_text`]])) : {});
  const wo = d.wo;
  if (!hs) { // isi awal dari data WO supaya Leader tinggal melengkapi
    f.inputs.when.value = dt(wo.started_at || wo.created_at, false);
    f.inputs.where.value = [wo.unit_name, wo.equipment?.area_name].filter(Boolean).join(' / ');
    f.inputs.who.value = d.techs.map((t) => t.name).join(', ');
    f.inputs.what.value = wo.closing_note || '';
  }
  return h('div', { class: 'card' }, h('div', { class: 'page-head' }, h('h2', {}, 'Equipment History'), h('span', { class: 'muted' }, 'tidak wajib · diisi Leader/SPV dari catatan penutupan WO')),
    isLeader ? [f.node, h('div', { style: 'margin-top:12px' }, h('button', { class: 'btn primary', onclick: () => attempt(async () => { await api.post('wo/history', { id, ...f.values() }); await reload(); }, 'History disimpan.') }, 'Simpan history'))]
      : hs ? h('dl', { class: 'kv' }, fields.map(([k, l]) => [h('dt', {}, l.split(' —')[0]), h('dd', {}, hs[`${k}_text`] || '—')])) : h('p', { class: 'muted' }, 'Belum diisi Leader/SPV.'));
}

function timelineTab(d) {
  const rel = d.reliability;
  return h('div', { class: 'grid g2' },
    h('div', { class: 'card' }, h('h2', {}, 'Aktivitas WO'), h('ul', { class: 'tl', style: 'margin-top:14px' },
      d.events.map((e) => h('li', {}, h('div', {}, e.message), h('small', {}, `${e.actor ?? ''} · ${dt(e.created_at)}`))))),
    h('div', {},
      rel ? h('div', { class: 'card' }, h('h2', {}, 'MTTR & MTBF equipment'), h('div', { class: 'rel', style: 'margin:12px 0' },
        relCell(rel.failures, 'Kali perbaikan'), relCell(hours(rel.mttr_hours), 'MTTR'), relCell(hours(rel.mtbf_hours), 'MTBF'), relCell(hours(rel.downtime_hours), 'Total perbaikan')),
      h('p', { class: 'muted' }, 'MTTR = rata-rata durasi kerja per kerusakan. MTBF = rata-rata jarak waktu antar kerusakan (Troubleshooting & Corrective).')) : null,
      h('div', { class: 'card' }, h('h2', {}, 'Riwayat WO equipment ini'), d.equipment_wo.length ? h('div', { style: 'margin-top:10px' }, d.equipment_wo.map((w) =>
        h('div', { class: 'note' }, h('a', { href: `#/wo/detail?id=${encodeURIComponent(w.id)}` }, h('b', {}, w.title)), h('div', {}, statusBadge(w.status), ' ', h('small', { class: 'muted' }, `${w.type} · ${dt(w.created_at, false)} · ${dur(w.work_seconds)}`))))) : h('p', { class: 'muted' }, '—'))));
}

function assignDialog(d, reload) {
  // dipanggil dari tombol Assign; meta teknisi dimuat ulang agar daftar terbaru
  api.get('meta').then(({ technicians }) => {
    const current = new Set(d.techs.map((t) => t.id));
    const boxes = technicians.filter((t) => t.active).map((t) => {
      const c = h('input', { type: 'checkbox', value: t.id, checked: current.has(t.id) });
      return { c, node: h('label', { class: 'task' }, c, h('span', {}, t.name), t.spec ? h('small', { class: 'muted' }, ` ${t.spec}`) : null) };
    });
    modal('Assign teknisi', boxes.length ? h('div', {}, boxes.map((b) => b.node)) : h('p', { class: 'muted' }, 'Belum ada teknisi aktif.'), { actions: [
      { label: 'Batal', run: () => {} },
      { label: 'Simpan', kind: 'primary', run: async () => (await attempt(async () => {
        await api.post('wo/action', { id: d.wo.id, action: 'assign', tech_ids: boxes.filter((b) => b.c.checked).map((b) => Number(b.c.value)) });
        await reload();
      }, 'Teknisi ditugaskan.')) ? true : false },
    ] });
  });
}
