// The route card on the admin order page (an order with a driver on the road and recorded positions). STATE=state.json from the seed.
import { BASE, chromium, login, shot, state, stubTiles, watch } from './lib.mjs';

const results = [];
const check = (name, ok, extra = '') => { results.push([name, ok]); console.log(`${ok ? 'PASS' : 'FAIL'} ${name}${extra ? ` (${extra})` : ''}`); };
const id = process.env.ORDER_ID ?? state.orders?.[0]?.id;
if (!id) throw new Error('ORDER_ID (or STATE=state.json) is required');

const browser = await chromium.launch();
for (const [label, viewport, dpr] of [['desktop', { width: 1440, height: 1000 }, 1], ['phone', { width: 390, height: 844 }, 2]]) {
    const ctx = await browser.newContext({ viewport, deviceScaleFactor: dpr });
    const page = await ctx.newPage();
    const log = watch(page);
    await stubTiles(page);
    await login(page);
    await page.goto(`${BASE}/admin/orders/${id}`);
    await page.locator('[data-route]').first().scrollIntoViewIfNeeded();
    await page.waitForSelector('[data-route] .abm .leaflet-marker-icon', { timeout: 20000 });
    await page.waitForTimeout(900);
    await shot(page, `order-route-${label}`);
    check(`${label}: route card map built once`, (await page.locator('[data-route] .abm-canvas').count()) === 1);
    check(`${label}: ETA and last position badges`, (await page.locator('.abm-badge').count()) >= 1, await page.locator('.abm-badge').allInnerTexts().then((t) => t.join(' | ')));
    check(`${label}: destination pin and branch pin`, (await page.locator('.abm-pin--destination').count()) === 1 && (await page.locator('.abm-pin--branch').count()) === 1);
    await page.evaluate(() => window.Livewire.first().$refresh());
    await page.waitForTimeout(1000);
    check(`${label}: survives a Livewire refresh`, (await page.locator('[data-route] .abm-canvas').count()) === 1);
    check(`${label}: no console problems`, log.length === 0, log.join(' | '));
    await ctx.close();
}
await browser.close();
process.exit(results.every(([, ok]) => ok) ? 0 : 1);
