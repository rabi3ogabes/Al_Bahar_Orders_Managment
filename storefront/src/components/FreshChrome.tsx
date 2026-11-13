import { useEffect, useMemo, useState, type ButtonHTMLAttributes, type ReactNode } from 'react';
import { Link, useLocation, useNavigate } from 'react-router-dom';
import { Heart, Languages, MapPin, Mail, Menu, Moon, Phone, Search, ShoppingBag, Sun, User as UserIcon } from 'lucide-react';
import { useAuth } from '../lib/auth';
import { useBranch } from '../lib/branch';
import { useCart } from '../lib/cart';
import { useI18n } from '../lib/i18n';
import { useTheme } from '../lib/theme';
import { useDesign } from '../lib/brand';
import { cx, telHref } from '../lib/format';
import { useAuthModal } from './AuthModal';
import { BranchPicker, CartDrawer, Logo, MenuDrawer, SearchOverlay, SeasonBanner, prefetchHeaderChunks, preload, useCategories } from './shell';
import { DrawerLink } from './drawerLink';

/** Round icon button that takes its colours from the bar it sits on. */
function Ico({ label, brand, className, children, ...p }: ButtonHTMLAttributes<HTMLButtonElement> & { label: string; brand: boolean; children: ReactNode }) {
  return (
    <button aria-label={label} title={label} className={cx('relative inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-full transition-colors', brand ? 'text-on-band hover:bg-white/15' : 'text-ink hover:bg-sand', className)} {...p}>{children}</button>
  );
}

/* ───────────── Header ───────────── */
export function FreshHeader() {
  const { t, locale, setLocale } = useI18n();
  const { theme, toggle } = useTheme();
  const { tokens } = useDesign();
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
  const brand = tokens.header === 'brand';
  const centre = tokens.nav === 'center_action';

  useEffect(() => { setMenu(false); setSearch(false); setPick(false); }, [loc.pathname, loc.search]);
  useEffect(() => { prefetchHeaderChunks(); }, []);
  useEffect(() => { cart.closeDrawer(); }, [loc.pathname]); // eslint-disable-line react-hooks/exhaustive-deps
  const links = useMemo(() => [
    { to: '/', label: t('fresh.nav_home'), on: loc.pathname === '/' },
    { to: '/shop', label: t('nav.shop'), on: loc.pathname === '/shop' && !loc.search.includes('sale=1') },
    { to: '/shop?sale=1', label: t('fresh.nav_deals'), on: loc.pathname === '/shop' && loc.search.includes('sale=1') },
    { to: '/auctions', label: t('nav.auctions'), on: loc.pathname.startsWith('/auctions') },
    { to: '/showrooms', label: t('nav.showrooms'), on: loc.pathname === '/showrooms' },
    { to: '/pages/about', label: t('fresh.nav_about'), on: loc.pathname === '/pages/about' },
  ], [t, loc.pathname, loc.search]);
  const topCats = (cats.data ?? []).slice(0, 8);

  return (
    <>
      <a href="#main" className="sr-only-focusable fixed start-4 top-4 z-[200] rounded-full bg-primary px-4 py-2 text-on-primary">{t('a11y.skip')}</a>
      <SeasonBanner />
      <header className={cx('sticky top-0 z-50 transition-shadow', brand ? 'bg-band text-on-band shadow-[0_6px_24px_-16px_rgb(0_0_0/0.55)]' : 'border-b border-line bg-bg/95 text-ink backdrop-blur')}>
        <div className="wrap flex h-[68px] items-center gap-3 lg:h-[76px] lg:gap-6">
          <Ico brand={brand} label={t('nav.menu')} className="lg:hidden" onClick={() => setMenu(true)} onPointerEnter={preload.menu} onFocus={preload.menu} onTouchStart={preload.menu}><Menu size={22} /></Ico>
          <Logo band={brand} />
          <nav aria-label={t('nav.main')} className={cx('hidden min-w-0 flex-1 items-center gap-1 lg:flex xl:gap-3', centre ? 'justify-center' : 'ms-4 justify-start')}>
            {links.map((l) => (
              <Link key={l.to} to={l.to} aria-current={l.on ? 'page' : undefined}
                className={cx('relative whitespace-nowrap rounded-full px-3 py-2 text-[14px] font-medium transition-colors after:absolute after:inset-x-3 after:-bottom-0.5 after:h-[3px] after:rounded-full after:bg-accent after:transition-transform', l.on ? 'after:scale-x-100' : 'after:scale-x-0 hover:after:scale-x-50', brand ? 'text-on-band/90 hover:text-on-band' : 'text-ink-soft hover:text-ink')}>{l.label}</Link>
            ))}
          </nav>
          <div className="ms-auto flex items-center gap-1 lg:ms-0">
            <button onClick={() => setSearch(true)} aria-label={t('search.title')} onPointerEnter={preload.search} onFocus={preload.search}
              className={cx('hidden h-11 w-[200px] items-center gap-2 rounded-full px-4 text-[14px] transition-colors xl:flex 2xl:w-[260px]', brand ? 'bg-white/12 text-on-band/80 hover:bg-white/20' : 'border border-line bg-paper text-muted hover:border-primary')}>
              <Search size={17} className="shrink-0" /><span className="truncate">{t('fresh.search_ph')}</span>
            </button>
            <Ico brand={brand} label={t('search.title')} className="xl:hidden" onClick={() => setSearch(true)} onPointerEnter={preload.search} onFocus={preload.search} onTouchStart={preload.search}><Search size={20} /></Ico>
            <Ico brand={brand} label={t('wishlist.title')} className="hidden sm:inline-flex" onClick={() => (user ? nav('/account/wishlist') : authModal.open({ reason: t('auth.reason_wishlist') }))}><Heart size={20} /></Ico>
            <Ico brand={brand} label={user ? t('account.title') : t('auth.sign_in')} onClick={() => (user ? nav('/account') : authModal.open())}><UserIcon size={20} /></Ico>
            <Ico brand={brand} label={`${t('cart.title')} (${cart.count})`} onClick={cart.openDrawer} onPointerEnter={preload.cart} onFocus={preload.cart} onTouchStart={preload.cart}>
              <ShoppingBag size={20} />
              {cart.count > 0 && <span className="absolute -end-0.5 -top-0.5 flex h-[19px] min-w-[19px] items-center justify-center rounded-full bg-accent px-1 text-[10px] font-bold text-on-accent">{cart.count}</span>}
            </Ico>
            <span className="hidden items-center gap-0.5 lg:flex">
              {branches.length > 1 && <Ico brand={brand} label={branch ? branch.name : t('branch.all')} onClick={() => setPick(true)}><MapPin size={19} /></Ico>}
              <Ico brand={brand} label={theme === 'dark' ? t('theme.light') : t('theme.dark')} onClick={toggle}>{theme === 'dark' ? <Sun size={18} /> : <Moon size={18} />}</Ico>
              <Ico brand={brand} label={locale === 'en' ? 'العربية' : 'English'} onClick={() => setLocale(locale === 'en' ? 'ar' : 'en')}><Languages size={18} /></Ico>
            </span>
          </div>
        </div>
      </header>

      <MenuDrawer open={menu} onClose={() => setMenu(false)}>
        <nav className="px-6 py-4">
          <ul className="divide-y divide-line text-[18px] font-semibold">
            {links.map((l) => <li key={l.to}><DrawerLink className={cx('flex items-center justify-between py-3.5', l.on && 'text-brass-deep')} to={l.to}>{l.label}</DrawerLink></li>)}
          </ul>
          {topCats.length > 0 && (
            <div className="mt-5 flex flex-wrap gap-2">
              {topCats.map((c) => <DrawerLink key={c.id} to={`/shop?category=${c.slug}`} className="rounded-full border border-line bg-paper px-3.5 py-1.5 text-sm hover:border-primary">{c.name}</DrawerLink>)}
            </div>
          )}
          <div className="mt-6 grid gap-2 text-sm">
            {branches.length > 1 && <button onClick={() => { setMenu(false); setPick(true); preload.branch(); }} className="flex items-center gap-3 rounded-2xl border border-line bg-paper p-4"><MapPin size={18} className="text-brass" />{branch ? branch.name : t('branch.all')}</button>}
            <button onClick={() => setLocale(locale === 'en' ? 'ar' : 'en')} className="flex items-center gap-3 rounded-2xl border border-line bg-paper p-4"><Languages size={18} className="text-brass" />{locale === 'en' ? 'العربية' : 'English'}</button>
            <button onClick={toggle} className="flex items-center gap-3 rounded-2xl border border-line bg-paper p-4">{theme === 'dark' ? <Sun size={18} className="text-brass" /> : <Moon size={18} className="text-brass" />}{theme === 'dark' ? t('theme.light') : t('theme.dark')}</button>
          </div>
        </nav>
      </MenuDrawer>
      <SearchOverlay open={search} onClose={() => setSearch(false)} />
      <BranchPicker open={pick} onClose={() => setPick(false)} />
      <CartDrawer />
    </>
  );
}

/* ───────────── Footer ───────────── */
export function FreshFooter() {
  const { t } = useI18n();
  const { branches } = useBranch();
  const b = branches[0];
  const col = 'text-[15px] font-semibold text-on-band';
  const a = 'text-[14px] text-on-band/75 hover:text-accent';
  return (
    <footer className="fx-sec bg-band text-on-band">
      <div className="wrap grid grid-cols-[minmax(0,1fr)] gap-10 py-14 sm:grid-cols-2 lg:grid-cols-[1.4fr_1fr_1fr_1.3fr]">
        <div>
          <Logo light band />
          <p className="mt-5 max-w-xs text-[14px] leading-relaxed text-on-band/75">{t('footer.about')}</p>
        </div>
        <div>
          <h3 className={col}>{t('fresh.footer_quick')}</h3>
          <ul className="mt-4 space-y-2.5">
            <li><Link className={a} to="/shop">{t('nav.shop')}</Link></li>
            <li><Link className={a} to="/shop?sale=1">{t('footer.sale')}</Link></li>
            <li><Link className={a} to="/shop?new=1">{t('footer.new')}</Link></li>
            <li><Link className={a} to="/auctions">{t('nav.auctions')}</Link></li>
            <li><Link className={a} to="/pages/about">{t('footer.about_us')}</Link></li>
          </ul>
        </div>
        <div>
          <h3 className={col}>{t('fresh.footer_support')}</h3>
          <ul className="mt-4 space-y-2.5">
            <li><Link className={a} to="/account/orders">{t('account.orders')}</Link></li>
            <li><Link className={a} to="/account/support">{t('account.support')}</Link></li>
            <li><Link className={a} to="/pages/returns">{t('footer.returns')}</Link></li>
            <li><Link className={a} to="/pages/privacy">{t('footer.privacy')}</Link></li>
            <li><Link className={a} to="/pages/terms">{t('footer.terms')}</Link></li>
          </ul>
        </div>
        <div>
          <h3 className={col}>{t('fresh.footer_contact')}</h3>
          <ul className="mt-4 space-y-3 text-[14px] text-on-band/80">
            {b?.address && <li><Link to="/showrooms" className="flex items-start gap-2.5 hover:text-accent"><MapPin size={16} className="mt-0.5 shrink-0 text-accent" /><span>{b.name}{b.area ? ` · ${b.area}` : ''}<br />{b.address}</span></Link></li>}
            {b?.phone && <li><a href={telHref(b.phone)} className="flex items-center gap-2.5 hover:text-accent"><Phone size={16} className="shrink-0 text-accent" /><bdi>{b.phone}</bdi></a></li>}
            {b?.email && <li><a href={`mailto:${b.email}`} className="flex items-center gap-2.5 hover:text-accent"><Mail size={16} className="shrink-0 text-accent" /><bdi>{b.email}</bdi></a></li>}
            {!b && <li><Link to="/showrooms" className="flex items-center gap-2.5 hover:text-accent"><MapPin size={16} className="shrink-0 text-accent" />{t('nav.showrooms')}</Link></li>}
          </ul>
        </div>
      </div>
      <div className="border-t border-on-band/15">
        <div className="wrap py-5 text-center text-[13px] text-on-band/70">© {new Date().getFullYear()} Al Bahar Home &amp; Living. {t('footer.rights')}</div>
      </div>
    </footer>
  );
}
