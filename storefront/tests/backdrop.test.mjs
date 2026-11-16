import test from 'node:test';
import assert from 'node:assert/strict';
import { existsSync, readdirSync, readFileSync } from 'node:fs';
import { BACKDROP_OFF, DEFAULT_OPACITY, DEFAULT_SCENE, SCENES, contrastRatio, cssUrl, isHex, isScene, readScene, resolveBackdrop, safeImageUrl } from '../src/lib/backdropCore.ts';

// The page background (docs/api/PAGE_BACKDROP.md): how the theme's `backdrop` block is read. Anything unexpected is "feature off".

const INK = '#2a1a20';
const theme = (backdrop, ink = INK) => ({ colors: { ink }, backdrop });
const block = (over = {}) => ({ style: 'skyline', scene: 'doha', image_url: null, fit: 'bottom', opacity: 0.08, color: null, tint: null, ...over });

test('the server defaults draw the skyline at 8 percent in the theme colours', () => {
  assert.deepEqual(resolveBackdrop(theme(block())), { active: true, picture: 'skyline', scene: 'doha', imageUrl: null, fit: 'bottom', opacity: 0.08, color: null, tint: null });
});

test('no block (older server) or a block that is not an object means feature off', () => {
  for (const bad of [undefined, null, {}, [], 'skyline', 7, true]) assert.equal(resolveBackdrop(theme(bad)), BACKDROP_OFF, String(bad));
  assert.equal(resolveBackdrop(null), BACKDROP_OFF);
  assert.equal(resolveBackdrop(undefined), BACKDROP_OFF);
  assert.equal(resolveBackdrop({}), BACKDROP_OFF);
  assert.equal(BACKDROP_OFF.active, false);
  assert.equal(BACKDROP_OFF.picture, 'none');
  assert.equal(Object.isFrozen(BACKDROP_OFF), true);
});

test('a style outside the allowed set means feature off, even with a valid colour next to it', () => {
  for (const style of ['Skyline', 'svg', '', 7, null, undefined]) assert.equal(resolveBackdrop(theme(block({ style, color: '#fcf9fa' }))), BACKDROP_OFF, String(style));
});

test('fit: bottom and cover are allowed, missing means bottom, anything else is feature off', () => {
  assert.equal(resolveBackdrop(theme(block({ fit: 'cover' }))).fit, 'cover');
  assert.equal(resolveBackdrop(theme(block({ fit: 'bottom' }))).fit, 'bottom');
  assert.equal(resolveBackdrop(theme(block({ fit: null }))).fit, 'bottom');
  assert.equal(resolveBackdrop(theme(block({ fit: undefined }))).fit, 'bottom');
  for (const fit of ['contain', 'COVER', 1, true]) assert.equal(resolveBackdrop(theme(block({ fit }))), BACKDROP_OFF, String(fit));
});

test('opacity is pulled into 0.02 .. 0.50, a missing one is the 0.08 default, a non-number is feature off', () => {
  const op = (v) => resolveBackdrop(theme(block({ opacity: v }))).opacity;
  assert.equal(op(0.08), 0.08);
  assert.equal(op(0.02), 0.02);
  assert.equal(op(0.5), 0.5);
  assert.equal(op(0), 0.02);
  assert.equal(op(0.001), 0.02);
  assert.equal(op(-3), 0.02);
  assert.equal(op(0.51), 0.5);
  assert.equal(op(1), 0.5);
  assert.equal(op(80), 0.5);
  assert.equal(op('0.12'), 0.12);
  assert.equal(op(null), DEFAULT_OPACITY);
  assert.equal(op(undefined), DEFAULT_OPACITY);
  for (const bad of ['abc', '', '  ', NaN, Infinity, -Infinity, true, {}, []]) assert.equal(resolveBackdrop(theme(block({ opacity: bad }))), BACKDROP_OFF, String(bad));
  assert.equal(resolveBackdrop(theme({ style: 'skyline' })).opacity, DEFAULT_OPACITY);
});

test('hex colours must be #RRGGBB: tint is lower-cased, a bad one is feature off', () => {
  assert.equal(resolveBackdrop(theme(block({ tint: '#8A1538' }))).tint, '#8a1538');
  assert.equal(resolveBackdrop(theme(block({ tint: null }))).tint, null);
  for (const bad of ['8a1538', '#8a153', '#8a15388', '#abc', 'red', 'rgb(1,2,3)', '#gggggg', 12, true]) {
    assert.equal(resolveBackdrop(theme(block({ tint: bad }))), BACKDROP_OFF, `tint ${bad}`);
    assert.equal(resolveBackdrop(theme(block({ color: bad }))), BACKDROP_OFF, `color ${bad}`);
  }
  assert.equal(isHex('#a1B2c3'), true);
  assert.equal(isHex('#a1B2c'), false);
  assert.equal(isHex(undefined), false);
  assert.equal(isHex(null), false);
});

test('style none with no page colour is not active: nothing changes anywhere', () => {
  const r = resolveBackdrop(theme(block({ style: 'none' })));
  assert.equal(r.active, false);
  assert.equal(r.picture, 'none');
  assert.equal(r.color, null);
});

test('a page colour alone (style none) is active', () => {
  const r = resolveBackdrop(theme(block({ style: 'none', color: '#F4ECEF' })));
  assert.equal(r.active, true);
  assert.equal(r.picture, 'none');
  assert.equal(r.color, '#f4ecef');
});

test('colour guard: a page colour that text cannot be read on (below 3:1) is ignored', () => {
  assert.ok(contrastRatio('#fcf9fa', INK) >= 3);
  assert.equal(resolveBackdrop(theme(block({ color: '#fcf9fa' }))).color, '#fcf9fa');
  // Maroon on a near-black ink is ~1.8:1: ignored, the skyline stays.
  assert.ok(contrastRatio('#8a1538', INK) < 3);
  const dark = resolveBackdrop(theme(block({ color: '#8a1538' })));
  assert.equal(dark.color, null);
  assert.equal(dark.active, true);
  // With nothing else to draw, an ignored colour leaves the feature inactive.
  assert.equal(resolveBackdrop(theme(block({ style: 'none', color: '#8a1538' }))).active, false);
  // The same colour is fine for a theme with light text.
  assert.equal(resolveBackdrop(theme(block({ color: '#8a1538' }), '#ffffff')).color, '#8a1538');
});

test('colour guard: the edge sits at exactly 3:1 and an unknown text colour ignores the page colour', () => {
  // #767676 on white is 4.54:1; #949494 on white is 3.03:1; #a0a0a0 is 2.64:1.
  assert.equal(resolveBackdrop(theme(block({ color: '#949494' }), '#ffffff')).color, '#949494');
  assert.equal(resolveBackdrop(theme(block({ color: '#a0a0a0' }), '#ffffff')).color, null);
  assert.equal(resolveBackdrop({ backdrop: block({ color: '#fcf9fa' }) }).color, null);
  assert.equal(resolveBackdrop(theme(block({ color: '#fcf9fa' }), 'not-a-colour')).color, null);
  assert.equal(resolveBackdrop({ colors: null, backdrop: block({ color: '#fcf9fa' }) }).color, null);
});

test('contrast ratio maths: black on white is 21, a colour on itself is 1', () => {
  assert.ok(Math.abs(contrastRatio('#000000', '#ffffff') - 21) < 1e-9);
  assert.ok(Math.abs(contrastRatio('#336699', '#336699') - 1) < 1e-9);
  assert.equal(contrastRatio('#123456', '#fedcba'), contrastRatio('#fedcba', '#123456'));
});

test('own picture: an absolute https address is used, anything else drops the picture but keeps the page colour', () => {
  const ok = resolveBackdrop(theme(block({ style: 'image', image_url: 'https://cdn.example.com/backdrop/a b.webp', fit: 'cover' })));
  assert.equal(ok.picture, 'image');
  assert.equal(ok.imageUrl, 'https://cdn.example.com/backdrop/a%20b.webp');
  assert.equal(ok.fit, 'cover');
  assert.equal(ok.active, true);
  for (const url of [null, undefined, '', 'http://cdn.example.com/a.webp', '/storage/backdrop/a.webp', 'javascript:alert(1)', 'data:image/png;base64,AAAA', 'https://u:p@cdn.example.com/a.webp', 'not a url', 12]) {
    const r = resolveBackdrop(theme(block({ style: 'image', image_url: url, color: '#fcf9fa' })));
    assert.equal(r.picture, 'none', String(url));
    assert.equal(r.imageUrl, null);
    assert.equal(r.color, '#fcf9fa');
    assert.equal(r.active, true);
  }
  assert.equal(resolveBackdrop(theme(block({ style: 'image', image_url: null }))).active, false);
  assert.equal(safeImageUrl('https://x.test/' + 'a'.repeat(2100)), null);
});

test('the built-in skyline never uses an image address; unknown keys are ignored', () => {
  const r = resolveBackdrop(theme(block({ image_url: 'https://cdn.example.com/a.webp', mood: 'calm', extra: { a: 1 } })));
  assert.equal(r.picture, 'skyline');
  assert.equal(r.imageUrl, null);
});

test('cssUrl keeps an address inside its quotes', () => {
  assert.equal(cssUrl('/store/assets/sky.svg'), 'url("/store/assets/sky.svg")');
  assert.equal(cssUrl('a"b\\c\nd'), 'url("a%22b%5Cc%0Ad")');
  assert.equal(cssUrl('data:image/svg+xml,%3csvg%3e'), 'url("data:image/svg+xml,%3csvg%3e")');
});

test('the sample from the contract resolves as documented', () => {
  const payload = { colors: { ink: INK }, backdrop: { style: 'skyline', scene: 'doha', image_url: null, fit: 'bottom', opacity: 0.08, color: '#F7F2EE', tint: '#8A1538', future_key: true } };
  assert.deepEqual(resolveBackdrop(payload), { active: true, picture: 'skyline', scene: 'doha', imageUrl: null, fit: 'bottom', opacity: 0.08, color: '#f7f2ee', tint: '#8a1538' });
});

/* ───────────── built-in artworks (`scene`) ───────────── */
const ALL = ['doha', 'dhow', 'souq', 'dunes', 'museum', 'arabesque'];

test('every contract scene is known, nothing else is', () => {
  assert.deepEqual(Object.keys(SCENES).sort(), [...ALL].sort());
  assert.equal(DEFAULT_SCENE, 'doha');
  for (const k of ALL) assert.equal(isScene(k), true, k);
  for (const bad of ['', 'Doha', 'DHOW', 'skyline', 'cityscape', ' doha', 'doha ', 'toString', '__proto__', 'constructor', 'hasOwnProperty', 7, true, null, undefined, {}, ['doha']]) assert.equal(isScene(bad), false, String(bad));
});

test('scene: each valid key is read as is', () => {
  for (const scene of ALL) {
    const r = resolveBackdrop(theme(block({ scene })));
    assert.equal(r.scene, scene);
    assert.equal(r.picture, 'skyline');
    assert.equal(r.active, true);
  }
});

test('scene: missing, null, unknown or wrongly typed means the Doha skyline, and the rest of the block is read as before', () => {
  for (const scene of [undefined, null, '', 'Dhow', 'ARABESQUE', 'pyramids', 'skyline', 'toString', '__proto__', 7, 0, true, false, {}, [], ['dhow']]) {
    const r = resolveBackdrop(theme(block({ scene, opacity: 0.2, tint: '#8A1538' })));
    assert.equal(r.scene, 'doha', String(scene));
    assert.equal(r.picture, 'skyline', String(scene));
    assert.equal(r.active, true, String(scene));
    assert.equal(r.opacity, 0.2);
    assert.equal(r.tint, '#8a1538');
  }
  assert.equal(readScene(undefined), 'doha');
  assert.equal(readScene('souq'), 'souq');
  assert.equal(readScene(['souq']), 'doha');
});

test('scene: an old payload without the key behaves exactly as before (skyline, same fields)', () => {
  const old = { style: 'skyline', image_url: null, fit: 'bottom', opacity: 0.08, color: '#F7F2EE', tint: '#8A1538' };
  const r = resolveBackdrop(theme(old));
  assert.deepEqual(r, { active: true, picture: 'skyline', scene: 'doha', imageUrl: null, fit: 'bottom', opacity: 0.08, color: '#f7f2ee', tint: '#8a1538' });
  assert.equal(SCENES[r.scene].tile, false);
  assert.equal(resolveBackdrop(theme({ style: 'skyline' })).scene, 'doha');
});

test('scene never turns the feature off or on by itself: style and page colour decide', () => {
  assert.equal(resolveBackdrop(theme(block({ style: 'none', scene: 'dhow' }))).active, false);
  assert.equal(resolveBackdrop(theme(block({ style: 'none', scene: 'dhow', color: '#fcf9fa' }))).active, true);
  assert.equal(resolveBackdrop(theme(block({ style: 'image', scene: 'dunes', image_url: 'https://cdn.example.com/a.webp' }))).picture, 'image');
  // A style outside the allowed set is still feature off, whatever the scene says.
  assert.equal(resolveBackdrop(theme(block({ style: 'svg', scene: 'dhow' }))), BACKDROP_OFF);
  assert.equal(BACKDROP_OFF.scene, 'doha');
});

test('tile flag: only the arabesque repeats, every other scene stands on the bottom edge', () => {
  for (const k of ALL) assert.equal(SCENES[k].tile, k === 'arabesque', k);
  assert.equal(resolveBackdrop(theme(block({ scene: 'arabesque' }))).scene, 'arabesque');
  assert.equal(SCENES[resolveBackdrop(theme(block({ scene: 'arabesque' }))).scene].tile, true);
  assert.equal(SCENES[resolveBackdrop(theme(block({ scene: 'unknown' }))).scene].tile, false);
});

test('registry is read-only', () => {
  assert.equal(Object.isFrozen(SCENES), true);
  for (const k of ALL) assert.equal(Object.isFrozen(SCENES[k]), true, k);
});

test('every scene has its artwork file in src/assets, and every artwork file has a scene', () => {
  const dir = new URL('../src/assets/', import.meta.url);
  for (const k of ALL) {
    const f = SCENES[k].file;
    assert.match(f, /^qatar-[a-z]+\.svg$/, k);
    assert.equal(existsSync(new URL(f, dir)), true, `${k} -> ${f}`);
    const svg = readFileSync(new URL(f, dir), 'utf8');
    assert.match(svg, /^<svg\b/, `${f} is an svg`);
    // The mask needs a viewBox to scale: a scene is the 1600x340 drawing, the tile is one 200x200 cell.
    assert.match(svg, SCENES[k].tile ? /viewBox="0 0 200 200"/ : /viewBox="0 0 1600 340"/, `${f} viewBox`);
  }
  assert.equal(new Set(ALL.map((k) => SCENES[k].file)).size, ALL.length, 'one file per scene');
  const onDisk = readdirSync(new URL('./', dir)).filter((f) => /^qatar-.*\.svg$/.test(f)).sort();
  assert.deepEqual(onDisk, ALL.map((k) => SCENES[k].file).sort());
});
