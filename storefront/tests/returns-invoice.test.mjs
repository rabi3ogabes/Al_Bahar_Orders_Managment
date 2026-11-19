import test from 'node:test';
import assert from 'node:assert/strict';
import { returnFailureKey, returnStatusKey, returnTone, returnableOf } from '../src/lib/returns.ts';
import { invoiceFailure } from '../src/lib/invoice.ts';
import { translate } from '../src/lib/i18nCore.ts';
import { en } from '../src/locales/en.ts';
import { ar } from '../src/locales/ar.ts';

const dicts = { en, ar };
const t = (k) => translate(dicts, 'en', k);

test('every state of a return has a sentence in both languages, with the date slots filled', () => {
  for (const status of ['requested', 'approved', 'rejected', 'received', 'refunded', 'closed', 'something_new']) {
    const key = returnStatusKey(status);
    for (const locale of ['en', 'ar']) {
      const text = translate(dicts, locale, key, { d: '3 Nov 2026', e: '4 Nov 2026' });
      assert.ok(text.length > 10, `${status} ${locale}`);
      assert.doesNotMatch(text, /return\.status|\{[a-z]\}/, `${status} ${locale}`);
    }
  }
  assert.equal(returnStatusKey('requested'), 'return.status.requested');
  assert.equal(returnStatusKey('nonsense'), 'return.status.other');
  assert.match(translate(dicts, 'en', 'return.status.requested', { d: '3 Nov 2026' }), /3 Nov 2026/);
});

test('a refused request is a warning, one waiting on us is calm, one that moved on is good news', () => {
  assert.deepEqual(['requested', 'approved', 'rejected', 'received', 'refunded', 'closed'].map(returnTone), ['info', 'info', 'warning', 'success', 'success', 'success']);
});

test('the pieces still returnable come from the server, or from the order for an older server', () => {
  assert.equal(returnableOf({ quantity: 3, returnable_quantity: 1 }), 1);
  assert.equal(returnableOf({ quantity: 3, returnable_quantity: 0 }), 0);
  assert.equal(returnableOf({ quantity: 3, quantity_cancelled: 1, quantity_returned: 1 }), 1);
  assert.equal(returnableOf({ quantity: 1, quantity_returned: 4 }), 0);
});

test('a failed return request is explained in plain words, never with the server\'s bare sentence', () => {
  assert.equal(returnFailureKey(403, 'forbidden', {}), 'return.err_closed');
  assert.equal(returnFailureKey(422, 'http_error', {}), 'return.err_not_allowed');
  assert.equal(returnFailureKey(422, 'validation_failed', { items: ['x'] }), 'return.err_items');
  assert.equal(returnFailureKey(422, 'validation_failed', { 'items.0.quantity': ['x'] }), 'return.err_items');
  assert.equal(returnFailureKey(422, 'validation_failed', { reason_code: ['x'] }), 'return.err_reason');
  assert.equal(returnFailureKey(429, 'too_many_requests', {}), 'return.err_busy');
  assert.equal(returnFailureKey(401, 'unauthenticated', {}), 'return.err_signin');
  assert.equal(returnFailureKey(500, 'server_error', {}), 'error.generic');
  for (const k of ['return.err_closed', 'return.err_signin', 'return.err_items', 'return.err_reason', 'return.err_not_allowed', 'return.err_busy']) assert.ok(en[k] && ar[k], k);
});

test('a failed invoice download says what to do', () => {
  const err = (status, code = 'x', message = 'server words') => ({ status, code, message });
  assert.equal(invoiceFailure(err(401), t), en['order.invoice_signin']);
  assert.equal(invoiceFailure(err(404), t), en['order.invoice_missing']);
  assert.equal(invoiceFailure(err(409, 'conflict', 'Payment is not done yet.'), t), 'Payment is not done yet.');
  assert.equal(invoiceFailure(err(409, 'conflict', ''), t), en['order.invoice_unavailable']);
  assert.equal(invoiceFailure(err(0, 'network_error', 'We could not reach the server.'), t), 'We could not reach the server.');
  assert.equal(invoiceFailure(err(503, 'session_refresh_unavailable', 'Please try again in a moment.'), t), 'Please try again in a moment.');
  assert.equal(invoiceFailure(err(502, 'http_error'), t), en['order.invoice_busy']);
  assert.equal(invoiceFailure(err(429, 'too_many_requests'), t), en['order.invoice_busy']);
  assert.equal(invoiceFailure(err(418), t), en['order.invoice_unavailable']);
  assert.equal(invoiceFailure(new Error('boom'), t), en['order.invoice_unavailable']);
  assert.equal(invoiceFailure(null, t), en['order.invoice_unavailable']);
});
