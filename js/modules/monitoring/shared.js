import { api } from '../../core/api.js';
import { h, attempt, modal, confirmBox, dt, table } from '../../core/ui.js';

const CLS = { OK: 'done', WARNING: 'open', ALERT: 'prio-critical', PENDING: '' };
export const stBadge = (s) => h('span', { class: `badge ${CLS[s] ?? ''}` }, s || '—');
export const today = () => new Date().toLocaleDateString('sv-SE'); // YYYY-MM-DD lokal
export const nowHM = () => new Date().toTimeString().slice(0, 5);

/** Sama dengan MonitoringApi::parseNumber di server. */
export function parseNumber(raw) {
  const s = String(raw).replace(/\s/g, '');
  return /^[-+]?(\d+([.,]\d*)?|[.,]\d+)$/.test(s) ? parseFloat(s.replace(',', '.')) : null;
}

/** Pratinjau status di browser (server tetap menghitung ulang saat menyimpan). */
export function evalStatus(p, raw) {
  if (raw === '' || raw == null) return { status: '', error: '' };
  if (p.type === 'status') {
    const i = (p.options || []).indexOf(raw);
    if (i < 0) return { status: '', error: 'pilihan tidak valid' };
    return { status: i === 0 ? 'OK' : i === p.options.length - 1 ? 'ALERT' : 'WARNING', error: '' };
  }
  const v = parseNumber(raw);
  if (v === null) return { status: '', error: 'isi angka saja, contoh 28,7' };
  const has = (x) => x !== null && x !== undefined && x !== '';
  if ((has(p.warn_max) && v > Number(p.warn_max)) || (has(p.warn_min) && v < Number(p.warn_min))) return { status: 'ALERT', error: '' };
  if ((has(p.normal_max) && v > Number(p.normal_max)) || (has(p.normal_min) && v < Number(p.normal_min))) return { status: 'WARNING', error: '' };
  return { status: 'OK', error: '' };
}

/** Dialog rincian sesi: nilai per parameter, tautan WO, aksi edit/hapus/buat WO. */
export async function sessionDialog(id, { user, go, onChange }) {
  const d = await api.get('dm/session', { id });
  const s = d.session;
  const canManage = ['admin', 'spv'].includes(user.role);
  const canEdit = canManage || s.created_by === user.name;
  const findings = d.items.filter((i) => i.status === 'WARNING' || i.status === 'ALERT');
  const actions = [];
  if (!s.wo_id && findings.length) {
    actions.push({ label: '🔧 Buat WO dari temuan', kind: 'primary', run: async () => {
      const r = await attempt(() => api.post('dm/wo', { session_id: id }), 'Work Order dibuat.');
      if (!r) return false;
      onChange?.();
      go('wo/detail', { id: r.wo_id });
    } });
  }
  if (canEdit) actions.push({ label: 'Edit', run: () => { go('dm/entry', { id }); } });
  if (canManage) {
    actions.push({ label: 'Hapus', kind: 'danger', run: async () => {
      if (!(await confirmBox('Hapus sesi', `Hapus sesi ${id}? Tidak dapat diurungkan.`))) return false;
      const ok = await attempt(() => api.post('dm/delete', { id }), 'Sesi dihapus.');
      if (!ok) return false;
      onChange?.();
    } });
  }
  const kv = (k, v) => (v ? [h('dt', {}, k), h('dd', {}, v)] : null);
  modal(`${d.equipment?.name ?? 'Sesi'} · ${dt(s.session_date, false)}`, h('div', {},
    h('dl', { class: 'kv' }, kv('Lokasi', [d.equipment?.unit_name, d.equipment?.area_name].filter(Boolean).join(' › ')), kv('Sesi', h('span', { class: 'mono' }, s.id)), kv('PIC', s.pic),
      kv('Waktu', [s.start_time, s.end_time].filter(Boolean).join(' – ')), kv('Status', stBadge(s.status)), kv('Catatan', s.catatan), kv('Tindakan', s.tindakan),
      s.wo_id ? kv('Work Order', h('a', { href: `#/wo/detail?id=${encodeURIComponent(s.wo_id)}`, onclick: () => document.querySelector('.overlay')?.remove() }, `${s.wo_id} (${d.wo?.status ?? '—'})`)) : null),
    h('div', { style: 'margin-top:14px' }, table([
      { label: 'Parameter', render: (i) => h('div', {}, h('b', {}, i.label), h('div', { class: 'muted' }, i.section)) },
      { label: 'Nilai', cls: 'num', render: (i) => `${i.value || '—'} ${i.unit || ''}` }, { label: 'Status', render: (i) => stBadge(i.status) },
      { label: 'Catatan', render: (i) => h('span', { class: 'muted' }, i.note || '') },
    ], d.items))), { wide: true, actions });
}
