import test from 'node:test';
import assert from 'node:assert/strict';
import { deliveryFeeLine } from '../src/lib/deliveryFee.ts';

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
