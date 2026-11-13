import { Suspense, useState, type ReactNode } from 'react';

/**
 * Mounts a lazily loaded side window the first time `when` is true and keeps it mounted afterwards, so the window can
 * play its exit animation and reopen instantly. Nothing of the window's code is in the first-paint bundle.
 */
export function LazyHost({ when, children }: { when: boolean; children: ReactNode }) {
  const [seen, setSeen] = useState(when);
  if (when && !seen) setSeen(true);
  return seen ? <Suspense fallback={null}>{children}</Suspense> : null;
}

/** Fetch a window's code ahead of the first tap (hover, focus, touch start, or idle time after load). */
export function prefetchOnIdle(...loaders: (() => Promise<unknown>)[]) {
  const run = () => loaders.forEach((l) => { void l().catch(() => { /* offline: the tap will retry */ }); });
  const w = window as Window & { requestIdleCallback?: (cb: () => void, o?: { timeout: number }) => number };
  if (w.requestIdleCallback) w.requestIdleCallback(run, { timeout: 4000 }); else window.setTimeout(run, 2500);
}
