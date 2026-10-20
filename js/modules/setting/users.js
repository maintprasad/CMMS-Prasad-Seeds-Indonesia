import { api } from '../../core/api.js';
import { h, attempt, modal, form, table } from '../../core/ui.js';

export const ROLE = { admin: 'Admin', spv: 'Leader / SPV', maintenance: 'Maintenance', user: 'User' };
const ROLE_HINT = {
  admin: 'Semua akses, termasuk Setting.', spv: 'Assign teknisi, Tasklist, Equipment History, parameter monitoring, setujui Purchase Request.',
  maintenance: 'Mengerjakan WO, monitoring, dan stok MaintWare (otomatis punya profil teknisi).', user: 'Hanya membuat dan memverifikasi Work Order miliknya.',
};

/** Setting → User: semua akun aplikasi (satu-satunya tempat mengelola user). */
export default async function render(root, { meta, user: me, reloadMeta }) {
  const host = h('div');
  const count = h('span', { class: 'muted' });
  let q = '', role = '', showInactive = false;

  const load = async () => {
    const { rows } = await api.get('users');
    const list = rows.filter((u) => (showInactive || Number(u.active)) && (!role || u.role === role)
      && (!q || `${u.name} ${u.username} ${u.department ?? ''}`.toLowerCase().includes(q.toLowerCase())));
    count.textContent = `${list.length} akun${rows.length !== list.length ? ` dari ${rows.length}` : ''}`;
    host.replaceChildren(table([
      { label: 'Nama', render: (u) => h('div', {}, h('b', {}, u.name), h('div', { class: 'muted' }, u.jabatan || '')) },
      { label: 'Username', render: (u) => h('span', { class: 'mono' }, u.username) },
      { label: 'Role', render: (u) => ROLE[u.role] }, { label: 'Unit', key: 'unit_name' }, { label: 'Departemen', key: 'department' },
      { label: 'WhatsApp', key: 'whatsapp' },
      { label: 'Status', render: (u) => h('span', { class: `badge ${Number(u.active) ? 'done' : 'cancel'}` }, Number(u.active) ? 'Aktif' : 'Nonaktif') },
    ], list, { empty: 'Tidak ada akun yang cocok.', onRow: (u) => edit(u) }));
  };

  const edit = (u = {}) => {
    const f = form([
      { name: 'name', label: 'Nama lengkap', required: true }, { name: 'username', label: 'Username (tidak bisa diubah)', required: true },
      { name: 'password', label: u.id ? 'Password baru (kosongkan bila tidak diubah)' : 'Password (min. 6)', type: 'password', wide: true },
      { name: 'role', label: 'Role', type: 'select', options: meta.roles.map((r) => ({ value: r, label: ROLE[r] })) },
      { name: 'unit_id', label: 'Unit kerja', type: 'select', optional: true, options: meta.units.map((x) => ({ value: x.id, label: x.name })) },
      { name: 'department', label: 'Departemen' }, { name: 'jabatan', label: 'Jabatan' }, { name: 'whatsapp', label: 'WhatsApp' },
      { name: 'active', label: 'Status', type: 'select', options: [{ value: 1, label: 'Aktif' }, { value: 0, label: 'Nonaktif (tidak bisa login)' }] },
    ], { ...u, active: u.id ? Number(u.active) : 1, role: u.role || 'user' });
    if (u.id) f.inputs.username.disabled = true;
    const hint = h('p', { class: 'muted', style: 'margin-top:10px' });
    const showHint = () => { hint.textContent = ROLE_HINT[f.inputs.role.value]; };
    f.inputs.role.addEventListener('change', showHint);
    showHint();
    modal(u.id ? `Ubah akun · ${u.username}` : 'Tambah akun', h('div', {}, f.node, hint,
      u.id && u.id === me.id ? h('p', { class: 'muted' }, 'Ini akun Anda sendiri: tidak bisa dinonaktifkan di sini.') : null), { wide: true, actions: [
      { label: 'Batal', run: () => {} },
      { label: 'Simpan', kind: 'primary', run: async () => (await attempt(async () => {
        await api.post('users/save', { id: u.id, ...f.values(), active: Number(f.inputs.active.value) });
        await reloadMeta();
        await load();
      }, 'Akun disimpan.')) ? true : false },
    ] });
  };

  let t;
  root.append(h('div', { class: 'page' },
    h('div', { class: 'page-head' }, h('h1', {}, 'User'), count, h('span', { class: 'sp' }), h('button', { class: 'btn primary', onclick: () => edit() }, '+ Akun')),
    h('p', { class: 'muted', style: 'margin-top:-6px' }, 'Akun tidak dihapus agar riwayat WO, monitoring, dan stok tetap utuh — nonaktifkan bila sudah tidak dipakai. Mengganti password atau menonaktifkan akun otomatis mengeluarkannya dari semua perangkat.'),
    h('div', { class: 'toolbar' },
      h('input', { class: 'grow', type: 'search', placeholder: 'Cari nama, username, departemen…', oninput: (e) => { clearTimeout(t); t = setTimeout(() => { q = e.target.value; load(); }, 250); } }),
      h('select', { 'aria-label': 'Role', onchange: (e) => { role = e.target.value; load(); } }, h('option', { value: '' }, 'Semua role'), meta.roles.map((r) => h('option', { value: r }, ROLE[r]))),
      h('label', { class: 'chip' }, h('input', { type: 'checkbox', style: 'width:auto;margin-right:6px', onchange: (e) => { showInactive = e.target.checked; load(); } }), 'Tampilkan nonaktif')),
    host));
  await load();
}
