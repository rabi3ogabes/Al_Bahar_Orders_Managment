import type { Tokens } from './types';

const BASE = '/api/v1';
const TOKEN_KEY = 'albahar.tokens';

export class ApiError extends Error {
  status: number;
  code: string;
  errors: Record<string, string[]>;
  requestId?: string;
  constructor(status: number, body: any) {
    super(body?.message || 'Request failed');
    this.status = status;
    this.code = body?.code || 'error';
    this.errors = body?.errors || {};
    this.requestId = body?.request_id;
  }
  /** First validation message for a field, if any. */
  field(name: string): string | undefined { return this.errors[name]?.[0]; }
}

let locale: 'en' | 'ar' = 'en';
export const setApiLocale = (l: 'en' | 'ar') => { locale = l; };

let tokens: Tokens | null = null;
try { const raw = localStorage.getItem(TOKEN_KEY); tokens = raw ? JSON.parse(raw) : null; } catch { tokens = null; }
const listeners = new Set<() => void>();
export const getTokens = () => tokens;
export const onAuthChange = (fn: () => void) => { listeners.add(fn); return () => { listeners.delete(fn); }; };

/** Work that needs the session while it still exists (for example removing this browser's push token): runs before sign-out. */
const beforeLogout = new Set<() => Promise<unknown>>();
export const onBeforeLogout = (fn: () => Promise<unknown>) => { beforeLogout.add(fn); return () => { beforeLogout.delete(fn); }; };
export const runBeforeLogout = async () => { await Promise.allSettled([...beforeLogout].map((f) => f())); };

export function setTokens(t: Tokens | null) {
  tokens = t;
  try { t ? localStorage.setItem(TOKEN_KEY, JSON.stringify(t)) : localStorage.removeItem(TOKEN_KEY); } catch { /* private mode */ }
  listeners.forEach((l) => l());
}

let refreshing: Promise<boolean> | null = null;
async function refresh(): Promise<boolean> {
  if (!tokens?.refresh_token) return false;
  refreshing ??= (async () => {
    try {
      const res = await fetch(`${BASE}/auth/refresh`, {
        method: 'POST',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Locale': locale },
        body: JSON.stringify({ refresh_token: tokens!.refresh_token }),
      });
      if (!res.ok) { setTokens(null); return false; }
      const json = await res.json();
      setTokens(json.data.tokens);
      return true;
    } catch { return false; } finally { refreshing = null; }
  })();
  return refreshing;
}

export interface ReqOpts { method?: string; query?: Record<string, unknown>; body?: unknown; form?: FormData; headers?: Record<string, string>; auth?: boolean; signal?: AbortSignal }

export function qs(query?: Record<string, unknown>): string {
  if (!query) return '';
  const p = new URLSearchParams();
  for (const [k, v] of Object.entries(query)) {
    if (v === undefined || v === null || v === '') continue;
    if (Array.isArray(v)) v.forEach((x) => p.append(`${k}[]`, String(x)));
    else if (typeof v === 'boolean') p.set(k, v ? '1' : '0');
    else p.set(k, String(v));
  }
  const s = p.toString();
  return s ? `?${s}` : '';
}

export async function api<T = any>(path: string, opts: ReqOpts = {}, retried = false): Promise<T> {
  const headers: Record<string, string> = { Accept: 'application/json', 'X-Locale': locale, ...opts.headers };
  if (opts.body !== undefined) headers['Content-Type'] = 'application/json';
  if (tokens?.access_token && opts.auth !== false) headers.Authorization = `Bearer ${tokens.access_token}`;
  let res: Response;
  try {
    res = await fetch(`${BASE}${path}${qs(opts.query)}`, {
      method: opts.method ?? (opts.body !== undefined || opts.form ? 'POST' : 'GET'),
      headers,
      body: opts.form ?? (opts.body !== undefined ? JSON.stringify(opts.body) : undefined),
      signal: opts.signal,
    });
  } catch (e: any) {
    if (e?.name === 'AbortError') throw e;
    throw new ApiError(0, { message: locale === 'ar' ? 'تعذّر الاتصال بالخادم. تحقق من اتصالك بالإنترنت وحاول مرة أخرى.' : 'We could not reach the server. Check your connection and try again.', code: 'network_error' });
  }
  if (res.status === 401 && !retried && tokens && opts.auth !== false && (await refresh())) return api<T>(path, opts, true);
  if (res.status === 204) return undefined as T;
  const json = await res.json().catch(() => null);
  if (!res.ok) {
    if (res.status === 401 && tokens) setTokens(null);
    throw new ApiError(res.status, json);
  }
  return json as T;
}

export const get = <T = any>(path: string, query?: Record<string, unknown>, signal?: AbortSignal) => api<T>(path, { query, signal });
export const post = <T = any>(path: string, body?: unknown, headers?: Record<string, string>) => api<T>(path, { method: 'POST', body: body ?? {}, headers });
export const put = <T = any>(path: string, body?: unknown) => api<T>(path, { method: 'PUT', body: body ?? {} });
export const patch = <T = any>(path: string, body?: unknown) => api<T>(path, { method: 'PATCH', body: body ?? {} });
export const del = <T = any>(path: string, body?: unknown) => api<T>(path, { method: 'DELETE', body });

export const uuid = () => (crypto.randomUUID ? crypto.randomUUID() : `${Date.now()}-${Math.random().toString(16).slice(2)}-${Math.random().toString(16).slice(2)}`);
