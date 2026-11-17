import test from 'node:test';
import assert from 'node:assert/strict';
import { readdirSync, readFileSync } from 'node:fs';
import { join, relative, sep } from 'node:path';
import { fileURLToPath } from 'node:url';
import { altPhoneAsked, altPhoneDone, altPhoneFields, altPhoneReady, altPhoneRequest, altPhoneStatus, nationalDigits, toE164 } from '../src/lib/altPhone.ts';
import { altPhoneEnabled } from '../src/lib/appConfigCore.ts';

// The customer's second number for the driver: the same rule as the app and the server.

test('Qatar mobile numbers are accepted in any common spelling, Arabic digits included', () => {
  for (const typed of ['55987654', '+974 5598 7654', '00974 55987654', '٥٥٩٨٧٦٥٤', '+٩٧٤٥٥٩٨٧٦٥٤', '33 987654', '6612 3456', '74123456', '۵۵۹۸۷۶۵۴']) {
    assert.match(toE164(typed) ?? '', /^\+974[3567]\d{7}$/, typed);
  }
  assert.equal(toE164('٥٥٩٨٧٦٥٤'), '+97455987654');
});

test('anything else is not a Qatar mobile number', () => {
  for (const typed of ['4412345', '12345678', '4455987654', 'call me', '+1 202 555 0143', '5598765', '555987654321', '+974 2012 3456', '']) {
    assert.equal(toE164(typed), null, typed);
  }
});

test('status: valid, the tick, missing, invalid, the main number', () => {
  const main = '+97455123456';
  assert.equal(altPhoneStatus('55987654', false, main), 'valid');
  assert.equal(altPhoneStatus('', true, main), 'declined');
  assert.equal(altPhoneStatus('', false, main), 'missing');
  assert.equal(altPhoneStatus('   ', false, main), 'missing');
  assert.equal(altPhoneStatus('5598', false, main), 'invalid');
  assert.equal(altPhoneStatus('55123456', false, main), 'same');
  assert.equal(altPhoneStatus('+974 5512 3456', false, main), 'same');
  assert.equal(altPhoneStatus('٥٥١٢٣٤٥٦', false, main), 'same');
  assert.equal(altPhoneStatus('55123456', false, null), 'valid', 'no main number to compare');
});

test('typing wins over the tick; only a valid number or the tick lets the order go ahead', () => {
  assert.equal(altPhoneStatus('55987654', true), 'valid');
  assert.deepEqual(['valid', 'declined', 'missing', 'invalid', 'same'].map((s) => altPhoneDone(s)), [true, true, false, false, false]);
});

test('request fields: both keys once answered, nothing before', () => {
  const main = '+97455123456';
  assert.deepEqual(altPhoneFields('٥٥٩٨٧٦٥٤', false, main), { alternate_phone: '+97455987654', no_alternate_phone: false });
  assert.deepEqual(altPhoneFields('', true, main), { alternate_phone: null, no_alternate_phone: true });
  assert.deepEqual(altPhoneFields('', false, main), {});
  assert.deepEqual(altPhoneFields('123', false, main), {});
  assert.deepEqual(altPhoneFields('55123456', false, main), {});
});

test('a saved number starts the field as its 8 national digits', () => {
  assert.equal(nationalDigits('+97455987654'), '55987654');
  assert.equal(nationalDigits(null), '');
  assert.equal(nationalDigits(undefined), '');
  assert.equal(nationalDigits('garbage'), '');
});

/* ───────────── the dashboard switch "Ask for an alternative phone number" (docs/api/CHECKOUT_FEES_V2.md, section 4) ───────────── */

test('the question is asked on delivery orders while the switch is on, and nowhere else', () => {
  assert.equal(altPhoneAsked(true, 'delivery'), true);
  assert.equal(altPhoneAsked(false, 'delivery'), false);
  assert.equal(altPhoneAsked(true, 'pickup'), false);
  assert.equal(altPhoneAsked(false, 'pickup'), false);
});

test('switch off (or a server that never said): the app config decides, an explicit false only', () => {
  assert.equal(altPhoneAsked(altPhoneEnabled({ alternate_phone_enabled: false }), 'delivery'), false);
  assert.equal(altPhoneAsked(altPhoneEnabled({ alternate_phone_enabled: true }), 'delivery'), true);
  assert.equal(altPhoneAsked(altPhoneEnabled({}), 'delivery'), true, 'an older server has no switch: on, as before');
});

test('not asked: the order is never held back by the number, whatever was typed', () => {
  for (const s of ['valid', 'declined', 'missing', 'invalid', 'same']) assert.equal(altPhoneReady(false, s), true, s);
});

test('asked: only a good number or the tick lets the order go ahead', () => {
  assert.deepEqual(['valid', 'declined', 'missing', 'invalid', 'same'].map((s) => altPhoneReady(true, s)), [true, true, false, false, false]);
});

test('switch off: nothing about the number is sent, even if one was typed or saved earlier', () => {
  const main = '+97455123456';
  assert.deepEqual(altPhoneRequest(false, '55987654', false, main), {});
  assert.deepEqual(altPhoneRequest(false, '', true, main), {});
  assert.deepEqual(altPhoneRequest(altPhoneAsked(true, 'pickup'), '55987654', false, main), {}, 'pickup never asks');
});

test('switch on: both keys go with the order once answered', () => {
  const main = '+97455123456';
  assert.deepEqual(altPhoneRequest(true, '55987654', false, main), { alternate_phone: '+97455987654', no_alternate_phone: false });
  assert.deepEqual(altPhoneRequest(true, '', true, main), { alternate_phone: null, no_alternate_phone: true });
  assert.deepEqual(altPhoneRequest(true, '', false, main), {});
});

// Where the field may live: a page that shows it without the switch would break the promise above, so the places are listed and each is checked.
const SRC = join(fileURLToPath(new URL('..', import.meta.url)), 'src');
const files = (dir) => readdirSync(dir, { withFileTypes: true, recursive: true }).filter((f) => f.isFile() && /\.(ts|tsx)$/.test(f.name) && !f.parentPath.includes(`${sep}assets`) && !f.parentPath.includes(`${sep}locales`)).map((f) => join(f.parentPath, f.name));
const rel = (f) => relative(SRC, f).split(sep).join('/');

test('only the checkout page and the profile form ask for the number, and both are behind the switch', () => {
  const asking = files(SRC).filter((f) => /alt_label|alternate_phone|altPhone/.test(readFileSync(f, 'utf8'))).map(rel).sort();
  assert.deepEqual(asking, ['components/ProfileForms.tsx', 'lib/altPhone.ts', 'lib/appConfig.ts', 'lib/appConfigCore.ts', 'lib/auth.tsx', 'lib/types.ts', 'pages/Checkout.tsx']);
  for (const page of ['components/ProfileForms.tsx', 'pages/Checkout.tsx']) assert.match(readFileSync(join(SRC, page), 'utf8'), /useAltPhoneEnabled\(\)/, page);
});

test('the address form, the address window and the saved address list have no alternative number field', () => {
  for (const page of ['components/AddressForm.tsx', 'components/AddressDrawer.tsx', 'components/windows.tsx', 'pages/Account.tsx']) {
    assert.doesNotMatch(readFileSync(join(SRC, page), 'utf8'), /alt_label|alternate_phone|altPhone|AltPhone/, page);
  }
});

test('the checkout input and the profile input are rendered only when the switch allows it', () => {
  const checkout = readFileSync(join(SRC, 'pages/Checkout.tsx'), 'utf8');
  const profile = readFileSync(join(SRC, 'components/ProfileForms.tsx'), 'utf8');
  assert.match(checkout, /\{altOn && <div[^>]*data-testid="alt-phone"/);
  assert.match(profile, /\{altOn && <Input[^>]*profile\.alt_label/);
});
