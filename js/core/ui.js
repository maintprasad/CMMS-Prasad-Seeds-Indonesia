// Helper DOM kecil: pembuat elemen, modal, toast, format.
export function h(tag, attrs, ...children) {
  const el = document.createElement(tag);
  for (const [k, v] of Object.entries(attrs || {})) {
    if (v == null || v === false) continue;
    if (k === 'class') el.className = v;
    else if (k === 'html') el.innerHTML = v;
    else if (k.startsWith('on') && typeof v === 'function') el.addEventListener(k.slice(2), v);
    else if (k === 'value') el.value = v;
    else if (v === true) el.setAttribute(k, '');
    else el.setAttribute(k, v);
  }
  for (const c of children.flat(Infinity)) {
    if (c == null || c === false) continue;
    el.append(c.nodeType ? c : document.createTextNode(String(c)));
  }
  return el;
}

export const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

export function toast(msg, kind = 'ok') {
  const t = h('div', { class: `msg-toast ${kind}` }, msg);
  document.getElementById('toasts').append(t);
  const life = kind === 'err' ? 5000 : 2500;
  setTimeout(() => t.classList.add('out'), life - 300);
  setTimeout(() => t.remove(), life);
}

/** Jalankan aksi async dengan toast error otomatis. Mengembalikan hasil, atau undefined bila gagal. */
export async function attempt(fn, okMsg) {
  try {
    const r = await fn();
    if (okMsg) toast(okMsg);
    return r ?? true;
  } catch (e) {
    toast(e.message, 'err');
    return undefined;
  }
}

export function modal(title, body, { actions = [], wide = false } = {}) {
  const close = () => {
    document.removeEventListener('keydown', onKey);
    overlay.classList.add('closing'); // animasi keluar, lalu dilepas
    setTimeout(() => overlay.remove(), 180);
  };
  const onKey = (e) => { if (e.key === 'Escape') close(); };
  const overlay = h('div', { class: 'overlay', onmousedown: (e) => { if (e.target === overlay) close(); } },
    h('div', { class: `dlg${wide ? ' wide' : ''}`, role: 'dialog', 'aria-label': title },
      h('div', { class: 'dlg-head' }, h('h3', {}, title), h('button', { class: 'icon-btn', 'aria-label': 'Tutup', onclick: close }, h('i', { class: 'ti ti-x' }))),
      h('div', { class: 'dlg-body' }, body),
      actions.length ? h('div', { class: 'dlg-foot' }, actions.map((a) => h('button', {
        class: `btn ${a.kind || ''}`,
        onclick: async (e) => {
          const btn = e.currentTarget;
          btn.disabled = true;
          try { if ((await a.run()) !== false) close(); } finally { btn.disabled = false; }
        },
      }, a.label))) : null));
  document.addEventListener('keydown', onKey);
  document.body.append(overlay);
  overlay.querySelector('input,select,textarea')?.focus();
  return { close };
}

export const confirmBox = (title, text) => new Promise((resolve) => {
  modal(title, h('p', {}, text), { actions: [
    { label: 'Batal', run: () => resolve(false) },
    { label: 'Ya, lanjutkan', kind: 'primary', run: () => resolve(true) },
  ] });
});

/** Form dari definisi field → { node, inputs, values() }. */
export function form(fields, init = {}) {
  const inputs = {};
  const node = h('div', { class: 'form-grid' }, fields.map((f) => {
    let input;
    if (f.type === 'select') {
      input = h('select', {}, f.optional ? h('option', { value: '' }, '—') : null,
        (f.options || []).map((o) => h('option', { value: o.value ?? o }, o.label ?? o)));
    } else if (f.type === 'textarea') {
      input = h('textarea', { rows: f.rows || 3, placeholder: f.placeholder || '' });
    } else {
      input = h('input', { type: f.type || 'text', placeholder: f.placeholder || '', min: f.min, step: f.step });
    }
    input.value = init[f.name] ?? f.default ?? '';
    inputs[f.name] = input;
    return h('label', { class: `field${f.wide ? ' wide' : ''}` }, h('span', {}, f.label + (f.required ? ' *' : '')), input);
  }));
  return { node, inputs, values: () => Object.fromEntries(Object.entries(inputs).map(([k, el]) => [k, el.value])) };
}

// ── Format ──
export function dur(sec) {
  sec = Math.max(0, Math.round(Number(sec) || 0));
  const d = Math.floor(sec / 86400), hh = Math.floor((sec % 86400) / 3600), m = Math.floor((sec % 3600) / 60);
  if (d) return `${d}h ${hh}j ${m}m`;
  if (hh) return `${hh}j ${m}m`;
  return `${m}m`;
}
export const hours = (v) => (v == null ? '—' : `${Number(v).toLocaleString('id-ID', { maximumFractionDigits: 1 })} j`);
const MON = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
export function dt(s, withTime = true) {
  if (!s) return '—';
  const d = new Date(String(s).replace(' ', 'T'));
  if (isNaN(d)) return s;
  const base = `${d.getDate()} ${MON[d.getMonth()]} ${d.getFullYear()}`;
  return withTime ? `${base} ${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}` : base;
}

const STATUS_CLASS = { 'Open': 'open', 'In Progress': 'prog', 'Pending Verification': 'pend', 'Closed': 'done', 'Cancelled': 'cancel' };
export const statusBadge = (s, hold = false) =>
  h('span', { class: `badge ${STATUS_CLASS[s] || ''}` }, s, hold ? ' · Hold' : null);
export const prioBadge = (p) => h('span', { class: `badge prio-${String(p).toLowerCase()}` }, p);

/** Durasi WO; bila timer berjalan, diperbarui tiap 20 detik selama elemen masih di halaman. */
export function liveTimer(baseSeconds, runningSince) {
  const el = h('span', { class: 'timer' }, dur(baseSeconds));
  if (!runningSince) return el;
  el.classList.add('running');
  const t0 = new Date(runningSince.replace(' ', 'T')).getTime();
  const iv = setInterval(() => {
    if (!el.isConnected) return clearInterval(iv);
    el.textContent = dur(baseSeconds + Math.max(0, (Date.now() - t0) / 1000));
  }, 20000);
  return el;
}

export function barChart(series, { keys, colors }) {
  const max = Math.max(1, ...series.flatMap((s) => keys.map((k) => s[k])));
  const W = 600, H = 150, pad = 18, bw = (W - pad * 2) / series.length;
  const label = (s) => String(s.date || s.period);
  const bars = series.map((s, i) => keys.map((k, j) => {
    const v = s[k], hh = (v / max) * (H - 30), w = (bw - 3) / keys.length;
    return `<rect style="--i:${i}" x="${pad + i * bw + j * w}" y="${H - 18 - hh}" width="${Math.max(1, w - 1)}" height="${hh}" rx="1.5" fill="${colors[j]}"><title>${esc(label(s))}: ${k} ${v}</title></rect>`;
  }).join('')).join('');
  const step = Math.ceil(series.length / 6);
  const lab = series.map((s, i) => (i % step ? '' :
    `<text x="${pad + i * bw}" y="${H - 4}" font-size="9" fill="currentColor" opacity=".6">${esc(label(s).slice(-5))}</text>`)).join('');
  return h('div', { class: 'chart', html: `<svg viewBox="0 0 ${W} ${H}" preserveAspectRatio="none" role="img" aria-label="Grafik">${bars}${lab}</svg>` });
}

/** Angka pada kartu statistik menghitung naik dari 0 (mis. "1.234", "Rp 627.594.110", "45%"). Teks majemuk dibiarkan. */
export function countUp(root) {
  if (matchMedia('(prefers-reduced-motion: reduce)').matches) return;
  root.querySelectorAll('.stat b').forEach((el) => {
    const m = /^(\D*?)(\d{1,3}(?:\.\d{3})+|\d+)(\D*)$/.exec(el.textContent.trim());
    if (!m) return;
    const target = parseInt(m[2].replace(/\./g, ''), 10);
    if (!target || target < 2) return;
    const t0 = performance.now(), dur = 900;
    const step = (now) => {
      const p = Math.min(1, (now - t0) / dur), e = 1 - Math.pow(1 - p, 3);
      el.textContent = m[1] + Math.round(target * e).toLocaleString('id-ID') + m[3];
      if (p < 1) requestAnimationFrame(step);
    };
    requestAnimationFrame(step);
  });
}

/** Kerangka pemuatan sementara halaman dibuat. */
export const skeleton = () => h('div', { class: 'skeleton' },
  h('div', { class: 'sk sk-title' }), h('div', { class: 'sk-grid' }, [0, 1, 2, 3].map(() => h('div', { class: 'sk sk-card' }))), h('div', { class: 'sk sk-table' }));

export function table(cols, rows, { empty = 'Tidak ada data.', onRow } = {}) {
  if (!rows.length) return h('div', { class: 'empty' }, empty);
  return h('div', { class: 'tbl-wrap' }, h('table', { class: 'tbl' },
    h('thead', {}, h('tr', {}, cols.map((c) => h('th', { class: c.cls }, c.label)))),
    h('tbody', {}, rows.map((r) => h('tr', { class: onRow ? 'click' : '', onclick: onRow ? () => onRow(r) : null },
      cols.map((c) => h('td', { 'data-label': typeof c.label === 'string' ? c.label : (c.title || ''), class: c.cls }, c.render ? c.render(r) : (r[c.key] ?? '—'))))))));
}

export function downloadCsv(name, header, rows) {
  const q = (v) => `"${String(v ?? '').replace(/"/g, '""')}"`;
  const csv = '﻿' + [header, ...rows].map((r) => r.map(q).join(',')).join('\r\n');
  const a = h('a', { href: URL.createObjectURL(new Blob([csv], { type: 'text/csv' })), download: name });
  document.body.append(a);
  a.click();
  a.remove();
}
