import { api } from '../../core/api.js';
import { h, attempt, modal, confirmBox, esc, dt, table, toast } from '../../core/ui.js';

export const MONTHS = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
export const PERIODS = ['Daily', 'Weekly', 'Monthly', 'Quarterly', 'Semi-Annual', 'Annual'];
export const today = () => new Date().toLocaleDateString('sv-SE');
export const PPE = { helm: 'Helm', masker: 'Masker', sarungTangan: 'Sarung Tangan', earPlug: 'Ear Plug', sepatu: 'Sepatu Safety', harness: 'Harness', lain: 'PPE Lain' };

const ST = { done: ['done', 'Selesai'], upcoming: ['prog', 'Akan datang'], overdue: ['prio-critical', 'Terlambat'] };
export const stBadge = (s) => h('span', { class: `badge ${ST[s]?.[0] ?? ''}` }, ST[s]?.[1] ?? s);
export const dueText = (r) => {
  const d = Math.round((new Date(r.next_due) - new Date(today())) / 86400000);
  return `${dt(r.next_due, false)} ${d < 0 ? `(${-d} hari lewat)` : d === 0 ? '(hari ini)' : `(${d} hari lagi)`}`;
};

/** Pilih Unit → Area → Equipment dari Equipment Database; panggil onPick(equipment) saat dipilih. */
export function equipmentPicker(meta, onPick, { initial } = {}) {
  const unit = h('select', {}, meta.units.map((u) => h('option', { value: u.id }, u.name)));
  const area = h('select', {});
  const eq = h('select', {}, h('option', { value: '' }, 'Pilih area dulu…'));
  const search = h('input', { type: 'search', placeholder: 'Cari equipment…' });
  const fillAreas = () => { area.replaceChildren(...meta.areas.filter((a) => a.unit_id == unit.value).map((a) => h('option', { value: a.id }, a.name))); fillEq(); };
  const fillEq = async () => {
    const searching = search.value.trim() !== '';
    const r = await api.get('equipment', searching ? { unit_id: unit.value, q: search.value, limit: 100 } : { area_id: area.value, limit: 300 });
    eq.replaceChildren(h('option', { value: '' }, '— pilih equipment —'), ...r.rows.map((e) => h('option', { value: e.id }, searching ? `${e.name} · ${e.area_name}` : e.name)));
    eq._rows = r.rows;
  };
  unit.onchange = fillAreas; area.onchange = fillEq;
  let t; search.oninput = () => { clearTimeout(t); t = setTimeout(fillEq, 250); };
  eq.onchange = () => onPick(eq._rows.find((e) => String(e.id) === eq.value) ?? null);
  fillAreas();
  return h('div', { class: 'form-grid' },
    h('label', { class: 'field' }, h('span', {}, 'Unit'), unit), h('label', { class: 'field' }, h('span', {}, 'Area'), area),
    h('label', { class: 'field wide' }, h('span', {}, 'Equipment * (dari Equipment Database)'), search, eq));
}

/** Papan tanda tangan (canvas): mengembalikan { node, dataUrl(), clear(), isEmpty() }. */
export function signaturePad() {
  const c = h('canvas', { width: 520, height: 180, style: 'width:100%;max-width:520px;height:180px;background:#fff;border-radius:6px;touch-action:none;cursor:crosshair' });
  const ctx = c.getContext('2d');
  ctx.lineWidth = 2.5; ctx.lineCap = 'round'; ctx.strokeStyle = '#111';
  let draw = false, dirty = false;
  const pos = (e) => { const r = c.getBoundingClientRect(); return [(e.clientX - r.left) * c.width / r.width, (e.clientY - r.top) * c.height / r.height]; };
  c.addEventListener('pointerdown', (e) => { draw = true; c.setPointerCapture(e.pointerId); ctx.beginPath(); ctx.moveTo(...pos(e)); });
  c.addEventListener('pointermove', (e) => { if (!draw) return; ctx.lineTo(...pos(e)); ctx.stroke(); dirty = true; });
  ['pointerup', 'pointercancel'].forEach((ev) => c.addEventListener(ev, () => { draw = false; }));
  return { node: c, isEmpty: () => !dirty, clear: () => { ctx.clearRect(0, 0, c.width, c.height); dirty = false; }, dataUrl: () => c.toDataURL('image/png') };
}

/** Dialog rincian laporan PM + aksi: tanda tangan, edit, hapus, cetak, buat WO. */
export async function reportDialog(id, { user, go, onChange }) {
  const d = await api.get('pm/report', { id });
  const r = d.report, eq = d.equipment;
  const manage = ['admin', 'spv'].includes(user.role);
  const canEdit = manage || r.created_by === user.name;
  const sig = r.signature;
  const sigBox = (label, name, img, at, role) => h('div', { class: 'note', style: 'text-align:center' }, h('small', { class: 'muted' }, label),
    img ? h('img', { src: img, alt: `TTD ${label}`, style: 'display:block;margin:6px auto;max-width:200px;max-height:80px;background:#fff;border-radius:4px' }) : h('div', { class: 'muted', style: 'padding:22px 0' }, 'belum ditandatangani'),
    h('div', {}, name ? h('b', {}, name) : '—'), at ? h('small', { class: 'muted' }, dt(at)) : null,
    manage ? h('div', { style: 'margin-top:6px' }, h('button', { class: 'btn sm', onclick: () => signDialog(r.id, role, () => { document.querySelector('.overlay')?.remove(); onChange?.(); reportDialog(id, { user, go, onChange }); }) }, img ? 'Tanda tangan ulang' : 'Tanda tangani')) : null);
  const actions = [{ label: '🖨 Cetak / PDF', run: () => { printReport(d); return false; } }];
  if (!r.wo_id && r.items_no > 0) actions.push({ label: '🔧 Buat WO dari temuan', kind: 'primary', run: async () => { const w = await attempt(() => api.post('pm/wo', { report_id: r.id }), 'Work Order dibuat.'); if (!w) return false; onChange?.(); go('wo/detail', { id: w.wo_id }); } });
  if (canEdit) actions.push({ label: 'Edit', run: () => { go('pm/form', { id: r.id }); } });
  if (manage) actions.push({ label: 'Hapus', kind: 'danger', run: async () => { if (!(await confirmBox('Hapus laporan PM', `Hapus ${r.id}? Pemakaian sparepart dikembalikan ke stok MaintWare.`))) return false; if (!(await attempt(() => api.post('pm/report/delete', { id: r.id }), 'Laporan dihapus.'))) return false; onChange?.(); } });
  modal(`PM · ${eq?.name ?? ''}`, h('div', {},
    h('dl', { class: 'kv' }, kv('No. laporan', h('span', { class: 'mono' }, r.id)), kv('Tanggal', dt(r.report_date, false)), kv('Lokasi', [eq?.unit_name, eq?.area_name].filter(Boolean).join(' › ')), kv('Kode', eq?.code),
      kv('Tipe / periode', `${eq?.type_label ?? '—'} · ${r.periode ?? '—'}`), kv('Teknisi', r.teknisi), kv('Disetujui', r.approval), kv('PPE', Object.entries(r.ppe || {}).filter(([, v]) => v).map(([k]) => PPE[k]).join(', ') || '—'),
      kv('Hasil', h('span', {}, `${r.items_ok}/${r.items_total} OK`, r.items_no ? h('span', { class: 'badge prio-critical', style: 'margin-left:8px' }, `${r.items_no} tidak OK`) : null)), kv('Catatan', r.notes),
      r.wo_id ? kv('Work Order', h('a', { href: `#/wo/detail?id=${encodeURIComponent(r.wo_id)}`, onclick: () => document.querySelector('.overlay')?.remove() }, `${r.wo_id} (${d.wo?.status ?? '—'})`)) : null),
    h('div', { style: 'margin-top:14px' }, table([
      { label: '#', render: (i) => i.seq + 1 }, { label: 'Uraian pekerjaan', key: 'text' },
      { label: 'Hasil', render: (i) => (i.no ? h('span', { class: 'badge prio-critical' }, 'Tidak') : i.yes ? h('span', { class: 'badge done' }, 'Ya') : h('span', { class: 'muted' }, '—')) },
      { label: 'Keterangan', render: (i) => h('span', { class: 'muted' }, i.note || '') },
    ], r.items)),
    r.parts.length ? h('div', { style: 'margin-top:14px' }, h('h3', {}, 'Sparepart dipakai'), table([{ label: 'Part', render: (p) => h('span', {}, p.name, p.item_id ? h('small', { class: 'muted mono' }, ` ${p.item_id}`) : null) }, { label: 'Spesifikasi', key: 'spec' }, { label: 'Qty', cls: 'num', render: (p) => `${p.qty} ${p.uom ?? ''}` }], r.parts)) : null,
    h('div', { class: 'grid g2', style: 'margin-top:14px' }, sigBox('Leader', sig?.leader_name, sig?.leader_signature, sig?.leader_signed_at, 'leader'), sigBox('Supervisor', sig?.spv_name, sig?.spv_signature, sig?.spv_signed_at, 'spv'))), { wide: true, actions });
}
const kv = (k, v) => (v ? [h('dt', {}, k), h('dd', {}, v)] : null);

export function signDialog(reportId, role, done) {
  const pad = signaturePad();
  const name = h('input', { placeholder: 'Nama penandatangan (kosong = nama akun)' });
  modal(`Tanda tangan ${role === 'spv' ? 'Supervisor' : 'Leader'}`, h('div', {}, h('p', { class: 'muted' }, 'Tanda tangani di kotak putih (jari atau mouse).'), pad.node, h('div', { style: 'margin-top:8px' }, h('button', { class: 'btn sm', onclick: pad.clear }, 'Hapus coretan')),
    h('label', { class: 'field', style: 'margin-top:12px' }, h('span', {}, 'Nama'), name)), { actions: [{ label: 'Batal', run: () => {} },
    { label: 'Simpan tanda tangan', kind: 'primary', run: async () => {
      if (pad.isEmpty()) { toast('Tanda tangan masih kosong.', 'err'); return false; }
      const r = await attempt(() => api.post('pm/sign', { report_id: reportId, role, signature: pad.dataUrl(), name: name.value }), 'Tanda tangan tersimpan.');
      if (!r) return false;
      done?.();
    } }] });
}

/** Cetak laporan PM (jendela baru → Print / Simpan PDF). */
export function printReport(d) {
  const r = d.report, eq = d.equipment || {}, sig = r.signature || {};
  const rows = r.items.map((i) => `<tr><td>${i.seq + 1}</td><td>${esc(i.text)}</td><td class="c">${i.yes ? '✔' : ''}</td><td class="c">${i.no ? '✔' : ''}</td><td>${esc(i.note || '')}</td></tr>`).join('');
  const parts = r.parts.map((p, n) => `<tr><td>${n + 1}</td><td>${esc(p.name)}</td><td>${esc(p.spec || '')}</td><td class="c">${p.qty} ${esc(p.uom || '')}</td></tr>`).join('');
  const img = (s, n, at) => `<div class="sg"><div class="sbox">${s ? `<img src="${s}">` : ''}</div><b>${esc(n || '')}</b><small>${at ? dt(at) : ''}</small></div>`;
  const ppe = Object.entries(r.ppe || {}).filter(([, v]) => v).map(([k]) => PPE[k]).join(', ') || '-';
  const w = window.open('', '_blank');
  if (!w) return;
  w.document.write(`<!doctype html><html lang="id"><head><meta charset="utf-8"><title>${esc(r.id)}</title><style>
    body{font-family:Arial,sans-serif;font-size:12px;color:#111;padding:22px}h1{font-size:18px;margin:0 0 2px}table{width:100%;border-collapse:collapse;margin:10px 0}th,td{border:1px solid #bbb;padding:5px 7px;text-align:left;vertical-align:top}th{background:#eef1f6}
    .c{text-align:center}.meta{display:grid;grid-template-columns:repeat(3,1fr);gap:6px;margin:10px 0}.meta div{border:1px solid #ddd;padding:5px 8px}.meta small{display:block;color:#889;font-size:9px;text-transform:uppercase}
    .sigs{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-top:26px;text-align:center}.sbox{height:70px;border-bottom:1px solid #333;display:grid;place-items:center}.sbox img{max-height:66px;max-width:100%}.sg small{display:block;color:#777}
  </style></head><body><h1>Laporan Preventive Maintenance</h1><div>No: <b>${esc(r.id)}</b></div>
  <div class="meta"><div><small>Equipment</small>${esc(eq.name || '')}</div><div><small>Kode</small>${esc(eq.code || '-')}</div><div><small>Tanggal</small>${esc(r.report_date)}</div>
  <div><small>Lokasi</small>${esc([eq.unit_name, eq.area_name].filter(Boolean).join(' › '))}</div><div><small>Tipe / Periode</small>${esc(eq.type_label || '-')} / ${esc(r.periode || '-')}</div><div><small>Teknisi</small>${esc(r.teknisi)}</div>
  <div style="grid-column:span 3"><small>PPE</small>${esc(ppe)}</div></div>
  <table><thead><tr><th>#</th><th>Uraian pekerjaan</th><th class="c">Ya</th><th class="c">Tidak</th><th>Keterangan</th></tr></thead><tbody>${rows}</tbody></table>
  ${parts ? `<b>Sparepart dipakai</b><table><thead><tr><th>#</th><th>Part</th><th>Spesifikasi</th><th class="c">Qty</th></tr></thead><tbody>${parts}</tbody></table>` : ''}
  ${r.notes ? `<div><b>Catatan:</b> ${esc(r.notes)}</div>` : ''}
  <div class="sigs"><div class="sg"><div class="sbox"></div><b>${esc(r.teknisi)}</b><small>Pelaksana</small></div>${img(sig.leader_signature, sig.leader_name || 'Leader', sig.leader_signed_at)}${img(sig.spv_signature, sig.spv_name || r.approval || 'Supervisor', sig.spv_signed_at)}</div>
  <script>setTimeout(()=>print(),400)<\/script></body></html>`);
  w.document.close();
}
