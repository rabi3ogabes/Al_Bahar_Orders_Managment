/**
 * Pure logic behind the side windows (no React, no `window`): the history back-stack and the URL parameters that make an
 * order or a quick view linkable. `useCloseOnBack` and `useUrlOverlay` are thin wrappers around this file, so it can be
 * unit-tested with a fake history (tests/overlay.test.mjs).
 */

/* ───────────── URL parameters ───────────── */

/** Search params that only open a side window; changing them must not scroll the page behind it to the top. */
export const OVERLAY_PARAMS = ['order', 'quick'] as const;
export type OverlayParam = (typeof OVERLAY_PARAMS)[number];

const params = (search: string) => new URLSearchParams(search.startsWith('?') ? search.slice(1) : search);
const toSearch = (p: URLSearchParams) => { const s = p.toString(); return s ? `?${s}` : ''; };

export const readParam = (search: string, key: string): string | null => {
  const v = params(search).get(key);
  return v && v.trim() ? v.trim() : null;
};
/** `?a=1` + (b, 2) = `?a=1&b=2`; replaces an existing value. */
export const withParam = (search: string, key: string, value: string): string => { const p = params(search); p.set(key, value); return toSearch(p); };
export const withoutParam = (search: string, key: string): string => { const p = params(search); p.delete(key); return toSearch(p); };
/** The search string without the params that only open a window: the scroll-to-top effect keys on this. */
export const stripOverlayParams = (search: string): string => { const p = params(search); OVERLAY_PARAMS.forEach((k) => p.delete(k)); return toSearch(p); };

/** Order ids are UUIDs and product slugs are url-safe words: anything else in `?order=` / `?quick=` is ignored. */
export const isOrderId = (v: string | null): v is string => !!v && /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(v);
export const isSlug = (v: string | null): v is string => !!v && v.length <= 190 && /^[\p{L}\p{N}][\p{L}\p{N}_-]*$/u.test(v);

/** Where the history entry that opened a URL window is remembered, so closing can step back instead of piling up entries. */
export const URL_MARK = '__ovUrl';
/** `location.state` of the router. True when this very entry was pushed by `open()` (one step back closes it); false for a deep link. */
export const openedByUs = (state: unknown, key: string): boolean => (state as Record<string, unknown> | null | undefined)?.[URL_MARK] === key;

/* ───────────── Back-button stack ───────────── */

const DEPTH = '__ovDepth';
export const depthOf = (state: unknown): number => {
  const d = (state as Record<string, unknown> | null)?.[DEPTH];
  return typeof d === 'number' && d > 0 ? d : 0;
};
export const withDepth = (state: unknown, depth: number): Record<string, unknown> => ({ ...((state as Record<string, unknown> | null) ?? {}), [DEPTH]: depth });

export interface HistoryLike { readonly state: unknown; pushState(state: unknown, unused: string): void; back(): void }
export interface Entry { readonly id: number; depth: number; pushed: boolean; closed: boolean; cancelled: boolean; readonly onClose: () => void }

/**
 * One history entry per open window. Back pops the top-most window only; closing by ✕, Esc or the scrim takes the entry
 * back out, so the next Back leaves the page as it should. The entry is pushed one tick after opening (so React
 * StrictMode's mount → unmount → mount leaves exactly one) and never while one of our own `back()` calls is still travelling.
 */
export class OverlayStack {
  private seq = 0;
  private open: Entry[] = [];
  private travelling = 0;
  private waiting: Entry[] = [];
  private readonly h: HistoryLike;
  constructor(h: HistoryLike) { this.h = h; }

  /** A window opened. Call `push(entry)` on the next tick. */
  register(onClose: () => void): Entry {
    return { id: ++this.seq, depth: 0, pushed: false, closed: false, cancelled: false, onClose };
  }

  push(e: Entry): void {
    if (e.cancelled || e.pushed) return;
    if (this.travelling > 0) { this.waiting.push(e); return; }
    e.depth = depthOf(this.h.state) + 1;
    this.h.pushState(withDepth(this.h.state, e.depth), '');
    e.pushed = true;
    this.open.push(e);
  }

  /** The window was closed by the page itself (✕, Esc, scrim, route change, a state change): take our entry back out. */
  release(e: Entry): void {
    e.cancelled = true;
    this.waiting = this.waiting.filter((w) => w !== e);
    if (!e.pushed || e.closed) return;
    const top = this.open[this.open.length - 1] === e;
    this.open = this.open.filter((x) => x !== e);
    // Only step back while the entry is still ours and on top. After a router navigation (a link inside the window) the
    // current entry belongs to the destination page and must stay.
    if (top && depthOf(this.h.state) === e.depth) { this.travelling += 1; this.h.back(); }
  }

  /** Call from the window's `popstate` listener. */
  handlePop(): void {
    if (this.travelling > 0) {
      this.travelling -= 1;
      if (this.travelling === 0) { const q = this.waiting; this.waiting = []; q.forEach((e) => this.push(e)); }
      return;
    }
    const now = depthOf(this.h.state);
    // Back (or a longer jump) landed below some windows: close them, top first, one `onClose` each.
    while (this.open.length && this.open[this.open.length - 1].depth > now) {
      const e = this.open.pop()!;
      e.closed = true;
      e.onClose();
    }
  }

  get size(): number { return this.open.length; }
}
