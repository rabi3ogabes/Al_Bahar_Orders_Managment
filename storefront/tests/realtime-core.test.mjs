import test from 'node:test';
import assert from 'node:assert/strict';
import { applyOrderEvent, applyOrderEventToCache, createSeqGate } from '../src/lib/realtime-core.ts';

const order = (over = {}) => ({
  id: 'o1', status: 'processing', status_label: 'Processing', payment_status: 'pending', seq: 3, can_cancel: true, can_pay: false,
  fulfillments: [{ id: 'f1', status: 'preparing', status_label: 'Preparing', delivery: { status: 'assigned', driver_name: 'Omar' } }], ...over,
});
const event = (over = {}) => ({ order_id: 'o1', number: 'AB1', status: 'out_for_delivery', status_label: 'On the way', seq: 4, fulfillments: [{ id: 'f1', status: 'dispatched', status_label: 'Dispatched', delivery_status: 'picked_up' }], ...over });

test('the gate refuses a message older than what it has seen and lets an equal or newer one through', () => {
  const g = createSeqGate();
  assert.equal(g.accept('o1', 5), true);
  assert.equal(g.accept('o1', 4), false);
  assert.equal(g.accept('o1', 5), true);
  assert.equal(g.accept('o1', 6), true);
  assert.equal(g.accept('o1', undefined), true);
  g.seen('o2', 9);
  assert.equal(g.accept('o2', 8), false);
  assert.equal(g.accept('o3', 1), true);
});

test('a newer message changes the cached order at once: status, parts, delivery, buttons switched off', () => {
  const next = applyOrderEvent(order(), event());
  assert.equal(next.status, 'out_for_delivery');
  assert.equal(next.status_label, 'On the way');
  assert.equal(next.seq, 4);
  assert.equal(next.fulfillments[0].status, 'dispatched');
  assert.equal(next.fulfillments[0].delivery.status, 'picked_up');
  assert.equal(next.fulfillments[0].delivery.driver_name, 'Omar');
  assert.equal(next.can_cancel, false);
});

test('an older message never overwrites a newer cached order, and another order is left alone', () => {
  const cached = order({ seq: 7 });
  assert.equal(applyOrderEvent(cached, event({ seq: 6 })), cached);
  assert.equal(applyOrderEvent(order({ id: 'o2' }), event()).status, 'processing');
});

test('an order without a version (older server) is always replaced', () => {
  const { seq, ...bare } = order();
  assert.equal(applyOrderEvent(bare, event()).status, 'out_for_delivery');
  void seq;
});

test('the list cache is patched in both shapes, only on the matching row', () => {
  const rows = [order(), order({ id: 'o2' })];
  assert.deepEqual(applyOrderEventToCache({ data: rows }, event()).data.map((r) => r.status), ['out_for_delivery', 'processing']);
  assert.deepEqual(applyOrderEventToCache({ pages: [{ data: rows }] }, event()).pages[0].data.map((r) => r.status), ['out_for_delivery', 'processing']);
  assert.equal(applyOrderEventToCache(undefined, event()), undefined);
});
