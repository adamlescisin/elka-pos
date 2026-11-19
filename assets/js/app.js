/* ELKA Pokladna – main app. */

import { api, refreshNonce } from './api.js';
import * as db from './db.js';
import * as sync from './sync.js';
import { totals, spayd } from './cart.js';
import { printReceipt } from './receipt.js';
import {
  esc, money, num, round2, fmtDate, fmtTime, norm, initials, uuid, debounce,
  icon, stamp, coin, klubLogo, toast, openModal, anyModal, topModal, confirmBox,
} from './ui.js';
import qrcode from '../vendor/qrcode.js';

const CFG = window.ELKA_POS;
const CART_KEY = 'elka-pos-cart-' + CFG.userId;
const MAX_TILES = 240;

const S = {
  config: null,
  klub: null,
  taxClasses: [],
  taxRates: {},
  cashier: null,
  categories: [],
  catById: new Map(),
  children: new Map(),
  products: [],
  byId: new Map(),
  bySku: new Map(),
  customers: [],
  filter: { cat: 0, q: '', inStock: false, sale: false },
  cart: emptyCart(),
  online: navigator.onLine,
  outbox: [],
  syncing: false,
  lastSync: null,
  eetQueue: null,
};

function emptyCart() {
  return { lines: [], customer: null, cartDiscount: null, coupons: [], note: '' };
}

const $ = (sel, root = document) => root.querySelector(sel);
const app = () => document.getElementById('app');

/* ===================================================================== */
/* Boot                                                                   */
/* ===================================================================== */

async function boot() {
  window.__ELKA_POS_STARTED = true;
  if ('serviceWorker' in navigator && CFG.sw) {
    // Remove service workers of an old till address (e.g. /pokladna/ = shop checkout).
    navigator.serviceWorker.getRegistrations().then((regs) => regs.forEach((r) => {
      if (r.scope !== CFG.base && r.active && /\/sw\.js/.test(r.active.scriptURL) && !r.active.scriptURL.startsWith(CFG.base)) r.unregister();
    })).catch(() => {});
    navigator.serviceWorker.register(CFG.sw, { scope: CFG.base }).catch(() => {});
  }
  db.persist();

  let boot = null;
  try {
    boot = await api('bootstrap', { timeout: 10000 });
    await db.meta.set('boot', boot);
  } catch (e) {
    boot = await db.meta.get('boot');
    if (!boot) {
      bootMessage('Pro první spuštění pokladny je potřeba připojení k internetu.', true);
      return;
    }
    S.online = false;
  }
  applyBoot(boot);

  const local = await sync.loadLocal();
  S.outbox = local.outbox;
  S.customers = local.customers;
  setProducts(local.products);
  S.lastSync = await db.meta.get('productsSyncedAt');

  if (!S.products.length && S.online) {
    bootMessage('Stahuji katalog…');
    try {
      await sync.syncProducts({ full: true, onProgress: (d, t) => bootProgress(d / t) });
      setProducts(await db.all('products'));
      S.lastSync = new Date().toISOString();
    } catch (e) {
      bootMessage('Katalog se nepodařilo stáhnout: ' + e.message, true);
      return;
    }
  }

  try {
    const saved = JSON.parse(localStorage.getItem(CART_KEY) || 'null');
    if (saved && Array.isArray(saved.lines)) S.cart = { ...emptyCart(), ...saved };
  } catch (e) { /* ignore */ }

  renderShell();
  renderAll();

  // Background work.
  if (S.online) {
    const custAt = await db.meta.get('customersSyncedAt');
    if (!custAt || Date.now() - new Date(custAt).getTime() > 6 * 3600e3) syncCustomersQuiet();
    else if (S.products.length) syncNow({ quiet: true });
  }
  setInterval(() => { if (S.online) syncNow({ quiet: true }); }, (S.config.syncMinutes || 5) * 60e3);
  setInterval(() => { if (S.online && S.outbox.length) flushOutbox(); }, 60e3);
  setInterval(() => refreshNonce(), 30 * 60e3);
  window.addEventListener('online', () => { setOnline(true); flushOutbox(); syncNow({ quiet: true }); });
  window.addEventListener('offline', () => setOnline(false));
  document.addEventListener('keydown', onKey);
  if (S.outbox.length && S.online) flushOutbox();
}

function bootMessage(msg, error = false) {
  const el = $('.boot-sub');
  if (el) {
    el.textContent = msg;
    el.style.color = error ? 'var(--ruze)' : '';
  }
  if (!$('.boot-progress') && !error) {
    const p = document.createElement('div');
    p.className = 'boot-progress';
    p.innerHTML = '<i></i>';
    $('.boot-screen').appendChild(p);
  }
}
function bootProgress(f) {
  const i = $('.boot-progress i');
  if (i) i.style.width = Math.round(f * 100) + '%';
}

function applyBoot(boot) {
  S.config = boot.config;
  CFG.config = boot.config;
  CFG.store = boot.config.store;
  S.klub = boot.klub;
  S.cashier = boot.cashier;
  S.taxClasses = boot.taxClasses || [];
  S.taxRates = {};
  S.taxClasses.forEach((t) => { S.taxRates[t.slug] = t.rate; });
  S.eetQueue = boot.eetQueue;
  S.categories = boot.categories || [];
  S.catById = new Map(S.categories.map((c) => [c.id, c]));
  S.children = new Map();
  S.categories.forEach((c) => {
    if (!S.children.has(c.parent)) S.children.set(c.parent, []);
    S.children.get(c.parent).push(c);
  });
  S.children.forEach((list) => list.sort((a, b) => (a.order - b.order) || a.name.localeCompare(b.name, 'cs')));
}

function setProducts(list) {
  S.products = list.slice().sort((a, b) => a.name.localeCompare(b.name, 'cs'));
  S.byId = new Map();
  S.bySku = new Map();
  S.products.forEach((p) => {
    S.byId.set(p.id, p);
    const skus = [p.sku];
    if (p.sku) S.bySku.set(norm(p.sku), { p, v: null });
    (p.variations || []).forEach((v) => {
      if (v.sku) { S.bySku.set(norm(v.sku), { p, v }); skus.push(v.sku); }
    });
    p._s = norm([p.name, p.brand, ...skus].join(' '));
  });
}

function setOnline(v) {
  S.online = v;
  renderHeader();
  if (!v) toast('Pokladna je offline – prodeje se uloží a odešlou později.', 'err', 4500);
  else toast('Připojení obnoveno.', 'ok');
}

function saveCart() {
  try { localStorage.setItem(CART_KEY, JSON.stringify(S.cart)); } catch (e) { /* ignore */ }
}

/* ===================================================================== */
/* Sync                                                                   */
/* ===================================================================== */

async function syncNow({ quiet = false, full = false } = {}) {
  if (S.syncing) return;
  S.syncing = true;
  renderHeader();
  try {
    try {
      const boot = await api('bootstrap', { timeout: 10000 });
      await db.meta.set('boot', boot);
      applyBoot(boot);
      renderRail();
    } catch (e) { if (e.network) throw e; }
    const res = await sync.syncProducts({ full });
    if (res.changed || res.deleted.length || full) {
      setProducts(await db.all('products'));
      renderMain();
    }
    S.lastSync = new Date().toISOString();
    if (S.outbox.length) await flushOutbox();
    if (!quiet) toast(res.changed ? `Katalog aktualizován (${res.changed} změn).` : 'Katalog je aktuální.', 'ok');
    if (!S.online) setOnline(true);
  } catch (e) {
    if (e.network && S.online) { S.online = false; }
    if (!quiet) toast('Synchronizace selhala: ' + e.message, 'err');
  } finally {
    S.syncing = false;
    renderHeader();
  }
}

async function syncCustomersQuiet() {
  try {
    S.customers = await sync.syncCustomers();
  } catch (e) { /* later */ }
}

async function flushOutbox() {
  if (!S.outbox.length) S.outbox = await sync.outbox();
  if (!S.outbox.length) return;
  const res = await sync.flushOutbox();
  S.outbox = await sync.outbox();
  renderHeader();
  const orders = res.sent.filter((s) => s.item.kind === 'order').length;
  if (orders) toast(`Odesláno ${orders} offline ${orders === 1 ? 'prodej' : orders < 5 ? 'prodeje' : 'prodejů'}.`, 'ok');
  const rejected = res.failed.filter((f) => !f.network);
  if (rejected.length) toast(`${rejected.length} záznam(y) ve frontě server odmítl – zkontrolujte v Prodejích.`, 'err', 6000);
}

/* ===================================================================== */
/* Rendering                                                              */
/* ===================================================================== */

function renderShell() {
  const root = app();
  root.className = 'pos';
  root.innerHTML = `
    <header class="pos-header" id="hdr"></header>
    <nav class="pos-rail" id="rail" aria-label="Kategorie"></nav>
    <main class="pos-main" id="main"></main>
    <aside class="pos-cart" id="cart" aria-label="Košík"></aside>`;
  root.addEventListener('click', onClick);
  root.addEventListener('input', onInput);
  root.addEventListener('keydown', onFieldKey);
}

function renderAll() {
  renderHeader();
  renderRail();
  renderMain();
  renderCart();
}

function renderHeader() {
  const hdr = $('#hdr');
  if (!hdr) return;
  const pending = S.outbox.length;
  const errors = S.outbox.filter((o) => o.lastError).length;
  let chip;
  if (!S.online) chip = `<button class="chip offline" data-act="orders">${icon('wifiOff', 18)} Offline${pending ? ` · ${pending} čeká` : ''}</button>`;
  else if (pending) chip = `<button class="chip warn ${S.syncing ? 'spin' : ''}" data-act="flush"><span class="dot"></span>${pending} čeká na odeslání${errors ? ' · chyba' : ''}</button>`;
  else chip = `<button class="chip ${S.syncing ? 'spin' : ''}" data-act="sync" title="Poslední synchronizace ${S.lastSync ? fmtDate(S.lastSync) : '—'}"><span class="dot"></span>${S.syncing ? 'Synchronizuji…' : 'Online'}</button>`;
  const searchFocused = document.activeElement && document.activeElement.id === 'q';
  const eet = S.config.eet && S.config.eet.enabled && S.eetQueue && (S.eetQueue.pending || S.eetQueue.error)
    ? `<span class="chip warn" title="Záznamy EET čekající na odeslání">EET ${S.eetQueue.pending + S.eetQueue.error}</span>` : '';
  if (!hdr.dataset.ready) {
    hdr.innerHTML = `
      <div class="brand"><div class="brand-stamp">${stamp(34)}</div><div class="brand-name"><b>${esc((S.config.store.name || 'ELKA FASHION').toUpperCase())}</b><span>POKLADNA</span></div></div>
      <label class="search"><span class="ico">${icon('search', 22)}</span><span class="sr-only">Hledat</span>
        <input id="q" type="search" autocomplete="off" spellcheck="false" placeholder="Hledat produkt, kód, značku… nebo naskenujte" value="${esc(S.filter.q)}">
        <span id="qx"></span></label>
      <div class="header-right"><span id="hchips"></span>
        <span class="cashier">${esc(S.cashier ? S.cashier.name : '')}</span>
        <button class="icon-btn" data-act="orders" title="Prodeje a vratky (F6)" aria-label="Prodeje">${icon('receipt')}</button>
        <button class="icon-btn" data-act="menu" title="Nabídka" aria-label="Nabídka">${icon('menu')}</button>
      </div>`;
    hdr.dataset.ready = '1';
  }
  $('#hchips').innerHTML = eet + ' ' + chip;
  $('#qx').innerHTML = S.filter.q ? `<button class="clear" data-act="clear-q" aria-label="Vymazat">${icon('close', 18)}</button>` : '<kbd>F2</kbd>';
  if (searchFocused) $('#q').focus();
}

function catCount(id) {
  const ids = descendants(id);
  return S.products.filter((p) => p.cats.some((c) => ids.has(c))).length;
}
function descendants(id) {
  const out = new Set([id]);
  const walk = (pid) => (S.children.get(pid) || []).forEach((c) => { out.add(c.id); walk(c.id); });
  walk(id);
  return out;
}

function renderRail() {
  const rail = $('#rail');
  if (!rail) return;
  const top = S.children.get(0) || [];
  const active = S.filter.cat;
  const activeRoot = active ? rootOf(active) : 0;
  let html = `<div class="rail-title">KATEGORIE</div>
    <button class="cat all ${!active ? 'active' : ''}" data-act="cat" data-id="0">Vše <span class="count">${S.products.length}</span></button>`;
  top.forEach((c) => {
    html += `<button class="cat ${active === c.id ? 'active' : ''}" data-act="cat" data-id="${c.id}">${esc(c.name)} <span class="count">${catCount(c.id)}</span></button>`;
    if (activeRoot === c.id) {
      (S.children.get(c.id) || []).forEach((sc) => {
        html += `<button class="cat sub ${active === sc.id ? 'active' : ''}" data-act="cat" data-id="${sc.id}">${esc(sc.name)} <span class="count">${catCount(sc.id)}</span></button>`;
      });
    }
  });
  rail.innerHTML = html;
}
function rootOf(id) {
  let c = S.catById.get(id);
  while (c && c.parent && S.catById.get(c.parent)) c = S.catById.get(c.parent);
  return c ? c.id : 0;
}

function filtered() {
  const q = norm(S.filter.q).trim();
  const words = q ? q.split(/\s+/) : [];
  const cats = S.filter.cat ? descendants(S.filter.cat) : null;
  return S.products.filter((p) => {
    if (cats && !p.cats.some((c) => cats.has(c))) return false;
    if (S.filter.inStock && !(p.stock === null ? p.inStock : p.stock > 0)) return false;
    if (S.filter.sale && !isSale(p)) return false;
    return words.every((w) => p._s.includes(w));
  });
}
const isSale = (p) => (p.variations && p.variations.length ? p.variations.some((v) => v.regular && v.price < v.regular) : p.regular && p.price < p.regular);

function tileHtml(p) {
  const sale = isSale(p);
  const isVar = p.type === 'variable';
  const stock = p.stock;
  const oos = stock !== null ? stock <= 0 : !p.inStock;
  const price = isVar && p.priceMax && p.priceMax !== p.price ? `od ${money(p.price)}` : money(p.price);
  const regular = !isVar && sale ? `<s>${money(p.regular)}</s>` : '';
  const pct = !isVar && sale && p.regular ? Math.round((1 - p.price / p.regular) * 100) : (isVar && sale ? saleMaxPct(p) : 0);
  const sizes = isVar ? (p.variations || []).length : 0;
  return `<button class="tile ${oos ? 'oos' : ''}" data-act="product" data-id="${p.id}">
    <div class="ph">${p.image ? `<img src="${esc(p.image)}" alt="" loading="lazy" decoding="async">` : `<span class="initials">${esc(initials(p.name))}</span>`}
      ${pct ? `<span class="badge tl sale">−${pct} %</span>` : ''}
      ${oos ? '<span class="badge tr out">VYPRODÁNO</span>' : stock !== null ? `<span class="badge tr stock">${num(stock)} ks</span>` : ''}
    </div>
    <div class="brandline">${esc(p.brand || '')}${isVar && sizes ? `${p.brand ? ' · ' : ''}${sizes} variant` : ''}</div>
    <div class="name">${esc(p.name)}</div>
    <div class="price ${sale ? 'sale' : ''}"><span>${price}</span>${regular}</div>
  </button>`;
}
function saleMaxPct(p) {
  let m = 0;
  (p.variations || []).forEach((v) => { if (v.regular && v.price < v.regular) m = Math.max(m, Math.round((1 - v.price / v.regular) * 100)); });
  return m;
}

function renderMain() {
  const main = $('#main');
  if (!main) return;
  const list = filtered();
  const cat = S.filter.cat ? S.catById.get(S.filter.cat) : null;
  const subs = cat ? (S.children.get(cat.id) || []) : [];
  const title = S.filter.q ? 'Výsledky hledání' : (cat ? cat.name : 'Všechny produkty');
  const shown = list.slice(0, MAX_TILES);
  main.innerHTML = `
    <div class="main-head">
      <h1>${esc(title)}</h1><span class="meta">${num(list.length)} ${list.length === 1 ? 'produkt' : list.length < 5 && list.length > 0 ? 'produkty' : 'produktů'}</span>
      <div class="filters">
        <button class="toggle ${S.filter.inStock ? 'on' : ''}" data-act="f-stock">Jen skladem</button>
        <button class="toggle ${S.filter.sale ? 'on' : ''}" data-act="f-sale">Ve slevě</button>
        <button class="toggle" data-act="custom">${'+ Ruční položka'}</button>
      </div>
    </div>
    ${subs.length ? `<div class="subcats">${subs.map((s) => `<button class="toggle" data-act="cat" data-id="${s.id}">${esc(s.name)}</button>`).join('')}</div>` : ''}
    <div class="grid" id="grid">
      ${shown.length ? shown.map(tileHtml).join('') : `<div class="empty"><div class="script">Nic nenalezeno</div>Zkuste jiný výraz, nebo přidejte <button class="link" data-act="custom">ruční položku</button>.</div>`}
      ${list.length > MAX_TILES ? `<div class="more-note">Zobrazeno ${MAX_TILES} z ${num(list.length)} – upřesněte hledání.</div>` : ''}
    </div>`;
}

/* --------------------------------------------------------------------- */

function cartTotals(method = null) {
  return totals(S.cart, {
    taxRates: S.taxRates,
    cashRounding: S.config.cashRounding,
    method,
    decimals: S.config.decimals ?? 2,
    amountPerPoint: S.klub ? S.klub.amountPerPoint : 0,
  });
}

function customerHtml() {
  const c = S.cart.customer;
  if (!c) {
    return `<div class="cust"><div class="cust-empty">
      <button class="btn soft block" data-act="customer">${icon('user', 20)} Přiřadit zákaznici <kbd>F4</kbd></button>
      <button class="btn soft" data-act="new-customer" title="Nová zákaznice / do ELKA Klubu">${icon('userPlus', 20)}</button>
    </div></div>`;
  }
  const k = c.klub;
  let klub = '';
  if (k) {
    const th = S.klub ? S.klub.threshold : 0;
    const pct = th ? Math.min(100, Math.round((k.balance % th) / th * 100)) : 0;
    klub = `<div class="klub-row">${klubLogo()}
      <span class="points">${coin(24)} ${num(k.balance)} bodů</span>
      ${th ? `<span class="progress" title="Do dalšího voucheru zbývá ${num(Math.max(0, th - (k.balance % th)))} bodů"><i style="width:${pct}%"></i></span>` : ''}
      ${k.card ? `<span class="tag">${esc(k.card)}</span>` : ''}${k.pending ? '<span class="tag warn">čeká na odeslání</span>' : ''}
    </div>
    ${(k.vouchers || []).map((v) => {
      const used = S.cart.coupons.some((x) => x.code.toUpperCase() === v.code.toUpperCase());
      return `<div class="voucher"><span>Voucher <b>${esc(v.label)}</b> · ${esc(String(v.code).toUpperCase())}${v.expires ? ` · do ${fmtDate(v.expires, false)}` : ''}</span>
        ${used ? '<span class="tag ok">uplatněn</span>' : `<button class="btn sm rose" data-act="use-voucher" data-code="${esc(v.code)}">Uplatnit</button>`}</div>`;
    }).join('')}`;
  } else if (S.klub && c.email) {
    klub = `<div class="klub-row">${klubLogo()}<span class="cust-sub">Zatím není členkou.</span><button class="btn sm" data-act="join-klub">Přidat do Klubu</button></div>`;
  }
  return `<div class="cust"><div class="cust-card">
      <div class="avatar">${esc(initials(c.name || c.email))}</div>
      <div><div class="cust-name">${esc(c.name || c.email)}</div><div class="cust-sub">${esc([c.email, c.phone].filter(Boolean).join(' · ')) || 'bez kontaktu'}</div></div>
      <div style="display:flex;gap:6px"><button class="icon-btn" data-act="customer" title="Změnit zákaznici" aria-label="Změnit zákaznici">${icon('edit', 20)}</button><button class="icon-btn" data-act="remove-customer" title="Odebrat zákaznici" aria-label="Odebrat zákaznici">${icon('close', 20)}</button></div>
    </div>${klub}</div>`;
}

function lineHtml(l, priced, idx) {
  const p = l.productId ? S.byId.get(l.productId) : null;
  const stock = l.variationId ? (p && (p.variations || []).find((v) => v.id === l.variationId) || {}).stock : (p ? p.stock : null);
  const warn = stock !== null && stock !== undefined && stock < l.qty && l.type !== 'custom';
  const disc = priced.lineDiscount + priced.cartDiscount;
  const discLabel = l.discount && l.discount.value ? (l.discount.type === 'percent' ? `−${num(l.discount.value, l.discount.value % 1 ? 1 : 0)} %` : `−${money(l.discount.value)}`) : '';
  return `<div class="line ${warn ? 'warn' : ''}">
    <div class="thumb ${l.type === 'custom' ? 'custom' : ''}">${l.image ? `<img src="${esc(l.image)}" alt="">` : (l.type === 'custom' ? icon('plus', 22) : esc(initials(l.name)))}</div>
    <div class="info" data-act="edit-line" data-idx="${idx}">
      <div class="title">${esc(l.name)}</div>
      ${l.variant ? `<div class="variant">${esc(l.variant)}</div>` : ''}
      <div class="unit">${money(l.unitPrice)} / ks${l.regular && l.regular > l.unitPrice ? ` <s>${money(l.regular)}</s>` : ''}</div>
      ${discLabel ? `<span class="disc">${esc(discLabel)}</span>` : ''}
    </div>
    <div class="right">
      <div class="total">${disc > 0.004 ? `<s>${money(priced.gross)}</s>` : ''}${money(priced.final)}</div>
      <div class="qty"><button data-act="qty-" data-idx="${idx}" aria-label="Méně">−</button><span>${l.qty}</span><button data-act="qty+" data-idx="${idx}" aria-label="Více">+</button></div>
    </div>
  </div>`;
}

function renderCart() {
  const el = $('#cart');
  if (!el) return;
  const t = cartTotals();
  const lines = S.cart.lines;
  const cd = S.cart.cartDiscount;
  el.innerHTML = `
    ${customerHtml()}
    ${lines.length ? `<div class="lines">${lines.map((l, i) => lineHtml(l, t.lines[i], i)).join('')}</div>`
      : `<div class="cart-empty"><div class="script">Vítejte</div><div class="serif">PRÁZDNÝ KOŠÍK</div><div>Vyberte produkt vlevo, naskenujte kód<br>nebo přidejte ruční položku.</div></div>`}
    <div class="cart-tools">
      <button class="tool" data-act="custom">${icon('plus')}Ruční položka</button>
      <button class="tool ${cd ? 'on' : ''}" data-act="cart-discount">${icon('percent')}Sleva</button>
      <button class="tool ${S.cart.coupons.length ? 'on' : ''}" data-act="coupon">${icon('ticket')}Kupón</button>
      <button class="tool ${S.cart.note ? 'on' : ''}" data-act="note">${icon('note')}Poznámka</button>
    </div>
    <div class="totals">
      ${lines.length ? `<div class="trow muted"><span>Položek ${num(t.items)} · cena v regálu</span><span>${money(t.shelf)}</span></div>` : ''}
      ${t.lineDisc > 0 ? `<div class="trow disc"><span>Slevy na položky</span><span>−${money(t.lineDisc)}</span></div>` : ''}
      ${cd ? `<div class="trow disc"><span>Sleva na nákup ${cd.type === 'percent' ? num(cd.value, cd.value % 1 ? 1 : 0) + ' %' : ''}${cd.reason ? ' · ' + esc(cd.reason) : ''}</span><span>−${money(t.cartDisc)} <button class="x" data-act="rm-cart-discount" aria-label="Zrušit slevu">×</button></span></div>` : ''}
      ${S.cart.coupons.map((c) => `<div class="trow disc"><span>Kupón ${esc(c.code)}</span><span>−${money(c.preview)} <button class="x" data-act="rm-coupon" data-code="${esc(c.code)}" aria-label="Odebrat kupón">×</button></span></div>`).join('')}
      <div class="trow grand"><span>Celkem</span><b>${money(t.total)}</b></div>
      ${S.config.store.vatPayer && t.vat.length && lines.length ? `<div class="trow muted"><span>z toho DPH</span><span>${t.vat.filter((v) => v.rate).map((v) => `${num(v.rate)} % ${money(v.tax)}`).join(' · ') || money(0)}</span></div>` : ''}
      ${S.cart.customer && S.cart.customer.klub && t.points ? `<div class="earn">${coin(18)} Za nákup získá přibližně ${num(t.points)} bodů</div>` : ''}
    </div>
    <button class="pay-btn" data-act="pay" ${lines.length ? '' : 'disabled'}><span>ZAPLATIT <kbd style="background:transparent;color:#C9C2BA;border-color:#6c6f75">F8</kbd></span><b>${money(t.total)}</b></button>`;
  saveCart();
}

/* ===================================================================== */
/* Events                                                                 */
/* ===================================================================== */

function onClick(e) {
  const el = e.target.closest('[data-act]');
  if (!el) return;
  const act = el.dataset.act;
  const idx = el.dataset.idx !== undefined ? Number(el.dataset.idx) : -1;
  switch (act) {
    case 'cat':
      S.filter.cat = Number(el.dataset.id);
      renderRail(); renderMain();
      break;
    case 'f-stock': S.filter.inStock = !S.filter.inStock; renderMain(); break;
    case 'f-sale': S.filter.sale = !S.filter.sale; renderMain(); break;
    case 'clear-q': S.filter.q = ''; $('#q').value = ''; renderHeader(); renderMain(); $('#q').focus(); break;
    case 'product': onProduct(Number(el.dataset.id)); break;
    case 'qty+': changeQty(idx, 1); break;
    case 'qty-': changeQty(idx, -1); break;
    case 'edit-line': openLineEditor(idx); break;
    case 'custom': openCustomItem(); break;
    case 'cart-discount': openCartDiscount(); break;
    case 'rm-cart-discount': S.cart.cartDiscount = null; cartChanged(); break;
    case 'coupon': openCoupon(); break;
    case 'rm-coupon': S.cart.coupons = S.cart.coupons.filter((c) => c.code !== el.dataset.code); renderCart(); break;
    case 'use-voucher': applyCoupon(el.dataset.code); break;
    case 'note': openNote(); break;
    case 'customer': openCustomer(); break;
    case 'new-customer': openCustomer({ create: true }); break;
    case 'remove-customer': setCustomer(null); break;
    case 'join-klub': joinKlub(); break;
    case 'pay': openPayment(); break;
    case 'orders': openOrders(); break;
    case 'menu': openMenu(); break;
    case 'sync': syncNow(); break;
    case 'flush': flushOutbox(); break;
    default: break;
  }
}

const searchChanged = debounce(() => renderMain(), 120);
function onInput(e) {
  if (e.target.id === 'q') {
    S.filter.q = e.target.value;
    renderHeader();
    searchChanged();
  }
}

function onFieldKey(e) {
  if (e.target.id !== 'q') return;
  if (e.key === 'Enter') {
    e.preventDefault();
    const raw = e.target.value.trim();
    if (!raw) return;
    const hit = S.bySku.get(norm(raw));
    if (hit) {
      if (hit.v) addVariation(hit.p, hit.v); else onProduct(hit.p.id);
      clearSearch();
      return;
    }
    // Club card scan.
    const card = S.customers.find((c) => c.klub && c.klub.card && norm(c.klub.card) === norm(raw));
    if (card) { setCustomer(card); clearSearch(); toast('Zákaznice: ' + card.name, 'ok'); return; }
    if (/^ELKA\d+$/i.test(raw) || /^29\d{11}$/.test(raw)) { openCustomer({ query: raw }); clearSearch(); return; }
    const list = filtered();
    if (list.length === 1) { onProduct(list[0].id); clearSearch(); }
  } else if (e.key === 'Escape') {
    clearSearch();
  }
}
function clearSearch() {
  S.filter.q = '';
  const q = $('#q');
  if (q) q.value = '';
  renderHeader();
  renderMain();
}

function onKey(e) {
  const typing = ['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement && document.activeElement.tagName);
  if (e.key === 'Escape' && anyModal()) { e.preventDefault(); topModal().close(); return; }
  if (anyModal()) {
    const m = topModal();
    if (m.onKey) m.onKey(e, typing);
    return;
  }
  if (e.key === 'F2' || (e.key === '/' && !typing)) { e.preventDefault(); $('#q').focus(); $('#q').select(); }
  else if (e.key === 'F4') { e.preventDefault(); openCustomer(); }
  else if (e.key === 'F6') { e.preventDefault(); openOrders(); }
  else if (e.key === 'F8') { e.preventDefault(); openPayment(); }
  else if (!typing && e.key.length === 1 && /[\p{L}\p{N}]/u.test(e.key) && !e.ctrlKey && !e.metaKey && !e.altKey) {
    // Typing anywhere goes to search (also catches barcode scanners).
    $('#q').focus();
  }
}

/* ===================================================================== */
/* Products & cart                                                        */
/* ===================================================================== */

function onProduct(id) {
  const p = S.byId.get(id);
  if (!p) return;
  if (p.type === 'variable' && p.variations && p.variations.length) {
    openVariationPicker(p);
  } else {
    addSimple(p);
  }
}

async function addSimple(p) {
  if (p.stock !== null && p.stock <= 0 && !(await confirmBox(`<b>${esc(p.name)}</b> podle skladu není k dispozici. Přesto prodat?`, { ok: 'Prodat' }))) return;
  addLine({
    type: 'product', productId: p.id, variationId: 0, name: p.name, variant: '', sku: p.sku,
    image: p.image, qty: 1, unitPrice: p.price ?? 0, regular: p.regular, taxClass: p.taxClass || '', attrs: null, discount: null,
  });
}

function variantLabel(p, v) {
  return (p.attributes || []).map((a) => {
    const val = v.attrs[a.key];
    const opt = a.options.find((o) => o.value === val);
    return opt ? opt.label : val;
  }).filter(Boolean).join(', ');
}

async function addVariation(p, v, chosen = null) {
  if (v.stock !== null && v.stock <= 0 && !(await confirmBox(`Varianta <b>${esc(variantLabel(p, v))}</b> podle skladu není k dispozici. Přesto prodat?`, { ok: 'Prodat' }))) return false;
  const attrs = { ...v.attrs };
  if (chosen) Object.keys(attrs).forEach((k) => { if (!attrs[k] && chosen[k]) attrs[k] = chosen[k]; });
  const label = (p.attributes || []).map((a) => {
    const opt = a.options.find((o) => o.value === attrs[a.key]);
    return opt ? opt.label : attrs[a.key];
  }).filter(Boolean).join(', ');
  addLine({
    type: 'product', productId: p.id, variationId: v.id, name: p.name, variant: label, sku: v.sku || p.sku,
    image: v.image || p.image, qty: 1, unitPrice: v.price ?? p.price ?? 0, regular: v.regular, taxClass: v.taxClass || p.taxClass || '', attrs, discount: null,
  });
  return true;
}

function addLine(line) {
  const same = S.cart.lines.find((l) => l.type === 'product' && l.productId === line.productId && l.variationId === line.variationId
    && !l.discount && l.unitPrice === line.unitPrice && JSON.stringify(l.attrs) === JSON.stringify(line.attrs));
  if (same) same.qty += line.qty;
  else S.cart.lines.push(line);
  cartChanged();
  toast(`Přidáno: ${line.name}${line.variant ? ' · ' + line.variant : ''}`, '', 1600);
}

function changeQty(idx, d) {
  const l = S.cart.lines[idx];
  if (!l) return;
  l.qty += d;
  if (l.qty <= 0) S.cart.lines.splice(idx, 1);
  cartChanged();
}

const revalidateCoupons = debounce(async () => {
  if (!S.cart.coupons.length || !S.online) return;
  const codes = S.cart.coupons.map((c) => c.code);
  for (const code of codes) {
    try {
      const res = await validateCoupon(code);
      const c = S.cart.coupons.find((x) => x.code === code);
      if (c) c.preview = res.preview;
    } catch (e) {
      if (e.network) return;
      S.cart.coupons = S.cart.coupons.filter((x) => x.code !== code);
      toast(`Kupón ${code} byl odebrán: ${e.message}`, 'err', 5000);
    }
  }
  renderCart();
}, 500);

function cartChanged() {
  if (!S.cart.lines.length) { S.cart.coupons = []; S.cart.cartDiscount = null; }
  renderCart();
  revalidateCoupons();
}

function linePayload(l) {
  return {
    type: l.type, productId: l.productId || 0, variationId: l.variationId || 0, name: l.name, qty: l.qty,
    unitPrice: l.unitPrice, discount: l.discount, taxClass: l.taxClass || '', attrs: l.attrs || null,
  };
}

/* Variation picker ---------------------------------------------------- */

function openVariationPicker(p) {
  const sizeLike = (a) => /velikost|size|vel/i.test(a.key + ' ' + a.name);
  const attrs = (p.attributes || []).slice().sort((a, b) => Number(sizeLike(a)) - Number(sizeLike(b)));
  const sel = {};
  attrs.forEach((a) => { if (a.options.length === 1) sel[a.key] = a.options[0].value; });

  const matches = (v, chosen) => attrs.every((a) => !chosen[a.key] || !v.attrs[a.key] || v.attrs[a.key] === chosen[a.key]);
  const exact = () => (attrs.every((a) => sel[a.key]) ? p.variations.find((v) => matches(v, sel)) : null);
  const stockFor = (a, value) => {
    const chosen = { ...sel, [a.key]: value };
    const vs = p.variations.filter((v) => matches(v, chosen));
    if (!vs.length) return { exists: false };
    if (vs.some((v) => v.stock === null)) return { exists: true, stock: null, inStock: vs.some((v) => v.inStock) };
    return { exists: true, stock: vs.reduce((s, v) => s + Math.max(0, v.stock), 0) };
  };

  const m = openModal({ title: '', size: 'wide', body: '' });
  const render = () => {
    const v = exact();
    const price = v ? v.price : p.price;
    const regular = v ? v.regular : null;
    m.body(`<div style="display:flex;justify-content:flex-end;margin:-6px -6px 0 0"><button class="icon-btn" data-close aria-label="Zavřít">${icon('close')}</button></div>
      <div class="vp">
        <div class="ph">${(v && v.image) || p.image ? `<img src="${esc((v && v.image) || p.image)}" alt="">` : `<span class="initials" style="font-family:var(--serif);font-size:48px;color:#C9B39C">${esc(initials(p.name))}</span>`}</div>
        <div>
          ${p.brand ? `<div class="brandline" style="font-size:12px;letter-spacing:.14em;color:var(--muted)">${esc(p.brand.toUpperCase())}</div>` : ''}
          <h3>${esc(p.name)}</h3>
          <div class="sku">${esc(v ? v.sku : p.sku)}</div>
          ${attrs.map((a, ai) => {
            const isLast = ai === attrs.length - 1;
            return `<div class="attr-title">${esc(a.name)}</div><div class="opts">${a.options.map((o) => {
              const st = stockFor(a, o.value);
              if (!st.exists) return '';
              const zero = st.stock === 0 || (st.stock === null && !st.inStock);
              const on = sel[a.key] === o.value;
              const text = o.label.length > 5;
              return `<button class="opt ${text ? 'text' : ''} ${on ? 'on' : ''} ${zero ? 'zero' : ''}" data-attr="${esc(a.key)}" data-val="${esc(o.value)}" data-last="${isLast ? 1 : 0}">
                <b>${esc(o.label)}</b>${text ? '' : `<small>${st.stock === null ? (st.inStock ? 'skladem' : '0 ks') : num(st.stock) + ' ks'}</small>`}</button>`;
            }).join('')}</div>`;
          }).join('')}
          <div class="vp-price">${money(price)}${regular && regular > price ? `<s>${money(regular)}</s>` : ''}</div>
          <div style="display:flex;gap:10px;margin-top:18px">
            <button class="btn lg" data-add ${v ? '' : 'disabled'}>${icon('bag', 20)} Do košíku</button>
            ${v ? `<span class="tag ${v.stock === 0 ? 'err' : 'ok'}" style="align-self:center">${v.stock === null ? 'skladem' : num(v.stock) + ' ks skladem'}</span>` : `<span style="align-self:center;color:var(--muted)">Vyberte ${esc(attrs.filter((a) => !sel[a.key]).map((a) => a.name.toLowerCase()).join(' a '))}</span>`}
          </div>
        </div>
      </div>`);
  };
  m.el.addEventListener('click', async (e) => {
    const o = e.target.closest('.opt');
    if (o) {
      sel[o.dataset.attr] = o.dataset.val;
      // Clicking the last attribute (usually size) with everything chosen adds right away.
      const v = exact();
      if (o.dataset.last === '1' && v) {
        if (await addVariation(p, v, sel)) m.close();
        return;
      }
      render();
      return;
    }
    if (e.target.closest('[data-add]')) {
      const v = exact();
      if (v && (await addVariation(p, v, sel))) m.close();
    }
  });
  m.onKey = (e) => {
    if (e.key === 'Enter') { const v = exact(); if (v) { e.preventDefault(); addVariation(p, v, sel).then((ok) => ok && m.close()); } }
  };
  render();
}

/* Line editor ---------------------------------------------------------- */

function openLineEditor(idx) {
  const l = S.cart.lines[idx];
  if (!l) return;
  let type = l.discount ? l.discount.type : 'percent';
  const m = openModal({
    title: 'Upravit položku',
    sub: esc(l.name + (l.variant ? ' · ' + l.variant : '')),
    body: `
      <div class="row">
        <div class="field"><label for="le-qty">Množství</label><input class="input" id="le-qty" type="number" min="1" step="1" value="${l.qty}"></div>
        <div class="field"><label for="le-price">Cena za kus (s DPH)</label><input class="input" id="le-price" type="number" min="0" step="0.01" value="${l.unitPrice}"></div>
      </div>
      <div class="field"><label>Sleva na položku</label>
        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
          <div class="seg" id="le-type"><button data-t="percent">%</button><button data-t="amount">Kč</button></div>
          <input class="input" id="le-disc" type="number" min="0" step="0.01" style="max-width:160px" value="${l.discount ? l.discount.value : ''}" placeholder="0">
          ${[10, 20, 30, 50].map((p) => `<button class="toggle" data-quick="${p}">${p} %</button>`).join('')}
        </div>
      </div>`,
    foot: `<button class="btn ghost" data-remove style="margin-right:auto;color:var(--ruze);border-color:var(--ruze)">${icon('trash', 18)} Odebrat</button><button class="btn ghost" data-close>Zrušit</button><button class="btn" data-save>Uložit</button>`,
  });
  const seg = () => m.$$('#le-type button').forEach((b) => b.classList.toggle('on', b.dataset.t === type));
  seg();
  m.el.addEventListener('click', (e) => {
    const t = e.target.closest('#le-type button');
    if (t) { type = t.dataset.t; seg(); return; }
    const q = e.target.closest('[data-quick]');
    if (q) { type = 'percent'; seg(); m.$('#le-disc').value = q.dataset.quick; return; }
    if (e.target.closest('[data-remove]')) { S.cart.lines.splice(idx, 1); m.close(); cartChanged(); return; }
    if (e.target.closest('[data-save]')) save();
  });
  const save = () => {
    const qty = Math.max(1, parseInt(m.$('#le-qty').value, 10) || 1);
    const price = Math.max(0, parseFloat(String(m.$('#le-price').value).replace(',', '.')) || 0);
    const dv = parseFloat(String(m.$('#le-disc').value).replace(',', '.')) || 0;
    l.qty = qty;
    l.unitPrice = round2(price);
    l.discount = dv > 0 ? { type, value: type === 'percent' ? Math.min(100, dv) : dv } : null;
    m.close();
    cartChanged();
  };
  m.onKey = (e) => { if (e.key === 'Enter') { e.preventDefault(); save(); } };
}

/* Custom item ---------------------------------------------------------- */

function openCustomItem() {
  const quick = ['Zboží bez kódu', 'Dárkové balení', 'Úprava oblečení', 'Dárkový poukaz'];
  const taxOpts = S.taxClasses.map((t) => `<option value="${esc(t.slug)}">${esc(t.name)}${S.config.taxes ? ` (${num(t.rate)} %)` : ''}</option>`).join('');
  const m = openModal({
    title: 'Ruční položka',
    body: `
      <div class="field"><label for="ci-name">Název</label><input class="input" id="ci-name" value="Zboží bez kódu" autofocus></div>
      <div style="display:flex;gap:8px;flex-wrap:wrap;margin:-4px 0 14px">${quick.map((q) => `<button class="toggle" data-q="${esc(q)}">${esc(q)}</button>`).join('')}</div>
      <div class="row3">
        <div class="field"><label for="ci-price">Cena za kus (s DPH)</label><input class="input" id="ci-price" type="number" min="0" step="0.01" placeholder="0"></div>
        <div class="field"><label for="ci-qty">Množství</label><input class="input" id="ci-qty" type="number" min="1" value="1"></div>
        <div class="field"><label for="ci-tax">Sazba DPH</label><select class="select input" id="ci-tax">${taxOpts}</select></div>
      </div>
      <div class="hint" style="font-size:13px;color:var(--muted)">Ruční položky neodečítají sklad a kupóny se na ně nevztahují.</div>`,
    foot: `<button class="btn ghost" data-close>Zrušit</button><button class="btn" data-add>${icon('plus', 18)} Přidat</button>`,
  });
  setTimeout(() => m.$('#ci-price').focus(), 50);
  m.el.addEventListener('click', (e) => {
    const q = e.target.closest('[data-q]');
    if (q) { m.$('#ci-name').value = q.dataset.q; m.$('#ci-price').focus(); return; }
    if (e.target.closest('[data-add]')) add();
  });
  const add = () => {
    const price = parseFloat(String(m.$('#ci-price').value).replace(',', '.'));
    if (!(price >= 0) || isNaN(price)) { m.$('#ci-price').focus(); toast('Zadejte cenu.', 'err'); return; }
    S.cart.lines.push({
      type: 'custom', productId: 0, variationId: 0, name: m.$('#ci-name').value.trim() || 'Ruční položka', variant: '', sku: '',
      image: '', qty: Math.max(1, parseInt(m.$('#ci-qty').value, 10) || 1), unitPrice: round2(price), regular: null, taxClass: m.$('#ci-tax').value, attrs: null, discount: null,
    });
    m.close();
    cartChanged();
  };
  m.onKey = (e) => { if (e.key === 'Enter') { e.preventDefault(); add(); } };
}

/* Cart discount ------------------------------------------------------- */

function openCartDiscount() {
  if (!S.cart.lines.length) { toast('Nejdřív přidejte zboží.', 'err'); return; }
  const cd = S.cart.cartDiscount;
  let type = cd ? cd.type : 'percent';
  const reasons = ['Věrná zákaznice', 'Vada zboží', 'Výprodej', 'Zaměstnanecká sleva'];
  const m = openModal({
    title: 'Sleva na celý nákup',
    body: `
      <div class="field"><label>Typ a výše slevy</label>
        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
          <div class="seg" id="cd-type"><button data-t="percent">%</button><button data-t="amount">Kč</button></div>
          <input class="input" id="cd-val" type="number" min="0" step="0.01" style="max-width:180px" value="${cd ? cd.value : ''}" placeholder="0" autofocus>
          ${[5, 10, 15, 20].map((p) => `<button class="toggle" data-quick="${p}">${p} %</button>`).join('')}
        </div></div>
      <div class="field"><label for="cd-reason">Důvod (nepovinné)</label><input class="input" id="cd-reason" value="${esc(cd ? cd.reason || '' : '')}">
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:6px">${reasons.map((r) => `<button class="toggle" data-r="${esc(r)}">${esc(r)}</button>`).join('')}</div></div>
      <div class="hint" style="font-size:13px;color:var(--muted)">Sleva se rozpočítá poměrně na všechny položky. Kupón se uplatní až po ní.</div>`,
    foot: `${cd ? '<button class="btn ghost" data-clear style="margin-right:auto">Zrušit slevu</button>' : ''}<button class="btn ghost" data-close>Zpět</button><button class="btn" data-save>Použít slevu</button>`,
  });
  const seg = () => m.$$('#cd-type button').forEach((b) => b.classList.toggle('on', b.dataset.t === type));
  seg();
  m.el.addEventListener('click', (e) => {
    const t = e.target.closest('#cd-type button');
    if (t) { type = t.dataset.t; seg(); return; }
    const q = e.target.closest('[data-quick]');
    if (q) { type = 'percent'; seg(); m.$('#cd-val').value = q.dataset.quick; return; }
    const r = e.target.closest('[data-r]');
    if (r) { m.$('#cd-reason').value = r.dataset.r; return; }
    if (e.target.closest('[data-clear]')) { S.cart.cartDiscount = null; m.close(); cartChanged(); return; }
    if (e.target.closest('[data-save]')) save();
  });
  const save = () => {
    const v = parseFloat(String(m.$('#cd-val').value).replace(',', '.')) || 0;
    S.cart.cartDiscount = v > 0 ? { type, value: type === 'percent' ? Math.min(100, v) : v, reason: m.$('#cd-reason').value.trim() } : null;
    m.close();
    cartChanged();
  };
  m.onKey = (e) => { if (e.key === 'Enter') { e.preventDefault(); save(); } };
}

/* Coupons -------------------------------------------------------------- */

function validateCoupon(code) {
  return api('coupons/validate', {
    method: 'POST',
    body: {
      code,
      lines: S.cart.lines.map(linePayload),
      cartDiscount: S.cart.cartDiscount,
      customerId: S.cart.customer ? S.cart.customer.id : 0,
      email: S.cart.customer ? S.cart.customer.email : '',
    },
    timeout: 12000,
  });
}

async function applyCoupon(code) {
  code = String(code || '').trim();
  if (!code) return false;
  if (!S.cart.lines.length) { toast('Nejdřív přidejte zboží.', 'err'); return false; }
  if (S.cart.coupons.some((c) => c.code.toUpperCase() === code.toUpperCase())) { toast('Kupón už je uplatněný.'); return false; }
  if (!S.online) { toast('Kupóny lze ověřit jen online.', 'err'); return false; }
  try {
    const res = await validateCoupon(code);
    if (res.individualUse && S.cart.coupons.length) {
      if (!(await confirmBox(`Kupón ${esc(res.code)} nelze kombinovat s jinými. Nahradit uplatněné kupóny?`, { ok: 'Nahradit' }))) return false;
      S.cart.coupons = [];
    }
    S.cart.coupons.push({ code: res.code, preview: res.preview, type: res.type, amount: res.amount });
    renderCart();
    toast(`Kupón ${res.code} uplatněn: −${money(res.preview)}`, 'ok');
    return true;
  } catch (e) {
    toast(e.message, 'err', 5000);
    return false;
  }
}

function openCoupon() {
  const m = openModal({
    title: 'Kupón',
    size: 'sm',
    body: `<div class="field"><label for="cp-code">Kód kupónu nebo voucheru ELKA Klubu</label><input class="input" id="cp-code" autocomplete="off" style="text-transform:uppercase;letter-spacing:.08em" autofocus></div>
      ${!S.online ? '<div class="error-box">Pokladna je offline – kupóny nelze ověřit.</div>' : ''}
      ${S.klub && S.klub.restrictEmail ? '<div class="hint" style="font-size:13px;color:var(--muted)">Vouchery ELKA Klubu jsou vázané na e-mail – nejdřív přiřaďte zákaznici.</div>' : ''}`,
    foot: `<button class="btn ghost" data-close>Zrušit</button><button class="btn" data-apply>Uplatnit</button>`,
  });
  const go = async () => { if (await applyCoupon(m.$('#cp-code').value)) m.close(); };
  m.$('[data-apply]').addEventListener('click', go);
  m.onKey = (e) => { if (e.key === 'Enter') { e.preventDefault(); go(); } };
}

function openNote() {
  const m = openModal({
    title: 'Poznámka k nákupu',
    size: 'sm',
    body: `<div class="field"><label for="nt">Poznámka se uloží k objednávce</label><textarea class="input" id="nt" rows="4" autofocus>${esc(S.cart.note)}</textarea></div>`,
    foot: `<button class="btn ghost" data-close>Zrušit</button><button class="btn" data-save>Uložit</button>`,
  });
  m.$('[data-save]').addEventListener('click', () => { S.cart.note = m.$('#nt').value.trim(); m.close(); renderCart(); });
}

/* ===================================================================== */
/* Customers & ELKA Klub                                                  */
/* ===================================================================== */

async function setCustomer(c) {
  S.cart.customer = c ? { ...c } : null;
  renderCart();
  revalidateCoupons();
  // Refresh club balance + vouchers live.
  if (c && S.online) {
    try {
      let fresh = null;
      if (c.id) fresh = await api(`customers/${c.id}`, { timeout: 8000 });
      else if (c.email) {
        const r = await api('klub/member', { query: { email: c.email }, timeout: 8000 });
        fresh = { ...c, klub: r.klub };
      }
      if (fresh && S.cart.customer && (S.cart.customer.id === c.id && S.cart.customer.email === c.email)) {
        S.cart.customer = { ...S.cart.customer, ...fresh };
        sync.saveCustomer(fresh);
        renderCart();
      }
    } catch (e) { /* keep cached data */ }
  }
}

function localCustomerSearch(q) {
  const w = norm(q).split(/\s+/).filter(Boolean);
  if (!w.length) return [];
  return S.customers.filter((c) => {
    const s = norm([c.name, c.email, c.phone, c.klub && c.klub.card, c.city].join(' ')).replace(/\s+/g, ' ');
    const digits = (c.phone || '').replace(/\D/g, '');
    return w.every((x) => s.includes(x) || (x.replace(/\D/g, '').length > 3 && digits.includes(x.replace(/\D/g, ''))));
  }).slice(0, 25);
}

function customerRow(c, i) {
  const k = c.klub;
  return `<button class="list-item" data-pick="${i}">
    <span class="avatar">${esc(initials(c.name || c.email))}</span>
    <span><span class="t">${esc(c.name || c.email)}</span><br><span class="s">${esc([c.email, c.phone, c.city].filter(Boolean).join(' · '))}</span></span>
    <span style="display:flex;gap:6px;align-items:center">${k ? `<span class="tag klub">${coin(16)} ${num(k.balance)} b.</span>` : ''}${!c.id ? '<span class="tag">bez účtu</span>' : ''}</span>
  </button>`;
}

function openCustomer({ create = false, query = '' } = {}) {
  let results = [];
  const m = openModal({ title: 'Zákaznice', size: 'wide', body: '' });

  const searchView = () => {
    m.body(`
      <div style="display:flex;gap:10px;margin-bottom:14px">
        <label class="search" style="max-width:none"><span class="ico">${icon('search', 22)}</span>
          <input id="cs-q" type="search" autocomplete="off" placeholder="Jméno, e-mail, telefon nebo číslo klubové karty" value="${esc(query)}" autofocus></label>
        <button class="btn" data-new>${icon('userPlus', 20)} Nová zákaznice</button>
      </div>
      <div id="cs-res"></div>`);
    const q = m.$('#cs-q');
    q.addEventListener('input', () => { query = q.value; runSearch(); });
    setTimeout(() => q.focus(), 30);
    runSearch();
  };

  const showResults = (list, note = '') => {
    results = list;
    const box = m.$('#cs-res');
    if (!box) return;
    if (!query.trim()) {
      box.innerHTML = `<div class="info-box">Začněte psát jméno, e-mail, telefon nebo naskenujte klubovou kartu. ${S.customers.length ? `V pokladně je uloženo ${num(S.customers.length)} zákaznic.` : ''}</div>`;
      return;
    }
    box.innerHTML = list.length ? `<div class="list">${list.map(customerRow).join('')}</div>${note}`
      : `<div class="empty" style="padding:30px"><div class="script" style="font-size:36px">Nenalezeno</div>Žádná zákaznice neodpovídá hledání. <button class="link" data-new>Založit novou</button></div>`;
  };

  const remote = debounce(async (q) => {
    try {
      const r = await api('customers', { query: { search: q }, timeout: 8000 });
      if (q !== query) return;
      showResults(r.customers);
    } catch (e) { /* stay with local results */ }
  }, 250);

  const runSearch = () => {
    showResults(localCustomerSearch(query), S.online ? '' : '<div class="hint" style="margin-top:8px;color:var(--muted);font-size:13px">Offline – hledá se v uložených zákaznicích.</div>');
    if (S.online && query.trim().length >= 2) remote(query.trim());
  };

  const createView = () => {
    const parts = /@/.test(query) ? { email: query } : (/^\+?[\d\s]{6,}$/.test(query) ? { phone: query } : { name: query });
    const [fn, ...ln] = (parts.name || '').trim().split(/\s+/);
    m.body(`
      ${!S.online ? '<div class="info-box">Offline – zákaznice se uloží a založí po obnovení připojení.</div>' : ''}
      <div class="row">
        <div class="field"><label for="nc-fn">Jméno</label><input class="input" id="nc-fn" value="${esc(fn || '')}" autofocus></div>
        <div class="field"><label for="nc-ln">Příjmení</label><input class="input" id="nc-ln" value="${esc(ln.join(' '))}"></div>
      </div>
      <div class="row">
        <div class="field"><label for="nc-em">E-mail</label><input class="input" id="nc-em" type="email" value="${esc(parts.email || '')}"><span class="hint">Pro účet, ELKA Klub i e-mailové účtenky.</span></div>
        <div class="field"><label for="nc-ph">Telefon</label><input class="input" id="nc-ph" type="tel" value="${esc(parts.phone || '')}"></div>
      </div>
      ${S.klub ? `
      <label class="check" style="margin-bottom:12px"><input type="checkbox" id="nc-klub" checked>
        <span><b>Přidat do ELKA Klubu</b><small>Zákaznice souhlasí s věrnostním řádem a zpracováním údajů pro účely klubu. Body se začnou připisovat hned od tohoto nákupu.</small></span></label>
      <div class="field" style="max-width:280px"><label for="nc-bd">Narozeniny (nepovinné)</label><input class="input" id="nc-bd" type="date"><span class="hint">Za narozeniny dostane bonusové body.</span></div>` : ''}
      <div id="nc-err"></div>`);
    m.foot(`<button class="btn ghost" data-back>${icon('back', 18)} Zpět</button><button class="btn" data-create>Uložit zákaznici</button>`);
    setTimeout(() => m.$('#nc-fn').focus(), 30);
  };

  const create_ = async () => {
    const payload = {
      firstName: m.$('#nc-fn').value.trim(),
      lastName: m.$('#nc-ln').value.trim(),
      email: m.$('#nc-em').value.trim().toLowerCase(),
      phone: m.$('#nc-ph').value.trim(),
      joinKlub: !!(m.$('#nc-klub') && m.$('#nc-klub').checked),
      birthday: m.$('#nc-bd') ? m.$('#nc-bd').value : '',
    };
    const err = (t) => { m.$('#nc-err').innerHTML = `<div class="error-box">${esc(t)}</div>`; };
    if (!payload.email || !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(payload.email)) { err('Zadejte platný e-mail.'); m.$('#nc-em').focus(); return; }
    const btn = m.$('[data-create]');
    btn.disabled = true;
    try {
      const c = await api('customers', { method: 'POST', body: payload });
      await sync.saveCustomer(c);
      S.customers = S.customers.filter((x) => x.email !== c.email).concat([c]);
      m.close();
      setCustomer(c);
      toast(c.existed ? 'Zákaznice už existovala – byla přiřazena.' : (payload.joinKlub ? 'Zákaznice uložena a přidána do ELKA Klubu.' : 'Zákaznice uložena.'), 'ok');
      if (c.warning) toast(c.warning, 'err');
    } catch (e) {
      if (e.network) {
        const local = {
          id: 0, firstName: payload.firstName, lastName: payload.lastName, name: `${payload.firstName} ${payload.lastName}`.trim() || payload.email,
          email: payload.email, phone: payload.phone, city: '', company: '',
          klub: payload.joinKlub ? { memberId: 0, card: '', balance: 0, lifetime: 0, vouchers: [], pending: true } : null,
        };
        await sync.enqueue({ uuid: uuid(), kind: 'customer', payload });
        S.outbox = await sync.outbox();
        await sync.saveCustomer(local);
        S.customers.push(local);
        renderHeader();
        m.close();
        setCustomer(local);
        toast('Offline: zákaznice se založí po obnovení připojení.', '', 4500);
      } else {
        err(e.message);
        btn.disabled = false;
      }
    }
  };

  m.el.addEventListener('click', (e) => {
    const pick = e.target.closest('[data-pick]');
    if (pick) { const c = results[Number(pick.dataset.pick)]; m.close(); setCustomer(c); return; }
    if (e.target.closest('[data-new]')) { createView(); return; }
    if (e.target.closest('[data-back]')) { m.foot(''); searchView(); return; }
    if (e.target.closest('[data-create]')) create_();
  });
  m.onKey = (e) => {
    if (e.key === 'Enter') {
      if (m.$('#nc-em')) { e.preventDefault(); create_(); }
      else if (results.length === 1) { e.preventDefault(); const c = results[0]; m.close(); setCustomer(c); }
    }
  };
  if (create) createView(); else searchView();
}

async function joinKlub() {
  const c = S.cart.customer;
  if (!c || !c.email) return;
  if (!(await confirmBox(`Přidat <b>${esc(c.name || c.email)}</b> do ELKA Klubu? Zákaznice souhlasí s věrnostním řádem.`, { ok: 'Přidat do Klubu' }))) return;
  const body = { email: c.email, userId: c.id, firstName: c.firstName, lastName: c.lastName };
  try {
    const r = await api('klub/join', { method: 'POST', body });
    S.cart.customer.klub = r.klub;
    sync.saveCustomer(S.cart.customer);
    toast('Vítejte v ELKA Klubu!', 'ok');
  } catch (e) {
    if (!e.network) { toast(e.message, 'err'); return; }
    await sync.enqueue({ uuid: uuid(), kind: 'klub', payload: body });
    S.outbox = await sync.outbox();
    S.cart.customer.klub = { memberId: 0, card: '', balance: 0, lifetime: 0, vouchers: [], pending: true };
    toast('Offline: členství se dokončí po obnovení připojení.');
    renderHeader();
  }
  renderCart();
}

/* ===================================================================== */
/* Payment                                                                */
/* ===================================================================== */

async function nextLocalNumber() {
  const d = new Date();
  const ymd = `${String(d.getFullYear()).slice(2)}${String(d.getMonth() + 1).padStart(2, '0')}${String(d.getDate()).padStart(2, '0')}`;
  const seq = (await db.meta.get('seq')) || { day: '', n: 0 };
  const n = seq.day === ymd ? seq.n + 1 : 1;
  await db.meta.set('seq', { day: ymd, n });
  return { local: `${S.config.store.registerId}-${ymd}-${String(n).padStart(3, '0')}`, vs: `${ymd}${String(n).padStart(3, '0')}` };
}

function qrSvg(text) {
  const qr = qrcode(0, 'M');
  qr.addData(text);
  qr.make();
  return qr.createSvgTag({ cellSize: 6, margin: 2, scalable: true });
}

async function openPayment() {
  if (!S.cart.lines.length || anyModal()) return;
  const methods = Object.entries(S.config.payments || {});
  if (!methods.length) { toast('V nastavení není povolena žádná platební metoda.', 'err'); return; }
  const ln = await nextLocalNumberPeek();
  const st = {
    method: methods[0][0],
    tendered: '',
    sendEmail: !!(S.config.emailDefault && S.cart.customer && S.cart.customer.email),
    email: S.cart.customer ? S.cart.customer.email || '' : '',
    busy: false,
    error: '',
  };
  const m = openModal({ title: '', size: 'wide', body: '' });
  m.el.style.overflow = 'hidden';

  const render = () => {
    const t = cartTotals(st.method);
    const tendered = parseFloat(String(st.tendered).replace(',', '.')) || 0;
    const change = round2(tendered - t.total);
    const quick = (() => {
      const set = new Set([t.total]);
      [100, 200, 500, 1000].forEach((step) => set.add(Math.ceil(t.total / step) * step));
      [500, 1000, 2000, 5000].forEach((n) => { if (n > t.total) set.add(n); });
      return [...set].sort((a, b) => a - b).slice(0, 8);
    })();
    let right = '';
    if (st.method === 'cash') {
      right = `<div class="lbl" style="font-size:12px;letter-spacing:.2em;color:var(--muted)">PŘIJATO OD ZÁKAZNICE</div>
        <input class="input big" id="pay-tendered" inputmode="decimal" placeholder="${num(t.total, t.total % 1 ? 2 : 0)}" value="${esc(st.tendered)}" autofocus>
        <div class="quick">${quick.map((q, i) => `<button class="${i === 0 ? 'exact' : ''}" data-tender="${q}">${i === 0 ? 'Přesně' : money(q)}</button>`).join('')}</div>
        ${tendered ? `<div class="change ${change < 0 ? 'short' : ''}"><span>${change < 0 ? 'Chybí' : 'Vrátit'}</span><b>${money(Math.abs(change))}</b></div>` : '<div class="change" style="background:var(--pisek)"><span>Vrátit</span><b>0 Kč</b></div>'}`;
    } else if (st.method === 'qr') {
      const iban = S.config.qr && S.config.qr.iban;
      right = iban ? `<div class="qr-wrap"><div class="qr-box">${qrSvg(spayd({ iban, amount: t.total, vs: ln.vs, message: (S.config.qr.message || '') + ' ' + ln.local }))}</div>
          <div><div style="font-family:var(--serif);font-size:24px;margin-bottom:8px">Naskenujte v bankovní aplikaci</div>
          <div style="color:var(--muted);line-height:1.7">Částka: <b style="color:var(--bridlice)">${money(t.total)}</b><br>Variabilní symbol: <b style="color:var(--bridlice)">${esc(ln.vs)}</b><br>Účet: ${esc(iban.replace(/(.{4})/g, '$1 ').trim())}</div>
          <p style="color:var(--muted);font-size:14px">Prodej dokončete, až uvidíte potvrzení platby.</p></div></div>`
        : '<div class="error-box">Pro QR platby vyplňte IBAN v nastavení pokladny (WooCommerce → ELKA Pokladna → Platby).</div>';
    } else if (st.method === 'card') {
      right = `<div class="card-note">Na terminálu SumUp zadejte<b>${money(t.total)}</b>a nechte zákaznici zaplatit.</div>
        <p style="color:var(--muted);text-align:center;margin:0">Prodej dokončete, až terminál potvrdí platbu.</p>`;
    }
    const canFinish = !st.busy && (st.method !== 'cash' || !tendered || change >= 0) && !(st.method === 'qr' && !(S.config.qr && S.config.qr.iban));
    m.body(`<div class="pay" style="margin:-20px -24px">
      <div class="pay-left">
        <div style="display:flex;justify-content:space-between;align-items:center"><span class="lbl">K ÚHRADĚ</span><button class="icon-btn" data-close aria-label="Zavřít">${icon('close')}</button></div>
        <div class="amount">${money(t.total)}</div>
        ${t.rounding ? `<div class="rounded">Zaokrouhleno z ${money(round2(t.total - t.rounding))}</div>` : ''}
        ${t.discountTotal > 0 ? `<div class="rounded">Ušetřeno ${money(t.discountTotal)}</div>` : ''}
        <div class="pay-methods">${methods.map(([id, label], i) => `<button class="method ${st.method === id ? 'on' : ''}" data-method="${id}">${icon(id === 'cash' ? 'cash' : id === 'qr' ? 'qr' : 'card', 26)} ${esc(label)}<kbd>${i + 1}</kbd></button>`).join('')}</div>
        <div style="margin-top:auto;font-size:14px;color:var(--muted)">
          ${S.cart.customer ? `${icon('user', 16)} ${esc(S.cart.customer.name || S.cart.customer.email)}${S.cart.customer.klub ? ` · ${coin(16)} +${num(t.points)} b.` : ''}` : 'Prodej bez zákaznice (host)'}
          ${!S.online ? `<div style="margin-top:8px;color:var(--ruze)">${icon('wifiOff', 16)} Offline – prodej se uloží a odešle později.</div>` : ''}
        </div>
      </div>
      <div class="pay-right">
        ${st.error ? `<div class="error-box">${esc(st.error)}</div>` : ''}
        ${right}
        <div style="margin-top:auto;display:flex;flex-direction:column;gap:10px">
          <label class="check"><input type="checkbox" id="pay-mail" ${st.sendEmail ? 'checked' : ''}><span><b>Poslat doklad e-mailem</b><small>${S.online ? 'Odešle se hned po dokončení.' : 'Odešle se po synchronizaci.'}</small></span></label>
          ${st.sendEmail ? `<input class="input" id="pay-email" type="email" placeholder="e-mail zákaznice" value="${esc(st.email)}">` : ''}
          <button class="btn lg block" data-finish ${canFinish ? '' : 'disabled'}>${st.busy ? 'Ukládám…' : `${icon('check', 22)} Dokončit prodej · ${money(t.total)}`}</button>
        </div>
      </div>
    </div>`);
    const tin = m.$('#pay-tendered');
    if (tin) {
      tin.addEventListener('input', () => { st.tendered = tin.value; const pos = tin.selectionStart; render(); const n = m.$('#pay-tendered'); n.focus(); try { n.setSelectionRange(pos, pos); } catch (e) { /* number */ } });
      setTimeout(() => { if (document.activeElement !== tin && !m.$('#pay-email:focus')) tin.focus(); }, 20);
    }
    const em = m.$('#pay-email');
    if (em) em.addEventListener('input', () => { st.email = em.value; });
  };

  m.el.addEventListener('click', (e) => {
    const me = e.target.closest('[data-method]');
    if (me) { st.method = me.dataset.method; st.error = ''; render(); return; }
    const td = e.target.closest('[data-tender]');
    if (td) { st.tendered = td.dataset.tender; render(); return; }
    if (e.target.id === 'pay-mail') { st.sendEmail = e.target.checked; render(); if (st.sendEmail && !st.email) setTimeout(() => m.$('#pay-email') && m.$('#pay-email').focus(), 20); return; }
    if (e.target.closest('[data-finish]')) finish();
  });
  m.onKey = (e, typing) => {
    if (e.key === 'Enter' && !e.target.closest('textarea')) { e.preventDefault(); finish(); }
    else if (!typing || e.target.id !== 'pay-tendered') {
      const i = Number(e.key) - 1;
      if (i >= 0 && i < methods.length && !typing) { st.method = methods[i][0]; render(); }
    }
  };

  const finish = async () => {
    if (st.busy) return;
    const t = cartTotals(st.method);
    const tendered = parseFloat(String(st.tendered).replace(',', '.')) || 0;
    if (st.method === 'cash' && tendered && tendered < t.total) return;
    if (st.method === 'qr' && !(S.config.qr && S.config.qr.iban)) return;
    if (st.sendEmail && st.email && !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(st.email)) { st.error = 'Zkontrolujte e-mail pro doklad.'; render(); return; }
    st.busy = true; st.error = ''; render();
    const res = await completeSale({ method: st.method, tendered: st.method === 'cash' ? (tendered || t.total) : 0, sendEmail: st.sendEmail && !!st.email, email: st.email, numbers: ln });
    if (res.ok) {
      m.close();
      await consumeLocalNumber();
      showDone(res.receipt, res.offline);
    } else {
      st.busy = false; st.error = res.error; render();
    }
  };
  render();
}

let peeked = null;
async function nextLocalNumberPeek() {
  const d = new Date();
  const ymd = `${String(d.getFullYear()).slice(2)}${String(d.getMonth() + 1).padStart(2, '0')}${String(d.getDate()).padStart(2, '0')}`;
  const seq = (await db.meta.get('seq')) || { day: '', n: 0 };
  const n = seq.day === ymd ? seq.n + 1 : 1;
  peeked = { local: `${S.config.store.registerId}-${ymd}-${String(n).padStart(3, '0')}`, vs: `${ymd}${String(n).padStart(3, '0')}` };
  return peeked;
}
async function consumeLocalNumber() { await nextLocalNumber(); }

function buildPayload({ method, tendered, sendEmail, email, numbers }) {
  const c = S.cart.customer;
  return {
    uuid: uuid(),
    createdAt: new Date().toISOString(),
    localNumber: numbers.local,
    customer: c ? { id: c.id || 0, email: c.email || '', firstName: c.firstName || '', lastName: c.lastName || '', phone: c.phone || '' } : null,
    lines: S.cart.lines.map(linePayload),
    cartDiscount: S.cart.cartDiscount,
    coupons: S.cart.coupons.map((x) => x.code),
    payment: { method, tendered },
    note: S.cart.note,
    sendEmail,
    email: sendEmail ? email : '',
  };
}

/** Local receipt in the same shape as the server's, for offline sales. */
function localReceipt(payload, t) {
  const c = S.cart.customer;
  return {
    id: 0,
    number: '',
    localNumber: payload.localNumber,
    offline: true,
    date: payload.createdAt,
    register: S.config.store.registerId,
    customer: c ? { id: c.id, name: c.name, email: c.email } : null,
    items: t.lines.map((l) => ({
      name: l.name, meta: l.variant ? [l.variant] : [], qty: l.qty, unitGross: l.unitPrice, shelfGross: l.gross,
      lineGross: l.final, discount: round2(l.gross - l.final), productId: l.productId, variationId: l.variationId,
    })),
    coupons: [],
    fees: t.rounding ? [{ name: 'Zaokrouhlení', amount: t.rounding }] : [],
    taxes: t.vat.filter((v) => v.rate).map((v) => ({ label: `DPH ${v.rate} %`, rate: v.rate, base: v.base, tax: v.tax })),
    total: t.total,
    payment: { method: payload.payment.method, label: S.config.payments[payload.payment.method], tendered: payload.payment.tendered, change: payload.payment.method === 'cash' ? Math.max(0, round2(payload.payment.tendered - t.total)) : 0 },
    klub: c && c.klub ? { card: c.klub.card, earned: 0, pending: t.points, balance: null } : null,
    eet: S.config.eet && S.config.eet.enabled ? { status: 'pending', unit: S.config.eet.unitId, register: S.config.store.registerId, porad: payload.localNumber } : null,
  };
}

async function completeSale(opt) {
  const payload = buildPayload(opt);
  const t = cartTotals(opt.method);
  if (payload.coupons.length && !S.online) return { ok: false, error: 'Kupóny vyžadují připojení. Odeberte kupón, nebo počkejte na připojení.' };
  let receipt = null;
  let offline = false;
  try {
    receipt = await api('orders', { method: 'POST', body: payload, timeout: 30000 });
  } catch (e) {
    if (!e.network) return { ok: false, error: e.message };
    if (!S.config.offline) return { ok: false, error: 'Bez připojení nelze prodej uložit (offline režim je vypnutý).' };
    if (payload.coupons.length) return { ok: false, error: 'Spojení selhalo a prodej obsahuje kupón – zkuste to znovu.' };
    // Offline: store locally, the outbox sends it later (idempotent by uuid).
    receipt = localReceipt(payload, t);
    await sync.enqueue({ uuid: payload.uuid, kind: 'order', payload, total: t.total, localNumber: payload.localNumber, customer: receipt.customer });
    await db.put('receipts', { uuid: payload.uuid, receipt, createdAt: payload.createdAt });
    S.outbox = await sync.outbox();
    offline = true;
    if (S.online) { S.online = false; }
  }
  decrementStock(S.cart.lines);
  if (receipt.klub && S.cart.customer && S.cart.customer.klub && receipt.klub.balance !== null && receipt.klub.balance !== undefined) {
    const c = { ...S.cart.customer, klub: { ...S.cart.customer.klub, balance: receipt.klub.balance } };
    sync.saveCustomer(c);
    S.customers = S.customers.map((x) => (x.email === c.email ? c : x));
  }
  S.cart = emptyCart();
  renderAll();
  return { ok: true, receipt, offline };
}

function decrementStock(lines) {
  const changed = new Set();
  lines.forEach((l) => {
    const p = l.productId ? S.byId.get(l.productId) : null;
    if (!p) return;
    if (l.variationId) {
      const v = (p.variations || []).find((x) => x.id === l.variationId);
      if (v && v.stock !== null) { v.stock -= l.qty; v.inStock = v.stock > 0; }
      if (p.stock !== null) p.stock = (p.variations || []).reduce((s, x) => s + Math.max(0, x.stock || 0), 0);
    } else if (p.stock !== null) {
      p.stock -= l.qty;
      p.inStock = p.stock > 0;
    }
    changed.add(p);
  });
  if (changed.size) db.putMany('products', [...changed].map(({ _s, ...rest }) => rest));
}

function showDone(r, offline) {
  const store = S.config.store;
  const m = openModal({
    className: 'done-modal',
    body: `<div class="done">
      <div class="script">Děkujeme</div>
      <div style="letter-spacing:.2em;font-size:13px;color:var(--muted)">${offline ? 'ULOŽENO OFFLINE · ' + esc(r.localNumber) : 'PRODEJ Č. ' + esc(r.number)}</div>
      <div class="big">${money(r.total)}</div>
      <div style="color:var(--muted)">${esc(r.payment.label || '')}</div>
      ${r.payment.method === 'cash' && r.payment.change > 0 ? `<div class="changebox">Vrátit <b>${money(r.payment.change)}</b></div>` : ''}
      ${r.klub ? `<div class="points" style="height:38px;font-size:15px">${coin(28)} ${r.klub.earned ? `+${num(r.klub.earned)} bodů · stav ${num(r.klub.balance)}` : `+${num(r.klub.pending || 0)} bodů se připíše po synchronizaci`}</div>` : ''}
      ${offline ? '<div class="info-box" style="margin:6px 0 0;text-align:left">Prodej se odešle do e-shopu (sklad, ELKA Klub, EET) automaticky, jakmile bude pokladna online.</div>' : ''}
      ${!offline && r.eet && r.eet.status === 'pending' ? '<div class="tag warn">EET se odešle dodatečně</div>' : ''}
      ${!offline && r.eet && r.eet.status === 'error' ? `<div class="tag err" title="${esc(r.eet.error)}">EET: chyba – zkontrolujte v Prodejích</div>` : ''}
      <div id="done-mail" style="width:100%"></div>
    </div>`,
    foot: `<button class="btn ghost" data-print>${icon('print', 20)} Tisk</button>
      ${!offline && !r.emailed ? `<button class="btn ghost" data-mail>${icon('mail', 20)} E-mail</button>` : ''}
      <button class="btn" data-close autofocus>Nový prodej <kbd style="background:transparent;color:#C9C2BA;border-color:#6c6f75">Enter</kbd></button>`,
    onClose: () => { const q = $('#q'); if (q) q.focus(); },
  });
  if (r.emailed) m.$('#done-mail').innerHTML = `<div class="tag ok">Doklad odeslán na ${esc(r.customer.email)}</div>`;
  m.el.addEventListener('click', (e) => {
    if (e.target.closest('[data-print]')) printReceipt(r, { copies: 1 });
    if (e.target.closest('[data-mail]')) mailForm(m.$('#done-mail'), r);
  });
  m.onKey = (e, typing) => { if (e.key === 'Enter' && !typing) { e.preventDefault(); m.close(); } };
  setTimeout(() => { const b = m.$('.modal-foot [data-close]'); if (b) b.focus(); if (store.autoPrint) printReceipt(r, { copies: store.copies }); }, 40);
}

function mailForm(box, r, after = null) {
  box.innerHTML = `<div style="display:flex;gap:8px;margin-top:10px"><input class="input" type="email" id="mf-email" placeholder="e-mail" value="${esc((r.customer && r.customer.email) || '')}"><button class="btn" id="mf-send">Odeslat</button></div>`;
  const inp = box.querySelector('#mf-email');
  inp.focus();
  const send = async () => {
    const email = inp.value.trim();
    if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)) { toast('Zadejte platný e-mail.', 'err'); return; }
    box.querySelector('#mf-send').disabled = true;
    try {
      await api(`orders/${r.id}/email`, { method: 'POST', body: { email } });
      box.innerHTML = `<div class="tag ok" style="margin-top:10px">Doklad odeslán na ${esc(email)}</div>`;
      r.emailed = new Date().toISOString();
      if (after) after();
    } catch (e) {
      toast(e.message, 'err');
      box.querySelector('#mf-send').disabled = false;
    }
  };
  box.querySelector('#mf-send').addEventListener('click', send);
  inp.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); e.stopPropagation(); send(); } });
}

/* ===================================================================== */
/* Orders, reprints and returns                                           */
/* ===================================================================== */

function openOrders() {
  if (anyModal()) return;
  const st = { q: '', scope: 'pos', list: [], sel: null, detail: null, summary: null, loading: false, refund: null };
  const m = openModal({ title: 'Prodeje a vratky', size: 'xl', body: '' });
  m.el.querySelector('.modal-body').style.padding = '0';

  const statusTag = (o) => {
    if (o.status === 'refunded') return '<span class="tag err">vráceno</span>';
    if (o.refunded > 0) return '<span class="tag warn">částečně vráceno</span>';
    return '';
  };
  const eetTag = (s) => ({ pending: '<span class="tag warn">EET čeká</span>', error: '<span class="tag err">EET chyba</span>', sent: '<span class="tag ok">EET</span>', verify: '<span class="tag">EET test</span>' }[s] || '');

  const render = () => {
    const pending = S.outbox.filter((o) => o.kind === 'order');
    const sum = st.summary;
    m.body(`<div class="orders">
      <div class="orders-list">
        <div class="tools">
          ${sum ? `<div class="summary-bar"><span>Dnes <b>${num(sum.count)}</b> prodejů</span><span><b>${money(sum.total - sum.refunded)}</b></span></div>
            <div class="summary-bar" style="font-size:13px">${Object.entries(sum.byMethod).map(([k, v]) => `<span>${esc(S.config.payments[k] || k)}: ${money(v)}</span>`).join('')}</div>` : ''}
          <label class="search" style="max-width:none"><span class="ico">${icon('search', 20)}</span><input id="or-q" type="search" placeholder="Číslo objednávky, e-mail, jméno" value="${esc(st.q)}" style="height:46px"></label>
          <div class="seg"><button data-scope="pos" class="${st.scope === 'pos' ? 'on' : ''}">Pokladna</button><button data-scope="all" class="${st.scope === 'all' ? 'on' : ''}">Vše vč. e-shopu</button></div>
        </div>
        <div class="items">
          ${pending.map((o) => `<button class="order-row ${st.sel === 'o:' + o.uuid ? 'sel' : ''}" data-out="${esc(o.uuid)}">
              <span class="n">${esc(o.localNumber || 'offline')} <span class="tag ${o.lastError ? 'err' : 'warn'}">${o.lastError ? 'odmítnuto' : 'čeká na odeslání'}</span></span><span class="amt">${money(o.total)}</span>
              <span class="d">${fmtDate(o.queuedAt)} · ${esc((o.customer && o.customer.name) || 'host')}</span><span></span></button>`).join('')}
          ${st.loading && !st.list.length ? '<div class="empty" style="padding:30px">Načítám…</div>' : ''}
          ${!S.online && !st.list.length ? '<div class="empty" style="padding:30px">Offline – historie prodejů je dostupná jen online.</div>' : ''}
          ${st.list.map((o) => `<button class="order-row ${st.sel === o.id ? 'sel' : ''}" data-id="${o.id}">
              <span class="n">#${esc(o.number)} ${statusTag(o)} ${!o.pos ? '<span class="tag">e-shop</span>' : ''}</span><span class="amt">${money(o.total)}</span>
              <span class="d">${fmtDate(o.date)} · ${esc(o.customer || 'host')} · ${esc(o.payment || '')}</span><span>${eetTag(o.eet)}</span></button>`).join('')}
        </div>
      </div>
      <div class="orders-detail" id="or-detail">${detailHtml()}</div>
    </div>`);
    const q = m.$('#or-q');
    q.addEventListener('input', () => { st.q = q.value; loadList(); });
  };

  const detailHtml = () => {
    if (st.refund) return refundHtml();
    const d = st.detail;
    if (!d) return `<div class="empty"><div class="script">Prodeje</div>Vyberte prodej vlevo – můžete vytisknout kopii účtenky, poslat doklad e-mailem nebo přijmout vratku.</div>`;
    if (d.outbox) {
      const r = d.receipt;
      return `<div class="detail-head"><h3>${esc(r.localNumber)}</h3><span class="tag ${d.item.lastError ? 'err' : 'warn'}">${d.item.lastError ? 'odmítnuto serverem' : 'čeká na odeslání'}</span></div>
        ${d.item.lastError ? `<div class="error-box">${esc(d.item.lastError)}</div>` : '<div class="info-box">Prodej proběhl offline. Odešle se automaticky po obnovení připojení.</div>'}
        ${itemsTable(r)}
        <div style="display:flex;gap:10px;margin-top:18px;flex-wrap:wrap">
          <button class="btn ghost" data-reprint-local>${icon('print', 18)} Tisk kopie</button>
          <button class="btn" data-send-now ${S.online ? '' : 'disabled'}>${icon('sync', 18)} Odeslat nyní</button>
          <button class="btn ghost" data-drop style="margin-left:auto;color:var(--ruze);border-color:var(--ruze)">${icon('trash', 18)} Smazat z fronty</button>
        </div>`;
    }
    const eet = d.eet && d.eet.status !== 'off' ? `<div style="margin-top:14px;font-size:14px">${eetTag(d.eet.status)} ${d.eet.code ? `<span style="color:var(--muted);word-break:break-all">${esc(d.eet.code)}</span>` : ''} ${d.eet.error ? `<span style="color:var(--ruze)">${esc(d.eet.error)}</span>` : ''}
      ${['pending', 'error'].includes(d.eet.status) ? '<button class="btn sm ghost" data-eet-retry style="margin-left:8px">Odeslat znovu</button>' : ''}</div>` : '';
    const canRefund = d.items.some((i) => i.refundable > 0);
    return `<div class="detail-head"><h3>#${esc(d.number)}</h3>${statusTagFromReceipt(d)}<span style="color:var(--muted)">${fmtDate(d.date)} · ${esc(d.payment.label || '')}${d.localNumber ? ' · ' + esc(d.localNumber) : ''}</span></div>
      ${d.customer && (d.customer.name || d.customer.email) ? `<div style="margin-bottom:12px">${icon('user', 16)} ${esc(d.customer.name || '')} ${d.customer.email ? `<span style="color:var(--muted)">${esc(d.customer.email)}</span>` : ''} ${d.klub ? `<span class="tag klub" style="margin-left:6px">${coin(16)} ${num(d.klub.balance)} b.</span>` : ''}</div>` : ''}
      ${itemsTable(d)}
      ${d.refunds.length ? `<div style="margin-top:14px">${d.refunds.map((r) => `<div class="trow disc"><span>Vratka R${r.id} · ${fmtDate(r.date)} · ${esc(r.reason)}</span><span>−${money(r.amount)}</span></div>`).join('')}</div>` : ''}
      ${eet}
      <div id="or-mail"></div>
      <div style="display:flex;gap:10px;margin-top:18px;flex-wrap:wrap">
        <button class="btn ghost" data-reprint>${icon('print', 18)} Tisk kopie</button>
        <button class="btn ghost" data-mail>${icon('mail', 18)} Poslat e-mailem</button>
        ${canRefund ? `<button class="btn rose" data-refund>${icon('back', 18)} Vrátit zboží</button>` : ''}
        <a class="btn ghost" style="margin-left:auto;text-decoration:none" target="_blank" rel="noopener" href="${esc(orderAdminUrl(d.id))}">Otevřít v administraci</a>
      </div>`;
  };

  const statusTagFromReceipt = (d) => (d.status === 'refunded' ? '<span class="tag err">vráceno</span>' : d.refunded > 0 ? '<span class="tag warn">částečně vráceno</span>' : '<span class="tag ok">zaplaceno</span>');

  const itemsTable = (r) => `<table class="table"><thead><tr><th>POLOŽKA</th><th class="num">KS</th><th class="num">CENA/KS</th><th class="num">SLEVA</th><th class="num">CELKEM</th></tr></thead><tbody>
      ${r.items.map((i) => `<tr><td>${esc(i.meta && i.meta.length ? i.name.split(' - ')[0] : i.name)}${i.meta && i.meta.length ? `<br><small>${i.meta.map(esc).join(' · ')}</small>` : ''}${i.refundable !== undefined && i.refundable < i.qty ? `<br><small style="color:var(--ruze)">vráceno ${i.qty - i.refundable} ks</small>` : ''}</td>
        <td class="num">${i.qty}</td><td class="num">${money(i.unitGross)}</td><td class="num">${i.discount > 0.004 ? '−' + money(i.discount) : ''}</td><td class="num">${money(i.lineGross)}</td></tr>`).join('')}
      ${(r.coupons || []).map((c) => `<tr><td colspan="4">Kupón ${esc(c.code)} <small>(rozpočítán v cenách)</small></td><td class="num">−${money(c.amount)}</td></tr>`).join('')}
      ${(r.fees || []).map((f) => `<tr><td colspan="4">${esc(f.name)}</td><td class="num">${money(f.amount)}</td></tr>`).join('')}
      <tr><td colspan="4" style="letter-spacing:.14em;font-size:13px">CELKEM</td><td class="num" style="font-family:var(--serif);font-size:22px">${money(r.total)}</td></tr>
    </tbody></table>`;

  const refundHtml = () => {
    const d = st.detail;
    const f = st.refund;
    const amount = round2(d.items.reduce((s, i) => s + (f.qty[i.itemId] || 0) * (i.qty ? i.lineGross / i.qty : 0), 0));
    const payout = f.method === 'cash' && S.config.cashRounding ? Math.round(amount) : amount;
    return `<div class="detail-head"><h3>Vratka k #${esc(d.number)}</h3><button class="btn sm ghost" data-refund-cancel>${icon('back', 16)} Zpět</button></div>
      <table class="table"><thead><tr><th>POLOŽKA</th><th class="num">ZAPLACENO/KS</th><th class="num">LZE VRÁTIT</th><th class="num">VRÁTIT KS</th></tr></thead><tbody>
      ${d.items.map((i) => `<tr><td>${esc(i.meta && i.meta.length ? i.name.split(' - ')[0] : i.name)}${i.meta && i.meta.length ? `<br><small>${i.meta.map(esc).join(' · ')}</small>` : ''}</td>
        <td class="num">${money(i.qty ? i.lineGross / i.qty : 0)}</td><td class="num">${i.refundable}</td>
        <td class="num">${i.refundable > 0 ? `<span class="qty"><button data-rq="${i.itemId}" data-d="-1">−</button><span>${f.qty[i.itemId] || 0}</span><button data-rq="${i.itemId}" data-d="1" data-max="${i.refundable}">+</button></span>` : '—'}</td></tr>`).join('')}
      </tbody></table>
      <div class="row" style="margin-top:18px">
        <div class="field"><label>Důvod</label><div style="display:flex;gap:8px;flex-wrap:wrap">${['Nesedí velikost', 'Nelíbí se', 'Vada zboží', 'Jiný důvod'].map((r) => `<button class="toggle ${f.reason === r ? 'on' : ''}" data-reason="${esc(r)}">${esc(r)}</button>`).join('')}</div></div>
        <div class="field"><label>Způsob vrácení peněz</label><div class="seg">${Object.entries(S.config.payments).map(([k, l]) => `<button class="${f.method === k ? 'on' : ''}" data-rmethod="${k}">${esc(k === 'card' ? 'Na kartu' : k === 'qr' ? 'Převodem' : 'Hotově')}</button>`).join('')}</div></div>
      </div>
      <label class="check" style="max-width:460px"><input type="checkbox" id="rf-restock" ${f.restock ? 'checked' : ''}><span><b>Vrátit zboží na sklad</b><small>Odškrtněte u vadného zboží.</small></span></label>
      ${f.method === 'card' ? '<div class="info-box" style="margin-top:12px">Vrácení na kartu proveďte v aplikaci SumUp (Historie transakcí → Vrátit).</div>' : ''}
      ${f.error ? `<div class="error-box" style="margin-top:12px">${esc(f.error)}</div>` : ''}
      <div style="display:flex;gap:10px;margin-top:18px;align-items:center">
        <span style="font-size:15px;color:var(--muted)">ELKA Klub body se odečtou automaticky.</span>
        <button class="btn rose lg" data-refund-go style="margin-left:auto" ${amount > 0 && !f.busy ? '' : 'disabled'}>${f.busy ? 'Zpracovávám…' : `Vrátit ${money(payout)}`}</button>
      </div>`;
  };

  const orderAdminUrl = (id) => (CFG.hpos ? CFG.admin.replace('admin.php?page=elka-pos', `admin.php?page=wc-orders&action=edit&id=${id}`) : CFG.orderAdmin + id);

  const loadList = debounce(async () => {
    if (!S.online) { render(); return; }
    st.loading = true;
    try {
      const [list, sum] = await Promise.all([
        api('orders', { query: { search: st.q, scope: st.scope }, timeout: 15000 }),
        st.summary ? Promise.resolve(st.summary) : api('summary', { timeout: 10000 }).catch(() => null),
      ]);
      st.list = list.orders;
      st.summary = sum;
    } catch (e) { toast(e.message, 'err'); }
    st.loading = false;
    const focus = document.activeElement && document.activeElement.id === 'or-q';
    render();
    if (focus) { const q = m.$('#or-q'); q.focus(); q.setSelectionRange(q.value.length, q.value.length); }
  }, 250);

  const loadDetail = async (id) => {
    st.sel = id; st.refund = null;
    st.detail = null;
    render();
    try { st.detail = await api(`orders/${id}`); } catch (e) { toast(e.message, 'err'); }
    m.$('#or-detail').innerHTML = detailHtml();
  };

  m.el.addEventListener('click', async (e) => {
    const t = e.target;
    const row = t.closest('[data-id]');
    if (row && row.classList.contains('order-row')) { loadDetail(Number(row.dataset.id)); return; }
    const out = t.closest('[data-out]');
    if (out) {
      const item = S.outbox.find((o) => o.uuid === out.dataset.out);
      const rec = await db.get('receipts', item.uuid);
      st.sel = 'o:' + item.uuid; st.refund = null;
      st.detail = { outbox: true, item, receipt: rec ? rec.receipt : { localNumber: item.localNumber, items: [], total: item.total } };
      render();
      return;
    }
    const sc = t.closest('[data-scope]');
    if (sc) { st.scope = sc.dataset.scope; render(); loadList(); return; }
    const d = st.detail;
    if (t.closest('[data-reprint]') && d) { printReceipt(d, { kind: 'copy' }); return; }
    if (t.closest('[data-reprint-local]') && d) { printReceipt(d.receipt, { kind: 'copy' }); return; }
    if (t.closest('[data-mail]') && d) { mailForm(m.$('#or-mail'), d); return; }
    if (t.closest('[data-eet-retry]') && d) {
      try { d.eet = await api(`orders/${d.id}/eet`, { method: 'POST' }); toast(d.eet.status === 'sent' ? 'Tržba evidována.' : 'EET: ' + (d.eet.error || d.eet.status), d.eet.status === 'sent' ? 'ok' : 'err'); } catch (err) { toast(err.message, 'err'); }
      m.$('#or-detail').innerHTML = detailHtml();
      return;
    }
    if (t.closest('[data-send-now]')) { await flushOutbox(); st.detail = null; st.sel = null; render(); loadList(); return; }
    if (t.closest('[data-drop]') && d) {
      if (await confirmBox('Smazat prodej z fronty? Nebude odeslán do e-shopu ani do EET. Použijte jen pokud byl prodej zadán omylem.', { ok: 'Smazat', danger: true })) {
        await db.del('outbox', d.item.uuid);
        S.outbox = await sync.outbox();
        st.detail = null; st.sel = null; renderHeader(); render();
      }
      return;
    }
    if (t.closest('[data-refund]') && d) {
      if (!S.online) { toast('Vratky lze zpracovat jen online.', 'err'); return; }
      st.refund = { qty: {}, reason: 'Nesedí velikost', method: d.payment.method && S.config.payments[d.payment.method] ? d.payment.method : Object.keys(S.config.payments)[0], restock: true, busy: false, error: '' };
      if (d.items.length === 1 && d.items[0].refundable === 1) st.refund.qty[d.items[0].itemId] = 1;
      m.$('#or-detail').innerHTML = detailHtml();
      return;
    }
    const f = st.refund;
    if (f) {
      if (t.closest('[data-refund-cancel]')) { st.refund = null; m.$('#or-detail').innerHTML = detailHtml(); return; }
      const rq = t.closest('[data-rq]');
      if (rq) {
        const id = Number(rq.dataset.rq);
        const item = d.items.find((i) => i.itemId === id);
        f.qty[id] = Math.max(0, Math.min(item.refundable, (f.qty[id] || 0) + Number(rq.dataset.d)));
      }
      const rs = t.closest('[data-reason]');
      if (rs) f.reason = rs.dataset.reason;
      const rm = t.closest('[data-rmethod]');
      if (rm) f.method = rm.dataset.rmethod;
      if (t.id === 'rf-restock') f.restock = t.checked;
      if (t.closest('[data-refund-go]')) {
        const lines = Object.entries(f.qty).filter(([, q]) => q > 0).map(([itemId, qty]) => ({ itemId: Number(itemId), qty }));
        if (!lines.length) return;
        f.busy = true; f.error = '';
        m.$('#or-detail').innerHTML = detailHtml();
        try {
          const res = await api(`orders/${d.id}/refund`, { method: 'POST', body: { lines, restock: f.restock, method: f.method, reason: f.reason } });
          printReceipt(res, { kind: 'refund', refund: res.refund });
          toast(`Vratka zpracována: ${money(res.refund.payout || res.refund.amount)}`, 'ok');
          st.refund = null;
          st.detail = res;
          st.summary = null;
          loadList();
          // Stock back in the local catalogue.
          if (f.restock) syncNow({ quiet: true });
        } catch (err) {
          f.busy = false; f.error = err.message;
        }
      }
      m.$('#or-detail').innerHTML = detailHtml();
    }
  });

  render();
  loadList();
}

/* ===================================================================== */
/* Menu                                                                   */
/* ===================================================================== */

function openMenu() {
  const m = openModal({
    title: 'Nabídka',
    size: 'sm',
    body: `<div class="list">
      <button class="list-item" data-m="orders"><span class="avatar" style="background:var(--pisek);color:var(--bridlice)">${icon('receipt', 20)}</span><span><span class="t">Prodeje a vratky</span><br><span class="s">Kopie účtenky, e-mail, vrácení zboží</span></span><kbd>F6</kbd></button>
      <button class="list-item" data-m="sync"><span class="avatar" style="background:var(--pisek);color:var(--bridlice)">${icon('sync', 20)}</span><span><span class="t">Synchronizovat</span><br><span class="s">Poslední: ${S.lastSync ? fmtDate(S.lastSync) : '—'} · ${num(S.products.length)} produktů · ${num(S.customers.length)} zákaznic</span></span><span></span></button>
      <button class="list-item" data-m="full"><span class="avatar" style="background:var(--pisek);color:var(--bridlice)">${icon('sync', 20)}</span><span><span class="t">Znovu stáhnout katalog a zákaznice</span><br><span class="s">Kompletní obnova dat v pokladně</span></span><span></span></button>
      <button class="list-item" data-m="test"><span class="avatar" style="background:var(--pisek);color:var(--bridlice)">${icon('print', 20)}</span><span><span class="t">Zkušební tisk</span><br><span class="s">Ověření tiskárny 80 mm</span></span><span></span></button>
      <button class="list-item" data-m="fs"><span class="avatar" style="background:var(--pisek);color:var(--bridlice)">${icon('expand', 20)}</span><span><span class="t">Celá obrazovka</span></span><span></span></button>
      <a class="list-item" href="${esc(CFG.admin)}" target="_blank" rel="noopener" style="text-decoration:none;color:inherit"><span class="avatar" style="background:var(--pisek);color:var(--bridlice)">${icon('settings', 20)}</span><span><span class="t">Nastavení pokladny</span><br><span class="s">Údaje na účtence, platby, EET</span></span><span></span></a>
      <a class="list-item" href="${esc(CFG.logout)}" style="text-decoration:none;color:inherit"><span class="avatar" style="background:var(--pisek);color:var(--bridlice)">${icon('logout', 20)}</span><span><span class="t">Odhlásit</span></span><span></span></a>
    </div>
    <p style="font-size:12px;color:var(--muted);margin:14px 0 0">ELKA Pokladna ${esc(CFG.version)} · Zkratky: F2 hledat · F4 zákaznice · F6 prodeje · F8 zaplatit · Esc zavřít</p>`,
  });
  m.el.addEventListener('click', async (e) => {
    const b = e.target.closest('[data-m]');
    if (!b) return;
    const a = b.dataset.m;
    m.close();
    if (a === 'orders') openOrders();
    if (a === 'sync') { await syncNow(); syncCustomersQuiet(); }
    if (a === 'full') { await syncNow({ full: true }); await syncCustomersQuiet(); toast('Data obnovena.', 'ok'); renderAll(); }
    if (a === 'fs') { if (!document.fullscreenElement) document.documentElement.requestFullscreen().catch(() => {}); else document.exitFullscreen(); }
    if (a === 'test') {
      printReceipt({
        number: 'TEST', date: new Date().toISOString(), register: S.config.store.registerId,
        items: [{ name: 'Zkušební položka', meta: ['Velikost: 104'], qty: 1, unitGross: 100, lineGross: 100, discount: 0 }],
        fees: [], coupons: [], taxes: S.config.store.vatPayer ? [{ rate: 21, base: 82.64, tax: 17.36 }] : [], total: 100,
        payment: { label: 'Hotově', tendered: 200, change: 100 }, customer: null, klub: null, eet: null,
      }, { kind: 'copy' });
    }
  });
}

/* Expose a tiny debug handle. */
window.ELKA_POS_APP = { S, syncNow, flushOutbox };

boot().catch((e) => {
  console.error(e);
  bootMessage('Pokladnu se nepodařilo spustit: ' + e.message, true);
});
