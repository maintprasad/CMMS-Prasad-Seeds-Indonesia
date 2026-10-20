import { api } from '../../core/api.js';
import { h, attempt, modal, form, confirmBox, table } from '../../core/ui.js';

const fmt = (v) => (v === null || v === undefined || v === '' ? '' : String(Number(v)));

/** Ringkasan batas parameter untuk tampilan. */
export function rangeText(p) {
  if (p.type === 'status') return (p.options || []).map((o, i, a) => (i === 0 ? `✓ ${o}` : i === a.length - 1 ? `✗ ${o}` : `⚠ ${o}`)).join('  ');
  const n = [fmt(p.normal_min), fmt(p.normal_max)];
  const normal = n[0] !== '' || n[1] !== '' ? `normal ${n[0] || '…'}–${n[1] || '…'}` : '';
  const warn = [p.warn_min !== null && p.warn_min !== '' ? `< ${fmt(p.warn_min)}` : '', p.warn_max !== null && p.warn_max !== '' ? `> ${fmt(p.warn_max)}` : ''].filter(Boolean).join(' atau ');
  return [normal, warn ? `ALERT ${warn}` : ''].filter(Boolean).join(' · ') || '—';
}

/** Dialog tambah/ubah satu parameter. `save(values)` mengirim ke API yang sesuai (equipment atau template). */
export function paramDialog(p, sections, save, done) {
  const f = form([
    { name: 'label', label: 'Nama parameter', required: true, wide: true },
    { name: 'unit', label: 'Satuan' }, { name: 'section', label: 'Seksi / kelompok' },
    { name: 'type', label: 'Tipe', type: 'select', options: [{ value: 'numeric', label: 'Angka (numeric)' }, { value: 'status', label: 'Pilihan status' }] },
    { name: 'normal_min', label: 'Normal min' }, { name: 'normal_max', label: 'Normal maks' },
    { name: 'warn_min', label: 'ALERT bila di bawah' }, { name: 'warn_max', label: 'ALERT bila di atas' },
    { name: 'options', label: 'Opsi (satu per baris) — pertama = OK, terakhir = ALERT, di antaranya WARNING', type: 'textarea', wide: true, rows: 4 },
  ], { type: 'numeric', ...p, options: (p.options || []).join('\n') });
  const dl = h('datalist', { id: 'sections-dl' }, sections.map((s) => h('option', { value: s })));
  f.inputs.section.setAttribute('list', 'sections-dl');
  const numeric = ['normal_min', 'normal_max', 'warn_min', 'warn_max'];
  const toggle = () => {
    const isNum = f.inputs.type.value === 'numeric';
    numeric.forEach((k) => { f.inputs[k].closest('.field').style.display = isNum ? '' : 'none'; });
    f.inputs.options.closest('.field').style.display = isNum ? 'none' : '';
  };
  f.inputs.type.onchange = toggle;
  toggle();
  modal(p.id ? 'Ubah parameter' : 'Tambah parameter', h('div', {}, f.node, dl,
    h('p', { class: 'muted', style: 'margin-top:10px' }, 'Angka: di luar batas normal → WARNING; di luar batas ALERT → ALERT. Batas boleh dikosongkan.')), { wide: true, actions: [
    { label: 'Batal', run: () => {} },
    { label: 'Simpan', kind: 'primary', run: async () => (await attempt(async () => { await save({ id: p.id, ...f.values() }); await done(); }, 'Parameter disimpan.')) ? true : false },
  ] });
}

/**
 * Daftar parameter terkelompok per seksi dengan aksi ubah/hapus/urut.
 * ops = { save(values), remove(id), reorder(ids) } — dipakai untuk parameter equipment maupun template.
 */
export function paramList(params, { canEdit, ops, reload }) {
  if (!params.length) return h('p', { class: 'muted' }, 'Belum ada parameter.');
  const sections = [...new Set(params.map((p) => p.section))];
  const move = async (p, dir) => {
    const ids = params.map((x) => x.id);
    const i = ids.indexOf(p.id), j = i + dir;
    if (j < 0 || j >= ids.length) return;
    [ids[i], ids[j]] = [ids[j], ids[i]];
    await attempt(async () => { await ops.reorder(ids); await reload(); });
  };
  const groups = [];
  for (const s of sections) {
    groups.push(h('h4', { style: 'margin:14px 0 6px;color:var(--muted);text-transform:uppercase;font-size:12px;letter-spacing:.04em' }, s || '(tanpa seksi)'));
    groups.push(table([
      { label: 'Parameter', render: (p) => h('b', {}, p.label) },
      { label: 'Satuan', key: 'unit' },
      { label: 'Tipe', render: (p) => (p.type === 'status' ? 'Status' : 'Angka') },
      { label: 'Batas', render: (p) => h('span', { class: 'muted' }, rangeText(p)) },
      ...(canEdit ? [{ label: '', render: (p) => h('div', { style: 'display:flex;gap:4px;justify-content:flex-end' },
        h('button', { class: 'btn sm', title: 'Naik', onclick: () => move(p, -1) }, '↑'), h('button', { class: 'btn sm', title: 'Turun', onclick: () => move(p, 1) }, '↓'),
        h('button', { class: 'btn sm', onclick: () => paramDialog(p, sections, ops.save, reload) }, 'Ubah'),
        h('button', { class: 'btn sm danger', onclick: async () => { if (await confirmBox('Hapus parameter', `Hapus “${p.label}”? Riwayat monitoring tetap tersimpan.`)) await attempt(async () => { await ops.remove(p.id); await reload(); }); } }, 'Hapus')) }] : []),
    ], params.filter((p) => p.section === s)));
  }
  return h('div', {}, groups);
}

export const allSections = (params) => [...new Set(params.map((p) => p.section).filter(Boolean))];

/** Kartu "Parameter Monitoring" di halaman detail equipment. */
export async function equipmentParamCard(eq, params, { canEdit, types, reload, go }) {
  const ops = {
    save: (v) => api.post('eqparam/save', { ...v, equipment_id: eq.id }),
    remove: (id) => api.post('eqparam/delete', { id }),
    reorder: (ids) => api.post('eqparam/reorder', { equipment_id: eq.id, ids }),
  };
  const applyTemplate = () => {
    const sel = h('select', {}, types.filter((t) => t.templates > 0).map((t) => h('option', { value: t.code }, `${t.label} (${t.templates} parameter)`)));
    sel.value = eq.monitor_type && types.some((t) => t.code === eq.monitor_type && t.templates) ? eq.monitor_type : sel.value;
    const mode = h('select', {}, h('option', { value: 'append' }, 'Tambahkan yang belum ada'), h('option', { value: 'replace' }, 'Ganti semua parameter equipment ini'));
    if (!params.length) mode.value = 'replace';
    modal('Terapkan template parameter', h('div', {}, h('p', { class: 'muted' }, 'Template = pengaturan dasar parameter per tipe monitoring (menu Equipment Database → Template Parameter).'),
      h('label', { class: 'field' }, h('span', {}, 'Tipe'), sel), h('div', { style: 'height:10px' }), h('label', { class: 'field' }, h('span', {}, 'Cara'), mode)), { actions: [
      { label: 'Batal', run: () => {} },
      { label: 'Terapkan', kind: 'primary', run: async () => {
        if (mode.value === 'replace' && params.length && !(await confirmBox('Ganti parameter', 'Semua parameter equipment ini diganti dengan template. Lanjutkan?'))) return false;
        return (await attempt(async () => { await api.post('eqparam/template', { equipment_id: eq.id, monitor_type: sel.value, mode: mode.value }); await reload(); }, 'Template diterapkan.')) ? true : false;
      } },
    ] });
  };
  const copyTo = async () => {
    const r = await api.get('equipment', { area_id: eq.area_id, limit: 300 });
    const boxes = r.rows.filter((e) => e.id !== eq.id).map((e) => {
      const c = h('input', { type: 'checkbox', value: e.id });
      return { c, node: h('label', { class: 'task' }, c, h('span', {}, e.name), e.param_count ? h('small', { class: 'muted' }, ` sudah ${e.param_count} parameter`) : null) };
    });
    modal('Salin parameter ke equipment lain (area yang sama)', boxes.length ? h('div', { style: 'max-height:50vh;overflow:auto' }, boxes.map((b) => b.node)) : h('p', { class: 'muted' }, 'Tidak ada equipment lain di area ini.'), { wide: true, actions: [
      { label: 'Batal', run: () => {} },
      { label: 'Salin (tambahkan yang belum ada)', kind: 'primary', run: async () => {
        const ids = boxes.filter((b) => b.c.checked).map((b) => Number(b.c.value));
        if (!ids.length) { await attempt(() => { throw new Error('Pilih equipment tujuan.'); }); return false; }
        return (await attempt(async () => { const x = await api.post('eqparam/copy', { from_equipment_id: eq.id, equipment_ids: ids, mode: 'append' }); await reload(); return x; }, 'Parameter disalin.')) ? true : false;
      } },
    ] });
  };
  return h('div', { class: 'card', style: 'margin-top:14px' },
    h('div', { class: 'page-head' }, h('h2', {}, `Parameter Monitoring (${params.length})`),
      eq.monitor_type ? h('span', { class: 'badge prog' }, types.find((t) => t.code === eq.monitor_type)?.label || eq.monitor_type) : h('span', { class: 'muted' }, 'belum ada tipe monitoring'), h('span', { class: 'sp' }),
      canEdit ? [h('button', { class: 'btn sm', onclick: applyTemplate }, 'Terapkan template'),
        params.length ? h('button', { class: 'btn sm', onclick: copyTo }, 'Salin ke equipment lain') : null,
        h('button', { class: 'btn sm primary', onclick: () => paramDialog({}, allSections(params), ops.save, reload) }, '+ Parameter')] : null),
    h('p', { class: 'muted', style: 'margin:0 0 6px' }, 'Parameter ini dipakai menu Daily Monitoring. Equipment tanpa parameter tidak muncul di papan Monitor.'),
    paramList(params, { canEdit, ops, reload }));
}
