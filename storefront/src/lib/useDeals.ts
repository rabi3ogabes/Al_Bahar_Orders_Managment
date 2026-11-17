import { useEffect, useMemo } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { ApiError, get } from './api';
import { useAuth } from './auth';
import { useBranch } from './branch';
import { nextChangeAt, readDeal, readDealsData, serverNow, setServerTime, type Deal, type DealsData } from './deals';

/** Public deals (docs/api/DEALS.md, section 5). The clock offset of every response is kept for all countdowns (`setServerTime`). */
export interface DealsQuery { home?: boolean; limitProducts?: number; branchId?: string | null }

export async function fetchDeals(opts: DealsQuery = {}, signal?: AbortSignal): Promise<DealsData> {
  const r = await get<{ data?: unknown }>('/deals', { home: opts.home ? true : undefined, limit_products: opts.limitProducts, branch_id: opts.branchId || undefined }, signal);
  const data = readDealsData(r?.data);
  setServerTime(data.serverTime);
  return data;
}

/** One deal with a page of its products (24 per page). A deal that ended or was paused answers 404 `deal_not_found`. */
export async function fetchDeal(id: string, page = 1, signal?: AbortSignal, branchId?: string | null): Promise<Deal> {
  const r = await get<{ data?: unknown }>(`/deals/${encodeURIComponent(id)}`, { page: page > 1 ? page : undefined, branch_id: branchId || undefined }, signal);
  const raw = r?.data as { server_time?: string } | undefined;
  setServerTime(raw?.server_time);
  const deal = readDeal(r?.data);
  if (!deal) throw new ApiError(404, { code: 'deal_not_found', message: 'Deal not found' });
  return deal;
}

const hidden = () => typeof document !== 'undefined' && document.visibilityState === 'hidden';
const MAX_TIMEOUT = 2_147_483_647;

/**
 * The deals the store shows. Fetched again every 60 s while the tab is visible (never while it is hidden), when the tab comes back,
 * and a moment after any deal starts or ends, so a timer reaching zero swaps the page to the truth without a reload.
 */
export function useDeals({ home = false, limitProducts = 8, enabled = true }: DealsQuery & { enabled?: boolean } = {}) {
  const { user } = useAuth();
  const { branchId } = useBranch();
  const q = useQuery({
    // `customer_remaining` is per person, so a different sign-in is a different answer; the products shown depend on the chosen showroom.
    queryKey: ['deals', { home, limitProducts }, user?.id ?? null, branchId || null], enabled, staleTime: 30_000,
    queryFn: ({ signal }) => fetchDeals({ home, limitProducts, branchId }, signal),
    // react-query pauses the interval while the tab is hidden (refetchIntervalInBackground is off) and asks again when it comes back.
    refetchInterval: 60_000, refetchIntervalInBackground: false, refetchOnWindowFocus: true, refetchOnReconnect: true,
  });
  const { refetch } = q;
  const data = q.data;
  useEffect(() => {
    const at = nextChangeAt(data);
    if (at === null) return;
    // Slightly after the server flips the phase, so the answer already says so.
    const wait = Math.min(MAX_TIMEOUT, Math.max(500, at - serverNow() + 800));
    const id = setTimeout(() => { if (!hidden()) void refetch(); }, wait);
    return () => clearTimeout(id);
  }, [data, refetch]);
  return q;
}

/** Is a deal live right now: the dot next to "Deals" in the header and the menu. Asks the same small question as the app (one product per deal). */
export function useLiveDeal(): boolean {
  const q = useDeals({ home: true, limitProducts: 1 });
  return useMemo(() => !!q.data?.live.some((d) => d.phase === 'live'), [q.data]);
}

/** A page that shows product prices asks for a refresh when a deal it showed has just ended. */
export function useRefreshOnDealEnd() {
  const qc = useQueryClient();
  return () => { void qc.invalidateQueries({ queryKey: ['deals'] }); void qc.invalidateQueries({ queryKey: ['product'] }); void qc.invalidateQueries({ queryKey: ['home'] }); void qc.invalidateQueries({ queryKey: ['products'] }); };
}
