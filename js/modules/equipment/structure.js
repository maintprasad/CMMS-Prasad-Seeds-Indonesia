import { api } from '../../core/api.js';
import { h, attempt, modal, form, confirmBox } from '../../core/ui.js';

/** Unit → Area (struktur Equipment Database). Hanya Admin yang bisa mengubah. */
export default async function render(root, { user, go, reloadMeta }) {
  const admin = user.role === 'admin';
  const host = h('div');
  const load = async () => {
    await reloadMeta();
    const { units, areas } = await api.get('meta');
    host.replaceChildren(...units.map((u) => h('div', { class: 'card' },
      h('div', { class: 'page-head' }, h('h2', {}, u.name), h('span', { class: 'mono muted' }, u.code), h('span', { class: 'sp' }),
        admin ? [h('button', { class: 'btn sm', onclick: () => unitDialog(u, load) }, 'Edit unit'), h('button', { class: 'btn sm primary', onclick: () => areaDialog({ unit_id: u.id }, units, load) }, '+ Area')] : null),
      h('div', { class: 'grid g3' }, areas.filter((a) => a.unit_id === u.id).map((a) => h('div', { class: 'note', style: 'cursor:pointer', onclick: () => go('eq/list', { area_id: a.id }) },
        h('b', {}, a.name), h('div', { class: 'muted' }, `${a.equipment_count} equipment`),
        admin ? h('button', { class: 'btn sm', style: 'margin-top:6px', onclick: (e) => { e.stopPropagation(); areaDialog(a, units, load); } }, 'Edit') : null))))));
  };
  root.append(h('div', { class: 'page' }, h('div', { class: 'page-head' }, h('h1', {}, 'Unit & Area'), h('span', { class: 'sp' }),
    admin ? h('button', { class: 'btn primary', onclick: () => unitDialog({}, load) }, '+ Unit') : null), host));
  await load();
}

function unitDialog(u, done) {
  const f = form([{ name: 'name', label: 'Nama unit', required: true }, { name: 'code', label: 'Kode (1–6 huruf/angka)', required: true }], u);
  modal(u.id ? 'Edit unit' : 'Tambah unit', f.node, { actions: [{ label: 'Batal', run: () => {} },
    { label: 'Simpan', kind: 'primary', run: async () => (await attempt(async () => { await api.post('unit/save', { id: u.id, ...f.values() }); await done(); }, 'Unit disimpan.')) ? true : false }] });
}

function areaDialog(a, units, done) {
  const f = form([{ name: 'name', label: 'Nama area', required: true, wide: true },
    { name: 'unit_id', label: 'Unit', type: 'select', options: units.map((u) => ({ value: u.id, label: u.name })), wide: true }], a);
  modal(a.id ? 'Edit area' : 'Tambah area', f.node, { actions: [
    ...(a.id ? [{ label: 'Hapus', kind: 'danger', run: async () => {
      if (!(await confirmBox('Hapus area', `Hapus area “${a.name}”?`))) return false;
      return (await attempt(async () => { await api.post('area/delete', { id: a.id }); await done(); }, 'Area dihapus.')) ? true : false;
    } }] : []),
    { label: 'Simpan', kind: 'primary', run: async () => (await attempt(async () => { await api.post('area/save', { id: a.id, ...f.values(), unit_id: Number(f.inputs.unit_id.value) }); await done(); }, 'Area disimpan.')) ? true : false }] });
}
