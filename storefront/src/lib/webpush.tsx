import { useCallback, useEffect, useRef, useState, useSyncExternalStore } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import type { Messaging } from 'firebase/messaging';
import { ApiError, api, del, onBeforeLogout, post, uuid } from './api';
import { useAuth } from './auth';
import { useI18n } from './i18n';
import { useToast } from './toast';
import {
  addUser, detectSupport, helpFamily, isDismissed, needsRegistration, normalizeMessage, orderToasts, parseConfig, parseStoredToken,
  parseUsers, promptApplies, removeUser, resolveState, type BrowserPermission, type HelpFamily, type PushConfig, type PushEnv,
  type PushState, type Support, type WebFirebaseConfig,
} from './webpush-core';

/**
 * Browser notifications for signed-in customers, switched on from the dashboard (Push notifications) and by the customer.
 *
 * Cost until used: nothing. Without a granted permission this module only reads `Notification.permission` and localStorage; the
 * Firebase SDK (two dynamic imports, its own chunk) is fetched when a customer taps Enable, or on later visits for a customer who
 * already did. The service worker is NOT part of the storefront build: the server serves it at /firebase-messaging-sw.js with the
 * current Firebase settings inside, and it is the only service worker the store registers (no offline caching).
 *
 * The decisions (supported? which state? re-register? which toast?) live in webpush-core.ts and are unit tested.
 */

const SW_URL = '/firebase-messaging-sw.js';
const APP_NAME = 'albahar-web-push';
const K = { token: 'albahar.push.token', users: 'albahar.push.users', device: 'albahar.push.device', dismissed: 'albahar.push.dismissed' } as const;
const CFG_TTL_MS = 5 * 60_000;
const CFG_RETRY_MS = 60_000;

type ErrorCode = 'sw' | 'token' | 'server';
class PushError extends Error { constructor(readonly code: ErrorCode | 'unsupported') { super(code); } }
const codeOf = (e: unknown): ErrorCode | 'unsupported' => (e instanceof PushError ? e.code : e instanceof ApiError ? 'server' : 'token');

/* ───────────── localStorage (every access may throw in private mode) ───────────── */
const read = (k: string): string | null => { try { return localStorage.getItem(k); } catch { return null; } };
const write = (k: string, v: string | null) => { try { v === null ? localStorage.removeItem(k) : localStorage.setItem(k, v); } catch { /* private mode: the choice just is not remembered */ } };

/* ───────────── The browser ───────────── */
function readEnv(): PushEnv {
  const nav = navigator as Navigator & { standalone?: boolean };
  return {
    secure: window.isSecureContext === true,
    serviceWorker: 'serviceWorker' in navigator,
    pushManager: 'PushManager' in window,
    notification: 'Notification' in window,
    ua: navigator.userAgent, platform: navigator.platform ?? '', touchPoints: navigator.maxTouchPoints ?? 0,
    standalone: nav.standalone === true || window.matchMedia?.('(display-mode: standalone)').matches === true,
  };
}
let envCache: { support: Support; family: HelpFamily } | null = null;
const env = () => (envCache ??= { support: detectSupport(readEnv()), family: helpFamily(readEnv()) });
const readPermission = (): BrowserPermission => (typeof Notification === 'undefined' ? 'default' : (Notification.permission as BrowserPermission));

/* ───────────── The store (read with useSyncExternalStore, like lib/realtime.tsx) ───────────── */
interface Raw { cfg: PushConfig | null; cfgReady: boolean; permission: BrowserPermission; users: string[]; busy: boolean; error: ErrorCode | null; unsupported: boolean }
let raw: Raw = { cfg: null, cfgReady: false, permission: readPermission(), users: parseUsers(read(K.users)), busy: false, error: null, unsupported: false };
const subs = new Set<() => void>();
const patch = (p: Partial<Raw>) => { raw = { ...raw, ...p }; subs.forEach((f) => f()); };
const subscribe = (f: () => void) => { subs.add(f); return () => { subs.delete(f); }; };
const snapshot = () => raw;

/* ───────────── Config from the dashboard ───────────── */
let cfgLoad: Promise<PushConfig | null> | null = null;
let cfgAt = 0;
let cfgOk = false;
function ensureConfig(): Promise<PushConfig | null> {
  if (cfgLoad && Date.now() - cfgAt < (cfgOk ? CFG_TTL_MS : CFG_RETRY_MS)) return cfgLoad;
  cfgAt = Date.now();
  // Public endpoint: no token, so an expired session cannot trigger a refresh just to read this.
  cfgLoad = api('/push-config', { auth: false }).then((r) => { cfgOk = true; return parseConfig(r); }, () => { cfgOk = false; return null; });
  void cfgLoad.then((cfg) => patch({ cfg, cfgReady: true }));
  return cfgLoad;
}

/* ───────────── Firebase, loaded on demand ───────────── */
interface Fb { messaging: Messaging; mod: typeof import('firebase/messaging') }
let fb: Promise<Fb> | null = null;
let fbKey = '';
function loadFirebase(web: WebFirebaseConfig): Promise<Fb> {
  const key = [web.projectId, web.messagingSenderId, web.appId, web.apiKey].join('|');
  if (fb && fbKey === key) return fb;
  fbKey = key;
  const p = (async () => {
    const [app, mod] = await Promise.all([import('firebase/app'), import('firebase/messaging')]);
    if (!(await mod.isSupported())) { patch({ unsupported: true }); throw new PushError('unsupported'); }
    const old = app.getApps().find((a) => a.name === APP_NAME);
    if (old) await app.deleteApp(old); // the dashboard values changed while this page was open
    const a = app.initializeApp({ apiKey: web.apiKey, authDomain: web.authDomain ?? undefined, projectId: web.projectId, messagingSenderId: web.messagingSenderId, appId: web.appId }, APP_NAME);
    return { messaging: mod.getMessaging(a), mod };
  })();
  fb = p;
  p.catch(() => { if (fb === p) fb = null; });
  return p;
}

const withTimeout = <T,>(p: Promise<T>, ms: number, code: ErrorCode): Promise<T> =>
  new Promise<T>((resolve, reject) => { const t = window.setTimeout(() => reject(new PushError(code)), ms); p.then((v) => { clearTimeout(t); resolve(v); }, (e) => { clearTimeout(t); reject(e); }); });

/** register() resolves before the worker is usable, and the SDK only waits for that when it registers the worker itself. */
function whenActive(reg: ServiceWorkerRegistration): Promise<void> {
  if (reg.active) return Promise.resolve();
  const sw = reg.installing ?? reg.waiting;
  if (!sw) return Promise.reject(new PushError('sw'));
  return new Promise<void>((resolve, reject) => {
    sw.addEventListener('statechange', () => { if (sw.state === 'activated') resolve(); else if (sw.state === 'redundant') reject(new PushError('sw')); });
  });
}
async function registerWorker(): Promise<ServiceWorkerRegistration> {
  try {
    const reg = await navigator.serviceWorker.register(SW_URL, { scope: '/' });
    await withTimeout(whenActive(reg), 10_000, 'sw');
    return reg;
  } catch (e) { throw e instanceof PushError ? e : new PushError('sw'); }
}

/* ───────────── Foreground messages ───────────── */
let fgOff: (() => void) | null = null;
let fgFor: Messaging | null = null;
let fgHandler: ((payload: unknown) => void) | null = null;
function attachForeground(f: Fb) {
  if (fgOff && fgFor === f.messaging) return;
  fgOff?.();
  fgFor = f.messaging;
  fgOff = f.mod.onMessage(f.messaging, (p) => fgHandler?.(p));
}
function detachForeground() { fgOff?.(); fgOff = null; fgFor = null; }

/* ───────────── This device on the server ───────────── */
const readToken = () => parseStoredToken(read(K.token));
const deviceId = () => { let id = read(K.device); if (!id) { id = uuid(); write(K.device, id); } return id; };

/** Revoking the browser's own token runs after the switch is already off; a later Enable waits for it so it cannot delete the new token. */
let revoking: Promise<void> = Promise.resolve();
const revokeBrowserToken = (web: WebFirebaseConfig): Promise<void> => (async () => {
  try {
    const f = await loadFirebase(web);
    await Promise.race([f.mod.deleteToken(f.messaging), new Promise((resolve) => window.setTimeout(resolve, 8000))]);
  } catch { /* nothing left to revoke, or offline: the server already ignores this token */ }
})();

/** Gets (or refreshes) this browser's token and tells the server, only when something changed. Throws a PushError. */
async function registerThisDevice(web: WebFirebaseConfig, userId: string): Promise<void> {
  await revoking;
  const f = await loadFirebase(web);
  const registration = await registerWorker();
  let token: string;
  try { token = await withTimeout(f.mod.getToken(f.messaging, { vapidKey: web.vapidKey, serviceWorkerRegistration: registration }), 20_000, 'token'); } catch (e) { throw e instanceof PushError ? e : new PushError('token'); }
  if (!token) throw new PushError('token');
  const stored = readToken();
  if (needsRegistration(stored, token, userId, Date.now())) {
    try { await post('/me/devices', { platform: 'web', push_token: token, locale: document.documentElement.lang === 'ar' ? 'ar' : 'en', device_id: deviceId() }); } catch (e) { throw e instanceof PushError ? e : new PushError('server'); }
    write(K.token, JSON.stringify({ token, userId, at: Date.now() }));
    // A rotated token leaves the old one on the server until a send to it fails: remove it now so this browser is not notified twice.
    if (stored && stored.token !== token) void del('/me/devices', { push_token: stored.token }).catch(() => undefined);
  }
  attachForeground(f);
}

/** Best effort, used on sign-out and when the browser permission is taken away. Never blocks for long and never throws. */
export async function forgetThisDevice(): Promise<void> {
  const stored = readToken();
  detachForeground();
  if (!stored) return;
  try { await Promise.race([del('/me/devices', { push_token: stored.token }), new Promise((_, no) => window.setTimeout(no, 3000))]); } catch { /* the server drops the token on the first failed send */ }
  write(K.token, null);
}

/** Silent: runs at app start and after sign-in, only for a customer who turned push on here and whose browser still allows it. */
async function syncDevice(userId: string): Promise<void> {
  if (env().support !== 'ok') return;
  const permission = readPermission();
  patch({ permission });
  if (permission === 'denied') { if (readToken()) void forgetThisDevice(); return; }
  if (permission !== 'granted' || !raw.users.includes(userId)) return;
  const cfg = await ensureConfig();
  if (!cfg?.enabled || !cfg.web) return;
  try { await registerThisDevice(cfg.web, userId); patch({ error: null }); } catch (e) { const c = codeOf(e); if (c !== 'unsupported') patch({ error: c }); }
}

export type EnableResult = 'granted' | 'denied' | 'dismissed' | 'failed';
/** Must run from a click or tap: the browser only shows its permission prompt for a user gesture. */
async function enable(userId: string): Promise<EnableResult> {
  if (raw.busy || env().support !== 'ok') return 'failed';
  patch({ busy: true, error: null });
  try {
    let permission = readPermission();
    // Asked before anything is awaited, so it still counts as the click's gesture.
    if (permission === 'default') permission = (await Notification.requestPermission()) as BrowserPermission;
    patch({ permission });
    if (permission !== 'granted') return permission === 'denied' ? 'denied' : 'dismissed';
    const cfg = await ensureConfig();
    if (!cfg?.enabled || !cfg.web) return 'failed';
    await registerThisDevice(cfg.web, userId);
    const users = addUser(raw.users, userId);
    write(K.users, JSON.stringify(users));
    patch({ users });
    return 'granted';
  } catch (e) {
    const c = codeOf(e);
    if (c !== 'unsupported') patch({ error: c });
    return 'failed';
  } finally { patch({ busy: false }); }
}

async function disable(userId: string): Promise<boolean> {
  if (raw.busy) return false;
  patch({ busy: true, error: null });
  try {
    const stored = readToken();
    // The server must stop sending first; if that fails the switch stays on, so what the page says is what happens.
    if (stored) await del('/me/devices', { push_token: stored.token });
    const users = removeUser(raw.users, userId);
    write(K.users, JSON.stringify(users));
    write(K.token, null);
    detachForeground();
    patch({ users });
    if (raw.cfg?.web) revoking = revokeBrowserToken(raw.cfg.web);
    return true;
  } catch { patch({ error: 'server' }); return false; } finally { patch({ busy: false }); }
}

/** Browser settings can change behind the page's back (the customer unblocks or blocks it, or iOS reinstalls). */
function syncPermission(userId: string) {
  const next = readPermission();
  if (next === raw.permission) return;
  patch({ permission: next });
  void syncDevice(userId);
}

/* ───────────── React ───────────── */

/** Mounted once under the providers. Renders nothing. Keeps this device registered and shows pushes that arrive while the store is open. */
export function WebPush() {
  const { user } = useAuth();
  const qc = useQueryClient();
  const toast = useToast();
  const { t } = useI18n();
  const userId = user?.id;
  const latest = useRef({ qc, toast, t });
  latest.current = { qc, toast, t };

  useEffect(() => onBeforeLogout(forgetThisDevice), []);

  useEffect(() => {
    fgHandler = (payload) => {
      const m = normalizeMessage(payload);
      if (!m) return;
      const { qc: client, toast: tt } = latest.current;
      // Same refresh the live order socket does, so the page is right whichever signal arrives first.
      void client.invalidateQueries({ queryKey: ['orders'] });
      void client.invalidateQueries({ queryKey: ['notifications'] });
      if (m.orderId) {
        void client.invalidateQueries({ queryKey: ['order', m.orderId] });
        void client.invalidateQueries({ queryKey: ['timeline', m.orderId] });
        if (!orderToasts.claim('push', m.orderId)) return; // the socket already told the customer
      }
      tt.push([m.title, m.body].filter(Boolean).join(' · '), 'info');
    };
    return () => { fgHandler = null; };
  }, []);

  useEffect(() => {
    if (!userId) { detachForeground(); return; }
    void syncDevice(userId);
    const check = () => { if (document.visibilityState === 'visible') syncPermission(userId); };
    window.addEventListener('focus', check);
    document.addEventListener('visibilitychange', check);
    return () => { window.removeEventListener('focus', check); document.removeEventListener('visibilitychange', check); };
  }, [userId]);

  return null;
}

export interface WebPushApi {
  /** False until the dashboard setting is known (show a skeleton, not a wrong state). */
  ready: boolean
  state: PushState
  busy: boolean
  /** Plain-words reason for state 'error'. */
  error: ErrorCode | null
  family: HelpFamily
  enable: () => Promise<EnableResult>
  disable: () => Promise<boolean>
}

export function useWebPush(): WebPushApi {
  const { user } = useAuth();
  const s = useSyncExternalStore(subscribe, snapshot, snapshot);
  const userId = user?.id;
  const { support, family } = env();
  const effective: Support = s.unsupported ? 'unsupported' : support;
  useEffect(() => { if (userId && effective !== 'unsupported') void ensureConfig(); }, [userId, effective]);
  const state = resolveState({ support: effective, config: s.cfg, permission: s.permission, optedIn: !!userId && s.users.includes(userId), error: s.error !== null });
  const on = useCallback(() => (userId ? enable(userId) : Promise.resolve<EnableResult>('failed')), [userId]);
  const off = useCallback(() => (userId ? disable(userId) : Promise.resolve(false)), [userId]);
  return { ready: !!userId && (effective === 'unsupported' || s.cfgReady), state, busy: s.busy, error: s.error, family, enable: on, disable: off };
}

/** Where the "get live updates" card may appear, and the 14-day snooze behind its "Not now". Never visible on first paint. */
export function usePushPrompt(): WebPushApi & { show: boolean; dismiss: () => void } {
  const api = useWebPush();
  const [dismissed, setDismissed] = useState(() => isDismissed(read(K.dismissed), Date.now()));
  const [late, setLate] = useState(false);
  const eligible = api.ready && promptApplies(api.state, dismissed);
  useEffect(() => {
    if (!eligible) { setLate(false); return; }
    const id = window.setTimeout(() => setLate(true), 1200);
    return () => window.clearTimeout(id);
  }, [eligible]);
  const dismiss = useCallback(() => { write(K.dismissed, String(Date.now())); setDismissed(true); }, []);
  return { ...api, show: eligible && late, dismiss };
}
