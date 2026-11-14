/**
 * Rules of the live order updates, free of React and of the socket so they can be tested on their own.
 *
 * Every `order.updated` message and every order the API returns carries `seq`, the order's version (the server raises it by one per
 * change). A message older than what the page already shows must never overwrite it; a message that is as new or newer is laid over
 * the cached order at once (the screen changes before the confirming read finishes); an order with no version (an older server) is
 * always replaced.
 */
export interface OrderEventPart { id: string; status: string; status_label?: string | null; delivery_status?: string | null; delivery_status_label?: string | null }
export interface OrderLiveEvent {
  order_id: string; number: string; status: string; status_label?: string; payment_status?: string
  delivery_status?: string | null; fulfillments?: OrderEventPart[]; seq?: number
}
interface Row { id: string; status?: string; status_label?: string; payment_status?: string; seq?: number; [k: string]: unknown }

/** Remembers the newest version seen per order. `accept` says whether a message is at least as new as that. */
export function createSeqGate() {
  const last = new Map<string, number>();
  return {
    accept(orderId: string, seq: number | undefined): boolean {
      if (typeof seq !== 'number') return true;
      const known = last.get(orderId);
      if (known !== undefined && seq < known) return false;
      last.set(orderId, seq);
      return true;
    },
    /** A version learned from a read of the API. */
    seen(orderId: string, seq: number | undefined) {
      if (typeof seq !== 'number') return;
      if ((last.get(orderId) ?? -1) < seq) last.set(orderId, seq);
    },
    last: (orderId: string) => last.get(orderId),
  };
}

/** The order as the event says it is, or the order itself when the event is older than it. */
export function applyOrderEvent<T extends Row>(order: T, e: OrderLiveEvent): T {
  if (order.id !== e.order_id) return order;
  if (typeof e.seq === 'number' && typeof order.seq === 'number' && e.seq < order.seq) return order;
  const next: Row = { ...order, status: e.status, seq: e.seq ?? order.seq };
  if (e.status_label) next.status_label = e.status_label;
  if (e.payment_status) next.payment_status = e.payment_status;
  const parts = (order as { fulfillments?: unknown }).fulfillments;
  if (Array.isArray(parts) && e.fulfillments) {
    next.fulfillments = parts.map((f: { id: string; status: string; status_label?: string; delivery?: Record<string, unknown> | null }) => {
      const p = e.fulfillments!.find((x) => x.id === f.id);
      if (!p) return f;
      return {
        ...f, status: p.status, status_label: p.status_label ?? f.status_label,
        delivery: p.delivery_status && f.delivery ? { ...f.delivery, status: p.delivery_status, ...(p.delivery_status_label ? { status_label: p.delivery_status_label } : {}) } : f.delivery,
      };
    });
  }
  // Buttons that depend on the state are only switched OFF here; the confirming read switches the right ones on.
  const closed = ['completed', 'cancelled', 'partially_cancelled', 'returned', 'cancellation_requested', 'out_for_delivery'];
  if ('can_cancel' in next && closed.includes(e.status)) next.can_cancel = false;
  if ('can_pay' in next && e.status !== 'pending_payment') next.can_pay = false;
  return next as T;
}

/** The orders list cache (a plain `{data: rows}` page, or the pages of an infinite query) with the event laid over the matching row. */
export function applyOrderEventToCache<T>(cache: T, e: OrderLiveEvent): T {
  const mapRows = (rows: unknown): unknown => (Array.isArray(rows) ? rows.map((r) => (r && typeof r === 'object' && 'id' in r ? applyOrderEvent(r as Row, e) : r)) : rows);
  if (!cache || typeof cache !== 'object') return cache;
  const c = cache as { data?: unknown; pages?: { data?: unknown }[] };
  if (Array.isArray(c.pages)) return { ...c, pages: c.pages.map((p) => ({ ...p, data: mapRows(p.data) })) } as T;
  if (Array.isArray(c.data)) return { ...c, data: mapRows(c.data) } as T;
  return cache;
}
