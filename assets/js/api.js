/* REST client with nonce refresh, timeouts and online detection. */

const CFG = window.ELKA_POS;
let nonce = CFG.nonce;

export class ApiError extends Error {
  constructor(message, { status = 0, code = '', network = false } = {}) {
    super(message);
    this.status = status;
    this.code = code;
    this.network = network; // true = no answer from the server (offline, timeout)
  }
}

export async function refreshNonce() {
  try {
    const res = await fetch(`${CFG.ajax}?action=elka_pos_nonce`, { credentials: 'same-origin', cache: 'no-store' });
    const data = await res.json();
    if (data && data.success) nonce = data.data.nonce;
    return !!(data && data.success);
  } catch (e) {
    return false;
  }
}

export async function api(path, { method = 'GET', body = null, query = null, timeout = 20000, retry = true } = {}) {
  let url = CFG.rest + path.replace(/^\//, '');
  if (query) {
    const qs = new URLSearchParams();
    Object.entries(query).forEach(([k, v]) => { if (v !== undefined && v !== null && v !== '') qs.set(k, v); });
    const s = qs.toString();
    if (s) url += (url.includes('?') ? '&' : '?') + s;
  }
  const ctrl = new AbortController();
  const t = setTimeout(() => ctrl.abort(), timeout);
  let res;
  try {
    res = await fetch(url, {
      method,
      credentials: 'same-origin',
      cache: 'no-store',
      headers: { 'X-WP-Nonce': nonce, ...(body ? { 'Content-Type': 'application/json' } : {}) },
      body: body ? JSON.stringify(body) : undefined,
      signal: ctrl.signal,
    });
  } catch (e) {
    clearTimeout(t);
    throw new ApiError(e.name === 'AbortError' ? 'Server neodpovídá.' : 'Bez připojení k internetu.', { network: true });
  }
  clearTimeout(t);

  let data = null;
  try { data = await res.json(); } catch (e) { data = null; }

  if (!res.ok) {
    const code = data && data.code ? data.code : '';
    if (retry && res.status === 403 && (code === 'rest_cookie_invalid_nonce' || code === 'rest_forbidden')) {
      if (await refreshNonce()) return api(path, { method, body, query, timeout, retry: false });
    }
    if (res.status >= 500 || res.status === 0) {
      throw new ApiError((data && data.message) || `Chyba serveru (${res.status}).`, { status: res.status, code, network: res.status >= 502 });
    }
    if (res.status === 401 || (res.status === 403 && code === 'rest_forbidden')) {
      throw new ApiError('Přihlášení vypršelo – obnovte stránku a přihlaste se.', { status: res.status, code });
    }
    throw new ApiError((data && data.message) ? stripTags(data.message) : `Chyba (${res.status}).`, { status: res.status, code });
  }
  if (data && data.nonce) nonce = data.nonce;
  return data;
}

const stripTags = (s) => String(s).replace(/<[^>]*>/g, '');

export const isOnline = () => navigator.onLine;
