import test from 'node:test';
import assert from 'node:assert/strict';
import { assemblyShown, offerView, pickedTime, pickFailureKey, proofKey, timeKey } from '../src/lib/followUps.ts';
import { translate } from '../src/lib/i18nCore.ts';
import { en } from '../src/locales/en.ts';
import { ar } from '../src/locales/ar.ts';

const dicts = { en, ar };
const time = (slot, date, available = true) => ({ slot_id: slot, date, starts_at: '16:00', ends_at: '18:00', available, label: `${date} 16:00-18:00` });
const offer = (over = {}) => ({ id: 'off1', status: 'open', source: 'system', expires_at: '2026-11-21T12:00:00+03:00', can_choose: true, options: [time('s1', '2026-11-20'), time('s2', '2026-11-21'), time('s3', '2026-11-22', false)], chosen: null, ...over });

test('an open offer with a free time asks the customer; with none free it says so; run out and chosen are calm', () => {
  assert.equal(offerView(offer()), 'choose');
  assert.equal(offerView(offer({ can_choose: false, options: [time('s1', '2026-11-20', false)] })), 'all_taken');
  assert.equal(offerView(offer({ options: [time('s1', '2026-11-20', false)] })), 'all_taken');
  assert.equal(offerView(offer({ status: 'expired', can_choose: false, options: [] })), 'expired');
  assert.equal(offerView(offer({ status: 'chosen', chosen: { ...time('s2', '2026-11-21'), at: null } })), 'chosen');
});

test('nothing is drawn for no offer, a chosen offer without its time, or a status a newer server invents', () => {
  assert.equal(offerView(null), null);
  assert.equal(offerView(undefined), null);
  assert.equal(offerView(offer({ status: 'chosen', chosen: null })), null);
  assert.equal(offerView(offer({ status: 'something_new' })), null);
});

test('only a time that is still free can be sent', () => {
  const o = offer();
  assert.equal(pickedTime(o, timeKey(o.options[1]))?.slot_id, 's2');
  assert.equal(pickedTime(o, timeKey(o.options[2])), null, 'taken');
  assert.equal(pickedTime(o, 'nope|2026-01-01'), null);
  assert.equal(pickedTime(o, null), null);
  assert.notEqual(timeKey(time('s1', '2026-11-20')), timeKey(time('s1', '2026-11-21')), 'the same slot on another day is another time');
});

test('every refusal has a plain sentence in both languages', () => {
  const cases = [[401, ''], [404, 'x'], [409, 'offer_expired'], [409, 'offer_already_chosen'], [409, 'offer_closed'], [409, 'slot_full'], [422, 'slot_unavailable'], [429, ''], [500, '']];
  for (const [status, code] of cases) {
    const key = pickFailureKey(status, code);
    for (const locale of ['en', 'ar']) {
      const missing = [];
      const text = translate(dicts, locale, key, { when: 'x' }, (k) => missing.push(k));
      assert.deepEqual(missing, [], `${key} (${locale})`);
      assert.ok(text.length > 5);
    }
  }
  assert.equal(pickFailureKey(409, 'offer_expired'), 'offer.expired');
  assert.equal(pickFailureKey(409, 'slot_full'), 'offer.err_taken');
});

test('the proof line names who received it only when the server says', () => {
  assert.equal(proofKey({ delivered_at: 'x', received_by: 'Mariam', photo: null }), 'proof.delivered_by');
  assert.equal(proofKey({ delivered_at: 'x', received_by: null, photo: null }), 'proof.delivered');
  assert.equal(translate(dicts, 'en', 'proof.delivered_by', { when: '19 Nov', name: 'Mariam' }), 'Delivered on 19 Nov · received by Mariam');
  assert.match(translate(dicts, 'ar', 'proof.delivered_by', { when: '19 Nov', name: 'Mariam' }), /استلمه Mariam/);
});

test('the assembly line shows only an answer', () => {
  assert.equal(assemblyShown(null), false);
  assert.equal(assemblyShown({ status: 'pending', done_at: null, reason_label: null }), false);
  assert.equal(assemblyShown({ status: 'done', done_at: 'x', reason_label: null }), true);
  assert.equal(assemblyShown({ status: 'not_done', done_at: null, reason_label: 'No time' }), true);
  assert.equal(translate(dicts, 'en', 'assembly.not_done', { reason: 'No time' }), 'The assembly was not done: No time. The branch will contact you.');
});

test('the new sentences exist in English and Arabic with the same placeholders', () => {
  const keys = Object.keys(en).filter((k) => /^(offer|proof|assembly)\./.test(k));
  assert.ok(keys.length >= 20);
  for (const k of keys) {
    assert.ok(ar[k], `${k} in Arabic`);
    const ph = (s) => (s.match(/\{[a-z_]+\}/g) ?? []).sort().join(',');
    assert.equal(ph(ar[k]), ph(en[k]), `${k} placeholders`);
  }
});
