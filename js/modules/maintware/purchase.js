import { api } from '../../core/api.js';
import { h, attempt, modal, confirmBox, esc, dt } from '../../core/ui.js';
import { rp, num } from './shared.js';

const STATUSES = ['Draft', 'Open', 'Approved', 'Sent to Purchasing', 'Done', 'Cancelled'];
const CLS = { Draft: '', Open: 'prog', Approved: 'done', 'Sent to Purchasing': 'pend', Done: 'done', Cancelled: 'cancel' };

export default async function render(root, { user, query }) {
  const canApprove = ['spv', 'admin'].includes(user.role);
  let filter = '';
  const strip = h('div', { class: 'chips' });
  const list = h('div');

  const load = async () => {
    const r = await api.get('mw/ro', { status: filter });
    strip.replaceChildren(h('button', { class: `chip${filter === '' ? ' on' : ''}`, onclick: () => { filter = ''; load(); } }, 'Semua'),
      ...STATUSES.filter((s) => r.counts[s]).map((s) => h('button', { class: `chip${filter === s ? ' on' : ''}`, onclick: () => { filter = s; load(); } }, s, h('b', {}, r.counts[s]))),
      h('span', { class: 'muted', style: 'margin-left:auto;align-self:center' }, `Total estimasi aktif: ${rp(r.total_est)}`));
    list.replaceChildren(...(r.rows.length ? r.rows.map(card) : [h('div', { class: 'empty' }, 'Belum ada Request Order.')]));
  };
  const setStatus = async (ro, status) => {
    if (!(await confirmBox('Ubah status RO', `Ubah status ${ro.ro_no} ke “${status}”?`))) return;
    await attempt(async () => { await api.post('mw/ro/status', { ro_no: ro.ro_no, status }); await load(); }, `Status RO → ${status}`);
  };
  const NEXT = { Open: ['Approved'], Approved: ['Sent to Purchasing'], 'Sent to Purchasing': ['Done'] };
  const card = (ro) => h('div', { class: 'card', style: 'margin-bottom:12px' },
    h('div', { class: 'page-head' },
      h('b', { class: 'mono' }, ro.ro_no), h('span', { class: `badge ${CLS[ro.status]}` }, ro.status), ro.priority !== 'Normal' ? h('span', { class: 'badge prio-high' }, ro.priority) : null, h('span', { class: 'sp' }),
      h('b', {}, rp(ro.total_est))),
    h('div', { class: 'muted' }, `${ro.unit_name ? ro.unit_name + ' · ' : ''}${ro.dept} · ${ro.pic} · ${dt(ro.created_at)}${ro.needed_date ? ' · butuh ' + dt(ro.needed_date, false) : ''}`),
    ro.notes ? h('div', {}, ro.notes) : null,
    h('div', { style: 'margin:8px 0' }, ro.items.slice(0, 4).map((i) => h('span', { class: 'badge', style: 'margin:0 4px 4px 0' }, `${i.name} ×${num(i.qty)}`)), ro.items.length > 4 ? h('span', { class: 'muted' }, `+${ro.items.length - 4} lagi`) : null),
    h('div', { style: 'display:flex;gap:6px;flex-wrap:wrap' },
      h('button', { class: 'btn sm', onclick: () => view(ro) }, 'Lihat'),
      ['Draft', 'Open'].includes(ro.status) ? h('button', { class: 'btn sm', onclick: () => edit(ro) }, 'Edit') : null,
      h('button', { class: 'btn sm', onclick: () => print(ro) }, '🖨 Print'),
      canApprove ? [...(NEXT[ro.status] || []), ...(ro.status === 'Draft' ? ['Open'] : []), ...(!['Done', 'Cancelled'].includes(ro.status) ? ['Cancelled'] : [])]
        .map((s) => h('button', { class: `btn sm${s === 'Cancelled' ? ' danger' : ' primary'}`, onclick: () => setStatus(ro, s) }, s === 'Cancelled' ? 'Batalkan' : `→ ${s}`)) : null));

  const view = (ro) => modal(`${ro.ro_no} — ${ro.status}`, h('div', {}, h('dl', { class: 'kv' }, [['Unit', ro.unit_name], ['Tanggal', ro.ro_date], ['Departemen', ro.dept], ['PIC', ro.pic], ['Prioritas', ro.priority], ['Target butuh', ro.needed_date], ['Keperluan', ro.notes]]
    .filter(([, v]) => v).map(([k, v]) => [h('dt', {}, k), h('dd', {}, v)])),
  h('div', { class: 'tbl-wrap', style: 'margin-top:14px' }, h('table', { class: 'tbl' }, h('thead', {}, h('tr', {}, ['#', 'Kode', 'Item', 'Spesifikasi', 'Qty', 'Harga', 'Subtotal'].map((c) => h('th', {}, c)))),
    h('tbody', {}, ro.items.map((i, n) => h('tr', {}, h('td', {}, n + 1), h('td', { class: 'mono' }, i.item_id || '—'), h('td', {}, i.name), h('td', {}, i.spec || '—'), h('td', { class: 'num' }, num(i.qty)), h('td', { class: 'num' }, rp(i.unit_price)), h('td', { class: 'num' }, rp(i.qty * i.unit_price))))))),
  h('p', { style: 'text-align:right;margin-top:10px' }, h('b', {}, 'Total estimasi: ' + rp(ro.total_est)))), { wide: true, actions: [{ label: '🖨 Print', run: () => { print(ro); return false; } }] });

  const edit = (ro) => roDialog(ro, load);
  root.append(h('div', { class: 'page' },
    h('div', { class: 'page-head' }, h('h1', {}, 'Purchase Request'), h('span', { class: 'sp' }), h('button', { class: 'btn primary', onclick: () => roDialog(null, load, user) }, '+ Buat Request Order')),
    strip, list));
  await load();
  if (query.new) roDialog(null, load, user, JSON.parse(sessionStorage.getItem('mw.ro.prefill') || '[]'));
}

/** Dialog RO baru/edit; `prefill` = item dari Reorder List. */
export async function roDialog(ro, done, user, prefill) {
  const units = (await api.get('meta')).units;
  const catalog = (await api.get('mw/items', { limit: 500 })).rows;
  let items = ro ? ro.items.map((i) => ({ ...i })) : (prefill?.length ? prefill : [{ item_id: '', name: '', spec: '', qty: 1, unit_price: 0 }]);
  const pic = h('input', { value: ro?.pic ?? user?.name ?? '' }), dept = h('input', { value: ro?.dept ?? '', placeholder: 'mis. Electrical' });
  const notes = h('textarea', { rows: 2, placeholder: 'Keperluan' }); notes.value = ro?.notes ?? '';
  const prio = h('select', {}, ['Normal', 'Urgent', 'Critical'].map((p) => h('option', {}, p))); prio.value = ro?.priority ?? 'Normal';
  const needed = h('input', { type: 'date', value: ro?.needed_date ?? '' });
  const unit = h('select', {}, h('option', { value: '' }, '— pilih Unit —'), units.map((u) => h('option', { value: u.id }, u.name)));
  unit.value = String(ro?.unit_id ?? user?.unit_id ?? '');
  const rowsEl = h('div');
  const total = h('b', {});
  const draw = () => {
    total.textContent = 'Total estimasi: ' + rp(items.reduce((a, i) => a + (Number(i.qty) || 0) * (Number(i.unit_price) || 0), 0));
    rowsEl.replaceChildren(...items.map((it, idx) => {
      const sel = h('select', { onchange: (e) => {
        const c = catalog.find((x) => x.id === e.target.value);
        if (c) Object.assign(it, { item_id: c.id, name: c.name, spec: c.spec || '', unit_price: Number(c.price) || 0 }); else Object.assign(it, { item_id: '' });
        draw();
      } }, h('option', { value: '' }, '✏ Item manual / pilih dari daftar'), catalog.map((c) => h('option', { value: c.id }, `${c.id} — ${c.name}${c.brand ? ` [${c.brand}]` : ''}`)));
      sel.value = it.item_id || '';
      return h('div', { class: 'note' },
        h('div', { class: 'form-grid' }, h('label', { class: 'field wide' }, sel),
          h('label', { class: 'field' }, h('span', {}, 'Nama item *'), h('input', { value: it.name, oninput: (e) => { it.name = e.target.value; } })),
          h('label', { class: 'field' }, h('span', {}, 'Spesifikasi'), h('input', { value: it.spec || '', oninput: (e) => { it.spec = e.target.value; } })),
          h('label', { class: 'field' }, h('span', {}, 'Qty'), h('input', { type: 'number', min: '0.01', step: 'any', value: it.qty, oninput: (e) => { it.qty = e.target.value; total.textContent = 'Total estimasi: ' + rp(items.reduce((a, i) => a + (Number(i.qty) || 0) * (Number(i.unit_price) || 0), 0)); } })),
          h('label', { class: 'field' }, h('span', {}, 'Harga / unit'), h('input', { type: 'number', min: '0', step: 'any', value: it.unit_price, oninput: (e) => { it.unit_price = e.target.value; total.textContent = 'Total estimasi: ' + rp(items.reduce((a, i) => a + (Number(i.qty) || 0) * (Number(i.unit_price) || 0), 0)); } }))),
        h('button', { class: 'btn sm danger', style: 'margin-top:6px', onclick: () => { if (items.length > 1) { items.splice(idx, 1); draw(); } } }, 'Hapus baris'));
    }));
  };
  const save = (status) => async () => {
    const r = await attempt(() => api.post('mw/ro/save', { ro_no: ro?.ro_no, status, unit_id: Number(unit.value) || undefined, pic: pic.value, dept: dept.value, notes: notes.value, priority: prio.value, needed_date: needed.value, items }),
      status === 'Draft' ? 'Disimpan sebagai draft.' : 'Request Order disimpan.');
    if (!r) return false;
    await done();
  };
  modal(ro ? `Edit ${ro.ro_no}` : 'Buat Request Order', h('div', {},
    h('div', { class: 'form-grid' }, h('label', { class: 'field wide' }, h('span', {}, 'Unit *'), unit), h('label', { class: 'field' }, h('span', {}, 'PIC *'), pic), h('label', { class: 'field' }, h('span', {}, 'Departemen *'), dept),
      h('label', { class: 'field' }, h('span', {}, 'Prioritas'), prio), h('label', { class: 'field' }, h('span', {}, 'Target butuh'), needed), h('label', { class: 'field wide' }, h('span', {}, 'Keperluan'), notes)),
    h('h3', { style: 'margin:14px 0 8px' }, 'Item'), rowsEl,
    h('div', { style: 'display:flex;justify-content:space-between;align-items:center;margin-top:8px' },
      h('button', { class: 'btn sm', onclick: () => { items.push({ item_id: '', name: '', spec: '', qty: 1, unit_price: 0 }); draw(); } }, '+ Tambah baris'), total)), { wide: true, actions: [
    { label: 'Batal', run: () => {} },
    ...(!ro || ro.status === 'Draft' ? [{ label: 'Simpan draft', run: save('Draft') }] : []),
    { label: 'Submit', kind: 'primary', run: save('Open') },
  ] });
  draw();
}

function print(ro) {
  const rows = ro.items.map((i, n) => `<tr><td>${n + 1}</td><td>${esc(i.item_id || '—')}</td><td>${esc(i.name)}</td><td>${esc(i.spec || '—')}</td><td style="text-align:center">${num(i.qty)}</td><td>${rp(i.unit_price)}</td><td>${rp(i.qty * i.unit_price)}</td></tr>`).join('');
  const w = window.open('', '_blank');
  if (!w) return;
  w.document.write(`<!doctype html><html lang="id"><head><meta charset="utf-8"><title>PR ${esc(ro.ro_no)}</title><style>
    body{font-family:Arial,sans-serif;font-size:12px;color:#111;padding:24px}h1{font-size:20px;color:#12263f;margin:0}table{width:100%;border-collapse:collapse;margin:16px 0}
    th,td{border:1px solid #ccd;padding:6px 8px;text-align:left}th{background:#eef1f6}.meta{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-top:14px}.meta div{border:1px solid #dde;padding:6px 8px}
    .meta small{display:block;color:#889;font-size:10px;text-transform:uppercase}.sign{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-top:40px;text-align:center}.sign div{border-top:1px solid #333;padding-top:4px}</style></head><body>
    <h1>Purchase Request</h1><div>No: <b>${esc(ro.ro_no)}</b> · Status: <b>${esc(ro.status)}</b> · Prioritas: <b>${esc(ro.priority)}</b></div>
    <div class="meta"><div><small>Unit</small>${esc(ro.unit_name || '—')}</div><div><small>Tanggal</small>${esc(ro.ro_date)}</div><div><small>Target butuh</small>${esc(ro.needed_date || '—')}</div><div><small>Requester</small>${esc(ro.pic)}</div>
    <div><small>Departemen</small>${esc(ro.dept)}</div><div style="grid-column:span 2"><small>Keperluan</small>${esc(ro.notes || '—')}</div></div>
    <table><thead><tr><th>#</th><th>Kode</th><th>Item</th><th>Spesifikasi</th><th>Qty</th><th>Est. harga</th><th>Subtotal</th></tr></thead><tbody>${rows}</tbody>
    <tfoot><tr><td colspan="6" style="text-align:right"><b>Total estimasi</b></td><td><b>${rp(ro.total_est)}</b></td></tr></tfoot></table>
    <div class="sign"><div>Dibuat oleh<br>${esc(ro.pic)}</div><div>Diperiksa<br>Supervisor</div><div>Disetujui<br>Manager</div><div>Purchasing</div></div>
    <script>setTimeout(()=>print(),400)<\/script></body></html>`);
  w.document.close();
}
