import test from 'node:test';
import assert from 'node:assert/strict';
import { promiseKey, promiseLines, promiseView } from '../src/lib/promise.ts';
import { MAX_QTY, addGuestRow, cartAdd, cartSetAssembly, cartSetQty, guestAssemblyTotal, setGuestAssembly, setGuestQty } from '../src/lib/cartMath.ts';
import { productShareText, productShareUrl, whatsappShareHref, basketShareText } from '../src/lib/shareBasket.ts';
import { slotRangeFrom } from '../src/lib/slotPick.ts';
import { en } from '../src/locales/en.ts';
import { ar } from '../src/locales/ar.ts';

// The delivery date promise, assembly in the cart and the WhatsApp share text: all pure, all in the customer's own words.

const t = (key, vars = {}) => `${key}${Object.keys(vars).length ? JSON.stringify(vars) : ''}`;
const parcel = (over = {}) => ({ branch: { id: 'b1', name: 'West Bay' }, type: 'delivery', status: 'scheduled', reason: null, kind: 'day', date: '2026-11-24', message: 'Delivery tomorrow', detail: null, slot: null, items: [], ...over });
const basket = (over = {}) => ({ enabled: true, fulfillment_type: 'delivery', status: 'scheduled', message: 'Delivery tomorrow', detail: null, all_by: null, is_split: false, location_known: true, parcels: [parcel()], ...over });

/* ───────────── what the promise block shows ───────────── */
test('nothing is shown while the switch is off, before an answer, or without a sentence', () => {
  assert.equal(promiseView(null, t), null);
  assert.equal(promiseView(undefined, t), null);
  assert.equal(promiseView(basket({ enabled: false }), t), null);
  assert.equal(promiseView(basket({ status: 'disabled' }), t), null);
  assert.equal(promiseView(basket({ message: null }), t), null);
});

test('a promised date is shown as the server wrote it, with its small print', () => {
  const v = promiseView(basket({ message: 'Delivery today before 10 pm', detail: 'if you order by 3 pm' }), t);
  assert.equal(v.tone, 'ok');
  assert.equal(v.headline, 'Delivery today before 10 pm');
  assert.equal(v.detail, 'if you order by 3 pm');
  assert.deepEqual(v.rows, []);
  assert.equal(v.hint, null);
});

test('no date is a calm "we will confirm", never an error, and the address hint is not piled on top of it', () => {
  const v = promiseView(basket({ status: 'unconfirmed', message: 'We will confirm your delivery date', detail: 'Not in stock right now.', location_known: false, parcels: [parcel({ status: 'unconfirmed', date: null })] }), t);
  assert.equal(v.tone, 'soft');
  assert.equal(v.headline, 'We will confirm your delivery date');
  assert.equal(v.detail, 'Not in stock right now.');
  assert.equal(v.hint, null);
});

test('without an address a delivery date says it depends on it; pickup never does', () => {
  assert.equal(promiseView(basket({ location_known: false }), t).hint, 'promise.address_hint');
  assert.equal(promiseView(basket({ fulfillment_type: 'pickup', location_known: false }), t).hint, null);
});

test('a basket that splits shows one row per parcel and the overall line on top', () => {
  const p = basket({ is_split: true, message: 'All delivered by Tue 24 Nov', all_by: { date: '2026-11-24', label: 'Tue 24 Nov' }, parcels: [
    parcel({ branch: { id: 'a', name: 'West Bay' }, message: 'Delivery today' }),
    parcel({ branch: { id: 'b', name: 'Al Wakra' }, message: 'Delivery Tue 24 Nov' }),
    parcel({ branch: { id: 'c', name: 'Lusail' }, status: 'unconfirmed', message: 'We will confirm your delivery date' }),
  ] });
  const v = promiseView(p, t);
  assert.equal(v.headline, 'All delivered by Tue 24 Nov');
  assert.deepEqual(v.rows.map((r) => [r.branch, r.text, r.ok]), [['West Bay', 'Delivery today', true], ['Al Wakra', 'Delivery Tue 24 Nov', true], ['Lusail', 'promise.confirm_delivery', false]]);
  // one parcel, even when flagged split by an older server, is just the headline
  assert.deepEqual(promiseView(basket({ is_split: true }), t).rows, []);
});

test('the basket request carries identifiers and quantities only, drops empty lines, and keys the same basket the same way', () => {
  const lines = promiseLines([{ variantId: 'v2', qty: 2 }, { variantId: 'v1', qty: 1, assembly: true }, { variantId: 'v3', qty: 0 }]);
  assert.deepEqual(lines, [{ variant_id: 'v2', quantity: 2 }, { variant_id: 'v1', quantity: 1, assembly: true }]);
  assert.equal(promiseKey(lines), promiseKey([...lines].reverse()));
  assert.notEqual(promiseKey(lines), promiseKey([{ variant_id: 'v2', quantity: 3 }, { variant_id: 'v1', quantity: 1, assembly: true }]));
  assert.ok(!JSON.stringify(lines).includes('price'));
});

/* ───────────── the picker starts where the items can be ready ───────────── */
test('the time picker starts at the earliest date for made-to-order items and never past what the server offers', () => {
  const now = Date.UTC(2026, 10, 23, 9, 0); // 12:00 in Qatar, Monday 23 Nov
  assert.deepEqual(slotRangeFrom(now), { from: '2026-11-23', to: '2026-12-06' });
  assert.deepEqual(slotRangeFrom(now, '2026-11-26'), { from: '2026-11-26', to: '2026-12-09' });
  assert.equal(slotRangeFrom(now, '2026-11-20').from, '2026-11-23'); // an earlier date than today changes nothing
  const far = slotRangeFrom(now, '2026-12-20'); // 27 days ahead: the list is cut at the server's last day (today + 29)
  assert.deepEqual(far, { from: '2026-12-20', to: '2026-12-22' });
  assert.ok(slotRangeFrom(now, '2027-02-01').from > slotRangeFrom(now, '2027-02-01').to); // nothing can be offered
});

/* ───────────── assembly in a guest's browser cart ───────────── */
const M = (v, cur = 'QAR') => ({ amount: Number(v).toFixed(2), currency: cur, formatted: `${cur} ${Number(v).toFixed(2)}` });
const fmt = (v, cur) => `${cur} ${v.toFixed(2)}`;
const price = (v) => ({ regular: M(v), sale: null, final: M(v), discount_percent: 0, sale_ends_at: null, source: 'product' });
const grow = (v, extra = {}) => ({ variant_id: v, qty: 1, product_id: v, slug: v, name: v, variant_name: null, image: null, brand: null, unit: M(1000), ...extra });

test('guest: assembly can only be ticked on a row whose product offers it, and totals fee x units', () => {
  let rows = [grow('sofa', { qty: 3, assembly_fee: M(100) }), grow('lamp', { qty: 1 })];
  rows = setGuestAssembly(rows, 'lamp', true);
  assert.equal(rows[1].assembly, undefined);
  rows = setGuestAssembly(rows, 'sofa', true);
  assert.equal(rows[0].assembly, true);
  assert.equal(guestAssemblyTotal(rows, fmt).amount, '300.00');
  rows = setGuestQty(rows, 'sofa', 2);
  assert.equal(guestAssemblyTotal(rows, fmt).amount, '200.00');
  assert.equal(guestAssemblyTotal(setGuestAssembly(rows, 'sofa', false), fmt), null);
});

test('guest: a row added again keeps its fee and its choice', () => {
  const rows = addGuestRow([grow('sofa', { assembly_fee: M(100), assembly: true })], grow('sofa', { assembly_fee: M(100), assembly: true }), 1);
  assert.equal(rows.length, 1);
  assert.equal(rows[0].qty, 2);
  assert.equal(rows[0].assembly, true);
  assert.equal(guestAssemblyTotal(rows, fmt).amount, '200.00');
});

test('guest: an awkward fee has no rounding to argue about', () => {
  assert.equal(guestAssemblyTotal([grow('x', { qty: 3, assembly_fee: M('33.33'), assembly: true })], fmt).amount, '99.99');
});

/* ───────────── assembly in the account cart (shown before the server answers) ───────────── */
const product = { id: 'p', slug: 'p', name: 'Sofa', brand: null, image: null };
const addSofa = (cart, qty, asm) => cartAdd(cart, { variantId: 'v', qty, variantName: null, price: price(1000), assembly: asm, product }, fmt);

test('account cart: a line added with its fee offers assembly, and ticking it adds fee x units to the total at once', () => {
  let c = addSofa(null, 2, { fee: M(100), selected: false });
  assert.equal(c.items[0].assembly.selected, false);
  assert.equal(c.assembly_total.amount, '0.00');
  c = cartSetAssembly(c, 'v', true, fmt);
  assert.equal(c.items[0].assembly.amount.amount, '200.00');
  assert.equal(c.assembly_total.amount, '200.00');
  assert.equal(c.subtotal.amount, '2000.00'); // goods only
  c = cartSetQty(c, 'v', 3, fmt);
  assert.equal(c.assembly_total.amount, '300.00');
  assert.equal(cartSetAssembly(c, 'v', false, fmt).assembly_total.amount, '0.00');
});

test('account cart: adding more without the tick never turns the choice off; adding with it turns it on', () => {
  let c = addSofa(null, 1, { fee: M(100), selected: true });
  c = addSofa(c, 1, { fee: M(100), selected: false });
  assert.equal(c.items[0].assembly.selected, true);
  assert.equal(c.assembly_total.amount, '200.00');
  let d = addSofa(null, 1, { fee: M(100), selected: false });
  d = addSofa(d, 1, { fee: M(100), selected: true });
  assert.equal(d.assembly_total.amount, '200.00');
});

test('account cart: a product without assembly stays without it, and a pickup cart is not charged', () => {
  const plain = cartSetAssembly(addSofa(null, 1, null), 'v', true, fmt);
  assert.equal(plain.items[0].assembly, null);
  assert.equal(plain.assembly_total.amount, '0.00');
  let c = addSofa(null, 1, { fee: M(100), selected: true });
  c = cartSetQty({ ...c, fulfillment_type: 'pickup' }, 'v', 2, fmt);
  assert.equal(c.assembly_total.amount, '0.00');
  assert.equal(c.items[0].assembly.selected, true); // the tick is kept for when they switch back
});

test('quantity limits still hold with assembly', () => {
  const c = cartSetQty(addSofa(null, 1, { fee: M(100), selected: true }), 'v', 999, fmt);
  assert.equal(c.items[0].quantity, MAX_QTY);
  assert.equal(c.assembly_total.amount, `${(100 * MAX_QTY).toFixed(2)}`);
});

/* ───────────── the WhatsApp message ───────────── */
test('a product share is a short bilingual-ready message with a plain link and nothing personal', () => {
  const url = productShareUrl('https://albahar.qa/', 'oslo-3-seater-sofa');
  assert.equal(url, 'https://albahar.qa/products/oslo-3-seater-sofa');
  const text = productShareText({ name: 'Oslo 3-Seater Sofa', variantName: 'Grey', price: 'QAR 4,500.00' }, { intro: 'Take a look:', price: 'Price: QAR 4,500.00' }, url);
  assert.equal(text, 'Take a look:\n\n*Oslo 3-Seater Sofa (Grey)*\nPrice: QAR 4,500.00\n\nhttps://albahar.qa/products/oslo-3-seater-sofa');
  assert.ok(text.endsWith(url));
  const href = whatsappShareHref(text);
  assert.ok(href.startsWith('https://wa.me/?text='));
  assert.equal(decodeURIComponent(href.slice('https://wa.me/?text='.length)), text);
});

test('the share link carries no query, no tracking and no hash, whatever the origin or slug looks like', () => {
  const url = productShareUrl('https://albahar.qa///', 'كنب-أوسلو');
  assert.equal(url, `https://albahar.qa/products/${encodeURIComponent('كنب-أوسلو')}`);
  assert.ok(!url.includes('?') && !url.includes('#'));
  assert.ok(!productShareUrl('https://albahar.qa', 'a b?x=1').includes('?x=1'));
});

test('a product with no price or no option still reads well, and Arabic text survives the link', () => {
  const text = productShareText({ name: 'كنب أوسلو' }, { intro: 'شاهد هذا:', price: null }, 'https://albahar.qa/products/x');
  assert.equal(text, 'شاهد هذا:\n\n*كنب أوسلو*\n\nhttps://albahar.qa/products/x');
  assert.equal(decodeURIComponent(whatsappShareHref(text).slice('https://wa.me/?text='.length)), text);
});

test('the basket share still lists each piece with its link and the total', () => {
  const text = basketShareText([{ name: 'Sofa', qty: 2, slug: 'sofa' }, { name: 'Lamp', variantName: 'Brass', qty: 1 }], { intro: 'Hello', total: 'Total: QAR 2,250.00' }, 'https://albahar.qa');
  assert.ok(text.startsWith('Hello\n\n• Sofa × 2\n  https://albahar.qa/products/sofa'));
  assert.ok(text.includes('• Lamp (Brass) × 1'));
  assert.ok(text.endsWith('Total: QAR 2,250.00'));
});

/* ───────────── words in both languages ───────────── */
test('every promise, assembly and share text exists in English and Arabic, with the same placeholders', () => {
  const keys = Object.keys(en).filter((k) => /^(promise\.(confirm|address|pickup_heading)|assembly\.|share\.|cart\.assembly|checkout\.assembly|order\.assembly|order\.total\.assembly)/.test(k));
  assert.ok(keys.length >= 19);
  const vars = (s) => [...s.matchAll(/\{(\w+)\}/g)].map((m) => m[1]).sort().join(',');
  for (const k of keys) {
    assert.ok(typeof ar[k] === 'string' && ar[k].length > 0, `missing Arabic text for ${k}`);
    assert.equal(vars(ar[k]), vars(en[k]), `different placeholders in ${k}`);
  }
  for (const k of Object.keys(ar).filter((x) => /^(assembly\.|share\.)/.test(x))) assert.ok(en[k], `missing English text for ${k}`);
});
