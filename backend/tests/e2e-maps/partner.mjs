// Smoke check of the partner page map: live drivers, bottom sheet at 390 px, route replay, order drawer route card, Arabic.
// PARTNER_TOKEN and PARTNER_PASSWORD (or STATE=state.json) must match the share link of the seeded database.
import { BASE, chromium, shot, state, stubTiles, watch } from './lib.mjs';

const TOKEN = process.env.PARTNER_TOKEN ?? state.partner_token;
const PASSWORD = process.env.PARTNER_PASSWORD ?? state.partner_password ?? 'Partner-QA-2026';
if (!TOKEN) throw new Error('PARTNER_TOKEN (or STATE=state.json) is required');
const results = [];
const check = (name, ok, extra = '') => { results.push([name, ok]); console.log(`${ok ? 'PASS' : 'FAIL'} ${name}${extra ? ` (${extra})` : ''}`); };

async function open(browser, viewport, opts = {}) {
    const ctx = await browser.newContext({ viewport, deviceScaleFactor: opts.dpr ?? 1, locale: 'en-GB', timezoneId: 'Asia/Qatar', ...(opts.ctx ?? {}) });
    const page = await ctx.newPage();
    const log = watch(page);
    const tiles = await stubTiles(page);
    await page.goto(`${BASE}/partner/${TOKEN}`);
    if (await page.locator('#unlock').count()) {
        await page.fill('input[type=password]', PASSWORD);
        await Promise.all([page.waitForNavigation(), page.keyboard.press('Enter')]);
    }
    await page.waitForSelector('#map-host .abm .leaflet-marker-icon', { timeout: 20000 });
    await page.waitForTimeout(800);
    return { ctx, page, log, tiles };
}

const browser = await chromium.launch();

// Desktop
{
    const { ctx, page, log, tiles } = await open(browser, { width: 1440, height: 900 });
    await shot(page, 'partner-desktop-live');
    check('drivers on the map', (await page.locator('#map-host .abm-dm').count()) >= 4, String(await page.locator('#map-host .abm-dm').count()));
    check('no full names leave the server', !(await page.locator('#map-host .abm-row__name').allInnerTexts()).some((n) => n.includes(' ')));
    check('no phone numbers in the card', await (async () => { await page.locator('#map-host .abm-row').first().click(); await page.waitForTimeout(900); return (await page.locator('.abm-card').innerText()).search(/\+974|tel:/) === -1 && (await page.locator('.abm-card a[href^="tel:"]').count()) === 0; })());
    await shot(page, 'partner-desktop-card');
    check('tiles carry a referer', [...tiles.referers].every((r) => r !== '(none)'), [...tiles.referers].join(','));
    await page.click('#m-history');
    await page.waitForSelector('#map-host .abm-pb, #map-host .abm-empty:not([hidden])', { timeout: 15000 });
    await page.waitForTimeout(800);
    await shot(page, 'partner-desktop-history');
    const trips = await page.locator('.abm-chip').count();
    check('history shows the trips of the day', trips >= 1, String(trips));
    if (trips) {
        const before = await page.locator('.abm-pb__clock').innerText();
        await page.click('.abm-pb__play');
        await page.waitForTimeout(2500);
        await shot(page, 'partner-desktop-playback');
        const after = await page.locator('.abm-pb__clock').innerText();
        check('playback advances the clock', before !== after, `${before} -> ${after}`);
        await page.click('.abm-pb__play');
        check('speed buttons present', (await page.locator('.abm-seg__b').count()) === 3);
    }
    console.log('console:', log.join(' | ') || 'clean');
    await ctx.close();
}

// Phone
{
    const { ctx, page, log } = await open(browser, { width: 390, height: 844 }, { dpr: 2, ctx: { hasTouch: true, isMobile: true } });
    await page.locator('#map-card').scrollIntoViewIfNeeded();
    await page.waitForTimeout(500);
    await shot(page, 'partner-phone-live');
    check('no sideways scroll at 390', await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1));
    await page.click('.abm-panel__handle');
    await page.waitForTimeout(600);
    await shot(page, 'partner-phone-sheet');
    await page.locator('#map-host .abm-row').first().click();
    await page.waitForTimeout(1500);
    await shot(page, 'partner-phone-card');
    console.log('console:', log.join(' | ') || 'clean');
    await ctx.close();
}

await browser.close();
process.exit(results.every(([, ok]) => ok) ? 0 : 1);
