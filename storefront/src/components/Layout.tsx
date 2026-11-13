import { useEffect, useMemo, useState } from 'react';
import { Link, NavLink, Outlet, useLocation, useNavigate } from 'react-router-dom';
import { Heart, Languages, MapPin, Menu, Moon, Search, ShoppingBag, Sun, User as UserIcon, Phone, Mail, ArrowRight, ArrowLeft, Truck, ShieldCheck, Gavel, X } from 'lucide-react';
import { useAuth } from '../lib/auth';
import { useBranch } from '../lib/branch';
import { useCart } from '../lib/cart';
import { useI18n } from '../lib/i18n';
import { useTheme } from '../lib/theme';
import { useDesign } from '../lib/brand';
import { cx, telHref } from '../lib/format';
import { useAuthModal } from './AuthModal';
import { Drawer, IconButton } from './ui';
import { useToast } from '../lib/toast';
import { BranchPicker, CartDrawer, Logo, SearchOverlay, SeasonBanner, useCategories } from './shell';
import { FreshFooter, FreshHeader } from './FreshChrome';

export { Logo, LogoMark } from './shell';

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
      <SeasonBanner />
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
              {cart.count > 0 && <span className="absolute end-0.5 top-0.5 flex h-[18px] min-w-[18px] items-center justify-center rounded-full bg-brass px-1 text-[10px] font-bold text-on-brass">{cart.count}</span>}
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
            <button aria-label={t('footer.subscribe')} className="flex h-10 w-10 items-center justify-center rounded-full bg-brass text-on-brass hover:bg-bg"><Arrow size={16} /></button>
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

function PreviewBar() {
  const { preview, exitPreview } = useDesign();
  const { t } = useI18n();
  if (!preview) return null;
  return (
    <div role="status" className="sticky top-0 z-[120] flex items-center justify-center gap-3 bg-ink px-4 py-2 text-[13px] text-bg">
      <span>{t('preview.label', { name: preview.name })}</span>
      <button onClick={exitPreview} className="inline-flex items-center gap-1 rounded-full border border-bg/40 px-3 py-0.5 font-medium hover:bg-bg hover:text-ink"><X size={13} />{t('preview.exit')}</button>
    </div>
  );
}

export function Layout() {
  const loc = useLocation();
  const { layout, ready } = useDesign();
  useEffect(() => { window.scrollTo({ top: 0, behavior: 'instant' as ScrollBehavior }); }, [loc.pathname, loc.search]);
  const fresh = layout === 'fresh';
  if (!ready) return <div className="min-h-dvh" aria-busy="true" />;
  return (
    <div className="flex min-h-dvh flex-col">
      <PreviewBar />
      {fresh ? <FreshHeader /> : <Header />}
      <main id="main" className="flex-1 outline-none" tabIndex={-1}><Outlet /></main>
      {fresh ? <FreshFooter /> : <Footer />}
    </div>
  );
}
