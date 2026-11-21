/**
 * The delivery or pickup date a customer is promised before paying (docs/guides/DELIVERY_PROMISE_ASSEMBLY_AND_SHARING.md). The server works
 * everything out (stock, opening hours, holidays, slot places, lead time) and writes the sentence in the customer's language; this file only
 * decides what to show of it and builds the request. Pure, so it is tested alone (tests/delivery-promise.test.mjs).
 */
export type Tr = (key: string, vars?: Record<string, string | number>) => string;

export interface PromiseParcel {
  branch: { id: string; name: string }
  type: 'delivery' | 'pickup'
  /** `unconfirmed` = no date can be promised (not in stock, closed, nothing free): the customer still can buy. */
  status: 'scheduled' | 'unconfirmed'
  reason: string | null
  kind: 'slot' | 'same_day' | 'day' | 'ready' | null
  /** The promised day, YYYY-MM-DD (Qatar). Null when unconfirmed. */
  date: string | null
  message: string
  detail: string | null
  slot: { id: string; starts_at: string; ends_at: string } | null
  items: { variant_id: string; quantity: number }[]
}

export interface BasketPromise {
  /** False when the dashboard switch is off: show nothing. */
  enabled: boolean
  fulfillment_type: 'delivery' | 'pickup'
  status: 'scheduled' | 'partial' | 'unconfirmed' | 'disabled'
  message: string | null
  detail: string | null
  all_by: { date: string; label: string } | null
  is_split: boolean
  /** Delivery only: the address (zone) is known. Until then the date is the best showroom's. */
  location_known: boolean
  parcels: PromiseParcel[]
}

/** What the promise block draws. */
export interface PromiseView {
  /** `ok`: a date was promised · `soft`: part of it, or none yet (a calm tone, never an error). */
  tone: 'ok' | 'soft'
  headline: string
  detail: string | null
  /** One row per parcel when the order splits across branches. */
  rows: { key: string; branch: string; text: string; ok: boolean }[]
  /** "The exact date depends on your address." */
  hint: string | null
  type: 'delivery' | 'pickup'
}

/** Null when there is nothing to say (switched off, or no answer yet). */
export function promiseView(p: BasketPromise | null | undefined, t: Tr): PromiseView | null {
  if (!p || !p.enabled || p.status === 'disabled' || !p.message) return null;
  const rows = p.is_split && p.parcels.length > 1
    ? p.parcels.map((x, i) => ({ key: `${x.branch.id}-${i}`, branch: x.branch.name, text: x.status === 'scheduled' ? x.message : t(x.type === 'pickup' ? 'promise.confirm_pickup' : 'promise.confirm_delivery'), ok: x.status === 'scheduled' }))
    : [];
  return {
    tone: p.status === 'scheduled' ? 'ok' : 'soft', headline: p.message, detail: p.detail, rows, type: p.fulfillment_type,
    hint: p.fulfillment_type === 'delivery' && !p.location_known && p.status !== 'unconfirmed' ? t('promise.address_hint') : null,
  };
}

/** The body of `POST /cart/promise`: what the customer wants, nothing about money. Lines with no quantity are left out. */
export function promiseLines(lines: { variantId: string; qty: number; assembly?: boolean }[]): { variant_id: string; quantity: number; assembly?: boolean }[] {
  return lines.filter((l) => l.qty > 0).map((l) => ({ variant_id: l.variantId, quantity: l.qty, ...(l.assembly ? { assembly: true } : {}) }));
}

/** A stable key for the lines of a basket (order does not matter), so the same basket never asks twice. */
export function promiseKey(lines: { variant_id: string; quantity: number; assembly?: boolean }[]): string {
  return [...lines].map((l) => `${l.variant_id}:${l.quantity}:${l.assembly ? 1 : 0}`).sort().join('|');
}
