import { Flame } from 'lucide-react';
import { useI18n } from '../lib/i18n';
import { useNow } from '../lib/useNow';
import { cx, moneyShort } from '../lib/format';
import { useRefreshOnDealEnd } from '../lib/useDeals';
import { cartLineText, dealPriceFor, effectivePhase, limitLines, partialText, stockInfo, unitsAtDealPrice, type DealBlock, type DealPrice, type QuoteLineText, type VariantDeal } from '../lib/deals';
import type { Price, ProductCard } from '../lib/types';
import { PriceTag } from './ui';
import { DealWidget } from './DealWidget';

/** The small pieces of the deals feature that sit inside other pages: prices, the product-page strip, the card badge and the cart wording. */

const minus = '−';
type Sized = 'sm' | 'md' | 'lg';

/** `PriceTag`, or the deal price with the regular price struck through while this product's deal is live. */
export function DealPriceTag({ p, variant, price, size = 'md' }: { p: { deal?: DealBlock | null }; variant?: VariantDeal | null; price: Price; size?: Sized }) {
  if (!p.deal) return <PriceTag price={price} size={size} />;
  return <LiveDealPrice p={p} variant={variant} price={price} size={size} />;
}
function LiveDealPrice({ p, variant, price, size }: { p: { deal?: DealBlock | null }; variant?: VariantDeal | null; price: Price; size: Sized }) {
  const { t, locale } = useI18n();
  const now = useNow();
  const dp = dealPriceFor({ deal: p.deal, price }, variant, now);
  if (!dp) return <PriceTag price={price} size={size} />;
  const big = size === 'lg' ? 'text-[28px]' : size === 'md' ? 'text-[17px]' : 'text-[15px]';
  return (
    <div className="flex flex-wrap items-baseline gap-x-2.5 gap-y-0.5" data-testid="deal-price">
      <span className={cx('font-semibold tabular-nums text-danger', big)}><span className="sr-only">{t('deal.deal_price')} </span>{moneyShort(dp.price, locale)}</span>
      <span className="text-[13px] text-muted line-through tabular-nums"><span className="sr-only">{t('deal.regular_price')} </span>{moneyShort(dp.regular, locale)}</span>
      <span className="rounded-full bg-danger/10 px-2 py-0.5 text-[11px] font-semibold text-danger"><bdi dir="ltr">{minus}{dp.percent}%</bdi></span>
    </div>
  );
}

/** A product card's deal: the percent badge and a small clock (a "Sold out" tag when the deal's units are gone; nothing when it is over or not started). */
export function DealCardMeta({ p, className }: { p: ProductCard; className?: string }) {
  if (!p.deal) return null;
  return <LiveCardMeta p={p} className={className} />;
}
function LiveCardMeta({ p, className }: { p: ProductCard; className?: string }) {
  const now = useNow();
  const b = p.deal as DealBlock;
  const phase = effectivePhase(b, now);
  const dp = dealPriceFor(p, null, now);
  const sold = phase === 'sold_out' || (phase === 'live' && b.sold_out === true);
  if (!sold && !(phase === 'live' && dp)) return null;
  return <DealWidget variant="compact" design={b.design} name={b.name ?? ''} phase={sold ? 'sold_out' : 'live'} startsAt={b.starts_at} endsAt={b.ends_at} badge={dp && !sold ? `${minus}${dp.percent}%` : null} className={className} />;
}

/** The deal strip of the product page: it replaces the price while the deal is live (price, countdown, stock, limits) and teases it before it starts. */
export function ProductPrice({ p, variant, price, qty, size = 'lg' }: { p: { deal?: DealBlock | null; has_variants?: boolean }; variant?: VariantDeal | null; price: Price; qty: number; size?: Sized }) {
  if (!p.deal) return <PriceTag price={price} size={size} />;
  return <LiveProductPrice p={p} variant={variant} price={price} qty={qty} size={size} />;
}
function LiveProductPrice({ p, variant, price, qty, size }: { p: { deal?: DealBlock | null; has_variants?: boolean }; variant?: VariantDeal | null; price: Price; qty: number; size: Sized }) {
  const { t, locale } = useI18n();
  const now = useNow();
  const refresh = useRefreshOnDealEnd();
  const b = p.deal as DealBlock;
  const phase = effectivePhase(b, now);
  if (phase === 'ended' || phase === 'paused') return <PriceTag price={price} size={size} />;
  const dp = phase === 'live' ? dealPriceFor({ deal: b, price }, variant, now) : null;
  const sold = phase === 'sold_out' || b.sold_out === true;
  const lim = limitLines(t, locale, b);
  const partial = dp ? partialText(t, locale, unitsAtDealPrice(b, qty), qty) : null;
  const notes = [...(sold ? [] : lim.lines), ...(partial ? [partial] : [])];
  return (
    <div className="space-y-4" data-testid="product-deal">
      {!dp && <PriceTag price={price} size={size} />}
      <DealWidget variant="strip" heading="h2" design={b.design} name={b.name ?? t('deal.label')} phase={sold ? 'sold_out' : b.phase} startsAt={b.starts_at} endsAt={b.ends_at} badge={dp ? `${minus}${dp.percent}%` : null} stock={sold ? null : stockInfo(b)} onEnd={refresh}
        foot={notes.length ? <>{notes.map((n, i) => <p key={i} className={cx('dw__note', lim.reached && i === 0 && 'dw__note--warn')} role={lim.reached && i === 0 ? 'status' : undefined}>{n}</p>)}</> : undefined}>
        {dp && <StripPrice dp={dp} from={!!p.has_variants && !variant} />}
      </DealWidget>
    </div>
  );
}
function StripPrice({ dp, from }: { dp: DealPrice; from: boolean }) {
  const { t, locale } = useI18n();
  return (
    <p className="dw__price" data-testid="deal-price">
      <span className="dw__price-now" lang={locale}>{from && <span className="dw__price-from">{t('deal.from')}</span>}<span className="sr-only">{t('deal.deal_price')} </span>{moneyShort(dp.price, locale)}</span>
      <s className="dw__price-was" lang={locale}><span className="sr-only">{t('deal.regular_price')} </span>{moneyShort(dp.regular, locale)}</s>
    </p>
  );
}

/** The words under a cart or checkout line that is in a deal: its name, how many units get the deal price, what was saved, and why not all of them. */
export function DealLineNote({ name, text, className }: { name: string | null; text: Pick<QuoteLineText, 'summary' | 'saved' | 'note' | 'warn'> | { summary: string | null; saved?: null; note?: string | null; warn?: boolean }; className?: string }) {
  const { t } = useI18n();
  return (
    <div className={cx('flex items-start gap-2 rounded-xl bg-brass-tint/70 px-2.5 py-1.5 text-[12px] leading-snug text-ink-soft', className)} data-testid="deal-line">
      <Flame size={14} aria-hidden="true" className="mt-px shrink-0 text-brass-deep" />
      <div className="min-w-0">
        <p className="font-semibold text-brass-deep">{name || t('deal.label')}</p>
        {text.summary && <p>{text.summary}{text.saved ? <> · <span className="font-semibold text-success">{text.saved}</span></> : null}</p>}
        {!text.summary && text.saved && <p className="font-semibold text-success">{text.saved}</p>}
        {text.note && <p className={text.warn ? 'font-medium text-warning' : undefined}>{text.note}</p>}
      </div>
    </div>
  );
}

/** The small dot beside "Deals" in the header and menu while a deal is live. */
export function LiveDot({ className }: { className?: string }) {
  const { t } = useI18n();
  return <span className={cx('inline-flex', className)}><span aria-hidden="true" className="live-dot inline-block h-2 w-2 rounded-full bg-success" /><span className="sr-only">{t('deals.live_dot')}</span></span>;
}

/** A cart line that is in a live deal, before the server has priced it: the deal, how many of the units get its price, and its limits. */
export function CartDealNote({ deal, qty, className }: { deal?: DealBlock | null; qty: number; className?: string }) {
  const { t, locale } = useI18n();
  const x = deal ? cartLineText(t, locale, { qty, deal }) : null;
  if (!x) return null;
  return <DealLineNote name={x.name} className={className} text={{ summary: x.summary, note: x.limits.join(' \u00b7 ') || null, warn: x.reached }} />;
}

/** "Deal prices are applied at checkout": once under the cart, when any line is in a live deal (the cart shows regular prices; the server prices the deal). */
export function CartDealsHint({ lines, className }: { lines: { qty: number; deal?: DealBlock | null }[]; className?: string }) {
  const { t, locale } = useI18n();
  if (!lines.some((l) => l.deal && cartLineText(t, locale, l)?.summary)) return null;
  return <p className={cx('flex items-start gap-2 text-[13px] text-ink-soft', className)} data-testid="deal-checkout-hint"><Flame size={15} aria-hidden="true" className="mt-0.5 shrink-0 text-brass-deep" />{t('deal.at_checkout')}</p>;
}
