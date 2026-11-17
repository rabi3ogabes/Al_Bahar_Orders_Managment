import test from 'node:test';
import assert from 'node:assert/strict';
import {
  cartLineText, contrast, countdown, dealBadgeLabel, dealPriceFor, dealSavingsSplit, dealTotal, dealVisible, designWord, effectivePhase, fmtNum, humanRemaining, isDealChanged, limitLines, minusDeal,
  nextChangeAt, normalizeDesign, normalizeUnits, orderDealSavings, orderLineDeal, orderLineGone, partialText, plural, positiveMoney, quoteFromDealChanged, quoteLineText, readDeal, readDealsData, readQuoteDeal,
  readableOn, remainingText, resetServerTime, setServerTime, skewFrom, stockInfo, unitsAtDealPrice,
} from '../src/lib/deals.ts';
import { en } from '../src/locales/en.ts';
import { ar } from '../src/locales/ar.ts';

// Deals (docs/api/DEALS.md): the countdown maths, the deal price, the limit and quote words in both languages, the 409 helper.
// Fixtures follow the contract's examples; the server may send less, never different.

const dicts = { en, ar };
const tr = (locale) => (key, vars) => {
  let s = dicts[locale][key] ?? dicts.en[key] ?? key;
  if (vars) for (const [k, v] of Object.entries(vars)) s = s.replaceAll(`{${k}}`, String(v));
  return s;
};
const T = tr('en'), A = tr('ar');
const M = (v, cur = 'QAR') => ({ amount: Number(v).toFixed(2), currency: cur, formatted: `${cur} ${Number(v).toFixed(2)}` });
const fmt = (v, cur) => `${cur} ${v.toFixed(2)}`;

const NOW = Date.parse('2026-11-17T09:12:00+03:00');
const DAY = 86_400_000, HOUR = 3_600_000, MIN = 60_000, SEC = 1000;
const at = (ms) => new Date(NOW + ms).toISOString();

/** The `deal` block of the contract (section 5), live, 30 % off, 12 left of 50, limits 2 per order and 3 per customer. */
const block = (o = {}) => ({
  deal_id: 'd1', name: 'Weekend Furniture Drop', phase: 'live', starts_at: at(-DAY), ends_at: at(2 * DAY + 4 * HOUR + 12 * MIN + 9 * SEC),
  deal_price: M(840), regular_price: M(1200), percent: 30, remaining: 12, remaining_percent: 24, limit_per_order: 2, limit_per_customer: 3, customer_remaining: 3, sold_out: false, design: {}, ...o,
});
const card = (o = {}) => ({ id: 'p1', slug: 'oak-table', price: { regular: M(1200), sale: null, final: M(1200), discount_percent: 0, sale_ends_at: null, source: 'base' }, deal: block(), ...o });

/* ───────────── countdown ───────────── */
test('the countdown reads dd:hh:mm:ss, every unit zero-padded', () => {
  const c = countdown(at(2 * DAY + 4 * HOUR + 12 * MIN + 9 * SEC), NOW, { skewMs: 0 });
  assert.equal(c.text, '02:04:12:09');
  assert.deepEqual(c.parts.map((p) => p.unit), ['d', 'h', 'm', 's']);
  assert.equal(c.hasDays, true);
  assert.equal(c.done, false);
  assert.equal(countdown(at(5 * DAY + 1 * SEC), NOW, { skewMs: 0 }).text, '05:00:00:01');
  assert.equal(countdown(at(123 * DAY), NOW, { skewMs: 0 }).parts[0].text, '123');
});

test('days are hidden under 24 hours, shown from 24 hours (seconds round up, so the boundary is clean)', () => {
  const under = countdown(at(23 * HOUR + 59 * MIN + 59 * SEC), NOW, { skewMs: 0 });
  assert.equal(under.text, '23:59:59');
  assert.equal(under.hasDays, false);
  assert.equal(under.parts.length, 3);
  const exactly = countdown(at(DAY), NOW, { skewMs: 0 });
  assert.equal(exactly.text, '01:00:00:00');
  assert.equal(exactly.hasDays, true);
  // One millisecond under a day still counts as the last whole second of day one: no flicker between the two forms.
  assert.equal(countdown(at(DAY - 1), NOW, { skewMs: 0 }).text, '01:00:00:00');
  assert.equal(countdown(at(DAY - SEC), NOW, { skewMs: 0 }).text, '23:59:59');
});

test('the countdown never goes negative and reaches zero cleanly', () => {
  const past = countdown(at(-5 * MIN), NOW, { skewMs: 0 });
  assert.equal(past.remainingMs, 0);
  assert.equal(past.totalSeconds, 0);
  assert.equal(past.done, true);
  assert.equal(past.text, '00:00:00');
  assert.ok(past.parts.every((p) => p.value === 0));
  // 3, 2, 1, 0: each second takes exactly one off, and 00:00:00 shows only when it is really over.
  const seq = [3000, 2000, 1000, 500, 1, 0, -1].map((left) => countdown(at(left), NOW, { skewMs: 0 }));
  assert.deepEqual(seq.map((c) => c.text), ['00:00:03', '00:00:02', '00:00:01', '00:00:01', '00:00:01', '00:00:00', '00:00:00']);
  assert.deepEqual(seq.map((c) => c.done), [false, false, false, false, false, true, true]);
});

test('the countdown follows the server clock, not the phone clock', () => {
  const end = at(HOUR);
  // The phone is 10 minutes behind the server: the server says it is 10 minutes later than the phone thinks.
  const skew = skewFrom(at(10 * MIN), NOW);
  assert.equal(skew, 10 * MIN);
  assert.equal(countdown(end, NOW, { skewMs: skew }).text, '00:50:00');
  // A phone 2 hours ahead of the server: more time, never a negative number.
  assert.equal(countdown(end, NOW, { skewMs: -2 * HOUR }).text, '03:00:00');
  assert.equal(countdown(end, NOW, { skewMs: 3 * HOUR }).done, true);
  // A missing or odd server_time teaches nothing.
  for (const bad of [undefined, null, '', 'not a time']) assert.equal(skewFrom(bad, NOW), null);
  // The clock the whole store shares.
  setServerTime(at(10 * MIN), NOW);
  assert.equal(countdown(end, NOW).text, '00:50:00');
  resetServerTime();
  assert.equal(countdown(end, NOW).text, '01:00:00');
});

test('a response without a usable server_time keeps the clock already learned', () => {
  resetServerTime();
  assert.equal(setServerTime(at(10 * MIN), NOW), 10 * MIN);
  // One response with no server_time (an older server, a cached copy, a field that is missing): the good offset stays.
  for (const bad of [undefined, null, '', 'garbage', Number.NaN]) {
    assert.equal(setServerTime(bad, NOW + 5 * SEC), 10 * MIN);
    assert.equal(countdown(at(HOUR), NOW).text, '00:50:00');
  }
  // A later good one replaces it.
  assert.equal(setServerTime(at(-3 * MIN), NOW), -3 * MIN);
  resetServerTime();
});

test('server_time is cut to the whole second: the store clock never runs behind the real one, so nothing shows time left after the end', () => {
  resetServerTime();
  const whole = (ms) => new Date(Math.floor(ms / 1000) * 1000).toISOString().replace('.000Z', 'Z'); // what the server writes: no fraction
  assert.doesNotMatch(whole(NOW), /\./);
  const end = NOW + 5 * SEC;
  // Whatever fraction of the second the server's real clock was at when it wrote the time, the store's copy of the clock is at or ahead of the real one.
  for (let frac = 0; frac < 1000; frac += 37) {
    const real = NOW + frac; // the server's clock the moment the answer left (the phone is in step with it, so the offset is 0 apart from the cut-off)
    const skew = skewFrom(whole(real), real);
    assert.ok(skew >= 0, `skew ${skew} for a fraction of ${frac} ms`);
    // The deal ends 5 s later by the real clock. At that instant the store must already read zero, not 00:00:01.
    const at5 = countdown(new Date(end + frac).toISOString(), real + 5 * SEC, { skewMs: skew });
    assert.equal(at5.done, true, `fraction ${frac} ms: ${at5.text}`);
    assert.equal(at5.text, '00:00:00');
    // And it never reads zero more than a second early.
    assert.equal(countdown(new Date(end + frac).toISOString(), real + 3 * SEC, { skewMs: skew }).done, false);
  }
  // A time that carries a fraction is exact: no correction.
  assert.equal(skewFrom('2026-11-17T09:12:00.250+03:00', Date.parse('2026-11-17T09:12:00.250+03:00')), 0);
  assert.equal(skewFrom('2026-11-17T09:12:00.250+03:00', Date.parse('2026-11-17T09:12:00.250+03:00') - 40), 40);
  assert.equal(skewFrom('2026-11-17T09:12:00+03:00', Date.parse('2026-11-17T09:12:00+03:00')), 1000);
});

test('urgency starts below urgency_minutes, and not at zero or when it is off', () => {
  const opts = { skewMs: 0, urgencyMinutes: 60 };
  assert.equal(countdown(at(2 * HOUR), NOW, opts).urgent, false);
  assert.equal(countdown(at(HOUR), NOW, opts).urgent, false);
  assert.equal(countdown(at(HOUR - 1), NOW, opts).urgent, true);
  assert.equal(countdown(at(30 * SEC), NOW, opts).urgent, true);
  assert.equal(countdown(at(0), NOW, opts).urgent, false);
  assert.equal(countdown(at(-SEC), NOW, opts).urgent, false);
  assert.equal(countdown(at(30 * SEC), NOW, { skewMs: 0, urgencyMinutes: 0 }).urgent, false);
  assert.equal(countdown(at(30 * SEC), NOW, { skewMs: 0 }).urgent, false);
});

test('the unit subset: any two or more of d, h, m, s, always in order; the time of an unlisted unit moves into the next smaller one', () => {
  assert.deepEqual(normalizeUnits(['s', 'h', 'm']), ['h', 'm', 's']);
  assert.deepEqual(normalizeUnits(['h']), ['d', 'h', 'm', 's']);
  assert.deepEqual(normalizeUnits(undefined), ['d', 'h', 'm', 's']);
  assert.deepEqual(normalizeUnits(['x', 'y']), ['d', 'h', 'm', 's']);
  const end = at(2 * DAY + 4 * HOUR + 12 * MIN + 9 * SEC);
  assert.equal(countdown(end, NOW, { skewMs: 0, units: ['h', 'm', 's'] }).text, '52:12:09');
  assert.equal(countdown(end, NOW, { skewMs: 0, units: ['m', 's'] }).text, '3132:09');
  assert.equal(countdown(end, NOW, { skewMs: 0, units: ['d', 'h'] }).text, '02:04');
  // With days and only one other unit, days are kept even under 24 hours (a single unit would say too little).
  assert.equal(countdown(at(HOUR), NOW, { skewMs: 0, units: ['d', 'h'] }).text, '00:01');
  assert.equal(countdown(at(HOUR), NOW, { skewMs: 0, units: ['d', 'h', 'm'] }).text, '01:00');
});

test('an unreadable target gives nothing to show', () => {
  for (const bad of [null, undefined, 'soon', '']) assert.equal(countdown(bad, NOW, { skewMs: 0 }).valid, false);
  assert.equal(countdown(at(HOUR), NOW, { skewMs: 0 }).valid, true);
});

/* ───────────── phase ───────────── */
test('the phase moves on with the clock until the next refresh', () => {
  const live = { phase: 'live', starts_at: at(-HOUR), ends_at: at(HOUR) };
  assert.equal(effectivePhase(live, NOW, 0), 'live');
  assert.equal(effectivePhase(live, NOW + 2 * HOUR, 0), 'ended');
  assert.equal(effectivePhase({ phase: 'scheduled', starts_at: at(HOUR), ends_at: at(DAY) }, NOW, 0), 'scheduled');
  assert.equal(effectivePhase({ phase: 'scheduled', starts_at: at(HOUR), ends_at: at(DAY) }, NOW + 2 * HOUR, 0), 'live');
  assert.equal(effectivePhase({ phase: 'paused', ends_at: at(HOUR) }, NOW, 0), 'paused');
  assert.equal(effectivePhase({ phase: 'ended', ends_at: at(HOUR) }, NOW, 0), 'ended');
  assert.equal(effectivePhase({ phase: 'sold_out', ends_at: at(HOUR) }, NOW, 0), 'sold_out');
  // No phase at all (an odd payload): the dates decide.
  assert.equal(effectivePhase({ starts_at: at(HOUR), ends_at: at(DAY) }, NOW, 0), 'scheduled');
  assert.equal(effectivePhase({ ends_at: at(DAY) }, NOW, 0), 'live');
  // The skew counts: the server's clock is already past the end.
  assert.equal(effectivePhase(live, NOW, 2 * HOUR), 'ended');
  for (const p of ['live', 'scheduled', 'sold_out']) assert.equal(dealVisible(p), true);
  for (const p of ['ended', 'paused']) assert.equal(dealVisible(p), false);
});

/* ───────────── deal price ───────────── */
test('the deal price of a product while its deal is live, with the regular price to strike through', () => {
  const r = dealPriceFor(card(), null, NOW, 0);
  assert.equal(r.price.amount, '840.00');
  assert.equal(r.regular.amount, '1200.00');
  assert.equal(r.percent, 30);
  assert.equal(dealPriceFor({ price: card().price }, null, NOW, 0), null);
  assert.equal(dealPriceFor({ deal: null, price: card().price }, null, NOW, 0), null);
});

test('no deal price: not started, ended, sold out, units gone, or this customer used their share', () => {
  assert.equal(dealPriceFor(card({ deal: block({ phase: 'scheduled', starts_at: at(HOUR) }) }), null, NOW, 0), null);
  assert.equal(dealPriceFor(card({ deal: block({ phase: 'ended' }) }), null, NOW, 0), null);
  assert.equal(dealPriceFor(card({ deal: block({ phase: 'sold_out', sold_out: true }) }), null, NOW, 0), null);
  assert.equal(dealPriceFor(card({ deal: block({ sold_out: true }) }), null, NOW, 0), null);
  assert.equal(dealPriceFor(card({ deal: block({ remaining: 0 }) }), null, NOW, 0), null);
  assert.equal(dealPriceFor(card({ deal: block({ customer_remaining: 0 }) }), null, NOW, 0), null);
  // The clock passed the end before the next refresh.
  assert.equal(dealPriceFor(card(), null, NOW + 3 * DAY, 0), null);
  // Unlimited stock and a guest (nulls) are fine.
  assert.ok(dealPriceFor(card({ deal: block({ remaining: null, remaining_percent: null, customer_remaining: null }) }), null, NOW, 0));
  // A deal price that is not cheaper than the regular price is never shown as a deal.
  assert.equal(dealPriceFor(card({ deal: block({ deal_price: M(1200) }) }), null, NOW, 0), null);
  assert.equal(dealPriceFor(card({ deal: block({ deal_price: null }) }), null, NOW, 0), null);
});

test('a variant carries its own deal price; null there means that variant has no deal', () => {
  const p = card();
  const small = { deal_price: M(500), price: { final: M(800) } };
  const r = dealPriceFor(p, small, NOW, 0);
  assert.equal(r.price.amount, '500.00');
  assert.equal(r.regular.amount, '800.00');
  assert.equal(r.percent, 38); // 1 - 500/800, worked out for the variant (the block's 30 is for the "from" price)
  assert.equal(dealPriceFor(p, { deal_price: null, price: { final: M(300) } }, NOW, 0), null);
  assert.equal(dealPriceFor(p, { deal: { deal_price: M(1000) }, price: { final: M(1500) } }, NOW, 0).price.amount, '1000.00');
  // A variant that says nothing about a deal only shares the block's price when it costs what the block's regular price says.
  assert.equal(dealPriceFor(p, { price: { final: M(1200) } }, NOW, 0).price.amount, '840.00');
  assert.equal(dealPriceFor(p, { price: { final: M(2000) } }, NOW, 0), null);
});

test('how many units get the deal price, and what a quantity costs', () => {
  const b = block();
  assert.equal(unitsAtDealPrice(b, 1), 1);
  assert.equal(unitsAtDealPrice(b, 3), 2); // limit 2 per order
  assert.equal(unitsAtDealPrice(block({ limit_per_order: 5, customer_remaining: 1 }), 4), 1);
  assert.equal(unitsAtDealPrice(block({ limit_per_order: 5, customer_remaining: 0 }), 4), 0);
  assert.equal(unitsAtDealPrice(block({ limit_per_order: null, customer_remaining: null, limit_per_customer: null, remaining: 3 }), 10), 3);
  assert.equal(unitsAtDealPrice(block({ limit_per_order: null, customer_remaining: null, limit_per_customer: null, remaining: null }), 10), 10);
  // A guest (no customer_remaining) is still held to the per-customer limit within one order.
  assert.equal(unitsAtDealPrice(block({ limit_per_order: null, customer_remaining: null, limit_per_customer: 3 }), 10), 3);
  assert.equal(unitsAtDealPrice(null, 3), 0);
  assert.equal(unitsAtDealPrice(b, 0), 0);
  assert.equal(unitsAtDealPrice(b, -2), 0);
  const dp = dealPriceFor(card(), null, NOW, 0);
  assert.equal(dealTotal(dp, 1), 840);
  assert.equal(dealTotal(dp, 3), 840 * 2 + 1200); // 2 at the deal price, 1 at the regular price
});

test('the badge of a whole deal comes from its price rule', () => {
  assert.equal(dealBadgeLabel({ type: 'percent', value: '30' }, T, fmt), '−30%');
  assert.equal(dealBadgeLabel({ type: 'percent', value: '12.50' }, T, fmt), '−12.5%');
  assert.equal(dealBadgeLabel({ type: 'amount_off', value: '50' }, T, fmt, 'QAR'), 'Save QAR 50.00');
  assert.equal(dealBadgeLabel({ type: 'amount_off', value: '50' }, A, fmt, 'QAR'), 'وفّر QAR 50.00');
  assert.equal(dealBadgeLabel({ type: 'fixed_price', value: null }, T, fmt), 'Special price');
  assert.equal(dealBadgeLabel({ type: 'percent', value: null }, T, fmt), null);
  assert.equal(dealBadgeLabel(null, T, fmt), null);
});

/* ───────────── stock and limit words ───────────── */
test('"Only 12 left": stock wording in English and Arabic, singular and plural', () => {
  assert.equal(remainingText(T, 'en', block({ remaining: 12 })), 'Only 12 left');
  assert.equal(remainingText(T, 'en', block({ remaining: 1 })), 'Only 1 left');
  assert.equal(remainingText(A, 'ar', block({ remaining: 1 })), 'بقيت قطعة واحدة فقط');
  assert.equal(remainingText(A, 'ar', block({ remaining: 2 })), 'بقيت قطعتان فقط');
  assert.equal(remainingText(A, 'ar', block({ remaining: 5 })), 'بقيت 5 قطع فقط');
  assert.equal(remainingText(A, 'ar', block({ remaining: 12 })), 'بقيت 12 قطعة فقط');
  assert.equal(remainingText(A, 'ar', block({ remaining: 100 })), 'بقيت 100 قطعة فقط');
  assert.equal(remainingText(A, 'ar', block({ remaining: 1234 })), 'بقيت 1,234 قطعة فقط'); // Latin digits, like the rest of the store
  for (const b of [block({ remaining: null }), block({ remaining: 0 }), block({ sold_out: true }), null, undefined]) assert.equal(remainingText(T, 'en', b), null);
  assert.deepEqual(stockInfo(block({ remaining: 12, remaining_percent: 24 })), { remaining: 12, percent: 24, low: false });
  assert.equal(stockInfo(block({ remaining: 12, remaining_percent: 9 })).low, true);
  assert.equal(stockInfo(block({ remaining: 4, remaining_percent: 80 })).low, true);
  assert.equal(stockInfo(block({ remaining: 12, remaining_percent: 400 })).percent, 100);
  assert.equal(stockInfo(block({ remaining: 12, remaining_percent: null })).percent, null);
});

test('limit wording: per order, per customer, what is left, and the limit reached', () => {
  assert.deepEqual(limitLines(T, 'en', block({ customer_remaining: null })).lines, ['Limit 2 per order', 'Limit 3 per customer']);
  assert.deepEqual(limitLines(T, 'en', block({ customer_remaining: 2 })).lines, ['Limit 2 per order', 'You can still get 2 at this price']);
  const done = limitLines(T, 'en', block({ customer_remaining: 0 }));
  assert.deepEqual(done.lines, ['Limit 2 per order', 'You reached your limit for this deal']);
  assert.equal(done.reached, true);
  assert.equal(limitLines(T, 'en', block({ customer_remaining: 2 })).reached, false);
  assert.deepEqual(limitLines(T, 'en', block({ limit_per_order: null, limit_per_customer: null, customer_remaining: null })).lines, []);
  assert.deepEqual(limitLines(T, 'en', block({ limit_per_order: 1, limit_per_customer: null, customer_remaining: null })).lines, ['Limit 1 per order']);
  assert.deepEqual(limitLines(T, 'en', null), { lines: [], reached: false });
  // Arabic: the noun agrees with the number.
  assert.deepEqual(limitLines(A, 'ar', block({ limit_per_order: 1, customer_remaining: null, limit_per_customer: 2 })).lines, ['الحد الأقصى قطعة واحدة للطلب', 'الحد الأقصى قطعتان لكل عميل']);
  assert.deepEqual(limitLines(A, 'ar', block({ limit_per_order: 4, customer_remaining: 11 })).lines, ['الحد الأقصى 4 قطع للطلب', 'يمكنك الحصول على 11 قطعة بهذا السعر']);
  assert.equal(limitLines(A, 'ar', block({ customer_remaining: 0 })).lines.at(-1), 'وصلت إلى الحد الأقصى لهذا العرض');
});

test('"Deal price on 2 of 3 units" only when the quantity is more than the deal price covers', () => {
  assert.equal(partialText(T, 'en', 2, 3), 'Deal price on 2 of 3 units');
  assert.equal(partialText(A, 'ar', 2, 3), 'سعر العرض على 2 من 3 قطع');
  assert.equal(partialText(A, 'ar', 1, 2), 'سعر العرض على 1 من قطعتين');
  assert.equal(partialText(A, 'ar', 5, 14), 'سعر العرض على 5 من 14 قطعة');
  assert.equal(partialText(T, 'en', 3, 3), null);
  assert.equal(partialText(T, 'en', 0, 3), null);
  assert.equal(partialText(T, 'en', 1, 1), null);
});

test('plural(): by the language rule, falling back to .other', () => {
  const t = (k, v) => ({ 'x.one': 'ONE', 'x.other': 'OTHER {n}' }[k] ?? k).replace('{n}', v?.n ?? '');
  assert.equal(plural(t, 'en', 'x', 1), 'ONE');
  assert.equal(plural(t, 'en', 'x', 5), 'OTHER 5');
  assert.equal(plural(t, 'ar', 'x', 2), 'OTHER 2'); // no .two defined: other
  assert.equal(plural(t, 'ar', 'x', 0), 'OTHER 0');
  assert.equal(fmtNum('ar', 12345), '12,345');
  assert.equal(fmtNum('en', 12345), '12,345');
});

test('the screen-reader sentence rounds to the minute and says only the two largest units', () => {
  const secs = (d, h, m, s = 0) => d * 86400 + h * 3600 + m * 60 + s;
  assert.equal(humanRemaining(T, 'en', secs(2, 4, 12, 9)), 'Ends in 2 days 4 hours');
  assert.equal(humanRemaining(T, 'en', secs(1, 0, 30)), 'Ends in 1 day');
  assert.equal(humanRemaining(T, 'en', secs(0, 3, 12)), 'Ends in 3 hours 12 minutes');
  assert.equal(humanRemaining(T, 'en', secs(0, 1, 0)), 'Ends in 1 hour');
  assert.equal(humanRemaining(T, 'en', secs(0, 0, 0, 45)), 'Ends in less than a minute');
  assert.equal(humanRemaining(T, 'en', secs(0, 0, 1, 30)), 'Ends in 1 minute');
  assert.equal(humanRemaining(T, 'en', secs(0, 0, 0, 0)), 'Ends in less than a minute');
  assert.equal(humanRemaining(T, 'en', secs(0, 5, 0), 'starts'), 'Starts in 5 hours');
  assert.equal(humanRemaining(A, 'ar', secs(2, 4, 12)), 'ينتهي خلال يومين و4 ساعات');
  assert.equal(humanRemaining(A, 'ar', secs(1, 0, 0)), 'ينتهي خلال يوم واحد');
  assert.equal(humanRemaining(A, 'ar', secs(0, 3, 12)), 'ينتهي خلال 3 ساعات و12 دقيقة');
  assert.equal(humanRemaining(A, 'ar', secs(0, 0, 0, 45)), 'ينتهي خلال أقل من دقيقة');
  assert.equal(humanRemaining(A, 'ar', secs(11, 0, 0), 'starts'), 'يبدأ خلال 11 يومًا');
  // The same minute reads the same, whatever the seconds: the spoken label changes once a minute.
  assert.equal(humanRemaining(T, 'en', secs(0, 3, 12, 59)), humanRemaining(T, 'en', secs(0, 3, 12, 1)));
});

/* ───────────── cart, quote, order ───────────── */
const qd = (o = {}) => ({ deal_id: 'd1', name: 'Weekend Furniture Drop', units_at_deal_price: 2, units_at_regular_price: 1, savings: M(720), ends_at: at(DAY), note: 'limit_per_order', ...o });

test('a quote line deal is read tolerantly', () => {
  assert.equal(readQuoteDeal(null), null);
  assert.equal(readQuoteDeal(undefined), null);
  assert.equal(readQuoteDeal('x'), null);
  assert.equal(readQuoteDeal({ units_at_deal_price: 0, units_at_regular_price: 3, note: null }), null);
  const d = readQuoteDeal(qd());
  assert.equal(d.units_at_deal_price, 2);
  assert.equal(d.units_at_regular_price, 1);
  assert.equal(d.savings.amount, '720.00');
  assert.equal(d.note, 'limit_per_order');
  const odd = readQuoteDeal({ units_at_deal_price: '2', units_at_regular_price: '1', note: 'something_new', savings: '80' });
  assert.equal(odd.units_at_deal_price, 2);
  assert.equal(odd.note, null);
  assert.equal(odd.savings.amount, '80.00');
  assert.equal(readQuoteDeal({ units_at_deal_price: 0, units_at_regular_price: 1, note: 'sold_out' }).note, 'sold_out');
});

test('quote line words: deal units, savings, and why not every unit got the deal price', () => {
  const partial = quoteLineText(T, 'en', readQuoteDeal(qd()), { per_order: 2 });
  assert.equal(partial.summary, 'Deal price on 2 of 3 units');
  assert.equal(partial.saved, 'You saved QAR 720.00');
  assert.equal(partial.note, 'Limit 2 per order. The rest are at the regular price');
  assert.equal(partial.warn, true);
  // Without the limit's number the note still makes sense.
  assert.equal(quoteLineText(T, 'en', readQuoteDeal(qd()), {}).note, 'Per-order limit reached. The rest are at the regular price');
  assert.equal(quoteLineText(T, 'en', readQuoteDeal(qd({ note: 'limit_per_customer' })), { per_customer: 3 }).note, 'Limit 3 per customer. The rest are at the regular price');
  assert.equal(quoteLineText(T, 'en', readQuoteDeal(qd({ note: 'limit_per_customer' })), {}).note, 'You reached your limit for this deal. The rest are at the regular price');
  assert.equal(quoteLineText(T, 'en', readQuoteDeal(qd({ note: 'sold_out' })), {}).note, 'Deal stock is almost gone. The rest are at the regular price');
  assert.equal(quoteLineText(T, 'en', readQuoteDeal(qd({ note: 'ended' })), {}).note, 'This deal has ended. The regular price applies');

  // Every unit got the deal price.
  const all = quoteLineText(T, 'en', readQuoteDeal(qd({ units_at_deal_price: 3, units_at_regular_price: 0, note: null })), {});
  assert.equal(all.summary, 'Deal price on all units');
  assert.equal(all.note, null);
  assert.equal(all.warn, false);
  assert.equal(quoteLineText(T, 'en', readQuoteDeal(qd({ units_at_deal_price: 1, units_at_regular_price: 0, note: null })), {}).summary, 'Deal price applied');

  // No unit got it: only the reason shows.
  const none = quoteLineText(T, 'en', readQuoteDeal(qd({ units_at_deal_price: 0, units_at_regular_price: 3, savings: M(0), note: 'limit_per_customer' })), {});
  assert.equal(none.summary, null);
  assert.equal(none.saved, null);
  assert.equal(none.note, 'You reached your limit for this deal. The regular price applies');
  assert.equal(quoteLineText(T, 'en', readQuoteDeal(qd({ units_at_deal_price: 0, note: 'sold_out', savings: null })), {}).note, 'This deal is sold out. The regular price applies');
  assert.equal(quoteLineText(T, 'en', readQuoteDeal(qd({ units_at_deal_price: 0, note: 'ended', savings: null })), {}).note, 'This deal has ended. The regular price applies');
  assert.equal(quoteLineText(T, 'en', null, {}), null);

  // Arabic
  const ar1 = quoteLineText(A, 'ar', readQuoteDeal(qd()), { per_order: 2 });
  assert.equal(ar1.summary, 'سعر العرض على 2 من 3 قطع');
  assert.equal(ar1.saved, 'وفّرت QAR 720.00');
  assert.equal(ar1.note, 'الحد الأقصى قطعتان للطلب. وباقي القطع بالسعر المعتاد');
  assert.equal(quoteLineText(A, 'ar', readQuoteDeal(qd({ units_at_deal_price: 3, units_at_regular_price: 0, note: null })), {}).summary, 'سعر العرض على جميع القطع');
});

test('the cart shows the deal of a line before the server prices it', () => {
  const x = cartLineText(T, 'en', { qty: 3, deal: block() }, NOW, 0);
  assert.equal(x.name, 'Weekend Furniture Drop');
  assert.equal(x.summary, 'Deal price on 2 of 3 units');
  assert.deepEqual(x.limits, ['Limit 2 per order', 'You can still get 3 at this price']);
  assert.equal(x.reached, false);
  assert.equal(cartLineText(T, 'en', { qty: 1, deal: block() }, NOW, 0).summary, 'Deal price applied');
  assert.equal(cartLineText(T, 'en', { qty: 2, deal: block() }, NOW, 0).summary, 'Deal price on all units');
  const reached = cartLineText(T, 'en', { qty: 2, deal: block({ customer_remaining: 0 }) }, NOW, 0);
  assert.equal(reached.summary, null);
  assert.equal(reached.reached, true);
  // Nothing for a line with no deal, a deal not started or over, or a sold-out deal.
  for (const deal of [null, undefined, block({ phase: 'ended' }), block({ phase: 'scheduled', starts_at: at(HOUR) }), block({ sold_out: true })]) assert.equal(cartLineText(T, 'en', { qty: 1, deal }, NOW, 0), null);
});

test('the cart prefers the units the server priced for the line, and shows a savings row only above zero', () => {
  // The server cart line: the product's block plus how many of THIS line's units get the deal price.
  const priced = block({ units_at_deal_price: 1, units_at_regular_price: 2, note: 'limit_per_customer' });
  const x = cartLineText(T, 'en', { qty: 3, deal: priced }, NOW, 0);
  assert.equal(x.summary, 'Deal price on 1 of 3 units'); // not the 2 the block's own limits would give
  // Nothing at the deal price for the line (the product's limit is used up by another line of the same product).
  assert.equal(cartLineText(T, 'en', { qty: 2, deal: block({ units_at_deal_price: 0, units_at_regular_price: 2, note: 'limit_per_order' }) }, NOW, 0).summary, null);
  assert.deepEqual(cartLineText(T, 'en', { qty: 2, deal: block({ units_at_deal_price: 0, units_at_regular_price: 2, note: 'sold_out', customer_remaining: null, limit_per_customer: null, limit_per_order: null }) }, NOW, 0).limits, ['This deal is sold out']);
  assert.deepEqual(cartLineText(T, 'en', { qty: 2, deal: block({ units_at_deal_price: 1, units_at_regular_price: 1, note: 'sold_out', customer_remaining: null, limit_per_customer: null, limit_per_order: null }) }, NOW, 0).limits, ['Deal stock is almost gone']);
  assert.equal(positiveMoney(M(720)).amount, '720.00');
  for (const v of [M(0), null, undefined, 'x', {}]) assert.equal(positiveMoney(v), null);
});

test('"Deal savings" is its own row, and the discount row does not count it twice', () => {
  const totals = (o) => ({ subtotal: M(2400), discount: M(720), delivery_fee: M(30), service_fee: M(0), tax: M(0), loyalty_discount: M(0), grand_total: M(1710), deal_savings: M(720), ...o });
  // Discount holds the deal savings (30 + 2400 - 720 = 1710).
  const inside = dealSavingsSplit({ totals: totals() }, fmt);
  assert.equal(inside.deal.amount, '720.00');
  assert.equal(inside.inDiscount, true);
  assert.equal(minusDeal(M(720), inside, fmt), null);
  assert.equal(minusDeal(M(770), inside, fmt).amount, '50.00');
  // Discount is only the other discounts and the deal savings come on top (2400 + 30 - 50 - 720 = 1660).
  const apart = dealSavingsSplit({ totals: totals({ discount: M(50), grand_total: M(1660) }) }, fmt);
  assert.equal(apart.inDiscount, false);
  assert.equal(minusDeal(M(50), apart, fmt).amount, '50.00');
  // A coupon next to the deal inside one discount: 720 deal + 50 coupon = 770; 2400 + 30 - 770 = 1660.
  const both = dealSavingsSplit({ totals: totals({ discount: M(770), grand_total: M(1660) }) }, fmt);
  assert.equal(both.inDiscount, true);
  assert.equal(minusDeal(M(770), both, fmt).amount, '50.00');
  // No deal: nothing changes.
  for (const t of [totals({ deal_savings: M(0) }), totals({ deal_savings: undefined }), totals({ deal_savings: null })]) {
    const none = dealSavingsSplit({ totals: t }, fmt);
    assert.equal(none.deal, null);
    assert.equal(minusDeal(M(20), none, fmt).amount, '20.00');
  }
  assert.deepEqual(dealSavingsSplit(null, fmt), { deal: null, inDiscount: false });
  assert.equal(minusDeal(null, inside, fmt), null);
  assert.equal(minusDeal(M(0), inside, fmt), null);
});

test('409 deal_changed is recognised, and its fresh quote is used only when complete', () => {
  assert.equal(isDealChanged({ status: 409, code: 'deal_changed' }), true);
  assert.equal(isDealChanged({ status: 409, code: 'quote_changed' }), false);
  assert.equal(isDealChanged({ status: 422, code: 'deal_changed' }), false);
  for (const x of [null, undefined, 'deal_changed', 409, new Error('x')]) assert.equal(isDealChanged(x), false);

  const quote = { quote_hash: 'h2', totals: { grand_total: M(1500) }, payment_methods: [], fulfillments: [], lines: [], discounts: [] };
  // The server's error envelope carries the quote as `context.quote`; the contract names `data.quote`: both are read.
  const real = { status: 409, code: 'deal_changed', body: { message: 'A deal changed', code: 'deal_changed', context: { deal_id: 'd1', product_id: 'p1', quote }, request_id: 'r' } };
  assert.equal(quoteFromDealChanged(real).quote_hash, 'h2');
  const err = { status: 409, code: 'deal_changed', body: { code: 'deal_changed', data: { quote } } };
  assert.equal(quoteFromDealChanged(err).quote_hash, 'h2');
  assert.equal(quoteFromDealChanged({ status: 409, body: { context: { deal_id: 'd1' } } }), null);
  assert.equal(quoteFromDealChanged({ status: 409, code: 'deal_changed', body: { code: 'deal_changed' } }), null);
  assert.equal(quoteFromDealChanged({ status: 409, body: { data: { quote: { quote_hash: 'x' } } } }), null);
  assert.equal(quoteFromDealChanged(null), null);
});

test('a placed order line keeps its deal: name, units and what they saved', () => {
  const l = orderLineDeal({ deal_id: 'd1', name: 'Weekend Furniture Drop', units: 2, unit_savings: M(360) }, fmt);
  assert.equal(l.name, 'Weekend Furniture Drop');
  assert.equal(l.units, 2);
  assert.equal(l.saved.amount, '720.00');
  assert.equal(l.saved.formatted, 'QAR 720.00');
  assert.equal(orderLineDeal({ name: 'x', units: 1, unit_savings: '90.5' }, fmt).saved.amount, '90.50');
  assert.equal(orderLineDeal({ name: 'x', units: 2 }, fmt).saved, null);
  for (const bad of [null, undefined, {}, { units: 0, unit_savings: M(5) }, 'x']) assert.equal(orderLineDeal(bad, fmt), null);
  const items = [{ deal: { name: 'A', units: 2, unit_savings: M(360) } }, { deal: null }, {}, { deal: { name: 'B', units: 1, unit_savings: M(80) } }];
  assert.equal(orderDealSavings(items, fmt).amount, '800.00');
  assert.equal(orderDealSavings([{ deal: null }], fmt), null);
  assert.equal(orderDealSavings([], fmt), null);
});

test('the order\'s deal savings leave out the lines the branch took out, count a reduced line by what is left, and fall back to the order total', () => {
  const deal = (units, each) => ({ deal_id: 'd1', name: 'Weekend Furniture Drop', units, unit_savings: M(each) });
  const sold = { quantity: 2, deal: deal(2, 360) }; // 720
  // Removed or replaced lines keep their snapshot but nothing was sold.
  const removed = { quantity: 2, adjustment: { kind: 'removed' }, deal: deal(2, 100) };
  const replaced = { quantity: 1, adjustment: { kind: 'replaced' }, deal: deal(1, 50) };
  const zero = { quantity: 0, deal: deal(3, 10) };
  assert.equal(orderDealSavings([sold, removed, replaced, zero], fmt).amount, '720.00');
  assert.equal(orderLineGone(removed), true);
  assert.equal(orderLineGone(replaced), true);
  assert.equal(orderLineGone(zero), true);
  assert.equal(orderLineGone(sold), false);
  assert.equal(orderLineGone({ quantity: 1, adjustment: { kind: 'reduced' } }), false);
  assert.equal(orderLineGone({}), false);
  // A reduced line keeps its snapshot of 3 deal units but has 1 piece left: 1 unit saved.
  const reduced = { quantity: 1, adjustment: { kind: 'reduced' }, deal: deal(3, 100) };
  assert.equal(orderDealSavings([reduced], fmt).amount, '100.00');
  assert.equal(orderLineDeal(reduced.deal, fmt, 1).units, 1);
  assert.equal(orderLineDeal(reduced.deal, fmt, 1).saved.amount, '100.00');
  assert.equal(orderLineDeal(reduced.deal, fmt).units, 3);
  assert.equal(orderLineDeal(reduced.deal, fmt, 0), null);
  // Every deal line was taken out: nothing was saved, and the order's own figure (which may still include them) is not used.
  assert.equal(orderDealSavings([removed, replaced], fmt, M(150)), null);
  // No line carries a deal snapshot (an order placed before it existed, or a payload without it): the order's own figure.
  assert.equal(orderDealSavings([{ quantity: 1 }, { quantity: 2, deal: null }], fmt, M(150)).amount, '150.00');
  assert.equal(orderDealSavings([{ quantity: 1 }], fmt, M(0)), null);
  assert.equal(orderDealSavings([{ quantity: 1 }], fmt, undefined), null);
  // The lines win over the order's figure when they have a snapshot.
  assert.equal(orderDealSavings([sold], fmt, M(999)).amount, '720.00');
});

/* ───────────── API payloads ───────────── */
const apiDeal = (o = {}) => ({
  id: 'd1', name: 'Weekend Furniture Drop', tagline: 'Big pieces, small prices', phase: 'live', starts_at: at(-HOUR), ends_at: at(DAY), seconds_left: 93234,
  discount: { type: 'percent', value: '30' }, limits: { per_order: 2, per_customer: 3 }, products_total: 18, products: [card()],
  design: { style: 'luxe', palette: 'gold', colors: { background: '#111111', text: '#F5F0E6', accent: '#C9A24B' }, shape: 'sharp', timer: { units: ['h', 'm', 's'], look: 'digits', urgency_minutes: 30 }, stock_bar: false, percent_badge: true, icon: 'crown', headline_en: 'Ends in', headline_ar: 'ينتهي خلال', cta_en: 'Shop the deal', cta_ar: 'تسوق العرض', image_url: null },
  show_on_home: true, ...o,
});

test('GET /deals: both lists, the clock, and every field present even when the server sends less', () => {
  const d = readDealsData({ server_time: at(0), live: [apiDeal(), { id: 'd2' }, null, 'x', { name: 'no id' }], upcoming: [apiDeal({ id: 'd3', phase: 'scheduled' })] });
  assert.equal(d.serverTime, at(0));
  assert.deepEqual(d.live.map((x) => x.id), ['d1', 'd2']);
  assert.equal(d.upcoming[0].phase, 'scheduled');
  const bare = d.live[1];
  assert.equal(bare.name, '');
  assert.equal(bare.phase, 'live');
  assert.deepEqual(bare.products, []);
  assert.equal(bare.design.style, 'classic');
  assert.equal(bare.discount.value, null);
  assert.deepEqual(bare.limits, { per_order: null, per_customer: null });
  assert.deepEqual(readDealsData(undefined), { serverTime: null, live: [], upcoming: [] });
  assert.deepEqual(readDealsData({ live: 'nope' }).live, []);
  const full = d.live[0];
  assert.equal(full.discount.value, '30');
  assert.equal(full.products_total, 18);
  assert.equal(full.show_on_home, true);
  assert.equal(full.design.style, 'luxe');
});

test('GET /deals/{id}: products come as {data, meta}', () => {
  const d = readDeal(apiDeal({ products: { data: [card(), card({ id: 'p2', slug: 'b' })], meta: { current_page: 2, last_page: 3, total: 50 } } }));
  assert.equal(d.products.length, 2);
  assert.deepEqual(d.products_meta, { current_page: 2, last_page: 3, total: 50 });
  assert.equal(readDeal(apiDeal({ products: [card()] })).products_meta, null);
  // A product without an id or slug is dropped instead of breaking the grid.
  assert.equal(readDeal(apiDeal({ products: [{ id: 'x' }, card()] })).products.length, 1);
  assert.equal(readDeal(null), null);
  assert.equal(readDeal({ name: 'x' }), null);
});

test('the next moment worth asking the server again: a deal ending or an upcoming one starting', () => {
  const data = { serverTime: null, live: [readDeal(apiDeal({ ends_at: at(3 * HOUR) })), readDeal(apiDeal({ id: 'd2', ends_at: at(HOUR) }))], upcoming: [readDeal(apiDeal({ id: 'd3', phase: 'scheduled', starts_at: at(30 * MIN), ends_at: at(DAY) }))] };
  assert.equal(nextChangeAt(data, NOW, 0), NOW + 30 * MIN);
  assert.equal(nextChangeAt(data, NOW + 45 * MIN, 0), NOW + HOUR);
  assert.equal(nextChangeAt(data, NOW + 10 * HOUR, 0), null);
  assert.equal(nextChangeAt(data, NOW, 2 * HOUR), NOW + 3 * HOUR); // the server's clock is two hours ahead: only the 3 h one is still ahead
  assert.equal(nextChangeAt(null), null);
  assert.equal(nextChangeAt({ serverTime: null, live: [], upcoming: [] }), null);
});

/* ───────────── design ───────────── */
test('the design is filled in and checked; colours are the server\'s or the store\'s own tokens, never invented', () => {
  const d = normalizeDesign(apiDeal().design);
  assert.equal(d.style, 'luxe');
  assert.equal(d.shape, 'sharp');
  assert.deepEqual(d.timer, { units: ['h', 'm', 's'], look: 'digits', urgency_minutes: 30 });
  assert.equal(d.stock_bar, false);
  assert.equal(d.icon, 'crown');
  assert.equal(d.colorsKnown, true);
  assert.deepEqual(d.colors, { background: '#111111', text: '#F5F0E6', accent: '#C9A24B' });

  const empty = normalizeDesign(undefined);
  assert.equal(empty.style, 'classic');
  assert.equal(empty.shape, 'rounded');
  assert.deepEqual(empty.timer, { units: ['d', 'h', 'm', 's'], look: 'boxes', urgency_minutes: 60 });
  assert.equal(empty.stock_bar, true);
  assert.equal(empty.percent_badge, true);
  assert.equal(empty.icon, 'flame');
  assert.equal(empty.colorsKnown, false);
  assert.ok(Object.values(empty.colors).every((c) => c.startsWith('var(--')));

  assert.equal(normalizeDesign({ style: 'neon', shape: 'blob', icon: 'rocket', timer: { look: 'matrix' } }).style, 'classic');
  assert.equal(normalizeDesign({ timer: { urgency_minutes: 0 } }).timer.urgency_minutes, 0); // 0 = off, kept
  assert.equal(normalizeDesign({ timer: { urgency_minutes: -5 } }).timer.urgency_minutes, 0);
  assert.equal(normalizeDesign({ timer: { units: ['s'] } }).timer.units.length, 4);
  assert.equal(normalizeDesign({ colors: { background: 'red', text: '#fff', accent: '#000' } }).colorsKnown, false);
  assert.equal(normalizeDesign({ colors: { background: '#abc', text: '#FFFFFF', accent: '#000000' } }).colorsKnown, true);
  assert.equal(normalizeDesign({ image_url: '  ' }).image_url, null);
  // Normalising twice changes nothing.
  assert.deepEqual(normalizeDesign(normalizeDesign(apiDeal().design)), d);
});

test('headline and button words follow the language, never the other one', () => {
  const d = normalizeDesign({ headline_en: 'Hurry, ends in', headline_ar: null, cta_en: 'Grab it', cta_ar: 'اغتنم الفرصة' });
  assert.equal(designWord(d, 'headline', 'en'), 'Hurry, ends in');
  assert.equal(designWord(d, 'headline', 'ar'), null); // the page then uses its own Arabic words, not the English ones
  assert.equal(designWord(d, 'cta', 'ar'), 'اغتنم الفرصة');
  assert.equal(designWord(d, 'cta', 'en'), 'Grab it');
});

test('contrast and the choice of readable text colour use only the palette', () => {
  assert.equal(Math.round(contrast('#000000', '#FFFFFF')), 21);
  assert.equal(contrast('#777777', '#777777'), 1);
  assert.equal(contrast('var(--x)', '#fff'), null);
  // Text on a gold accent: the dark background reads, the cream text does not.
  assert.equal(readableOn('#C9A24B', '#111111', '#F5F0E6'), '#111111');
  // Text on a dark accent: the light one.
  assert.equal(readableOn('#8A1538', '#FCF9FA', '#2A1A20'), '#FCF9FA');
  assert.equal(readableOn('#8A1538', 'var(--a)', 'var(--b)'), 'var(--a)');
});

/* ───────────── words exist in both languages ───────────── */
test('every deals string exists in English and Arabic with the same placeholders, and Arabic has all six number forms where English has any', () => {
  const mine = (k) => k.startsWith('deal.') || k.startsWith('deals.') || k === 'nav.deals';
  const enKeys = Object.keys(en).filter(mine), arKeys = Object.keys(ar).filter(mine);
  const slots = (s) => [...s.matchAll(/\{(\w+)\}/g)].map((m) => m[1]).sort().join(',');
  for (const k of enKeys) {
    assert.ok(k in ar, `Arabic is missing ${k}`);
    if (!/\.(one|two|few|many|other)$/.test(k)) assert.equal(slots(ar[k]), slots(en[k]), `placeholders differ in ${k}`);
  }
  // Extra Arabic forms are fine; each Arabic form must use a placeholder English also uses (or none).
  for (const k of arKeys) assert.ok(k in en || /\.(one|two|few|many|other)$/.test(k), `English is missing ${k}`);
  const families = new Set(enKeys.filter((k) => /\.(one|two|few|many|other)$/.test(k)).map((k) => k.replace(/\.(one|two|few|many|other)$/, '')));
  assert.ok(families.size >= 8);
  for (const f of families) {
    for (const form of ['one', 'two', 'few', 'many', 'other']) assert.ok(`${f}.${form}` in ar, `Arabic needs ${f}.${form}`);
    assert.ok(`${f}.other` in en, `English needs ${f}.other`);
  }
  // No English word leaks into the Arabic text.
  for (const k of arKeys) assert.ok(!/[A-Za-z]{4,}/.test(ar[k].replace(/\{\w+\}/g, '')), `Latin letters in Arabic ${k}: ${ar[k]}`);
});
