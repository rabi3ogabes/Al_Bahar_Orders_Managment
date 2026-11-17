import { Fragment, useEffect, useId, useMemo, useRef, type CSSProperties, type ReactNode } from 'react';
import { Link } from 'react-router-dom';
import { ArrowLeft, ArrowRight, Crown, Flame, Gift, Star, Tag, Timer, Zap, type LucideIcon } from 'lucide-react';
import { useI18n } from '../lib/i18n';
import { useNow } from '../lib/useNow';
import { cx } from '../lib/format';
import { contrast, countdown, designWord, effectivePhase, humanRemaining, normalizeDesign, plural, readableOn, type DealDesign, type DealIcon, type StockInfo } from '../lib/deals';
import './dealWidget.css';

/**
 * THE deal widget: one component for the five looks the admin can pick (classic, bold, minimal, luxe, ribbon), their shapes and
 * timer looks. It paints only the colours the server resolved for the deal (`design.colors`), never its own.
 *
 *   full     the deals page, the home strip and a deal's own page: name, tagline, countdown, stock, button.
 *   strip    the product page (under the title): the same, packed into the buy box; the caller passes the price and the limit wording.
 *   compact  product cards: a percent badge and a small timer.
 *
 * Accessibility: the moving digits are hidden from screen readers; one visually hidden `timer` says "Ends in 2 days 4 hours" and
 * changes once a minute. Right-to-left comes from logical CSS properties (the digits stay in d:h:m:s order, as on a clock).
 * Motion: the urgency pulse only runs when the visitor has not asked for reduced motion (see dealWidget.css).
 */
const ICONS: Record<DealIcon, LucideIcon> = { flame: Flame, bolt: Zap, timer: Timer, tag: Tag, gift: Gift, star: Star, crown: Crown };

export interface DealWidgetProps {
  variant?: 'full' | 'strip' | 'compact'
  /** The deal's `design` as the server sent it (resolved colours included). */
  design: unknown
  name: string
  tagline?: string | null
  /** The server's phase (`live`, `scheduled`, `sold_out`, ...); the clock moves it on until the next refresh. */
  phase?: string | null
  startsAt?: string | null
  endsAt?: string | null
  /** The percent badge, already worded ("−30%"); null or empty hides it. */
  badge?: string | null
  /** Units left (a bar and "Only 12 left"); null when the deal has no quantity. */
  stock?: StockInfo | null
  cta?: { to: string; label?: string } | null
  heading?: 'h1' | 'h2' | 'h3'
  /** Strip and full: under the title (the price on the product page). */
  children?: ReactNode
  /** Strip and full: under the stock bar (limit wording on the product page). */
  foot?: ReactNode
  /** Called once when the deal's time runs out while the widget is on screen. */
  onEnd?: () => void
  className?: string
}

const UNIT_KEY = { d: 'deal.unit.d', h: 'deal.unit.h', m: 'deal.unit.m', s: 'deal.unit.s' } as const;
const SHORT_KEY = { d: 'deal.short.d', h: 'deal.short.h', m: 'deal.short.m', s: 'deal.short.s' } as const;

/** The CSS variables of the widget: the server's three colours, and the two derived choices between them (never a colour of our own). */
function colourVars(d: DealDesign): CSSProperties {
  const { background: bg, text, accent } = d.colors;
  // Text on an accent fill is whichever of the palette's other colours reads better; an accent number on the background falls back to the text colour when it would be faint.
  const onAccent = d.colorsKnown ? readableOn(accent, bg, text) : bg;
  const accentFg = !d.colorsKnown || (contrast(accent, bg) ?? 4) >= 3 ? accent : text;
  return { '--dw-bg': bg, '--dw-text': text, '--dw-accent': accent, '--dw-on-accent': onAccent, '--dw-accent-fg': accentFg } as CSSProperties;
}

export function DealWidget({ variant = 'full', design: rawDesign, name, tagline, phase: serverPhase, startsAt, endsAt, badge, stock, cta, heading = 'h2', children, foot, onEnd, className }: DealWidgetProps) {
  const { t, locale, dir } = useI18n();
  const now = useNow();
  const design = useMemo(() => normalizeDesign(rawDesign), [rawDesign]);
  const titleId = useId();
  const phase = effectivePhase({ phase: serverPhase, starts_at: startsAt, ends_at: endsAt }, now);
  const upcoming = phase === 'scheduled';
  const cd = countdown(upcoming ? startsAt : endsAt, now, { units: design.timer.units, urgencyMinutes: upcoming ? 0 : design.timer.urgency_minutes });
  const soldOut = phase === 'sold_out';
  const over = phase === 'ended' || phase === 'paused' || (!upcoming && !soldOut && cd.valid && cd.done);
  const timing = !soldOut && !over && cd.valid;
  const urgent = timing && cd.urgent;

  const was = useRef(phase);
  useEffect(() => { if (phase === 'ended' && was.current !== 'ended') onEnd?.(); was.current = phase; }, [phase, onEnd]);

  const Icon = ICONS[design.icon];
  const Arrow = dir === 'rtl' ? ArrowLeft : ArrowRight;
  const style = colourVars(design);
  const label = upcoming ? t('deal.starts_in') : designWord(design, 'headline', locale) ?? t('deal.ends_in');
  const spoken = timing ? humanRemaining(t, locale, Math.floor(cd.totalSeconds / 60) * 60, upcoming ? 'starts' : 'ends') : null;
  const badgeText = design.percent_badge && badge ? badge : null;
  const bits = `dw dw--${design.style} dw--s-${design.shape}`;

  if (variant === 'compact') {
    if (over) return null;
    const short = (u: 'd' | 'h' | 'm' | 's') => t(SHORT_KEY[u]);
    // "2d · 04:12" while there are days, "04:12:09" under a day.
    const shown = cd.hasDays ? `${cd.parts[0].text.replace(/^0/, '')}${short('d')} · ${cd.parts.slice(1, 3).map((p) => p.text).join(':')}` : cd.parts.slice(0, 3).map((p) => p.text).join(':');
    return (
      <span className={cx(bits, 'dw--compact', urgent && 'dw--urgent', className)} style={style} data-testid="deal-compact">
        <span className="dw-c__badge" aria-hidden={timing ? true : undefined}>{badgeText ? <bdi dir="ltr">{badgeText}</bdi> : <><Icon size={12} aria-hidden="true" />{soldOut || !timing ? <span>{soldOut ? t('deal.sold_out') : t('deal.label')}</span> : null}</>}</span>
        {timing && <span className="dw-c__time" aria-hidden="true"><span dir="ltr">{shown}</span></span>}
        {spoken && <span role="timer" className="sr-only">{spoken}</span>}
      </span>
    );
  }

  const H = heading;
  return (
    <section className={cx(bits, variant === 'strip' ? 'dw--strip' : 'dw--full', urgent && 'dw--urgent', design.image_url && 'dw--img', (over || soldOut) && 'dw--off', className)} style={style} aria-labelledby={titleId} data-testid="deal-widget" data-style={design.style} data-look={design.timer.look}>
      {design.image_url && <><img className="dw__img" src={design.image_url} alt="" loading="lazy" decoding="async" /><span className="dw__veil" aria-hidden="true" /></>}
      <span className="dw__deco" aria-hidden="true" />
      <div className="dw__body">
        <div className="dw__main">
          <div className="dw__head">
            <p className="dw__kicker"><Icon size={15} aria-hidden="true" /><span>{t('deal.kicker')}</span></p>
            {badgeText && <span className="dw__badge"><bdi dir="ltr">{badgeText}</bdi></span>}
          </div>
          {design.style === 'luxe' && <span className="dw__orn" aria-hidden="true"><i /><b /><i /></span>}
          <H id={titleId} className="dw__title">{name}</H>
          {tagline && <p className="dw__tag">{tagline}</p>}
          {children && <div className="dw__slot">{children}</div>}
        </div>
        <div className="dw__side">
          {timing && (
            <div className="dw__timer">
              <p className="dw__tlabel" aria-hidden="true">{label}</p>
              <div className={cx('dw-t', `dw-t--${design.timer.look}`)} dir="ltr" aria-hidden="true">
                {cd.parts.map((p, i) => (
                  <Fragment key={p.unit}>
                    {i > 0 && design.timer.look === 'digits' && <span className="dw-t__sep">:</span>}
                    <span className="dw-t__cell" data-unit={p.unit}>
                      <span className="dw-t__num">{p.text}</span>
                      {design.timer.look === 'text' ? <span className="dw-t__lab">{t(SHORT_KEY[p.unit])}</span> : <span className="dw-t__lab">{t(UNIT_KEY[p.unit])}</span>}
                    </span>
                  </Fragment>
                ))}
              </div>
              <span role="timer" className="sr-only">{spoken}</span>
            </div>
          )}
          {!timing && <p className="dw__status" role="status">{soldOut ? t('deal.sold_out') : t('deal.ended')}</p>}
          {design.stock_bar && stock && !over && !soldOut && (
            <div className="dw__stock">
              <p className={cx('dw__stock-txt', stock.low && 'dw__stock-txt--low')}>{plural(t, locale, 'deal.left', stock.remaining)}</p>
              {stock.percent !== null && <div className="dw__bar" aria-hidden="true"><i style={{ width: `${Math.max(3, stock.percent)}%` }} /></div>}
            </div>
          )}
          {foot && <div className="dw__foot">{foot}</div>}
          {cta && !over && !soldOut && <Link to={cta.to} className="dw__cta"><span>{cta.label ?? designWord(design, 'cta', locale) ?? t('deal.shop')}</span><Arrow size={17} aria-hidden="true" /></Link>}
        </div>
      </div>
    </section>
  );
}
