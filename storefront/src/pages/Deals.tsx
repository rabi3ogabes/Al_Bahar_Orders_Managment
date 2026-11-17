import { useEffect } from 'react';
import { Link, useParams } from 'react-router-dom';
import { useInfiniteQuery } from '@tanstack/react-query';
import { Flame, Hourglass } from 'lucide-react';
import { ApiError } from '../lib/api';
import { useAuth } from '../lib/auth';
import { useI18n } from '../lib/i18n';
import { useDocTitle } from '../lib/hooks';
import { fetchDeal, useDeals } from '../lib/useDeals';
import { CardGrid, CardSkeletons, ProductCard } from '../components/ProductCard';
import { DealSection } from '../components/DealsStrip';
import { Breadcrumbs, Button, EmptyState, ErrorState, LinkButton, Skeleton } from '../components/ui';
import { DealWidget } from '../components/DealWidget';
import { dealBadgeLabel, serverNow, stockInfo } from '../lib/deals';
import { money } from '../lib/format';

/** The meta description of this page for crawlers that run scripts (the server writes its own into the page shell too). */
function useMetaDescription(text: string) {
  useEffect(() => {
    const el = document.querySelector('meta[name="description"]');
    const before = el?.getAttribute('content') ?? null;
    el?.setAttribute('content', text);
    return () => { if (el && before !== null) el.setAttribute('content', before); };
  }, [text]);
}

function Placeholder() {
  return (
    <div className="mt-10 space-y-8" aria-hidden="true">
      <Skeleton className="h-64 rounded-[24px]" />
      <CardSkeletons n={4} />
    </div>
  );
}

/* ───────────── /deals ───────────── */
export function DealsPage() {
  const { t } = useI18n();
  useDocTitle(t('deals.title'));
  useMetaDescription(t('deals.desc'));
  const q = useDeals({ limitProducts: 8 });
  const live = q.data?.live ?? [];
  const upcoming = q.data?.upcoming ?? [];
  const empty = !!q.data && live.length === 0 && upcoming.length === 0;
  return (
    <div className="wrap py-8 md:py-12">
      <Breadcrumbs items={[{ to: '/', label: t('nav.home') }, { label: t('deals.title') }]} />
      <p className="eyebrow mt-6">{t('deals.eyebrow')}</p>
      <h1 className="mt-2 text-[clamp(40px,6vw,76px)] leading-[0.98]">{t('deals.title')}</h1>
      <p className="mt-4 max-w-xl text-ink-soft">{t('deals.intro')}</p>

      {q.isLoading && <Placeholder />}
      {q.isError && !q.data && <ErrorState onRetry={() => void q.refetch()} text={t('deals.error_text')} />}
      {empty && <EmptyState icon={<Flame size={28} />} title={t('deals.none')} text={t('deals.none_text')} action={<LinkButton to="/shop?sale=1" variant="outline">{t('deals.shop_sale')}</LinkButton>} />}

      {live.length > 0 && (
        <section aria-labelledby="deals-live">
          <h2 id="deals-live" className="mb-6 mt-12 flex items-center gap-3 text-3xl"><span aria-hidden="true" className="live-dot h-2.5 w-2.5 rounded-full bg-success" />{t('deals.live_now')}</h2>
          <div className="space-y-16">{live.map((d) => <DealSection key={d.id} deal={d} heading="h3" />)}</div>
        </section>
      )}
      {upcoming.length > 0 && (
        <section aria-labelledby="deals-soon">
          <h2 id="deals-soon" className="mt-16 flex items-center gap-3 text-3xl"><Hourglass size={24} aria-hidden="true" className="text-brass" />{t('deals.upcoming')}</h2>
          <p className="mb-6 mt-1 text-muted">{t('deals.upcoming_text')}</p>
          <div className="space-y-14">{upcoming.map((d) => <DealSection key={d.id} deal={d} heading="h3" max={4} />)}</div>
        </section>
      )}
    </div>
  );
}

/* ───────────── /deals/:id ───────────── */
export function DealPage() {
  const { id = '' } = useParams();
  const { t, locale, num } = useI18n();
  const { user } = useAuth();
  const q = useInfiniteQuery({
    queryKey: ['deal', id, user?.id ?? null], initialPageParam: 1, staleTime: 30_000, refetchOnWindowFocus: true,
    queryFn: ({ pageParam, signal }) => fetchDeal(id, pageParam, signal),
    getNextPageParam: (last) => (last.products_meta && last.products_meta.current_page < last.products_meta.last_page ? last.products_meta.current_page + 1 : undefined),
    retry: (n, e) => !(e instanceof ApiError && e.status === 404) && n < 2,
  });
  const deal = q.data?.pages[0];
  useDocTitle(deal?.name ?? t('deals.title'));
  useMetaDescription(deal?.tagline ?? t('deals.desc'));
  // The deal's last moment passes: ask again a moment later, so the page says it is over (the server then answers "not found").
  const { refetch } = q;
  const endsAt = deal?.ends_at ? Date.parse(deal.ends_at) : NaN;
  useEffect(() => {
    if (!Number.isFinite(endsAt)) return;
    const wait = Math.min(2_147_483_647, Math.max(500, endsAt - serverNow() + 800));
    const timer = setTimeout(() => { if (document.visibilityState !== 'hidden') void refetch(); }, wait);
    return () => clearTimeout(timer);
  }, [endsAt, refetch]);

  const gone = q.isError && q.error instanceof ApiError && q.error.status === 404;
  if (gone) return <div className="wrap py-20"><EmptyState icon={<Flame size={28} />} title={t('deals.not_found')} text={t('deals.not_found_text')} action={<LinkButton to="/deals">{t('deals.see_all')}</LinkButton>} /></div>;
  if (q.isError && !deal) return <div className="wrap py-20"><ErrorState onRetry={() => void q.refetch()} text={t('deals.error_text')} /></div>;
  if (!deal) return <div className="wrap py-8 md:py-12"><Skeleton className="h-6 w-56" /><Placeholder /></div>;

  const products = q.data!.pages.flatMap((p) => p.products);
  const total = deal.products_meta?.total ?? deal.products_total;
  const badge = dealBadgeLabel(deal.discount, t, (v, cur) => money(v, cur, locale), products[0]?.price.final.currency ?? 'QAR');
  return (
    <div className="wrap py-8 md:py-12">
      <Breadcrumbs items={[{ to: '/', label: t('nav.home') }, { to: '/deals', label: t('deals.title') }, { label: deal.name }]} />
      <div className="mt-6">
        <DealWidget variant="full" heading="h1" design={deal.design} name={deal.name} tagline={deal.tagline} phase={deal.phase} startsAt={deal.starts_at} endsAt={deal.ends_at} badge={badge} stock={total === 1 ? stockInfo(products[0]?.deal) : null} />
      </div>
      <h2 className="mb-6 mt-12 text-3xl">{t('deals.products')}</h2>
      {products.length > 0 && (
        <>
          <CardGrid cols={4}>{products.map((p, i) => <ProductCard key={p.id} p={p} priority={i < 4} />)}</CardGrid>
          <div className="mt-12 flex flex-col items-center gap-3">
            <p className="text-sm text-muted">{t('shop.shown', { a: num(products.length), b: num(total) })}</p>
            {q.hasNextPage && <Button variant="outline" size="lg" loading={q.isFetchingNextPage} onClick={() => void q.fetchNextPage()}>{t('action.load_more')}</Button>}
          </div>
        </>
      )}
      <p className="mt-12 text-center text-sm"><Link to="/deals" className="font-medium text-brass-deep hover:underline">{t('deals.see_all')}</Link></p>
    </div>
  );
}
