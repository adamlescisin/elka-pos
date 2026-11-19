/* Small UI helpers: escaping, formatting, icons, modals, toasts. */

export const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

const czk0 = new Intl.NumberFormat('cs-CZ', { style: 'currency', currency: 'CZK', minimumFractionDigits: 0, maximumFractionDigits: 0 });
const czk2 = new Intl.NumberFormat('cs-CZ', { style: 'currency', currency: 'CZK', minimumFractionDigits: 2, maximumFractionDigits: 2 });
export const money = (v) => {
  const n = Math.round((Number(v) || 0) * 100) / 100;
  return (Number.isInteger(n) ? czk0 : czk2).format(n).replace(/ /g, ' ');
};
export const num = (v, d = 0) => new Intl.NumberFormat('cs-CZ', { minimumFractionDigits: d, maximumFractionDigits: d }).format(Number(v) || 0);
export const round2 = (v) => Math.round((Number(v) + Number.EPSILON) * 100) / 100;

export const fmtDate = (iso, withTime = true) => {
  if (!iso) return '';
  const d = new Date(iso);
  if (isNaN(d)) return '';
  const date = `${d.getDate()}. ${d.getMonth() + 1}. ${d.getFullYear()}`;
  return withTime ? `${date} ${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}` : date;
};
export const fmtTime = (d) => `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;

export const norm = (s) => String(s ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
export const initials = (name) => norm(name).split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0].toUpperCase()).join('') || '•';
export const uuid = () => (crypto.randomUUID ? crypto.randomUUID() : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
  const r = (Math.random() * 16) | 0; return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
}));
export const debounce = (fn, ms) => { let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); }; };

/* Icons – 1.4 px line, as in the identity sheet. */
const P = {
  search: '<circle cx="11" cy="11" r="7"/><path d="M20 20l-4-4"/>',
  close: '<path d="M6 6l12 12M18 6L6 18"/>',
  user: '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
  userPlus: '<circle cx="10" cy="8" r="4"/><path d="M3 21a7 7 0 0 1 11-5.7"/><path d="M18 14v6M15 17h6"/>',
  bag: '<path d="M5 8h14l-1 12H6L5 8z"/><path d="M9 8a3 3 0 0 1 6 0"/>',
  tag: '<path d="M3 12V4h8l10 10-8 8L3 12z"/><circle cx="7.5" cy="8.5" r="1.5"/>',
  percent: '<path d="M19 5L5 19"/><circle cx="7" cy="7" r="2.5"/><circle cx="17" cy="17" r="2.5"/>',
  ticket: '<path d="M3 8h18v3a2 2 0 0 0 0 4v3H3v-3a2 2 0 0 0 0-4z"/><path d="M9 8v10" stroke-dasharray="1.5 1.5"/>',
  plus: '<path d="M12 5v14M5 12h14"/>',
  edit: '<path d="M4 20h4L19 9l-4-4L4 16v4z"/>',
  note: '<path d="M5 4h14v16H5z"/><path d="M8 9h8M8 13h8M8 17h5"/>',
  trash: '<path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/>',
  receipt: '<path d="M6 3h12v18l-3-2-3 2-3-2-3 2V3z"/><path d="M9 8h6M9 12h6"/>',
  sync: '<path d="M20 11a8 8 0 0 0-14.9-3M4 13a8 8 0 0 0 14.9 3"/><path d="M4 4v4h4M20 20v-4h-4"/>',
  menu: '<path d="M4 7h16M4 12h16M4 17h16"/>',
  cash: '<rect x="3" y="6" width="18" height="12" rx="1"/><circle cx="12" cy="12" r="2.5"/><path d="M6 9v.01M18 15v.01"/>',
  card: '<rect x="3" y="5" width="18" height="14" rx="1.5"/><path d="M3 10h18M7 15h4"/>',
  qr: '<path d="M4 4h6v6H4zM14 4h6v6h-6zM4 14h6v6H4z"/><path d="M14 14h2v2h-2zM18 14h2M14 18h2M18 18h2v2"/>',
  print: '<path d="M7 9V4h10v5"/><rect x="4" y="9" width="16" height="8" rx="1"/><path d="M7 14h10v6H7z"/>',
  mail: '<rect x="3" y="5" width="18" height="14" rx="1"/><path d="M3 7l9 6 9-6"/>',
  back: '<path d="M4 9h11a5 5 0 0 1 0 10H8"/><path d="M8 5L4 9l4 4"/>',
  check: '<path d="M5 12l5 5 9-10"/>',
  wifiOff: '<path d="M3 3l18 18M8.5 16.5a5 5 0 0 1 7 0M5 12.5a10 10 0 0 1 4-2.4M14.5 10.2A10 10 0 0 1 19 12.5M12 20h.01"/>',
  list: '<path d="M8 6h13M8 12h13M8 18h13M4 6h.01M4 12h.01M4 18h.01"/>',
  expand: '<path d="M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5"/>',
  logout: '<path d="M15 4h4v16h-4M10 8l-4 4 4 4M6 12h10"/>',
  settings: '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M4.9 4.9L7 7M17 17l2.1 2.1M4.9 19.1L7 17M17 7l2.1-2.1"/>',
  store: '<path d="M4 10l1.5-5h13L20 10"/><path d="M5 10v10h14V10"/><path d="M10 20v-5h4v5"/>',
  heart: '<path d="M12 20s-7-4.4-7-10a4 4 0 0 1 7-2.6A4 4 0 0 1 19 10c0 5.6-7 10-7 10z"/>',
  minus: '<path d="M5 12h14"/>',
};
export const icon = (name, size = 22, sw = 1.4) =>
  `<svg viewBox="0 0 24 24" width="${size}" height="${size}" fill="none" stroke="currentColor" stroke-width="${sw}" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${P[name] || ''}</svg>`;

export const stamp = (size = 34) => `<svg viewBox="0 0 120 120" width="${size}" height="${size}" aria-hidden="true"><circle cx="60" cy="60" r="52" fill="none" stroke="currentColor" stroke-width="10" pathLength="120" stroke-dasharray="0.45 1.05"></circle><text x="60" y="73" text-anchor="middle" font-family="Playfair Display, Georgia, serif" font-size="36" fill="currentColor">LK</text></svg>`;

/* Honey "heart coin" = points, never anything else (ELKA Klub identity). */
export const coin = (size = 24) => `<svg viewBox="0 0 48 48" width="${size}" height="${size}" aria-hidden="true"><circle cx="24" cy="24" r="21" fill="#E2BE6A"></circle><circle cx="24" cy="24" r="16.5" fill="none" stroke="#3B3E44" stroke-width="1.2" stroke-dasharray="0.1 2.6" stroke-linecap="round"></circle><path d="M24 31s-7-4.2-7-9.3a3.5 3.5 0 0 1 7-2.4 3.5 3.5 0 0 1 7 2.4c0 5.1-7 9.3-7 9.3z" fill="#3B3E44"></path></svg>`;

export const klubLogo = () => '<span class="klub-logo"><b>ELKA</b><i>Klub</i></span>';

/* Toasts */
export function toast(msg, type = '', ms = 3200) {
  const root = document.getElementById('toast-root');
  const el = document.createElement('div');
  el.className = 'toast ' + type;
  el.textContent = msg;
  root.appendChild(el);
  setTimeout(() => { el.style.opacity = '0'; el.style.transition = 'opacity .3s'; setTimeout(() => el.remove(), 320); }, ms);
}

/* Modals – a stack; Esc closes the top one. */
const stack = [];
export function openModal({ title = '', sub = '', body = '', foot = '', size = '', onClose = null, className = '' }) {
  const root = document.getElementById('modal-root');
  const overlay = document.createElement('div');
  overlay.className = 'overlay';
  overlay.innerHTML = `<div class="modal ${size} ${className}" role="dialog" aria-modal="true" aria-label="${esc(title)}">
    ${title ? `<div class="modal-head"><h2>${esc(title)}</h2>${sub ? `<span class="sub">${sub}</span>` : ''}<button class="icon-btn close" data-close aria-label="Zavřít">${icon('close')}</button></div>` : ''}
    <div class="modal-body">${body}</div>
    <div class="modal-foot" ${foot ? '' : 'hidden'}>${foot}</div>
  </div>`;
  const modal = overlay.firstElementChild;
  const api = {
    el: modal,
    overlay,
    close: () => {
      const i = stack.indexOf(api);
      if (i >= 0) stack.splice(i, 1);
      overlay.remove();
      if (onClose) onClose();
    },
    body: (html) => { modal.querySelector('.modal-body').innerHTML = html; },
    foot: (html) => { const f = modal.querySelector('.modal-foot'); f.innerHTML = html; f.hidden = !html; },
    $: (sel) => modal.querySelector(sel),
    $$: (sel) => [...modal.querySelectorAll(sel)],
  };
  overlay.addEventListener('mousedown', (e) => { if (e.target === overlay) api.close(); });
  modal.addEventListener('click', (e) => { if (e.target.closest('[data-close]')) api.close(); });
  root.appendChild(overlay);
  stack.push(api);
  setTimeout(() => {
    const f = modal.querySelector('[autofocus]') || modal.querySelector('input:not([type=checkbox]):not([type=hidden]), select, textarea');
    if (f) f.focus();
  }, 30);
  return api;
}
export const topModal = () => stack[stack.length - 1] || null;
export const anyModal = () => stack.length > 0;
export const closeAllModals = () => { while (stack.length) stack[stack.length - 1].close(); };

export function confirmBox(text, { ok = 'Ano', cancel = 'Zpět', danger = false } = {}) {
  return new Promise((resolve) => {
    let done = false;
    const m = openModal({
      size: 'sm',
      title: 'Potvrzení',
      body: `<p style="font-size:17px;margin:0">${text}</p>`,
      foot: `<button class="btn ghost" data-close>${esc(cancel)}</button><button class="btn ${danger ? 'rose' : ''}" data-ok autofocus>${esc(ok)}</button>`,
      onClose: () => { if (!done) resolve(false); },
    });
    m.$('[data-ok]').addEventListener('click', () => { done = true; m.close(); resolve(true); });
    setTimeout(() => m.$('[data-ok]').focus(), 40);
  });
}
