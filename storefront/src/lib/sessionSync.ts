import type { Tokens } from './types';

/**
 * Keeping the sign-in alive across tabs. The server rotates the refresh token on every use and treats an old one coming back as theft
 * (it signs out every device of that login), so two tabs must never refresh with the same token. This file is the rule, with every
 * outside thing (storage, the lock, the network, the clock) handed in, so the tests can play two tabs against each other.
 *
 * - One refresh at a time across all tabs (a lock), and inside one tab (one shared promise).
 * - After taking the lock the stored tokens are read again: if another tab already refreshed (the access token is not the one that
 *   was refused any more) nothing is sent; this tab simply takes the newer tokens.
 * - Only a clear "no" from the server (401 or 403 on the refresh call) ends the session. A dropped connection, a 429 or a 5xx
 *   (a deploy in progress) keeps the tokens and tries again later, waiting a little longer after each failure.
 */

export type RefreshOutcome = 'ok' | 'invalid' | 'transient';
export type RefreshAttempt = { kind: 'ok'; tokens: Tokens } | { kind: 'invalid' } | { kind: 'transient' };

export interface RefresherDeps {
  /** What is saved right now (other tabs may have changed it); null when signed out. */
  stored: () => Tokens | null;
  /** Take these tokens (null signs out): memory, storage and whoever listens. */
  apply: (tokens: Tokens | null) => void;
  /** One call to the refresh endpoint with this refresh token. Never throws. */
  attempt: (refreshToken: string) => Promise<RefreshAttempt>;
  /** Runs the work while no other tab does the same work. */
  withLock: <T>(run: () => Promise<T>) => Promise<T>;
  now: () => number;
}

/** How long to leave the server alone after a failed refresh: 2 s, 4 s, 8 s ... never more than 30 s. */
export const backoffMs = (failures: number) => Math.min(30_000, 1_000 * 2 ** Math.max(1, failures));

export function createRefresher(deps: RefresherDeps) {
  let flight: Promise<RefreshOutcome> | null = null;
  let failures = 0;
  let retryAt = 0;

  const run = async (refused: string | null): Promise<RefreshOutcome> => {
    const current = deps.stored();
    if (!current?.refresh_token) { // another tab signed out while this one waited
      deps.apply(null);
      return 'invalid';
    }
    // Another tab refreshed first: its tokens are newer than the one this request used. Use them, send nothing.
    if (refused && current.access_token !== refused) {
      deps.apply(current);
      failures = 0;
      return 'ok';
    }
    const r = await deps.attempt(current.refresh_token);
    if (r.kind === 'ok') { deps.apply(r.tokens); failures = 0; retryAt = 0; return 'ok'; }
    if (r.kind === 'invalid') { deps.apply(null); failures = 0; retryAt = 0; return 'invalid'; }
    failures += 1;
    retryAt = deps.now() + backoffMs(failures);
    return 'transient';
  };

  return {
    /** `refused` is the access token the server just answered 401 to (null when unknown). */
    refresh(refused: string | null): Promise<RefreshOutcome> {
      if (flight) return flight;
      if (deps.now() < retryAt) return Promise.resolve('transient');
      flight = deps.withLock(() => run(refused)).finally(() => { flight = null; });
      return flight;
    },
  };
}

// ── The cross-tab lock ──────────────────────────────────────────────────────────────────────────────────────────────

export interface LockManagerLike { request: <T>(name: string, callback: () => Promise<T>) => Promise<T> }
export interface LeaseStorage { get: (key: string) => string | null; set: (key: string, value: string) => void; remove: (key: string) => void }

export interface LockDeps {
  locks?: LockManagerLike | null;
  lease?: LeaseStorage | null;
  now: () => number;
  sleep: (ms: number) => Promise<void>;
  /** A random 0..1 number (spreads out tabs that wake at the same moment). */
  random: () => number;
  id: string;
}

const LOCK_NAME = 'albahar.session.refresh';
const LEASE_KEY = 'albahar.session.refresh.lease';
const LEASE_MS = 20_000;
const LEASE_WAIT_MS = 20_000;

/**
 * The best lock the browser offers. The Web Locks API where it exists (the lock is let go by itself if the tab closes). Without it, a lease in
 * localStorage: write "mine, until T", wait a moment, and if that is still what is written the lock is mine; a tab that dies leaves a lease that
 * runs out. Without storage too, the work simply runs (one tab can only race itself, and the single shared promise covers that).
 * Whatever lock is used, the caller reads the tokens again afterwards, so a lock that was lost to a slow tab costs nothing worse than one extra look.
 */
export function createLock(deps: LockDeps): <T>(run: () => Promise<T>) => Promise<T> {
  const { locks, lease } = deps;
  if (locks) return (run) => locks.request(LOCK_NAME, run);
  if (!lease) return (run) => run();

  const holder = (): { owner: string; until: number } | null => {
    try { const v = JSON.parse(lease.get(LEASE_KEY) ?? 'null'); return v && typeof v.owner === 'string' && typeof v.until === 'number' ? v : null; } catch { return null; }
  };
  return async (run) => {
    const giveUpAt = deps.now() + LEASE_WAIT_MS;
    for (;;) {
      const h = holder();
      if (!h || h.until <= deps.now()) {
        lease.set(LEASE_KEY, JSON.stringify({ owner: deps.id, until: deps.now() + LEASE_MS }));
        await deps.sleep(40); // a tab that wrote at the same moment overwrites us: the last writer wins, the others see it
        if (holder()?.owner === deps.id) break;
      }
      if (deps.now() >= giveUpAt) break; // the holder is slow or gone: go on, the tokens are read again after this anyway
      await deps.sleep(100 + Math.floor(deps.random() * 100));
    }
    try { return await run(); } finally { if (holder()?.owner === deps.id) lease.remove(LEASE_KEY); }
  };
}
