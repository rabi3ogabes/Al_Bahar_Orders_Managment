import type { Cart, CartItem, Money, Price, ProductCard } from './types';

/**
 * Pure cart arithmetic behind the optimistic cart: the header badge, the cart panel and the subtotal change the moment a
 * button is tapped, before the server has answered. Same variant added again means a bigger quantity, never a second line.
 * Tested in tests/cart-math.test.mjs.
 */
/** Formats an amount for display (`money()` of the active language); passed in so this file stays free of imports. */
export type Fmt = (value: number, currency: string) => string;

export const MAX_QTY = 20;
const cap = (n: number, max: number) => Math.max(0, Math.min(max, Math.floor(n)));

/* ───────────── guest cart (this browser) ───────────── */
export interface GuestRow { variant_id: string; qty: number; product_id: string; slug: string; name: string; variant_name: string | null; image: string | null; brand: string | null; unit: Money }

/** Adds `qty` of a variant: an existing row grows (capped), a new variant becomes a new row. */
export function addGuestRow(rows: GuestRow[], row: GuestRow, qty: number, max = MAX_QTY): GuestRow[] {
  const i = rows.findIndex((r) => r.variant_id === row.variant_id);
  if (i < 0) return qty > 0 ? [...rows, { ...row, qty: cap(qty, max) }] : rows;
  return rows.map((r, k) => (k === i ? { ...r, qty: cap(r.qty + qty, max) } : r));
}
/** Sets a row's quantity; zero or less removes it. */
export function setGuestQty(rows: GuestRow[], variantId: string, qty: number, max = MAX_QTY): GuestRow[] {
  return rows.map((r) => (r.variant_id === variantId ? { ...r, qty: cap(qty, max) } : r)).filter((r) => r.qty > 0);
}
export const removeGuestRow = (rows: GuestRow[], variantId: string): GuestRow[] => rows.filter((r) => r.variant_id !== variantId);

/* ───────────── money ───────────── */
export function lineTotal(unit: Money, qty: number, fmt: Fmt): Money {
  const v = parseFloat(unit.amount) * qty;
  return { amount: v.toFixed(2), currency: unit.currency, formatted: fmt(v, unit.currency) };
}
export function sumMoney(parts: Money[], fallbackCurrency: string, fmt: Fmt): Money {
  const cur = parts[0]?.currency ?? fallbackCurrency;
  const v = parts.reduce((s, m) => s + parseFloat(m.amount), 0);
  return { amount: v.toFixed(2), currency: cur, formatted: fmt(v, cur) };
}

/* ───────────── signed-in cart (server shape, shown before the server answers) ───────────── */
export interface NewLine {
  variantId: string; qty: number; variantName: string | null; price: Price
  product: Pick<ProductCard, 'id' | 'slug' | 'name' | 'brand'> & { image?: string | null }
}
const EMPTY: Omit<Cart, 'subtotal'> = { id: 'pending', branch_id: null, fulfillment_type: 'delivery', address_id: null, coupon_code: null, loyalty_points: 0, items: [], items_count: 0, warnings: [] };

function recount(cart: Cart, items: CartItem[], fmt: Fmt): Cart {
  return { ...cart, items, items_count: items.reduce((n, i) => n + i.quantity, 0), subtotal: sumMoney(items.map((i) => i.line_total), cart.subtotal.currency, fmt) };
}
const withQty = (i: CartItem, qty: number, fmt: Fmt): CartItem => ({ ...i, quantity: qty, line_total: lineTotal(i.unit_price.final, qty, fmt) });

export function cartAdd(cart: Cart | null, n: NewLine, fmt: Fmt, max = MAX_QTY): Cart {
  const base: Cart = cart ?? { ...EMPTY, subtotal: sumMoney([], n.price.final.currency, fmt) };
  const found = base.items.find((i) => i.variant_id === n.variantId);
  if (found) return recount(base, base.items.map((i) => (i === found ? withQty(i, cap(i.quantity + n.qty, Math.min(max, i.available_quantity || max)), fmt) : i)), fmt);
  const p = n.product;
  const item: CartItem = {
    id: `pending-${n.variantId}`, variant_id: n.variantId, variant_name: n.variantName, quantity: cap(n.qty, max), unit_price: n.price, line_total: lineTotal(n.price.final, cap(n.qty, max), fmt),
    available: true, available_quantity: 0, price_changed: false, previous_price: null, issue: null,
    product: {
      id: p.id, slug: p.slug, name: p.name, brand: p.brand, sku: '', short_description: null, image: p.image ? { url: p.image, thumb_url: p.image, alt: p.name } : null, price: n.price,
      rating: { average: 0, count: 0 }, badges: [], in_stock: true, purchasable: true, has_variants: false,
    },
  };
  return recount(base, [...base.items, item], fmt);
}
/** Quantity of a variant's line; zero or less removes the line. */
export function cartSetQty(cart: Cart | null, variantId: string, qty: number, fmt: Fmt, max = MAX_QTY): Cart | null {
  if (!cart) return cart;
  if (qty <= 0) return recount(cart, cart.items.filter((i) => i.variant_id !== variantId), fmt);
  return recount(cart, cart.items.map((i) => (i.variant_id === variantId ? withQty(i, cap(qty, Math.min(max, i.available_quantity || max)), fmt) : i)), fmt);
}
