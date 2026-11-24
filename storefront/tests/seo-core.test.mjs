import test from 'node:test';
import assert from 'node:assert/strict';
import { SITE_NAME, langFromSearch, pageTitle } from '../src/lib/seoCore.ts';

test('a page title carries the site name in the shopper language', () => {
  assert.equal(pageTitle('Sofas', 'en'), 'Sofas · Al Bahar Home & Living');
  assert.equal(pageTitle('الكنب', 'ar'), 'الكنب · البحر للمنزل والمعيشة');
});

test('a page without a title of its own shows just the site name', () => {
  assert.equal(pageTitle(undefined, 'en'), SITE_NAME.en);
  assert.equal(pageTitle(null, 'ar'), SITE_NAME.ar);
  assert.equal(pageTitle('', 'en'), SITE_NAME.en);
});

test('the title stays within what a search result shows for typical names', () => {
  assert.ok(pageTitle('Corniche Modular Sectional', 'en').length <= 60);
});

test('the Arabic address of a page asks for Arabic; anything else asks for nothing', () => {
  assert.equal(langFromSearch('?category=sofas&lang=ar'), 'ar');
  assert.equal(langFromSearch('?lang=en'), 'en');
  assert.equal(langFromSearch('?lang=fr'), null);
  assert.equal(langFromSearch(''), null);
  assert.equal(langFromSearch('?category=sofas'), null);
});

test('editor headings never skip a level under the page heading', async () => {
  const { fixHeadingLevels } = await import('../src/lib/seoCore.ts');
  assert.deepEqual(fixHeadingLevels([3, 3, 4, 2]), [2, 2, 3, 2]);   // body starts with h3: it becomes h2
  assert.deepEqual(fixHeadingLevels([2, 4, 4]), [2, 3, 3]);          // h2 then h4: h4 steps up to h3
  assert.deepEqual(fixHeadingLevels([2, 3, 2, 3]), [2, 3, 2, 3]);    // a well-formed outline is left alone
  assert.deepEqual(fixHeadingLevels([]), []);
  assert.deepEqual(fixHeadingLevels([4], 3), [3]);                   // under an h2 the first heading is at most h3
});
