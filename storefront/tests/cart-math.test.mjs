import test from 'node:test';
import assert from 'node:assert/strict';
import { MAX_QTY, addGuestRow, cartAdd, cartSetQty, lineTotal, removeGuestRow, setGuestQty, sumMoney } from '../src/lib/cartMath.ts';

const fmt = (v, cur) => `${cur} ${v.toLocaleString('en-GB', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
const M = (v, cur = 'QAR') => ({ amount: v.toFixed(2), currency: cur, formatted: `${cur} ${v}` });
const price = (v) => ({ regular: M(v), sale: null, final: M(v), discount_percent: 0, sale_ends_at: null, source: 'product' });
const product = (id) => ({ id, slug: `p-${id}`, name: `Product ${id}`, brand: { id: 'b', name: 'Nordic' }, image: `/img/${id}.jpg` });
const row = (v, unit = 100) => ({ variant_id: v, qty: 1, product_id: v, slug: v, name: v, variant_name: null, image: null, brand: null, unit: M(unit) });

/* ───────────── guest cart ───────────── */
test('guest: the same variant added again grows the quantity, never a second row', () => {
  let rows = addGuestRow([], row('a'), 1);
  rows = addGuestRow(rows, row('a'), 1);
  assert.equal(rows.length, 1);
  assert.equal(rows[0].qty, 2);
  rows = addGuestRow(rows, row('b'), 1);
  assert.deepEqual(rows.map((r) => [r.variant_id, r.qty]), [['a', 2], ['b', 1]]);
});
test('guest: quantity is capped and the input is not mutated', () => {
  const start = [{ ...row('a'), qty: MAX_QTY - 1 }];
  const next = addGuestRow(start, row('a'), 5);
  assert.equal(next[0].qty, MAX_QTY);
  assert.equal(start[0].qty, MAX_QTY - 1);
  assert.equal(addGuestRow([], row('z'), 99)[0].qty, MAX_QTY);
  assert.deepEqual(addGuestRow([], row('z'), 0), []);
});
test('guest: setting zero removes the row, removeGuestRow drops it', () => {
  const rows = [{ ...row('a'), qty: 3 }, row('b')];
  assert.deepEqual(setGuestQty(rows, 'a', 0).map((r) => r.variant_id), ['b']);
  assert.equal(setGuestQty(rows, 'a', 5)[0].qty, 5);
  assert.equal(setGuestQty(rows, 'a', 500)[0].qty, MAX_QTY);
  assert.deepEqual(removeGuestRow(rows, 'b').map((r) => r.variant_id), ['a']);
});

/* ───────────── money ───────────── */
test('lineTotal and sumMoney use two decimals and keep the currency', () => {
  assert.equal(lineTotal(M(19.99), 3, fmt).amount, '59.97');
  assert.equal(lineTotal(M(1450), 2, fmt).formatted, 'QAR 2,900.00');
  const s = sumMoney([M(10.1), M(20.2)], 'QAR', fmt);
  assert.equal(s.amount, '30.30');
  assert.equal(sumMoney([], 'QAR', fmt).amount, '0.00');
  assert.match(lineTotal(M(5), 2, (v, c) => `${v.toFixed(2)} ${c === 'QAR' ? 'ر.ق' : c}`).formatted, /ر\.ق/);
});

/* ───────────── signed-in cart, before the server answers ───────────── */
test('cartAdd on an empty cart creates one line, the badge count and the subtotal at once', () => {
  const c = cartAdd(null, { variantId: 'v1', qty: 1, variantName: null, price: price(1450), product: product('1') }, fmt);
  assert.equal(c.items.length, 1);
  assert.equal(c.items_count, 1);
  assert.equal(c.subtotal.formatted, 'QAR 1,450.00');
  assert.equal(c.items[0].product.image.thumb_url, '/img/1.jpg');
  assert.equal(c.items[0].line_total.amount, '1450.00');
});
test('cartAdd of the same variant again makes quantity 2, not a second line', () => {
  const n = { variantId: 'v1', qty: 1, variantName: null, price: price(1450), product: product('1') };
  const once = cartAdd(null, n, fmt);
  const twice = cartAdd(once, n, fmt);
  assert.equal(twice.items.length, 1);
  assert.equal(twice.items[0].quantity, 2);
  assert.equal(twice.items_count, 2);
  assert.equal(twice.subtotal.amount, '2900.00');
  assert.equal(once.items[0].quantity, 1, 'the previous cart is not mutated');
});
test('cartAdd keeps other lines and respects the stock the server reported', () => {
  const a = cartAdd(null, { variantId: 'a', qty: 1, variantName: null, price: price(100), product: product('a') }, fmt);
  const withStock = { ...a, items: a.items.map((i) => ({ ...i, available_quantity: 3 })) };
  const b = cartAdd(withStock, { variantId: 'b', qty: 2, variantName: 'Oak', price: price(50), product: product('b') }, fmt);
  assert.deepEqual(b.items.map((i) => [i.variant_id, i.quantity]), [['a', 1], ['b', 2]]);
  assert.equal(b.subtotal.amount, '200.00');
  const capped = cartAdd(withStock, { variantId: 'a', qty: 9, variantName: null, price: price(100), product: product('a') }, fmt);
  assert.equal(capped.items[0].quantity, 3);
});
test('cartSetQty changes the line and subtotal; zero removes the line; a missing cart stays missing', () => {
  let c = cartAdd(null, { variantId: 'a', qty: 1, variantName: null, price: price(100), product: product('a') }, fmt);
  c = cartAdd(c, { variantId: 'b', qty: 1, variantName: null, price: price(40), product: product('b') }, fmt);
  const up = cartSetQty(c, 'a', 4, fmt);
  assert.equal(up.items_count, 5);
  assert.equal(up.subtotal.amount, '440.00');
  const down = cartSetQty(up, 'a', 0, fmt);
  assert.deepEqual(down.items.map((i) => i.variant_id), ['b']);
  assert.equal(down.subtotal.amount, '40.00');
  assert.equal(cartSetQty(null, 'a', 2, fmt), null);
  const gone = cartSetQty(down, 'b', 0, fmt);
  assert.equal(gone.items_count, 0);
  assert.equal(gone.subtotal.amount, '0.00');
});
