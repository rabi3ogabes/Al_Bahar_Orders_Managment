import type { Money, ProductCard, Quote } from './types';

/**
 * Deals (countdown offers): the pure part. Types of the contract (docs/api/DEALS.md), the countdown maths, the deal price of a
 * product, and every sentence about stock, limits and quote lines. No React and no runtime imports, so `npm test` runs it directly
 * (tests/deals.test.mjs). The fetch helpers and the `useDeals()` hook are in `useDeals.ts`; the clock that ticks is `useNow.ts`.
 *
 * Every field is read defensively: an older server sends no `deal` at all, a newer one may add fields, and the page must still render.
 */

/* ───────────── contract types ───────────── */
export type DealPhase = 'scheduled' | 'live' | 'sold_out' | 'ended' | 'paused';
export type DealStyle = 'classic' | 'bold' | 'minimal' | 'luxe' | 'ribbon';
export type DealShape = 'rounded' | 'sharp' | 'pill';
export type TimerLook = 'boxes' | 'digits' | 'text';
export type TimerUnit = 'd' | 'h' | 'm' | 's';
export type DealIcon = 'flame' | 'bolt' | 'timer' | 'tag' | 'gift' | 'star' | 'crown';
export type DealNote = 'limit_per_order' | 'limit_per_customer' | 'sold_out' | 'ended';

export interface DealColors { background: string; text: string; accent: string }
/** The widget design after `normalizeDesign`: every field present, every value one the widget knows. */
export interface DealDesign {
  style: DealStyle; palette: string; colors: DealColors
  /** The server sent all three colours as hex. When false the store's own brand tokens stand in (an older or broken payload). */
  colorsKnown: boolean
  shape: DealShape
  timer: { units: TimerUnit[]; look: TimerLook; urgency_minutes: number }
  stock_bar: boolean; percent_badge: boolean; icon: DealIcon
  headline_en: string | null; headline_ar: string | null; cta_en: string | null; cta_ar: string | null; image_url: string | null
}

/** The `deal` block on product cards, product detail and cart lines. */
export interface DealBlock {
  deal_id?: string | null; name?: string | null; phase?: DealPhase | string | null
  starts_at?: string | null; ends_at?: string | null
  deal_price?: Money | null; regular_price?: Money | null; percent?: number | null
  remaining?: number | null; remaining_percent?: number | null
  limit_per_order?: number | null; limit_per_customer?: number | null; customer_remaining?: number | null
  sold_out?: boolean | null; design?: Partial<DealDesign> | Record<string, unknown> | null
  /** Only on a server cart line (`items[].deal`): how many of THIS line's units the server prices at the deal price, and why not all of them. */
  units_at_deal_price?: number | null; units_at_regular_price?: number | null; note?: DealNote | string | null
}
/** What a variant row of the product detail may carry (`deal_price`, or a whole `deal` block). */
export interface VariantDeal { deal_price?: Money | string | number | null; deal?: Pick<DealBlock, 'deal_price'> | null; price?: { final?: Money | null } | null }

export interface Deal {
  id: string; name: string; tagline: string | null; phase: DealPhase; starts_at: string | null; ends_at: string | null; seconds_left: number | null
  discount: { type: 'percent' | 'amount_off' | 'fixed_price' | string; value: string | null }
  limits: { per_order: number | null; per_customer: number | null }
  products_total: number; products: ProductCard[]; design: DealDesign; show_on_home: boolean
  /** Detail page only: the paging of `products` (24 per page). */
  products_meta?: { current_page: number; last_page: number; total: number } | null
}
export interface DealsData { serverTime: string | null; live: Deal[]; upcoming: Deal[] }

export type Tr = (key: string, vars?: Record<string, string | number>) => string;
export type Fmt = (value: number, currency: string) => string;

/* ───────────── small readers ───────────── */
const isObj = (v: unknown): v is Record<string, unknown> => typeof v === 'object' && v !== null && !Array.isArray(v);
const isCount = (v: unknown): v is number => typeof v === 'number' && Number.isFinite(v) && v >= 0;
const num = (v: unknown): number | null => { const n = typeof v === 'number' ? v : typeof v === 'string' && v.trim() !== '' ? Number(v) : NaN; return Number.isFinite(n) ? n : null; };
const count = (v: unknown): number | null => { const n = num(v); return n === null ? null : Math.max(0, Math.floor(n)); };
const str = (v: unknown): string | null => (typeof v === 'string' && v.trim() !== '' ? v.trim() : null);
const bool = (v: unknown, dflt: boolean): boolean => (typeof v === 'boolean' ? v : dflt);
const oneOf = <T extends string>(v: unknown, all: readonly T[], dflt: T): T => (typeof v === 'string' && (all as readonly string[]).includes(v) ? (v as T) : dflt);
const cents = (m: Money | null | undefined): number => Math.round(parseFloat(m?.amount ?? '0') * 100) || 0;
const timeMs = (iso: unknown): number | null => { if (typeof iso !== 'string' && typeof iso !== 'number') return null; const t = typeof iso === 'number' ? iso : Date.parse(iso); return Number.isFinite(t) ? t : null; };

/** Money as the server sends it (`{amount, currency, formatted}`); a bare number or "840.00" is accepted too and formatted with `fmt`. */
export function moneyOf(v: unknown, fmt?: Fmt, currency = 'QAR'): Money | null {
  if (isObj(v) && v.amount !== undefined) {
    const n = num(v.amount);
    if (n === null) return null;
    const cur = str(v.currency) ?? currency;
    return { amount: n.toFixed(2), currency: cur, formatted: str(v.formatted) ?? (fmt ? fmt(n, cur) : `${cur} ${n.toFixed(2)}`) };
  }
  const n = num(v);
  if (n === null) return null;
  return { amount: n.toFixed(2), currency, formatted: fmt ? fmt(n, currency) : `${currency} ${n.toFixed(2)}` };
}

/** Number as the rest of the store prints it: Latin digits in both languages (see `num` in lib/i18n.tsx). */
export const fmtNum = (locale: string, n: number): string => new Intl.NumberFormat(locale === 'ar' ? 'ar-QA-u-nu-latn' : 'en-GB').format(n);

/* ───────────── server clock ───────────── */
let skewMs = 0;
/**
 * The server writes `server_time` to the whole second (ISO-8601 without a fraction, cut down), so its real clock was up to one second later than
 * the text says. The offset is moved forward by that second: the store's copy of the server clock then runs a little ahead of the real one, never
 * behind it, so a countdown reaches zero no later than the server ends the deal (a clock that was behind showed 00:00:01 for a moment after the end,
 * and an order placed then came back as `deal_changed`). A time that carries a fraction is exact and is left alone.
 */
export const WHOLE_SECOND_BIAS_MS = 1000;
const hasFraction = (iso: string) => /\d{2}:\d{2}:\d{2}[.,]\d/.test(iso);
/** Offset to add to the visitor's clock to get the server's, from a `server_time`; null when it is missing or not a time (nothing to learn from it). */
export function skewFrom(serverIso: string | null | undefined, nowMs = Date.now()): number | null {
  const s = timeMs(serverIso);
  if (s === null || typeof serverIso !== 'string') return null;
  return s - nowMs + (hasFraction(serverIso) ? 0 : WHOLE_SECOND_BIAS_MS);
}
/**
 * Remember the clock offset. Every countdown of the store uses it, so all of them agree with the server and with each other.
 * A response without a usable `server_time` keeps the offset learned before (it never resets a good clock to "the phone is right").
 */
export function setServerTime(serverIso: string | null | undefined, nowMs = Date.now()): number {
  const s = skewFrom(serverIso, nowMs);
  if (s !== null) skewMs = s;
  return skewMs;
}
/** Back to "the phone's clock is right" (tests, and nothing else: a page never forgets the offset). */
export function resetServerTime(): void { skewMs = 0; }
export const getSkew = (): number => skewMs;
export const serverNow = (nowMs = Date.now()): number => nowMs + skewMs;

/* ───────────── countdown ───────────── */
export const DEFAULT_UNITS: readonly TimerUnit[] = ['d', 'h', 'm', 's'];
const SECONDS: Record<TimerUnit, number> = { d: 86_400, h: 3_600, m: 60, s: 1 };

/** Any subset of d/h/m/s with at least two units, always in d, h, m, s order; anything else is the full set. */
export function normalizeUnits(raw: unknown): TimerUnit[] {
  if (!Array.isArray(raw)) return [...DEFAULT_UNITS];
  const picked = DEFAULT_UNITS.filter((u) => raw.includes(u));
  return picked.length >= 2 ? picked : [...DEFAULT_UNITS];
}

export interface CountdownPart { unit: TimerUnit; value: number; text: string }
export interface Countdown {
  /** The target was a real time. When false there is nothing to show. */
  valid: boolean
  remainingMs: number; totalSeconds: number
  /** Zero reached (never negative). The digits read 00 only when this is true. */
  done: boolean
  /** Below `urgency_minutes` (0 = off) and not yet over. */
  urgent: boolean
  /** The units shown: days are left out below 24 h when `d` is listed and at least two other units remain. */
  parts: CountdownPart[]
  hasDays: boolean
  /** "dd:hh:mm:ss" (or the units that are shown). */
  text: string
}
export interface CountdownOptions { skewMs?: number; units?: unknown; urgencyMinutes?: number | null }

const pad2 = (n: number) => String(n).padStart(2, '0');

/**
 * Time left until `target`, read against the server's clock (`skewMs`, see `setServerTime`). Seconds are rounded up, so 00:00:01 shows
 * until the very last moment and 00:00:00 shows exactly when the deal is over. A unit that is not listed hands its time to the next
 * smaller one that is (with units h, m, s two days left read 48:00:00).
 */
export function countdown(target: string | number | null | undefined, nowMs: number, opts: CountdownOptions = {}): Countdown {
  const units = normalizeUnits(opts.units);
  const end = timeMs(target);
  const skew = opts.skewMs ?? skewMs;
  if (end === null) return { valid: false, remainingMs: 0, totalSeconds: 0, done: true, urgent: false, parts: units.map((unit) => ({ unit, value: 0, text: '00' })), hasDays: units.includes('d'), text: '' };
  const remainingMs = Math.max(0, end - (nowMs + skew));
  const totalSeconds = Math.ceil(remainingMs / 1000);
  const shown = units.includes('d') && totalSeconds < SECONDS.d && units.length > 2 ? units.filter((u) => u !== 'd') : units;
  let rest = totalSeconds;
  const parts = shown.map((unit) => { const value = Math.floor(rest / SECONDS[unit]); rest -= value * SECONDS[unit]; return { unit, value, text: pad2(value) }; });
  const urgencyMinutes = typeof opts.urgencyMinutes === 'number' && opts.urgencyMinutes > 0 ? opts.urgencyMinutes : 0;
  return {
    valid: true, remainingMs, totalSeconds, done: remainingMs <= 0, urgent: urgencyMinutes > 0 && remainingMs > 0 && remainingMs < urgencyMinutes * 60_000,
    parts, hasDays: parts.some((p) => p.unit === 'd'), text: parts.map((p) => p.text).join(':'),
  };
}

/* ───────────── plural-safe sentences ───────────── */
/**
 * `key.one`, `key.two`, `key.few`, `key.many` or `key.other`, by the plural rule of the language (Arabic has six forms), falling back to
 * `key.other`. `{n}` is the number in the store's digits; `vars` adds more.
 */
export function plural(t: Tr, locale: string, key: string, n: number, vars: Record<string, string | number> = {}): string {
  const cat = new Intl.PluralRules(locale === 'ar' ? 'ar' : 'en').select(n);
  const all = { n: fmtNum(locale, n), ...vars };
  const exact = t(`${key}.${cat}`, all);
  return exact === `${key}.${cat}` ? t(`${key}.other`, all) : exact;
}

/** "Ends in 2 days 4 hours": only the two largest units, rounded down to the minute, so a screen reader hears a change once a minute. */
export function humanRemaining(t: Tr, locale: string, totalSeconds: number, mode: 'ends' | 'starts' = 'ends'): string {
  const total = Math.max(0, Math.floor(totalSeconds));
  const d = Math.floor(total / 86_400), h = Math.floor((total % 86_400) / 3_600), m = Math.floor((total % 3_600) / 60);
  let phrase: string;
  if (total < 60) phrase = t('deal.aria.lt_minute');
  else {
    const bits = d > 0 ? [plural(t, locale, 'deal.aria.day', d), ...(h > 0 ? [plural(t, locale, 'deal.aria.hour', h)] : [])]
      : h > 0 ? [plural(t, locale, 'deal.aria.hour', h), ...(m > 0 ? [plural(t, locale, 'deal.aria.minute', m)] : [])]
        : [plural(t, locale, 'deal.aria.minute', m)];
    phrase = bits.length === 2 ? t('deal.aria.pair', { a: bits[0], b: bits[1] }) : bits[0];
  }
  return t(mode === 'ends' ? 'deal.aria.ends' : 'deal.aria.starts', { t: phrase });
}

/* ───────────── design ───────────── */
const STYLES = ['classic', 'bold', 'minimal', 'luxe', 'ribbon'] as const;
const SHAPES = ['rounded', 'sharp', 'pill'] as const;
const LOOKS = ['boxes', 'digits', 'text'] as const;
const ICONS = ['flame', 'bolt', 'timer', 'tag', 'gift', 'star', 'crown'] as const;
const HEX = /^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i;
/** Stand-ins for a payload without colours: the store's own tokens (a brand-palette widget). The server always sends real ones. */
const FALLBACK: DealColors = { background: 'var(--primary)', text: 'var(--on-primary)', accent: 'var(--rose)' };

/** Fill every field of the design the server sent, and drop values the widget does not know. Colours are the server's, never invented. */
export function normalizeDesign(raw: unknown): DealDesign {
  const d = isObj(raw) ? raw : {};
  const c = isObj(d.colors) ? d.colors : {};
  const known = typeof c.background === 'string' && typeof c.text === 'string' && typeof c.accent === 'string' && HEX.test(c.background.trim()) && HEX.test(c.text.trim()) && HEX.test(c.accent.trim());
  const tm = isObj(d.timer) ? d.timer : {};
  const urgency = num(tm.urgency_minutes);
  return {
    style: oneOf(d.style, STYLES, 'classic'), palette: str(d.palette) ?? 'brand',
    colors: known ? { background: (c.background as string).trim(), text: (c.text as string).trim(), accent: (c.accent as string).trim() } : { ...FALLBACK },
    colorsKnown: known,
    shape: oneOf(d.shape, SHAPES, 'rounded'),
    timer: { units: normalizeUnits(tm.units), look: oneOf(tm.look, LOOKS, 'boxes'), urgency_minutes: urgency === null ? 60 : Math.max(0, Math.floor(urgency)) },
    stock_bar: bool(d.stock_bar, true), percent_badge: bool(d.percent_badge, true), icon: oneOf(d.icon, ICONS, 'flame'),
    headline_en: str(d.headline_en), headline_ar: str(d.headline_ar), cta_en: str(d.cta_en), cta_ar: str(d.cta_ar), image_url: str(d.image_url),
  };
}

/** The admin's headline or button words in the active language, or null (the caller then uses the store's own words, never the other language). */
export function designWord(design: DealDesign, which: 'headline' | 'cta', locale: string): string | null {
  return locale === 'ar' ? design[`${which}_ar` as const] : design[`${which}_en` as const];
}

/** Hex to [r, g, b], or null for anything else (a CSS variable). */
export function parseHex(c: string): [number, number, number] | null {
  const s = c.trim();
  if (!HEX.test(s)) return null;
  const h = s.length === 4 ? s.slice(1).split('').map((x) => x + x).join('') : s.slice(1);
  return [parseInt(h.slice(0, 2), 16), parseInt(h.slice(2, 4), 16), parseInt(h.slice(4, 6), 16)];
}
const lum = ([r, g, b]: [number, number, number]) => { const f = (v: number) => { const s = v / 255; return s <= 0.03928 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4; }; return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b); };
/** WCAG contrast ratio of two hex colours (1 to 21); null when either is not hex. */
export function contrast(a: string, b: string): number | null {
  const x = parseHex(a), y = parseHex(b);
  if (!x || !y) return null;
  const [hi, lo] = [lum(x), lum(y)].sort((p, q) => q - p);
  return (hi + 0.05) / (lo + 0.05);
}
/** Of the palette's own colours, the one that reads best on `on` (text on an accent button, an accent number on the background). */
export function readableOn(on: string, ...candidates: string[]): string {
  let best = candidates[0], top = -1;
  for (const c of candidates) { const r = contrast(c, on); if (r !== null && r > top) { top = r; best = c; } }
  return best;
}

/* ───────────── phase and price ───────────── */
const PHASES: readonly DealPhase[] = ['scheduled', 'live', 'sold_out', 'ended', 'paused'];

/** The phase now: the server's, moved on by the clock (a deal past its end is over, a scheduled one past its start is live) until the next refresh. */
export function effectivePhase(d: { phase?: string | null; starts_at?: string | null; ends_at?: string | null }, nowMs = Date.now(), skew = skewMs): DealPhase {
  const base = PHASES.includes(d.phase as DealPhase) ? (d.phase as DealPhase) : null;
  if (base === 'ended' || base === 'paused') return base;
  const t = nowMs + skew;
  const end = timeMs(d.ends_at), start = timeMs(d.starts_at);
  if (end !== null && t >= end) return 'ended';
  if (base === 'scheduled') return start !== null && t >= start ? 'live' : 'scheduled';
  if (base === null) return start !== null && t < start ? 'scheduled' : 'live';
  return base;
}
/** Never shown when ended or paused. */
export const dealVisible = (phase: DealPhase): boolean => phase === 'live' || phase === 'scheduled' || phase === 'sold_out';

export interface DealPrice { block: DealBlock; price: Money; regular: Money; percent: number }

/**
 * The price a customer pays for one unit of this product while its deal is live, with the regular price to strike through; null when
 * no deal price applies (no deal, scheduled, ended, sold out, or this customer used up their limit: the normal price stands).
 * A variant row may carry its own `deal_price` (null there means this variant has no deal); without a variant the block's "from" price is used.
 */
export function dealPriceFor(p: { deal?: DealBlock | null; price?: { final?: Money | null } | null }, variant?: VariantDeal | null, nowMs = Date.now(), skew = skewMs): DealPrice | null {
  const b = isObj(p.deal) ? (p.deal as DealBlock) : null;
  if (!b || effectivePhase(b, nowMs, skew) !== 'live' || b.sold_out === true) return null;
  if (isCount(b.remaining) && b.remaining <= 0) return null;
  if (isCount(b.customer_remaining) && b.customer_remaining <= 0) return null;
  const own = variant ? ('deal_price' in variant ? variant.deal_price : variant.deal && 'deal_price' in variant.deal ? variant.deal.deal_price : undefined) : undefined;
  const regular = moneyOf(variant?.price?.final ?? b.regular_price ?? p.price?.final);
  // A variant with no price of its own in the payload only shares the block's "from" price when it costs the same as the block's regular price.
  if (variant && own === undefined && regular && b.regular_price && cents(regular) !== cents(moneyOf(b.regular_price))) return null;
  const price = moneyOf(own !== undefined ? own : b.deal_price);
  if (!price || !regular || cents(price) <= 0 || cents(price) >= cents(regular)) return null;
  const serverPct = !variant ? num(b.percent) : null;
  return { block: b, price, regular, percent: serverPct !== null && serverPct > 0 ? Math.round(serverPct) : Math.max(1, Math.round((1 - cents(price) / cents(regular)) * 100)) };
}

/** How many of `qty` units get the deal price: no more than the per-order limit, what this customer may still buy, or what is left. */
export function unitsAtDealPrice(b: DealBlock | null | undefined, qty: number): number {
  if (!b) return 0;
  let n = Math.max(0, Math.floor(qty));
  for (const cap of [b.limit_per_order, b.customer_remaining, b.remaining]) if (isCount(cap)) n = Math.min(n, cap);
  // A guest has no `customer_remaining`, but the per-customer limit still caps what one order can get.
  if (!isCount(b.customer_remaining) && isCount(b.limit_per_customer)) n = Math.min(n, b.limit_per_customer);
  return n;
}

/** Total for `qty` units when only the first `unitsAtDealPrice` pay the deal price. */
export function dealTotal(dp: DealPrice, qty: number): number {
  const d = unitsAtDealPrice(dp.block, qty);
  return d * parseFloat(dp.price.amount) + Math.max(0, qty - d) * parseFloat(dp.regular.amount);
}

/** "-30%", "Save QAR 50" or "Special price": the badge of a whole deal, from its price rule. */
export function dealBadgeLabel(discount: Deal['discount'] | null | undefined, t: Tr, fmt: Fmt, currency = 'QAR'): string | null {
  const v = discount ? num(discount.value) : null;
  if (!discount || v === null || v <= 0) return discount?.type === 'fixed_price' ? t('deal.special_price') : null;
  if (discount.type === 'percent') return `−${Number(v.toFixed(2))}%`;
  if (discount.type === 'amount_off') return t('deal.save_amount', { v: fmt(v, currency) });
  return t('deal.special_price');
}

/* ───────────── stock and limits ───────────── */
export interface StockInfo { remaining: number; percent: number | null; low: boolean }
/** Units left of a deal that has a quantity; null when it is unlimited or sold out. */
export function stockInfo(b: DealBlock | null | undefined): StockInfo | null {
  if (!b || !isCount(b.remaining) || b.remaining <= 0 || b.sold_out === true) return null;
  const pct = num(b.remaining_percent);
  const percent = pct === null ? null : Math.min(100, Math.max(0, pct));
  return { remaining: Math.floor(b.remaining), percent, low: (percent !== null && percent <= 20) || b.remaining <= 5 };
}
/** "Only 12 left". */
export function remainingText(t: Tr, locale: string, b: DealBlock | null | undefined): string | null {
  const s = stockInfo(b);
  return s ? plural(t, locale, 'deal.left', s.remaining) : null;
}

export interface LimitInfo { lines: string[]; /** This customer used up their share: the normal price stands. */ reached: boolean }
/**
 * What the limits mean for this person: "Limit 2 per order", then either "Limit 3 per customer" (a guest, or no account figure) or what
 * is left of it ("You can still get 2 at this price", "You reached your limit for this deal").
 */
export function limitLines(t: Tr, locale: string, b: DealBlock | null | undefined): LimitInfo {
  const lines: string[] = [];
  let reached = false;
  if (!b) return { lines, reached };
  if (isCount(b.limit_per_order) && b.limit_per_order > 0) lines.push(plural(t, locale, 'deal.limit_order', b.limit_per_order));
  if (isCount(b.customer_remaining)) {
    if (b.customer_remaining <= 0) { reached = true; lines.push(t('deal.limit_reached')); } else lines.push(plural(t, locale, 'deal.can_get', b.customer_remaining));
  } else if (isCount(b.limit_per_customer) && b.limit_per_customer > 0) lines.push(plural(t, locale, 'deal.limit_customer', b.limit_per_customer));
  return { lines, reached };
}

/** "Deal price on 2 of 3 units" when the quantity chosen is more than the deal price covers; otherwise null. */
export function partialText(t: Tr, locale: string, dealUnits: number, qty: number): string | null {
  return qty > 1 && dealUnits > 0 && dealUnits < qty ? plural(t, locale, 'deal.partial', qty, { a: fmtNum(locale, dealUnits), b: fmtNum(locale, qty) }) : null;
}

/* ───────────── cart, quote and order ───────────── */
export interface QuoteLineDeal { deal_id: string | null; name: string | null; units_at_deal_price: number; units_at_regular_price: number; savings: Money | null; ends_at: string | null; note: DealNote | null }
const NOTES: readonly DealNote[] = ['limit_per_order', 'limit_per_customer', 'sold_out', 'ended'];

/** The `deal` of a quote line, or null when it has none (an older server, or a line with no deal). */
export function readQuoteDeal(raw: unknown): QuoteLineDeal | null {
  if (!isObj(raw)) return null;
  const at = count(raw.units_at_deal_price) ?? 0, reg = count(raw.units_at_regular_price) ?? 0;
  const note = NOTES.includes(raw.note as DealNote) ? (raw.note as DealNote) : null;
  if (at === 0 && !note) return null;
  return { deal_id: str(raw.deal_id), name: str(raw.name), units_at_deal_price: at, units_at_regular_price: reg, savings: moneyOf(raw.savings), ends_at: str(raw.ends_at), note };
}

export interface QuoteLineText {
  /** "Deal price on 2 of 3 units" / "Deal price applied" / "Deal price on all units"; null when no unit got the deal price. */
  summary: string | null
  /** "You saved QAR 80". */
  saved: string | null
  /** Why not every unit got it, in plain words; null when nothing to explain. */
  note: string | null
  /** The note is a limit or an ending: shown calmly in the warning tone. */
  warn: boolean
}

/**
 * The words under a quote line that has a deal. `limits` (from the product's deal block) lets the note name the number: without it the
 * note says "limit reached" without one.
 */
export function quoteLineText(t: Tr, locale: string, d: QuoteLineDeal | null, limits?: { per_order?: number | null; per_customer?: number | null }): QuoteLineText | null {
  if (!d) return null;
  const total = d.units_at_deal_price + d.units_at_regular_price;
  const summary = d.units_at_deal_price <= 0 ? null : d.units_at_regular_price > 0 ? plural(t, locale, 'deal.partial', total, { a: fmtNum(locale, d.units_at_deal_price), b: fmtNum(locale, total) }) : total === 1 ? t('deal.line_one') : t('deal.line_all');
  const saved = d.savings && cents(d.savings) > 0 ? t('deal.line_saved', { v: d.savings.formatted }) : null;
  const rest = d.units_at_regular_price > 0 ? t('deal.rest_regular') : null;
  const join = (...bits: (string | null)[]) => bits.filter(Boolean).join('. ');
  let note: string | null = null;
  if (d.note === 'limit_per_order') note = join(isCount(limits?.per_order) && limits!.per_order! > 0 ? plural(t, locale, 'deal.limit_order', limits!.per_order!) : t('deal.note.limit_per_order'), rest);
  else if (d.note === 'limit_per_customer') note = d.units_at_deal_price <= 0 ? join(t('deal.limit_reached'), t('deal.regular_applies')) : join(isCount(limits?.per_customer) && limits!.per_customer! > 0 ? plural(t, locale, 'deal.limit_customer', limits!.per_customer!) : t('deal.note.limit_per_customer'), rest);
  else if (d.note === 'sold_out') note = d.units_at_deal_price <= 0 ? join(t('deal.note.sold_out'), t('deal.regular_applies')) : join(t('deal.note.running_out'), rest);
  else if (d.note === 'ended') note = join(t('deal.note.ended'), t('deal.regular_applies'));
  return summary || note ? { summary, saved, note, warn: !!d.note } : null;
}

/** The words under a cart line before the server has priced it: the deal of the product, and how many of the units get its price. */
export function cartLineText(t: Tr, locale: string, line: { qty: number; deal?: DealBlock | null }, nowMs = Date.now(), skew = skewMs): { name: string | null; summary: string | null; limits: string[]; reached: boolean } | null {
  const b = line.deal;
  if (!b || !isObj(b)) return null;
  const phase = effectivePhase(b, nowMs, skew);
  if (phase !== 'live' || b.sold_out === true) return null;
  const lim = limitLines(t, locale, b);
  // The server cart says how many units of this line it prices at the deal price (the checkout quote is the final word); without that, the same rules worked out here.
  const d = isCount(b.units_at_deal_price) ? Math.min(Math.floor(b.units_at_deal_price), line.qty) : unitsAtDealPrice(b, line.qty);
  const summary = d <= 0 ? null : d < line.qty ? plural(t, locale, 'deal.partial', line.qty, { a: fmtNum(locale, d), b: fmtNum(locale, line.qty) }) : line.qty === 1 ? t('deal.line_one') : t('deal.line_all');
  const limits = b.note === 'sold_out' ? [...lim.lines, t(d > 0 ? 'deal.note.running_out' : 'deal.note.sold_out')] : lim.lines;
  return { name: str(b.name), summary, limits, reached: lim.reached };
}

/**
 * The "Deal savings" row. Whether the quote's `totals.discount` already holds the deal savings is read from the numbers
 * (subtotal + fees - discount - points = total): when it does, the deal part is taken out of the "Discount" row (`minusDeal`),
 * so nothing is shown twice. When neither reading fits (a fee this file does not know), the deal is assumed to be inside `discount`.
 */
export interface DealSplit { deal: Money | null; inDiscount: boolean }
export function dealSavingsSplit(q: Pick<Quote, 'totals'> | null | undefined, fmt: Fmt): DealSplit {
  if (!q) return { deal: null, inDiscount: false };
  const x = q.totals as Quote['totals'] & { deal_savings?: Money | null };
  const deal = moneyOf(x.deal_savings, fmt, x.subtotal?.currency);
  if (!deal || cents(deal) <= 0) return { deal: null, inDiscount: false };
  const base = cents(x.subtotal) + cents(x.delivery_fee) + cents(x.service_fee) + cents(x.tax) - cents(x.loyalty_discount);
  const discount = cents(x.discount), grand = cents(x.grand_total);
  const separate = Math.abs(base - discount - cents(deal) - grand) <= 1 && Math.abs(base - discount - grand) > 1;
  return { deal, inDiscount: !separate };
}
/** A discount amount without the deal part (null when nothing is left). */
export function minusDeal(m: Money | null | undefined, split: DealSplit, fmt: Fmt): Money | null {
  if (!m || cents(m) <= 0) return null;
  if (!split.deal || !split.inDiscount) return m;
  const left = cents(m) - cents(split.deal);
  return left > 0 ? { amount: (left / 100).toFixed(2), currency: m.currency, formatted: fmt(left / 100, m.currency) } : null;
}

/** An amount that is more than zero, as `Money`; null for "0.00", nothing, or anything that is not an amount (the cart's and the quote's "Deal savings" rows). */
export function positiveMoney(v: unknown, fmt?: Fmt): Money | null {
  const m = moneyOf(v, fmt);
  return m && cents(m) > 0 ? m : null;
}

/** `409 deal_changed` from place order: the deal moved under the customer's feet. Matches the shape of `ApiError` without importing it. */
export function isDealChanged(e: unknown): boolean {
  return isObj(e) && e.status === 409 && e.code === 'deal_changed';
}
/**
 * The fresh quote the server sends with `deal_changed`, when it is complete enough to show. The error envelope carries it as `context.quote`
 * (what the server does today); the contract names `data.quote`, so that is read too.
 */
export function quoteFromDealChanged(e: unknown): Quote | null {
  const body = isObj(e) && isObj(e.body) ? e.body : null;
  const where = (k: string) => { const o = body && isObj(body[k]) ? (body[k] as Record<string, unknown>) : null; return o && isObj(o.quote) ? o.quote : null; };
  const q = where('context') ?? where('data');
  if (!q || typeof q.quote_hash !== 'string' || !isObj(q.totals) || !isObj(q.totals.grand_total) || !Array.isArray(q.payment_methods) || !Array.isArray(q.fulfillments)) return null;
  return q as unknown as Quote;
}

export interface OrderLineDeal { name: string | null; units: number; saved: Money | null }
/** The deal snapshot of a placed order line: its name and what its units saved. `maxUnits` (the quantity still on the line) caps the units, as a reduced line keeps its snapshot. */
export function orderLineDeal(raw: unknown, fmt: Fmt, maxUnits?: number | null): OrderLineDeal | null {
  if (!isObj(raw)) return null;
  const cap = typeof maxUnits === 'number' && Number.isFinite(maxUnits) ? Math.max(0, Math.floor(maxUnits)) : null;
  const units = Math.min(count(raw.units) ?? 0, cap ?? Infinity);
  if (units <= 0) return null;
  const per = moneyOf(raw.unit_savings, fmt);
  const cur = per?.currency ?? 'QAR';
  const total = per ? parseFloat(per.amount) * units : 0;
  return { name: str(raw.name), units, saved: per && total > 0 ? { amount: total.toFixed(2), currency: cur, formatted: fmt(total, cur) } : null };
}
/** A line the branch took out of the order (removed, or swapped for another): it keeps its deal snapshot but nothing was sold. */
export function orderLineGone(item: { quantity?: number | null; adjustment?: { kind?: string | null } | null }): boolean {
  const kind = item.adjustment?.kind;
  return kind === 'removed' || kind === 'replaced' || (typeof item.quantity === 'number' && item.quantity <= 0);
}
/**
 * What the whole order saved with deals: the sum over the lines that were really sold (removed and replaced lines are left out, a reduced line counts
 * only the units it still has). An order whose lines carry no deal snapshot at all falls back to the order's own `totals.deal_savings`.
 */
export function orderDealSavings(items: { deal?: unknown; quantity?: number | null; adjustment?: { kind?: string | null } | null }[], fmt: Fmt, orderTotal?: unknown): Money | null {
  let sum = 0, cur = 'QAR', snapshots = 0;
  for (const i of items) {
    if (orderLineDeal(i.deal, fmt) === null) continue;
    snapshots += 1;
    if (orderLineGone(i)) continue;
    const l = orderLineDeal(i.deal, fmt, i.quantity);
    if (l?.saved) { sum += parseFloat(l.saved.amount); cur = l.saved.currency; }
  }
  if (sum > 0) return { amount: sum.toFixed(2), currency: cur, formatted: fmt(sum, cur) };
  return snapshots === 0 ? positiveMoney(orderTotal, fmt) : null;
}

/* ───────────── API payloads → the shapes above ───────────── */
const PHASE_OF = (v: unknown): DealPhase => (PHASES.includes(v as DealPhase) ? (v as DealPhase) : 'live');

/** `products` is a plain list on `GET /deals` and `{data, meta}` on `GET /deals/{id}`. */
function productsOf(v: unknown): { list: ProductCard[]; meta: Deal['products_meta'] } {
  const list = Array.isArray(v) ? v : isObj(v) && Array.isArray(v.data) ? v.data : [];
  const m = isObj(v) && isObj(v.meta) ? v.meta : null;
  return { list: list.filter((p): p is ProductCard => isObj(p) && typeof p.id === 'string' && typeof p.slug === 'string'), meta: m ? { current_page: count(m.current_page) ?? 1, last_page: count(m.last_page) ?? 1, total: count(m.total) ?? list.length } : null };
}

/** One deal from the API, with every field present. Null when it is not a deal (no id). */
export function readDeal(raw: unknown): Deal | null {
  if (!isObj(raw) || typeof raw.id !== 'string' || raw.id === '') return null;
  const dc = isObj(raw.discount) ? raw.discount : {};
  const lim = isObj(raw.limits) ? raw.limits : {};
  const { list, meta } = productsOf(raw.products);
  return {
    id: raw.id, name: str(raw.name) ?? '', tagline: str(raw.tagline), phase: PHASE_OF(raw.phase), starts_at: str(raw.starts_at), ends_at: str(raw.ends_at), seconds_left: num(raw.seconds_left),
    discount: { type: str(dc.type) ?? 'percent', value: dc.value === undefined || dc.value === null ? null : String(dc.value) },
    limits: { per_order: count(lim.per_order), per_customer: count(lim.per_customer) },
    products_total: count(raw.products_total) ?? meta?.total ?? list.length, products: list, design: normalizeDesign(raw.design), show_on_home: raw.show_on_home === true, products_meta: meta,
  };
}
const readDeals = (v: unknown): Deal[] => (Array.isArray(v) ? v.map(readDeal).filter((d): d is Deal => d !== null) : []);

/** The body of `GET /deals`. */
export function readDealsData(raw: unknown): DealsData {
  const d = isObj(raw) ? raw : {};
  return { serverTime: str(d.server_time), live: readDeals(d.live), upcoming: readDeals(d.upcoming) };
}

/** The soonest moment something changes in a list of deals (a deal ends or starts), as server milliseconds; null when nothing is ahead. */
export function nextChangeAt(data: DealsData | null | undefined, nowMs = Date.now(), skew = skewMs): number | null {
  if (!data) return null;
  const t = nowMs + skew;
  const ahead = [...data.live.map((d) => timeMs(d.ends_at)), ...data.upcoming.map((d) => timeMs(d.starts_at))].filter((x): x is number => x !== null && x > t);
  return ahead.length ? Math.min(...ahead) : null;
}
