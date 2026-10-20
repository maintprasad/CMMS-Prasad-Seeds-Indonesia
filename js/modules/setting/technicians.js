import { api } from '../../core/api.js';
import { h, attempt, modal, form, confirmBox, table, dur } from '../../core/ui.js';

/** Setting → Teknisi: satu-satunya tempat daftar teknisi/PIC (dipakai Work Order, Daily Monitoring, MaintWare) beserta ringkasan kinerjanya. */
export default async function render(root, { user, reloadMeta }) {
  const admin = user.role === 'admin';
  const host = h('div');
  const count = h('span', { class: 'muted' });
  let users = [];
  let showInactive = false;

  const load = async () => {
    const [t, u] = await Promise.all([api.get('technicians'), admin ? api.get('users') : { rows: [] }]);
    users = u.rows;
    const rows = t.rows.filter((r) => showInactive || Number(r.active));
    count.textContent = `${rows.length} teknisi`;
    host.replaceChildren(table([
      { label: 'Nama', render: (r) => h('b', {}, r.name) }, { label: 'Spesialisasi', key: 'spec' }, { label: 'Shift', key: 'shift' }, { label: 'No. WhatsApp', key: 'phone' },
      { label: 'Akun login', render: (r) => (r.username ? h('span', {}, h('span', { class: 'mono' }, r.username), Number(r.user_active) ? '' : h('span', { class: 'muted' }, ' (nonaktif)')) : h('span', { class: 'muted' }, 'tanpa akun (PIC saja)')) },
      { label: 'WO', key: 'wo_count', cls: 'num' }, { label: 'Closed', key: 'closed', cls: 'num' }, { label: 'Berjalan', key: 'in_progress', cls: 'num' },
      { label: 'Jam kerja', cls: 'num', render: (r) => dur(r.seconds) },
      { label: 'Status', render: (r) => h('span', { class: `badge ${Number(r.active) ? 'done' : 'cancel'}` }, Number(r.active) ? 'Aktif' : 'Nonaktif') },
    ], rows, { empty: 'Belum ada teknisi.', onRow: (r) => edit(r) }));
  };

  const edit = (t = {}) => {
    const linkable = users.filter((u) => u.role === 'maintenance' || u.id === t.user_id);
    const f = form([
      { name: 'name', label: 'Nama', required: true, wide: true }, { name: 'spec', label: 'Spesialisasi' }, { name: 'shift', label: 'Shift' }, { name: 'phone', label: 'No. WhatsApp' },
      { name: 'user_id', label: 'Akun login (role Maintenance)', type: 'select', optional: true, options: linkable.map((u) => ({ value: u.id, label: `${u.name} (${u.username})` })) },
      { name: 'active', label: 'Status', type: 'select', options: [{ value: 1, label: 'Aktif' }, { value: 0, label: 'Nonaktif' }] },
    ], { ...t, user_id: t.user_id ?? '', active: t.id ? Number(t.active) : 1 });
    if (!admin) f.inputs.user_id.disabled = true;
    modal(t.id ? 'Ubah teknisi' : 'Tambah teknisi', h('div', {}, f.node, h('p', { class: 'muted', style: 'margin-top:10px' },
      'Teknisi yang terhubung ke akun otomatis tercatat saat menekan Start/Continue di WO. Teknisi tanpa akun tetap bisa dipilih sebagai PIC monitoring dan di-assign ke WO.')), { wide: true, actions: [
      ...(t.id && admin ? [{ label: 'Hapus', kind: 'danger', run: async () => {
        if (!(await confirmBox('Hapus teknisi', `Hapus ${t.name}? Bila sudah tercatat di WO, nonaktifkan saja.`))) return false;
        return (await attempt(async () => { await api.post('technicians/delete', { id: t.id }); await reloadMeta(); await load(); }, 'Teknisi dihapus.')) ? true : false;
      } }] : []),
      { label: 'Simpan', kind: 'primary', run: async () => (await attempt(async () => {
        await api.post('technicians/save', { id: t.id, ...f.values(), active: Number(f.inputs.active.value) });
        await reloadMeta();
        await load();
      }, 'Teknisi disimpan.')) ? true : false },
    ] });
  };

  root.append(h('div', { class: 'page' },
    h('div', { class: 'page-head' }, h('h1', {}, 'Teknisi'), count, h('span', { class: 'sp' }), h('button', { class: 'btn primary', onclick: () => edit() }, '+ Teknisi')),
    h('div', { class: 'toolbar' }, h('label', { class: 'chip' }, h('input', { type: 'checkbox', style: 'width:auto;margin-right:6px', onchange: (e) => { showInactive = e.target.checked; load(); } }), 'Tampilkan nonaktif'),
      h('a', { class: 'btn sm', href: '#/wo/rating' }, 'Rating teknisi →')),
    host));
  await load();
}
