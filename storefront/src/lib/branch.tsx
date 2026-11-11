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
  const value = useMemo<Ctx>(() => ({
    branches, loading: isLoading, branchId: id, branch: branches.find((b) => b.id === id) ?? null,
    select: (v) => { setId(v); try { v ? localStorage.setItem(KEY, v) : localStorage.removeItem(KEY); } catch { /* ignore */ } },
  }), [branches, isLoading, id]);
  return <C.Provider value={value}>{children}</C.Provider>;
}
