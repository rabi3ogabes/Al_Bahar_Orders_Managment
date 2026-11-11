import { createContext, useContext, useEffect, useMemo, useState, type ReactNode } from 'react';
import { useQuery } from '@tanstack/react-query';
import { get } from './api';
import type { Branch } from './types';

const KEY = 'albahar.branch';
interface Ctx { branches: Branch[]; branch: Branch | null; branchId: string | null; select: (id: string | null) => void; loading: boolean }
const C = createContext<Ctx>(null as unknown as Ctx);
export const useBranch = () => useContext(C);

export function BranchProvider({ children }: { children: ReactNode }) {
  const { data, isLoading } = useQuery({ queryKey: ['branches'], queryFn: async () => (await get<{ data: Branch[] }>('/branches')).data, staleTime: 5 * 60_000 });
  const [id, setId] = useState<string | null>(() => { try { return localStorage.getItem(KEY); } catch { return null; } });
  const branches = data ?? [];
  // Forget a stored showroom that no longer exists.
  useEffect(() => { if (id && data && !data.some((b) => b.id === id)) setId(null); }, [id, data]);
  // Automatic choice when the shopper has not picked one: the only branch, else the nearest open one that delivers
  // (location is used only if the browser already allows it, so there is no surprise prompt), else the first open one.
  const [autoId, setAutoId] = useState<string | null>(null);
  useEffect(() => {
    if (id || !data || data.length === 0) { setAutoId(null); return; }
    const best = (list: Branch[]) => { const d = list.filter((b) => b.delivery_enabled !== false); const pool = d.length ? d : list; return (pool.find((b) => b.opening?.is_open !== false) ?? pool[0])?.id ?? null; };
    if (data.length === 1) { setAutoId(data[0].id); return; }
    setAutoId(best(data));
    let alive = true;
    (async () => {
      try {
        const perm = await navigator.permissions?.query({ name: 'geolocation' as PermissionName });
        if (perm?.state !== 'granted') return;
        navigator.geolocation.getCurrentPosition(async (pos) => {
          try {
            const near = (await get<{ data: Branch[] }>('/branches/nearby', { lat: pos.coords.latitude, lng: pos.coords.longitude, radius_km: 100 })).data;
            if (alive && near.length) setAutoId(best(near));
          } catch { /* keep the fallback */ }
        }, () => undefined, { timeout: 8000, maximumAge: 600000 });
      } catch { /* permissions API unavailable */ }
    })();
    return () => { alive = false; };
  }, [id, data]);
  const effective = id ?? autoId;
  const value = useMemo<Ctx>(() => ({
    branches, loading: isLoading, branchId: effective, branch: branches.find((b) => b.id === effective) ?? null,
    select: (v) => { setId(v); try { v ? localStorage.setItem(KEY, v) : localStorage.removeItem(KEY); } catch { /* ignore */ } },
  }), [branches, isLoading, id, effective]);
  return <C.Provider value={value}>{children}</C.Provider>;
}
