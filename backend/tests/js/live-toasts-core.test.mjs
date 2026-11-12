import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createStack, normalise, toneFor, fill, MAX_VISIBLE } from '../../resources/js/live-toasts-core.js';

const raw = (over = {}) => ({ id: 'e1', type: 'placed', order_id: 'o1', number: 'AB-1', status: 'confirmed', total: '120.00', currency: 'QAR', ...over });

test('normalise keeps the documented fields and drops malformed frames', () => {
    assert.equal(normalise(null), null);
    assert.equal(normalise({ id: 'x' }), null);
    const e = normalise(raw({ customer: 'Noor', payment_method: 'cod', fulfilment: 'delivery' }));
    assert.deepEqual([e.id, e.type, e.orderId, e.number, e.customer, e.paymentMethod, e.fulfilment], ['e1', 'placed', 'o1', 'AB-1', 'Noor', 'cod', 'delivery']);
    assert.equal(normalise(raw({ type: 'weird' })).type, 'status');
});

test('toneFor: gold bag for new orders, a tone per status otherwise', () => {
    assert.deepEqual(toneFor({ type: 'placed' }), { tone: 'gold', icon: 'bag' });
    assert.equal(toneFor({ type: 'status', status: 'out_for_delivery' }).icon, 'truck');
    assert.equal(toneFor({ type: 'status', status: 'cancelled' }).tone, 'rose');
    assert.equal(toneFor({ type: 'status', status: 'unknown' }).icon, 'bell');
});

test('stack de-duplicates by id', () => {
    const s = createStack();
    assert.equal(s.add(normalise(raw())).shown, true);
    assert.equal(s.add(normalise(raw())).accepted, false);
    assert.equal(s.visible.length, 1);
});

test('stack shows at most MAX_VISIBLE, queues the rest and promotes in order', () => {
    const s = createStack();
    for (let i = 0; i < MAX_VISIBLE + 3; i++) s.add(normalise(raw({ id: `e${i}` })));
    assert.equal(s.visible.length, MAX_VISIBLE);
    assert.equal(s.queued, 3);
    assert.equal(s.remove('e0').id, `e${MAX_VISIBLE}`);
    assert.equal(s.queued, 2);
    assert.equal(s.remove('missing'), null);
    assert.equal(s.visible.length, MAX_VISIBLE);
});

test('fill replaces placeholders', () => {
    assert.equal(fill('+:n more', { n: 3 }), '+3 more');
    assert.equal(fill('for {name}', { name: 'Noor' }), 'for Noor');
});
