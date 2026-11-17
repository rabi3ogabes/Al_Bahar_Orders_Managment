import test from 'node:test';
import assert from 'node:assert/strict';
import { readdirSync, readFileSync } from 'node:fs';
import { join, relative, sep } from 'node:path';
import { fileURLToPath } from 'node:url';
import { en } from '../src/locales/en.ts';
import { ar } from '../src/locales/ar.ts';

// The wording of the checkout fees (docs/api/CHECKOUT_FEES_V2.md): no cash-on-delivery fee, no minimum order, an extra delivery fee for bulky items.

const SRC = join(fileURLToPath(new URL('..', import.meta.url)), 'src');
const files = (dir) => readdirSync(dir, { withFileTypes: true, recursive: true }).filter((f) => f.isFile() && /\.(ts|tsx)$/.test(f.name) && !f.parentPath.includes(`${sep}assets`)).map((f) => join(f.parentPath, f.name));
const placeholders = (s) => [...s.matchAll(/\{(\w+)\}/g)].map((m) => m[1]).sort().join(',');

test('English and Arabic have the same keys, each with the same {placeholders}', () => {
  assert.deepEqual(Object.keys(en).sort(), Object.keys(ar).sort());
  for (const k of Object.keys(en)) assert.equal(placeholders(ar[k]), placeholders(en[k]), k);
});

// The one that stays: the totals of an order placed before the fee was removed still list it (docs/api/CHECKOUT_FEES_V2.md, section 1: "Orders already placed keep their stored cod_fee").
const HISTORY = 'order.total.cod_fee';

test('the checkout has no cash-on-delivery fee label and no minimum-order wording, in either language', () => {
  for (const [name, d] of [['en', en], ['ar', ar]]) {
    assert.equal('checkout.cod_fee' in d, false, `${name}: checkout.cod_fee`);
    for (const [k, v] of Object.entries(d)) {
      if (k === HISTORY) continue;
      assert.doesNotMatch(k, /min_order|below_min|minimum_order|^cart\.min|^checkout\.min|cod_fee/, `${name}: ${k}`);
      assert.doesNotMatch(v, name === 'en' ? /minimum order|cash.on.delivery fee|COD fee/i : /الحد الأدنى للطلب|رسوم الدفع عند الاستلام/, `${name}: ${k}`);
    }
  }
});

test('the cash-on-delivery payment option itself stays', () => {
  for (const d of [en, ar]) for (const k of ['pay.cod', 'pay.cod_text']) assert.ok(d[k]?.trim(), k);
});

test('no screen reads a cash-on-delivery fee or a minimum order from the quote', () => {
  for (const f of files(SRC)) {
    const text = readFileSync(f, 'utf8');
    const name = relative(SRC, f).split(sep).join('/');
    assert.doesNotMatch(text, /below_min_order|min_order_amount|minOrder|totals\.cod_fee|checkout\.cod_fee/, name);
  }
});

test('the extra delivery fee wording exists in both languages', () => {
  for (const d of [en, ar]) {
    for (const k of ['checkout.extra_delivery', 'checkout.extra_each', 'checkout.extra_once', 'product.extra_fee_unit', 'product.extra_fee_line']) assert.ok(d[k]?.trim(), k);
    assert.match(d['checkout.extra_each'], /\{n\}.*\{fee\}/);
    assert.match(d['product.extra_fee_unit'], /\{fee\}/);
    assert.match(d['product.extra_fee_line'], /\{fee\}/);
  }
});
