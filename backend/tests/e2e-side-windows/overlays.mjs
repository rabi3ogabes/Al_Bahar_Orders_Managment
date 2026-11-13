// Behaviour of the dashboard's overlay stack (resources/js/overlays.js + <x-admin.dialog>): Back / Esc / scrim / ✕ close exactly the top window,
// one history entry per window, focus returns, scroll is never left locked, toasts show above a window, wire:navigate from a window leaves no
// stale entry, a window with unsaved input asks "Discard your changes?", and a wire:confirm opens above a window.
// Usage: LANG_UI=en WIDTH=1280 ADMIN_EMAIL=... ADMIN_PASSWORD=... node tests/e2e-side-windows/overlays.mjs   (needs a seeded database: at least one transfer awaiting approval)
import { chromium, BASE, check, done, signIn, openPage, settle } from './lib.mjs';

const lang = process.env.LANG_UI ?? 'en';
const width = parseInt(process.env.WIDTH ?? '1280', 10);
const phone = width < 768;
const browser = await chromium.launch();
const state = await signIn(browser);
const { ctx, page, errors } = await openPage(browser, state, { lang, width, height: 800 });

const visible = () => page.evaluate(() => [...document.querySelectorAll('[role=dialog],[role=alertdialog]')].filter((d) => getComputedStyle(d).display !== 'none').map((d) => `${d.getAttribute('role')}:${d.getAttribute('aria-label')}`));
const st = () => page.evaluate(() => ({ ov: history.state && history.state.__ov, count: window.Overlays ? window.Overlays.count() : -1, marker: window.__marker, lock: document.documentElement.style.overflow, url: location.pathname + location.search }));
const opener = '[wire\\:click="create"]';

await page.goto(`${BASE}/admin/brands`);
await settle(page);
await page.evaluate(() => { window.__marker = 'same-document'; });

// 1. Back closes the window and keeps the page
await page.click(opener); await page.waitForTimeout(600);
let s = await st();
check('open pushes one history entry and locks the page scroll', s.ov === 1 && s.count === 1 && s.lock === 'hidden', JSON.stringify(s));
await page.evaluate(() => history.back()); await page.waitForTimeout(700);
s = await st();
check('Back closes the window and stays on the page', (await visible()).length === 0 && s.marker === 'same-document' && !s.ov && s.lock === '', JSON.stringify(s));

// 2. Esc closes; focus returns to the opener; no stale entry is left
await page.click(opener); await page.waitForTimeout(500);
await page.keyboard.press('Escape'); await page.waitForTimeout(700);
s = await st();
check('Esc closes the window', (await visible()).length === 0 && s.count === 0, JSON.stringify(s));
check('focus returns to the button that opened it', await page.evaluate(() => document.activeElement?.getAttribute('wire:click') === 'create'));

// 3. scrim and ✕
await page.click(opener); await page.waitForTimeout(500);
await (phone ? page.mouse.click(width / 2, 12) : page.mouse.click(60, 400)); await page.waitForTimeout(600);
check('scrim closes the window', (await visible()).length === 0);
await page.click(opener); await page.waitForTimeout(500);
await page.click('[role=dialog] button[aria-label]'); await page.waitForTimeout(600);
check('✕ closes the window', (await visible()).length === 0);

// 4. toast above the window and clear of the pinned action row
await page.click(opener); await page.waitForTimeout(500);
await page.evaluate(() => window.dispatchEvent(new CustomEvent('toast', { detail: { type: 'success', message: 'Saved' } })));
await page.waitForTimeout(500);
const t = await page.evaluate(() => {
    const el = [...document.querySelectorAll('[aria-live=polite] .card')].pop();
    if (!el) return null;
    const b = el.getBoundingClientRect();
    const top = document.elementFromPoint(b.x + b.width / 2, b.y + b.height / 2);
    const row = [...document.querySelectorAll('.drawer-body form > div:last-child')].filter((e) => e.offsetParent !== null).pop();
    return { onTop: !!top && el.contains(top), clear: !row || b.bottom <= row.getBoundingClientRect().top + 2 };
});
check('toast is visible above the window', !!t && t.onTop, JSON.stringify(t));
check('toast does not cover the window’s action row', !!t && t.clear, JSON.stringify(t));
await page.keyboard.press('Escape'); await page.waitForTimeout(500);

// 5. wire:navigate from inside a window
await page.goto(`${BASE}/admin/brands`); await settle(page);
await page.click(opener); await page.waitForTimeout(500);
await page.evaluate(() => document.querySelector('[role=dialog] .drawer-body').insertAdjacentHTML('beforeend', '<a id="navlink" wire:navigate href="/admin/zones">go</a>'));
await page.waitForTimeout(100);
await page.evaluate(() => document.getElementById('navlink').click());
await page.waitForURL(/\/admin\/zones/, { timeout: 8000 }).catch(() => {});
await settle(page, 600);
s = await st();
check('navigating from a window reaches the page, closes the window and leaves the scroll unlocked', /\/admin\/zones/.test(s.url) && s.count === 0 && s.lock === '', JSON.stringify(s));
await page.goBack(); await page.waitForTimeout(900);
s = await st();
check('Back from there returns straight to the list (no stale window entry)', /\/admin\/brands$/.test(s.url) && !s.ov && (await visible()).length === 0, JSON.stringify(s));

// 6. unsaved input asks before closing
await page.click(opener); await page.waitForTimeout(600);
await page.keyboard.press('Escape'); await page.waitForTimeout(700);
check('a clean form closes without asking', (await visible()).length === 0);
await page.click(opener); await page.waitForTimeout(600);
await page.locator('[role=dialog] .drawer-body input.input').first().fill('Draft brand'); await page.waitForTimeout(100);
await page.click('[role=dialog] button[aria-label]'); await page.waitForTimeout(500);
let v = await visible();
check('✕ on a form with input asks to discard', v.length === 2 && v[1].startsWith('alertdialog'), JSON.stringify(v));
check('"Keep editing" is focused', await page.evaluate(() => document.activeElement?.hasAttribute('data-cancel')));
await page.keyboard.press('Escape'); await page.waitForTimeout(500);
v = await visible(); s = await st();
check('Esc closes only the question; the window and the typed text stay', v.length === 1 && s.count === 1 && (await page.locator('[role=dialog] .drawer-body input.input').first().inputValue()) === 'Draft brand', JSON.stringify({ v, s }));
await page.evaluate(() => history.back()); await page.waitForTimeout(900);
v = await visible(); s = await st();
check('Back on a form with input asks and keeps the window', v.length === 2 && s.count === 2, JSON.stringify({ v, s }));
await page.keyboard.press('Escape'); await page.waitForTimeout(400);
await page.click('[role=dialog] button[aria-label]'); await page.waitForTimeout(500);
await page.click('[role=alertdialog] [data-x=ok]'); await page.waitForTimeout(800);
s = await st();
check('Discard closes the window and its history entry', (await visible()).length === 0 && s.count === 0 && !s.ov, JSON.stringify(s));

// 7. a themed confirm above a window: Esc and Back close only the confirm
await page.goto(`${BASE}/admin/transfers`); await settle(page);
const row = page.locator('a[wire\\:click\\.prevent^="openView"]').first();
if (await row.count()) {
    await row.click(); await page.waitForTimeout(1500);
    const approve = page.locator('[role=dialog] button[wire\\:click="approve"]:visible').first();
    if (await approve.count()) {
        await approve.click(); await page.waitForTimeout(600);
        v = await visible(); s = await st();
        check('a wire:confirm opens above the window', v.length === 2 && v[1].startsWith('alertdialog') && s.count === 2, JSON.stringify({ v, s }));
        await page.keyboard.press('Escape'); await page.waitForTimeout(500);
        v = await visible(); s = await st();
        check('Esc closes only the confirm', v.length === 1 && s.count === 1, JSON.stringify({ v, s }));
        await approve.click(); await page.waitForTimeout(600);
        await page.evaluate(() => history.back()); await page.waitForTimeout(800);
        v = await visible(); s = await st();
        check('Back closes only the confirm', v.length === 1 && s.count === 1, JSON.stringify({ v, s }));
        check('focus returns to the Approve button', await page.evaluate(() => document.activeElement?.getAttribute('wire:click') === 'approve'));
    } else {
        check('transfer window offers Approve (seed a transfer awaiting approval)', false);
    }
} else {
    check('a transfer exists to open', false, 'seed one');
}

check('no console or page errors', errors.length === 0, errors.slice(0, 3).join(' | '));
await ctx.close();
await browser.close();
done();
