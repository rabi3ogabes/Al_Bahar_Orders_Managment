import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { del, get, patch, post, ApiError } from './api';
import { useAuth } from './auth';
import { useI18n } from './i18n';
import { money } from './format';
import { useToast } from './toast';
import type { Cart, Money, ProductCard, Variant } from './types';

/** One cart row, whether it lives on the server (signed in) or in this browser (guest). */
export interface Line {
  key: string; variantId: string; productId: string; slug: string; name: string; variantName: string | null; image: string | null
  brand: string | null; unit: Money; total: Money; qty: number; max: number; available: boolean; issue: string | null; priceChanged: boolean
}
interface GuestRow { variant_id: string; qty: number; product_id: string; slug: string; name: string; variant_name: string | null; image: string | null; brand: string | null; unit: Money }

const GUEST = 'albahar.guest_cart';
const readGuest = (): GuestRow[] => { try { return JSON.parse(localStorage.getItem(GUEST) || '[]'); } catch { return []; } };
const writeGuest = (r: GuestRow[]) => { try { localStorage.setItem(GUEST, JSON.stringify(r)); } catch { /* ignore */ } };
const MAX_QTY = 20;

interface Ctx {
  lines: Line[]; count: number; subtotal: Money; loading: boolean; cart: Cart | null
  add: (product: Pick<ProductCard, 'id' | 'slug' | 'name' | 'brand'> & { image?: string | null }, variant: Variant, qty?: number) => Promise<void>
  setQty: (line: Line, qty: number) => Promise<void>
  remove: (line: Line) => Promise<void>
  drawer: boolean; openDrawer: () => void; closeDrawer: () => void
  adding: boolean
}
const C = createContext<Ctx>(null as unknown as Ctx);
export const useCart = () => useContext(C);

export function CartProvider({ children }: { children: ReactNode }) {
  const { user } = useAuth();
  const { locale, t } = useI18n();
  const toast = useToast();
  const qc = useQueryClient();
  const [guest, setGuest] = useState<GuestRow[]>(readGuest);
  const [drawer, setDrawer] = useState(false);

  const server = useQuery({ queryKey: ['cart', user?.id], enabled: !!user, queryFn: async () => (await get<{ data: Cart }>('/cart')).data });

  // Signing in: fold the guest basket into the account cart, then forget it.
  useEffect(() => {
    if (!user || guest.length === 0) return;
    const rows = guest;
    setGuest([]); writeGuest([]);
    (async () => {
      for (const r of rows) { try { await post('/cart/items', { variant_id: r.variant_id, quantity: r.qty }); } catch { /* unavailable item: skip */ } }
      await qc.invalidateQueries({ queryKey: ['cart'] });
    })();
  }, [user, guest, qc]);

  const apply = (c: Cart) => qc.setQueryData(['cart', user?.id], c);
  const addM = useMutation({ mutationFn: async (v: { variantId: string; qty: number }) => (await post<{ data: Cart }>('/cart/items', { variant_id: v.variantId, quantity: v.qty })).data, onSuccess: apply });
  const updM = useMutation({ mutationFn: async (v: { id: string; qty: number }) => (await patch<{ data: Cart }>(`/cart/items/${v.id}`, { quantity: v.qty })).data, onSuccess: apply });
  const delM = useMutation({ mutationFn: async (id: string) => (await del<{ data: Cart }>(`/cart/items/${id}`)).data, onSuccess: apply });

  const lines = useMemo<Line[]>(() => {
    if (user) {
      return (server.data?.items ?? []).map((i) => ({
        key: i.id, variantId: i.variant_id, productId: i.product.id, slug: i.product.slug, name: i.product.name, variantName: i.variant_name,
        image: i.product.image?.thumb_url ?? null, brand: i.product.brand?.name ?? null, unit: i.unit_price.final, total: i.line_total, qty: i.quantity,
        max: Math.min(MAX_QTY, i.available_quantity || MAX_QTY), available: i.available, issue: i.issue, priceChanged: i.price_changed,
      }));
    }
    return guest.map((g) => ({
      key: g.variant_id, variantId: g.variant_id, productId: g.product_id, slug: g.slug, name: g.name, variantName: g.variant_name, image: g.image, brand: g.brand,
      unit: g.unit, total: { ...g.unit, amount: (parseFloat(g.unit.amount) * g.qty).toFixed(2), formatted: money(parseFloat(g.unit.amount) * g.qty, g.unit.currency, locale) },
      qty: g.qty, max: MAX_QTY, available: true, issue: null, priceChanged: false,
    }));
  }, [user, server.data, guest, locale]);

  const count = lines.reduce((n, l) => n + l.qty, 0);
  const subtotal = useMemo<Money>(() => {
    if (user && server.data) return server.data.subtotal;
    const sum = lines.reduce((s, l) => s + parseFloat(l.total.amount), 0);
    const cur = lines[0]?.unit.currency ?? 'QAR';
    return { amount: sum.toFixed(2), currency: cur, formatted: money(sum, cur, locale) };
  }, [user, server.data, lines, locale]);

  const fail = useCallback((e: unknown) => { toast.push(e instanceof ApiError ? e.message : t('error.generic'), 'error'); }, [toast, t]);

  const value: Ctx = {
    lines, count, subtotal, loading: !!user && server.isLoading, cart: server.data ?? null, drawer, adding: addM.isPending,
    openDrawer: () => setDrawer(true), closeDrawer: () => setDrawer(false),
    add: async (product, variant, qty = 1) => {
      try {
        if (user) { await addM.mutateAsync({ variantId: variant.id, qty }); }
        else {
          const rows = [...guest]; const i = rows.findIndex((r) => r.variant_id === variant.id);
          if (i >= 0) rows[i] = { ...rows[i], qty: Math.min(MAX_QTY, rows[i].qty + qty) };
          else rows.push({ variant_id: variant.id, qty, product_id: product.id, slug: product.slug, name: product.name, variant_name: variant.name || null, image: product.image ?? variant.image_url, brand: product.brand?.name ?? null, unit: variant.price.final });
          setGuest(rows); writeGuest(rows);
        }
        setDrawer(true);
      } catch (e) { fail(e); throw e; }
    },
    setQty: async (line, qty) => {
      try {
        if (user) { qty <= 0 ? await delM.mutateAsync(line.key) : await updM.mutateAsync({ id: line.key, qty }); }
        else {
          const rows = guest.map((r) => (r.variant_id === line.key ? { ...r, qty } : r)).filter((r) => r.qty > 0);
          setGuest(rows); writeGuest(rows);
        }
      } catch (e) { fail(e); }
    },
    remove: async (line) => {
      try {
        if (user) await delM.mutateAsync(line.key);
        else { const rows = guest.filter((r) => r.variant_id !== line.key); setGuest(rows); writeGuest(rows); }
      } catch (e) { fail(e); }
    },
  };
  return <C.Provider value={value}>{children}</C.Provider>;
}
