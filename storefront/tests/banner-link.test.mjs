import test from 'node:test';
import assert from 'node:assert/strict';
import { bannerTarget, isHttpsUrl } from '../src/lib/bannerLink.ts';

// Where a home slide leads, and what the store refuses to follow.

test('a slide without a link is not clickable', () => {
  assert.equal(bannerTarget(null), null);
  assert.equal(bannerTarget(undefined), null);
  assert.equal(bannerTarget({ type: 'category', value: '' }), null);
  assert.equal(bannerTarget({ type: 'category', value: '   ' }), null);
});

test('each kind of link goes to its store route', () => {
  const id = '0194d2f4-8f3e-7000-8000-000000000001';
  assert.deepEqual(bannerTarget({ type: 'category', value: id }), { kind: 'internal', to: `/shop?category=${id}` });
  assert.deepEqual(bannerTarget({ type: 'brand', value: id }), { kind: 'internal', to: `/shop?brand=${id}` });
  assert.deepEqual(bannerTarget({ type: 'product', value: id }), { kind: 'internal', to: `/products/${id}` });
  assert.deepEqual(bannerTarget({ type: 'auction', value: id }), { kind: 'internal', to: `/auctions/${id}` });
  assert.deepEqual(bannerTarget({ type: 'page', value: 'about-us' }), { kind: 'internal', to: '/pages/about-us' });
});

test('search words are encoded, Arabic included', () => {
  assert.deepEqual(bannerTarget({ type: 'search', value: 'sofa & sale' }), { kind: 'internal', to: '/shop?q=sofa%20%26%20sale' });
  assert.equal(bannerTarget({ type: 'search', value: 'كنب' }).to, `/shop?q=${encodeURIComponent('كنب')}`);
});

test('a value can never break out of its path', () => {
  assert.equal(bannerTarget({ type: 'page', value: '../admin' }).to, '/pages/..%2Fadmin');
  assert.equal(bannerTarget({ type: 'product', value: 'a/b?c=d#e' }).to, '/products/a%2Fb%3Fc%3Dd%23e');
});

test('a web address opens outside only when it is a clean https one', () => {
  assert.deepEqual(bannerTarget({ type: 'url', value: 'https://example.com/offers?x=1' }), { kind: 'external', href: 'https://example.com/offers?x=1' });
  for (const bad of ['javascript:alert(1)', 'JaVaScRiPt:alert(1)', 'http://example.com', 'data:text/html,hi', 'ftp://example.com', '//example.com', '/shop', 'https://', 'https://user:pw@example.com',
    'https://exa mple.com', "https://example.com/\nx", 'example.com', '']) {
    assert.equal(bannerTarget({ type: 'url', value: bad }), null, bad);
  }
  assert.equal(isHttpsUrl('https://example.com'), true);
  assert.equal(isHttpsUrl(`https://example.com/${'a'.repeat(1000)}`), false);
});

test('a kind the store does not know is ignored, not guessed', () => {
  assert.equal(bannerTarget({ type: 'telegram', value: 'x' }), null);
  assert.equal(bannerTarget({ type: 'collection', value: 'x' }), null);
});
