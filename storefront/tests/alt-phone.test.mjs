import test from 'node:test';
import assert from 'node:assert/strict';
import { altPhoneDone, altPhoneFields, altPhoneStatus, nationalDigits, toE164 } from '../src/lib/altPhone.ts';

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
