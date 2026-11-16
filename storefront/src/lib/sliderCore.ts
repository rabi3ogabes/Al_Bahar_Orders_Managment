// The arithmetic of the home slider, kept free of React and the DOM so it is unit tested (tests/slider-core.test.mjs).

export interface SliderSettings { autoplay: boolean; interval: number; aspect: string; dots: boolean; show_titles: boolean; rounded: boolean }

export const ASPECTS = ['16:9', '21:9', '3:1', '4:3', '1:1'] as const;
export const DEFAULT_SLIDER: SliderSettings = { autoplay: true, interval: 5, aspect: '16:9', dots: true, show_titles: true, rounded: true };

/** Any value the server sent (or an older server did not send at all) becomes complete, valid settings. */
export function normaliseSlider(raw?: Partial<Record<keyof SliderSettings, unknown>> | null): SliderSettings {
  const bool = (v: unknown, d: boolean) => (typeof v === 'boolean' ? v : d);
  const n = Number(raw?.interval);
  return {
    autoplay: bool(raw?.autoplay, DEFAULT_SLIDER.autoplay),
    interval: Number.isFinite(n) && n > 0 ? Math.max(3, Math.min(15, Math.round(n))) : DEFAULT_SLIDER.interval,
    aspect: typeof raw?.aspect === 'string' && (ASPECTS as readonly string[]).includes(raw.aspect) ? raw.aspect : DEFAULT_SLIDER.aspect,
    dots: bool(raw?.dots, DEFAULT_SLIDER.dots),
    show_titles: bool(raw?.show_titles, DEFAULT_SLIDER.show_titles),
    rounded: bool(raw?.rounded, DEFAULT_SLIDER.rounded),
  };
}

/** `16:9` → width and height, for the image's own `width`/`height` attributes and the CSS aspect-ratio (no layout shift while it loads). */
export function aspectSize(aspect: string): { w: number; h: number } {
  const [w, h] = aspect.split(':').map(Number);
  return w > 0 && h > 0 ? { w, h } : { w: 16, h: 9 };
}

/** The next or previous slide, going round. */
export const wrapIndex = (i: number, count: number): number => (count <= 0 ? 0 : ((i % count) + count) % count);

/** Which slide a scroll position shows. Right-to-left tracks scroll to negative offsets, so only the distance matters. */
export function indexFromScroll(scrollLeft: number, width: number, count: number): number {
  if (width <= 0 || count <= 0) return 0;
  return Math.max(0, Math.min(count - 1, Math.round(Math.abs(scrollLeft) / width)));
}

/** The scroll offset that shows slide `i` (negative in right-to-left, where the first slide is on the right). */
export function scrollLeftFor(i: number, width: number, rtl: boolean): number {
  const x = i * width;
  return rtl && x !== 0 ? -x : x;
}

/** What an arrow key does: the key points in screen directions, so in right-to-left "left" is the NEXT slide. 0 = not a slider key. */
export function stepForKey(key: string, rtl: boolean): 1 | -1 | 0 {
  if (key === 'ArrowRight') return rtl ? -1 : 1;
  if (key === 'ArrowLeft') return rtl ? 1 : -1;
  return 0;
}
