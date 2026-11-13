import test from 'node:test';
import assert from 'node:assert/strict';
import { FILTER_KEYS, activeFilterCount, flatCategories, patched, productParams, readFilters } from '../src/lib/shopQuery.ts';

const cat = (id, slug, children = []) => ({ id, slug, name: slug, description: null, image_url: null, icon_url: null, banner_url: null, children });
const CATS = [cat('1', 'living', [cat('11', 'sofas')]), cat('2', 'dining')];
const BRANDS = [{ id: 'b1', slug: 'nordic', name: 'Nordic', logo_url: null }];
const sp = (s) => new URLSearchParams(s);

test('flatCategories walks the tree depth first', () => {
  assert.deepEqual(flatCategories(CATS).map((c) => c.slug), ['living', 'sofas', 'dining']);
});

test('readFilters resolves slugs (and ids) and defaults the sort', () => {
  const f = readFilters(sp('category=sofas&brand=nordic&min=100&max=900&stock=1&new=1'), CATS, BRANDS);
  assert.equal(f.cat.id, '11');
  assert.equal(f.brand.id, 'b1');
  assert.equal(f.sort, '-popularity');
  assert.equal(f.min, '100');
  assert.ok(f.inStock && f.isNew && !f.onSale);
  assert.equal(readFilters(sp('category=2'), CATS, BRANDS).cat.slug, 'dining');
  assert.equal(readFilters(sp('q=sofa'), CATS, BRANDS).sort, 'relevance');
  assert.equal(readFilters(sp('category=nope'), CATS, BRANDS).cat, undefined);
});

test('productParams sends only what is set', () => {
  const f = readFilters(sp('category=sofas&brand=nordic&min=100&stock=1'), CATS, BRANDS);
  assert.deepEqual(productParams(f, 'br1', 2, 24), {
    q: undefined, category_id: '11', brand_id: ['b1'], sort: '-popularity', price_min: '100', price_max: undefined,
    in_stock: true, on_sale: undefined, new_arrival: undefined, branch_id: 'br1', page: 2, per_page: 24,
  });
  const plain = productParams(readFilters(sp('q=lamp'), CATS, BRANDS), null, 1, 1);
  assert.equal(plain.sort, 'relevance');
  assert.equal(plain.branch_id, undefined);
  assert.equal(productParams(readFilters(sp(''), CATS, BRANDS), null, 1, 1).sort, '-popularity');
});

test('patched copies, sets, and removes on null or empty', () => {
  const base = sp('category=sofas&sort=price');
  const next = patched(base, { category: null, min: '50', sort: '' });
  assert.equal(next.toString(), 'min=50');
  assert.equal(base.toString(), 'category=sofas&sort=price', 'the original is untouched');
});

test('activeFilterCount counts the price range once and ignores q / sort', () => {
  assert.equal(activeFilterCount(sp('')), 0);
  assert.equal(activeFilterCount(sp('q=x&sort=price')), 0);
  assert.equal(activeFilterCount(sp('category=a&brand=b&min=1&max=2&stock=1')), 4);
  assert.equal(activeFilterCount(sp('max=2')), 1);
  assert.deepEqual([...FILTER_KEYS], ['category', 'brand', 'min', 'max', 'stock', 'sale', 'new']);
});
