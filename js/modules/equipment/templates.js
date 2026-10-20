import { api } from '../../core/api.js';
import { h, attempt, modal, form, confirmBox } from '../../core/ui.js';
import { paramList, paramDialog, allSections } from './params.js';

/** Pengaturan dasar parameter: template parameter per tipe monitoring. Diterapkan ke equipment dari halaman detail equipment. */
export default async function render(root, { user, query }) {
  const canEdit = ['admin', 'spv'].includes(user.role);
  let current = query.type || '';
  const body = h('div');
  const chips = h('div', { class: 'chips' });

  const load = async () => {
    const d = await api.get('dm/templates');
    if (!d.types.some((t) => t.code === current)) current = d.types[0]?.code || '';
    chips.replaceChildren(...d.types.map((t) => h('button', { class: `chip${t.code === current ? ' on' : ''}`, onclick: () => { current = t.code; load(); } }, t.label, h('b', {}, t.templates))),
      canEdit ? h('button', { class: 'chip', onclick: () => typeDialog(load) }, '+ Tipe baru') : null);
    const type = d.types.find((t) => t.code === current);
    if (!type) { body.replaceChildren(h('div', { class: 'empty' }, 'Belum ada tipe monitoring.')); return; }
    const params = d.templates.filter((p) => p.monitor_type === current);
    const ops = {
      save: (v) => api.post('dm/template/save', { ...v, monitor_type: current }),
      remove: (id) => api.post('dm/template/delete', { id }),
      reorder: (ids) => api.post('dm/template/reorder', { monitor_type: current, ids }),
    };
    body.replaceChildren(h('div', { class: 'card' },
      h('div', { class: 'page-head' }, h('h2', {}, `${type.label} · ${params.length} parameter`), h('span', { class: 'muted' }, `${type.equipment} equipment bertipe ini`), h('span', { class: 'sp' }),
        canEdit ? [
          type.equipment && params.length ? h('button', { class: 'btn sm', onclick: async () => {
            if (await confirmBox('Terapkan ke semua equipment', `Tambahkan parameter template yang belum ada ke ${type.equipment} equipment bertipe ${type.label}? Parameter yang sudah ada tidak diubah.`)) {
              await attempt(async () => { const r = await api.post('eqparam/template', { monitor_type: current, all_of_type: true, mode: 'append' }); await load(); return r; }, 'Template diterapkan.');
            }
          } }, 'Terapkan ke semua equipment tipe ini') : null,
          h('button', { class: 'btn sm primary', onclick: () => paramDialog({}, allSections(params), ops.save, load) }, '+ Parameter'),
          !type.equipment ? h('button', { class: 'btn sm danger', onclick: async () => { if (await confirmBox('Hapus tipe', `Hapus tipe ${type.label} beserta templatenya?`)) await attempt(async () => { await api.post('dm/type/delete', { code: current }); await load(); }); } }, 'Hapus tipe') : null,
        ] : null),
      h('p', { class: 'muted', style: 'margin:0' }, 'Template hanya titik awal. Setiap equipment punya salinan parameternya sendiri (di halaman detail equipment), jadi mengubah template tidak mengubah equipment yang sudah berjalan kecuali Anda menerapkannya lagi.'),
      paramList(params, { canEdit, ops, reload: load })));
  };

  root.append(h('div', { class: 'page' }, h('div', { class: 'page-head' }, h('h1', {}, 'Template Parameter'), h('span', { class: 'muted' }, 'pengaturan dasar parameter Daily Monitoring')), chips, body));
  await load();
}

function typeDialog(done) {
  const f = form([{ name: 'code', label: 'Kode (huruf/angka, mis. CHILLER)', required: true }, { name: 'label', label: 'Nama tipe', required: true }]);
  modal('Tipe monitoring baru', f.node, { actions: [{ label: 'Batal', run: () => {} },
    { label: 'Simpan', kind: 'primary', run: async () => (await attempt(async () => { await api.post('dm/type/save', f.values()); await done(); }, 'Tipe dibuat.')) ? true : false }] });
}
