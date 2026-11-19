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

import { asNewOrderRaw, createBurstGate } from '../../resources/js/live-toasts-core.js';

test('asNewOrderRaw: a valid placed order is new at once, an unpaid online order only once it is paid', () => {
    assert.equal(asNewOrderRaw(raw({ type: 'placed', status: 'pending_payment' })), null);
    const placed = asNewOrderRaw(raw({ type: 'placed', status: 'confirmed', order_id: 'o9' }));
    assert.deepEqual([placed.type, placed.id, placed.order_id], ['placed', 'new:o9', 'o9']);
    const paid = asNewOrderRaw(raw({ id: 'status:7', type: 'status', previous: 'pending_payment', status: 'confirmed', order_id: 'o9' }));
    assert.deepEqual([paid.type, paid.id], ['placed', 'new:o9']); // the same id, so the same order is never announced twice
    assert.equal(asNewOrderRaw(raw({ type: 'status', previous: 'confirmed', status: 'processing' })), null);
    assert.equal(asNewOrderRaw(raw({ type: 'status', previous: 'pending_payment', status: 'cancelled' })), null);
    assert.equal(asNewOrderRaw({ id: 'x' }), null);
});

test('burst frames normalise to one "N new orders" toast and only for two or more', () => {
    const e = normalise({ id: 'burst:a:3', type: 'burst', count: 3 });
    assert.deepEqual([e.type, e.count, e.orderId], ['burst', 3, null]);
    assert.equal(normalise({ id: 'burst:a:1', type: 'burst', count: 1 }), null);
    assert.equal(normalise({ id: 'burst:a', type: 'burst', count: '3' }), null);
    assert.deepEqual(toneFor(e), { tone: 'gold', icon: 'bag' });
});

function fakeClock() {
    let t = 1_000_000; let pending = null;
    return {
        now: () => t,
        setTimer: (f, ms) => { pending = { f, at: t + ms }; return 1; },
        clearTimer: () => { pending = null; },
        advance(ms) { t += ms; if (pending && pending.at <= t) { const f = pending.f; pending = null; f(); } },
        get armed() { return pending !== null; },
    };
}

test('burst gate: the first order shows at once, the others wait for the 3 s window and are collapsed', () => {
    const clock = fakeClock(); const out = [];
    const gate = createBurstGate({ intervalMs: 3000, now: clock.now, setTimer: clock.setTimer, clearTimer: clock.clearTimer, emit: (f) => out.push(f) });
    assert.equal(gate.offer(raw({ id: 'new:1' })), 'shown');
    clock.advance(500);
    assert.equal(gate.offer(raw({ id: 'new:2' })), 'held');
    clock.advance(500);
    assert.equal(gate.offer(raw({ id: 'new:3' })), 'held');
    assert.equal(gate.pending, 2);
    clock.advance(1900);
    assert.equal(out.length, 1); // still inside the window
    clock.advance(200);
    assert.equal(out.length, 2);
    assert.deepEqual([out[1].type, out[1].count], ['burst', 2]);
    assert.equal(gate.pending, 0);
});

test('burst gate: a single held order is shown as itself, and a quiet gap lets the next one through at once', () => {
    const clock = fakeClock(); const out = [];
    const gate = createBurstGate({ now: clock.now, setTimer: clock.setTimer, clearTimer: clock.clearTimer, emit: (f) => out.push(f) });
    gate.offer(raw({ id: 'new:1' }));
    clock.advance(1000);
    gate.offer(raw({ id: 'new:2' }));
    clock.advance(2000);
    assert.deepEqual(out.map((f) => f.id), ['new:1', 'new:2']);
    clock.advance(3000);
    assert.equal(gate.offer(raw({ id: 'new:3' })), 'shown');
    assert.equal(out.length, 3);
    assert.equal(clock.armed, false);
});

test('burst gate: destroy drops what is held and stops the timer', () => {
    const clock = fakeClock(); const out = [];
    const gate = createBurstGate({ now: clock.now, setTimer: clock.setTimer, clearTimer: clock.clearTimer, emit: (f) => out.push(f) });
    gate.offer(raw({ id: 'new:1' })); gate.offer(raw({ id: 'new:2' }));
    gate.destroy();
    clock.advance(10_000);
    assert.equal(out.length, 1);
    assert.equal(clock.armed, false);
});
