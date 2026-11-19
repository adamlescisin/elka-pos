/* Cart pricing. Mirrors Elka_Pos_Orders::price_lines() – keep both in sync.
   Prices are shelf prices incl. VAT. */

import { round2 } from './ui.js';

export function priceLines(lines, cartDiscount, decimals = 2) {
  const r = (v) => { const f = Math.pow(10, decimals); return Math.round((v + Number.EPSILON) * f) / f; };
  const out = lines.map((l) => {
    const qty = Math.max(0, Number(l.qty) || 0);
    const gross = r((Number(l.unitPrice) || 0) * qty);
    let disc = 0;
    if (l.discount && Number(l.discount.value)) {
      const v = Number(l.discount.value);
      disc = l.discount.type === 'percent' ? r(gross * Math.min(100, v) / 100) : Math.min(gross, v);
    }
    return { ...l, gross, lineDiscount: disc, final: Math.max(0, gross - disc), cartDiscount: 0 };
  });
  if (cartDiscount && Number(cartDiscount.value)) {
    const base = out.reduce((s, l) => s + l.final, 0);
    if (base > 0) {
      const v = Number(cartDiscount.value);
      const total = cartDiscount.type === 'percent' ? r(base * Math.min(100, v) / 100) : Math.min(base, v);
      let left = total;
      out.forEach((l, i) => {
        let share = i === out.length - 1 ? left : r(total * l.final / base);
        share = Math.min(share, l.final, left);
        l.cartDiscount = share;
        l.final = round2(l.final - share);
        left = round2(left - share);
      });
    }
  }
  return out;
}

/**
 * @param {object} cart  { lines, cartDiscount, coupons:[{code, preview}] }
 * @param {object} opt   { taxRates: {slug: rate%}, cashRounding, method, decimals, amountPerPoint }
 */
export function totals(cart, opt = {}) {
  const lines = priceLines(cart.lines, cart.cartDiscount, opt.decimals ?? 2);
  const shelf = round2(lines.reduce((s, l) => s + l.gross, 0));
  const lineDisc = round2(lines.reduce((s, l) => s + l.lineDiscount, 0));
  const cartDisc = round2(lines.reduce((s, l) => s + l.cartDiscount, 0));
  const afterManual = round2(lines.reduce((s, l) => s + l.final, 0));
  const couponDisc = round2((cart.coupons || []).reduce((s, c) => s + (Number(c.preview) || 0), 0));
  let total = Math.max(0, round2(afterManual - couponDisc));

  // VAT estimate per rate (exact numbers come from WooCommerce after saving).
  const vat = {};
  const couponRatio = afterManual > 0 ? (afterManual - couponDisc) / afterManual : 1;
  lines.forEach((l) => {
    const rate = Number((opt.taxRates || {})[l.taxClass || ''] ?? 0);
    const g = l.final * couponRatio;
    if (!vat[rate]) vat[rate] = { rate, gross: 0, tax: 0, base: 0 };
    vat[rate].gross += g;
  });
  Object.values(vat).forEach((v) => {
    v.gross = round2(v.gross);
    v.tax = v.rate ? round2(v.gross * v.rate / (100 + v.rate)) : 0;
    v.base = round2(v.gross - v.tax);
  });

  let rounding = 0;
  if (opt.cashRounding && opt.method === 'cash') {
    const rounded = Math.round(total);
    rounding = round2(rounded - total);
    total = rounded;
  }
  const items = lines.reduce((s, l) => s + (Number(l.qty) || 0), 0);
  const points = opt.amountPerPoint ? Math.floor((total + 0.00001) / opt.amountPerPoint) : 0;
  return { lines, shelf, lineDisc, cartDisc, couponDisc, discountTotal: round2(lineDisc + cartDisc + couponDisc), afterManual, total, rounding, vat: Object.values(vat).sort((a, b) => b.rate - a.rate), items, points };
}

/* SPAYD (QR Platba) string. */
export function spayd({ iban, amount, vs, message }) {
  const clean = (s) => String(s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/\*/g, '').toUpperCase().slice(0, 60);
  const parts = ['SPD', '1.0', `ACC:${iban}`, `AM:${Number(amount).toFixed(2)}`, 'CC:CZK'];
  if (vs) parts.push(`X-VS:${String(vs).replace(/\D/g, '').slice(0, 10)}`);
  if (message) parts.push(`MSG:${clean(message)}`);
  return parts.join('*');
}
