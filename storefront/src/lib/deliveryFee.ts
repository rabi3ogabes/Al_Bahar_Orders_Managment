import type { DeliveryExtra, ExtraDeliveryMode, Money, MoneyLike, Quote } from './types';

/**
 * The delivery fee line of the order summary. There is no "free above X" rule: every delivery order pays its zone's fee, and
 * delivery is free only when a free-delivery coupon is applied (the quote's discount with `free_delivery: true`). The line is
 * always shown. The fee is the gross total: it already holds the extra delivery fee of bulky products, shown as its own
 * sub-line (`extraDelivery` below). There is no cash-on-delivery fee and no minimum order any more.
 * Tested in tests/delivery-fee.test.mjs (docs/api/FREE_DELIVERY_AND_ABANDONED_CARTS.md, section 1; docs/api/CHECKOUT_FEES_V2.md).
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

/* ───────────── extra delivery fee of bulky products (docs/api/CHECKOUT_FEES_V2.md, section 3) ───────────── */

/** The translator of `useI18n()`, passed in so this file stays free of imports. */
export type Tr = (key: string, vars?: Record<string, string | number>) => string;

/**
 * Money as the server may send it: the usual `{amount, currency, formatted}` (its own `formatted` is kept), or a bare "50.00" / 50 (built with `fmt`).
 * Null for anything that is not a number, so a missing or odd field never breaks the page.
 */
export function parseMoney(v: MoneyLike | null | undefined, currency: string, fmt: Fmt): Money | null {
  if (v === null || v === undefined || v === '') return null;
  const raw = typeof v === 'object' ? v.amount : v;
  const n = typeof raw === 'number' ? raw : parseFloat(String(raw).trim());
  if (!Number.isFinite(n)) return null;
  const cur = typeof v === 'object' && typeof v.currency === 'string' && v.currency ? v.currency : currency;
  const formatted = typeof v === 'object' && typeof v.formatted === 'string' && v.formatted ? v.formatted : fmt(n, cur);
  return { amount: n.toFixed(2), currency: cur, formatted };
}

/** An unknown mode is `per_unit`, the server's default. */
export const extraMode = (m: unknown): ExtraDeliveryMode => (m === 'per_line' ? 'per_line' : 'per_unit');

/** One product's part of the extra delivery fee. */
export interface ExtraItem {
  productId: string | null; name: string; mode: ExtraDeliveryMode; quantity: number
  /** The fee for one unit (`per_unit`), or for the product (`per_line`). */
  feeEach: Money
  amount: Money
}
export interface ExtraDelivery {
  /** The part of the delivery fee that comes from bulky products (already inside the delivery total). */
  amount: Money
  /** One row per product; empty when the server sent no detail (an older server, or only the total). */
  items: ExtraItem[]
  /** A free-delivery coupon waives the whole fee, this part included. */
  waived: boolean
}

function extraItem(x: DeliveryExtra | null | undefined, currency: string, fmt: Fmt): ExtraItem | null {
  if (!x || typeof x !== 'object') return null;
  const name = typeof x.name === 'string' ? x.name.trim() : '';
  const amount = parseMoney(x.amount, currency, fmt);
  if (!name || !amount || cents(amount) <= 0) return null;
  const mode = extraMode(x.mode);
  const quantity = Math.max(1, Math.round(Number(x.quantity)) || 1);
  let feeEach = parseMoney(x.fee_each, currency, fmt);
  if (!feeEach || cents(feeEach) <= 0) {
    const v = mode === 'per_line' ? parseFloat(amount.amount) : parseFloat(amount.amount) / quantity;
    feeEach = { amount: v.toFixed(2), currency: amount.currency, formatted: fmt(v, amount.currency) };
  }
  return { productId: x.product_id ? String(x.product_id) : null, name, mode, quantity, feeEach, amount };
}

/**
 * The "extra delivery fee for bulky items" sub-line under the delivery line. Null when there is nothing to show: pickup, no extra fee,
 * or a server that does not send it yet (every missing field means none).
 */
export function extraDelivery(q: Pick<Quote, 'fulfillment_type' | 'totals' | 'discounts' | 'delivery_extras'> | null | undefined, fmt: Fmt): ExtraDelivery | null {
  if (!q || q.fulfillment_type === 'pickup') return null;
  const currency = q.totals.delivery_fee?.currency ?? 'QAR';
  const amount = parseMoney(q.totals.extra_delivery_fee, currency, fmt);
  if (!amount || cents(amount) <= 0) return null;
  const items = (Array.isArray(q.delivery_extras) ? q.delivery_extras : []).flatMap((x) => extraItem(x, currency, fmt) ?? []);
  const waived = (q.discounts ?? []).some((d) => d.free_delivery === true) && cents(q.totals.delivery_fee) > 0;
  return { amount, items, waived };
}

/** What one product adds, in a few words: "×3 · QAR 50.00 each" (every unit) or "charged once" (once for the product). */
export const extraItemCaption = (i: ExtraItem, t: Tr): string =>
  i.mode === 'per_line' ? t('checkout.extra_once') : t('checkout.extra_each', { n: i.quantity, fee: i.feeEach.formatted });

/** A product's own extra delivery fee (the note on the product page). */
export interface ProductExtraFee { fee: Money; mode: ExtraDeliveryMode }

/** Null when the product has no extra delivery fee (0, missing, negative or not a number). */
export function productExtraFee(p: { extra_delivery_fee?: MoneyLike | null; extra_delivery_fee_mode?: unknown; price?: { final?: Money | null } | null } | null | undefined, fmt: Fmt): ProductExtraFee | null {
  if (!p) return null;
  const fee = parseMoney(p.extra_delivery_fee, p.price?.final?.currency ?? 'QAR', fmt);
  if (!fee || cents(fee) <= 0) return null;
  return { fee, mode: extraMode(p.extra_delivery_fee_mode) };
}

/** "Extra delivery fee QAR 50.00 per item" or "Extra delivery fee QAR 50.00 for this item, whatever the quantity". */
export const productExtraFeeText = (x: ProductExtraFee, t: Tr): string =>
  t(x.mode === 'per_line' ? 'product.extra_fee_line' : 'product.extra_fee_unit', { fee: x.fee.formatted });
