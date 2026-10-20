// Klien API: semua modul memanggil backend lewat sini.
const BASE = new URL('api/index.php', document.baseURI).pathname;
const TOKEN_KEY = 'cmms.token';

// Token disimpan di localStorage dan dicadangkan di cookie (path aplikasi): origin localhost dipakai bersama aplikasi lain,
// sehingga localStorage bisa terhapus oleh aplikasi tetangga — cookie bertindak sebagai cadangan.
const COOKIE = 'cmms_token';
const cookiePath = new URL('./', document.baseURI).pathname;
const readCookie = () => document.cookie.split('; ').find((c) => c.startsWith(COOKIE + '='))?.slice(COOKIE.length + 1) || null;
export const session = {
  get token() {
    let t = null;
    try { t = localStorage.getItem(TOKEN_KEY); } catch { /* storage diblokir */ }
    if (!t) {
      t = readCookie();
      if (t) { try { localStorage.setItem(TOKEN_KEY, t); } catch { /* abaikan */ } }
    }
    return t;
  },
  set token(v) {
    try { v ? localStorage.setItem(TOKEN_KEY, v) : localStorage.removeItem(TOKEN_KEY); } catch { /* storage diblokir */ }
    document.cookie = v ? `${COOKIE}=${v}; path=${cookiePath}; max-age=${30 * 86400}; samesite=lax` : `${COOKIE}=; path=${cookiePath}; max-age=0`;
  },
};

export class ApiError extends Error {
  constructor(message, status) { super(message); this.status = status; }
}

let onUnauthorized = () => {};
export const setUnauthorizedHandler = (fn) => { onUnauthorized = fn; };

async function request(method, route, opts = {}, retried = false) {
  const { query, body } = opts;
  const qs = new URLSearchParams({ r: route, ...Object.fromEntries(Object.entries(query || {}).filter(([, v]) => v !== '' && v != null)) });
  const headers = { 'Content-Type': 'application/json' };
  if (session.token) headers['X-Auth-Token'] = session.token;
  let res;
  try {
    res = await fetch(`${BASE}?${qs}`, { method, headers, body: body ? JSON.stringify(body) : undefined });
  } catch {
    throw new ApiError('Tidak bisa terhubung ke server.', 0);
  }
  const data = await res.json().catch(() => ({ ok: false, error: 'Respons server tidak valid.' }));
  if (!data.ok) {
    if (res.status === 401 && route !== 'auth/login') {
      // jangan langsung keluar: pastikan token benar-benar tidak valid (bukan kegagalan sesaat) sebelum logout
      if (!retried && session.token) {
        const probe = await fetch(`${BASE}?r=auth/me`, { headers: { 'X-Auth-Token': session.token } }).catch(() => null);
        if (probe?.ok) return request(method, route, opts, true);
      }
      onUnauthorized();
    }
    throw new ApiError(data.error || 'Terjadi kesalahan.', res.status);
  }
  return data;
}

/** Ambil file biner (foto/lampiran) dengan token → Blob. Tag <img> tidak bisa mengirim header, jadi lewat fetch. */
export async function fetchBlob(route, query) {
  const qs = new URLSearchParams({ r: route, ...query });
  const res = await fetch(`${BASE}?${qs}`, { headers: { 'X-Auth-Token': session.token || '' } });
  if (!res.ok) throw new ApiError((await res.json().catch(() => ({}))).error || 'Gagal mengambil file.', res.status);
  return res.blob();
}

export const api = {
  get: (route, query) => request('GET', route, { query }),
  post: (route, body) => request('POST', route, { body: body || {} }),
};
