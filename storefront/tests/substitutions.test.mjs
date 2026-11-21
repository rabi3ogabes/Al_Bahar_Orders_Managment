import test from 'node:test';
import assert from 'node:assert/strict';
import { answerFailureKey, minutesLeft, outcomeKey, priceNoteKey, waitingOf } from '../src/lib/substitutions.ts';
import { translate } from '../src/lib/i18nCore.ts';
import { en } from '../src/locales/en.ts';
import { ar } from '../src/locales/ar.ts';

const dicts = { en, ar };
const money = (amount) => ({ amount, currency: 'QAR', formatted: `QAR ${amount}` });
const q = (id, status, expires) => ({ id, status, expires_at: expires, original: null, replacement: null, price_difference: money('0.00'), fallback: 'remove', asked_at: '', answered_at: null, can_answer: status === 'waiting' });

test('only the waiting questions need an answer, the soonest to run out first', () => {
  const list = [q('a', 'accepted', '2026-11-19T12:30:00+03:00'), q('b', 'waiting', '2026-11-19T13:00:00+03:00'), q('c', 'waiting', '2026-11-19T12:10:00+03:00'), q('d', 'cancelled', '2026-11-19T11:00:00+03:00')];
  assert.deepEqual(waitingOf(list).map((r) => r.id), ['c', 'b']);
  assert.deepEqual(waitingOf(undefined), []);
});

test('the price is "same price" or "less", never more', () => {
  assert.equal(priceNoteKey(money('0.00')), 'subst.price_same');
  assert.equal(priceNoteKey(money('-800.00')), 'subst.price_less');
  assert.equal(priceNoteKey(money('-0.50')), 'subst.price_less');
});

test('minutes left count up to the deadline and never go below zero', () => {
  const at = Date.parse('2026-11-19T12:30:00+03:00');
  assert.equal(minutesLeft('2026-11-19T12:30:00+03:00', at - 29 * 60000 - 1000), 30);
  assert.equal(minutesLeft('2026-11-19T12:30:00+03:00', at - 60000), 1);
  assert.equal(minutesLeft('2026-11-19T12:30:00+03:00', at), 0);
  assert.equal(minutesLeft('2026-11-19T12:30:00+03:00', at + 3600000), 0);
  assert.equal(minutesLeft('not a date', at), 0);
});

test('every way a question can end has a sentence, and an unknown one says nothing', () => {
  for (const s of ['accepted', 'declined', 'expired_removed', 'expired_kept', 'cancelled']) {
    const key = outcomeKey(s);
    assert.ok(key, s);
    for (const locale of ['en', 'ar']) assert.ok(translate(dicts, locale, key).length > 8, `${locale} ${s}`);
  }
  assert.equal(outcomeKey('waiting'), null);
  assert.equal(outcomeKey('something_new'), null);
});

test('a failed answer is explained in plain words, in both languages', () => {
  assert.equal(answerFailureKey(401, 'x'), 'subst.err_signin');
  assert.equal(answerFailureKey(404, 'not_found'), 'subst.err_gone');
  assert.equal(answerFailureKey(409, 'substitution_already_answered'), 'subst.err_answered');
  assert.equal(answerFailureKey(409, 'substitution_expired'), 'subst.err_expired');
  assert.equal(answerFailureKey(409, 'substitution_closed'), 'subst.err_closed');
  assert.equal(answerFailureKey(429, 'x'), 'subst.err_busy');
  assert.equal(answerFailureKey(500, 'x'), 'error.generic');
  for (const k of ['subst.err_signin', 'subst.err_gone', 'subst.err_answered', 'subst.err_expired', 'subst.err_closed', 'subst.err_busy']) assert.ok(en[k] && ar[k], k);
});

test('the question and its buttons have English and Arabic words with the same placeholders', () => {
  const keys = Object.keys(en).filter((k) => k.startsWith('subst.'));
  assert.ok(keys.length > 20);
  const slots = (s) => [...s.matchAll(/\{(\w+)\}/g)].map((m) => m[1]).sort().join(',');
  for (const k of keys) { assert.ok(ar[k], `ar ${k}`); assert.equal(slots(ar[k]), slots(en[k]), k); }
  assert.match(translate(dicts, 'en', 'subst.ask', { item: 'Oslo sofa', replacement: 'Bergen sofa' }), /Oslo sofa.*Bergen sofa/);
  assert.match(translate(dicts, 'ar', 'subst.time_left', { n: 12 }), /12/);
});
