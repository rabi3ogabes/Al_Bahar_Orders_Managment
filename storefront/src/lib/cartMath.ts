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
export interface GuestRow { variant_id: string; qty: number; product_id: string; slug: string; name: string; variant_name: string | null; image: string | null; brand: string | null; unit: Money
  /** The product's assembly fee per unit when it offers assembly (as the product page showed it), and the customer's choice. The server prices it again at sign-in and at checkout. */
  assembly_fee?: Money | null; assembly?: boolean }

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
/** Ticks or unticks assembly on a row; a product that offers none (no fee) stays as it is. */
export function setGuestAssembly(rows: GuestRow[], variantId: string, on: boolean): GuestRow[] {
  return rows.map((r) => (r.variant_id === variantId && r.assembly_fee ? { ...r, assembly: on } : r));
}
/** What the rows with assembly add: the fee per unit times the units (zero when none chose it). */
export function guestAssemblyTotal(rows: GuestRow[], fmt: Fmt): Money | null {
  const chosen = rows.filter((r) => r.assembly && r.assembly_fee);
  return chosen.length ? sumMoney(chosen.map((r) => lineTotal(r.assembly_fee as Money, r.qty, fmt)), 'QAR', fmt) : null;
}

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
  /** The customer ticked assembly for this line (the fee per unit is the product's, as the page showed it). */
  assembly?: { fee: Money; selected: boolean } | null
  product: Pick<ProductCard, 'id' | 'slug' | 'name' | 'brand'> & { image?: string | null }
}
const EMPTY: Omit<Cart, 'subtotal'> = { id: 'pending', branch_id: null, fulfillment_type: 'delivery', address_id: null, coupon_code: null, loyalty_points: 0, items: [], items_count: 0, warnings: [] };

/** What the lines that chose assembly add on delivery (a pickup cart shows the choice but is not charged). The server's own figure replaces this once it answers. */
const assemblySum = (cart: Cart, items: CartItem[], fmt: Fmt): Money => sumMoney(
  cart.fulfillment_type === 'pickup' ? [] : items.flatMap((i) => (i.assembly?.selected ? [lineTotal(i.assembly.fee_each, i.quantity, fmt)] : [])), cart.subtotal.currency, fmt);

function recount(cart: Cart, items: CartItem[], fmt: Fmt): Cart {
  return { ...cart, items, items_count: items.reduce((n, i) => n + i.quantity, 0), subtotal: sumMoney(items.map((i) => i.line_total), cart.subtotal.currency, fmt), assembly_total: assemblySum(cart, items, fmt) };
}
const withQty = (i: CartItem, qty: number, fmt: Fmt): CartItem => ({
  ...i, quantity: qty, line_total: lineTotal(i.unit_price.final, qty, fmt),
  assembly: i.assembly ? { ...i.assembly, amount: i.assembly.selected ? lineTotal(i.assembly.fee_each, qty, fmt) : null } : i.assembly,
});

/** Adding with assembly ticked turns it on for a line that already is in the cart (adding without it never turns it off: the choice stays). */
const withChosenAssembly = (i: CartItem, a: NewLine['assembly'], fmt: Fmt): CartItem =>
  a?.selected && i.assembly ? { ...i, assembly: { ...i.assembly, selected: true, amount: lineTotal(i.assembly.fee_each, i.quantity, fmt) } } : i;

export function cartAdd(cart: Cart | null, n: NewLine, fmt: Fmt, max = MAX_QTY): Cart {
  const base: Cart = cart ?? { ...EMPTY, subtotal: sumMoney([], n.price.final.currency, fmt) };
  const found = base.items.find((i) => i.variant_id === n.variantId);
  if (found) return recount(base, base.items.map((i) => (i === found ? withChosenAssembly(withQty(i, cap(i.quantity + n.qty, Math.min(max, i.available_quantity || max)), fmt), n.assembly, fmt) : i)), fmt);
  const p = n.product;
  const item: CartItem = {
    id: `pending-${n.variantId}`, variant_id: n.variantId, variant_name: n.variantName, quantity: cap(n.qty, max), unit_price: n.price, line_total: lineTotal(n.price.final, cap(n.qty, max), fmt),
    available: true, available_quantity: 0, price_changed: false, previous_price: null, issue: null,
    assembly: n.assembly ? { selected: n.assembly.selected, fee_each: n.assembly.fee, amount: n.assembly.selected ? lineTotal(n.assembly.fee, cap(n.qty, max), fmt) : null } : null,
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
/** Ticks or unticks assembly on a line (only a line whose product offers it); the assembly total follows at once. */
export function cartSetAssembly(cart: Cart | null, variantId: string, on: boolean, fmt: Fmt): Cart | null {
  if (!cart) return cart;
  return recount(cart, cart.items.map((i) => (i.variant_id === variantId && i.assembly ? { ...i, assembly: { ...i.assembly, selected: on, amount: on ? lineTotal(i.assembly.fee_each, i.quantity, fmt) : null } } : i)), fmt);
}
