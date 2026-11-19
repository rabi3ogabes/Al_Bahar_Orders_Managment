import test from 'node:test';
import assert from 'node:assert/strict';
import { FILTER_KEYS, activeFilterCount, attrIds, flatCategories, mergeAttributeFilters, patched, productParams, readFilters, toggleAttr } from '../src/lib/shopQuery.ts';

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
    in_stock: true, on_sale: undefined, new_arrival: undefined, attribute_values: undefined, branch_id: 'br1', page: 2, per_page: 24,
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
  assert.deepEqual([...FILTER_KEYS], ['category', 'brand', 'min', 'max', 'stock', 'sale', 'new', 'attr']);
});

// ── Colour / material / size ────────────────────────────────────────────────────────────────────────────────────────────

const group = (code, type, values) => ({ attribute: { code, name: code, type }, values: values.map(([id, name, hex = null]) => ({ id, code: name.toLowerCase(), name, color_hex: hex })) });

test('the chosen attribute values come from the URL as whole numbers only, each once', () => {
  assert.deepEqual(attrIds(sp('attr=12,15,31')), ['12', '15', '31']);
  assert.deepEqual(attrIds(sp('attr=12,12, 15 ,x,1.5,-3,,7e3,99999999999')), ['12', '15']);
  assert.deepEqual(attrIds(sp('')), []);
  assert.equal(attrIds(sp(`attr=${Array.from({ length: 80 }, (_, i) => i + 1).join(',')}`)).length, 30);
});

test('attribute values are read into the filters and sent to the API as attribute_values[]', () => {
  const f = readFilters(sp('category=sofas&attr=3,9'), CATS, BRANDS);
  assert.deepEqual(f.attrs, ['3', '9']);
  assert.deepEqual(productParams(f, null, 1, 24).attribute_values, ['3', '9']);
  assert.equal(productParams(readFilters(sp('category=sofas'), CATS, BRANDS), null, 1, 24).attribute_values, undefined);
});

test('choosing a value adds it, choosing it again takes it off, and the last one removes the key', () => {
  assert.deepEqual(toggleAttr([], '3'), { attr: '3' });
  assert.deepEqual(toggleAttr(['3'], '9'), { attr: '3,9' });
  assert.deepEqual(toggleAttr(['3', '9'], '3'), { attr: '9' });
  assert.deepEqual(toggleAttr(['9'], '9'), { attr: null });
  assert.equal(patched(sp('category=sofas&attr=9'), toggleAttr(['9'], '9')).toString(), 'category=sofas');
});

test('each chosen value counts as one filter, and clearing the filters clears them', () => {
  assert.equal(activeFilterCount(sp('attr=3,9,12')), 3);
  assert.equal(activeFilterCount(sp('category=a&attr=3,9&min=5')), 4);
  const cleared = patched(sp('category=sofas&attr=3,9&q=lamp'), Object.fromEntries(FILTER_KEYS.map((k) => [k, null])));
  assert.equal(cleared.toString(), 'q=lamp');
});

test('the colour list of several sections becomes one list: an attribute once, its values joined without repeats', () => {
  const merged = mergeAttributeFilters([
    [group('colour', 'color', [[1, 'Grey', '#888'], [2, 'Beige', '#d8c7a5']]), group('material', 'select', [[10, 'Linen']])],
    [group('colour', 'color', [[2, 'Beige', '#d8c7a5'], [3, 'Navy', '#123']]), group('size', 'select', [])],
    [],
  ]);
  assert.deepEqual(merged.map((g) => g.attribute.code), ['colour', 'material'], 'an attribute with no values is left out');
  assert.deepEqual(merged[0].values.map((v) => v.id), [1, 2, 3]);
  assert.deepEqual(mergeAttributeFilters([]), []);
});
