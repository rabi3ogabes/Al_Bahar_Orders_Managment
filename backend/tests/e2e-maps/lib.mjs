// Shared helpers for the Playwright map checks (not part of CI: they need a running app, a seeded database and Chromium).
// Usage: BASE=http://127.0.0.1:8083 SHOTS=/some/dir node tests/e2e-maps/fleet.mjs
// The sandbox has no internet, so every tile request is answered with a generated grid tile.
import { deflateSync } from 'node:zlib';
import { mkdirSync } from 'node:fs';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
export const { chromium } = (() => {
    try { return require('playwright'); } catch { return require('/opt/node-tools/node_modules/playwright'); }
})();

export const BASE = process.env.BASE ?? 'http://127.0.0.1:8083';
export const SHOTS = process.env.SHOTS ?? '/tmp/maps-web-shots';
mkdirSync(SHOTS, { recursive: true });

const crcTable = Array.from({ length: 256 }, (_, n) => { let c = n; for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1; return c >>> 0; });
const crc32 = (buf) => { let c = 0xffffffff; for (const b of buf) c = crcTable[(c ^ b) & 0xff] ^ (c >>> 8); return (c ^ 0xffffffff) >>> 0; };
const chunk = (type, data) => { const len = Buffer.alloc(4); len.writeUInt32BE(data.length); const td = Buffer.concat([Buffer.from(type), data]); const crc = Buffer.alloc(4); crc.writeUInt32BE(crc32(td)); return Buffer.concat([len, td, crc]); };

/** A 256x256 PNG: land colour with a faint grid and a few "roads", varied by tile so seams are visible. */
export function tilePng(z, x, y, dark = false) {
    const size = 256;
    const land = dark ? [26, 29, 38] : [236, 230, 218];
    const road = dark ? [58, 64, 80] : [255, 253, 249];
    const water = dark ? [22, 36, 52] : [190, 214, 230];
    const raw = Buffer.alloc((size * 3 + 1) * size);
    for (let j = 0; j < size; j++) {
        raw[j * (size * 3 + 1)] = 0;
        for (let i = 0; i < size; i++) {
            let c = land;
            if (i === 0 || j === 0) c = dark ? [44, 48, 60] : [214, 205, 188];
            if (Math.abs(i - ((x * 53) % 200 + 28)) < 4 || Math.abs(j - ((y * 71) % 200 + 28)) < 4) c = road;
            if (((x + y) % 7 === 0) && i > 150 && j > 170) c = water;
            const o = j * (size * 3 + 1) + 1 + i * 3;
            raw[o] = c[0]; raw[o + 1] = c[1]; raw[o + 2] = c[2];
        }
    }
    const ihdr = Buffer.alloc(13); ihdr.writeUInt32BE(size, 0); ihdr.writeUInt32BE(size, 4); ihdr[8] = 8; ihdr[9] = 2;
    return Buffer.concat([Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]), chunk('IHDR', ihdr), chunk('IDAT', deflateSync(raw)), chunk('IEND', Buffer.alloc(0))]);
}

export async function stubTiles(page, { fail = false } = {}) {
    const hits = { ok: 0, failed: 0, referers: new Set() };
    await page.route(/tile\.openstreetmap\.org|tiles\.example\.test|basemaps\.cartocdn\.com/, async (route) => {
        const url = route.request().url();
        hits.referers.add(route.request().headers().referer ?? '(none)');
        if (fail) { hits.failed += 1; return route.fulfill({ status: 404, body: 'no' }); }
        const m = url.match(/\/(\d+)\/(\d+)\/(\d+)(?:@2x)?\.png/);
        hits.ok += 1;
        const dark = /dark/.test(url);
        return route.fulfill({ status: 200, contentType: 'image/png', body: m ? tilePng(+m[1], +m[2], +m[3], dark) : tilePng(0, 0, 0, dark) });
    });
    return hits;
}

export function watch(page, log = []) {
    page.on('console', (m) => { if (['error', 'warning'].includes(m.type())) log.push(`${m.type()}: ${m.text()}`); });
    page.on('pageerror', (e) => log.push(`pageerror: ${e.message}`));
    return log;
}

export async function login(page, email = 'maps@albahar.test', password = 'MapsQA-12345!') {
    await page.goto(`${BASE}/admin/login`);
    await page.fill('input[type=email]', email);
    await page.fill('input[type=password]', password);
    await Promise.all([page.waitForURL(/\/admin(?!\/login)/, { timeout: 20000 }), page.keyboard.press('Enter')]);
}

export const shot = (page, name, opts = {}) => page.screenshot({ path: `${SHOTS}/${name}.png`, ...opts });
