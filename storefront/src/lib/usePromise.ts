import { useQuery } from '@tanstack/react-query';
import { get, post } from './api';
import { useAuth } from './auth';
import { useI18n } from './i18n';
import { promiseKey, promiseLines, type BasketPromise } from './promise';
import type { Address } from './types';

/**
 * "When will I get it?" (docs/guides/DELIVERY_PROMISE_ASSEMBLY_AND_SHARING.md): the server's answer for one product on its page, or for the basket.
 * An answer that cannot be had (a busy server, no network) is simply no answer: the page shows nothing and buying is never blocked.
 */
const FRESH_MS = 30_000;

export function useProductPromise(o: { productId: string | undefined; variantId: string | null; qty: number; assembly: boolean; branchId: string | null; type: 'delivery' | 'pickup'; enabled?: boolean }) {
  const { locale } = useI18n();
  return useQuery({
    queryKey: ['promise', 'product', o.productId, o.variantId, o.qty, o.assembly, o.branchId, o.type, locale], enabled: !!o.productId && !!o.variantId && o.enabled !== false, staleTime: FRESH_MS, retry: false,
    queryFn: async () => (await get<{ data: BasketPromise }>(`/products/${o.productId}/delivery-promise`, {
      variant_id: o.variantId, quantity: o.qty, assembly: o.type === 'delivery' && o.assembly, branch_id: o.branchId, fulfillment_type: o.type })).data,
  });
}

/** The basket on screen (a guest's browser cart or the optimistic account cart), sent as plain identifiers; a signed-in customer's default address finds the zone. */
export function useBasketPromise(o: { lines: { variantId: string; qty: number; assembly?: boolean }[]; type: 'delivery' | 'pickup'; branchId: string | null; enabled?: boolean }) {
  const { locale } = useI18n();
  const { user } = useAuth();
  const addresses = useQuery({ queryKey: ['addresses', user?.id], enabled: !!user, queryFn: async () => (await get<{ data: Address[] }>('/me/addresses')).data });
  const addressId = o.type === 'delivery' ? (addresses.data?.find((a) => a.is_default) ?? addresses.data?.[0])?.id ?? null : null;
  const lines = promiseLines(o.lines);
  return useQuery({
    queryKey: ['promise', 'basket', promiseKey(lines), o.type, o.branchId, addressId, !!user, locale], enabled: lines.length > 0 && o.enabled !== false && (!user || !addresses.isLoading), staleTime: FRESH_MS, retry: false,
    placeholderData: (previous) => previous,
    queryFn: async () => (await post<{ data: BasketPromise }>('/cart/promise', { lines, fulfillment_type: o.type, branch_id: o.branchId ?? undefined, address_id: addressId ?? undefined })).data,
  });
}
