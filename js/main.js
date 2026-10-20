import { api, session, setUnauthorizedHandler } from './core/api.js';
import { h, countUp, skeleton } from './core/ui.js';
import { MENU, PAGES, visibleItems, findItem } from './core/menu.js';
import { searchBox, notifBell } from './core/topbar.js';

const app = document.getElementById('app');
const html = document.documentElement;
export const state = { user: null, meta: null };

// ── Router berbasis hash: #/<modul>/<halaman>?param=nilai ──
export function parseHash() {
  const raw = location.hash.replace(/^#\/?/, '');
  const [path, qs] = raw.split('?');
  return { path: path || 'wo/list', query: Object.fromEntries(new URLSearchParams(qs || '')) };
}
export const go = (path, query) => {
  const qs = query ? '?' + new URLSearchParams(query) : '';
  location.hash = `#/${path}${qs}`;
};

// ── Tema (layout Arclon): data-bs-theme / data-sidenav-size diatur di <html> ──
const store = {
  get: (k) => { try { return localStorage.getItem(k); } catch { return null; } },
  set: (k, v) => { try { localStorage.setItem(k, v); } catch { /* storage diblokir */ } },
};
const MOBILE = 1140;
const applySidenav = () => html.setAttribute('data-sidenav-size', window.innerWidth <= MOBILE ? 'full' : (store.get('cmms.sidenav') || 'default'));
const setTheme = (t) => { html.setAttribute('data-bs-theme', t); store.set('cmms.theme', t); };
setTheme(store.get('cmms.theme') || 'dark');

setUnauthorizedHandler(() => { session.token = null; state.user = null; showLogin('Sesi berakhir, silakan login ulang.'); });

async function boot() {
  if (!session.token) return showLogin();
  try {
    state.user = (await api.get('auth/me')).user;
    await loadMeta();
    showShell();
  } catch {
    showLogin();
  }
}

export async function loadMeta() {
  state.meta = await api.get('meta');
}

function showLogin(msg) {
  stopShell();
  const u = h('input', { name: 'username', class: 'form-control', autocomplete: 'username', placeholder: 'Masukkan username', required: true });
  const p = h('input', { name: 'password', class: 'form-control', type: 'password', autocomplete: 'current-password', placeholder: 'Masukkan password', required: true });
  const err = h('div', { class: 'login-err' }, msg || '');
  const f = h('form', { class: 'text-start mb-3', onsubmit: async (e) => {
    e.preventDefault();
    err.textContent = '';
    try {
      const r = await api.post('auth/login', { username: u.value, password: p.value });
      session.token = r.token;
      state.user = r.user;
      await loadMeta();
      showShell();
    } catch (ex) { err.textContent = ex.message; }
  } },
  h('div', { class: 'mb-3' }, h('label', { class: 'form-label' }, 'Username'), u),
  h('div', { class: 'mb-3' }, h('label', { class: 'form-label' }, 'Password'), p), err,
  h('div', { class: 'd-grid' }, h('button', { class: 'btn primary fw-semibold', type: 'submit' }, 'Masuk')));
  app.replaceChildren(h('div', { class: 'auth-bg d-flex min-vh-100' },
    h('div', { class: 'auth-wrap' },
      h('div', { class: 'auth-brand' }, h('i', { class: 'ti ti-settings-cog' }), h('span', {}, 'CMMS')),
      h('p', { class: 'fw-semibold mb-4 text-center text-muted fs-15' }, 'Computerized Maintenance Management System'),
      h('div', { class: 'card overflow-hidden text-center p-3 p-xxl-4 mb-0' }, h('h4', { class: 'fw-semibold mb-3 fs-18' }, 'Masuk ke akun Anda'), f),
      h('p', { class: 'text-center mt-4 text-muted' }, 'Work Order · Daily Monitoring · Preventive Maintenance · MaintWare'))));
  u.focus();
}

// ── Shell aplikasi (struktur Arclon: sidenav-menu + app-topbar + page-content) ──
let shellCleanup = null;
function stopShell() {
  shellCleanup?.();
  shellCleanup = null;
  html.classList.remove('sidebar-enable');
  document.getElementById('custom-backdrop')?.remove();
}

const roleLabel = (r) => ({ admin: 'Admin', spv: 'Leader / SPV', maintenance: 'Maintenance', user: 'User' }[r] || r);

function showShell() {
  stopShell();
  const user = state.user;
  const first = findItem(parseHash().path);
  const open = new Set([first?.group.key || 'wo', first?.parent?.key].filter(Boolean));
  const nav = h('ul', { class: 'side-nav' });
  const content = h('div', { class: 'page-container', id: 'content' });
  const titleEl = h('h4', { class: 'page-title fs-20 fw-semibold mb-0' }, 'CMMS');
  const userMenu = h('div', { class: 'dropdown-menu dropdown-menu-end', 'data-bs-popper': 'static' });
  let cur = parseHash().path;

  const closeMobile = () => { html.classList.remove('sidebar-enable'); document.getElementById('custom-backdrop')?.remove(); document.body.style.overflow = ''; };
  const isActive = (p) => cur === p || (cur === 'wo/detail' && p === 'wo/list') || (cur === 'dm/entry' && p === 'dm/monitor') || (cur === 'pm/form' && p === 'pm/input');

  const link = (i) => h('li', { class: 'side-nav-item' },
    h('a', { href: `#/${i.path}`, class: `side-nav-link${isActive(i.path) ? ' active' : ''}${i.ready === false ? ' opacity-50' : ''}`, onclick: closeMobile },
      i.icon && !i.sub ? h('span', { class: 'menu-icon' }, h('i', { class: `ti ${i.icon}` })) : null,
      h('span', { class: 'menu-text' }, i.label), i.ready === false ? h('span', { class: 'badge bg-secondary-subtle text-secondary ms-auto' }, 'segera') : null));

  /** Induk yang bisa dilipat: modul (tingkat 1) atau submenu (tingkat 2, mis. Setting → Equipment Database). */
  const parent = (key, icon, label, children) => {
    const isOpen = open.has(key);
    return h('li', { class: `side-nav-item${isOpen ? ' active' : ''}` },
      h('a', { href: 'javascript:void(0)', class: 'side-nav-link', 'aria-expanded': String(isOpen), onclick: (e) => {
          const link = e.currentTarget, li = link.parentNode, now = !open.has(key);
          now ? open.add(key) : open.delete(key);
          link.setAttribute('aria-expanded', String(now));
          li.classList.toggle('active', now);
          li.querySelector(':scope > .collapse').classList.toggle('show', now);
        } },
        h('span', { class: 'menu-icon' }, h('i', { class: `ti ${icon}` })), h('span', { class: 'menu-text' }, label), h('span', { class: 'menu-arrow' })),
      h('div', { class: `collapse${isOpen ? ' show' : ''}` }, h('ul', { class: 'sub-menu' }, children)));
  };

  const draw = () => {
    cur = parseHash().path;
    nav.replaceChildren(h('li', { class: 'side-nav-title' }, 'Modul'),
      ...MENU.filter((g) => g.key !== 'st').map((g) => {
        const items = visibleItems(g, user.role);
        return items.length ? parent(g.key, g.icon, g.title, items.map((i) => link({ ...i, sub: true }))) : null;
      }),
      h('li', { class: 'side-nav-title mt-2' }, 'Pengaturan'),
      ...MENU.filter((g) => g.key === 'st').map((g) => parent(g.key, g.icon, g.title, visibleItems(g, user.role).map((i) =>
        (i.children ? parent(i.key, i.icon, i.label, i.children.map((c) => link({ ...c, sub: true }))) : link({ ...i, sub: true }))))));
  };

  const render = async () => {
    const { path, query } = parseHash();
    bell.reload();
    const item = findItem(path);
    if (item) { open.add(item.group.key); if (item.parent) open.add(item.parent.key); }
    draw();
    const title = item ? item.label : ({ 'wo/detail': 'Detail Work Order', 'dm/entry': 'Form Monitoring', 'pm/form': 'Laporan PM' }[path] ?? 'CMMS');
    titleEl.textContent = title;
    document.title = `${title} · CMMS`;
    content.replaceChildren(skeleton());
    window.scrollTo(0, 0);
    try {
      if (item && item.roles && !item.roles.includes(user.role)) throw new Error('Anda tidak punya akses ke halaman ini.');
      if (!PAGES[path]) {
        const pending = await import('./modules/pending.js');
        return pending.default(content, { item, path });
      }
      const mod = await PAGES[path]();
      content.replaceChildren();
      await mod.default(content, { query, user, meta: state.meta, go, reloadMeta: loadMeta });
      // judul halaman sudah tampil di topbar — buang h1 kembar
      const h1 = content.querySelector('.page > .page-head > h1');
      if (h1 && h1.textContent.trim() === title) h1.remove();
      countUp(content);
    } catch (e) {
      content.replaceChildren(h('div', { class: 'empty err' }, e.message));
    }
  };

  // tombol sidenav: layar kecil = buka/tutup (dengan backdrop), layar besar = ringkas/lebar
  const toggleSidenav = () => {
    if (window.innerWidth <= MOBILE) {
      if (html.classList.toggle('sidebar-enable')) {
        document.body.append(h('div', { id: 'custom-backdrop', class: 'offcanvas-backdrop fade show', onclick: closeMobile }));
        document.body.style.overflow = 'hidden';
      } else closeMobile();
    } else {
      const next = html.getAttribute('data-sidenav-size') === 'condensed' ? 'default' : 'condensed';
      store.set('cmms.sidenav', next);
      applySidenav();
    }
  };
  const toggleTheme = () => setTheme(html.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark');
  const dropdown = (el) => { const show = !el.classList.contains('show'); el.classList.toggle('show', show); };
  const outside = (e) => { if (!e.target.closest('.nav-user')) userMenu.classList.remove('show'); };
  const gsearch = searchBox();
  const bell = notifBell();
  const onResize = () => { applySidenav(); if (window.innerWidth > MOBILE) closeMobile(); };

  userMenu.append(
    h('div', { class: 'dropdown-header noti-title' }, h('h6', { class: 'text-overflow m-0' }, user.name), h('small', { class: 'text-muted' }, roleLabel(user.role))),
    h('a', { href: 'javascript:void(0)', class: 'dropdown-item pwa-install', hidden: !installEvent, onclick: () => { userMenu.classList.remove('show'); installApp(); } }, h('i', { class: 'ti ti-device-mobile-down me-1 fs-16 align-middle' }), h('span', { class: 'align-middle' }, 'Pasang aplikasi')),
    h('a', { href: '#/st/account', class: 'dropdown-item', onclick: () => userMenu.classList.remove('show') }, h('i', { class: 'ti ti-user-circle me-1 fs-16 align-middle' }), h('span', { class: 'align-middle' }, 'Akun Saya')),
    h('div', { class: 'dropdown-divider' }),
    h('a', { href: 'javascript:void(0)', class: 'dropdown-item text-danger fw-semibold', onclick: async () => { try { await api.post('auth/logout'); } catch { /* sudah keluar */ } session.token = null; location.hash = ''; location.reload(); } },
      h('i', { class: 'ti ti-logout me-1 fs-16 align-middle' }), h('span', { class: 'align-middle' }, 'Keluar')));

  app.replaceChildren(h('div', { class: 'wrapper' },
    h('div', { class: 'sidenav-menu' },
      h('a', { href: '#/wo/list', class: 'logo' }, h('span', { class: 'logo-lg brand-mark' }, h('i', { class: 'ti ti-settings-cog' }), h('b', {}, 'CMMS')), h('span', { class: 'logo-sm brand-mark' }, h('i', { class: 'ti ti-settings-cog' }))),
      h('button', { class: 'button-close-fullsidebar', 'aria-label': 'Tutup menu', onclick: closeMobile }, h('i', { class: 'ti ti-x align-middle' })),
      h('div', { class: 'sidenav-scroll' }, nav)),
    h('header', { class: 'app-topbar', id: 'header' }, h('div', { class: 'page-container topbar-menu' },
      h('div', { class: 'd-flex align-items-center gap-2' },
        h('button', { class: 'sidenav-toggle-button', 'aria-label': 'Menu', onclick: toggleSidenav }, h('i', { class: 'ri-menu-5-line fs-24' })),
        h('div', { class: 'topbar-item px-2' }, titleEl)),
      gsearch.el,
      h('div', { class: 'd-flex align-items-center gap-2' },
        bell.el,
        h('div', { class: 'topbar-item d-none d-sm-flex' }, h('button', { class: 'topbar-link', id: 'light-dark-mode', type: 'button', 'aria-label': 'Ganti tema', onclick: toggleTheme },
          h('i', { class: 'ri-moon-line light-mode-icon fs-22' }), h('i', { class: 'ri-sun-line dark-mode-icon fs-22' }))),
        h('div', { class: 'topbar-item nav-user' }, h('div', { class: 'dropdown' },
          h('a', { href: 'javascript:void(0)', class: 'topbar-link dropdown-toggle drop-arrow-none px-2', 'aria-haspopup': 'true', onclick: () => dropdown(userMenu) },
            h('span', { class: 'user-av me-lg-2' }, user.name.slice(0, 1).toUpperCase()),
            h('span', { class: 'd-lg-flex flex-column d-none lh-sm' }, h('h5', { class: 'my-0 fs-14' }, user.name), h('small', { class: 'text-muted' }, roleLabel(user.role))),
            h('i', { class: 'ri-arrow-down-s-line d-none d-lg-block align-middle ms-1' })),
          userMenu))))),
    h('div', { class: 'page-content' }, content,
      h('footer', { class: 'footer' }, h('div', { class: 'page-container' }, h('div', { class: 'row' },
        h('div', { class: 'col-md-6 text-center text-md-start' }, `${new Date().getFullYear()} © CMMS — Computerized Maintenance Management System`),
        h('div', { class: 'col-md-6 text-center text-md-end text-muted' }, 'Template: Arclon')))))));

  applySidenav();
  window.addEventListener('hashchange', render);
  const onScroll = () => document.getElementById('header')?.classList.toggle('topbar-active', window.scrollY >= 25);
  window.addEventListener('scroll', onScroll, { passive: true });
  window.addEventListener('resize', onResize);
  document.addEventListener('click', outside);
  shellCleanup = () => {
    window.removeEventListener('hashchange', render);
    window.removeEventListener('resize', onResize);
    window.removeEventListener('scroll', onScroll);
    document.removeEventListener('click', outside);
    gsearch.cleanup();
    bell.cleanup();
  };
  render();
}

// ── PWA: service worker + tombol "Pasang aplikasi" ──
let installEvent = null;
window.addEventListener('beforeinstallprompt', (e) => { e.preventDefault(); installEvent = e; document.querySelector('.pwa-install')?.removeAttribute('hidden'); });
window.addEventListener('appinstalled', () => { installEvent = null; document.querySelector('.pwa-install')?.setAttribute('hidden', ''); });
export const installApp = async () => { if (!installEvent) return; installEvent.prompt(); await installEvent.userChoice.catch(() => null); installEvent = null; document.querySelector('.pwa-install')?.setAttribute('hidden', ''); };
if ('serviceWorker' in navigator && (location.protocol === 'https:' || location.hostname === 'localhost' || location.hostname === '127.0.0.1')) {
  window.addEventListener('load', () => navigator.serviceWorker.register('sw.js').catch(() => { /* PWA opsional */ }));
}

boot();
