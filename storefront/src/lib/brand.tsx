import { createContext, useCallback, useContext, useEffect, useLayoutEffect, useMemo, useState, type ReactNode } from 'react';
import { get } from './api';

export type Layout = 'classic' | 'fresh';
export interface DesignTokens {
  radius: 'md' | 'lg' | 'xl';
  card: 'flat' | 'soft' | 'outlined';
  header: 'standard' | 'brand';
  nav: 'classic' | 'center_action';
  background: 'plain' | 'gradient';
  density: 'comfortable' | 'compact';
}
export interface BrandTheme {
  key: string; kind: 'base' | 'event' | 'custom'; name: string; version: string;
  layout?: Layout;
  tokens?: Partial<DesignTokens>;
  design?: { key: string; name_en?: string; name_ar?: string; name?: string } | null;
  colors: { primary: string; primary_soft: string; accent: string; background: string; surface: string; ink: string };
  images: { hero: string | null; banner: string | null; logo: string | null; splash: string | null };
  banner: { title: string | null; subtitle: string | null };
}

const CLASSIC_TOKENS: DesignTokens = { radius: 'lg', card: 'soft', header: 'standard', nav: 'classic', background: 'plain', density: 'comfortable' };
const FRESH_TOKENS: DesignTokens = { radius: 'xl', card: 'soft', header: 'brand', nav: 'center_action', background: 'plain', density: 'comfortable' };

function pick<T extends string>(v: unknown, allowed: readonly T[], fallback: T): T {
  return typeof v === 'string' && (allowed as readonly string[]).includes(v) ? (v as T) : fallback;
}
/** Tolerant: a payload without layout or tokens (older backend) is the classic look. */
export function resolveDesign(t: BrandTheme | null): { layout: Layout; tokens: DesignTokens } {
  const layout: Layout = t?.layout === 'fresh' ? 'fresh' : 'classic';
  const base = layout === 'fresh' ? FRESH_TOKENS : CLASSIC_TOKENS;
  const k = t?.tokens ?? {};
  return {
    layout,
    tokens: {
      radius: pick(k.radius, ['md', 'lg', 'xl'], base.radius),
      card: pick(k.card, ['flat', 'soft', 'outlined'], base.card),
      header: pick(k.header, ['standard', 'brand'], base.header),
      nav: pick(k.nav, ['classic', 'center_action'], base.nav),
      background: pick(k.background, ['plain', 'gradient'], base.background),
      density: pick(k.density, ['comfortable', 'compact'], base.density),
    },
  };
}

interface Ctx { theme: BrandTheme | null; layout: Layout; tokens: DesignTokens; ready: boolean; preview: { key: string; name: string } | null; exitPreview: () => void }
const C = createContext<Ctx>({ theme: null, layout: 'classic', tokens: CLASSIC_TOKENS, ready: true, preview: null, exitPreview: () => {} });
/** The brand payload (colours, artwork, seasonal banner). Null until the first load. */
export const useBrand = () => useContext(C).theme;
/** Which web design is active and its tokens, plus the admin-preview state. */
export const useDesign = () => useContext(C);

/* ───────────── colour maths (keeps text readable on any admin-chosen palette) ───────────── */
const hex = /^#[0-9a-f]{6}$/i;
const rgb = (h: string): [number, number, number] => [parseInt(h.slice(1, 3), 16), parseInt(h.slice(3, 5), 16), parseInt(h.slice(5, 7), 16)];
const toHex = (c: number[]) => `#${c.map((v) => Math.round(Math.max(0, Math.min(255, v))).toString(16).padStart(2, '0')).join('')}`;
const mix = (a: string, b: string, w: number) => { const x = rgb(a), y = rgb(b); return toHex(x.map((v, i) => v * (1 - w) + y[i] * w)); };
const lum = (h: string) => { const [r, g, b] = rgb(h).map((v) => { const s = v / 255; return s <= 0.03928 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4; }); return 0.2126 * r + 0.7152 * g + 0.0722 * b; };
const contrast = (a: string, b: string) => { const x = lum(a), y = lum(b); return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05); };
/** Best of white or near-black for text on `bg`. */
const readable = (bg: string) => (contrast(bg, '#ffffff') >= contrast(bg, '#111111') ? '#ffffff' : '#111111');
/** Darkens (or lightens, for dark pages) `c` until it reaches `min` contrast against `bg`. */
function ensure(c: string, bg: string, min: number, toward: '#000000' | '#ffffff' = '#000000'): string {
  let out = c;
  for (let i = 0; i < 12 && contrast(out, bg) < min; i++) out = mix(out, toward, 0.12);
  return out;
}

function css(t: BrandTheme, layout: Layout): string {
  const c = t.colors;
  if (!c || ![c.primary, c.primary_soft, c.accent, c.background, c.surface, c.ink].every((v) => hex.test(v ?? ''))) return '';
  const onPrimary = readable(c.primary);
  const onAccent = readable(c.accent);
  if (layout === 'classic') {
    return `:root:not([data-theme='dark']){--primary:${c.primary};--primary-soft:${c.primary_soft};--brass:${c.accent};--brass-deep:${c.primary};--bg:${c.background};--paper:${c.surface};--ink:${c.ink};--rose:${c.primary_soft};--accent:${c.accent};--on-accent:${onAccent};--on-brass:${onAccent};--on-primary:${onPrimary};--band:${c.primary};--on-band:${onPrimary}}`;
  }
  // Fresh: the accent (often a light yellow) is only used as a fill with its own readable text colour.
  // --brass (used as text and focus ring on light pages) stays a dark, AA-safe shade of the primary.
  const brass = ensure(c.primary_soft, c.background, 4.5);
  const brassTint = mix(c.background, c.primary, 0.08);
  const sand = mix(c.background, c.primary, 0.05);
  const line = mix(c.background, c.ink, 0.12);
  const muted = ensure(mix(c.ink, c.background, 0.38), c.background, 4.5);
  const inkSoft = mix(c.ink, c.background, 0.22);
  const light = `:root:not([data-theme='dark']){--primary:${c.primary};--primary-soft:${c.primary_soft};--brass:${brass};--brass-deep:${ensure(c.primary, c.background, 7)};--brass-tint:${brassTint};--bg:${c.background};--paper:${c.surface};--sand:${sand};--sand-deep:${mix(c.background, c.primary, 0.12)};--ink:${c.ink};--ink-soft:${inkSoft};--muted:${muted};--line:${line};--rose:${mix(c.background, c.primary, 0.14)};--accent:${c.accent};--on-accent:${onAccent};--on-primary:${onPrimary};--band:${c.primary};--on-band:${onPrimary}}`;
  // Dark mode: derived from the same primary so the green store never turns maroon.
  const dBg = mix('#0d1210', c.primary, 0.1), dPaper = mix('#151c18', c.primary, 0.14);
  const dBrass = ensure(mix(c.accent, '#ffffff', 0.1), dBg, 4.5, '#ffffff');
  const dPrimary = mix(c.primary, '#ffffff', 0.4);
  const dark = `:root[data-theme='dark'][data-layout='fresh']{--primary:${dPrimary};--primary-soft:${mix(c.primary, '#ffffff', 0.55)};--bg:${dBg};--paper:${dPaper};--sand:${mix(dPaper, '#ffffff', 0.05)};--sand-deep:${mix(dPaper, '#ffffff', 0.1)};--ink:#eef2ee;--ink-soft:#cdd6cf;--muted:#9fb0a4;--line:${mix(dPaper, '#ffffff', 0.12)};--brass:${dBrass};--on-brass:${readable(dBrass)};--brass-deep:${mix(c.accent, '#ffffff', 0.25)};--brass-tint:${mix(dPaper, c.primary, 0.3)};--rose:${mix(dPaper, c.primary, 0.3)};--accent:${c.accent};--on-accent:${onAccent};--on-primary:${readable(dPrimary)};--band:${mix(c.primary, '#000000', 0.35)};--on-band:#ffffff}`;
  return light + dark;
}

/* ───────────── cache + preview ───────────── */
const CACHE_KEY = 'albahar.brand.v2';
function readCache(): BrandTheme | null {
  try { const raw = localStorage.getItem(CACHE_KEY); const v = raw ? JSON.parse(raw) : null; return v && v.colors ? (v as BrandTheme) : null; } catch { return null; }
}
function writeCache(t: BrandTheme) { try { localStorage.setItem(CACHE_KEY, JSON.stringify(t)); } catch { /* private mode */ } }
/** `?preview_design=<key>` is read once at load, kept in memory only and never stored. */
const initialPreview = (() => { try { return new URLSearchParams(window.location.search).get('preview_design'); } catch { return null; } })();

function applyAttrs(layout: Layout, k: DesignTokens) {
  const d = document.documentElement.dataset;
  d.layout = layout; d.radius = k.radius; d.card = k.card; d.header = k.header; d.nav = k.nav; d.background = k.background; d.density = k.density;
}

/** Loads the current look (store colours, artwork, seasonal banner, web design) from the dashboard and keeps it fresh. */
export function BrandProvider({ children }: { children: ReactNode }) {
  const [previewKey, setPreviewKey] = useState<string | null>(initialPreview);
  const [theme, setTheme] = useState<BrandTheme | null>(() => (initialPreview ? null : readCache()));
  const { layout, tokens } = useMemo(() => resolveDesign(theme), [theme]);
  // First visit has no cached design: hold the first paint briefly so the page never jumps from one layout to the other.
  const [settled, setSettled] = useState(() => !!theme);
  useEffect(() => { const id = window.setTimeout(() => setSettled(true), 1200); return () => window.clearTimeout(id); }, []);

  useEffect(() => {
    let alive = true;
    const load = async () => {
      try {
        const r = await get<{ data: BrandTheme }>('/theme', previewKey ? { design: previewKey } : undefined);
        if (!alive) return;
        setTheme(r.data);
        if (!previewKey) writeCache(r.data);
      } catch { /* keep the cached or built-in look */ } finally { if (alive) setSettled(true); }
    };
    load();
    const id = window.setInterval(load, 10 * 60_000);
    const vis = () => document.visibilityState === 'visible' && load();
    document.addEventListener('visibilitychange', vis);
    return () => { alive = false; window.clearInterval(id); document.removeEventListener('visibilitychange', vis); };
  }, [previewKey]);

  // Layout effect: attributes and colours land before the first paint, so a cached design never flashes.
  useLayoutEffect(() => {
    applyAttrs(layout, tokens);
    let el = document.getElementById('brand-theme') as HTMLStyleElement | null;
    if (!el) { el = document.createElement('style'); el.id = 'brand-theme'; document.head.appendChild(el); }
    el.textContent = theme ? css(theme, layout) : '';
    if (theme && hex.test(theme.colors.primary) && document.documentElement.dataset.theme !== 'dark') document.querySelector('meta[name="theme-color"]')?.setAttribute('content', theme.colors.primary);
  }, [theme, layout, tokens]);

  const exitPreview = useCallback(() => {
    setPreviewKey(null);
    setTheme(readCache());
    try { const u = new URL(window.location.href); u.searchParams.delete('preview_design'); window.history.replaceState(null, '', u.toString()); } catch { /* ignore */ }
  }, []);

  const value = useMemo<Ctx>(() => ({
    theme, layout, tokens, exitPreview, ready: settled,
    preview: previewKey ? { key: previewKey, name: theme?.design?.name || theme?.name || previewKey } : null,
  }), [theme, layout, tokens, settled, previewKey, exitPreview]);
  return <C.Provider value={value}>{children}</C.Provider>;
}
