import { useEffect, useMemo, useRef, useState } from 'react';
import { Link, NavLink, Outlet, useLocation, useNavigate } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { Heart, Languages, MapPin, Menu, Moon, Search, ShoppingBag, Sun, User as UserIcon, X, Phone, Mail, ArrowRight, ArrowLeft, Truck, ShieldCheck, Gavel } from 'lucide-react';
import { get } from '../lib/api';
import { useAuth } from '../lib/auth';
import { useBranch } from '../lib/branch';
import { useCart } from '../lib/cart';
import { useI18n } from '../lib/i18n';
import { useTheme } from '../lib/theme';
import { useDebounce } from '../lib/hooks';
import { cx, telHref } from '../lib/format';
import type { Category, Paged, ProductCard } from '../lib/types';
import { useAuthModal } from './AuthModal';
import { Button, Drawer, IconButton, Modal, Pic, PriceTag, QtyStepper, Spinner } from './ui';
import { useToast } from '../lib/toast';

export function LogoMark({ className = 'h-9 w-9 sm:h-10 sm:w-10' }: { className?: string }) {
  return (
    <svg viewBox="0 0 64 64" aria-hidden="true" className={cx('shrink-0', className)}>
      <circle cx="32" cy="32" r="32" fill="#8A1538" />
      <circle cx="32" cy="22" r="7" fill="#F6D3DC" />
      <path d="M10 37c4.5-5 9-5 13.5 0s9 5 13.5 0 9-5 13.5 0 2.5 2.5 3.5 3.5" fill="none" stroke="#F6D3DC" strokeWidth="3" strokeLinecap="round" />
      <path d="M10 47c4.5-5 9-5 13.5 0s9 5 13.5 0 9-5 13.5 0 2.5 2.5 3.5 3.5" fill="none" stroke="#FCF9FA" strokeWidth="3" strokeLinecap="round" opacity=".9" />
    </svg>
  );
}

/** Bilingual lockup: wave mark, Arabic name over the Latin name. */
export function Logo({ light }: { light?: boolean }) {
  return (
    <Link to="/" className={cx('flex items-center gap-2.5 leading-none', light ? 'text-bg' : 'text-ink')} aria-label="Al Bahar البحار">
      <LogoMark />
      <span className="flex flex-col items-start">
        <span lang="ar" dir="rtl" className="font-arabic text-[24px] font-semibold leading-[1.05] sm:text-[28px]">البحار</span>
        <span className="mt-0.5 whitespace-nowrap text-[10px] font-semibold uppercase tracking-[0.3em] text-brass sm:text-[11px]">Al Bahar</span>
      </span>
    </Link>
  );
}

function useCategories() {
  return useQuery({ queryKey: ['categories'], queryFn: async () => (await get<{ data: Category[] }>('/categories')).data, staleTime: 10 * 60_000 });
}

/* ───────────── Search ───────────── */
function SearchOverlay({ open, onClose }: { open: boolean; onClose: () => void }) {
  const { t } = useI18n();
  const nav = useNavigate();
  const [q, setQ] = useState('');
  const dq = useDebounce(q.trim(), 250);
  const ref = useRef<HTMLInputElement>(null);
  const { branchId } = useBranch();
  const res = useQuery({
    queryKey: ['suggest', dq, branchId], enabled: open && dq.length >= 2,
    queryFn: async () => (await get<Paged<ProductCard>>('/products', { q: dq, per_page: 6, branch_id: branchId })).data,
  });
  useEffect(() => { if (open) { setQ(''); setTimeout(() => ref.current?.focus(), 50); } }, [open]);
  useEffect(() => { if (!open) return; const k = (e: KeyboardEvent) => e.key === 'Escape' && onClose(); window.addEventListener('keydown', k); return () => window.removeEventListener('keydown', k); }, [open, onClose]);
  if (!open) return null;
  const go = (e?: React.FormEvent) => { e?.preventDefault(); if (q.trim()) { onClose(); nav(`/shop?q=${encodeURIComponent(q.trim())}`); } };
  return (
    <div className="fixed inset-0 z-[85]" role="dialog" aria-modal="true" aria-label={t('search.title')}>
      <div className="fade absolute inset-0 bg-ink/45 backdrop-blur-[2px]" onClick={onClose} />
      <div className="rise relative mx-auto mt-0 max-w-3xl rounded-b-[28px] bg-bg p-5 shadow-soft sm:mt-6 sm:rounded-[28px] sm:p-7">
        <form onSubmit={go} className="flex items-center gap-3 border-b border-primary pb-3">
          <Search size={22} className="text-muted" />
          <input ref={ref} value={q} onChange={(e) => setQ(e.target.value)} placeholder={t('search.placeholder')} aria-label={t('search.title')} className="flex-1 bg-transparent font-display text-[26px] outline-none placeholder:text-muted/60" />
          <IconButton label={t('action.close')} type="button" onClick={onClose}><X size={20} /></IconButton>
        </form>
        <div className="mt-5 min-h-24">
          {res.isFetching && <div className="flex justify-center py-6"><Spinner /></div>}
          {!res.isFetching && dq.length >= 2 && res.data?.length === 0 && <p className="py-6 text-center text-muted">{t('search.none', { q: dq })}</p>}
          {!res.isFetching && res.data && res.data.length > 0 && (
            <ul className="divide-y divide-line">
              {res.data.map((p) => (
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
function BranchPicker({ open, onClose }: { open: boolean; onClose: () => void }) {
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
function CartDrawer() {
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

/* ───────────── Header ───────────── */
export function Header() {
  const { t, locale, setLocale } = useI18n();
  const { theme, toggle } = useTheme();
  const { user } = useAuth();
  const { branch, branches } = useBranch();
  const cart = useCart();
  const authModal = useAuthModal();
  const cats = useCategories();
  const nav = useNavigate();
  const loc = useLocation();
  const [menu, setMenu] = useState(false);
  const [search, setSearch] = useState(false);
  const [pick, setPick] = useState(false);
  const [stuck, setStuck] = useState(false);

  useEffect(() => { setMenu(false); }, [loc.pathname]);
  useEffect(() => { const f = () => setStuck(window.scrollY > 8); f(); window.addEventListener('scroll', f, { passive: true }); return () => window.removeEventListener('scroll', f); }, []);
  const top = useMemo(() => (cats.data ?? []).slice(0, 6), [cats.data]);
  const link = ({ isActive }: { isActive: boolean }) => cx('relative whitespace-nowrap py-2 text-[13px] font-medium uppercase tracking-[0.14em] transition-colors hover:text-brass-deep after:absolute after:inset-x-0 after:-bottom-0.5 after:h-px after:origin-center after:scale-x-0 after:bg-brass after:transition-transform hover:after:scale-x-100', isActive ? 'text-brass-deep after:scale-x-100' : 'text-ink-soft');

  return (
    <>
      <a href="#main" className="sr-only-focusable fixed start-4 top-4 z-[200] rounded-full bg-primary px-4 py-2 text-bg">{t('a11y.skip')}</a>
      <div className="bg-primary text-[12px] text-bg/90">
        <div className="wrap flex items-center justify-between gap-4 py-2">
          <span className="flex min-w-0 items-center gap-2"><Truck size={14} className="shrink-0 text-rose" /><span className="truncate">{t('top.promo')}</span></span>
          {branches[0]?.phone && (
            <span className="hidden items-center gap-5 sm:flex">
              <a href={telHref(branches[0].phone)} className="flex items-center gap-1.5 hover:text-brass"><Phone size={13} /><bdi>{branches[0].phone}</bdi></a>
            </span>
          )}
        </div>
      </div>
      <header className={cx('sticky top-0 z-50 border-b transition-all duration-300', stuck ? 'border-line bg-bg/92 shadow-[0_6px_24px_-18px_rgb(0_0_0/0.4)] backdrop-blur-xl' : 'border-transparent bg-bg')}>
        <div className="wrap grid h-[68px] grid-cols-[1fr_auto_1fr] items-center gap-2 lg:h-[84px]">
          <div className="flex items-center gap-1">
            <IconButton label={t('nav.menu')} className="lg:hidden" onClick={() => setMenu(true)}><Menu size={22} /></IconButton>
            <nav aria-label={t('nav.main')} className="hidden items-center gap-7 lg:flex">
              <NavLink to="/shop" end className={link}>{t('nav.shop')}</NavLink>
              {top.slice(0, 3).map((c) => <NavLink key={c.id} to={`/shop?category=${c.slug}`} className={link}>{c.name}</NavLink>)}
            </nav>
          </div>
          <Logo />
          <div className="flex items-center justify-end gap-0.5">
            <nav aria-label={t('nav.secondary')} className="me-3 hidden items-center gap-6 xl:flex">
              <NavLink to="/auctions" className={link}>{t('nav.auctions')}</NavLink>
              <NavLink to="/showrooms" className={link}>{t('nav.showrooms')}</NavLink>
            </nav>
            <IconButton label={t('search.title')} onClick={() => setSearch(true)}><Search size={20} /></IconButton>
            <span className="hidden sm:inline-flex"><IconButton label={t('wishlist.title')} onClick={() => (user ? nav('/account/wishlist') : authModal.open({ reason: t('auth.reason_wishlist') }))}><Heart size={20} /></IconButton></span>
            <IconButton label={user ? t('account.title') : t('auth.sign_in')} onClick={() => (user ? nav('/account') : authModal.open())}><UserIcon size={20} /></IconButton>
            <IconButton label={`${t('cart.title')} (${cart.count})`} onClick={cart.openDrawer}>
              <ShoppingBag size={20} />
              {cart.count > 0 && <span className="absolute end-0.5 top-0.5 flex h-[18px] min-w-[18px] items-center justify-center rounded-full bg-brass px-1 text-[10px] font-bold text-white">{cart.count}</span>}
            </IconButton>
          </div>
        </div>
        <div className="hidden border-t border-line/70 lg:block">
          <div className="wrap flex h-10 items-center justify-between text-[13px] text-muted">
            {branches.length > 1 ? (
              <button onClick={() => setPick(true)} className="flex items-center gap-2 hover:text-ink"><MapPin size={14} className="text-brass" />{branch ? branch.name : t('branch.all')}<span className="text-[11px] underline underline-offset-2">{t('branch.change')}</span></button>
            ) : (
              <Link to="/showrooms" className="flex items-center gap-2 hover:text-ink"><MapPin size={14} className="text-brass" />{branches[0] ? `${branches[0].name}${branches[0].area ? ` · ${branches[0].area}` : ''}` : t('nav.showrooms')}</Link>
            )}
            <div className="flex items-center gap-1">
              <button onClick={toggle} aria-label={theme === 'dark' ? t('theme.light') : t('theme.dark')} className="flex h-8 w-8 items-center justify-center rounded-full hover:bg-sand">{theme === 'dark' ? <Sun size={15} /> : <Moon size={15} />}</button>
              <button onClick={() => setLocale(locale === 'en' ? 'ar' : 'en')} className="flex h-8 items-center gap-1.5 rounded-full px-3 hover:bg-sand"><Languages size={15} />{locale === 'en' ? 'العربية' : 'English'}</button>
            </div>
          </div>
        </div>
      </header>

      {/* Mobile menu */}
      <Drawer open={menu} onClose={() => setMenu(false)} side="start" title={t('nav.menu')}>
        <nav className="px-6 py-4">
          <ul className="divide-y divide-line font-display text-[26px]">
            <li><Link className="flex items-center justify-between py-4" to="/shop">{t('nav.shop')}</Link></li>
            {top.map((c) => <li key={c.id}><Link className="block py-4" to={`/shop?category=${c.slug}`}>{c.name}</Link></li>)}
            <li><Link className="flex items-center gap-2 py-4" to="/auctions"><Gavel size={20} className="text-brass" />{t('nav.auctions')}</Link></li>
            <li><Link className="block py-4" to="/showrooms">{t('nav.showrooms')}</Link></li>
          </ul>
          <div className="mt-6 grid gap-2 text-sm">
            {branches.length > 1 && <button onClick={() => { setMenu(false); setPick(true); }} className="flex items-center gap-3 rounded-2xl border border-line bg-paper p-4"><MapPin size={18} className="text-brass" />{branch ? branch.name : t('branch.all')}</button>}
            <button onClick={() => setLocale(locale === 'en' ? 'ar' : 'en')} className="flex items-center gap-3 rounded-2xl border border-line bg-paper p-4"><Languages size={18} className="text-brass" />{locale === 'en' ? 'العربية' : 'English'}</button>
            <button onClick={toggle} className="flex items-center gap-3 rounded-2xl border border-line bg-paper p-4">{theme === 'dark' ? <Sun size={18} className="text-brass" /> : <Moon size={18} className="text-brass" />}{theme === 'dark' ? t('theme.light') : t('theme.dark')}</button>
          </div>
        </nav>
      </Drawer>
      <SearchOverlay open={search} onClose={() => setSearch(false)} />
      <BranchPicker open={pick} onClose={() => setPick(false)} />
      <CartDrawer />
    </>
  );
}

/* ───────────── Footer ───────────── */
export function Footer() {
  const { t, dir } = useI18n();
  const { branches } = useBranch();
  const toast = useToast();
  const Arrow = dir === 'rtl' ? ArrowLeft : ArrowRight;
  const [email, setEmail] = useState('');
  return (
    <footer className="mt-24 bg-primary text-bg/85">
      <div className="wrap grid gap-10 py-16 md:grid-cols-[1.3fr_1fr_1fr_1.2fr]">
        <div>
          <Logo light />
          <p className="mt-5 max-w-xs text-sm leading-relaxed text-bg/65">{t('footer.about')}</p>
          <ul className="mt-6 space-y-2 text-sm text-bg/75">
            <li className="flex items-center gap-2"><ShieldCheck size={16} className="text-brass" />{t('footer.warranty')}</li>
            <li className="flex items-center gap-2"><Truck size={16} className="text-brass" />{t('footer.delivery')}</li>
          </ul>
        </div>
        <div>
          <h3 className="eyebrow mb-4 !text-brass">{t('footer.shop')}</h3>
          <ul className="space-y-2.5 text-sm">
            <li><Link className="hover:text-brass" to="/shop">{t('nav.shop')}</Link></li>
            <li><Link className="hover:text-brass" to="/shop?sale=1">{t('footer.sale')}</Link></li>
            <li><Link className="hover:text-brass" to="/shop?new=1">{t('footer.new')}</Link></li>
            <li><Link className="hover:text-brass" to="/auctions">{t('nav.auctions')}</Link></li>
          </ul>
        </div>
        <div>
          <h3 className="eyebrow mb-4 !text-brass">{t('footer.help')}</h3>
          <ul className="space-y-2.5 text-sm">
            <li><Link className="hover:text-brass" to="/account/orders">{t('account.orders')}</Link></li>
            <li><Link className="hover:text-brass" to="/account/support">{t('account.support')}</Link></li>
            <li><Link className="hover:text-brass" to="/showrooms">{t('nav.showrooms')}</Link></li>
            <li><Link className="hover:text-brass" to="/pages/returns">{t('footer.returns')}</Link></li>
            <li><Link className="hover:text-brass" to="/pages/about">{t('footer.about_us')}</Link></li>
            <li><a className="hover:text-brass" href="/mobile-web/">{t('footer.app')}</a></li>
          </ul>
        </div>
        <div>
          <h3 className="eyebrow mb-4 !text-brass">{t('footer.news')}</h3>
          <p className="mb-3 text-sm text-bg/65">{t('footer.news_text')}</p>
          <form onSubmit={(e) => { e.preventDefault(); setEmail(''); toast.push(t('footer.thanks')); }} className="flex items-center rounded-full border border-bg/25 p-1 focus-within:border-brass">
            <Mail size={16} className="mx-3 shrink-0 text-bg/50" />
            <input type="email" required value={email} onChange={(e) => setEmail(e.target.value)} placeholder={t('footer.email')} aria-label={t('footer.email')} className="min-w-0 flex-1 bg-transparent text-sm outline-none placeholder:text-bg/45" />
            <button aria-label={t('footer.subscribe')} className="flex h-10 w-10 items-center justify-center rounded-full bg-brass text-white hover:bg-bg"><Arrow size={16} /></button>
          </form>
          {branches[0]?.phone && <a href={telHref(branches[0].phone)} className="mt-6 flex items-center gap-2 text-sm hover:text-brass"><Phone size={15} className="text-brass" />{branches[0].phone}</a>}
        </div>
      </div>
      <div className="border-t border-bg/10">
        <div className="wrap flex flex-col items-center justify-between gap-3 py-5 text-xs text-bg/50 sm:flex-row">
          <span>© {new Date().getFullYear()} Al Bahar Home &amp; Living. {t('footer.rights')}</span>
          <span className="flex items-center gap-4"><Link to="/pages/privacy" className="hover:text-brass">{t('footer.privacy')}</Link><Link to="/pages/terms" className="hover:text-brass">{t('footer.terms')}</Link></span>
        </div>
      </div>
    </footer>
  );
}

export function Layout() {
  const loc = useLocation();
  useEffect(() => { window.scrollTo({ top: 0, behavior: 'instant' as ScrollBehavior }); }, [loc.pathname, loc.search]);
  return (
    <div className="flex min-h-dvh flex-col">
      <Header />
      <main id="main" className="flex-1 outline-none" tabIndex={-1}><Outlet /></main>
      <Footer />
    </div>
  );
}
