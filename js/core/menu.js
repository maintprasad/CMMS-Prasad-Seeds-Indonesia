// Struktur menu bertingkat: modul → menu. Menu asli tiap aplikasi lama dipertahankan.
// ready:false = halaman belum dibangun (tahap berikutnya), tetap tampil agar struktur menu utuh.
const ALL = ['admin', 'spv', 'maintenance', 'user'];
const STAFF = ['admin', 'spv', 'maintenance'];

export const MENU = [
  {
    key: 'wo', title: 'Work Order', icon: 'ti-clipboard-list', items: [
      { path: 'wo/list', label: 'Progress WO', icon: 'ti-clipboard-list', roles: ALL },
      { path: 'wo/comparison', label: 'in / out', icon: 'ti-arrows-exchange', roles: ALL },
      { path: 'wo/dashboard', label: 'Dashboard', icon: 'ti-layout-dashboard', roles: ALL },
      { path: 'wo/equip-history', label: 'Equip. History', icon: 'ti-history', roles: STAFF },
      { path: 'wo/mttr', label: 'MTTR / MTBF', icon: 'ti-clock-hour-4', roles: STAFF },
      { path: 'wo/reports', label: 'Reports', icon: 'ti-file-analytics', roles: STAFF },
      { path: 'wo/rating', label: 'Rating Teknisi', icon: 'ti-star', roles: STAFF },
    ],
  },
  {
    key: 'dm', title: 'Daily Monitoring', icon: 'ti-heartbeat', items: [
      { path: 'dm/dashboard', label: 'Dashboard', icon: 'ti-layout-dashboard', roles: STAFF },
      { path: 'dm/monitor', label: 'Monitor', icon: 'ti-device-desktop-analytics', roles: STAFF },
      { path: 'dm/history', label: 'Riwayat', icon: 'ti-history', roles: STAFF },
      { path: 'dm/findings', label: 'Finding / Alarm', icon: 'ti-alert-triangle', roles: STAFF },
    ],
  },
  {
    key: 'pm', title: 'Preventive Maintenance', icon: 'ti-tool', items: [
      { path: 'pm/dashboard', label: 'Dashboard PM', icon: 'ti-layout-dashboard', roles: STAFF },
      { path: 'pm/schedule', label: 'Schedule PM', icon: 'ti-calendar', roles: STAFF },
      { path: 'pm/input', label: 'Input PM', icon: 'ti-clipboard-check', roles: STAFF },
      { path: 'pm/list', label: 'Daftar Laporan', icon: 'ti-list-details', roles: STAFF },
      { path: 'pm/resume', label: 'Resume Laporan', icon: 'ti-report', roles: STAFF },
      { path: 'pm/outstanding', label: 'Outstanding PM', icon: 'ti-alarm', roles: STAFF },
      { path: 'pm/takeover', label: 'Takeover PM', icon: 'ti-switch-horizontal', roles: STAFF },
      { path: 'pm/templates', label: 'Kelola Template PM', icon: 'ti-template', roles: STAFF },
    ],
  },
  {
    key: 'mw', title: 'MaintWare', icon: 'ti-packages', items: [
      { path: 'mw/dashboard', label: 'Dashboard', icon: 'ti-layout-dashboard', roles: STAFF },
      { path: 'mw/items', label: 'All Items', icon: 'ti-box', roles: STAFF },
      { path: 'mw/log', label: 'Stock Log', icon: 'ti-list', roles: STAFF },
      { path: 'mw/monthly', label: 'Monthly Report', icon: 'ti-calendar-stats', roles: STAFF },
      { path: 'mw/reorder', label: 'Reorder List', icon: 'ti-shopping-cart', roles: STAFF },
      { path: 'mw/purchase', label: 'Purchase Request', icon: 'ti-receipt', roles: STAFF },
      { path: 'mw/config', label: 'Config', icon: 'ti-settings', roles: STAFF },
    ],
  },
  {
    key: 'rp', title: 'Report', icon: 'ti-file-analytics', items: [
      { path: 'rp/center', label: 'Generate Report', icon: 'ti-file-export', roles: STAFF },
    ],
  },
  {
    key: 'st', title: 'Setting', icon: 'ti-settings', items: [
      { path: 'st/account', label: 'Akun Saya', icon: 'ti-user-circle', roles: ALL },
      { path: 'st/users', label: 'User', icon: 'ti-users', roles: ['admin'] },
      { path: 'st/technicians', label: 'Teknisi', icon: 'ti-helmet', roles: ['admin', 'spv'] },
      {
        key: 'st-eq', label: 'Equipment Database', icon: 'ti-building-factory-2', children: [
          { path: 'eq/list', label: 'Daftar Equipment', icon: 'ti-list-search', roles: ALL },
          { path: 'eq/structure', label: 'Unit & Area', icon: 'ti-hierarchy-2', roles: ALL },
          { path: 'eq/templates', label: 'Template Parameter', icon: 'ti-adjustments', roles: STAFF },
        ],
      },
    ],
  },
];

/** Halaman yang sudah dibangun → loader modulnya. */
export const PAGES = {
  'wo/list': () => import('../modules/wo/list.js'),
  'wo/detail': () => import('../modules/wo/detail.js'),
  'wo/comparison': () => import('../modules/wo/reports.js').then((m) => ({ default: m.comparison })),
  'wo/dashboard': () => import('../modules/wo/dashboard.js'),
  'wo/equip-history': () => import('../modules/wo/reports.js').then((m) => ({ default: m.equipHistory })),
  'wo/mttr': () => import('../modules/wo/reports.js').then((m) => ({ default: m.mttr })),
  'wo/reports': () => import('../modules/wo/reports.js').then((m) => ({ default: m.exportReport })),
  'wo/rating': () => import('../modules/wo/reports.js').then((m) => ({ default: m.rating })),
  'eq/list': () => import('../modules/equipment/list.js'),
  'eq/structure': () => import('../modules/equipment/structure.js'),
  'pm/dashboard': () => import('../modules/preventive/dashboard.js'),
  'pm/schedule': () => import('../modules/preventive/schedule.js'),
  'pm/input': () => import('../modules/preventive/input.js'),
  'pm/form': () => import('../modules/preventive/form.js'),
  'pm/list': () => import('../modules/preventive/list.js'),
  'pm/resume': () => import('../modules/preventive/resume.js'),
  'pm/outstanding': () => import('../modules/preventive/outstanding.js'),
  'pm/takeover': () => import('../modules/preventive/takeover.js'),
  'pm/templates': () => import('../modules/preventive/templates.js'),
  'rp/center': () => import('../modules/report/center.js'),
  'st/account': () => import('../modules/setting/account.js'),
  'st/users': () => import('../modules/setting/users.js'),
  'st/technicians': () => import('../modules/setting/technicians.js'),
  'eq/templates': () => import('../modules/equipment/templates.js'),
  'dm/dashboard': () => import('../modules/monitoring/dashboard.js'),
  'dm/monitor': () => import('../modules/monitoring/monitor.js'),
  'dm/entry': () => import('../modules/monitoring/entry.js'),
  'dm/history': () => import('../modules/monitoring/history.js'),
  'dm/findings': () => import('../modules/monitoring/findings.js'),
  'mw/dashboard': () => import('../modules/maintware/dashboard.js'),
  'mw/items': () => import('../modules/maintware/items.js'),
  'mw/log': () => import('../modules/maintware/log.js'),
  'mw/monthly': () => import('../modules/maintware/monthly.js'),
  'mw/reorder': () => import('../modules/maintware/reorder.js'),
  'mw/purchase': () => import('../modules/maintware/purchase.js'),
  'mw/config': () => import('../modules/maintware/config.js'),
};

/** Menu yang boleh dilihat role ini. Item dengan `children` = submenu (hilang bila semua anaknya tersembunyi). */
export const visibleItems = (group, role) => group.items
  .map((i) => (i.children ? { ...i, children: i.children.filter((c) => !c.roles || c.roles.includes(role)) } : i))
  .filter((i) => (i.children ? i.children.length : !i.roles || i.roles.includes(role)));
/** Cari item berdasarkan path (termasuk di dalam submenu); hasil memuat group dan parent (bila ada). */
export const findItem = (path) => MENU.flatMap((g) => g.items.flatMap((i) => (i.children ? i.children.map((c) => ({ ...c, group: g, parent: i })) : [{ ...i, group: g }]))).find((i) => i.path === path);
