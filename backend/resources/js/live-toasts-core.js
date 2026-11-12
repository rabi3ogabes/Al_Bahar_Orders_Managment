// Pure logic of the live order toasts (no DOM, no timers) so it can be unit tested with `npm test`.

export const MAX_VISIBLE = 4;
export const TTL_MS = 7000;
const SEEN_CAP = 300;
const QUEUE_CAP = 100;

const text = (v, max = 120) => (v === null || v === undefined ? null : String(v).slice(0, max));

/** Accepts only the documented payload shape; anything else is dropped so a bad frame can never break the page. */
export function normalise(raw) {
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
    if (evt.type === 'placed') return { tone: 'gold', icon: 'bag' };
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
