// The page background (built-in Qatar artwork, colour, own picture): the tolerant reading of the theme's `backdrop` block.
// Kept free of React and the DOM so it is unit tested (tests/backdrop.test.mjs). Contract: docs/api/PAGE_BACKDROP.md.
//
// Tolerance rule: a payload without the block, or with a value outside the allowed set, means "feature off" and the page
// looks exactly as it did before. Two things are not errors: a number outside 0.02..0.50 is pulled into range, and a missing or
// unknown `scene` is the Doha skyline (a client that does not know a newer artwork still draws something sensible).

export type BackdropStyle = 'skyline' | 'image' | 'none';
export type BackdropFit = 'bottom' | 'cover';
/** The built-in artworks (`style = skyline` means "built-in artwork", `scene` says which one). */
export type BackdropScene = 'doha' | 'dhow' | 'souq' | 'dunes' | 'museum' | 'arabesque';
/** The block as the server sends it (`GET /theme`). Every key may be missing on an older server. */
export interface Backdrop { style: BackdropStyle; scene: BackdropScene; image_url: string | null; fit: BackdropFit; opacity: number; color: string | null; tint: string | null }

/**
 * The built-in artworks. `tile` is true for a pattern cell that repeats over the whole page (arabesque); false for a scene that
 * stands on the bottom edge. `file` is the SVG in src/assets (the doha skyline keeps its first name, qatar-skyline.svg).
 */
export const SCENES: Readonly<Record<BackdropScene, { readonly tile: boolean; readonly file: string }>> = Object.freeze({
  doha: Object.freeze({ tile: false, file: 'qatar-skyline.svg' }),
  dhow: Object.freeze({ tile: false, file: 'qatar-dhow.svg' }),
  souq: Object.freeze({ tile: false, file: 'qatar-souq.svg' }),
  dunes: Object.freeze({ tile: false, file: 'qatar-dunes.svg' }),
  museum: Object.freeze({ tile: false, file: 'qatar-museum.svg' }),
  arabesque: Object.freeze({ tile: true, file: 'qatar-arabesque.svg' }),
});
export const DEFAULT_SCENE: BackdropScene = 'doha';

/** What the page paints. `active` is false when nothing may change anywhere. */
export interface ResolvedBackdrop {
  active: boolean
  /** The picture behind the content: the built-in artwork, the admin's own image, or nothing. */
  picture: BackdropStyle
  /** Which built-in artwork (only used when `picture` is 'skyline'). A missing or unknown value is the Doha skyline. */
  scene: BackdropScene
  imageUrl: string | null
  fit: BackdropFit
  opacity: number
  /** Page colour for LIGHT mode, already checked against the text colour. Null keeps the theme's own. */
  color: string | null
  /** Colour of the built-in artwork. Null means the theme's primary colour. */
  tint: string | null
}

export const MIN_OPACITY = 0.02;
export const MAX_OPACITY = 0.5;
export const DEFAULT_OPACITY = 0.08;
/** Below this contrast against the theme's text colour a page colour is ignored (the dashboard itself asks for 4.5:1). */
export const MIN_PAGE_CONTRAST = 3;

export const BACKDROP_OFF: ResolvedBackdrop = Object.freeze({ active: false, picture: 'none', scene: DEFAULT_SCENE, imageUrl: null, fit: 'bottom', opacity: DEFAULT_OPACITY, color: null, tint: null });

/* ───────────── colour maths ───────────── */
const HEX = /^#[0-9a-f]{6}$/i;
export const isHex = (v: unknown): v is string => typeof v === 'string' && HEX.test(v);
const channels = (h: string): number[] => [1, 3, 5].map((i) => parseInt(h.slice(i, i + 2), 16));
const luminance = (h: string): number => {
  const [r, g, b] = channels(h).map((v) => { const s = v / 255; return s <= 0.03928 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4; });
  return 0.2126 * r + 0.7152 * g + 0.0722 * b;
};
/** WCAG contrast ratio (1 to 21) between two #RRGGBB colours. */
export function contrastRatio(a: string, b: string): number {
  const x = luminance(a), y = luminance(b);
  return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05);
}

/* ───────────── reading the block ───────────── */
const present = (v: unknown) => v !== undefined && v !== null;
const STYLES: readonly string[] = ['skyline', 'image', 'none'];
const FITS: readonly string[] = ['bottom', 'cover'];

/** True for a known scene key. Own-property check, so keys such as `toString` or `__proto__` are not scenes. */
export const isScene = (v: unknown): v is BackdropScene => typeof v === 'string' && Object.prototype.hasOwnProperty.call(SCENES, v);

/** The scene to draw: a missing, unknown or wrongly typed value is the Doha skyline (the contract's rule for an older client). */
export const readScene = (v: unknown): BackdropScene => (isScene(v) ? v : DEFAULT_SCENE);

/** An absolute https address only (the picture is an <img>, never a script or a data URL). Returns it cleaned, or null. */
export function safeImageUrl(v: unknown): string | null {
  if (typeof v !== 'string' || v.length > 2048) return null;
  try {
    const u = new URL(v.trim());
    return u.protocol === 'https:' && !u.username && !u.password ? u.href : null;
  } catch { return null; }
}

/** `url("…")` for a CSS custom property; quotes, backslashes and line breaks in the address cannot end the string early. */
export const cssUrl = (u: string): string => `url("${u.replace(/[\\"\n\r]/g, (c) => encodeURIComponent(c))}")`;

/**
 * The theme payload to what the page paints. Anything unexpected is "feature off" (see the top of the file); the page colour
 * is dropped when text on it would be hard to read (needs the theme's own text colour, `colors.ink`, to judge).
 */
export function resolveBackdrop(theme: { backdrop?: unknown; colors?: { ink?: unknown } | null } | null | undefined): ResolvedBackdrop {
  const b = theme?.backdrop;
  if (!b || typeof b !== 'object' || Array.isArray(b)) return BACKDROP_OFF;
  const r = b as Record<string, unknown>;

  if (typeof r.style !== 'string' || !STYLES.includes(r.style)) return BACKDROP_OFF;
  if (present(r.fit) && (typeof r.fit !== 'string' || !FITS.includes(r.fit))) return BACKDROP_OFF;
  if (present(r.color) && !isHex(r.color)) return BACKDROP_OFF;
  if (present(r.tint) && !isHex(r.tint)) return BACKDROP_OFF;

  let opacity = DEFAULT_OPACITY;
  if (present(r.opacity)) {
    const n = typeof r.opacity === 'number' ? r.opacity : typeof r.opacity === 'string' && r.opacity.trim() !== '' ? Number(r.opacity) : NaN;
    if (!Number.isFinite(n)) return BACKDROP_OFF;
    opacity = Math.min(MAX_OPACITY, Math.max(MIN_OPACITY, n));
  }

  const ink = theme?.colors?.ink;
  const color = isHex(r.color) && isHex(ink) && contrastRatio(r.color, ink) >= MIN_PAGE_CONTRAST ? r.color.toLowerCase() : null;
  const imageUrl = r.style === 'image' ? safeImageUrl(r.image_url) : null;
  // An own picture without a usable address cannot be drawn: the picture is dropped, the page colour (if any) stays.
  const picture: BackdropStyle = r.style === 'image' && !imageUrl ? 'none' : (r.style as BackdropStyle);
  return {
    active: picture !== 'none' || color !== null,
    picture,
    scene: readScene(r.scene),
    imageUrl,
    fit: r.fit === 'cover' ? 'cover' : 'bottom',
    opacity,
    color,
    tint: isHex(r.tint) ? r.tint.toLowerCase() : null,
  };
}
