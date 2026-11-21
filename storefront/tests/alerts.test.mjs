import test from 'node:test';
import assert from 'node:assert/strict';
import { DEFAULT_ALERTS_CONFIG, alertStatus, findAlert, priceMove, productControls, productPath } from '../src/lib/alertsCore.ts';
import { en } from '../src/locales/en.ts';
import { ar } from '../src/locales/ar.ts';

const money = (amount) => ({ amount, currency: 'QAR', formatted: `QAR ${amount}` });
const alert = (type, variantId, over = {}) => ({
  id: `${type}-${variantId}`, type, product: { id: 'p1', slug: 'oslo-sofa', name: 'Oslo Sofa', image_url: null, thumb_url: null }, variant: { id: variantId, name: null },
  branch_id: null, reference_price: money('200.00'), current_price: money('200.00'), in_stock: true, since: '2026-11-19T12:00:00+03:00', expires_at: '2027-02-17T12:00:00+03:00', ...over,
});
const ON = { ...DEFAULT_ALERTS_CONFIG, price_drop: true };

test('until the server answers, back-in-stock is offered and price alerts are not', () => {
  assert.deepEqual([DEFAULT_ALERTS_CONFIG.back_in_stock, DEFAULT_ALERTS_CONFIG.price_drop], [true, false]);
});

test('the bell is for what cannot be bought, and shows the subscribed state', () => {
  assert.equal(productControls({ canBuy: false, cfg: ON, variantId: 'v1' }).bell, 'notify');
  assert.equal(productControls({ canBuy: false, cfg: ON, variantId: 'v1', list: [alert('back_in_stock', 'v1')] }).bell, 'subscribed');
  assert.equal(productControls({ canBuy: false, cfg: ON, variantId: 'v2', list: [alert('back_in_stock', 'v1')] }).bell, 'notify', 'another variant is its own alert');
  assert.equal(productControls({ canBuy: true, cfg: ON, variantId: 'v1' }).bell, 'hidden');
  assert.equal(productControls({ canBuy: false, cfg: ON, variantId: null }).bell, 'hidden', 'no variant chosen yet');
  assert.equal(productControls({ canBuy: false, cfg: { ...ON, back_in_stock: false }, variantId: 'v1' }).bell, 'hidden');
});

test('the price choice is for what can be bought and only while the owner has it on, but a watcher can always stop', () => {
  assert.equal(productControls({ canBuy: true, cfg: ON, variantId: 'v1' }).price, 'offer');
  assert.equal(productControls({ canBuy: true, cfg: DEFAULT_ALERTS_CONFIG, variantId: 'v1' }).price, 'hidden');
  assert.equal(productControls({ canBuy: false, cfg: ON, variantId: 'v1' }).price, 'hidden', 'nobody can use a price they cannot buy at');
  const watching = [alert('price_drop', 'v1')];
  assert.equal(productControls({ canBuy: true, cfg: ON, variantId: 'v1', list: watching }).price, 'subscribed');
  assert.equal(productControls({ canBuy: true, cfg: DEFAULT_ALERTS_CONFIG, variantId: 'v1', list: watching }).price, 'subscribed');
  assert.equal(productControls({ canBuy: false, cfg: DEFAULT_ALERTS_CONFIG, variantId: 'v1', list: watching }).price, 'subscribed');
});

test('findAlert matches variant and kind only', () => {
  const list = [alert('back_in_stock', 'v1'), alert('price_drop', 'v2')];
  assert.equal(findAlert(list, 'v1', 'back_in_stock')?.id, 'back_in_stock-v1');
  assert.equal(findAlert(list, 'v1', 'price_drop'), undefined);
  assert.equal(findAlert(undefined, 'v1', 'price_drop'), undefined);
  assert.equal(findAlert(list, null, 'price_drop'), undefined);
});

test('priceMove tells a drop from a rise and survives missing or odd prices', () => {
  assert.deepEqual(priceMove(alert('price_drop', 'v', { current_price: money('150.00') })), { percent: -25, dropped: true });
  assert.deepEqual(priceMove(alert('price_drop', 'v', { current_price: money('230.00') })), { percent: 15, dropped: false });
  assert.deepEqual(priceMove(alert('price_drop', 'v')), { percent: 0, dropped: false });
  assert.equal(priceMove(alert('price_drop', 'v', { current_price: null })), null);
  assert.equal(priceMove(alert('price_drop', 'v', { reference_price: money('0.00') })), null);
  assert.equal(priceMove(alert('price_drop', 'v', { reference_price: money('abc') })), null);
});

test('the status line follows the stock', () => {
  assert.equal(alertStatus({ type: 'back_in_stock', in_stock: false }), 'waiting_stock');
  assert.equal(alertStatus({ type: 'back_in_stock', in_stock: true }), 'available');
  assert.equal(alertStatus({ type: 'price_drop', in_stock: true }), 'watching');
  assert.equal(alertStatus({ type: 'price_drop', in_stock: false }), 'sold_out');
});

test('a product link uses the slug, or the id when there is none (Arabic slugs too)', () => {
  assert.equal(productPath(alert('back_in_stock', 'v1')), '/products/oslo-sofa');
  assert.equal(productPath({ product: { id: 'p9', slug: null } }), '/products/p9');
  assert.equal(productPath({ product: { id: 'p9', slug: 'كنبة-أوسلو-1' } }), '/products/كنبة-أوسلو-1');
});

test('every alert text exists in English and Arabic', () => {
  const keys = (d) => Object.keys(d).filter((k) => k.startsWith('alerts.')).sort();
  assert.ok(keys(en).length >= 12);
  assert.deepEqual(keys(ar), keys(en));
  for (const k of keys(en)) assert.ok(en[k].trim() && ar[k].trim(), k);
});
