import test from 'node:test';
import assert from 'node:assert/strict';
import { aspectSize, indexFromScroll, normaliseSlider, scrollLeftFor, stepForKey, wrapIndex } from '../src/lib/sliderCore.ts';

// The arithmetic behind the home slider: settings, going round, scroll positions (also right to left) and the arrow keys.

test('settings always come out complete and in range', () => {
  assert.deepEqual(normaliseSlider(undefined), { autoplay: true, interval: 5, aspect: '16:9', dots: true, show_titles: true, rounded: true });
  assert.deepEqual(normaliseSlider({ autoplay: false, interval: 8, aspect: '3:1', dots: false, show_titles: false, rounded: false }), { autoplay: false, interval: 8, aspect: '3:1', dots: false, show_titles: false, rounded: false });
  assert.equal(normaliseSlider({ interval: 1 }).interval, 3);
  assert.equal(normaliseSlider({ interval: 99 }).interval, 15);
  assert.equal(normaliseSlider({ interval: 'abc' }).interval, 5);
  assert.equal(normaliseSlider({ interval: 7.6 }).interval, 8);
  assert.equal(normaliseSlider({ aspect: '7:1' }).aspect, '16:9');
  assert.equal(normaliseSlider({ aspect: 5 }).aspect, '16:9');
  assert.equal(normaliseSlider({ dots: 'no', autoplay: null }).dots, true);
});

test('the aspect ratio gives the picture its size before it loads', () => {
  assert.deepEqual(aspectSize('21:9'), { w: 21, h: 9 });
  assert.deepEqual(aspectSize('1:1'), { w: 1, h: 1 });
  assert.deepEqual(aspectSize('nonsense'), { w: 16, h: 9 });
  assert.deepEqual(aspectSize('0:5'), { w: 16, h: 9 });
});

test('going next or previous wraps around', () => {
  assert.equal(wrapIndex(4, 4), 0);
  assert.equal(wrapIndex(-1, 4), 3);
  assert.equal(wrapIndex(2, 4), 2);
  assert.equal(wrapIndex(5, 0), 0);
});

test('a scroll position maps to a slide, left to right and right to left', () => {
  assert.equal(indexFromScroll(0, 400, 3), 0);
  assert.equal(indexFromScroll(190, 400, 3), 0);
  assert.equal(indexFromScroll(210, 400, 3), 1);
  assert.equal(indexFromScroll(800, 400, 3), 2);
  assert.equal(indexFromScroll(5000, 400, 3), 2); // never past the last
  assert.equal(indexFromScroll(-810, 400, 3), 2); // right to left scrolls to negative offsets
  assert.equal(indexFromScroll(-0, 400, 3), 0);
  assert.equal(indexFromScroll(100, 0, 3), 0);
  assert.equal(indexFromScroll(100, 400, 0), 0);
});

test('the offset of a slide is negative in right to left', () => {
  assert.equal(scrollLeftFor(2, 400, false), 800);
  assert.equal(scrollLeftFor(2, 400, true), -800);
  assert.ok(Object.is(scrollLeftFor(0, 400, true), 0)); // not -0
});

test('the arrow keys point in screen directions', () => {
  assert.equal(stepForKey('ArrowRight', false), 1);
  assert.equal(stepForKey('ArrowLeft', false), -1);
  assert.equal(stepForKey('ArrowRight', true), -1);
  assert.equal(stepForKey('ArrowLeft', true), 1);
  assert.equal(stepForKey('ArrowUp', false), 0);
  assert.equal(stepForKey('Tab', true), 0);
});
