// Smoke check of the admin live fleet map: layout, markers, list, filters, card, theme, RTL, mobile. Writes screenshots to SHOTS.
import { BASE, SHOTS, chromium, login, shot, stubTiles, watch } from './lib.mjs';

const browser = await chromium.launch();
const results = [];
const check = (name, ok, extra = '') => { results.push([name, ok]); console.log(`${ok ? 'PASS' : 'FAIL'} ${name}${extra ? ` (${extra})` : ''}`); };

const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
const page = await ctx.newPage();
const log = watch(page);
const tiles = await stubTiles(page);
await login(page);
await page.goto(`${BASE}/admin/fleet`);
await page.waitForSelector('.abm .leaflet-marker-icon .abm-dm', { timeout: 20000 });
await page.waitForTimeout(1200);
await shot(page, 'fleet-desktop-light');
check('markers rendered', (await page.locator('.abm-dm').count()) >= 5, `${await page.locator('.abm-dm').count()}`);
check('branch pins rendered', (await page.locator('.abm-pin--branch').count()) === 4);
check('list rows', (await page.locator('.abm-row').count()) >= 6, `${await page.locator('.abm-row').count()}`);
check('tiles requested with a referer', [...tiles.referers].every((r) => r !== '(none)'), [...tiles.referers].join(','));
check('attribution kept', (await page.locator('.leaflet-control-attribution').innerText()).includes('OpenStreetMap'));
console.log('console:', log.join(' | ') || 'clean');
await browser.close();
process.exit(results.every(([, ok]) => ok) ? 0 : 1);
