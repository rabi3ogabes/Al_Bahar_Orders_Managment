// "Notify me when it is back" and "Tell me if the price drops": the pure rules the store follows (tests/alerts.test.mjs). No imports, so it runs in plain node.

export type AlertType = 'back_in_stock' | 'price_drop';
export interface AlertMoney { amount: string; currency: string; formatted: string }
export interface StockAlert {
  id: string; type: AlertType;
  product: { id: string; slug: string | null; name: string; image_url: string | null; thumb_url: string | null };
  variant: { id: string; name: string | null };
  branch_id: string | null; reference_price: AlertMoney | null; current_price: AlertMoney | null; in_stock: boolean; since: string; expires_at: string;
}
/** What the store offers (`GET /stock-alerts/config`). */
export interface AlertsConfig { back_in_stock: boolean; price_drop: boolean; price_drop_percent: number; price_drop_min_amount: string }

/** Until the server has answered: back-in-stock is on, price alerts are not (the owner switches them on), so a choice that cannot fire is never shown. */
export const DEFAULT_ALERTS_CONFIG: AlertsConfig = { back_in_stock: true, price_drop: false, price_drop_percent: 5, price_drop_min_amount: '5.00' };

/** The alert a customer already has for this variant and kind, if any. */
export const findAlert = (list: readonly StockAlert[] | undefined, variantId: string | undefined | null, type: AlertType): StockAlert | undefined =>
  variantId ? list?.find((a) => a.variant.id === variantId && a.type === type) : undefined;

/**
 * What the product page offers for the chosen variant: the back-in-stock bell when it cannot be bought, the price choice when it can (and the owner has it on).
 * `subscribed` says which state the control is in.
 */
export function productControls(opts: { canBuy: boolean; cfg: AlertsConfig; list?: readonly StockAlert[]; variantId?: string | null }): { bell: 'hidden' | 'notify' | 'subscribed'; price: 'hidden' | 'offer' | 'subscribed' } {
  const back = findAlert(opts.list, opts.variantId, 'back_in_stock');
  const price = findAlert(opts.list, opts.variantId, 'price_drop');
  return {
    bell: !opts.variantId || opts.canBuy || !opts.cfg.back_in_stock ? 'hidden' : back ? 'subscribed' : 'notify',
    // A customer who already watches the price may always turn it off, even if the owner switched the feature off meanwhile.
    price: !opts.variantId || (!opts.canBuy && !price) ? 'hidden' : price ? 'subscribed' : opts.cfg.price_drop ? 'offer' : 'hidden',
  };
}

/** How far the price has moved since the customer started watching: a negative percent is a drop. Null when there is nothing to compare. */
export function priceMove(a: Pick<StockAlert, 'reference_price' | 'current_price'>): { percent: number; dropped: boolean } | null {
  if (!a.reference_price || !a.current_price) return null;
  const was = Number(a.reference_price.amount), now = Number(a.current_price.amount);
  if (!Number.isFinite(was) || !Number.isFinite(now) || was <= 0) return null;
  const percent = Math.round(((now - was) / was) * 100);
  return { percent, dropped: now < was };
}

/** The line under an alert in the list: what it is waiting for, or what already happened. */
export function alertStatus(a: Pick<StockAlert, 'type' | 'in_stock'>): 'waiting_stock' | 'available' | 'watching' | 'sold_out' {
  if (a.type === 'back_in_stock') return a.in_stock ? 'available' : 'waiting_stock';
  return a.in_stock ? 'watching' : 'sold_out';
}

/** The product's address for a link (the slug, or the id when it has none). */
export const productPath = (a: Pick<StockAlert, 'product'>): string => `/products/${a.product.slug || a.product.id}`;
