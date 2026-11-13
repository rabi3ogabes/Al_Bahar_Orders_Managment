import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState, type MutableRefObject, type ReactNode } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { del, get, patch, post, ApiError } from './api';
import { useAuth } from './auth';
import { useI18n } from './i18n';
import { useToast } from './toast';
import { MAX_QTY, addGuestRow, cartAdd, cartSetQty, lineTotal, removeGuestRow, setGuestQty, sumMoney, type GuestRow } from './cartMath';
import { money } from './format';
import type { Cart, Money, Price, ProductCard, Variant } from './types';

/** One cart row, whether it lives on the server (signed in) or in this browser (guest). `key` is the variant id. */
export interface Line {
  key: string; variantId: string; productId: string; slug: string; name: string; variantName: string | null; image: string | null
  brand: string | null; unit: Money; total: Money; qty: number; max: number; available: boolean; issue: string | null; priceChanged: boolean
}

const GUEST = 'albahar.guest_cart';
const readGuest = (): GuestRow[] => { try { return JSON.parse(localStorage.getItem(GUEST) || '[]'); } catch { return []; } };
const writeGuest = (r: GuestRow[]) => { try { localStorage.setItem(GUEST, JSON.stringify(r)); } catch { /* ignore */ } };

type Product = Pick<ProductCard, 'id' | 'slug' | 'name' | 'brand'> & { image?: string | null };
const priceOf = (unit: Money): Price => ({ regular: unit, sale: null, final: unit, discount_percent: 0, sale_ends_at: null, source: 'cart' });

interface Ctx {
  lines: Line[]; count: number; subtotal: Money; loading: boolean; cart: Cart | null
  /** Adds to the cart and (unless `open: false`) opens the cart panel. Updates the badge and the panel at once; the server answers later and a failure rolls back with a toast. */
  add: (product: Product, variant: Variant, qty?: number, opts?: { open?: boolean }) => Promise<void>
  /** One tap from a card or a rail: the product's single variant (or `variantId`), `qty` pieces. */
  quickAdd: (p: ProductCard, qty?: number, variantId?: string | null) => Promise<void>
  setQty: (line: Line, qty: number) => Promise<void>
  remove: (line: Line) => Promise<void>
  /** Undo for `remove`: puts the same line (variant and quantity) back. */
  restore: (line: Line) => Promise<void>
  /** Resolves when every cart change made so far has reached the server (call before going to checkout). */
  flush: () => Promise<void>
  drawer: boolean
  /** Opens the panel as the header icon does (no "Added" confirmation). */
  openDrawer: () => void
  /** Shows the panel for an add that was made with `open: false` (the quick view closes first), keeping the confirmation. */
  reveal: () => void
  closeDrawer: () => void
  /** Variant that was just added: the cart panel confirms it ("Added to cart") until the panel is opened again another way. No timers. */
  lastAdded: string | null
  /** What had focus when the panel opened (the Add button): focus goes back there on close. */
  opener: MutableRefObject<HTMLElement | null>
  adding: boolean
}
const C = createContext<Ctx>(null as unknown as Ctx);
export const useCart = () => useContext(C);

export function CartProvider({ children }: { children: ReactNode }) {
  const { user } = useAuth();
  const { locale, t } = useI18n();
  const toast = useToast();
  const qc = useQueryClient();
  const [guest, setGuestState] = useState<GuestRow[]>(readGuest);
  const guestRef = useRef(guest);
  const [drawer, setDrawer] = useState(false);
  const [lastAdded, setLastAdded] = useState<string | null>(null);
  const [writing, setWriting] = useState(0);
  const opener = useRef<HTMLElement | null>(null);
  const key = useMemo(() => ['cart', user?.id], [user?.id]);
  const fmt = useCallback((v: number, cur: string) => money(v, cur, locale), [locale]);

  const commitGuest = useCallback((rows: GuestRow[]) => { guestRef.current = rows; setGuestState(rows); writeGuest(rows); }, []);
  const server = useQuery({ queryKey: key, enabled: !!user, queryFn: async () => (await get<{ data: Cart }>('/cart')).data });

  // Signing in: fold the guest basket into the account cart, then forget it.
  useEffect(() => {
    if (!user || guest.length === 0) return;
    const rows = guest;
    commitGuest([]);
    (async () => {
      for (const r of rows) { try { await post('/cart/items', { variant_id: r.variant_id, quantity: r.qty }); } catch { /* unavailable item: skip */ } }
      await qc.invalidateQueries({ queryKey: ['cart'] });
    })();
  }, [user, guest, qc, commitGuest]);

  /* ───── signed-in writes: shown at once, sent one after another ─────
   * The cache is changed first (badge, panel and subtotal follow immediately); requests then go out in order, so a quantity
   * change never overtakes the add it depends on. The last answer replaces the optimistic data with the server's. A failure
   * refetches the real cart (the rollback) and shows a toast. */
  const inflight = useRef(0);
  const chain = useRef<Promise<unknown>>(Promise.resolve());
  const ids = useRef(new Map<string, string>()); // variant id -> server line id
  const remember = (c: Cart) => { ids.current = new Map(c.items.map((i) => [i.variant_id, i.id])); };
  useEffect(() => { if (server.data) remember(server.data); }, [server.data]);

  const write = (optimistic: (c: Cart | null) => Cart | null, send: () => Promise<Cart | null>): Promise<void> => {
    void qc.cancelQueries({ queryKey: key });
    qc.setQueryData<Cart | null>(key, (c) => optimistic(c ?? null) ?? c ?? null);
    inflight.current += 1; setWriting(inflight.current);
    const run = chain.current.then(send).then(
      (c) => { if (c) remember(c); inflight.current -= 1; setWriting(inflight.current); if (inflight.current === 0 && c) qc.setQueryData(key, c); },
      (e) => { inflight.current -= 1; setWriting(inflight.current); void qc.invalidateQueries({ queryKey: key }); throw e; },
    );
    chain.current = run.catch(() => undefined);
    return run as Promise<void>;
  };

  const fail = useCallback((e: unknown) => { toast.push(e instanceof ApiError ? e.message : t('error.generic'), 'error'); }, [toast, t]);
  const keepFocus = () => { const a = document.activeElement; opener.current = a instanceof HTMLElement && a !== document.body ? a : null; };

  const lines = useMemo<Line[]>(() => {
    if (user) {
      return (server.data?.items ?? []).map((i) => ({
        key: i.variant_id, variantId: i.variant_id, productId: i.product.id, slug: i.product.slug, name: i.product.name, variantName: i.variant_name,
        image: i.product.image?.thumb_url ?? null, brand: i.product.brand?.name ?? null, unit: i.unit_price.final, total: i.line_total, qty: i.quantity,
        max: Math.min(MAX_QTY, i.available_quantity || MAX_QTY), available: i.available, issue: i.issue, priceChanged: i.price_changed,
      }));
    }
    return guest.map((g) => ({
      key: g.variant_id, variantId: g.variant_id, productId: g.product_id, slug: g.slug, name: g.name, variantName: g.variant_name, image: g.image, brand: g.brand,
      unit: g.unit, total: lineTotal(g.unit, g.qty, fmt), qty: g.qty, max: MAX_QTY, available: true, issue: null, priceChanged: false,
    }));
  }, [user, server.data, guest, fmt]);

  const count = lines.reduce((n, l) => n + l.qty, 0);
  const subtotal = useMemo<Money>(() => {
    if (user && server.data) return server.data.subtotal;
    return sumMoney(lines.map((l) => l.total), lines[0]?.unit.currency ?? 'QAR', fmt);
  }, [user, server.data, lines, fmt]);

  const shown = (variantId: string) => { setLastAdded(variantId); };

  const addLine = async (product: Product, variantId: string, variantName: string | null, price: Price, qty: number, image: string | null, open: boolean) => {
    keepFocus();
    if (user) {
      write((c) => cartAdd(c, { variantId, qty, variantName, price, product: { ...product, image } }, fmt),
        async () => (await post<{ data: Cart }>('/cart/items', { variant_id: variantId, quantity: qty })).data).catch(fail);
    } else {
      commitGuest(addGuestRow(guestRef.current, { variant_id: variantId, qty, product_id: product.id, slug: product.slug, name: product.name, variant_name: variantName, image, brand: product.brand?.name ?? null, unit: price.final }, qty));
    }
    shown(variantId);
    if (open) setDrawer(true);
  };

  const value: Ctx = {
    lines, count, subtotal, loading: !!user && server.isLoading && !server.data, cart: server.data ?? null, drawer, adding: writing > 0, lastAdded, opener,
    openDrawer: () => { keepFocus(); setLastAdded(null); setDrawer(true); },
    reveal: () => setDrawer(true),
    closeDrawer: () => setDrawer(false),
    flush: async () => { await chain.current; },
    add: (product, variant, qty = 1, opts) => addLine(product, variant.id, variant.name || null, variant.price, qty, product.image ?? variant.image_url, opts?.open !== false),
    quickAdd: (p, qty = 1, variantId) => { const v = variantId ?? p.variant_id; return v ? addLine({ id: p.id, slug: p.slug, name: p.name, brand: p.brand }, v, null, p.price, qty, p.image?.thumb_url ?? null, true) : Promise.resolve(); },
    restore: async (line) => {
      const price = priceOf(line.unit);
      if (user) {
        write((c) => cartAdd(c, { variantId: line.variantId, qty: line.qty, variantName: line.variantName, price, product: { id: line.productId, slug: line.slug, name: line.name, brand: line.brand ? { id: '', name: line.brand } : null, image: line.image } }, fmt),
          async () => (await post<{ data: Cart }>('/cart/items', { variant_id: line.variantId, quantity: line.qty })).data).catch(fail);
      } else {
        commitGuest(addGuestRow(guestRef.current, { variant_id: line.variantId, qty: line.qty, product_id: line.productId, slug: line.slug, name: line.name, variant_name: line.variantName, image: line.image, brand: line.brand, unit: line.unit }, line.qty));
      }
      shown(line.variantId);
    },
    setQty: async (line, qty) => {
      if (user) {
        write((c) => cartSetQty(c, line.variantId, qty, fmt), async () => {
          const id = ids.current.get(line.variantId);
          if (!id) return null; // the add it depends on failed: nothing to change
          return (await (qty <= 0 ? del<{ data: Cart }>(`/cart/items/${id}`) : patch<{ data: Cart }>(`/cart/items/${id}`, { quantity: qty }))).data;
        }).catch(fail);
      } else commitGuest(setGuestQty(guestRef.current, line.variantId, qty));
    },
    remove: async (line) => {
      if (user) {
        write((c) => cartSetQty(c, line.variantId, 0, fmt), async () => {
          const id = ids.current.get(line.variantId);
          return id ? (await del<{ data: Cart }>(`/cart/items/${id}`)).data : null;
        }).catch(fail);
      } else commitGuest(removeGuestRow(guestRef.current, line.variantId));
    },
  };
  return <C.Provider value={value}>{children}</C.Provider>;
}
