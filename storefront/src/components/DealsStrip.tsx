import { useMemo } from 'react';
import { Link } from 'react-router-dom';
import { ArrowLeft, ArrowRight } from 'lucide-react';
import { useI18n } from '../lib/i18n';
import { useDesign } from '../lib/brand';
import { money } from '../lib/format';
import { useDeals } from '../lib/useDeals';
import { dealBadgeLabel, stockInfo, type Deal } from '../lib/deals';
import { CardGrid, ProductCard } from './ProductCard';
import { SectionHead } from './ui';
import { DealWidget } from './DealWidget';

/**
 * One deal as the store shows it everywhere: the widget (name, countdown, stock, button) and a grid of its products.
 * Used by the deals page, a deal's own page and the home strip.
 */
export function DealSection({ deal, max = 8, heading = 'h2', cta = true }: { deal: Deal; max?: number; heading?: 'h2' | 'h3'; cta?: boolean }) {
  const { t, locale, dir, num } = useI18n();
  const Arrow = dir === 'rtl' ? ArrowLeft : ArrowRight;
  const badge = useMemo(() => dealBadgeLabel(deal.discount, t, (v, cur) => money(v, cur, locale), deal.products[0]?.price.final.currency ?? 'QAR'), [deal.discount, deal.products, t, locale]);
  // One product with a quantity: its stock bar belongs to the whole deal.
  const single = deal.products.length === 1 ? stockInfo(deal.products[0].deal) : null;
  const upcoming = deal.phase === 'scheduled';
  const cards = deal.products.slice(0, upcoming ? 4 : max);
  return (
    <section className="space-y-7" data-testid="deal-section" data-deal={deal.id}>
      <DealWidget variant="full" heading={heading} design={deal.design} name={deal.name} tagline={deal.tagline} phase={deal.phase} startsAt={deal.starts_at} endsAt={deal.ends_at} badge={badge} stock={single}
        cta={cta && !upcoming && deal.phase !== 'sold_out' ? { to: `/deals/${deal.id}` } : null} />
      {cards.length > 0 && <CardGrid cols={4}>{cards.map((p, i) => <ProductCard key={p.id} p={p} priority={i < 2} />)}</CardGrid>}
      {cta && deal.products_total > cards.length && (
        <div className="text-center"><Link to={`/deals/${deal.id}`} className="group inline-flex min-h-11 items-center gap-1.5 text-sm font-semibold text-brass-deep hover:underline">{t('deals.view_products', { n: num(deal.products_total) })}<Arrow size={16} className="transition-transform group-hover:translate-x-0.5 rtl:group-hover:-translate-x-0.5" /></Link></div>
      )}
    </section>
  );
}

/** The home page strip: deals the admin marked "show on home" that are live now, soonest ending first. Nothing at all when there is none. */
export function DealsStrip() {
  const { t } = useI18n();
  const { layout } = useDesign();
  const q = useDeals({ home: true, limitProducts: 4 });
  const live = (q.data?.live ?? []).filter((d) => d.phase === 'live').slice(0, 2);
  if (live.length === 0) return null;
  return (
    <section className={layout === 'fresh' ? 'wrap fx-sec' : 'wrap mt-24'} aria-labelledby="home-deals" data-testid="home-deals">
      <div id="home-deals"><SectionHead eyebrow={t('deals.home_eyebrow')} title={t('deals.home_title')} link={{ to: '/deals', label: t('deals.see_all') }} /></div>
      <div className="space-y-14">{live.map((d) => <DealSection key={d.id} deal={d} heading="h3" max={4} />)}</div>
    </section>
  );
}
