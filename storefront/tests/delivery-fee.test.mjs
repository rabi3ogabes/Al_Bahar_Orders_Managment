import test from 'node:test';
import assert from 'node:assert/strict';
import { deliveryFeeLine, extraDelivery, extraItemCaption, extraMode, parseMoney, productExtraFee, productExtraFeeText } from '../src/lib/deliveryFee.ts';
import { en } from '../src/locales/en.ts';
import { ar } from '../src/locales/ar.ts';

// The delivery fee line of the order summary: always there, free only with a free-delivery coupon (no "spend X more" rule).

const fmt = (v, cur) => `${cur} ${v.toFixed(2)}`;
const M = (v, cur = 'QAR') => ({ amount: Number(v).toFixed(2), currency: cur, formatted: `${cur} ${Number(v).toFixed(2)}` });
const quote = ({ fee = 15, discount = 0, discounts = [], type = 'delivery' } = {}) => ({ fulfillment_type: type, totals: { delivery_fee: M(fee), discount: M(discount) }, discounts });
const freeCode = (code = 'SHIP4U') => ({ code, label: 'Free delivery', amount: M(15), free_delivery: true });

test('before there is a quote the line still shows, saying the fee comes at checkout', () => {
  for (const q of [null, undefined]) assert.deepEqual(deliveryFeeLine(q, fmt), { kind: 'pending', fee: null, code: null, pickup: false, discount: null });
});

test('a delivery order pays its fee, however large the basket is (there is no free-above rule)', () => {
  const r = deliveryFeeLine(quote({ fee: 15 }), fmt);
  assert.equal(r.kind, 'fee');
  assert.equal(r.fee.formatted, 'QAR 15.00');
  assert.equal(r.code, null);
  assert.equal(r.discount, null);
});

test('a discount that is not a free-delivery coupon stays in the discount row as the server sent it', () => {
  const q = quote({ fee: 15, discount: 20, discounts: [{ code: 'TEN', label: '10% off', amount: M(20) }] });
  const r = deliveryFeeLine(q, fmt);
  assert.equal(r.kind, 'fee');
  assert.equal(r.discount, q.totals.discount);
});

test('a free-delivery coupon shows the fee struck through with its code, and the discount row no longer counts the fee', () => {
  const r = deliveryFeeLine(quote({ fee: 15, discount: 15, discounts: [freeCode()] }), fmt);
  assert.equal(r.kind, 'free_coupon');
  assert.equal(r.fee.formatted, 'QAR 15.00');
  assert.equal(r.code, 'SHIP4U');
  assert.equal(r.discount, null);
});

test('free delivery together with another discount: only the other discount is left in the discount row', () => {
  const r = deliveryFeeLine(quote({ fee: 15, discount: 35, discounts: [freeCode('BOTH')] }), fmt);
  assert.equal(r.kind, 'free_coupon');
  assert.equal(r.discount.amount, '20.00');
  assert.equal(r.discount.formatted, 'QAR 20.00');
  assert.equal(r.discount.currency, 'QAR');
});

test('the fee carries surcharges: the whole fee is struck through and taken out of the discount', () => {
  const r = deliveryFeeLine(quote({ fee: 27.5, discount: 27.5, discounts: [freeCode()] }), fmt);
  assert.equal(r.fee.formatted, 'QAR 27.50');
  assert.equal(r.discount, null);
});

test('a discount smaller than the fee never goes negative', () => {
  const r = deliveryFeeLine(quote({ fee: 15, discount: 5, discounts: [freeCode()] }), fmt);
  assert.equal(r.kind, 'free_coupon');
  assert.equal(r.discount, null);
});

test('a coupon without a code in the payload still shows Free, with no code line', () => {
  const r = deliveryFeeLine(quote({ discount: 15, discounts: [{ ...freeCode(), code: undefined }] }), fmt);
  assert.equal(r.kind, 'free_coupon');
  assert.equal(r.code, null);
  assert.equal(deliveryFeeLine(quote({ discount: 15, discounts: [{ ...freeCode(), code: '  ' }] }), fmt).code, null);
});

test('pickup, or a zone without a fee, has nothing to pay and says Free, even with a free-delivery coupon on the order', () => {
  const pick = deliveryFeeLine(quote({ fee: 0, type: 'pickup' }), fmt);
  assert.equal(pick.kind, 'zero');
  assert.equal(pick.pickup, true);
  const none = deliveryFeeLine(quote({ fee: 0 }), fmt);
  assert.equal(none.kind, 'zero');
  assert.equal(none.pickup, false);
  const coupon = deliveryFeeLine(quote({ fee: 0, discount: 0, discounts: [freeCode()] }), fmt);
  assert.equal(coupon.kind, 'zero');
  assert.equal(coupon.discount, null);
});

test('only an explicit free_delivery: true counts (a missing flag, or an older server, is an ordinary discount)', () => {
  for (const flag of [undefined, false, null, 'true', 1]) {
    const r = deliveryFeeLine(quote({ fee: 15, discount: 15, discounts: [{ code: 'X', amount: M(15), free_delivery: flag }] }), fmt);
    assert.equal(r.kind, 'fee', String(flag));
  }
  assert.equal(deliveryFeeLine({ fulfillment_type: 'delivery', totals: { delivery_fee: M(15), discount: M(0) } }, fmt).kind, 'fee');
});

test('amounts that arrive as strings with rounding noise still compare in cents', () => {
  const r = deliveryFeeLine(quote({ fee: 15, discount: 15.004, discounts: [freeCode()] }), fmt);
  assert.equal(r.discount, null);
});

/* ───────────── extra delivery fee of bulky products (docs/api/CHECKOUT_FEES_V2.md, section 3) ───────────── */

// The real dictionaries, so the wording a customer reads is what is tested.
const tr = (dict) => (key, vars = {}) => Object.entries(vars).reduce((out, [k, v]) => out.replaceAll(`{${k}}`, String(v)), dict[key] ?? key);
const tEn = tr(en);
const tAr = tr(ar);
const arFmt = (v, cur) => `${v.toFixed(2)} ${cur === 'QAR' ? 'ر.ق' : cur}`;
const row = (o = {}) => ({ product_id: 'p1', name: 'Marble coffee table', mode: 'per_unit', quantity: 3, fee_each: M(50), amount: M(150), ...o });
const withExtra = ({ extra = 100, extras, fee = 130, ...rest } = {}) => ({ ...quote({ fee, ...rest }), totals: { delivery_fee: M(fee), discount: M(rest.discount ?? 0), extra_delivery_fee: M(extra) }, ...(extras === undefined ? {} : { delivery_extras: extras }) });
const withoutExtraField = () => { const q = withExtra({ extras: [row()] }); delete q.totals.extra_delivery_fee; return q; };

test('parseMoney: the usual money object keeps the server wording; a bare decimal is formatted by the page', () => {
  assert.deepEqual(parseMoney({ amount: '50.00', currency: 'QAR', formatted: 'QAR 50.00' }, 'USD', fmt), { amount: '50.00', currency: 'QAR', formatted: 'QAR 50.00' });
  assert.deepEqual(parseMoney('50.00', 'QAR', fmt), { amount: '50.00', currency: 'QAR', formatted: 'QAR 50.00' });
  assert.deepEqual(parseMoney(12.5, 'QAR', fmt), { amount: '12.50', currency: 'QAR', formatted: 'QAR 12.50' });
  assert.deepEqual(parseMoney({ amount: '7', currency: 'QAR', formatted: '' }, 'QAR', fmt), { amount: '7.00', currency: 'QAR', formatted: 'QAR 7.00' });
  assert.deepEqual(parseMoney(' 50 ', 'QAR', fmt)?.amount, '50.00');
});

test('parseMoney: anything that is not a number is nothing', () => {
  for (const v of [null, undefined, '', 'abc', NaN, Infinity, {}, { amount: 'x' }, { currency: 'QAR' }]) assert.equal(parseMoney(v, 'QAR', fmt), null, String(JSON.stringify(v)));
});

test('the extra mode is per_line only when the server says so; the default and anything odd is per_unit', () => {
  assert.equal(extraMode('per_line'), 'per_line');
  for (const m of ['per_unit', undefined, null, '', 'PER_LINE', 'line', 3]) assert.equal(extraMode(m), 'per_unit', String(m));
});

test('no extra line: no quote, an older server (fields missing), zero, junk, or pickup', () => {
  assert.equal(extraDelivery(null, fmt), null);
  assert.equal(extraDelivery(undefined, fmt), null);
  assert.equal(extraDelivery(quote({ fee: 15 }), fmt), null, 'older server: no extra fields at all');
  assert.equal(extraDelivery(withoutExtraField(), fmt), null, 'rows without the total: nothing is shown');
  assert.equal(extraDelivery(withExtra({ extra: 0, extras: [row()] }), fmt), null, 'a zero fee is hidden even if rows came with it');
  assert.equal(extraDelivery({ ...withExtra({ extra: 0 }), totals: { delivery_fee: M(15), discount: M(0), extra_delivery_fee: '0.00' } }, fmt), null);
  assert.equal(extraDelivery({ ...withExtra({ extra: 0 }), totals: { delivery_fee: M(15), discount: M(0), extra_delivery_fee: 'n/a' } }, fmt), null);
  assert.equal(extraDelivery({ ...withExtra({ extra: 0 }), totals: { delivery_fee: M(15), discount: M(0), extra_delivery_fee: M(-5) } }, fmt), null);
  assert.equal(extraDelivery(withExtra({ extra: 100, type: 'pickup', fee: 0 }), fmt), null, 'pickup never shows a delivery extra');
});

test('an extra fee with no detail still shows its total (the list is optional)', () => {
  for (const extras of [undefined, null, [], 'nope']) {
    const e = extraDelivery(withExtra({ extra: 100, extras }), fmt);
    assert.equal(e.amount.formatted, 'QAR 100.00', String(extras));
    assert.deepEqual(e.items, []);
    assert.equal(e.waived, false);
  }
});

test('the delivery line keeps the gross total, extra included', () => {
  const q = withExtra({ fee: 130, extra: 100, extras: [row()] });
  const line = deliveryFeeLine(q, fmt);
  assert.equal(line.kind, 'fee');
  assert.equal(line.fee.formatted, 'QAR 130.00');
  assert.equal(extraDelivery(q, fmt).amount.formatted, 'QAR 100.00');
});

test('the detail rows: per unit shows the quantity and the fee each, per line is charged once', () => {
  const e = extraDelivery(withExtra({ extra: 200, extras: [row(), row({ product_id: 'p2', name: 'Oak wardrobe', mode: 'per_line', quantity: 2, fee_each: M(50), amount: M(50) })] }), fmt);
  assert.equal(e.items.length, 2);
  assert.deepEqual(e.items.map((i) => [i.name, i.mode, i.quantity, i.feeEach.formatted, i.amount.formatted]), [
    ['Marble coffee table', 'per_unit', 3, 'QAR 50.00', 'QAR 150.00'],
    ['Oak wardrobe', 'per_line', 2, 'QAR 50.00', 'QAR 50.00'],
  ]);
  assert.equal(extraItemCaption(e.items[0], tEn), '×3 · QAR 50.00 each');
  assert.equal(extraItemCaption(e.items[1], tEn), 'charged once');
  assert.equal(extraItemCaption(e.items[0], tAr), '×3 · QAR 50.00 للقطعة');
  assert.equal(extraItemCaption(e.items[1], tAr), 'تُحتسب مرة واحدة');
});

test('the detail rows read the server in every shape and drop what cannot be shown', () => {
  const e = extraDelivery(withExtra({ extra: 100, extras: [
    row({ name: '  Sofa  ', quantity: '2', fee_each: '25.00', amount: '50.00' }), // bare strings, a padded name, a quantity sent as text
    row({ product_id: undefined, name: 'No fee each', quantity: 4, fee_each: undefined, amount: M(40) }), // fee each worked out: 40 / 4
    row({ name: 'Once', mode: 'per_line', quantity: 3, fee_each: null, amount: M(30) }), // per line: the fee each is the amount
    row({ name: 'Odd mode', mode: 'weird', quantity: 0, fee_each: M(10), amount: M(10) }), // unknown mode is per unit, quantity is at least 1
    row({ name: '', amount: M(5) }), // no name: nothing to say
    row({ name: 'Free', amount: M(0) }), // nothing to pay
    row({ name: 'Broken', amount: 'x' }),
    null, 'text', 5,
  ] }), fmt);
  assert.deepEqual(e.items.map((i) => [i.name, i.mode, i.quantity, i.feeEach.amount, i.amount.amount]), [
    ['Sofa', 'per_unit', 2, '25.00', '50.00'],
    ['No fee each', 'per_unit', 4, '10.00', '40.00'],
    ['Once', 'per_line', 3, '30.00', '30.00'],
    ['Odd mode', 'per_unit', 1, '10.00', '10.00'],
  ]);
  assert.equal(e.items[1].productId, null);
  assert.equal(e.items[0].productId, 'p1');
});

test('a split order lists a product once for each showroom that carries it', () => {
  const e = extraDelivery(withExtra({ extra: 100, extras: [row({ branch_id: 'a', amount: M(50), quantity: 1 }), row({ branch_id: 'b', amount: M(50), quantity: 1 })] }), fmt);
  assert.equal(e.items.length, 2);
});

test('a free-delivery coupon waives the whole fee, the extra included: one struck-through delivery line and no leftover discount', () => {
  const q = { ...withExtra({ fee: 130, extra: 100, extras: [row()], discount: 130, discounts: [freeCode()] }) };
  const line = deliveryFeeLine(q, fmt);
  assert.equal(line.kind, 'free_coupon');
  assert.equal(line.fee.formatted, 'QAR 130.00', 'the struck-through fee is the gross, extra included');
  assert.equal(line.discount, null, 'the discount row no longer counts any of the delivery fee');
  const e = extraDelivery(q, fmt);
  assert.equal(e.amount.formatted, 'QAR 100.00');
  assert.equal(e.waived, true);
});

test('another discount next to free delivery stays in the discount row; the extra is still waived with the rest of the fee', () => {
  const q = withExtra({ fee: 130, extra: 100, extras: [row()], discount: 150, discounts: [freeCode()] });
  assert.equal(deliveryFeeLine(q, fmt).discount.amount, '20.00');
  assert.equal(extraDelivery(q, fmt).waived, true);
});

test('an ordinary coupon does not waive the extra fee', () => {
  const q = withExtra({ fee: 130, extra: 100, extras: [row()], discount: 20, discounts: [{ code: 'TEN', amount: M(20) }] });
  assert.equal(deliveryFeeLine(q, fmt).kind, 'fee');
  assert.equal(extraDelivery(q, fmt).waived, false);
});

test('product note: nothing when the fee is zero, missing, junk or negative', () => {
  const none = [{}, { extra_delivery_fee: null }, { extra_delivery_fee: '0.00' }, { extra_delivery_fee: 0 }, { extra_delivery_fee: M(0) }, { extra_delivery_fee: '' }, { extra_delivery_fee: 'free' }, { extra_delivery_fee: '-5.00' }];
  for (const p of none) assert.equal(productExtraFee(p, fmt), null, JSON.stringify(p));
  assert.equal(productExtraFee(null, fmt), null);
  assert.equal(productExtraFee(undefined, fmt), null);
});

test('product note wording: per unit says "per item", per line says "for this item, whatever the quantity"', () => {
  const unit = productExtraFee({ extra_delivery_fee: '50.00', extra_delivery_fee_mode: 'per_unit', price: { final: M(900) } }, fmt);
  const line = productExtraFee({ extra_delivery_fee: '50.00', extra_delivery_fee_mode: 'per_line', price: { final: M(900) } }, fmt);
  assert.equal(productExtraFeeText(unit, tEn), 'Extra delivery fee QAR 50.00 per item');
  assert.equal(productExtraFeeText(line, tEn), 'Extra delivery fee QAR 50.00 for this item, whatever the quantity');
  const arUnit = productExtraFee({ extra_delivery_fee: '50.00', price: { final: M(900) } }, arFmt);
  const arLine = productExtraFee({ extra_delivery_fee: '50.00', extra_delivery_fee_mode: 'per_line', price: { final: M(900) } }, arFmt);
  assert.equal(productExtraFeeText(arUnit, tAr), 'رسوم توصيل إضافية 50.00 ر.ق لكل قطعة');
  assert.equal(productExtraFeeText(arLine, tAr), 'رسوم توصيل إضافية 50.00 ر.ق لهذه القطعة مهما كانت الكمية');
});

test('product note: a missing or odd mode is per unit; the fee may be a string, a number or a money object; the currency follows the price', () => {
  assert.equal(productExtraFee({ extra_delivery_fee: '50.00' }, fmt).mode, 'per_unit');
  assert.equal(productExtraFee({ extra_delivery_fee: '50.00', extra_delivery_fee_mode: 'banana' }, fmt).mode, 'per_unit');
  assert.equal(productExtraFee({ extra_delivery_fee: 75 }, fmt).fee.formatted, 'QAR 75.00');
  assert.equal(productExtraFee({ extra_delivery_fee: M(75, 'USD') }, fmt).fee.formatted, 'USD 75.00');
  assert.equal(productExtraFee({ extra_delivery_fee: '75.5', price: { final: M(10, 'USD') } }, fmt).fee.formatted, 'USD 75.50');
});
