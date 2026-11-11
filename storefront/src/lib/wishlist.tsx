import { createContext, useContext, useMemo, type ReactNode } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { del, get, post } from './api';
import { useAuth } from './auth';
import type { ProductCard } from './types';

interface Item { id: string; product: ProductCard | null; variant_id: string | null; added_at: string }
interface Ctx { items: Item[]; has: (productId: string) => boolean; toggle: (productId: string) => Promise<void>; loading: boolean }
const C = createContext<Ctx>(null as unknown as Ctx);
export const useWishlist = () => useContext(C);

export function WishlistProvider({ children, onNeedAuth }: { children: ReactNode; onNeedAuth: () => void }) {
  const { user } = useAuth();
  const qc = useQueryClient();
  const q = useQuery({ queryKey: ['wishlist', user?.id], enabled: !!user, queryFn: async () => (await get<{ data: Item[] }>('/me/wishlist')).data });
  const add = useMutation({ mutationFn: (id: string) => post('/me/wishlist', { product_id: id }), onSettled: () => qc.invalidateQueries({ queryKey: ['wishlist'] }) });
  const rm = useMutation({ mutationFn: (id: string) => del(`/me/wishlist/${id}`), onSettled: () => qc.invalidateQueries({ queryKey: ['wishlist'] }) });
  const value = useMemo<Ctx>(() => {
    const items = (q.data ?? []).filter((i) => i.product);
    const ids = new Set(items.map((i) => i.product!.id));
    return {
      items, loading: q.isLoading, has: (id) => ids.has(id),
      toggle: async (id) => {
        if (!user) { onNeedAuth(); return; }
        if (ids.has(id)) await rm.mutateAsync(id); else await add.mutateAsync(id);
      },
    };
  }, [q.data, q.isLoading, user, add, rm, onNeedAuth]);
  return <C.Provider value={value}>{children}</C.Provider>;
}
