// Komponen topbar: pencarian global (equipment, WO, MaintWare, PM) dan lonceng notifikasi.
import { api } from './api.js';
import { h } from './ui.js';

/** Kotak pencarian global dengan hasil bergrup; Ctrl+K atau "/" untuk fokus, ↑ ↓ Enter untuk navigasi. */
export function searchBox() {
  const input = h('input', { type: 'search', class: 'gs-input', placeholder: 'Cari equipment, WO, item…  (Ctrl+K)', 'aria-label': 'Pencarian global', autocomplete: 'off' });
  const panel = h('div', { class: 'gs-panel', hidden: true });
  const box = h('div', { class: 'gs-box' }, h('i', { class: 'ti ti-search gs-ico' }), input, panel);
  let timer = 0, seq = 0, links = [], active = -1;

  const close = () => { panel.hidden = true; active = -1; };
  const mark = () => links.forEach((a, i) => a.classList.toggle('on', i === active));
  const draw = (groups, q) => {
    panel.replaceChildren();
    links = [];
    if (!groups.length) panel.append(h('div', { class: 'gs-empty' }, `Tidak ada hasil untuk "${q}"`));
    for (const g of groups) {
      panel.append(h('div', { class: 'gs-title' }, h('i', { class: `ti ${g.icon}` }), g.title));
      for (const r of g.rows) {
        const a = h('a', { href: r.href, class: 'gs-item', onclick: () => { close(); input.value = ''; input.blur(); } }, h('b', {}, r.label), h('small', {}, r.sub));
        links.push(a);
        panel.append(a);
      }
    }
    active = -1;
    panel.hidden = false;
  };
  const search = async () => {
    const q = input.value.trim();
    if (q.length < 2) return close();
    const mine = ++seq;
    panel.replaceChildren(h('div', { class: 'gs-empty' }, 'Mencari…'));
    panel.hidden = false;
    try {
      const { groups } = await api.get('search', { q });
      if (mine === seq) draw(groups, q);
    } catch (e) { if (mine === seq) panel.replaceChildren(h('div', { class: 'gs-empty' }, e.message)); }
  };
  input.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(search, 250); });
  input.addEventListener('focus', () => { if (input.value.trim().length >= 2 && links.length) panel.hidden = false; });
  input.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') { close(); input.blur(); }
    else if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      if (!links.length) return;
      e.preventDefault();
      active = (active + (e.key === 'ArrowDown' ? 1 : -1) + links.length) % links.length;
      mark();
      links[active].scrollIntoView({ block: 'nearest' });
    } else if (e.key === 'Enter') {
      const a = links[active] || links[0];
      if (a) { e.preventDefault(); a.click(); }
    }
  });
  const onKey = (e) => {
    const typing = /^(INPUT|TEXTAREA|SELECT)$/.test(document.activeElement?.tagName);
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); input.focus(); input.select(); }
    else if (e.key === '/' && !typing) { e.preventDefault(); input.focus(); }
  };
  const onDoc = (e) => { if (!box.contains(e.target)) close(); };
  document.addEventListener('keydown', onKey);
  document.addEventListener('click', onDoc);
  return { el: box, focus: () => input.focus(), cleanup: () => { document.removeEventListener('keydown', onKey); document.removeEventListener('click', onDoc); clearInterval(box._poll); } };
}

/** Lonceng notifikasi: ringkasan hal yang butuh tindakan, diperbarui tiap 2 menit. */
export function notifBell() {
  const badge = h('span', { class: 'nt-badge', hidden: true });
  const list = h('div', { class: 'nt-list' });
  const menu = h('div', { class: 'dropdown-menu dropdown-menu-end nt-menu', 'data-bs-popper': 'static' },
    h('div', { class: 'dropdown-header noti-title' }, h('h6', { class: 'm-0' }, 'Perlu tindakan')), list);
  const btn = h('button', { class: 'topbar-link', type: 'button', 'aria-label': 'Notifikasi', onclick: (e) => { e.stopPropagation(); menu.classList.toggle('show'); if (menu.classList.contains('show')) load(); } },
    h('i', { class: 'ri-notification-3-line fs-22' }), badge);
  const el = h('div', { class: 'topbar-item nt-wrap' }, btn, menu);

  const load = async () => {
    try {
      const { items, total } = await api.get('notifications');
      badge.hidden = !total;
      badge.textContent = total > 99 ? '99+' : total;
      list.replaceChildren(...(items.length ? items.map((n) => h('a', { href: n.href, class: `nt-item ${n.level}`, onclick: () => menu.classList.remove('show') },
        h('span', { class: 'nt-ico' }, h('i', { class: `ti ${n.icon}` })), h('span', { class: 'nt-text' }, n.text), h('b', { class: 'nt-count' }, n.count)))
        : [h('div', { class: 'gs-empty' }, 'Semua beres — tidak ada yang perlu ditindaklanjuti.')]));
    } catch { /* abaikan; coba lagi pada siklus berikutnya */ }
  };
  const onDoc = (e) => { if (!el.contains(e.target)) menu.classList.remove('show'); };
  document.addEventListener('click', onDoc);
  load();
  const poll = setInterval(load, 120000);
  return { el, reload: load, cleanup: () => { clearInterval(poll); document.removeEventListener('click', onDoc); } };
}
