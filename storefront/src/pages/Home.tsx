import { lazy, Suspense } from 'react';
import { Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { ArrowRight, ArrowLeft, Gavel, MapPin, PackageCheck, ShieldCheck, Sparkles, Truck, Clock } from 'lucide-react';
import { get } from '../lib/api';
import { useBranch } from '../lib/branch';
import { useI18n } from '../lib/i18n';
import { useDocTitle, useTick } from '../lib/hooks';
import { useBrand, useDesign } from '../lib/brand';
import type { Auction, Brand, HomeSection, ProductCard as P } from '../lib/types';
import { Rail } from '../components/Rail';
import { BuyAgain } from '../components/BuyAgain';
import { CardSkeletons, ProductCard } from '../components/ProductCard';
import { Badge, LinkButton, Pic, SectionHead, Skeleton } from '../components/ui';

function linkFor(l?: { type: string; value: string } | null): string {
  if (!l) return '/shop';
  if (l.type === 'category') return `/shop?category=${l.value}`;
  if (l.type === 'product') return `/products/${l.value}`;
  if (l.type === 'auction') return `/auctions/${l.value}`;
  return l.value || '/shop';
}

function Hero({ banner }: { banner?: { title: string; subtitle: string | null; image_url: string | null; link: any } }) {
  const { t, dir, num } = useI18n();
  const { branches, branchId } = useBranch();
  const brand = useBrand();
  const heroImage = brand?.images.hero ?? banner?.image_url ?? null;
  const eventTitle = brand && brand.kind !== 'base' ? brand.banner.title : null;
  const eventSub = brand && brand.kind !== 'base' ? brand.banner.subtitle : null;
  const cats = useQuery({ queryKey: ['categories'], queryFn: async () => (await get<{ data: unknown[] }>('/categories')).data, staleTime: 10 * 60_000 });
  const total = useQuery({ queryKey: ['catalogue-size', branchId], queryFn: async () => (await get<{ meta: { total: number } }>('/products', { per_page: 1, branch_id: branchId })).meta.total, staleTime: 10 * 60_000 });
  const stats: [string, string][] = [];
  if (branches.length > 1) stats.push([num(branches.length), t('home.stat_showrooms')]);
  if (total.data) stats.push([total.data >= 100 ? `${num(Math.floor(total.data / 50) * 50)}+` : num(total.data), t('home.stat_warranty')]);
  if (cats.data?.length) stats.push([num(cats.data.length), t('home.stat_delivery')]);
  const Arrow = dir === 'rtl' ? ArrowLeft : ArrowRight;
  return (
    <section className="relative overflow-hidden">
      <div className="wrap grid items-center gap-10 py-10 md:py-16 lg:grid-cols-[minmax(0,1.05fr)_minmax(0,1fr)] lg:py-20">
        <div className="rise order-2 lg:order-1">
          <p className="eyebrow mb-5 flex items-center gap-2"><Sparkles size={14} />{t('home.eyebrow')}</p>
          <h1 className="text-[clamp(44px,7vw,92px)] leading-[0.98]">{eventTitle || banner?.title || t('home.title')}</h1>
          <p className="mt-6 max-w-lg text-[17px] leading-relaxed text-ink-soft">{eventSub || banner?.subtitle || t('home.subtitle')}</p>
          <div className="mt-9 flex flex-wrap gap-3">
            <LinkButton to={banner ? linkFor(banner.link) : '/shop'} size="lg" variant="primary">{t('home.cta')}<Arrow size={18} /></LinkButton>
            <LinkButton to="/showrooms" size="lg" variant="outline"><MapPin size={17} />{t('home.cta_showrooms')}</LinkButton>
          </div>
          <dl className="mt-12 grid max-w-lg grid-cols-3 gap-6 border-t border-line pt-6 text-sm empty:hidden">
            {stats.map(([n, l]) => (
              <div key={l}><dt className="font-display text-4xl text-brass-deep">{n}</dt><dd className="mt-0.5 text-[13px] text-muted">{l}</dd></div>
            ))}
          </dl>
        </div>
        <div className="order-1 lg:order-2">
          <div className="relative mx-auto aspect-[4/5] w-full max-w-[520px]">
            {/* Arch-shaped frame — a nod to Qatari majlis architecture. */}
            <div className="absolute inset-0 overflow-hidden rounded-t-[999px] rounded-b-[32px] bg-[radial-gradient(90%_70%_at_50%_0%,var(--brass-tint),var(--sand)_60%,var(--sand-deep))] shadow-soft">
              {heroImage ? <img src={heroImage} alt="" className="h-full w-full object-cover" fetchPriority="high" /> : (
                <svg viewBox="0 0 400 500" className="h-full w-full" aria-hidden>
                  <defs><linearGradient id="g" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stopColor="var(--brass)" stopOpacity=".35" /><stop offset="1" stopColor="var(--brass)" stopOpacity=".05" /></linearGradient></defs>
                  <path d="M60 500V230a140 140 0 0 1 280 0v270" fill="none" stroke="var(--brass)" strokeOpacity=".5" strokeWidth="1.5" />
                  <path d="M100 500V240a100 100 0 0 1 200 0v260" fill="url(#g)" stroke="var(--brass)" strokeOpacity=".6" strokeWidth="1.5" />
                  <path d="M150 500V250a50 50 0 0 1 100 0v250" fill="var(--paper)" fillOpacity=".7" stroke="var(--brass)" strokeOpacity=".7" strokeWidth="1.5" />
                  <g fill="var(--ink)" fillOpacity=".85"><rect x="118" y="352" width="44" height="86" rx="10" /><rect x="132" y="334" width="16" height="22" rx="4" /><rect x="170" y="372" width="40" height="66" rx="9" /><rect x="182" y="356" width="16" height="20" rx="4" /><rect x="222" y="384" width="62" height="54" rx="8" /></g>
                  <rect x="232" y="396" width="42" height="7" rx="3.5" fill="var(--brass)" fillOpacity=".8" /><rect x="118" y="438" width="166" height="8" rx="4" fill="var(--brass)" fillOpacity=".55" />
                  <circle cx="200" cy="140" r="4" fill="var(--brass)" />
                </svg>
              )}
            </div>
            <div className="absolute -start-3 bottom-10 hidden rounded-2xl border border-line bg-paper px-5 py-4 shadow-soft sm:block">
              <p className="eyebrow !text-[10px]">{t('home.badge_label')}</p><p className="font-display text-2xl">{t('home.badge')}</p>
            </div>
          </div>
        </div>
      </div>
    </section>
  );
}

function PromiseStrip() {
  const { t } = useI18n();
  const items = [[Truck, t('promise.delivery'), t('promise.delivery_text')], [PackageCheck, t('promise.pickup'), t('promise.pickup_text')], [ShieldCheck, t('promise.warranty'), t('promise.warranty_text')], [Clock, t('promise.support'), t('promise.support_text')]] as const;
  return (
    <section aria-label={t('promise.title')} className="border-y border-line bg-paper">
      <ul className="wrap grid grid-cols-2 gap-x-6 gap-y-6 py-7 lg:grid-cols-4">
        {items.map(([Icon, a, b]) => (
          <li key={a} className="flex items-start gap-3.5"><Icon size={24} strokeWidth={1.4} className="mt-0.5 shrink-0 text-brass" /><div><p className="text-sm font-semibold">{a}</p><p className="text-[13px] leading-snug text-muted">{b}</p></div></li>
        ))}
      </ul>
    </section>
  );
}

function CountdownText({ iso }: { iso: string }) {
  useTick(1000);
  const { t } = useI18n();
  const ms = new Date(iso).getTime() - Date.now();
  if (ms <= 0) return <>{t('auction.ended')}</>;
  const h = Math.floor(ms / 3.6e6), m = Math.floor((ms % 3.6e6) / 6e4), s = Math.floor((ms % 6e4) / 1000);
  return <span className="tabular-nums">{String(h).padStart(2, '0')}:{String(m).padStart(2, '0')}:{String(s).padStart(2, '0')}</span>;
}

export function AuctionBand({ items }: { items: Auction[] }) {
  const { t } = useI18n();
  if (!items.length) return null;
  const a = items[0];
  return (
    <section className="mt-24 bg-primary text-bg">
      <div className="wrap grid items-center gap-10 py-16 md:grid-cols-2">
        <div>
          <p className="eyebrow mb-4 !text-on-primary/80"><span className="me-2 inline-block h-2 w-2 animate-pulse rounded-full bg-danger align-middle" />{t('auction.live_now')}</p>
          <h2 className="text-[clamp(34px,5vw,58px)] leading-[1.05]">{a.title}</h2>
          <p className="mt-4 max-w-md text-bg/70">{t('auction.band_text')}</p>
          <div className="mt-8 flex flex-wrap items-center gap-4">
            <LinkButton to={`/auctions/${a.id}`} variant="brass" size="lg"><Gavel size={18} />{t('auction.enter')}</LinkButton>
            <span className="text-sm text-bg/70">{t('auction.lots', { n: a.lots_count })}{new Date(a.ends_at).getTime() > Date.now() && <> · {t('auction.ends_in')} <CountdownText iso={a.ends_at} /></>}</span>
          </div>
        </div>
        <Link to={`/auctions/${a.id}`} className="group block"><Pic src={a.cover_image_url} alt={a.title} ratio="16/10" label={a.title} className="rounded-[28px] ring-1 ring-bg/15" /></Link>
      </div>
    </section>
  );
}

function ClassicHome() {
  const { t, dir } = useI18n();
  const { branchId } = useBranch();
  useDocTitle();
  const Arrow = dir === 'rtl' ? ArrowLeft : ArrowRight;
  const home = useQuery({ queryKey: ['home', branchId], queryFn: async () => (await get<{ data: { sections: HomeSection[] } }>('/home', { branch_id: branchId })).data.sections });
  const sections = home.data ?? [];
  const byType = (ty: string) => sections.filter((s) => s.type === ty);
  const banner = byType('banners')[0]?.items[0];
  const cats = byType('categories')[0];

  const productSection = (s: HomeSection, eyebrow?: string) => s.items.length === 0 ? null : (
    <section key={s.id} className="wrap mt-24">
      <SectionHead eyebrow={eyebrow} title={s.title ?? ''} link={{ to: '/shop', label: t('action.view_all') }} />
      {s.layout === 'grid'
        ? <div className="grid grid-cols-2 gap-x-4 gap-y-9 md:gap-x-6 lg:grid-cols-3 xl:grid-cols-6">{(s.items as P[]).slice(0, 6).map((p) => <ProductCard key={p.id} p={p} />)}</div>
        : <Rail>{(s.items as P[]).map((p) => <ProductCard key={p.id} p={p} />)}</Rail>}
    </section>
  );

  return (
    <>
      <Hero banner={banner} />
      <PromiseStrip />
      <BuyAgain />

      {cats && cats.items.length > 0 && (
        <section className="wrap mt-24">
          <SectionHead eyebrow={t('home.rooms_eyebrow')} title={cats.title ?? t('home.rooms')} link={{ to: '/shop', label: t('action.view_all') }} />
          <div className="grid grid-cols-2 gap-4 md:grid-cols-3 md:gap-6 lg:grid-cols-5">
            {cats.items.map((c: any, i: number) => (
              <Link key={c.id} to={`/shop?category=${c.slug}`} className="group relative block overflow-hidden rounded-[24px]">
                <Pic src={c.image_url} alt={c.name} ratio={i % 2 ? '3/4' : '4/5'} label={c.name} />
                <div className="absolute inset-x-0 bottom-0 flex items-end justify-between bg-gradient-to-t from-ink/70 via-ink/20 to-transparent p-4 pt-14 text-white">
                  <span className="font-display text-2xl leading-none">{c.name}</span>
                  <Arrow size={18} className="mb-0.5 transition-transform group-hover:translate-x-1 rtl:group-hover:-translate-x-1" />
                </div>
              </Link>
            ))}
          </div>
        </section>
      )}

      {home.isLoading && <div className="wrap mt-24"><Skeleton className="mb-8 h-10 w-72" /><CardSkeletons n={4} /></div>}

      {byType('promotions')[0]?.items.length ? (
        <section className="wrap mt-24">
          <ul className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
            {byType('promotions')[0].items.slice(0, 3).map((p: any) => (
              <li key={p.id}><Link to="/shop?sale=1" className="flex h-full items-center gap-4 rounded-[24px] border border-brass/30 bg-brass-tint p-5 transition-shadow hover:shadow-soft">
                <span className="flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-brass text-sm font-bold text-on-brass">{p.badge ?? '%'}</span>
                <span><span className="block font-display text-2xl leading-tight">{p.name}</span>{p.description && <span className="text-sm text-ink-soft">{p.description}</span>}</span></Link></li>
            ))}
          </ul>
        </section>
      ) : null}

      {byType('featured_products').map((s) => productSection(s, t('home.featured_eyebrow')))}
      {byType('new_arrivals').map((s) => productSection(s, t('home.new_eyebrow')))}
      <AuctionBand items={(byType('live_auctions')[0]?.items ?? []) as Auction[]} />
      {byType('best_sellers').map((s) => productSection(s, t('home.best_eyebrow')))}
      {byType('recommended').map((s) => productSection(s))}
      {byType('product_collection').map((s) => productSection(s))}

      {byType('brands')[0]?.items.length ? (
        <section className="wrap mt-24">
          <SectionHead eyebrow={t('home.brands_eyebrow')} title={byType('brands')[0].title ?? t('home.brands')} />
          <ul className="grid grid-cols-2 gap-4 sm:grid-cols-4">
            {(byType('brands')[0].items as Brand[]).map((b) => (
              <li key={b.id}><Link to={`/shop?brand=${b.slug}`} className="flex h-24 items-center justify-center rounded-[20px] border border-line bg-paper px-4 text-center transition-colors hover:border-brass">
                {b.logo_url ? <img src={b.logo_url} alt={b.name} className="max-h-12 object-contain opacity-80" loading="lazy" /> : <span className="font-display text-2xl tracking-wide text-ink-soft">{b.name}</span>}</Link></li>
            ))}
          </ul>
        </section>
      ) : null}

      <section className="wrap mt-24">
        <div className="relative overflow-hidden rounded-[32px] bg-[linear-gradient(135deg,var(--sand),var(--brass-tint))] p-8 md:p-14">
          <div className="max-w-xl">
            <Badge tone="brass"><MapPin size={12} className="me-1" />{t('home.visit_badge')}</Badge>
            <h2 className="mt-4 text-[clamp(30px,4vw,48px)] leading-tight">{t('home.visit_title')}</h2>
            <p className="mt-3 text-ink-soft">{t('home.visit_text')}</p>
            <LinkButton to="/showrooms" className="mt-7" size="lg">{t('home.visit_cta')}<Arrow size={18} /></LinkButton>
          </div>
        </div>
      </section>
    </>
  );
}

const FreshHome = lazy(() => import('./FreshHome'));

/** The web design chosen in the dashboard decides which home page renders. */
export default function Home() {
  const { layout } = useDesign();
  if (layout !== 'fresh') return <ClassicHome />;
  return <Suspense fallback={<div className="min-h-[60vh]" />}><FreshHome /></Suspense>;
}
