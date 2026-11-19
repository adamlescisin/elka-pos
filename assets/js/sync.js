/* Catalogue/customer sync and the offline outbox. */

import { api } from './api.js';
import * as db from './db.js';

export async function loadLocal() {
  const [products, customers, outbox] = await Promise.all([db.all('products'), db.all('customers'), db.all('outbox')]);
  return { products, customers, outbox };
}

/**
 * Full (first run) or delta product sync.
 * @param {function} onProgress (done, total)
 * @returns {Promise<{changed:number, deleted:number[]}>}
 */
export async function syncProducts({ full = false, onProgress = () => {} } = {}) {
  const since = full ? '' : (await db.meta.get('productsSince')) || '';
  let page = 1;
  let pages = 1;
  let changed = 0;
  let deleted = [];
  let serverTime = '';
  if (full) await db.clear('products');
  do {
    const res = await api('products', { query: { page, per_page: 100, modified_after: since }, timeout: 60000 });
    pages = res.pages || 1;
    serverTime = res.serverTime;
    if (res.products.length) await db.putMany('products', res.products);
    changed += res.products.length;
    if (res.deleted && res.deleted.length) {
      deleted = deleted.concat(res.deleted);
      await db.delMany('products', res.deleted);
    }
    onProgress(page, pages, res.total);
    page++;
  } while (page <= pages);
  // Small overlap protects against clock skew between requests.
  if (serverTime) {
    const t = new Date(serverTime.replace(' ', 'T') + 'Z');
    t.setMinutes(t.getMinutes() - 2);
    await db.meta.set('productsSince', t.toISOString().slice(0, 19).replace('T', ' '));
  }
  await db.meta.set('productsSyncedAt', new Date().toISOString());
  return { changed, deleted };
}

const customerKey = (c) => (c.id ? 'u' + c.id : 'm' + (c.email || '').toLowerCase());

export async function syncCustomers({ onProgress = () => {} } = {}) {
  let page = 1;
  let pages = 1;
  const rows = [];
  do {
    const res = await api('customers/all', { query: { page }, timeout: 60000 });
    pages = res.pages || 1;
    res.customers.forEach((c) => rows.push({ ...c, key: customerKey(c) }));
    onProgress(page, pages);
    page++;
  } while (page <= pages);
  await db.clear('customers');
  await db.putMany('customers', rows);
  await db.meta.set('customersSyncedAt', new Date().toISOString());
  return rows;
}

export async function saveCustomer(c) {
  const row = { ...c, key: customerKey(c) };
  await db.put('customers', row);
  return row;
}

/* Outbox ---------------------------------------------------------------- */

export async function enqueue(entry) {
  await db.put('outbox', { ...entry, queuedAt: entry.queuedAt || new Date().toISOString(), attempts: 0 });
}

export async function outbox() {
  const items = await db.all('outbox');
  return items.sort((a, b) => (a.queuedAt < b.queuedAt ? -1 : 1));
}

/**
 * Send queued customers and sales in order. Customers first, so a sale made for a
 * customer created offline is linked to the new account.
 * @returns {Promise<{sent: object[], failed: object[]}>}
 */
export async function flushOutbox() {
  const items = await outbox();
  const sent = [];
  const failed = [];
  const createdCustomers = {};
  for (const item of items) {
    try {
      if (item.kind === 'customer') {
        const c = await api('customers', { method: 'POST', body: item.payload });
        createdCustomers[(item.payload.email || '').toLowerCase()] = c;
        await saveCustomer(c);
        await db.del('outbox', item.uuid);
        sent.push({ item, result: c });
      } else if (item.kind === 'klub') {
        await api('klub/join', { method: 'POST', body: item.payload });
        await db.del('outbox', item.uuid);
        sent.push({ item });
      } else if (item.kind === 'order') {
        const payload = { ...item.payload };
        const email = (payload.customer && payload.customer.email || '').toLowerCase();
        if (payload.customer && !payload.customer.id && createdCustomers[email]) {
          payload.customer = createdCustomers[email];
        }
        const receipt = await api('orders', { method: 'POST', body: payload, timeout: 45000 });
        await db.del('outbox', item.uuid);
        const local = await db.get('receipts', item.uuid);
        if (local) await db.put('receipts', { ...local, synced: true, server: receipt });
        sent.push({ item, result: receipt });
      }
    } catch (e) {
      if (e.network) {
        failed.push({ item, error: e.message, network: true });
        break; // Still offline – keep order of the queue.
      }
      // Rejected by the server (e.g. product deleted). Keep it, flag it for the cashier.
      item.attempts = (item.attempts || 0) + 1;
      item.lastError = e.message;
      await db.put('outbox', item);
      failed.push({ item, error: e.message, network: false });
    }
  }
  return { sent, failed };
}
