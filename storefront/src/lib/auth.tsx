import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { api, ApiError, get, getTokens, onAuthChange, post, runBeforeLogout, setTokens, patch } from './api';
import type { AuthResult, User } from './types';

interface Auth {
  user: User | null; loading: boolean
  requestOtp: (phone: string, purpose?: 'login' | 'password_reset') => Promise<{ resend_in: number; expires_in: number; debug_code?: string }>
  verifyOtp: (phone: string, code: string) => Promise<AuthResult>
  register: (p: { registration_token: string; name: string; email?: string; password?: string; password_confirmation?: string; marketing_consent?: boolean; referral_code?: string }) => Promise<void>
  passwordLogin: (identifier: string, password: string) => Promise<void>
  logout: () => Promise<void>
  updateProfile: (p: Partial<Pick<User, 'name' | 'email' | 'locale' | 'marketing_consent' | 'date_of_birth' | 'gender' | 'alternate_phone'>>) => Promise<void>
  reload: () => Promise<void>
}
const Ctx = createContext<Auth>(null as unknown as Auth);
export const useAuth = () => useContext(Ctx);

const device = () => ({ device_name: 'Web storefront', platform: 'web' as const });

export function AuthProvider({ children }: { children: ReactNode }) {
  const qc = useQueryClient();
  const [user, setUser] = useState<User | null>(null);
  const [loading, setLoading] = useState<boolean>(!!getTokens());

  const userRef = useRef<User | null>(null);
  userRef.current = user;

  // When the server cannot be reached (or is being updated) the person is not signed out: their sign-in is kept and who they are is asked again, a little later each time.
  const retry = useRef<{ n: number; timer?: number }>({ n: 0 });
  const reload = useCallback(async () => {
    window.clearTimeout(retry.current.timer);
    if (!getTokens()) { setUser(null); setLoading(false); return; }
    try { setUser((await get<{ data: User }>('/me')).data); retry.current.n = 0; } catch (e) {
      // Only a clear "this sign-in is over" shows the signed-out store.
      if (e instanceof ApiError && (e.status === 401 || e.status === 403) || !getTokens()) setUser(null);
      else if (retry.current.n < 8) { retry.current.timer = window.setTimeout(() => void reload(), Math.min(30_000, 2_000 * 2 ** retry.current.n)); retry.current.n += 1; }
    } finally { setLoading(false); }
  }, []);

  useEffect(() => { void reload(); return () => window.clearTimeout(retry.current.timer); }, [reload]);
  // The connection came back while the account was still unknown: ask at once.
  useEffect(() => {
    const online = () => { if (getTokens() && !userRef.current) void reload(); };
    window.addEventListener('online', online);
    return () => window.removeEventListener('online', online);
  }, [reload]);
  useEffect(() => onAuthChange((source) => {
    if (!getTokens()) { setUser(null); qc.clear(); return; } // signed out here, or in another tab
    if (source === 'tab' && !userRef.current) void reload(); // signed in from another tab
  }), [qc, reload]);

  const finish = useCallback(async (r: { user: User; tokens: AuthResult['tokens'] }) => {
    setTokens(r.tokens!);
    setUser(r.user);
    // Anything cached for the previous (guest) identity is stale.
    await qc.invalidateQueries();
  }, [qc]);

  const value = useMemo<Auth>(() => ({
    user, loading, reload,
    requestOtp: async (phone, purpose = 'login') => (await post('/auth/otp/request', { phone, purpose })).data,
    verifyOtp: async (phone, code) => {
      const res = (await post('/auth/otp/verify', { phone, code, ...device() })).data as AuthResult;
      if (res.status === 'authenticated') await finish({ user: res.user!, tokens: res.tokens });
      return res;
    },
    register: async (p) => {
      const res = (await post('/auth/register', { ...p, locale: document.documentElement.lang === 'ar' ? 'ar' : 'en', ...device() })).data as AuthResult;
      await finish({ user: res.user!, tokens: res.tokens });
    },
    passwordLogin: async (identifier, password) => {
      const isEmail = identifier.includes('@');
      const res = (await post('/auth/login', { [isEmail ? 'email' : 'phone']: identifier.trim(), password, ...device() })).data as AuthResult;
      await finish({ user: res.user!, tokens: res.tokens });
    },
    logout: async () => {
      await runBeforeLogout();
      try { await api('/auth/logout', { method: 'POST', body: {} }); } catch { /* token may already be gone */ }
      setTokens(null);
    },
    updateProfile: async (p) => { setUser((await patch<{ data: User }>('/me', p)).data); },
  }), [user, loading, reload, finish]);

  return <Ctx.Provider value={value}>{children}</Ctx.Provider>;
}
