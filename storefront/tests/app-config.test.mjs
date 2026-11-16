import test from 'node:test';
import assert from 'node:assert/strict';
import { altPhoneEnabled, parseCachedConfig } from '../src/lib/appConfigCore.ts';

// The dashboard switch "Ask for an alternative phone number": only an explicit false turns the field off.

test('the alternative number is on unless the server says false', () => {
  assert.equal(altPhoneEnabled({ alternate_phone_enabled: false }), false);
  assert.equal(altPhoneEnabled({ alternate_phone_enabled: true }), true);
  assert.equal(altPhoneEnabled({}), true);
  assert.equal(altPhoneEnabled(null), true);
  assert.equal(altPhoneEnabled(undefined), true);
  assert.equal(altPhoneEnabled({ alternate_phone_enabled: 'false' }), true); // not a boolean: ignored
  assert.equal(altPhoneEnabled({ alternate_phone_enabled: 0 }), true);
});

test('the value remembered on this device is read safely', () => {
  assert.deepEqual(parseCachedConfig(JSON.stringify({ alternate_phone_enabled: false })), { alternate_phone_enabled: false });
  assert.deepEqual(parseCachedConfig(JSON.stringify({ alternate_phone_enabled: true })), { alternate_phone_enabled: true });
  for (const junk of [null, '', 'not json', '[]', '"x"', '123', 'null']) assert.deepEqual(parseCachedConfig(junk), {}, String(junk));
  assert.equal(parseCachedConfig(JSON.stringify({ alternate_phone_enabled: 'yes' })).alternate_phone_enabled, undefined);
  assert.equal(altPhoneEnabled(parseCachedConfig('garbage')), true);
});
