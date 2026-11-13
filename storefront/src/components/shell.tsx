import { lazy, type ReactNode } from 'react';
import { Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { Sparkles } from 'lucide-react';
import { get } from '../lib/api';
import { useCart } from '../lib/cart';
import { cx } from '../lib/format';
import type { Category } from '../lib/types';
import { useBrand } from '../lib/brand';
import { LazyHost, prefetchOnIdle } from './Lazy';

export function LogoMark({ className = 'h-9 w-9 sm:h-10 sm:w-10' }: { className?: string }) {
  return (
    <svg viewBox="0 0 64 64" aria-hidden="true" className={cx('shrink-0', className)}>
      <circle cx="32" cy="32" r="32" style={{ fill: 'var(--mark-bg, #8A1538)' }} />
      <circle cx="32" cy="22" r="7" style={{ fill: 'var(--mark-fg, #F6D3DC)' }} />
      <path d="M10 37c4.5-5 9-5 13.5 0s9 5 13.5 0 9-5 13.5 0 2.5 2.5 3.5 3.5" fill="none" style={{ stroke: 'var(--mark-fg, #F6D3DC)' }} strokeWidth="3" strokeLinecap="round" />
      <path d="M10 47c4.5-5 9-5 13.5 0s9 5 13.5 0 9-5 13.5 0 2.5 2.5 3.5 3.5" fill="none" style={{ stroke: 'var(--mark-fg2, #FCF9FA)' }} strokeWidth="3" strokeLinecap="round" opacity=".9" />
    </svg>
  );
}

/** Bilingual lockup: wave mark, Arabic name over the Latin name. */
export function Logo({ light, band }: { light?: boolean; band?: boolean }) {
  const brand = useBrand();
  return (
    <Link to="/" className={cx('flex items-center gap-2.5 leading-none', band ? 'text-on-band' : light ? 'text-bg' : 'text-ink')} aria-label="Al Bahar البحار">
      {brand?.images.logo ? <img src={brand.images.logo} alt="" className="h-9 w-9 shrink-0 rounded-full object-cover sm:h-10 sm:w-10" /> : <LogoMark />}
      <span className="flex flex-col items-start">
        <span lang="ar" dir="rtl" className="font-arabic text-[24px] font-semibold leading-[1.05] sm:text-[28px]">البحار</span>
        <span className={cx('mt-0.5 whitespace-nowrap text-[10px] font-semibold uppercase tracking-[0.3em] sm:text-[11px]', light ? 'text-on-primary/80' : band ? 'text-accent' : 'text-brass')}>Al Bahar</span>
      </span>
    </Link>
  );
}

/** Seasonal strip from the dashboard (Qatar events). Hidden on the normal look. */
export function SeasonBanner() {
  const brand = useBrand();
  if (!brand || brand.kind === 'base' || !brand.banner.title) return null;
  return (
    <div className="relative overflow-hidden bg-brass text-on-brass" role="note" aria-label={brand.name}>
      {brand.images.banner && <img src={brand.images.banner} alt="" className="absolute inset-0 h-full w-full object-cover opacity-40" />}
      <div className="wrap relative flex flex-wrap items-center justify-center gap-x-3 gap-y-0.5 py-2 text-center text-[13px]">
        <Sparkles size={14} className="shrink-0" /><strong className="font-semibold">{brand.banner.title}</strong>{brand.banner.subtitle && <span className="opacity-90">{brand.banner.subtitle}</span>}
      </div>
    </div>
  );
}

export function useCategories() {
  return useQuery({ queryKey: ['categories'], queryFn: async () => (await get<{ data: Category[] }>('/categories')).data, staleTime: 10 * 60_000 });
}

/* ───────────── Side windows of the header ─────────────
 * Each one is its own chunk, fetched on first use (or idle time / hover), so none of it is in the first-paint bundle. */
const loadCart = () => import('./CartDrawer');
const loadSearch = () => import('./SearchOverlay');
const loadBranch = () => import('./BranchDrawer');
const loadMenu = () => import('./MenuDrawer');
const CartImpl = lazy(loadCart);
const SearchImpl = lazy(loadSearch);
const BranchImpl = lazy(loadBranch);
const MenuImpl = lazy(loadMenu);
/** Warm a window's code on hover / focus / touch, before the tap lands. */
export const preload = { cart: () => { void loadCart(); }, search: () => { void loadSearch(); }, menu: () => { void loadMenu(); }, branch: () => { void loadBranch(); } };
/** Idle time after the page is up: fetch the window most people open first. */
export const prefetchHeaderChunks = () => prefetchOnIdle(loadCart);

export function CartDrawer() {
  const cart = useCart();
  return <LazyHost when={cart.drawer}><CartImpl /></LazyHost>;
}
export function SearchOverlay({ open, onClose }: { open: boolean; onClose: () => void }) {
  return <LazyHost when={open}><SearchImpl open={open} onClose={onClose} /></LazyHost>;
}
export function BranchPicker({ open, onClose }: { open: boolean; onClose: () => void }) {
  return <LazyHost when={open}><BranchImpl open={open} onClose={onClose} /></LazyHost>;
}
export function MenuDrawer({ open, onClose, children }: { open: boolean; onClose: () => void; children: ReactNode }) {
  return <LazyHost when={open}><MenuImpl open={open} onClose={onClose}>{children}</MenuImpl></LazyHost>;
}
