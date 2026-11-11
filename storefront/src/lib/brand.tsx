import { createContext, useContext, useEffect, useMemo, useState, type ReactNode } from 'react';
import { get } from './api';

export interface BrandTheme {
  key: string; kind: 'base' | 'event' | 'custom'; name: string; version: string;
  colors: { primary: string; primary_soft: string; accent: string; background: string; surface: string; ink: string };
  images: { hero: string | null; banner: string | null; logo: string | null; splash: string | null };
  banner: { title: string | null; subtitle: string | null };
}
const C = createContext<BrandTheme | null>(null);
export const useBrand = () => useContext(C);

const hex = /^#[0-9a-f]{6}$/i;

/** Turns the admin-chosen look into CSS variables for the light theme (dark mode keeps its own palette). */
function css(t: BrandTheme): string {
  const c = t.colors;
  if (![c.primary, c.primary_soft, c.accent, c.background, c.surface, c.ink].every((v) => hex.test(v ?? ''))) return '';
  return `:root:not([data-theme='dark']){--primary:${c.primary};--primary-soft:${c.primary_soft};--brass:${c.accent};--brass-deep:${c.primary};--bg:${c.background};--paper:${c.surface};--ink:${c.ink};--rose:${c.primary_soft}}`;
}

/** Loads the current look (store colours, artwork, seasonal banner) from the dashboard and keeps it fresh. */
export function BrandProvider({ children }: { children: ReactNode }) {
  const [theme, setTheme] = useState<BrandTheme | null>(null);
  useEffect(() => {
    let alive = true;
    const load = async () => { try { const r = await get<{ data: BrandTheme }>('/theme'); if (alive) setTheme(r.data); } catch { /* keep the built-in look */ } };
    load();
    const id = window.setInterval(load, 10 * 60_000);
    const vis = () => document.visibilityState === 'visible' && load();
    document.addEventListener('visibilitychange', vis);
    return () => { alive = false; window.clearInterval(id); document.removeEventListener('visibilitychange', vis); };
  }, []);
  useEffect(() => {
    let el = document.getElementById('brand-theme') as HTMLStyleElement | null;
    if (!el) { el = document.createElement('style'); el.id = 'brand-theme'; document.head.appendChild(el); }
    el.textContent = theme ? css(theme) : '';
    if (theme && hex.test(theme.colors.primary)) document.querySelector('meta[name="theme-color"]')?.setAttribute('content', theme.colors.primary);
  }, [theme]);
  const value = useMemo(() => theme, [theme]);
  return <C.Provider value={value}>{children}</C.Provider>;
}
