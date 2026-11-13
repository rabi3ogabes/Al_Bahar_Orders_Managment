import { useEffect, useRef, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { Search, ShoppingBag, X, Sparkles } from 'lucide-react';
import { get } from '../lib/api';
import { useBranch } from '../lib/branch';
import { useCart } from '../lib/cart';
import { useI18n } from '../lib/i18n';
import { useDebounce } from '../lib/hooks';
import { cx } from '../lib/format';
import type { Category, ProductCard } from '../lib/types';
import { Button, Drawer, IconButton, Modal, Pic, PriceTag, QtyStepper, Spinner, useFocusTrap } from './ui';
import { useBrand } from '../lib/brand';

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

/* ───────────── Search ───────────── */
interface Suggest { products: ProductCard[]; categories: { id: string; slug: string; name: string }[]; brands: { id: string; slug: string; name: string }[]; did_you_mean: string | null }

export function SearchOverlay({ open, onClose }: { open: boolean; onClose: () => void }) {
  const { t } = useI18n();
  const nav = useNavigate();
  const [q, setQ] = useState('');
  const dq = useDebounce(q.trim(), 250);
  const ref = useRef<HTMLInputElement>(null);
  const box = useRef<HTMLDivElement>(null);
  useFocusTrap(open, box);
  const { branchId } = useBranch();
  const res = useQuery({
    queryKey: ['suggest', dq, branchId], enabled: open && dq.length >= 2,
    queryFn: async () => (await get<{ data: Suggest }>('/search/suggest', { q: dq, branch_id: branchId })).data,
  });
  const products = res.data?.products ?? [];
  useEffect(() => { if (open) { setQ(''); setTimeout(() => ref.current?.focus(), 50); } }, [open]);
  useEffect(() => { if (!open) return; const k = (e: KeyboardEvent) => e.key === 'Escape' && onClose(); window.addEventListener('keydown', k); return () => window.removeEventListener('keydown', k); }, [open, onClose]);
  if (!open) return null;
  const go = (e?: React.FormEvent) => { e?.preventDefault(); if (q.trim()) { onClose(); nav(`/shop?q=${encodeURIComponent(q.trim())}`); } };
  return (
    <div className="fixed inset-0 z-[85]" role="dialog" aria-modal="true" aria-label={t('search.title')}>
      <div className="fade absolute inset-0 bg-ink/45 backdrop-blur-[2px]" onClick={onClose} />
      <div ref={box} className="rise relative mx-auto mt-0 max-w-3xl rounded-b-[28px] bg-bg p-5 shadow-soft sm:mt-6 sm:rounded-[28px] sm:p-7">
        <form onSubmit={go} className="flex items-center gap-3 border-b border-primary pb-3">
          <Search size={22} className="text-muted" />
          <input ref={ref} value={q} onChange={(e) => setQ(e.target.value)} placeholder={t('search.placeholder')} aria-label={t('search.title')} className="flex-1 bg-transparent font-display text-[26px] outline-none placeholder:text-muted/60" />
          <IconButton label={t('action.close')} type="button" onClick={onClose}><X size={20} /></IconButton>
        </form>
        <div className="mt-5 min-h-24">
          {res.isFetching && <div className="flex justify-center py-6"><Spinner /></div>}
          {res.data?.did_you_mean && (
            <button onClick={() => setQ(res.data!.did_you_mean!)} className="mb-3 flex items-center gap-2 rounded-full bg-brass-tint px-4 py-2 text-sm font-medium text-brass-deep hover:underline"><Sparkles size={15} />{t('search.did_you_mean', { q: res.data.did_you_mean })}</button>
          )}
          {res.data && (res.data.categories.length > 0 || res.data.brands.length > 0) && (
            <div className="mb-3 flex flex-wrap gap-2">
              {res.data.categories.map((c) => <Link key={c.id} to={`/shop?category=${c.slug}`} onClick={onClose} className="rounded-full border border-line bg-paper px-3.5 py-1.5 text-sm hover:border-primary">{c.name}</Link>)}
              {res.data.brands.map((b) => <Link key={b.id} to={`/shop?brand=${b.slug}`} onClick={onClose} className="rounded-full border border-line bg-paper px-3.5 py-1.5 text-sm text-muted hover:border-primary">{b.name}</Link>)}
            </div>
          )}
          {!res.isFetching && dq.length >= 2 && res.data && products.length === 0 && !res.data.did_you_mean && <p className="py-6 text-center text-muted">{t('search.none', { q: dq })}</p>}
          {!res.isFetching && products.length > 0 && (
            <ul className="divide-y divide-line">
              {products.map((p) => (
                <li key={p.id}>
                  <Link to={`/products/${p.slug}`} onClick={onClose} className="flex items-center gap-4 py-3 hover:opacity-80">
                    <Pic src={p.image?.thumb_url} alt={p.name} ratio="1/1" className="h-16 w-16 shrink-0 rounded-xl" />
                    <div className="min-w-0 flex-1"><p className="truncate font-display text-xl">{p.name}</p><p className="text-xs text-muted">{p.brand?.name}</p></div>
                    <PriceTag price={p.price} size="sm" />
                  </Link>
                </li>
              ))}
              <li className="pt-3"><button onClick={() => go()} className="text-sm font-medium text-brass-deep hover:underline">{t('search.all', { q: dq })}</button></li>
            </ul>
          )}
        </div>
      </div>
    </div>
  );
}

/* ───────────── Showroom picker ───────────── */
export function BranchPicker({ open, onClose }: { open: boolean; onClose: () => void }) {
  const { t } = useI18n();
  const { branches, branchId, select } = useBranch();
  return (
    <Modal open={open} onClose={onClose} title={t('branch.pick_title')}>
      <p className="-mt-2 mb-5 text-sm text-muted">{t('branch.pick_text')}</p>
      <div className="space-y-2.5">
        <button onClick={() => { select(null); onClose(); }} className={cx('w-full rounded-2xl border p-4 text-start transition-colors', !branchId ? 'border-primary ring-1 ring-ink' : 'border-line hover:border-brass')}>
          <span className="block font-semibold">{t('branch.all')}</span><span className="text-[13px] text-muted">{t('branch.all_sub')}</span>
        </button>
        {branches.map((b) => (
          <button key={b.id} onClick={() => { select(b.id); onClose(); }} className={cx('w-full rounded-2xl border p-4 text-start transition-colors', branchId === b.id ? 'border-primary ring-1 ring-ink' : 'border-line hover:border-brass')}>
            <span className="flex items-center justify-between gap-3"><span className="font-semibold">{b.name}</span>
              <span className={cx('rounded-full px-2 py-0.5 text-[11px] font-semibold', b.opening.is_open ? 'bg-success/12 text-success' : 'bg-muted/15 text-muted')}>{b.opening.is_open ? t('branch.open') : t('branch.closed')}</span></span>
            <span className="text-[13px] text-muted">{b.area}{b.address ? ` · ${b.address}` : ''}</span>
          </button>
        ))}
      </div>
    </Modal>
  );
}

/* ───────────── Cart drawer ───────────── */
export function CartDrawer() {
  const { t, locale } = useI18n();
  const cart = useCart();
  const nav = useNavigate();
  const empty = cart.lines.length === 0;
  return (
    <Drawer open={cart.drawer} onClose={cart.closeDrawer} title={`${t('cart.title')}${cart.count ? ` (${cart.count})` : ''}`}
      footer={!empty && (
        <div>
          <div className="mb-3 flex items-baseline justify-between"><span className="text-sm text-muted">{t('cart.subtotal')}</span><span className="text-xl font-semibold tabular-nums">{cart.subtotal.formatted}</span></div>
          <p className="mb-3 text-xs text-muted">{t('cart.shipping_note')}</p>
          <Button size="lg" block onClick={() => { cart.closeDrawer(); nav('/checkout'); }}>{t('cart.checkout')}</Button>
          <Button size="md" variant="ghost" block className="mt-1.5" onClick={() => { cart.closeDrawer(); nav('/cart'); }}>{t('cart.view')}</Button>
        </div>
      )}>
      {empty ? (
        <div className="flex h-full flex-col items-center justify-center px-8 text-center">
          <ShoppingBag size={44} strokeWidth={1.2} className="text-brass" />
          <p className="mt-4 font-display text-2xl">{t('cart.empty')}</p>
          <p className="mt-1 text-sm text-muted">{t('cart.empty_text')}</p>
          <Button className="mt-6" onClick={() => { cart.closeDrawer(); nav('/shop'); }}>{t('cart.continue')}</Button>
        </div>
      ) : (
        <ul className="divide-y divide-line px-6">
          {cart.lines.map((l) => (
            <li key={l.key} className="flex gap-4 py-5">
              <Link to={`/products/${l.slug}`} onClick={cart.closeDrawer} className="w-24 shrink-0"><Pic src={l.image} alt={l.name} ratio="1/1" className="rounded-2xl" /></Link>
              <div className="min-w-0 flex-1">
                <Link to={`/products/${l.slug}`} onClick={cart.closeDrawer} className="line-clamp-2 font-display text-xl leading-tight">{l.name}</Link>
                {l.variantName && <p className="text-[13px] text-muted">{l.variantName}</p>}
                {l.issue && <p className="mt-1 text-[12px] font-medium text-danger">{t(`cart.issue.${l.issue}`)}</p>}
                <div className="mt-3 flex items-center justify-between">
                  <QtyStepper size="sm" value={l.qty} max={l.max} min={1} onChange={(n) => cart.setQty(l, n)} />
                  <span className="text-[15px] font-semibold tabular-nums" lang={locale}>{l.total.formatted}</span>
                </div>
                <button onClick={() => cart.remove(l)} className="mt-2 text-xs text-muted underline-offset-2 hover:text-danger hover:underline">{t('cart.remove')}</button>
              </div>
            </li>
          ))}
        </ul>
      )}
    </Drawer>
  );
}

