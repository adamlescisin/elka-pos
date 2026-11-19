/* 80 mm thermal receipt (72 mm printable) – printed via a hidden iframe. */

import { esc, money, fmtDate, num } from './ui.js';

const CFG = window.ELKA_POS;

const css = (assets) => `
@font-face{font-family:'Jost';font-weight:400;src:url(${assets}fonts/jost-latin-ext-400-normal.woff2) format('woff2');unicode-range:U+0100-02AF}
@font-face{font-family:'Jost';font-weight:400;src:url(${assets}fonts/jost-latin-400-normal.woff2) format('woff2');unicode-range:U+0000-00FF,U+2000-206F}
@font-face{font-family:'Jost';font-weight:600;src:url(${assets}fonts/jost-latin-ext-600-normal.woff2) format('woff2');unicode-range:U+0100-02AF}
@font-face{font-family:'Jost';font-weight:600;src:url(${assets}fonts/jost-latin-600-normal.woff2) format('woff2');unicode-range:U+0000-00FF,U+2000-206F}
@font-face{font-family:'Playfair Display';src:url(${assets}fonts/playfair-display-latin-ext-400-normal.woff2) format('woff2');unicode-range:U+0100-02AF}
@font-face{font-family:'Playfair Display';src:url(${assets}fonts/playfair-display-latin-400-normal.woff2) format('woff2');unicode-range:U+0000-00FF,U+2000-206F}
@font-face{font-family:'Great Vibes';src:url(${assets}fonts/great-vibes-latin-ext-400-normal.woff2) format('woff2');unicode-range:U+0100-02AF}
@font-face{font-family:'Great Vibes';src:url(${assets}fonts/great-vibes-latin-400-normal.woff2) format('woff2');unicode-range:U+0000-00FF,U+2000-206F}
@page{size:80mm auto;margin:0}
*{box-sizing:border-box}
html,body{margin:0;padding:0;background:#fff;color:#000}
body{width:72mm;margin:0 auto;padding:3mm 0 6mm;font-family:'Jost',Arial,sans-serif;font-size:11.5px;line-height:1.35;-webkit-print-color-adjust:exact;print-color-adjust:exact}
.c{text-align:center}.r{text-align:right}
.logo{display:flex;flex-direction:column;align-items:center;gap:2px;margin-bottom:6px}
.logo .n{font-size:17px;letter-spacing:.12em;margin-top:4px}
.logo .t{font-size:8px;letter-spacing:.36em}
.small{font-size:10px}
hr{border:0;border-top:1px dashed #000;margin:6px 0}
hr.solid{border-top:1px solid #000}
.title{font-family:'Playfair Display',Georgia,serif;font-size:14px;letter-spacing:.14em;text-align:center;margin:4px 0}
table{width:100%;border-collapse:collapse}
td{vertical-align:top;padding:1px 0}
.it .nm{font-weight:600}
.it .mt{font-size:10px}
.row{display:flex;justify-content:space-between;gap:6px}
.total{display:flex;justify-content:space-between;align-items:baseline;font-size:13px;letter-spacing:.12em;margin:4px 0}
.total b{font-family:'Playfair Display',Georgia,serif;font-weight:400;font-size:22px;letter-spacing:0}
.vat td{font-size:10px}
.vat th{font-size:9px;font-weight:600;text-align:right;letter-spacing:.06em}
.vat th:first-child{text-align:left}
.klub{border:1px solid #000;padding:4px 6px;margin-top:6px}
.klub .k{font-family:'Great Vibes',cursive;font-size:18px;line-height:.8}
.sig{font-family:'Great Vibes',cursive;font-size:22px;text-align:center;margin-top:6px;line-height:1}
.ref{font-size:13px;letter-spacing:.14em;text-align:center;font-weight:600;border:1px solid #000;padding:3px;margin:6px 0}
.cut{height:4mm}
`;

const logo = () => `<div class="logo">
  <svg viewBox="0 0 120 120" width="54" height="54"><circle cx="60" cy="60" r="52" fill="none" stroke="#000" stroke-width="10" pathLength="120" stroke-dasharray="0.45 1.05"></circle><circle cx="60" cy="60" r="42.5" fill="none" stroke="#000" stroke-width="1.8" pathLength="90" stroke-dasharray="0 1" stroke-linecap="round"></circle><text x="60" y="72" text-anchor="middle" font-family="Playfair Display, Georgia, serif" font-size="34" fill="#000">LK</text></svg>
  <div class="n">${esc((CFG.store.name || 'ELKA FASHION').toUpperCase())}</div>
  <div class="t">${esc((CFG.store.tagline || '').toUpperCase())}</div>
</div>`;

function header(store) {
  const lines = [];
  if (store.company) lines.push(esc(store.company));
  if (store.address) lines.push(esc(store.address).replace(/\n/g, '<br>'));
  const ids = [];
  if (store.ico) ids.push('IČO: ' + esc(store.ico));
  if (store.dic) ids.push('DIČ: ' + esc(store.dic));
  if (ids.length) lines.push(ids.join(' · '));
  if (!store.vatPayer && store.ico) lines.push('Neplátce DPH');
  if (store.phone) lines.push('Tel.: ' + esc(store.phone));
  return `<div class="c small">${lines.join('<br>')}</div>`;
}

function eetBlock(eet, offline) {
  if (!eet || !CFG.config || !CFG.config.eet || !CFG.config.eet.enabled) return '';
  const rows = [];
  rows.push('Evidence tržeb – EET 2.0');
  if (eet.unit) rows.push(`Evid. jednotka: ${esc(eet.unit)} · Pokladna: ${esc(eet.register)}`);
  if (eet.porad) rows.push(`Pořadové číslo: ${esc(eet.porad)}`);
  if (eet.status === 'sent' && eet.code) rows.push(`Kód potvrzení: <span style="word-break:break-all">${esc(eet.code)}</span>`);
  else if (eet.status === 'verify') rows.push('Ověřovací režim – tržba nebyla evidována');
  else if (offline || eet.status === 'pending' || eet.status === 'error') rows.push('Tržba bude evidována dodatečně (do 48 h).');
  return `<hr><div class="small">${rows.join('<br>')}</div>`;
}

/**
 * @param {object} r Receipt (server shape or local offline shape).
 * @param {object} opt { kind: 'sale'|'refund'|'copy', refund }
 */
export function receiptHtml(r, opt = {}) {
  const store = CFG.store || {};
  const kind = opt.kind || 'sale';
  const assets = CFG.assets;
  const number = r.number ? r.number : (r.localNumber || '—');
  const date = r.date ? fmtDate(r.date) : fmtDate(new Date().toISOString());
  let body = '';

  if (kind === 'refund' && opt.refund) {
    const f = opt.refund;
    body += `<div class="ref">DOKLAD O VRÁCENÍ</div>
      <div class="row small"><span>K účtence č. ${esc(number)}</span><span>${fmtDate(new Date().toISOString())}</span></div>
      <div class="row small"><span>Vratka č. R${esc(f.id)}</span><span>Pokladna: ${esc(r.register || store.registerId)}</span></div><hr>
      <table>${(f.items || []).map((it) => `<tr class="it"><td><div class="nm">${esc(it.name)}</div><div class="mt">${it.qty} ks</div></td><td class="r">−${money(it.gross)}</td></tr>`).join('')}</table>
      <hr class="solid"><div class="total"><span>VRÁCENO</span><b>${money(f.payout || f.amount)}</b></div>
      <div class="small">Způsob vrácení: ${esc(({ cash: 'hotově', card: 'na kartu', qr: 'převodem' })[f.method] || f.method)}${f.reason ? '<br>Důvod: ' + esc(f.reason.split(' · ')[0]) : ''}</div>
      ${eetBlock(f.eet, false)}
      <div style="margin-top:14px" class="small">Podpis zákazníka: ..................................</div>`;
  } else {
    body += `<div class="title">${kind === 'copy' ? 'KOPIE ÚČTENKY' : 'ÚČTENKA'}</div>
      <div class="row small"><span>Č. ${esc(number)}</span><span>${esc(date)}</span></div>
      <div class="row small"><span>Pokladna: ${esc(r.register || store.registerId)}</span><span>${esc(r.cashier || '')}</span></div>
      ${r.offline && !r.number ? '<div class="small" style="margin-top:2px">Vystaveno offline – číslo objednávky bude přiděleno po synchronizaci.</div>' : ''}
      <hr>
      <table>${r.items.map((it) => `<tr class="it"><td>
          <div class="nm">${esc(it.meta && it.meta.length ? it.name.split(' - ')[0] : it.name)}</div>
          ${it.meta && it.meta.length ? `<div class="mt">${it.meta.map(esc).join(' · ')}</div>` : ''}
          <div class="mt">${it.qty} × ${money(it.unitGross)}${it.discount > 0.004 ? ` · sleva −${money(it.discount)}` : ''}</div>
        </td><td class="r" style="white-space:nowrap;padding-left:6px">${money(it.lineGross)}</td></tr>`).join('')}</table>
      ${(r.coupons || []).length ? '<hr>' + r.coupons.map((c) => `<div class="row small"><span>Kupón ${esc(c.code)}</span><span>(v cenách) −${money(c.amount)}</span></div>`).join('') : ''}
      ${(r.fees || []).map((f) => `<div class="row small"><span>${esc(f.name)}</span><span>${money(f.amount)}</span></div>`).join('')}
      <hr class="solid">
      <div class="total"><span>CELKEM</span><b>${money(r.total)}</b></div>
      <div class="row small"><span>Platba: ${esc(r.payment?.label || '')}</span><span>${money(r.total)}</span></div>
      ${r.payment && r.payment.tendered ? `<div class="row small"><span>Přijato</span><span>${money(r.payment.tendered)}</span></div><div class="row small"><span>Vráceno</span><span>${money(r.payment.change)}</span></div>` : ''}
      ${store.vatPayer && (r.taxes || []).length ? `<hr><table class="vat"><tr><th>Sazba DPH</th><th>Základ</th><th>DPH</th><th>Celkem</th></tr>${r.taxes.map((t) => `<tr><td>${num(t.rate, 0)} %</td><td class="r">${num(t.base, 2)}</td><td class="r">${num(t.tax, 2)}</td><td class="r">${num(t.base + t.tax, 2)}</td></tr>`).join('')}</table>` : ''}
      ${r.customer && (r.customer.name || r.customer.email) ? `<hr><div class="small">Zákazník: ${esc(r.customer.name || r.customer.email)}</div>` : ''}
      ${r.klub ? `<div class="klub small"><div class="row"><span>ELKA <span class="k">Klub</span></span><span>${esc(r.klub.card || '')}</span></div>
          ${r.klub.earned ? `<div class="row"><span>Za tento nákup</span><span>+${num(r.klub.earned)} b.</span></div>` : (r.klub.pending ? `<div class="row"><span>Za tento nákup</span><span>+${num(r.klub.pending)} b. (připíše se)</span></div>` : '')}
          ${r.klub.balance !== undefined && r.klub.balance !== null ? `<div class="row"><span>Stav bodů</span><span>${num(r.klub.balance)} b.</span></div>` : ''}
        </div>` : ''}
      ${eetBlock(r.eet, r.offline && !r.number)}`;
  }

  return `<!doctype html><html lang="cs"><head><meta charset="utf-8"><title>Účtenka ${esc(number)}</title><style>${css(assets)}</style></head><body>
    ${logo()}${header(store)}<hr>
    ${body}
    <hr>
    ${store.footer ? `<div class="c small">${esc(store.footer)}</div>` : ''}
    ${store.signoff ? `<div class="sig">${esc(store.signoff)}</div>` : ''}
    ${store.web ? `<div class="c small" style="margin-top:4px">${esc(store.web)}</div>` : ''}
    <div class="cut"></div>
  </body></html>`;
}

/** Print through the hidden iframe; resolves when the print dialog/job returns. */
export function printHtml(html, copies = 1) {
  return new Promise((resolve) => {
    const frame = document.getElementById('print-frame');
    frame.onload = async () => {
      const w = frame.contentWindow;
      try { await w.document.fonts.ready; } catch (e) { /* fonts optional */ }
      const prev = document.activeElement;
      setTimeout(() => {
        try {
          w.focus();
          for (let i = 0; i < Math.max(1, copies); i++) w.print();
        } catch (e) { /* ignore */ }
        // Give keyboard focus back to the till (Enter = next sale, scanner input…).
        window.focus();
        if (prev && document.contains(prev) && prev.focus) prev.focus();
        resolve();
      }, 60);
    };
    frame.srcdoc = html;
  });
}

export function printReceipt(r, opt = {}) {
  return printHtml(receiptHtml(r, opt), opt.copies || 1);
}
