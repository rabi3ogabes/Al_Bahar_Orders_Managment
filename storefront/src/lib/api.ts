import type { Tokens } from './types';
import { createLock, createRefresher } from './sessionSync';

const BASE = '/api/v1';
const TOKEN_KEY = 'albahar.tokens';

export class ApiError extends Error {
  status: number;
  code: string;
  errors: Record<string, string[]>;
  requestId?: string;
  /** The whole error body, for errors that carry data (`409 deal_changed` sends the fresh quote in `data.quote`). */
  body: any;
  constructor(status: number, body: any) {
    super(body?.message || 'Request failed');
    this.body = body;
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

const parseTokens = (raw: string | null): Tokens | null => {
  try { const t = raw ? JSON.parse(raw) : null; return t && typeof t.access_token === 'string' && typeof t.refresh_token === 'string' ? t : null; } catch { return null; }
};
const readSaved = (): string | null => { try { return localStorage.getItem(TOKEN_KEY); } catch { return null; } };

let tokens: Tokens | null = parseTokens(readSaved());
// Private mode or a full disk can refuse to save: the session then lives in this tab only, and "what is saved" means "what this tab has".
let storageWorks = true;
/** 'local': this tab changed the tokens. 'tab': another tab did (sign-in, a refresh or sign-out there), and this one has just followed. */
export type AuthSource = 'local' | 'tab';
const listeners = new Set<(source: AuthSource) => void>();
export const getTokens = () => tokens;
export const onAuthChange = (fn: (source: AuthSource) => void) => { listeners.add(fn); return () => { listeners.delete(fn); }; };

/** Work that needs the session while it still exists (for example removing this browser's push token): runs before sign-out. */
const beforeLogout = new Set<() => Promise<unknown>>();
export const onBeforeLogout = (fn: () => Promise<unknown>) => { beforeLogout.add(fn); return () => { beforeLogout.delete(fn); }; };
export const runBeforeLogout = async () => { await Promise.allSettled([...beforeLogout].map((f) => f())); };

export function setTokens(t: Tokens | null) {
  tokens = t;
  try { t ? localStorage.setItem(TOKEN_KEY, JSON.stringify(t)) : localStorage.removeItem(TOKEN_KEY); storageWorks = true; } catch { storageWorks = false; /* private mode */ }
  listeners.forEach((l) => l('local'));
}

// Every other tab of the store follows what one tab does: a refresh gives all of them the new tokens, a sign-out signs all of them out.
if (typeof window !== 'undefined') {
  window.addEventListener('storage', (e) => {
    if (e.key !== null && e.key !== TOKEN_KEY) return; // `null`: the browser's storage was cleared
    tokens = e.key === null ? null : parseTokens(e.newValue);
    listeners.forEach((l) => l('tab'));
  });
}

const refresher = createRefresher({
  stored: () => (storageWorks ? parseTokens(readSaved()) : tokens),
  apply: setTokens,
  attempt: async (refreshToken) => {
    const ctl = new AbortController();
    const timer = setTimeout(() => ctl.abort(), 15_000); // a stuck call must not hold the lock for every other tab
    try {
      const res = await fetch(`${BASE}/auth/refresh`, {
        method: 'POST',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Locale': locale },
        body: JSON.stringify({ refresh_token: refreshToken }),
        signal: ctl.signal,
      });
      if (res.status === 401 || res.status === 403) return { kind: 'invalid' };
      if (!res.ok) return { kind: 'transient' }; // 429, 5xx (a deploy), anything else: the session is not the problem
      const next = (await res.json())?.data?.tokens;
      return next?.access_token && next?.refresh_token ? { kind: 'ok', tokens: next as Tokens } : { kind: 'transient' };
    } catch { return { kind: 'transient' }; } finally { clearTimeout(timer); }
  },
  withLock: createLock({
    locks: typeof navigator !== 'undefined' && navigator.locks ? navigator.locks : null,
    lease: typeof localStorage !== 'undefined' ? { get: (k) => { try { return localStorage.getItem(k); } catch { return null; } }, set: (k, v) => { try { localStorage.setItem(k, v); } catch { /* no lease */ } }, remove: (k) => { try { localStorage.removeItem(k); } catch { /* ignore */ } } } : null,
    now: Date.now, sleep: (ms) => new Promise((r) => setTimeout(r, ms)), random: Math.random,
    id: `${Date.now().toString(36)}-${Math.random().toString(36).slice(2)}`,
  }),
  now: Date.now,
});

export interface ReqOpts { method?: string; query?: Record<string, unknown>; body?: unknown; form?: FormData; headers?: Record<string, string>; auth?: boolean; signal?: AbortSignal; /** The answer is a file (a PDF): resolves to a Blob instead of JSON. */ blob?: boolean }

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

const FRIENDLY_RETRY: Record<'en' | 'ar', string> = {
  en: 'We could not refresh your sign-in just now. Please try again in a moment.',
  ar: 'تعذّر تجديد تسجيل دخولك الآن. يرجى المحاولة بعد لحظات.',
};

export async function api<T = any>(path: string, opts: ReqOpts = {}, retried = false): Promise<T> {
  const headers: Record<string, string> = { Accept: opts.blob ? 'application/pdf, application/json' : 'application/json', 'X-Locale': locale, ...opts.headers };
  if (opts.body !== undefined) headers['Content-Type'] = 'application/json';
  const sent = tokens?.access_token && opts.auth !== false ? tokens.access_token : null;
  if (sent) headers.Authorization = `Bearer ${sent}`;
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
  if (res.status === 401 && sent && !retried) {
    const outcome = await refresher.refresh(sent);
    if (outcome === 'ok') return api<T>(path, opts, true);
    // The server could not be asked right now (a deploy, a busy moment): the sign-in is kept, this one request fails gently.
    if (outcome === 'transient') throw new ApiError(503, { message: FRIENDLY_RETRY[locale], code: 'session_refresh_unavailable' });
  }
  if (res.status === 204) return undefined as T;
  if (opts.blob && res.ok) return (await res.blob()) as T;
  const json = await res.json().catch(() => null);
  if (!res.ok) {
    // Signed out for certain: the refreshed sign-in was refused too (an account switched off), or the refresh itself was refused (already cleared).
    if (res.status === 401 && sent && retried && tokens) setTokens(null);
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
