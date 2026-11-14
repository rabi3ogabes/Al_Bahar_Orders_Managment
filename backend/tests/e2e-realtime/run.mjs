// Real-time order status, end to end against a running local stack (see README.md).
//
// Three actors with real WebSockets: the customer (their own channel), the branch staff member (the branch channel) and the
// driver (their own channel), plus a staff member of ANOTHER branch who must hear nothing. The script places orders through the
// API and drives them through every delivery step as the right actor, timing every event from the moment the request leaves
// to the moment the socket delivers it, and checks the contract (seq grows, payloads carry what the apps need, nothing leaks).
//
// Usage: node tests/e2e-realtime/run.mjs        (BASE, WS_HOST, WS_PORT, WS_KEY, OUT, see lib.mjs)
import { writeFileSync } from 'node:fs';
import { Actor, api, fmt, loginCustomer, loginStaff, median, must, now, proofForm, sleep } from './lib.mjs';

const MODE = process.env.MODE ?? 'auto'; // auto | legacy (the checks of the new contract are skipped)
const SETTLE = Number(process.env.SETTLE ?? 600); // quiet time before each step so a late event of the previous step is never counted for this one
const results = [];
const rows = []; // the latency table
const check = (name, ok, extra = '') => { results.push([name, ok]); console.log(`${ok ? 'PASS' : 'FAIL'} ${name}${extra ? ` (${extra})` : ''}`); };

// ── Actors ────────────────────────────────────────────────────────────────────────────────────────────────────────────
const customer = await loginCustomer();
const staff = await loginStaff('manager.westbay@albahar.qa');
const driver = await loginStaff('driver1@albahar.qa');
const outsider = await loginStaff('manager.pearl@albahar.qa');

const overview = (await must('GET', '/admin/dispatch/overview', { token: staff.token })).json.data;
const branchId = overview.branch.id;
const otherBranchId = (await must('GET', '/admin/dispatch/overview', { token: outsider.token })).json.data.branch.id;

const A = {
    customer: new Actor('customer', customer.token),
    staff: new Actor('staff', staff.token),
    driver: new Actor('driver', driver.token),
    outsider: new Actor('outsider', outsider.token),
};
await Promise.all(Object.values(A).map((a) => a.connected()));
await A.customer.join(`private-users.${customer.user.id}`);
await A.staff.join(`private-branches.${branchId}`);
await A.driver.join(`private-drivers.${driver.user.id}`);
await A.outsider.join(`private-branches.${otherBranchId}`);
check('every actor authorised its private channel (customer, staff, driver, other-branch staff)', true);

// The wrong channels must be refused.
const refused = async (actor, channel) => { try { await actor.join(channel); return false; } catch { return true; } };
check('driver cannot join the branch channel of a branch they do not work for', await refused(A.driver, `private-branches.${otherBranchId}`));
check('driver cannot join another driver channel', await refused(A.driver, `private-drivers.${staff.user.id}`));
check('customer cannot join a branch channel', await refused(A.customer, `private-branches.${branchId}`));
check('customer cannot join another customer channel', await refused(A.customer, `private-users.${staff.user.id}`));
check('staff of another branch cannot join this branch channel', await refused(A.outsider, `private-branches.${branchId}`));

// ── Fixtures: an address in a zone West Bay serves, a product in stock, auto-dispatch off ─────────────────────────────
// An address in West Bay (zone 61): reuse the one a previous run made.
const existing = (await must('GET', '/me/addresses', { token: customer.token })).json.data.find((x) => x.zone_number === '61');
const addressId = existing?.id ?? (await must('POST', '/me/addresses', { token: customer.token, body: {
    label: 'Home', recipient_name: 'Noor', recipient_phone: '+97455123456', zone_number: '61', street_number: '850', building_number: '12',
    latitude: 25.3, longitude: 51.5 } })).json.data.id;
const products = (await must('GET', '/products?per_page=40', { token: customer.token })).json.data.filter((x) => x.purchasable !== false);
const clearCart = async () => { for (const line of (await must('GET', '/cart', { token: customer.token })).json.data.items ?? []) await api('DELETE', `/cart/items/${line.id}`, { token: customer.token }); };

// A free delivery slot of West Bay two days from now (the branch publishes slots, so checkout needs one).
const slotDay = new Date(Date.now() + 2 * 86400_000).toISOString().slice(0, 10);
const slot = (await must('GET', `/branches/${branchId}/delivery-slots?date=${slotDay}`)).json.data.find((x) => !x.is_full);
if (!slot) throw new Error('West Bay has no free delivery slot to book');

// Stock for the whole run: West Bay's manager books +40 of one sofa variant, so reruns never run the demo stock dry.
const variantId = (await must('GET', `/products/${products[0].id}`, { token: customer.token })).json.data.variants[0].id;
const adj = await api('POST', '/admin/inventory/adjustments', { token: staff.token, body: { branch_id: branchId, type: 'opening_balance', reason: 'real-time e2e stock', items: [{ variant_id: variantId, quantity: 40 }] } });
if (adj.status < 300) await api('POST', `/admin/inventory/adjustments/${adj.json.data.id}/post`, { token: staff.token });
else console.log(`(stock top-up skipped: HTTP ${adj.status}; the demo stock is used)`);

/** Places a COD order of that product as the customer. */
async function place() {
    await clearCart();
    await must('POST', '/cart/items', { token: customer.token, body: { variant_id: variantId, quantity: 1 } });
    const opts = { address_id: addressId, payment_method: 'cod', delivery_slot_id: slot.id, delivery_date: slot.date };
    const q = await must('POST', '/checkout/quote', { token: customer.token, body: opts });
    if (q.json.data.fulfillments?.[0]?.branch?.id !== branchId) throw new Error(`the quote did not pick West Bay: ${JSON.stringify(q.json.data.errors ?? q.json.data.fulfillments?.[0]?.branch)}`);
    return api('POST', '/checkout/orders', { token: customer.token, body: { ...opts, quote_hash: q.json.data.quote_hash }, headers: { 'Idempotency-Key': crypto.randomUUID() } });
}

// ── One timed step ─────────────────────────────────────────────────────────────────────────────────────────────────────
/**
 * Runs `action`, then gathers what every actor hears for `who` (the actors that must hear something) until each has heard it
 * or `timeout` passes, plus a short tail so a burst is complete. `label` names the transition in the table.
 */
async function step(label, actor, action, who, { timeout = 7000 } = {}) {
    await sleep(SETTLE);
    const t0 = now();
    const res = await action();
    const tResp = now();
    const waits = who.map((n) => A[n].waitFor((e) => e.at >= t0, { since: t0, timeout }));
    await Promise.all(waits);
    await sleep(350);
    const heard = {};
    for (const [name, a] of Object.entries(A)) heard[name] = a.events.filter((e) => e.at >= t0 && e.at < t0 + timeout + 400);
    for (const [name, evs] of Object.entries(heard)) {
        for (const e of evs) rows.push({ transition: label, by: actor, actor: name, event: e.event, channel: e.channel, status: e.data.status ?? '', delivery: e.data.delivery_status ?? '', seq: e.data.seq ?? '', ms: e.at - t0, afterResponse: e.at - tResp });
    }
    const first = (n) => (heard[n][0] ? heard[n][0].at - t0 : NaN);
    console.log(`  ${label.padEnd(34)} ${Object.keys(A).map((n) => `${n}=${heard[n].length ? fmt(first(n)) : '-'}`).join('  ')}`);
    return { res, heard };
}

const mine = (orderNo, orderId, fulfillmentId) => (e) => [e.data.number, e.data.order_id, e.data.fulfillment_id].some((x) => x && [orderNo, orderId, fulfillmentId].includes(x));

console.log('\nOrder 1: placed, prepared, delivered (manual dispatch)');
const placed = await step('customer places the order', 'customer', place, ['staff', 'customer']);
check('order placed', placed.res.status === 201 || placed.res.status === 200, `HTTP ${placed.res.status} ${placed.res.status < 300 ? '' : placed.res.text.slice(0, 300)}`);
if (placed.res.status >= 300) process.exit(1);
const order = placed.res.json.data.order;
const orderId = order.id;
const orderNo = order.number;
const fulfillmentId = (await must('GET', `/orders/${orderId}`, { token: customer.token })).json.data.fulfillments[0].id;
const total = (await must('GET', `/orders/${orderId}`, { token: customer.token })).json.data.grand_total.amount;
const isMine = mine(orderNo, orderId, fulfillmentId);
await A.customer.join(`private-orders.${orderId}`);

const patch = (path, token, body) => () => api('POST', path, { token, body });
await step('staff accepts', 'staff', patch(`/admin/fulfillments/${fulfillmentId}/transition`, staff.token, { status: 'accepted' }), ['staff', 'customer']);
await step('staff starts preparing', 'staff', patch(`/admin/fulfillments/${fulfillmentId}/transition`, staff.token, { status: 'preparing' }), ['staff', 'customer']);
await step('staff marks ready', 'staff', patch(`/admin/fulfillments/${fulfillmentId}/transition`, staff.token, { status: 'ready' }), ['staff', 'customer']);
const assigned = await step('staff assigns the driver', 'staff', patch(`/admin/dispatch/fulfillments/${fulfillmentId}/assign`, staff.token, { driver_id: driver.user.id }), ['staff', 'driver', 'customer']);
const deliveryId = assigned.res.json.data.delivery_id;
await step('driver accepts the job', 'driver', patch(`/admin/deliveries/${deliveryId}/accept`, driver.token), ['staff', 'driver', 'customer']);
await step('driver picks the order up', 'driver', patch(`/admin/deliveries/${deliveryId}/pickup`, driver.token), ['staff', 'driver', 'customer']);
await step('driver is out for delivery', 'driver', patch(`/admin/deliveries/${deliveryId}/out-for-delivery`, driver.token), ['staff', 'driver', 'customer']);
const delivered = await step('driver delivers (COD, proof)', 'driver', () => api('POST', `/admin/deliveries/${deliveryId}/deliver`, { token: driver.token,
    form: proofForm({ recipient_name: 'Noor', 'cod[tender]': 'cash', 'cod[amount]': String(total) }) }), ['staff', 'driver', 'customer']);
check('delivery confirmed', delivered.res.status === 200, `HTTP ${delivered.res.status} ${delivered.res.status === 200 ? '' : delivered.res.text.slice(0, 160)}`);

console.log('\nOrder 2: cancelled by the customer before the branch accepts');
const p2 = await step('customer places order 2', 'customer', place, ['staff', 'customer']);
if (p2.res.status >= 300) throw new Error(`order 2: ${p2.res.status} ${p2.res.text.slice(0, 300)}`);
const o2 = p2.res.json.data.order;
await step('customer cancels (auto-approved)', 'customer', () => api('POST', `/orders/${o2.id}/cancel`, { token: customer.token, body: { reason_code: 'changed_mind' } }), ['staff', 'customer']);

console.log('\nOrder 3: delivery fails and comes back to the branch');
const p3 = await step('customer places order 3', 'customer', place, ['staff', 'customer']);
const f3 = (await must('GET', `/orders/${p3.res.json.data.order.id}`, { token: customer.token })).json.data.fulfillments[0].id;
for (const s of ['accepted', 'preparing', 'ready']) await must('POST', `/admin/fulfillments/${f3}/transition`, { token: staff.token, body: { status: s } });
const d3 = (await must('POST', `/admin/dispatch/fulfillments/${f3}/assign`, { token: staff.token, body: { driver_id: driver.user.id } })).json.data.delivery_id;
await must('POST', `/admin/deliveries/${d3}/accept`, { token: driver.token });
await must('POST', `/admin/deliveries/${d3}/pickup`, { token: driver.token });
await must('POST', `/admin/deliveries/${d3}/out-for-delivery`, { token: driver.token });
await sleep(1200);
await step('driver reports a failed delivery', 'driver', patch(`/admin/deliveries/${d3}/fail`, driver.token, { reason_code: 'customer_unreachable' }), ['staff', 'driver', 'customer']);
await step('staff receives it back at the branch', 'staff', patch(`/admin/deliveries/${d3}/return`, staff.token), ['staff', 'driver', 'customer']);

console.log('\nOrder 4: customer asks to cancel while the branch prepares it, staff approve');
const p4 = await step('customer places order 4', 'customer', place, ['staff', 'customer']);
const f4 = (await must('GET', `/orders/${p4.res.json.data.order.id}`, { token: customer.token })).json.data.fulfillments[0].id;
for (const s of ['accepted', 'preparing']) await must('POST', `/admin/fulfillments/${f4}/transition`, { token: staff.token, body: { status: s } });
await sleep(600);
await step('customer requests cancellation', 'customer', () => api('POST', `/orders/${p4.res.json.data.order.id}/cancel`, { token: customer.token, body: { reason_code: 'changed_mind' } }), ['staff', 'customer']);

// ── What the table must show ───────────────────────────────────────────────────────────────────────────────────────────
const byTransition = (label) => rows.filter((r) => r.transition === label);
const heardBy = (label, actor) => byTransition(label).filter((r) => r.actor === actor);
const lat = (label, actor) => Math.min(...heardBy(label, actor).map((r) => r.ms));

for (const [label, who] of [
    ['customer places the order', ['staff', 'customer']], ['staff accepts', ['staff', 'customer']], ['staff starts preparing', ['staff', 'customer']], ['staff marks ready', ['staff', 'customer']],
    ['staff assigns the driver', ['staff', 'driver', 'customer']], ['driver accepts the job', ['staff', 'driver', 'customer']], ['driver picks the order up', ['staff', 'driver', 'customer']],
    ['driver is out for delivery', ['staff', 'driver', 'customer']], ['driver delivers (COD, proof)', ['staff', 'driver', 'customer']],
    ['customer cancels (auto-approved)', ['staff', 'customer']], ['driver reports a failed delivery', ['staff', 'driver', 'customer']], ['staff receives it back at the branch', ['staff', 'driver', 'customer']],
    ['customer requests cancellation', ['staff', 'customer']],
]) {
    for (const who1 of who) check(`${label}: ${who1} hears it`, heardBy(label, who1).length > 0, fmt(lat(label, who1)));
}
const all = rows.filter((r) => Number.isFinite(r.ms));
const worst = (actor) => Math.max(...all.filter((r) => r.actor === actor).map((r) => r.ms), 0);
check('nobody of another branch heard anything about these orders', rows.filter((r) => r.actor === 'outsider').length === 0, `${rows.filter((r) => r.actor === 'outsider').length} events`);
check('the driver never heard about the order before it was assigned', heardBy('customer places the order', 'driver').length === 0 && heardBy('staff accepts', 'driver').length === 0);
check('every event reaches its listener within 1 s (slowest first-event latency)', Math.max(...results.length ? [0] : [0], ...Object.keys(A).flatMap((a) => [...new Set(rows.map((r) => r.transition))].map((t) => lat(t, a)).filter(Number.isFinite))) <= 1000);

if (MODE !== 'legacy') {
    const orderEvents = rows.filter((r) => r.channel.startsWith('private-users.') && r.event === 'order.updated');
    check('every customer event carries a seq', orderEvents.length > 0 && orderEvents.every((r) => r.seq !== ''), `${orderEvents.length} events`);
    const seqs = A.customer.events.filter((e) => e.event === 'order.updated' && e.data.order_id === orderId).map((e) => Number(e.data.seq));
    check('seq never goes backwards for one order', seqs.every((s, i) => i === 0 || s > seqs[i - 1]), seqs.join(','));
    const stale = A.customer.events.filter((e) => e.event === 'order.updated' && e.data.order_id === orderId).map((e) => e.data);
    check('customer payload carries order_id, number, status, fulfillment_id, delivery_status, seq and at', stale.every((d) => ['order_id', 'number', 'status', 'fulfillment_id', 'delivery_status', 'seq', 'at'].every((k) => k in d)));
    const leaks = A.customer.events.filter((e) => /driver_id|branch_id|customer_phone|cod_amount|driver_phone|\bphone\b/.test(JSON.stringify(e.data)));
    check('customer payloads carry no staff or driver identifiers', leaks.length === 0, leaks.map((e) => Object.keys(e.data).join('/')).join(' | '));
    const driverEvents = A.driver.events.map((e) => e.data);
    check('driver payloads only describe own jobs and never name other drivers', driverEvents.every((d) => !('customer_phone' in d)));
}

const out = { at: new Date().toISOString(), summary: { events: all.length, worst_ms: Object.fromEntries(Object.keys(A).map((a) => [a, Math.round(worst(a))])), median_first_event_ms: Math.round(median([...new Set(rows.map((r) => r.transition))].flatMap((t) => ['staff', 'customer', 'driver'].map((a) => lat(t, a))).filter(Number.isFinite))) }, rows, results };
if (process.env.OUT) writeFileSync(process.env.OUT, JSON.stringify(out, null, 2));
console.log(`\nmedian first-event latency ${out.summary.median_first_event_ms} ms; slowest event per actor: ${JSON.stringify(out.summary.worst_ms)}`);
Object.values(A).forEach((a) => a.close());
const failed = results.filter(([, ok]) => !ok).length;
console.log(`${results.length - failed}/${results.length} checks passed`);
process.exit(failed ? 1 : 0);
