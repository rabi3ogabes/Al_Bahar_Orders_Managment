// Edge states of the partner map: skeleton while loading, empty (branches still shown), refresh error with retry, chunk load failure
// with retry, reduced motion, keyboard use of markers and the list. STATE=state.json from the seed.
import { BASE, chromium, shot, state, stubTiles, watch } from './lib.mjs';

const TOKEN = process.env.PARTNER_TOKEN ?? state.partner_token;
const PASSWORD = process.env.PARTNER_PASSWORD ?? state.partner_password ?? 'Partner-QA-2026';
const results = [];
const check = (name, ok, extra = '') => { results.push([name, ok]); console.log(`${ok ? 'PASS' : 'FAIL'} ${name}${extra ? ` (${extra})` : ''}`); };
const browser = await chromium.launch();

async function unlocked(page) {
    await page.goto(`${BASE}/partner/${TOKEN}`);
    if (await page.locator('#unlock').count()) {
        await page.fill('input[type=password]', PASSWORD);
        await Promise.all([page.waitForNavigation(), page.keyboard.press('Enter')]);
    }
}

/* skeleton, then empty state */
{
    const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const page = await ctx.newPage();
    const log = watch(page);
    await stubTiles(page);
    await unlocked(page);
    await page.route(/\/fleet$/, async (route) => { await new Promise((r) => setTimeout(r, 1500)); await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: { drivers: [], at: new Date().toISOString() } }) }); });
    await page.reload();
    await page.waitForSelector('#map-host .abm-skel', { timeout: 10000 });
    check('skeleton while loading', await page.locator('#map-host .abm-skel').isVisible());
    await page.waitForSelector('#map-host .abm-empty:not([hidden])', { timeout: 10000 });
    await page.waitForTimeout(600);
    await shot(page, 'states-empty');
    check('empty state "No drivers on duty"', (await page.locator('.abm-empty').innerText()).includes('No drivers on duty'));
    check('empty state keeps the branches on the map', (await page.locator('.abm-pin--branch').count()) === 4);
    check('empty list message', (await page.locator('.abm-panel__note').innerText()).length > 5);
    check('no console problems', log.length === 0, log.join(' | '));
    await ctx.close();
}

/* refresh error and retry */
{
    const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const page = await ctx.newPage();
    await stubTiles(page);
    await unlocked(page);
    await page.waitForSelector('#map-host .abm .leaflet-marker-icon');
    let fail = true;
    await page.route(/\/fleet$/, (route) => (fail ? route.fulfill({ status: 500, body: 'x' }) : route.continue()));
    await page.evaluate(() => document.querySelector('.abm-conn').focus());
    await page.reload();
    await page.waitForSelector('#map-host .abm-err:not([hidden])', { timeout: 10000 });
    await shot(page, 'states-error');
    check('refresh error bar with Retry', await page.locator('.abm-err .abm-btn').isVisible());
    fail = false;
    await page.click('.abm-err .abm-btn');
    await page.waitForSelector('#map-host .abm-err', { state: 'hidden', timeout: 10000 });
    check('Retry recovers and shows the drivers', (await page.locator('.abm-dm').count()) >= 4);
    await ctx.close();
}

/* the map chunk cannot be fetched: plain message with retry */
{
    const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const page = await ctx.newPage();
    await stubTiles(page);
    let block = true;
    await page.route(/build\/assets\/maps-.*\.js/, (route) => (block ? route.abort() : route.continue()));
    await unlocked(page);
    await page.waitForSelector('#map-host [role=alert]', { timeout: 10000 });
    await shot(page, 'states-chunk-failed');
    check('chunk failure shows a message and a Retry button', (await page.locator('#map-host [role=alert] button').innerText()) === 'Retry');
    block = false;
    await Promise.all([page.waitForNavigation(), page.click('#map-host [role=alert] button')]);
    if (await page.locator('#unlock').count()) await unlocked(page);
    await page.waitForSelector('#map-host .abm .leaflet-marker-icon', { timeout: 15000 });
    check('Retry (page reload) loads the map', true);
    await ctx.close();
}

/* reduced motion + keyboard */
{
    const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 }, reducedMotion: 'reduce' });
    const page = await ctx.newPage();
    await stubTiles(page);
    await unlocked(page);
    await page.waitForSelector('#map-host .abm-dm');
    check('reduced motion: no pulse, shimmer or pop animation', await page.evaluate(() => {
        const dm = document.querySelector('.abm-dm');
        return ['.abm-dm__pulse', '.abm-conn__dot'].every((s) => getComputedStyle(document.querySelector(s)).animationName === 'none') && getComputedStyle(dm).animationName === 'none';
    }));
    // keyboard: a marker is a button; Enter selects it
    await page.locator('.leaflet-marker-icon:has(.abm-dm)').first().focus();
    await page.keyboard.press('Enter');
    await page.waitForTimeout(500);
    check('keyboard: Enter on a marker opens its card', await page.locator('.abm-card').isVisible());
    check('markers carry an accessible name', await page.evaluate(() => [...document.querySelectorAll('.abm-dm')].every((d) => (d.parentElement.getAttribute('aria-label') || '').length > 3)));
    await page.keyboard.press('Escape');
    // list: arrow keys move between rows
    await page.focus('.abm-row');
    await page.keyboard.press('ArrowDown');
    check('keyboard: arrow keys move through the list', await page.evaluate(() => document.activeElement.classList.contains('abm-row') && document.activeElement !== document.querySelector('.abm-row')));
    await ctx.close();
}

await browser.close();
const failed = results.filter(([, ok]) => !ok).length;
console.log(`\n${results.length - failed}/${results.length} checks passed`);
process.exit(failed ? 1 : 0);
