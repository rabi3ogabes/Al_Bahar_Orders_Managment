import test from 'node:test';
import assert from 'node:assert/strict';
import { PAY_REASONS, nothingToPayWith, payReason } from '../src/lib/payReasons.ts';
import { translate } from '../src/lib/i18nCore.ts';
import { splitTemplate } from '../src/lib/template.ts';
import { basketShareText, whatsappShareHref } from '../src/lib/shareBasket.ts';
import { en } from '../src/locales/en.ts';
import { ar } from '../src/locales/ar.ts';

const dicts = { en, ar };
const qar = (n) => ({ amount: n.toFixed(2), currency: 'QAR', formatted: `QAR ${n.toLocaleString('en-US', { minimumFractionDigits: 2 })}` });
const say = (locale, r) => translate(dicts, locale, r.key, r.vars);

test('cash on delivery over its limit says the limit and the basket, in both languages', () => {
  const r = payReason({ reason: 'outside_cod_limits', limits: { max: qar(5000), min: null } }, qar(8200), (m) => m.formatted);
  assert.equal(say('en', r), 'Cash on delivery is available up to QAR 5,000.00. Your basket is QAR 8,200.00.');
  assert.match(say('ar', r), /5,000\.00/);
  assert.match(say('ar', r), /8,200\.00/);
  assert.doesNotMatch(say('ar', r), /\{|pay\.reason/);
});

test('cash on delivery below its lowest amount says where it starts', () => {
  const r = payReason({ reason: 'outside_cod_limits', limits: { min: qar(300), max: null } }, qar(265), (m) => m.formatted);
  assert.equal(say('en', r), 'Cash on delivery is available from QAR 300.00. Your basket is QAR 265.00.');
});

test('with both limits the one that was crossed is the one named', () => {
  const limits = { min: qar(300), max: qar(5000) };
  assert.equal(payReason({ reason: 'outside_cod_limits', limits }, qar(8200), (m) => m.formatted).key, 'pay.reason.cod_max');
  assert.equal(payReason({ reason: 'outside_cod_limits', limits }, qar(100), (m) => m.formatted).key, 'pay.reason.cod_min');
});

test('without the numbers (an older server) the sentence is still kind and complete', () => {
  for (const reason of ['outside_cod_limits', 'exceeds_cod_limit']) {
    const r = payReason({ reason }, qar(8200), (m) => m.formatted);
    assert.equal(r.key, 'pay.reason.outside_cod_limits');
    assert.equal(say('en', r), 'Cash on delivery is not available for an order of this size.');
  }
  assert.equal(payReason({ reason: 'outside_cod_limits', limits: { max: qar(5000) } }, null, (m) => m.formatted).key, 'pay.reason.outside_cod_limits');
});

test('every reason the server can send has a sentence in English and Arabic, with no raw key', () => {
  for (const reason of [...PAY_REASONS, 'unavailable']) {
    const r = payReason({ reason }, qar(8200), (m) => m.formatted);
    for (const locale of ['en', 'ar']) {
      const text = say(locale, r);
      assert.ok(text.length > 10, `${reason} ${locale}`);
      assert.doesNotMatch(text, /pay\.reason|\{[a-z]+\}/, `${reason} ${locale}`);
    }
    assert.ok(dicts.en[r.key] && dicts.ar[r.key], `${reason}: ${r.key} exists in both languages`);
  }
});

test('a reason from a newer server never shows its code', () => {
  const r = payReason({ reason: 'some_future_reason' }, qar(10), (m) => m.formatted);
  const missing = [];
  const text = translate(dicts, 'en', r.key, r.vars, (k) => missing.push(k));
  assert.equal(text, 'Not available for this order.');
  assert.deepEqual(missing, ['pay.reason.some_future_reason']);
  assert.equal(translate(dicts, 'ar', r.key, r.vars), dicts.ar['pay.reason.unavailable']);
});

test('a missing key anywhere falls back to the generic sentence, never the key', () => {
  const seen = [];
  assert.equal(translate(dicts, 'en', 'nothing.here', undefined, (k) => seen.push(k)), 'Something went wrong. Please try again.');
  assert.equal(translate(dicts, 'ar', 'nothing.here'), dicts.ar['error.generic']);
  assert.deepEqual(seen, ['nothing.here']);
  assert.equal(translate(dicts, 'en', 'return.title'), 'Report a problem or return');
  assert.equal(translate(dicts, 'en', 'checkout.points', { n: 5 }), 'You have 5 points');
  assert.equal(translate({ en: { a: 'English only {n}' }, ar: {} }, 'ar', 'a', { n: 2 }), 'English only 2', 'a key missing in Arabic shows the English text');
});

test('nothing to pay with means the quote is there and every method is off', () => {
  assert.equal(nothingToPayWith(undefined), false);
  assert.equal(nothingToPayWith([]), false);
  assert.equal(nothingToPayWith([{ available: false }, { available: false }, { available: false }]), true);
  assert.equal(nothingToPayWith([{ available: false }, { available: true }]), false);
});

// ── The basket sent to the family ───────────────────────────────────────────────────────────────────────────────────

test('the message to the family lists each piece with its page and ends with the total', () => {
  const text = basketShareText([{ name: 'Oslo Sofa', variantName: 'Grey', qty: 2, slug: 'oslo-sofa' }, { name: 'Lamp', qty: 1 }], { intro: 'Please help.', total: 'Total: QAR 8,200.00' }, 'https://albahar.qa');
  assert.equal(text, 'Please help.\n\n• Oslo Sofa (Grey) × 2\n  https://albahar.qa/products/oslo-sofa\n• Lamp × 1\n\nTotal: QAR 8,200.00');
  const href = whatsappShareHref(text);
  assert.ok(href.startsWith('https://wa.me/?text='));
  assert.equal(decodeURIComponent(href.slice('https://wa.me/?text='.length)), text);
});

test('a very long basket is cut after 15 pieces and says how many more', () => {
  const lines = Array.from({ length: 18 }, (_, i) => ({ name: `Piece ${i + 1}`, qty: 1 }));
  const text = basketShareText(lines, { intro: 'Hi', total: 'T' }, 'https://x');
  assert.ok(text.includes('Piece 15') && !text.includes('Piece 16'));
  assert.ok(text.includes('… +3'));
});

// ── {slots} in a sentence ───────────────────────────────────────────────────────────────────────────────────────────

test('a sentence with slots is cut into text and slots, in any order', () => {
  assert.deepEqual(splitTemplate('By continuing you agree to our {terms} and {privacy}.'), ['By continuing you agree to our ', { slot: 'terms' }, ' and ', { slot: 'privacy' }, '.']);
  assert.deepEqual(splitTemplate('بالمتابعة فإنك توافق على {terms} و{privacy}.'), ['بالمتابعة فإنك توافق على ', { slot: 'terms' }, ' و', { slot: 'privacy' }, '.']);
  assert.deepEqual(splitTemplate('no slots'), ['no slots']);
  assert.deepEqual(splitTemplate('{a}{b}'), [{ slot: 'a' }, { slot: 'b' }]);
  assert.deepEqual(splitTemplate(''), []);
});

test('the sign-up notice exists in both languages with both links', () => {
  for (const d of [en, ar]) {
    const slots = splitTemplate(d['auth.legal']).filter((p) => typeof p !== 'string').map((p) => p.slot);
    assert.deepEqual(slots, ['terms', 'privacy']);
    assert.ok(d['auth.legal_terms'] && d['auth.legal_privacy']);
  }
});

// ── The two languages hold the same keys ───────────────────────────────────────────────────────────────────────────────

test('English and Arabic have exactly the same keys, and no value is empty', () => {
  const only = (a, b) => Object.keys(a).filter((k) => !(k in b));
  assert.deepEqual(only(en, ar), [], 'in English only');
  assert.deepEqual(only(ar, en), [], 'in Arabic only');
  for (const [k, v] of [...Object.entries(en), ...Object.entries(ar)]) assert.ok(String(v).trim().length > 0, k);
});

test('a {slot} in the English text is in the Arabic text too (so no number or name goes missing)', () => {
  const slots = (s) => [...s.matchAll(/\{([a-zA-Z_]+)\}/g)].map((m) => m[1]).sort().join(',');
  const bad = Object.keys(en).filter((k) => slots(en[k]) !== slots(ar[k] ?? ''));
  assert.deepEqual(bad, []);
});
