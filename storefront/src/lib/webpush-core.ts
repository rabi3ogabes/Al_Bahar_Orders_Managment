/**
 * Web push: the decisions, with no React, no DOM and no imports, so they run unchanged under `node --test`
 * (tests/webpush-core.test.mjs) and inside the app (lib/webpush.tsx does the browser work).
 */

export type PushState = 'unsupported' | 'ios_needs_install' | 'disabled_by_admin' | 'default' | 'granted' | 'denied' | 'error';
export type BrowserPermission = 'default' | 'granted' | 'denied';
export type Support = 'ok' | 'unsupported' | 'ios_needs_install';

/** Public Firebase web config the dashboard hands out (`GET /api/v1/push-config`). */
export interface WebFirebaseConfig { apiKey: string; authDomain: string | null; projectId: string; messagingSenderId: string; appId: string; vapidKey: string }
export interface PushConfig { enabled: boolean; web: WebFirebaseConfig | null }

const str = (v: unknown): string => (typeof v === 'string' ? v.trim() : '');

/** Accepts the raw response (`{ data: {...} }`) or its inner object. Anything incomplete counts as "off": the store never half-starts. */
export function parseConfig(raw: unknown): PushConfig {
  const root = (raw && typeof raw === 'object' ? raw : {}) as Record<string, unknown>;
  const d = (root.data && typeof root.data === 'object' ? root.data : root) as Record<string, unknown>;
  const w = (d.web && typeof d.web === 'object' ? d.web : null) as Record<string, unknown> | null;
  const web = w && str(w.apiKey) && str(w.projectId) && str(w.messagingSenderId) && str(w.appId) && str(w.vapidKey)
    ? { apiKey: str(w.apiKey), authDomain: str(w.authDomain) || null, projectId: str(w.projectId), messagingSenderId: str(w.messagingSenderId), appId: str(w.appId), vapidKey: str(w.vapidKey) }
    : null;
  return { enabled: d.enabled === true && web !== null, web };
}

/* ───────────── What this browser can do ───────────── */

export interface PushEnv { secure: boolean; serviceWorker: boolean; pushManager: boolean; notification: boolean; ua: string; platform: string; touchPoints: number; standalone: boolean }

export const isIos = (e: Pick<PushEnv, 'ua' | 'platform' | 'touchPoints'>): boolean =>
  /iPad|iPhone|iPod/.test(e.ua) || (e.platform === 'MacIntel' && e.touchPoints > 1); // iPadOS reports itself as a Mac

/** [major, minor] from the user agent, or null (iPadOS in "desktop" mode hides it). */
export function iosVersion(ua: string): [number, number] | null {
  const m = /OS (\d+)[_.](\d+)/.exec(ua);
  return m ? [Number(m[1]), Number(m[2])] : null;
}

/**
 * iPhone and iPad (Safari 16.4+) only allow web push for a site that was added to the Home Screen; in a normal tab the
 * Push API does not even exist, so the iOS check comes before the feature check.
 */
export function detectSupport(e: PushEnv): Support {
  if (!e.secure) return 'unsupported';
  if (isIos(e)) {
    const v = iosVersion(e.ua);
    if (v && (v[0] < 16 || (v[0] === 16 && v[1] < 4))) return 'unsupported';
    if (!e.standalone) return 'ios_needs_install';
  }
  return e.serviceWorker && e.pushManager && e.notification ? 'ok' : 'unsupported';
}

export interface StateInput { support: Support; config: PushConfig | null; permission: BrowserPermission; optedIn: boolean; error: boolean }

/**
 * Order matters: a browser that cannot do push is "unsupported" whatever the admin chose; an admin who switched push off beats
 * the install hint (never ask people to install an app for a feature that is off); a blocked browser beats everything below it.
 * `granted` means: the browser allows it AND this customer turned it on for this device.
 */
export function resolveState(i: StateInput): PushState {
  if (i.support === 'unsupported') return 'unsupported';
  if (!i.config || !i.config.enabled || !i.config.web) return 'disabled_by_admin';
  if (i.support === 'ios_needs_install') return 'ios_needs_install';
  if (i.permission === 'denied') return 'denied';
  if (i.error) return 'error';
  return i.permission === 'granted' && i.optedIn ? 'granted' : 'default';
}

/* ───────────── "Notifications are blocked": which steps to show ───────────── */

export type HelpFamily = 'chrome' | 'safari' | 'firefox' | 'ios';
export const HELP_FAMILIES: readonly HelpFamily[] = ['chrome', 'safari', 'firefox', 'ios'];

/** Chrome, Edge, Samsung Internet, Opera and Android Chrome share one set of steps. */
export function helpFamily(e: Pick<PushEnv, 'ua' | 'platform' | 'touchPoints'>): HelpFamily {
  if (isIos(e)) return 'ios';
  if (/Firefox\//.test(e.ua)) return 'firefox';
  if (/Chrome\/|Edg\/|OPR\/|SamsungBrowser\//.test(e.ua)) return 'chrome';
  if (/Safari\//.test(e.ua)) return 'safari';
  return 'chrome';
}
/** The visitor's own browser first, the others after it. */
export const helpOrder = (own: HelpFamily): HelpFamily[] => [own, ...HELP_FAMILIES.filter((f) => f !== own)];

/* ───────────── This device's registration (kept in localStorage) ───────────── */

export interface StoredToken { token: string; userId: string; at: number }
/** The server keeps a device's last-seen time; refreshing a registration weekly keeps a long-lived install from looking dead. */
export const REREGISTER_AFTER_MS = 7 * 24 * 3600 * 1000;

export function parseStoredToken(raw: string | null): StoredToken | null {
  if (!raw) return null;
  try {
    const v = JSON.parse(raw) as Partial<StoredToken>;
    return typeof v.token === 'string' && v.token && typeof v.userId === 'string' && v.userId ? { token: v.token, userId: v.userId, at: typeof v.at === 'number' ? v.at : 0 } : null;
  } catch { return null; }
}

/** POST the device only when something changed: new token, a different customer, or a week since the last time. */
export const needsRegistration = (stored: StoredToken | null, token: string, userId: string, now: number): boolean =>
  !stored || stored.token !== token || stored.userId !== userId || now - stored.at >= REREGISTER_AFTER_MS;

/** Customers who turned push on for this device. Permission is per browser, the choice is per account. */
export function parseUsers(raw: string | null): string[] {
  try { const v: unknown = raw ? JSON.parse(raw) : []; return Array.isArray(v) ? v.filter((x): x is string => typeof x === 'string' && x !== '').slice(0, 20) : []; } catch { return []; }
}
export const addUser = (users: string[], id: string): string[] => (users.includes(id) ? users : [...users, id].slice(-20));
export const removeUser = (users: string[], id: string): string[] => users.filter((u) => u !== id);

/* ───────────── The soft prompt ───────────── */

export const PROMPT_SNOOZE_DAYS = 14;
export function isDismissed(raw: string | null, now: number, days = PROMPT_SNOOZE_DAYS): boolean {
  const at = Number(raw);
  return Number.isFinite(at) && at > 0 && now - at < days * 86_400_000;
}

/** Offer push after an order only when the customer can act on it right now (turn it on, or learn how to install the app). */
export const promptApplies = (state: PushState, dismissed: boolean): boolean => !dismissed && (state === 'default' || state === 'ios_needs_install');

/* ───────────── Incoming messages ───────────── */

export interface PushMessage { title: string; body: string; orderId: string | null; kind: string | null }

/** FCM `onMessage` payload: our data keys (title, body, order_id, kind) win over the optional `notification` block. */
export function normalizeMessage(raw: unknown): PushMessage | null {
  const p = (raw && typeof raw === 'object' ? raw : {}) as { notification?: Record<string, unknown>; data?: Record<string, unknown> };
  const d = p.data ?? {};
  const n = p.notification ?? {};
  const title = str(d.title) || str(n.title);
  const body = str(d.body) || str(n.body);
  if (!title && !body) return null;
  return { title, body, orderId: str(d.order_id) || null, kind: str(d.kind) || null };
}

/**
 * Two channels (live socket, push) often announce the same change within a second, in either order. `claim` says whether to show
 * it: signals from the same source always show (two real changes in a row are two toasts), a different source inside the window is
 * a duplicate of what was just shown.
 */
export function createDedupe(windowMs = 3000) {
  const seen = new Map<string, { at: number; source: string }>();
  return {
    claim(source: string, key: string, now: number = Date.now()): boolean {
      for (const [k, v] of seen) if (now - v.at >= windowMs) seen.delete(k);
      const prev = seen.get(key);
      if (prev && prev.source !== source) return false;
      seen.set(key, { at: now, source });
      return true;
    },
  };
}
/** One toast per order change. The socket event carries no sequence number, so the order id inside a 3 s window is the key. */
export const orderToasts = createDedupe(3000);
