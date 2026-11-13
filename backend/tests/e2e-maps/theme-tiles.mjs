// With a custom provider that has separate light and dark tiles, toggling the theme swaps the tile layer live (no CSS tint),
// and the tile requests use the right URLs. Needs map.provider=custom with tile_url_light / tile_url_dark on tiles.example.test.
import { BASE, chromium, login, shot, stubTiles, watch } from './lib.mjs';

const results = [];
const check = (name, ok, extra = '') => { results.push([name, ok]); console.log(`${ok ? 'PASS' : 'FAIL'} ${name}${extra ? ` (${extra})` : ''}`); };
const browser = await chromium.launch();
const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
const page = await ctx.newPage();
const log = watch(page);
const seen = [];
page.on('request', (r) => { if (r.url().includes('tiles.example.test')) seen.push(r.url()); });
await stubTiles(page);
await login(page);
await page.goto(`${BASE}/admin/fleet`);
await page.waitForSelector('.abm .leaflet-tile-loaded', { timeout: 20000 });
await page.waitForTimeout(800);
check('light tiles requested first', seen.length > 0 && seen.every((u) => u.includes('/light/')), seen[0]);
await shot(page, 'theme-tiles-light');
seen.length = 0;
await page.evaluate(() => window.toggleTheme());
await page.waitForTimeout(1800);
check('dark tiles requested after the toggle', seen.some((u) => u.includes('/dark/')), seen[0]);
check('no CSS tint when real dark tiles exist', (await page.locator('.abm-canvas.abm--tint').count()) === 0);
check('only the dark layer stays loaded', await page.evaluate(() => [...document.querySelectorAll('.leaflet-tile-loaded')].every((t) => t.src.includes('/dark/'))));
await shot(page, 'theme-tiles-dark');
await page.evaluate(() => window.toggleTheme());
await page.waitForTimeout(1500);
check('back to light tiles', await page.evaluate(() => [...document.querySelectorAll('.leaflet-tile-loaded')].every((t) => t.src.includes('/light/'))));
check('attribution follows the provider', (await page.locator('.leaflet-control-attribution').innerText()).includes('QA Tiles'), await page.locator('.leaflet-control-attribution').innerText());
console.log('console:', log.join(' | ') || 'clean');
await browser.close();
process.exit(results.every(([, ok]) => ok) ? 0 : 1);
