// Pure logic of the live order toasts (no DOM, no timers) so it can be unit tested with `npm test`.

export const MAX_VISIBLE = 4;
export const TTL_MS = 7000;
const SEEN_CAP = 300;
const QUEUE_CAP = 100;

const text = (v, max = 120) => (v === null || v === undefined ? null : String(v).slice(0, max));

/** Accepts only the documented payload shape; anything else is dropped so a bad frame can never break the page. */
export function normalise(raw) {
    // A burst is made by createBurstGate below: "N new orders" in one toast instead of N toasts.
    if (raw && typeof raw === 'object' && raw.type === 'burst' && raw.id && Number.isInteger(raw.count) && raw.count > 1) {
        return { id: text(raw.id, 80), type: 'burst', count: Math.min(raw.count, 999), orderId: null, number: '', status: null, previous: null, branch: null, branchAr: null, customer: null, total: null, currency: 'QAR', paymentMethod: null, fulfilment: null, at: text(raw.at, 40) };
    }
    if (!raw || typeof raw !== 'object' || !raw.id || !raw.order_id || !raw.number) return null;
    return {
        id: text(raw.id, 80), type: raw.type === 'placed' ? 'placed' : 'status', orderId: text(raw.order_id, 64), number: text(raw.number, 40),
        status: text(raw.status, 40), previous: text(raw.previous, 40), branch: text(raw.branch), branchAr: text(raw.branch_ar), customer: text(raw.customer, 40),
        total: text(raw.total, 20), currency: text(raw.currency, 3) || 'QAR', paymentMethod: text(raw.payment_method, 24), fulfilment: text(raw.fulfilment, 16), at: text(raw.at, 40),
    };
}

/** Visual language per order status: a colour tone (CSS class suffix) and an icon name. */
const TONES = {
    pending_payment: ['slate', 'clock'],
    confirmed: ['emerald', 'check'],
    processing: ['sky', 'flame'],
    partially_fulfilled: ['sky', 'flame'],
    ready_for_pickup: ['amber', 'bag'],
    out_for_delivery: ['indigo', 'truck'],
    completed: ['emerald', 'flag'],
    cancellation_requested: ['rose', 'alert'],
    cancelled: ['rose', 'x'],
    partially_cancelled: ['rose', 'x'],
    returned: ['rose', 'undo'],
};

export function toneFor(evt) {
    if (evt.type === 'placed' || evt.type === 'burst') return { tone: 'gold', icon: 'bag' };
    const [tone, icon] = TONES[evt.status] ?? ['slate', 'bell'];
    return { tone, icon };
}

/** Replaces :n / {n} style placeholders. */
export const fill = (s, vars = {}) => String(s).replace(/[:{](\w+)}?/g, (m, k) => (k in vars ? String(vars[k]) : m));

/**
 * The on-screen state: at most `max` visible toasts, the rest wait in a queue (shown as "+N more").
 * Events are de-duplicated by id (the same event can arrive on a global and a branch channel, or twice from a poll).
 */
export function createStack({ max = MAX_VISIBLE } = {}) {
    const seen = new Set();
    let visible = [];
    let queue = [];

    return {
        add(evt) {
            if (!evt || seen.has(evt.id)) return { accepted: false, shown: false };
            seen.add(evt.id);
            if (seen.size > SEEN_CAP) seen.delete(seen.values().next().value);
            if (visible.length < max) { visible.push(evt); return { accepted: true, shown: true }; }
            queue.push(evt);
            if (queue.length > QUEUE_CAP) queue.shift();
            return { accepted: true, shown: false };
        },
        /** Removes a visible toast and returns the queued one that takes its place, if any. */
        remove(id) {
            const before = visible.length;
            visible = visible.filter((e) => e.id !== id);
            if (visible.length === before) return null;
            const next = queue.shift() ?? null;
            if (next) visible.push(next);
            return next;
        },
        clear() { visible = []; queue = []; },
        get visible() { return [...visible]; },
        get queued() { return queue.length; },
    };
}

/**
 * Which live frames mean "a new order the branch must look at", as a frame of the same shape (type placed), or null.
 *  - a placed order that is already valid (cash on delivery, pay at pickup) is new at once;
 *  - an online order is new only when its payment succeeded (pending_payment -> confirmed), never while it is unpaid.
 * The id is per order (`new:<order id>`), so the same order can never be announced twice, whichever frame carried it.
 */
export function asNewOrderRaw(raw) {
    if (!raw || typeof raw !== 'object' || !raw.id || !raw.order_id || !raw.number) return null;
    const placed = raw.type === 'placed' && raw.status !== 'pending_payment';
    const paid = raw.type !== 'placed' && raw.previous === 'pending_payment' && raw.status === 'confirmed';
    if (!placed && !paid) return null;
    return { ...raw, type: 'placed', id: `new:${raw.order_id}` };
}

export const BURST_INTERVAL_MS = 3000;

/**
 * Rate limit for the new-order toasts: at most one toast per `intervalMs`. The first order is shown at once; orders that arrive inside the
 * window are held, and when it ends they are shown together: one toast when only one was held, otherwise a single "N new orders" toast
 * (a frame of type burst, see normalise). Timers and the clock are injected so the rule is tested without waiting.
 */
export function createBurstGate({ intervalMs = BURST_INTERVAL_MS, now = () => Date.now(), setTimer = (f, ms) => setTimeout(f, ms), clearTimer = (t) => clearTimeout(t), emit }) {
    let lastShown = -Infinity;
    let held = [];
    let timer = null;

    const flush = () => {
        timer = null;
        if (!held.length) return;
        const batch = held;
        held = [];
        lastShown = now();
        emit(batch.length === 1 ? batch[0] : { id: `burst:${batch[0].id}:${batch.length}`, type: 'burst', count: batch.length, at: batch[batch.length - 1].at });
    };

    return {
        /** @returns {'shown'|'held'} */
        offer(frame) {
            const t = now();
            if (!held.length && t - lastShown >= intervalMs) {
                lastShown = t;
                emit(frame);
                return 'shown';
            }
            held.push(frame);
            if (timer === null) timer = setTimer(flush, Math.max(0, lastShown + intervalMs - t));
            return 'held';
        },
        get pending() { return held.length; },
        destroy() { if (timer !== null) clearTimer(timer); timer = null; held = []; },
    };
}
