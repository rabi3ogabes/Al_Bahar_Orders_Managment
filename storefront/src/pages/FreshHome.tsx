import { Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { ArrowLeft, ArrowRight, CreditCard, PackageCheck, Repeat, ShieldCheck, Store, Tag, Truck, Users } from 'lucide-react';
import { get } from '../lib/api';
import { useBranch } from '../lib/branch';
import { useI18n } from '../lib/i18n';
import { useDocTitle } from '../lib/hooks';
import { useBrand } from '../lib/brand';
import type { Auction, Brand, Category, HomeSection, Paged, ProductCard as P } from '../lib/types';
import { BuyAgain } from '../components/BuyAgain';
import { CardSkeletons, ProductCard } from '../components/ProductCard';
import { Pic, Skeleton } from '../components/ui';
import { AuctionBand } from './Home';

function linkFor(l?: { type: string; value: string } | null): string {
  if (!l) return '/shop';
  if (l.type === 'category') return `/shop?category=${l.value}`;
  if (l.type === 'product') return `/products/${l.value}`;
  if (l.type === 'auction') return `/auctions/${l.value}`;
  return l.value || '/shop';
}

const pill = 'inline-flex h-[52px] items-center justify-center gap-2 rounded-full px-8 text-[15px] font-semibold transition-transform hover:-translate-y-0.5';

function Head({ eyebrow, title, link }: { eyebrow: string; title: string; link?: { to: string; label: string } }) {
  const { dir } = useI18n();
  const Arrow = dir === 'rtl' ? ArrowLeft : ArrowRight;
  return (
    <div className="mb-8 flex flex-wrap items-end justify-between gap-x-6 gap-y-3">
      <div><p className="eyebrow mb-2">{eyebrow}</p><h2 className="text-[clamp(26px,3.6vw,40px)] leading-[1.1]">{title}</h2></div>
      {link && <Link to={link.to} className="group inline-flex items-center gap-1.5 text-[14px] font-semibold text-brass-deep hover:underline">{link.label}<Arrow size={16} className="transition-transform group-hover:translate-x-0.5 rtl:group-hover:-translate-x-0.5" /></Link>}
    </div>
  );
}

function Hero({ banner }: { banner?: { title: string; subtitle: string | null; image_url: string | null; link: any } }) {
  const { t, dir } = useI18n();
  const brand = useBrand();
  const Arrow = dir === 'rtl' ? ArrowLeft : ArrowRight;
  const image = brand?.images.hero ?? banner?.image_url ?? null;
  const custom = (brand && brand.kind !== 'base' ? brand.banner.title : null) || banner?.title || null;
  const sub = (brand && brand.kind !== 'base' ? brand.banner.subtitle : null) || banner?.subtitle || t('fresh.hero_text');
  // Two-line headline: the second half is the accent colour.
  let a = t('fresh.hero_a'), b = t('fresh.hero_b');
  if (custom) { const w = custom.split(/\s+/); const k = Math.ceil(w.length / 2); a = w.slice(0, k).join(' '); b = w.slice(k).join(' '); }
  const trust = [[Truck, t('promise.delivery'), t('promise.delivery_text')], [PackageCheck, t('promise.pickup'), t('promise.pickup_text')], [ShieldCheck, t('promise.warranty'), t('promise.warranty_text')]] as const;
  return (
    <section className="wrap pt-4 sm:pt-6">
      <div className="relative isolate overflow-hidden rounded-[var(--r-big)] text-on-band" style={{ background: 'linear-gradient(120deg, var(--band), color-mix(in srgb, var(--band) 68%, var(--primary-soft)))' }}>
        {image ? (
          <>
            <img src={image} alt="" width={1600} height={720} fetchPriority="high" decoding="async" className="absolute inset-0 -z-20 h-full w-full object-cover" />
            <div className="absolute inset-0 -z-10 bg-gradient-to-r from-band from-25% via-band/75 to-band/10 rtl:bg-gradient-to-l" />
          </>
        ) : (
          <div aria-hidden className="absolute inset-0 -z-10 overflow-hidden">
            <div className="absolute -end-24 -top-24 h-[420px] w-[420px] rounded-full bg-accent/15" />
            <div className="absolute -bottom-32 end-[22%] h-[300px] w-[300px] rounded-full bg-white/8" />
            <div className="absolute end-[8%] top-[30%] h-24 w-24 rounded-full bg-accent/25" />
          </div>
        )}
        <div className="px-6 py-12 sm:px-12 sm:py-16 lg:px-16 lg:py-20">
          <div className="rise max-w-[640px]">
            <p className="eyebrow !text-accent">{t('fresh.hero_eyebrow')}</p>
            <h1 className="mt-4 text-[clamp(34px,5.4vw,66px)] leading-[1.06]">{a}{' '}<span className="block text-accent">{b}</span></h1>
            <p className="mt-5 max-w-[520px] text-[16px] leading-relaxed text-on-band/85 sm:text-[17px]">{sub}</p>
            <Link to={banner ? linkFor(banner.link) : '/shop'} className={`${pill} mt-8 bg-accent text-on-accent`}>{t('fresh.hero_cta')}<Arrow size={18} /></Link>
          </div>
          <ul className="mt-10 grid gap-x-8 gap-y-5 border-t border-on-band/20 pt-7 sm:grid-cols-3">
            {trust.map(([Icon, title, text]) => (
              <li key={title} className="flex items-start gap-3.5">
                <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-accent text-on-accent"><Icon size={21} strokeWidth={1.8} /></span>
                <span><span className="block text-[15px] font-semibold">{title}</span><span className="block text-[13px] leading-snug text-on-band/75">{text}</span></span>
              </li>
            ))}
          </ul>
        </div>
      </div>
    </section>
  );
}

function Categories({ items }: { items: Pick<Category, 'id' | 'slug' | 'name' | 'image_url'>[] }) {
  const { t, dir } = useI18n();
  const Arrow = dir === 'rtl' ? ArrowLeft : ArrowRight;
  if (!items.length) return null;
  return (
    <section className="wrap fx-sec" aria-label={t('fresh.cats_title')}>
      <Head eyebrow={t('fresh.cats_eyebrow')} title={t('fresh.cats_title')} link={{ to: '/shop', label: t('fresh.cats_all') }} />
      <ul className="grid grid-cols-2 gap-3 sm:grid-cols-3 sm:gap-5 lg:grid-cols-6">
        {items.slice(0, 6).map((c) => (
          <li key={c.id}>
            <Link to={`/shop?category=${c.slug}`} className="fx-card fx-hover group relative block h-full p-2.5">
              <Pic src={c.image_url} alt={c.name} ratio="4/3" label={c.name} className="rounded-[var(--r-ctl)]" />
              <span className="flex items-center justify-between gap-2 px-1.5 pb-1 pt-3">
                <span className="line-clamp-2 text-[15px] font-semibold leading-tight">{c.name}</span>
                <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-primary text-on-primary transition-transform group-hover:translate-x-0.5 rtl:group-hover:-translate-x-0.5"><Arrow size={16} /></span>
              </span>
            </Link>
          </li>
        ))}
      </ul>
    </section>
  );
}

function ProductRow({ eyebrow, title, items }: { eyebrow: string; title: string; items: P[] }) {
  const { t } = useI18n();
  if (!items.length) return null;
  return (
    <section className="wrap fx-sec">
      <Head eyebrow={eyebrow} title={title} link={{ to: '/shop', label: t('action.view_all') }} />
      <div className="grid grid-cols-2 gap-3 sm:gap-5 md:grid-cols-3 lg:grid-cols-6">{items.slice(0, 6).map((p) => <ProductCard key={p.id} p={p} />)}</div>
    </section>
  );
}

function PromoBand({ items }: { items: { id: string; name: string; description?: string | null; badge?: string | null }[] }) {
  const { t, dir } = useI18n();
  const Arrow = dir === 'rtl' ? ArrowLeft : ArrowRight;
  if (!items.length) return null;
  const [first, ...rest] = items;
  return (
    <section className="wrap fx-sec">
      <div className="relative overflow-hidden rounded-[var(--r-big)] bg-band px-6 py-10 text-on-band sm:px-12 sm:py-14">
        <div aria-hidden className="absolute -end-20 -top-20 h-72 w-72 rounded-full bg-accent/12" />
        <div className="relative grid items-center gap-8 lg:grid-cols-[1.2fr_1fr]">
          <div>
            <p className="eyebrow !text-accent">{t('fresh.offer_eyebrow')}</p>
            <h2 className="mt-3 text-[clamp(28px,4vw,46px)] leading-[1.1]">{first.name}</h2>
            {first.description && <p className="mt-3 max-w-md text-on-band/80">{first.description}</p>}
            <Link to="/shop?sale=1" className={`${pill} mt-7 bg-accent text-on-accent`}>{t('fresh.offer_cta')}<Arrow size={18} /></Link>
          </div>
          <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-1">
            {[first, ...rest].slice(0, 4).map((p) => (
              <li key={p.id} className="flex items-center gap-3.5 rounded-[var(--r-card)] bg-white/10 p-3.5">
                <span className="flex h-11 min-w-11 shrink-0 items-center justify-center rounded-full bg-accent px-2 text-[13px] font-bold text-on-accent">{p.badge ?? <Tag size={18} />}</span>
                <span className="min-w-0"><span className="block truncate text-[15px] font-semibold">{p.name}</span>{p.description && <span className="block truncate text-[13px] text-on-band/75">{p.description}</span>}</span>
              </li>
            ))}
          </ul>
        </div>
      </div>
    </section>
  );
}

function Why() {
  const { t } = useI18n();
  const items = [[Store, 'fresh.why1'], [CreditCard, 'fresh.why2'], [Users, 'fresh.why3'], [Repeat, 'fresh.why4']] as const;
  return (
    <section className="wrap fx-sec">
      <Head eyebrow={t('fresh.why_eyebrow')} title={t('fresh.why_title')} />
      <ul className="grid gap-3 sm:grid-cols-2 sm:gap-5 lg:grid-cols-4">
        {items.map(([Icon, k]) => (
          <li key={k} className="fx-card p-6">
            <span className="flex h-12 w-12 items-center justify-center rounded-full bg-brass-tint text-brass-deep"><Icon size={22} strokeWidth={1.7} /></span>
            <h3 className="mt-4 text-[17px] leading-snug">{t(k)}</h3>
            <p className="mt-1.5 text-[14px] leading-relaxed text-muted">{t(`${k}_text`)}</p>
          </li>
        ))}
      </ul>
    </section>
  );
}

export default function FreshHome() {
  const { t } = useI18n();
  const { branchId } = useBranch();
  useDocTitle();
  const home = useQuery({ queryKey: ['home', branchId], queryFn: async () => (await get<{ data: { sections: HomeSection[] } }>('/home', { branch_id: branchId })).data.sections });
  const sections = home.data ?? [];
  const byType = (ty: string) => sections.filter((s) => s.type === ty);
  const first = (...types: string[]) => { for (const ty of types) { const s = byType(ty).find((x) => x.items.length > 0); if (s) return s; } return undefined; };
  const banner = byType('banners')[0]?.items[0];

  const homeCats = byType('categories')[0]?.items as Category[] | undefined;
  const catApi = useQuery({ queryKey: ['categories'], enabled: home.isFetched && !homeCats?.length, queryFn: async () => (await get<{ data: Category[] }>('/categories')).data, staleTime: 10 * 60_000 });
  const cats = homeCats?.length ? homeCats : catApi.data ?? [];

  const bestSection = first('best_sellers', 'featured_products', 'recommended');
  const bestApi = useQuery({
    queryKey: ['fresh-best', branchId], enabled: home.isFetched && !bestSection,
    queryFn: async () => (await get<Paged<P>>('/products', { sort: '-popularity', per_page: 6, branch_id: branchId })).data,
  });
  const best = (bestSection?.items as P[] | undefined) ?? bestApi.data ?? [];
  const fresh = first('new_arrivals');
  const promos = (byType('promotions')[0]?.items ?? []) as { id: string; name: string; description?: string | null; badge?: string | null }[];
  const brands = (byType('brands')[0]?.items ?? []) as Brand[];

  return (
    <>
      <Hero banner={banner} />
      <BuyAgain />
      <Categories items={cats} />
      {home.isLoading && <div className="wrap fx-sec"><Skeleton className="mb-8 h-10 w-72" /><CardSkeletons n={6} /></div>}
      <ProductRow eyebrow={t('fresh.best_eyebrow')} title={bestSection?.title && bestSection.type === 'best_sellers' ? bestSection.title : t('fresh.best_title')} items={best} />
      <PromoBand items={promos} />
      {fresh && <ProductRow eyebrow={t('home.new_eyebrow')} title={fresh.title ?? t('footer.new')} items={fresh.items as P[]} />}
      <AuctionBand items={(byType('live_auctions')[0]?.items ?? []) as Auction[]} />
      {brands.length > 0 && (
        <section className="wrap fx-sec">
          <Head eyebrow={t('home.brands_eyebrow')} title={byType('brands')[0].title ?? t('home.brands')} />
          <ul className="grid grid-cols-2 gap-3 sm:grid-cols-4 sm:gap-5">
            {brands.map((b) => (
              <li key={b.id}><Link to={`/shop?brand=${b.slug}`} className="fx-card fx-hover flex h-24 items-center justify-center px-4 text-center">
                {b.logo_url ? <img src={b.logo_url} alt={b.name} width={120} height={48} className="max-h-12 w-auto object-contain" loading="lazy" /> : <span className="text-[17px] font-semibold text-ink-soft">{b.name}</span>}</Link></li>
            ))}
          </ul>
        </section>
      )}
      <Why />
    </>
  );
}
