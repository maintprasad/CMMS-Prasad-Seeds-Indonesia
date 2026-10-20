import { api } from '../../core/api.js';
import { h, attempt, modal, form, confirmBox } from '../../core/ui.js';

/** Kelola Template PM: checklist pekerjaan per tipe PM (tipe dipilih di Equipment Database → Edit equipment). */
export default async function render(root, { user, query }) {
  const canEdit = ['admin', 'spv'].includes(user.role);
  let current = query.type || '';
  const chips = h('div', { class: 'chips' }), body = h('div');
  const load = async () => {
    const d = await api.get('pm/templates');
    if (!d.types.some((t) => t.code === current)) current = d.types.find((t) => t.items)?.code ?? d.types[0]?.code ?? '';
    chips.replaceChildren(...d.types.map((t) => h('button', { class: `chip${t.code === current ? ' on' : ''}`, onclick: () => { current = t.code; load(); } }, t.label, h('b', {}, t.items))),
      canEdit ? h('button', { class: 'chip', onclick: () => typeDialog(load) }, '+ Tipe baru') : null);
    const type = d.types.find((t) => t.code === current);
    if (!type) { body.replaceChildren(h('div', { class: 'empty' }, 'Belum ada tipe PM.')); return; }
    const items = [...(d.items[current] ?? [])];
    const ta = h('textarea', { rows: Math.max(8, items.length + 2), style: 'font-size:14px' }); ta.value = items.join('\n');
    ta.disabled = !canEdit;
    body.replaceChildren(h('div', { class: 'card' }, h('div', { class: 'page-head' }, h('h2', {}, `${type.label} · ${items.length} butir`), h('span', { class: 'muted' }, `${type.equipment} equipment bertipe ini`), h('span', { class: 'sp' }),
      canEdit && !type.equipment ? h('button', { class: 'btn sm danger', onclick: async () => { if (await confirmBox('Hapus tipe', `Hapus tipe ${type.label} beserta checklistnya?`)) await attempt(async () => { await api.post('pm/type/delete', { code: current }); await load(); }); } }, 'Hapus tipe') : null),
      h('p', { class: 'muted', style: 'margin:0 0 10px' }, 'Satu baris = satu butir pekerjaan. Perubahan berlaku untuk laporan PM berikutnya; laporan lama tidak berubah.'), ta,
      canEdit ? h('div', { style: 'margin-top:12px;display:flex;gap:8px' }, h('button', { class: 'btn primary', onclick: () => attempt(async () => { await api.post('pm/template/save', { pm_type: current, items: ta.value.split('\n') }); await load(); }, 'Template disimpan.') }, 'Simpan template')) : null));
  };
  root.append(h('div', { class: 'page' }, h('div', { class: 'page-head' }, h('h1', {}, 'Kelola Template PM')), chips, body));
  await load();
}

function typeDialog(done) {
  const f = form([{ name: 'code', label: 'Kode (huruf/angka, mis. chiller)', required: true }, { name: 'label', label: 'Nama tipe', required: true }]);
  modal('Tipe PM baru', f.node, { actions: [{ label: 'Batal', run: () => {} }, { label: 'Simpan', kind: 'primary', run: async () => (await attempt(async () => { await api.post('pm/type/save', f.values()); await done(); }, 'Tipe dibuat.')) ? true : false }] });
}
