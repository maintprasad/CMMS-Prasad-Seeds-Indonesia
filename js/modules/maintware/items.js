import { api } from '../../core/api.js';
import { h, attempt, modal, confirmBox, table, dt, downloadCsv } from '../../core/ui.js';
import { rp, num, isLow, stockBadge, trxBadge, movingBadge, mwMeta, itemDialog, stockDialog, trxWhere, photoUrl } from './shared.js';

const PAGE = 25;

export default async function render(root, { user, meta, query }) {
  const m = await mwMeta();
  const f = { q: '', type: '', class: '', moving: '', low: query.low || '', photo: query.photo || '', unit: query.unit || '', nounit: query.nounit || '' };
  f.q = query.q || '';
  let page = 0, sort = 'id', dir = 'asc';
  const host = h('div');
  const pager = h('div', { class: 'toolbar' });
  const count = h('span', { class: 'muted' });
  const ctx = { units: meta.units, areas: meta.areas, user };
  const canDelete = user.role === 'admin';

  const load = async () => {
    const r = await api.get('mw/items', { q: f.q, type: f.type, class: f.class, moving: f.moving, low: f.low, photo: f.photo, unit: f.unit, nounit: f.nounit, sort, dir, limit: PAGE, offset: page * PAGE });
    const th = (label, key) => h('span', { style: 'cursor:pointer', onclick: () => { dir = sort === key && dir === 'asc' ? 'desc' : 'asc'; sort = key; load(); } }, label + (sort === key ? (dir === 'asc' ? ' ▲' : ' ▼') : ''));
    host.replaceChildren(table([
      { label: th('Kode', 'id'), title: 'Kode', render: (i) => h('span', { class: 'mono' }, i.id) },
      { label: 'Unit', render: (i) => (i.unit_name ? h('span', { class: 'badge prog' }, i.unit_name) : h('span', { class: 'badge prio-critical', title: 'Belum ada Unit — edit item untuk melengkapi' }, 'Belum ada')) },
      { label: th('Item', 'name'), title: 'Item', render: (i) => h('div', {}, h('b', {}, i.name), h('div', { class: 'muted' }, [i.brand, i.spec].filter(Boolean).join(' · ') || '—')) },
      { label: 'Type / Class', render: (i) => h('div', {}, i.item_type || '—', h('div', { class: 'muted' }, i.item_class || '')) },
      { label: 'Moving', render: (i) => movingBadge(i.moving_type) },
      { label: th('Stok', 'qty'), title: 'Stok', cls: 'num', render: (i) => h('span', { style: isLow(i) ? 'color:var(--red);font-weight:700' : '' }, `${num(i.qty)} ${i.uom}`) },
      { label: 'Min', cls: 'num', render: (i) => num(i.min_stock) },
      { label: th('Harga', 'price'), title: 'Harga', cls: 'num', render: (i) => rp(i.price) },
      { label: 'PIC', key: 'pic' }, { label: 'Foto', render: (i) => (i.has_photo ? h('i', { class: 'ti ti-photo text-success', title: 'Ada foto' }) : h('i', { class: 'ti ti-photo-off text-muted', title: 'Belum ada foto' })) },
      { label: 'Status', render: (i) => stockBadge(i) },
      { label: '', render: (i) => h('div', { style: 'display:flex;gap:4px' },
        h('button', { class: 'btn sm', onclick: (e) => { e.stopPropagation(); stockDialog(i, ctx, load); } }, 'Stock'),
        h('button', { class: 'btn sm', onclick: (e) => { e.stopPropagation(); itemDialog(i, ctx, load); } }, 'Edit'),
        canDelete ? h('button', { class: 'btn sm danger', onclick: async (e) => {
          e.stopPropagation();
          if (await confirmBox('Hapus item', `Hapus ${i.id} — ${i.name} beserta riwayat transaksinya? Tidak dapat diurungkan.`)) await attempt(async () => { await api.post('mw/item/delete', { id: i.id }); await load(); }, 'Item dihapus.');
        } }, 'Del') : null) },
    ], r.rows, { empty: 'Tidak ada item yang cocok.', onRow: (i) => drawer(i, ctx, load) }));
    const pages = Math.max(1, Math.ceil(r.total / PAGE));
    count.textContent = `${r.total} item`;
    pager.replaceChildren(h('span', { class: 'muted' }, `halaman ${page + 1}/${pages}`),
      h('button', { class: 'btn sm', disabled: page === 0, onclick: () => { page--; load(); } }, '‹'),
      h('button', { class: 'btn sm', disabled: page + 1 >= pages, onclick: () => { page++; load(); } }, '›'));
  };
  const reload = () => { page = 0; load(); };
  const sel = (label, key, values) => h('select', { 'aria-label': label, onchange: (e) => { f[key] = e.target.value; reload(); } }, h('option', { value: '' }, `Semua ${label}`), values.map((v) => h('option', {}, v)));
  let t;
  const lowChip = h('button', { class: `chip${f.low ? ' on' : ''}`, onclick: () => { f.low = f.low ? '' : '1'; lowChip.classList.toggle('on', !!f.low); reload(); } }, '⚠ Low stock');
  const unitSel = h('select', { 'aria-label': 'Unit', onchange: (e) => { f.unit = e.target.value; reload(); } }, h('option', { value: '' }, 'Semua unit'), meta.units.map((u) => h('option', { value: u.id }, u.name)));
  unitSel.value = f.unit;
  const noUnitChip = h('button', { class: `chip${f.nounit ? ' on' : ''}`, onclick: () => { f.nounit = f.nounit ? '' : '1'; noUnitChip.classList.toggle('on', !!f.nounit); reload(); } }, 'Tanpa unit');
  const bulkUnit = () => {
    const u = h('select', {}, h('option', { value: '' }, '— pilih Unit tujuan —'), meta.units.map((x) => h('option', { value: x.id }, x.name)));
    const hasFilter = f.q || f.type || f.class || f.moving || f.nounit;
    modal('Atur Unit massal', h('div', {}, h('p', {}, hasFilter ? 'Unit akan diterapkan ke SEMUA item yang cocok dengan filter yang sedang aktif (bukan hanya halaman ini). Transaksi lama item itu yang belum punya Unit ikut diisi.' : 'Aktifkan filter dulu (mis. chip “Tanpa unit”, Type, atau pencarian) supaya tidak mengubah seluruh item sekaligus.'), h('label', { class: 'field' }, h('span', {}, 'Unit tujuan'), u)), { actions: [
      { label: 'Batal', run: () => {} },
      ...(hasFilter ? [{ label: 'Terapkan', kind: 'primary', run: async () => {
        if (!u.value) { await attempt(async () => { throw new Error('Pilih Unit tujuan.'); }); return false; }
        const r = await attempt(() => api.post('mw/items/unit', { unit_id: Number(u.value), q: f.q, type: f.type, class: f.class, moving: f.moving, nounit: f.nounit }), 'Unit diterapkan.');
        if (!r) return false;
        await load();
      } }] : []),
    ] });
  };
  const noPhotoChip = h('button', { class: `chip${f.photo ? ' on' : ''}`, onclick: () => { f.photo = f.photo ? '' : 'none'; noPhotoChip.classList.toggle('on', !!f.photo); reload(); } }, 'Tanpa foto');
  root.append(h('div', { class: 'page' },
    h('div', { class: 'page-head' }, h('h1', {}, 'All Items'), count, h('span', { class: 'sp' }),
      h('button', { class: 'btn', onclick: () => stockDialog(null, ctx, load, 'OUT') }, '± Stock IN / OUT'),
      h('button', { class: 'btn primary', onclick: () => itemDialog(null, ctx, load) }, '+ Item')),
    h('div', { class: 'toolbar' },
      h('input', { class: 'grow', type: 'search', value: f.q, placeholder: 'Cari kode, nama, brand, spesifikasi, PIC…', oninput: (e) => { clearTimeout(t); t = setTimeout(() => { f.q = e.target.value; reload(); }, 300); } }),
      sel('type', 'type', m.types), sel('class', 'class', m.classes), sel('moving', 'moving', m.moving), unitSel, lowChip, noUnitChip, noPhotoChip, ['admin', 'spv'].includes(user.role) ? h('button', { class: 'btn sm', onclick: bulkUnit }, 'Atur Unit massal') : null),
    host, pager));
  await load();
}

async function drawer(item, ctx, reload) {
  const d = await api.get('mw/item', { id: item.id });
  const i = d.item;
  const kv = (k, v) => [h('dt', {}, k), h('dd', {}, v ?? '—')];
  const pic = h('div', { class: 'ph-view' }, i.has_photo ? h('span', { class: 'muted' }, 'Memuat foto…') : h('div', { class: 'ph-empty' }, h('i', { class: 'ti ti-photo-off' }), h('span', {}, 'Belum ada foto — tambahkan lewat Edit')));
  if (i.has_photo) photoUrl(i.id).then((u) => pic.replaceChildren(u ? h('img', { src: u, alt: i.name, onclick: () => window.open(u, '_blank') }) : h('span', { class: 'muted' }, 'Foto tidak dapat dimuat.')));
  modal(`${i.id} — ${i.name}`, h('div', {},
    h('div', { class: 'ph-split' }, h('dl', { class: 'kv' }, kv('Unit', i.unit_name ? h('b', {}, i.unit_name) : h('span', { class: 'badge prio-critical' }, 'Belum ada — lengkapi lewat Edit')), kv('Brand', i.brand), kv('Spesifikasi', i.spec), kv('Type / Class', `${i.item_type ?? '—'} / ${i.item_class ?? '—'}`), kv('Moving', i.moving_type), kv('PIC', i.pic),
      kv('Harga', rp(i.price)), kv('Catatan', i.notes),
      kv('Stok sekarang', h('span', {}, h('b', { style: `font-size:18px;color:${isLow(i) ? 'var(--red)' : 'var(--green)'}` }, num(i.qty)), ` ${i.uom} `, stockBadge(i))),
      kv('Min stok', num(i.min_stock)), kv('Nilai stok', rp(i.qty * i.price))), pic),
    h('h3', { style: 'margin:16px 0 8px' }, 'Dipakai di equipment'),
    d.equipment.length ? d.equipment.map((e) => h('div', { class: 'note' },
      h('a', { href: `#/eq/list?id=${e.id}`, onclick: () => document.querySelector('.overlay')?.remove() }, h('b', {}, e.name)), h('small', { class: 'muted' }, ` ${[e.unit_name, e.area_name].join(' › ')}`),
      h('div', { class: 'muted' }, [e.standard_qty != null ? `sparepart standar: ${num(e.standard_qty)} / mesin` : null, Number(e.used_qty) > 0 ? `terpakai ${num(e.used_qty)} ${i.uom}${e.last_used ? ' · terakhir ' + dt(e.last_used, false) : ''}` : null].filter(Boolean).join(' · ') || '—')))
      : h('p', { class: 'muted' }, 'Belum tertaut ke equipment (tautkan di Equipment Database → Sparepart, atau pilih equipment saat Stock OUT).'),
    h('h3', { style: 'margin:16px 0 8px' }, 'Histori transaksi (20 terbaru)'),
    d.history.length ? d.history.map((t) => h('div', { class: 'note' }, h('div', {}, trxBadge(t.trx_type), ' ', h('b', {}, `${t.trx_type === 'IN' ? '+' : '−'}${num(t.qty)}`), h('span', { class: 'muted' }, ` → sisa ${num(t.stock_after)}`)),
      h('small', {}, `${dt(t.trx_date)} · ${t.pic ?? '—'}${t.equipment_name ? ' · ' + [t.area_name, t.equipment_name].join(' › ') : ''}${t.wo_id ? ' · ' + t.wo_id : ''}${t.notes ? ' · ' + t.notes : ''}`)))
      : h('p', { class: 'muted' }, 'Belum ada transaksi.')), { wide: true, actions: [
    { label: '+ Stock', run: () => { stockDialog(i, ctx, reload); } },
    { label: 'Edit', run: () => { itemDialog(i, ctx, reload); } },
  ] });
}
