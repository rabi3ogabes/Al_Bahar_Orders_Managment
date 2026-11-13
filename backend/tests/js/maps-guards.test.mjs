import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';

const dir = new URL('../../resources/js/maps/', import.meta.url).pathname;
const sources = readdirSync(dir).filter((f) => f.endsWith('.js')).map((f) => [f, readFileSync(join(dir, f), 'utf8')]);

test('tile requests keep the Referer: no map module sets a referrerPolicy (OpenStreetMap blocks tiles without it)', () => {
    for (const [file, src] of sources) assert.doesNotMatch(src, /referrerPolicy/i, file);
});

test('the partner page sends only the origin as referrer (the secret link stays private, tiles still load)', () => {
    const layout = readFileSync(new URL('../../resources/views/partner/layout.blade.php', import.meta.url), 'utf8');
    assert.match(layout, /<meta name="referrer" content="strict-origin">/);
});

test('map modules never write HTML from data: no innerHTML / outerHTML / insertAdjacentHTML / eval outside the constant-markup helper', () => {
    for (const [file, src] of sources) {
        if (file === 'dom.js') continue; // svgFrom(): constant markup only
        assert.doesNotMatch(src, /\.(innerHTML|outerHTML)\s*=|insertAdjacentHTML|document\.write|\beval\(|new Function\(/, file);
    }
});
