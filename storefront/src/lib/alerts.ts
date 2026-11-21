import { useCallback } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ApiError, del, get, post } from './api';
import { useAuth } from './auth';
import { DEFAULT_ALERTS_CONFIG, findAlert, type AlertType, type AlertsConfig, type StockAlert } from './alertsCore';

/**
 * The customer's alerts ("Notify me when it is back", "Tell me if the price drops") and what the store offers. One list serves the product page,
 * the wishlist and the "My alerts" window, so every control shows the same state at once.
 */
export function useStockAlerts() {
  const { user } = useAuth();
  const qc = useQueryClient();
  const config = useQuery({ queryKey: ['stock-alerts-config'], staleTime: 5 * 60_000, queryFn: async () => (await get<{ data: AlertsConfig }>('/stock-alerts/config')).data });
  const list = useQuery({ queryKey: ['stock-alerts', user?.id], enabled: !!user, queryFn: async () => (await get<{ data: StockAlert[] }>('/me/stock-alerts')).data });
  const refresh = useCallback(() => qc.invalidateQueries({ queryKey: ['stock-alerts'] }), [qc]);
  const add = useMutation({
    mutationFn: (p: { variantId: string; type: AlertType; branchId?: string | null }) => post('/me/stock-alerts', { variant_id: p.variantId, type: p.type, branch_id: p.branchId || undefined }),
    onSettled: refresh,
  });
  const remove = useMutation({ mutationFn: (id: string) => del(`/me/stock-alerts/${id}`), onSettled: refresh });
  return {
    config: config.data ?? DEFAULT_ALERTS_CONFIG,
    list: list.data,
    loading: !!user && list.isLoading,
    failed: list.isError,
    reload: () => list.refetch(),
    find: (variantId: string | null | undefined, type: AlertType) => findAlert(list.data, variantId, type),
    subscribe: add.mutateAsync,
    unsubscribe: remove.mutateAsync,
    busy: add.isPending || remove.isPending,
  };
}

/** The server's own plain sentence for a refused request ("This item is available now"), or the store's general one. */
export const alertError = (e: unknown, fallback: string): string => (e instanceof ApiError ? e.message : fallback);
