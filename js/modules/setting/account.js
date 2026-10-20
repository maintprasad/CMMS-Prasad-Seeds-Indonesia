import { api, session } from '../../core/api.js';
import { h, attempt, confirmBox, form, toast } from '../../core/ui.js';
import { ROLE } from './users.js';

/** Setting → Akun Saya: profil, ganti password, keluar. Berlaku untuk semua role. */
export default async function render(root, { user, meta }) {
  const unit = meta.units.find((u) => u.id === user.unit_id)?.name;
  const prof = form([
    { name: 'name', label: 'Nama lengkap', required: true, wide: true },
    { name: 'department', label: 'Departemen' }, { name: 'jabatan', label: 'Jabatan' }, { name: 'whatsapp', label: 'WhatsApp', wide: true },
  ], user);
  const pw = form([
    { name: 'old', label: 'Password lama', type: 'password', wide: true },
    { name: 'new', label: 'Password baru (min. 6)', type: 'password' }, { name: 'again', label: 'Ulangi password baru', type: 'password' },
  ]);
  const kv = (k, v) => [h('dt', {}, k), h('dd', {}, v || '—')];

  const leave = () => { session.token = null; location.hash = ''; location.reload(); };
  root.append(h('div', { class: 'page' }, h('div', { class: 'page-head' }, h('h1', {}, 'Akun Saya')),
    h('div', { class: 'grid g2' },
      h('div', { class: 'card' }, h('h2', {}, 'Profil'),
        h('dl', { class: 'kv', style: 'margin:12px 0' }, kv('Username', h('span', { class: 'mono' }, user.username)), kv('Role', ROLE[user.role]), kv('Unit kerja', unit)),
        h('p', { class: 'muted' }, 'Role, unit, dan status akun hanya bisa diubah Admin (Setting → User).'), prof.node,
        h('div', { style: 'margin-top:12px' }, h('button', { class: 'btn primary', onclick: () => attempt(async () => {
          await api.post('auth/profile', prof.values());
          toast('Profil disimpan. Memuat ulang…');
          setTimeout(() => location.reload(), 600);
        }) }, 'Simpan profil'))),
      h('div', {},
        h('div', { class: 'card' }, h('h2', {}, 'Ganti password'), h('div', { style: 'margin-top:12px' }, pw.node),
          h('div', { style: 'margin-top:12px' }, h('button', { class: 'btn primary', onclick: () => {
            const v = pw.values();
            if (v.new !== v.again) return toast('Ulangi password baru dengan sama persis.', 'err');
            attempt(async () => { await api.post('auth/password', { old: v.old, new: v.new }); pw.inputs.old.value = pw.inputs.new.value = pw.inputs.again.value = ''; }, 'Password diganti.');
          } }, 'Ganti password'))),
        h('div', { class: 'card' }, h('h2', {}, 'Sesi login'), h('p', { class: 'muted' }, 'Keluar dari perangkat ini, atau dari semua perangkat bila akun dipakai di tempat lain.'),
          h('div', { style: 'display:flex;gap:8px;flex-wrap:wrap' },
            h('button', { class: 'btn', onclick: async () => { await attempt(() => api.post('auth/logout')); leave(); } }, 'Keluar'),
            h('button', { class: 'btn danger', onclick: async () => {
              if (await confirmBox('Keluar dari semua perangkat', 'Semua sesi login akun ini diakhiri, termasuk di perangkat ini.')) { await attempt(() => api.post('auth/logout-all')); leave(); }
            } }, 'Keluar dari semua perangkat')))))));
}
