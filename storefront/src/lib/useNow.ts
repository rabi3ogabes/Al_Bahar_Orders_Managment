import { useSyncExternalStore } from 'react';

/**
 * One shared clock for every countdown on the page: a single timer ticks once a second (self-correcting, so it never drifts or skips a
 * second), and stops when nothing listens or the tab is hidden. `useNow()` gives the current time and re-renders its component on each tick.
 * (`useTick` in hooks.ts gives each component its own interval; a page of deal cards would have dozens.)
 */
let ticks = 0;
let timer: ReturnType<typeof setTimeout> | null = null;
let due = 0;
const listeners = new Set<() => void>();

const visible = () => typeof document === 'undefined' || document.visibilityState !== 'hidden';
function emit() { ticks += 1; listeners.forEach((l) => l()); }
function schedule() {
  if (timer !== null || listeners.size === 0 || !visible()) return;
  const now = Date.now();
  // Aim at the next whole step after the last one (no drift); a device that slept just waits one second from now (no replayed ticks).
  due += 1000;
  if (due <= now) due = now + 1000;
  timer = setTimeout(() => { timer = null; emit(); schedule(); }, due - now);
}
function onVisibility() {
  if (!visible()) { if (timer !== null) { clearTimeout(timer); timer = null; } return; }
  due = Date.now();
  emit();
  schedule();
}
function subscribe(l: () => void) {
  if (listeners.size === 0 && typeof document !== 'undefined') document.addEventListener('visibilitychange', onVisibility);
  listeners.add(l);
  if (listeners.size === 1) due = Date.now();
  schedule();
  return () => {
    listeners.delete(l);
    if (listeners.size === 0) { if (timer !== null) { clearTimeout(timer); timer = null; } if (typeof document !== 'undefined') document.removeEventListener('visibilitychange', onVisibility); }
  };
}

/** The current time in milliseconds, refreshed once a second while the tab is visible. */
export function useNow(): number {
  useSyncExternalStore(subscribe, () => ticks, () => 0);
  return Date.now();
}
