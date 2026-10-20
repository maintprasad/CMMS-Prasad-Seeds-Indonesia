import { api } from '../../core/api.js';
import { h, attempt, modal, toast, dt } from '../../core/ui.js';
import { stBadge, evalStatus, today, nowHM } from './shared.js';
import { rangeText } from '../equipment/params.js';

/** Form monitoring satu equipment (baru: ?equipment_id=…, edit: ?id=<sesi>). Status dihitung langsung dari batas parameter equipment. */
export default async function render(root, { query, user, meta, go }) {
  const d = await api.get('dm/form', { equipment_id: query.equipment_id, id: query.id });
  const eq = d.equipment;
  const editing = !!d.session;
  const prevItems = Object.fromEntries(d.items.map((i) => [i.param_id, i]));
  const fields = {}; // param_id → { input, note, badge, p }

  const date = h('input', { type: 'date', value: d.session?.session_date ?? today(), max: today() });
  const start = h('input', { type: 'time', value: d.session?.start_time ?? nowHM() });
  const end = h('input', { type: 'time', value: d.session?.end_time ?? '' });
  const catatan = h('textarea', { rows: 3, placeholder: 'Catatan kondisi equipment…' });
  const tindakan = h('textarea', { rows: 2, placeholder: 'Tindakan yang sudah dilakukan (jika ada)' });
  catatan.value = d.session?.catatan ?? '';
  tindakan.value = d.session?.tindakan ?? '';

  // PIC: pilih dari teknisi (Equipment/WO), plus kolom bebas untuk nama lain
  const preset = (d.session?.pic ?? '').split(',').map((s) => s.trim()).filter(Boolean);
  const mine = meta.technicians.find((t) => t.user_id === user.id)?.name;
  const techs = meta.technicians.filter((t) => t.active);
  const picked = new Set(editing ? preset.filter((n) => techs.some((t) => t.name.toLowerCase() === n.toLowerCase())).map((n) => techs.find((t) => t.name.toLowerCase() === n.toLowerCase()).name) : (mine ? [mine] : []));
  const extraPic = h('input', { placeholder: 'Nama lain (pisahkan koma)', value: editing ? preset.filter((n) => !techs.some((t) => t.name.toLowerCase() === n.toLowerCase())).join(', ') : '' });
  const picBox = h('div', { class: 'chips' });
  const drawPic = () => picBox.replaceChildren(...techs.map((t) => h('button', { type: 'button', class: `chip${picked.has(t.name) ? ' on' : ''}`, onclick: () => { picked.has(t.name) ? picked.delete(t.name) : picked.add(t.name); drawPic(); } }, t.name)));
  drawPic();

  const summary = h('div', { class: 'note' });
  const refresh = () => {
    let ok = 0, w = 0, a = 0, filled = 0;
    for (const { input, p, badge, row, hint } of Object.values(fields)) {
      const { status, error } = evalStatus(p, input.value.trim());
      badge.replaceChildren(error ? h('span', { class: 'badge prio-critical' }, '✗ FORMAT') : stBadge(status));
      hint.textContent = error;
      row.dataset.st = error ? 'ALERT' : status;
      if (status) filled++;
      if (status === 'OK') ok++; else if (status === 'WARNING') w++; else if (status === 'ALERT') a++;
    }
    summary.replaceChildren(h('b', {}, `${filled}/${Object.keys(fields).length} terisi`), ' · ', h('span', { class: 'badge done' }, `${ok} OK`), ' ', h('span', { class: 'badge open' }, `${w} WARNING`), ' ', h('span', { class: 'badge prio-critical' }, `${a} ALERT`));
  };

  const sections = [...new Set(d.params.map((p) => p.section))];
  const body = sections.map((sec) => h('div', { class: 'card', style: 'margin-bottom:12px' }, h('h3', {}, sec || 'Parameter'),
    d.params.filter((p) => p.section === sec).map((p) => {
      const old = prevItems[p.id];
      const last = d.last[p.label];
      const input = p.type === 'status'
        ? h('select', {}, h('option', { value: '' }, '— pilih —'), p.options.map((o) => h('option', { value: o }, o)))
        : h('input', { inputmode: 'decimal', autocomplete: 'off', placeholder: p.unit ? `angka (${p.unit})` : 'angka' });
      input.value = old?.value ?? '';
      const note = h('input', { placeholder: 'catatan parameter (opsional)', value: old?.note ?? '', style: old?.note ? '' : 'display:none' });
      const badge = h('span', {});
      const hint = h('small', { style: 'color:var(--red)' });
      const row = h('div', { class: 'param-row' },
        h('div', { class: 'param-name' }, h('b', {}, p.label), h('div', { class: 'muted' }, rangeText(p)), last ? h('div', { class: 'muted' }, `sebelumnya: ${last.value}${p.unit ? ' ' + p.unit : ''} (${dt(last.date, false)})`) : null),
        h('div', { class: 'param-input' }, input, p.unit && p.type !== 'status' ? h('span', { class: 'unit' }, p.unit) : null),
        h('div', { class: 'param-badge' }, badge, h('button', { type: 'button', class: 'icon-btn', title: 'Tambah catatan', onclick: () => { note.style.display = note.style.display === 'none' ? '' : 'none'; } }, '📝')),
        h('div', { class: 'param-note' }, hint, note));
      input.addEventListener('input', refresh);
      input.addEventListener('change', refresh);
      fields[p.id] = { input, note, badge, p, row, hint };
      return row;
    })));

  const submit = async (btn) => {
    const pic = [...picked, ...extraPic.value.split(',').map((s) => s.trim()).filter(Boolean)];
    btn.disabled = true;
    const r = await attempt(() => api.post('dm/save', {
      id: d.session?.id, equipment_id: eq.id, date: date.value, pic, start_time: start.value, end_time: end.value || (editing ? '' : nowHM()),
      catatan: catatan.value, tindakan: tindakan.value,
      items: Object.entries(fields).map(([pid, f]) => ({ param_id: Number(pid), value: f.input.value.trim(), note: f.note.value })),
    }));
    btn.disabled = false;
    if (!r) return;
    toast(`Tersimpan · status ${r.status}`);
    if (r.alerts + r.warnings > 0 && !d.session?.wo_id) {
      modal('Ada temuan', h('p', {}, `${r.alerts} ALERT dan ${r.warnings} WARNING tercatat di ${eq.name}. Buat Work Order dari temuan ini?`), { actions: [
        { label: 'Nanti saja', run: () => { go('dm/monitor'); } },
        { label: '🔧 Buat Work Order', kind: 'primary', run: async () => {
          const w = await attempt(() => api.post('dm/wo', { session_id: r.id }), 'Work Order dibuat.');
          if (!w) return false;
          go('wo/detail', { id: w.wo_id });
        } },
      ] });
    } else {
      go('dm/monitor');
    }
  };

  const saveBtn = h('button', { class: 'btn primary', onclick: (e) => submit(e.currentTarget) }, editing ? '✓ Simpan perubahan' : '✓ Simpan monitoring');
  root.append(h('div', { class: 'page' },
    h('div', { class: 'page-head' }, h('a', { href: '#/dm/monitor', class: 'btn sm' }, '← Monitor'), h('h1', {}, eq.name), h('span', { class: 'muted' }, `${eq.unit_name} › ${eq.area_name}`), editing ? h('span', { class: 'mono muted' }, d.session.id) : null),
    h('div', { class: 'card', style: 'margin-bottom:12px' },
      h('div', { class: 'form-grid' },
        h('label', { class: 'field' }, h('span', {}, 'Tanggal'), date), h('div', { class: 'form-grid' }, h('label', { class: 'field' }, h('span', {}, 'Mulai'), start), h('label', { class: 'field' }, h('span', {}, 'Selesai'), end)),
        h('div', { class: 'field wide' }, h('span', {}, 'PIC * (pilih satu atau lebih)'), picBox, extraPic))),
    summary, h('div', { style: 'height:12px' }), ...body,
    h('div', { class: 'card' }, h('div', { class: 'form-grid' }, h('label', { class: 'field' }, h('span', {}, 'Catatan'), catatan), h('label', { class: 'field' }, h('span', {}, 'Tindakan'), tindakan))),
    h('div', { class: 'sticky-actions' }, h('a', { href: '#/dm/monitor', class: 'btn' }, 'Batal'), saveBtn)));
  refresh();
}
