import { api, fetchBlob } from '../../core/api.js';
import { h, attempt, modal, confirmBox, toast, dt } from '../../core/ui.js';

const CATS = [
  { key: 'before', title: 'Foto Before', hint: 'kondisi sebelum perbaikan', photo: true },
  { key: 'after', title: 'Foto After', hint: 'hasil setelah perbaikan', photo: true },
  { key: 'verification', title: 'Foto Verifikasi', hint: 'bukti dari pemohon saat verifikasi', photo: true },
  { key: 'attachment', title: 'Lampiran', hint: 'PDF, Word, Excel, TXT/CSV, atau gambar · maks 3 MB', photo: false },
];
const MAX_ATTACH = 3_000_000;
const urlCache = new Map();

const blobUrl = async (id) => {
  if (!urlCache.has(id)) urlCache.set(id, URL.createObjectURL(await fetchBlob('wo/file', { id })));
  return urlCache.get(id);
};
const kb = (n) => (n >= 1e6 ? `${(n / 1e6).toFixed(1)} MB` : `${Math.max(1, Math.round(n / 1e3))} KB`);

/** Perkecil foto di browser (maks 1600 px, JPEG 80%) supaya ringan di database & koneksi seluler. */
async function compress(file) {
  const bmp = await createImageBitmap(file).catch(() => null);
  if (!bmp) throw new Error(`“${file.name}” bukan gambar yang bisa dibaca.`);
  const k = Math.min(1, 1600 / Math.max(bmp.width, bmp.height));
  const c = h('canvas');
  c.width = Math.round(bmp.width * k);
  c.height = Math.round(bmp.height * k);
  const ctx = c.getContext('2d');
  ctx.fillStyle = '#fff';
  ctx.fillRect(0, 0, c.width, c.height);
  ctx.drawImage(bmp, 0, 0, c.width, c.height);
  return new Promise((res) => c.toBlob(res, 'image/jpeg', 0.8));
}
const toBase64 = (blob) => new Promise((res, rej) => {
  const r = new FileReader();
  r.onload = () => res(String(r.result).split(',')[1]);
  r.onerror = () => rej(new Error('Gagal membaca file.'));
  r.readAsDataURL(blob);
});

export function filesTab(d, { id, user, final, reload }) {
  const wo = d.wo;
  const isOwner = wo.requestor_id === user.id;
  const worker = ['maintenance', 'spv', 'admin'].includes(user.role);
  const leader = ['spv', 'admin'].includes(user.role);
  const canUpload = (cat) => !final && ({ before: isOwner || worker, attachment: isOwner || worker, after: worker, verification: isOwner || leader }[cat]);
  const canDelete = (f) => !final && (f.uploaded_id === user.id || leader);

  const upload = async (cat, files) => {
    let okCount = 0;
    for (const f of files) {
      const ok = await attempt(async () => {
        const isImg = f.type.startsWith('image/');
        let blob = f;
        if (isImg && f.type !== 'image/gif') blob = await compress(f);
        else if (cat !== 'attachment') throw new Error('Foto harus berupa gambar.');
        if (cat === 'attachment' && blob.size > MAX_ATTACH) throw new Error(`“${f.name}” melebihi 3 MB.`);
        const name = isImg ? f.name.replace(/\.\w+$/, '') + '.jpg' : f.name;
        await api.post('wo/file', { id, category: cat, name, data: await toBase64(blob) });
      });
      if (ok === undefined) break;
      okCount++;
    }
    if (okCount) toast(`${okCount} file tersimpan.`);
    await reload();
  };

  const remove = async (f) => {
    if (await confirmBox('Hapus file', `Hapus “${f.name}”?`)) await attempt(async () => { await api.post('wo/file/delete', { file_id: f.id }); await reload(); }, 'File dihapus.');
  };

  const lightbox = async (f) => {
    const img = h('img', { alt: f.name, style: 'max-width:100%;max-height:70vh;display:block;margin:0 auto;border-radius:8px' });
    img.src = await blobUrl(f.id);
    modal(f.name, h('div', {}, img, h('p', { class: 'muted', style: 'text-align:center;margin-top:8px' }, `${f.uploaded_by} · ${dt(f.created_at)} · ${kb(f.size)}`)), { wide: true, actions: [
      ...(canDelete(f) ? [{ label: 'Hapus', kind: 'danger', run: async () => { await remove(f); } }] : []),
    ] });
  };

  const download = (f) => attempt(async () => {
    const a = h('a', { href: URL.createObjectURL(await fetchBlob('wo/file', { id: f.id })), download: f.name });
    document.body.append(a);
    a.click();
    a.remove();
  });

  const section = ({ key, title, hint, photo }) => {
    const files = d.files.filter((f) => f.category === key);
    const input = h('input', { type: 'file', multiple: true, accept: photo ? 'image/*' : '.pdf,.doc,.docx,.xls,.xlsx,.txt,.csv,image/*', style: 'display:none',
      onchange: (e) => { upload(key, [...e.target.files]); e.target.value = ''; } });
    const body = photo
      ? h('div', { class: 'photo-grid' }, files.map((f) => {
        const img = h('img', { alt: f.name, loading: 'lazy' });
        blobUrl(f.id).then((u) => { img.src = u; }).catch(() => { img.alt = 'gagal memuat'; });
        return h('button', { class: 'photo', title: `${f.name} · ${f.uploaded_by}`, onclick: () => lightbox(f) }, img);
      }))
      : h('div', {}, files.map((f) => h('div', { class: 'task' },
        h('span', {}, '📎'), h('div', {}, h('b', {}, f.name), h('div', { class: 'muted' }, `${kb(f.size)} · ${f.uploaded_by} · ${dt(f.created_at)}`)), h('span', { class: 'sp' }),
        h('button', { class: 'btn sm', onclick: () => download(f) }, '⬇ Unduh'),
        canDelete(f) ? h('button', { class: 'icon-btn', 'aria-label': 'Hapus', onclick: () => remove(f) }, '✕') : null)));
    return h('div', { class: 'card' },
      h('div', { class: 'page-head' }, h('h2', {}, title, files.length ? h('span', { class: 'muted', style: 'font-weight:400' }, ` (${files.length})`) : null), h('span', { class: 'muted' }, hint), h('span', { class: 'sp' }),
        canUpload(key) ? [input, h('button', { class: 'btn sm primary', onclick: () => input.click() }, photo ? '+ Foto' : '+ Lampiran')] : null),
      files.length ? body : h('p', { class: 'muted' }, 'Belum ada.'));
  };
  return h('div', {}, CATS.map(section));
}
