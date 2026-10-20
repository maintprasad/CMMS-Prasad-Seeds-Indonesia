import { api } from '../../core/api.js';
import { h, attempt, modal, toast, dt } from '../../core/ui.js';
import { today, PERIODS, PPE, stBadge, dueText } from './shared.js';

/** Form laporan PM: checklist dari template tipe PM equipment, PPE, sparepart (tertaut MaintWare), teknisi & persetujuan. */
export default async function render(root, { query, user, meta, go }) {
  const d = await api.get('pm/form', { id: query.id, schedule_id: query.schedule_id, equipment_id: query.equipment_id });
  const eq = d.equipment, rep = d.report;
  const sched = d.schedule;

  const date = h('input', { type: 'date', value: rep?.report_date ?? today(), max: today() });
  const periode = h('select', {}, PERIODS.map((p) => h('option', {}, p)));
  periode.value = rep?.periode ?? sched?.periode ?? 'Annual';
  const approval = h('select', {}, h('option', { value: '' }, '— pilih —'), meta.owners.filter((u) => ['spv', 'admin'].includes(u.role)).map((u) => h('option', { value: u.name }, `${u.name} (${u.role === 'spv' ? 'Leader/SPV' : 'Admin'})`)));
  approval.value = rep?.approval ?? '';
  const notes = h('textarea', { rows: 3, placeholder: 'Catatan pelaksanaan…' }); notes.value = rep?.notes ?? '';

  // teknisi: pilih dari daftar (Setting → Teknisi) + nama lain
  const preset = (rep?.teknisi ?? '').split(',').map((s) => s.trim()).filter(Boolean);
  const techs = meta.technicians.filter((t) => t.active);
  const mine = meta.technicians.find((t) => t.user_id === user.id)?.name;
  const known = (n) => techs.find((t) => t.name.toLowerCase() === n.toLowerCase())?.name;
  const picked = new Set(rep ? preset.map(known).filter(Boolean) : [mine, known(sched?.pic ?? '')].filter(Boolean));
  const extra = h('input', { placeholder: 'Nama lain (pisahkan koma)', value: rep ? preset.filter((n) => !known(n)).join(', ') : '' });
  const techBox = h('div', { class: 'chips' });
  const drawTech = () => techBox.replaceChildren(...techs.map((t) => h('button', { type: 'button', class: `chip${picked.has(t.name) ? ' on' : ''}`, onclick: () => { picked.has(t.name) ? picked.delete(t.name) : picked.add(t.name); drawTech(); } }, t.name)));
  drawTech();

  const ppeState = { ...(rep?.ppe ?? {}) };
  const ppeBox = h('div', { class: 'chips' }, Object.entries(PPE).map(([k, l]) => {
    const c = h('button', { type: 'button', class: `chip${ppeState[k] ? ' on' : ''}`, onclick: () => { ppeState[k] = !ppeState[k]; c.classList.toggle('on', ppeState[k]); } }, l);
    return c;
  }));

  // checklist
  const rows = (rep ? rep.items.map((i) => ({ text: i.text, yes: !!i.yes, no: !!i.no, note: i.note ?? '' })) : d.template.map((t) => ({ text: t, yes: false, no: false, note: '' })));
  const progress = h('div', { class: 'note' });
  const list = h('div');
  const refresh = () => {
    const ok = rows.filter((r) => r.yes).length, no = rows.filter((r) => r.no).length;
    progress.replaceChildren(h('b', {}, `${ok + no}/${rows.length} dijawab`), ' · ', h('span', { class: 'badge done' }, `${ok} Ya`), ' ', h('span', { class: `badge ${no ? 'prio-critical' : ''}` }, `${no} Tidak`),
      h('div', { class: 'bar', style: 'margin-top:8px' }, h('i', { style: `width:${rows.length ? (ok + no) / rows.length * 100 : 0}%` })));
  };
  const drawRows = () => {
    list.replaceChildren(...rows.map((r, i) => {
      const yes = h('button', { type: 'button', class: `btn sm${r.yes ? ' primary' : ''}`, onclick: () => { r.yes = !r.yes; if (r.yes) r.no = false; drawRows(); } }, 'Ya');
      const no = h('button', { type: 'button', class: `btn sm${r.no ? ' danger' : ''}`, style: r.no ? 'background:var(--ct-danger);color:#fff' : '', onclick: () => { r.no = !r.no; if (r.no) r.yes = false; drawRows(); } }, 'Tidak');
      return h('div', { class: 'param-row', 'data-st': r.no ? 'ALERT' : '' },
        h('div', { class: 'param-name' }, h('b', {}, `${i + 1}. `), r.text), h('div', { class: 'param-input' }, h('input', { placeholder: 'keterangan', value: r.note, oninput: (e) => { r.note = e.target.value; } })),
        h('div', { class: 'param-badge' }, yes, no, h('button', { type: 'button', class: 'icon-btn', title: 'Hapus butir', onclick: () => { rows.splice(i, 1); drawRows(); } }, h('i', { class: 'ti ti-trash' }))));
    }), h('div', { style: 'margin-top:10px;display:flex;gap:8px;flex-wrap:wrap' },
      h('button', { type: 'button', class: 'btn sm', onclick: () => { rows.forEach((r) => { r.yes = true; r.no = false; }); drawRows(); } }, '✔ Tandai semua "Ya"'),
      h('button', { type: 'button', class: 'btn sm', onclick: () => { const t = prompt('Butir pekerjaan tambahan:'); if (t?.trim()) { rows.push({ text: t.trim(), yes: false, no: false, note: '' }); drawRows(); } } }, '+ Butir tambahan')));
    refresh();
  };
  drawRows();

  // sparepart
  const parts = (rep?.parts ?? []).map((p) => ({ item_id: p.item_id, name: p.name, spec: p.spec ?? '', qty: p.qty, uom: p.uom }));
  const partsBox = h('div');
  const drawParts = () => partsBox.replaceChildren(...(parts.length ? parts.map((p, i) => h('div', { class: 'note', style: 'display:flex;gap:8px;align-items:center;flex-wrap:wrap' },
    h('div', { style: 'flex:1;min-width:180px' }, h('b', {}, p.name), p.item_id ? h('small', { class: 'muted mono' }, ` ${p.item_id} · stok MaintWare berkurang`) : h('small', { class: 'muted' }, ' (di luar MaintWare)'), p.spec ? h('div', { class: 'muted' }, p.spec) : null),
    h('input', { type: 'number', min: '0.01', step: 'any', value: p.qty, style: 'width:90px', oninput: (e) => { p.qty = e.target.value; } }), h('span', { class: 'muted' }, p.uom ?? ''),
    h('button', { type: 'button', class: 'icon-btn', onclick: () => { parts.splice(i, 1); drawParts(); } }, h('i', { class: 'ti ti-trash' }))))
    : [h('p', { class: 'muted' }, 'Tidak ada sparepart dipakai.')]));
  drawParts();
  const addPart = () => {
    const search = h('input', { type: 'search', placeholder: 'Cari item MaintWare (kode / nama / spesifikasi)…' });
    const res = h('div', { style: 'max-height:240px;overflow:auto;margin-top:8px' });
    const manual = h('input', { placeholder: 'atau ketik nama part di luar MaintWare' });
    const qty = h('input', { type: 'number', min: '0.01', step: 'any', value: '1' });
    let picked2 = null;
    const doSearch = async () => {
      if (!search.value.trim()) return res.replaceChildren();
      const r = await api.get('mw/items', { q: search.value, limit: 20 });
      res.replaceChildren(...(r.rows.length ? r.rows.map((i) => h('div', { class: `note`, style: `cursor:pointer;${picked2?.id === i.id ? 'border-color:var(--ct-primary)' : ''}`, onclick: () => { picked2 = i; doSearch(); } },
        h('b', {}, i.name), h('small', { class: 'muted' }, ` ${i.id} · stok ${i.qty} ${i.uom}`), i.spec ? h('div', { class: 'muted' }, [i.brand, i.spec].filter(Boolean).join(' · ')) : null)) : [h('p', { class: 'muted' }, 'Tidak ada di MaintWare.')]));
    };
    let t; search.oninput = () => { clearTimeout(t); t = setTimeout(doSearch, 250); };
    modal('Tambah sparepart', h('div', {}, search, res, h('div', { style: 'height:10px' }), manual, h('label', { class: 'field', style: 'margin-top:10px' }, h('span', {}, 'Jumlah'), qty)), { wide: true, actions: [
      { label: 'Batal', run: () => {} },
      { label: 'Tambahkan', kind: 'primary', run: () => {
        if (!picked2 && !manual.value.trim()) { toast('Pilih item MaintWare atau isi nama part.', 'err'); return false; }
        parts.push(picked2 ? { item_id: picked2.id, name: picked2.name, spec: [picked2.brand, picked2.spec].filter(Boolean).join(' '), qty: qty.value, uom: picked2.uom } : { item_id: null, name: manual.value.trim(), spec: '', qty: qty.value, uom: 'pcs' });
        drawParts();
      } }] });
  };

  const submit = async (btn) => {
    const teknisi = [...picked, ...extra.value.split(',').map((s) => s.trim()).filter(Boolean)];
    btn.disabled = true;
    const r = await attempt(() => api.post('pm/report/save', { id: rep?.id, equipment_id: eq.id, schedule_id: sched?.id, date: date.value, periode: periode.value, teknisi, approval: approval.value, notes: notes.value,
      ppe: ppeState, items: rows, parts: parts.map((p) => ({ item_id: p.item_id, name: p.name, spec: p.spec, qty: Number(p.qty), uom: p.uom })) }));
    btn.disabled = false;
    if (!r) return;
    toast(`Laporan tersimpan · ${r.items_ok}/${r.items_total} OK`);
    if (r.items_no > 0 && !rep?.wo_id) {
      modal('Ada butir tidak OK', h('p', {}, `${r.items_no} butir ditandai "Tidak". Buat Work Order dari temuan ini?`), { actions: [
        { label: 'Nanti saja', run: () => { go('pm/list', { id: r.id }); } },
        { label: '🔧 Buat Work Order', kind: 'primary', run: async () => { const w = await attempt(() => api.post('pm/wo', { report_id: r.id }), 'Work Order dibuat.'); if (!w) return false; go('wo/detail', { id: w.wo_id }); } }] });
    } else go('pm/list', { id: r.id });
  };

  root.append(h('div', { class: 'page' },
    h('div', { class: 'page-head' }, h('a', { href: '#/pm/input', class: 'btn sm' }, '← Input PM'), h('h1', {}, rep ? 'Edit laporan PM' : 'Laporan PM'), h('span', { class: 'muted' }, `${eq.unit_name} › ${eq.area_name}`)),
    h('div', { class: 'card' }, h('div', { class: 'grid g2' },
      h('div', {}, h('h2', {}, eq.name), h('dl', { class: 'kv', style: 'margin-top:8px' }, [['Kode', eq.code], ['Tipe PM', d.types.find((t) => t.code === (rep?.pm_type ?? eq.pm_type))?.label],
        ['Jadwal', sched ? `${sched.id} · ${sched.periode}` : 'tanpa jadwal'], ['Status jadwal', sched ? h('span', {}, stBadge(sched.status), ' ', dueText(sched)) : null], ['PM terakhir', d.last ? `${dt(d.last.report_date, false)} oleh ${d.last.teknisi}` : 'belum pernah']]
        .filter(([, v]) => v).map(([k, v]) => [h('dt', {}, k), h('dd', {}, v)]))),
      h('div', { class: 'form-grid' }, h('label', { class: 'field' }, h('span', {}, 'Tanggal PM'), date), h('label', { class: 'field' }, h('span', {}, 'Periode'), periode),
        h('div', { class: 'field wide' }, h('span', {}, 'Teknisi pelaksana * (pilih satu atau lebih)'), techBox, extra)))),
    h('div', { class: 'card' }, h('h2', {}, 'PPE yang dipakai'), h('div', { style: 'margin-top:10px' }, ppeBox)),
    h('div', { class: 'card' }, h('h2', {}, 'Checklist pekerjaan'), rows.length || !d.template.length ? null : null, progress, h('div', { style: 'height:6px' }), rows.length ? list : h('div', { class: 'empty' }, 'Equipment ini belum punya template checklist. Atur tipe PM di Equipment Database (Edit equipment) dan isi templatenya di Kelola Template PM.')),
    h('div', { class: 'card' }, h('div', { class: 'page-head' }, h('h2', {}, 'Sparepart dipakai'), h('span', { class: 'sp' }), h('button', { type: 'button', class: 'btn sm', onclick: addPart }, '+ Sparepart')), partsBox),
    h('div', { class: 'card' }, h('div', { class: 'form-grid' }, h('label', { class: 'field' }, h('span', {}, 'Catatan'), notes), h('label', { class: 'field' }, h('span', {}, 'Disetujui oleh (Leader/SPV)'), approval))),
    h('div', { class: 'sticky-actions' }, h('a', { href: '#/pm/input', class: 'btn' }, 'Batal'), h('button', { class: 'btn primary', onclick: (e) => submit(e.currentTarget) }, rep ? '✓ Simpan perubahan' : '✓ Kirim laporan PM'))));
}
