// Checks of the admin live fleet map: layout, markers, panel, filters, card, follow, theme, RTL, phone, tile failure, init idempotence.
// Writes screenshots to SHOTS. Needs the seeded QA database (see docs/qa/WEB_MAPS_QA.md) and Reverb for the live part.
import { spawn } from 'node:child_process';
import { BASE, chromium, login, shot, stubTiles, watch } from './lib.mjs';

const results = [];
const check = (name, ok, extra = '') => { results.push([name, ok]); console.log(`${ok ? 'PASS' : 'FAIL'} ${name}${extra ? ` (${extra})` : ''}`); };
const browser = await chromium.launch();

async function openFleet(viewport = { width: 1440, height: 900 }, opts = {}) {
    const ctx = await browser.newContext({ viewport, deviceScaleFactor: opts.dpr ?? 1, ...(opts.ctx ?? {}) });
    const page = await ctx.newPage();
    const log = watch(page);
    const tiles = await stubTiles(page, opts.tiles);
    await login(page);
    await page.goto(`${BASE}/admin/fleet`);
    await page.waitForSelector('.abm .leaflet-marker-icon', { timeout: 20000 });
    await page.waitForTimeout(1000);
    return { ctx, page, log, tiles };
}

/* ───────────── Desktop, light ───────────── */
{
    const { ctx, page, log, tiles } = await openFleet();
    const drivers = await page.locator('.abm-dm').count();
    check('driver markers', drivers >= 5, String(drivers));
    check('branch pins', (await page.locator('.abm-pin--branch').count()) === 4);
    check('list rows match markers', (await page.locator('.abm-row').count()) === drivers);
    check('stat tiles', (await page.locator('.abm-stat__n').allInnerTexts()).join(',').length > 0, (await page.locator('.abm-stat__n').allInnerTexts()).join('/'));
    check('connection chip is live', (await page.locator('.abm-conn').getAttribute('data-state')) === 'live', await page.locator('.abm-conn').innerText());
    check('attribution kept', (await page.locator('.leaflet-control-attribution').innerText()).includes('OpenStreetMap'));
    check('tile requests carry a referer', [...tiles.referers].every((r) => r !== '(none)'));
    check('tool buttons are 44px and labelled', await page.evaluate(() => [...document.querySelectorAll('.abm-tool')].filter((b) => b.offsetParent !== null).every((b) => b.offsetWidth >= 44 && b.offsetHeight >= 44 && b.getAttribute('aria-label'))));

    // search and status filter
    await page.fill('.abm-input--search', 'ravi');
    await page.waitForTimeout(300);
    check('search narrows list and markers', (await page.locator('.abm-row').count()) === 1 && (await page.locator('.abm-dm').count()) === 1);
    await page.fill('.abm-input--search', '');
    await page.selectOption('.abm-input--select', 'out');
    await page.waitForTimeout(300);
    check('status filter "on the way"', (await page.locator('.abm-row').count()) === 1);
    await page.selectOption('.abm-input--select', 'all');
    await page.waitForTimeout(300);

    // select, card, link
    await page.locator('.abm-row', { hasText: 'Ravi' }).click();
    await page.waitForTimeout(1500);
    check('card opens with the order link', (await page.locator('.abm-card a.abm-btn--primary').getAttribute('href'))?.includes('/admin/orders/') === true);
    check('selected marker highlighted', (await page.locator('.abm-dm.is-selected').count()) === 1);
    check('driver not hidden behind the card', await page.evaluate(() => {
        const m = document.querySelector('.abm-dm.is-selected').getBoundingClientRect();
        const c = document.querySelector('.abm-card').getBoundingClientRect();
        return m.right < c.left || m.left > c.right || m.bottom < c.top || m.top > c.bottom;
    }));
    await page.locator('.abm-row', { hasText: 'Hamad' }).click();
    await page.waitForTimeout(600);
    check('call link is a tel: link with the number as text and a note', (await page.locator('.abm-card a[href^="tel:"]').count()) === 1 && (await page.locator('.abm-phone').innerText()).startsWith('+974') && (await page.locator('.abm-card__note').innerText()).length > 10);
    await page.locator('.abm-row', { hasText: 'Ravi' }).click();
    await page.waitForTimeout(600);
    await page.click('.abm-card .abm-btn[aria-pressed]');
    check('follow toggles', (await page.locator('.abm-tool[aria-label="Stop following"]').count()) === 1);
    await page.click('.abm-card .abm-btn[aria-pressed]');
    await shot(page, 'fleet-desktop-card');
    await page.keyboard.press('Escape');
    check('Escape closes the card', await page.locator('.abm-card').isHidden());

    // layers and legend
    await page.click('.abm-tool[aria-label="Branches"]');
    check('branch layer toggles off', (await page.locator('.abm-pin--branch').count()) === 0);
    await page.click('.abm-tool[aria-label="Branches"]');
    await page.click('.abm-tool[aria-label="Legend"]');
    await shot(page, 'fleet-desktop-legend');
    check('legend opens', await page.locator('.abm-legend').isVisible());
    await page.click('.abm-tool[aria-label="Legend"]');

    // full screen (real API in Chromium)
    await page.click('.abm-tool[aria-label="Full screen"]');
    await page.waitForTimeout(500);
    check('full screen on', await page.evaluate(() => !!document.fullscreenElement || document.querySelector('.abm--pseudo-fs') !== null));
    await shot(page, 'fleet-desktop-fullscreen');
    await page.keyboard.press('Escape');
    await page.waitForTimeout(400);

    // theme
    await page.evaluate(() => window.toggleTheme());
    await page.waitForTimeout(700);
    await shot(page, 'fleet-desktop-dark');
    check('dark theme applies to the map UI', await page.evaluate(() => getComputedStyle(document.querySelector('.abm')).getPropertyValue('--abm-panel').trim() === '#1d2029'));
    check('tiles tinted when there are no dark tiles', await page.locator('.abm-canvas.abm--tint').count() === 1);
    await page.evaluate(() => window.toggleTheme());

    console.log('console:', log.join(' | ') || 'clean');
    await ctx.close();
}

/* ───────────── Idempotent init: Livewire refresh, wire:navigate away and back ───────────── */
{
    const { ctx, page, log } = await openFleet();
    await page.evaluate(() => { document.querySelector('.abm-canvas').dataset.marker = 'first'; });
    await page.evaluate(() => window.Livewire.first().$refresh());
    await page.waitForTimeout(800);
    await page.evaluate(() => window.Livewire.first().$refresh());
    await page.waitForTimeout(800);
    check('Livewire refresh keeps the same map', (await page.locator('.abm-canvas[data-marker="first"]').count()) === 1 && (await page.locator('.abm-canvas').count()) === 1);
    await page.click('a[href$="/admin/deliveries"]');
    await page.waitForURL(/deliveries/);
    await page.click('a[href$="/admin/fleet"]');
    await page.waitForSelector('.abm .leaflet-marker-icon');
    check('navigate away and back builds exactly one map', (await page.locator('.abm-canvas').count()) === 1);
    await page.goBack();
    await page.goForward();
    await page.waitForSelector('.abm .leaflet-marker-icon');
    check('back / forward builds exactly one map', (await page.locator('.abm-canvas').count()) === 1);
    check('no "already initialized" or page errors', !log.some((l) => /already initialized|pageerror/i.test(l)), log.join(' | '));
    await ctx.close();
}

/* ───────────── Branch filter (Livewire select) refits the map ───────────── */
{
    const { ctx, page } = await openFleet();
    const before = await page.locator('.abm-row').count();
    const opt = await page.locator('select[wire\\:model\\.live=branch] option').nth(2).getAttribute('value');
    await page.selectOption('select[wire\\:model\\.live=branch]', opt);
    await page.waitForTimeout(1800);
    const after = await page.locator('.abm-row').count();
    check('branch filter reduces the list', after < before, `${before} -> ${after}`);
    await ctx.close();
}

/* ───────────── Arabic / RTL ───────────── */
{
    const { ctx, page, log } = await openFleet();
    await page.click('button:has-text("العربية"), a:has-text("العربية")');
    await page.waitForLoadState('networkidle');
    await page.goto(`${BASE}/admin/fleet`);
    await page.waitForSelector('.abm .leaflet-marker-icon', { timeout: 20000 });
    await page.waitForTimeout(1200);
    check('html is rtl', (await page.evaluate(() => document.documentElement.dir)) === 'rtl');
    const geo = await page.evaluate(() => {
        const t = document.querySelector('.abm-tools').getBoundingClientRect();
        const p = document.querySelector('.abm-panel').getBoundingClientRect();
        const s = document.querySelector('.abm-stage').getBoundingClientRect();
        return { toolsLeftHalf: t.left < s.left + s.width / 2, panelRight: p.left > s.left };
    });
    check('RTL: tools on the left, list on the right', geo.toolsLeftHalf && geo.panelRight, JSON.stringify(geo));
    check('Arabic labels', (await page.locator('.abm-stat__l').first().innerText()).includes('الدوام'));
    await page.locator('.abm-row').first().click();
    await page.waitForTimeout(1500);
    await shot(page, 'fleet-desktop-rtl-card');
    console.log('console:', log.join(' | ') || 'clean');
    // the language is stored on the user: put it back
    await page.click('button:has-text("English"), a:has-text("English")');
    await page.waitForLoadState('networkidle');
    await ctx.close();
}

/* ───────────── Phone ───────────── */
{
    const { ctx, page, log } = await openFleet({ width: 390, height: 844 }, { dpr: 2, ctx: { hasTouch: true, isMobile: true } });
    await page.locator('.abm').scrollIntoViewIfNeeded();
    await page.waitForTimeout(500);
    await shot(page, 'fleet-phone');
    check('no sideways scroll at 390', await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1));
    check('phone: tools fold behind "more"', (await page.locator('.abm-tools .abm-tool:visible').count()) <= 6);
    await page.click('.abm-panel__handle');
    await page.waitForTimeout(500);
    await shot(page, 'fleet-phone-sheet');
    await page.locator('.abm-row').first().click();
    await page.waitForTimeout(1500);
    await shot(page, 'fleet-phone-card');
    check('phone: driver visible above the card', await page.evaluate(() => {
        const m = document.querySelector('.abm-dm.is-selected')?.getBoundingClientRect();
        const c = document.querySelector('.abm-card').getBoundingClientRect();
        const s = document.querySelector('.abm-stage').getBoundingClientRect();
        return Boolean(m) && m.bottom <= c.top && m.top >= s.top;
    }));
    console.log('console:', log.join(' | ') || 'clean');
    await ctx.close();
}

/* ───────────── Tiles unavailable ───────────── */
{
    const { ctx, page } = await openFleet({ width: 1440, height: 900 }, { tiles: { fail: true } });
    await page.waitForTimeout(1500);
    check('tile failure: notice and soft background', await page.locator('.abm--no-tiles').count() === 1 && (await page.locator('.abm-toast').innerText()).includes('Map tiles unavailable'));
    await shot(page, 'fleet-tiles-unavailable');
    check('tile failure: markers still shown', (await page.locator('.abm-dm').count()) >= 5);
    await ctx.close();
}

await browser.close();
const failed = results.filter(([, ok]) => !ok).length;
console.log(`\n${results.length - failed}/${results.length} checks passed`);
process.exit(failed ? 1 : 0);
