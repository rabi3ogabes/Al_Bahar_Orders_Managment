import type { Money, Quote } from './types';

/**
 * The delivery fee line of the order summary. There is no "free above X" rule: every delivery order pays its zone's fee, and
 * delivery is free only when a free-delivery coupon is applied (the quote's discount with `free_delivery: true`). The line is
 * always shown. Tested in tests/delivery-fee.test.mjs (docs/api/FREE_DELIVERY_AND_ABANDONED_CARTS.md, section 1).
 */
export type Fmt = (value: number, currency: string) => string;

export interface DeliveryFeeLine {
  /** pending: no quote yet · fee: a fee to pay · free_coupon: the coupon waives it (show it struck through) · zero: nothing to pay (pickup, or a zone without a fee) */
  kind: 'pending' | 'fee' | 'free_coupon' | 'zero'
  /** The fee to show; struck through for `free_coupon`. */
  fee: Money | null
  /** The free-delivery coupon code, when the quote names it. */
  code: string | null
  pickup: boolean
  /** The "Discount" row. The quote's `totals.discount` counts the waived fee too, so it is taken out here; null means no row. */
  discount: Money | null
}

const cents = (m: Money | null | undefined) => Math.round(parseFloat(m?.amount ?? '0') * 100) || 0;

export function deliveryFeeLine(q: Pick<Quote, 'fulfillment_type' | 'totals' | 'discounts'> | null | undefined, fmt: Fmt): DeliveryFeeLine {
  if (!q) return { kind: 'pending', fee: null, code: null, pickup: false, discount: null };
  const pickup = q.fulfillment_type === 'pickup';
  const fee = q.totals.delivery_fee;
  const free = (q.discounts ?? []).find((d) => d.free_delivery === true);
  const discount = cents(q.totals.discount);
  if (free && cents(fee) > 0) {
    const rest = Math.max(0, discount - cents(fee));
    return { kind: 'free_coupon', fee, code: free.code?.trim() || null, pickup, discount: rest > 0 ? { amount: (rest / 100).toFixed(2), currency: fee.currency, formatted: fmt(rest / 100, fee.currency) } : null };
  }
  const shown = discount > 0 ? q.totals.discount : null;
  return { kind: cents(fee) > 0 ? 'fee' : 'zero', fee, code: null, pickup, discount: shown };
}
