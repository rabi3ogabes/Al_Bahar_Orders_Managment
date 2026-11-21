import test from 'node:test';
import assert from 'node:assert/strict';
import { CLAIM_TYPES, MAX_CLAIM_PHOTOS, RETURN_STATUSES, claimIsOpen, claimNeedsPhoto, claimStepKey, photoProblem, refundHowKey, returnFailureKey } from '../src/lib/returns.ts';
import { translate } from '../src/lib/i18nCore.ts';
import { en } from '../src/locales/en.ts';
import { ar } from '../src/locales/ar.ts';

const dicts = { en, ar };
const jpg = (size = 1000) => ({ type: 'image/jpeg', size });

test('a damaged or wrong item needs a photo, the other kinds do not', () => {
  assert.deepEqual(CLAIM_TYPES.map((c) => [c.code, claimNeedsPhoto(c.code)]), [['damaged', true], ['wrong_item', true], ['missing_item', false], ['quality', false], ['changed_mind', false]]);
  assert.equal(claimNeedsPhoto('something_else'), false);
});

test('photos: only JPEG, PNG or WebP, under 8 MB each, at most five in all', () => {
  assert.equal(photoProblem([jpg(), { type: 'image/png', size: 5 }, { type: 'image/webp', size: 5 }]), null);
  assert.equal(photoProblem([{ type: 'image/svg+xml', size: 10 }]), 'return.err_photo_type');
  assert.equal(photoProblem([{ type: 'application/pdf', size: 10 }]), 'return.err_photo_type');
  assert.equal(photoProblem([jpg(8 * 1024 * 1024 + 1)]), 'return.err_photo_size');
  assert.equal(photoProblem([jpg(8 * 1024 * 1024)]), null);
  assert.equal(photoProblem(Array.from({ length: 5 }, () => jpg())), null);
  assert.equal(photoProblem(Array.from({ length: 6 }, () => jpg())), 'return.err_photo_count');
  assert.equal(photoProblem([jpg(), jpg()], 4), 'return.err_photo_count');
  assert.equal(photoProblem([jpg()], MAX_CLAIM_PHOTOS - 1), null);
});

test('only a claim nobody decided can still take photos, a note, or be cancelled', () => {
  assert.deepEqual(RETURN_STATUSES.filter((s) => claimIsOpen({ status: s })), ['requested', 'under_review']);
});

test('every timeline step, refund way, claim type and message has words in both languages', () => {
  const steps = ['received', 'under_review', 'decision', 'approved', 'rejected', 'handover', 'pickup', 'branch', 'item_received', 'refunded', 'cancelled', 'brand_new_step'];
  for (const locale of ['en', 'ar']) {
    for (const s of steps) assert.ok(translate(dicts, locale, claimStepKey(s)).length > 2, `${locale} step ${s}`);
    for (const m of ['original', 'manual_cash', 'manual_bank_transfer', 'other']) assert.ok(translate(dicts, locale, refundHowKey(m)).length > 2, `${locale} refund ${m}`);
    for (const c of CLAIM_TYPES) for (const k of [`return.type.${c.code}`, `return.type.${c.code}.hint`]) assert.ok(translate(dicts, locale, k).length > 5, `${locale} ${k}`);
    assert.match(translate(dicts, locale, 'return.step.refund_done', { amount: 'QAR 500.00', how: 'x' }), /QAR 500\.00/);
    assert.match(translate(dicts, locale, 'return.step.branch_detail', { branch: 'Al Markhiya' }), /Al Markhiya/);
    assert.match(translate(dicts, locale, 'return.window_until', { d: '2 Dec 2026' }), /2 Dec 2026/);
  }
  assert.equal(claimStepKey('pickup'), 'return.step.pickup');
  assert.equal(claimStepKey('nonsense'), 'return.step.other');
  assert.equal(refundHowKey('nonsense'), 'return.refund.other');
});

test('the English and Arabic sentences of the claim flow carry the same placeholders and no leftovers', () => {
  const keys = Object.keys(en).filter((k) => k.startsWith('return.'));
  assert.ok(keys.length > 80);
  for (const k of keys) {
    assert.ok(ar[k], `ar ${k}`);
    const slots = (s) => [...s.matchAll(/\{(\w+)\}/g)].map((m) => m[1]).sort().join(',');
    assert.equal(slots(ar[k]), slots(en[k]), k);
  }
});

test('the new failures of a claim are explained in plain words', () => {
  assert.equal(returnFailureKey(422, 'validation_failed', { photos: ['x'] }), 'return.err_photos');
  assert.equal(returnFailureKey(422, 'validation_failed', { 'photos.0': ['x'] }), 'return.err_photos');
  assert.equal(returnFailureKey(422, 'validation_failed', { reason: ['x'] }), 'return.err_note');
  assert.equal(returnFailureKey(409, 'claim_not_editable', {}), 'return.err_locked');
  for (const k of ['return.err_photos', 'return.err_note', 'return.err_locked', 'return.err_photo_type', 'return.err_photo_size', 'return.err_photo_count']) assert.ok(en[k] && ar[k], k);
});
