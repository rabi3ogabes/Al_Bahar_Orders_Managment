// A decision modal opened over a side window must keep its own plain button row: the window's pinned action row (.drawer-body rules in
// resources/css/app.css) must not match it, so there is no sticky band under the modal's buttons.
// Opens a customer in the Customers list window, then "Suspend" (a modal inside the window). 375 and 1280 px, English and Arabic, light and dark.
// Usage: CUSTOMER=<customer uuid> ADMIN_EMAIL=... ADMIN_PASSWORD=... node tests/e2e-side-windows/modal-over-drawer.mjs
import { chromium, BASE, SHOTS, check, done, signIn, openPage, settle } from './lib.mjs';

const customer = process.env.CUSTOMER;
if (!customer) throw new Error('Set CUSTOMER to the id of a customer (the list window opens it with ?view=).');

const browser = await chromium.launch();
const state = await signIn(browser);

for (const lang of ['en', 'ar']) {
    for (const [width, height] of [[375, 780], [1280, 800]]) {
        for (const theme of ['light', 'dark']) {
            const tag = `${lang} ${width}px ${theme}`;
            const { ctx, page, errors } = await openPage(browser, state, { lang, theme, width, height });
            await page.goto(`${BASE}/admin/customers?view=${customer}`);
            await settle(page, 900);
            const opener = page.locator('[role=dialog]:visible button[wire\\:click^="askStatus"]:visible').first();
            if (!(await opener.count())) { check(`${tag}: customer window offers a status decision`, false, 'no askStatus button'); await ctx.close(); continue; }
            await opener.click();
            await page.waitForTimeout(700);
            const m = await page.evaluate(() => {
                const modal = [...document.querySelectorAll('[role=alertdialog]')].find((d) => getComputedStyle(d).display !== 'none');
                if (!modal) return null;
                const form = modal.querySelector('form');
                const row = form && form.querySelector(':scope > div:last-child');
                if (!row) return { noRow: true };
                const cs = getComputedStyle(row);
                const r = row.getBoundingClientRect();
                const card = modal.querySelector('.card').getBoundingClientRect();
                return {
                    position: cs.position, bg: cs.backgroundColor, borderTop: cs.borderTopWidth, marginInline: [cs.marginLeft, cs.marginRight],
                    insideCard: r.left >= card.left - 0.5 && r.right <= card.right + 0.5,
                    // the modal's buttons sit at the end of the form like in any other decision, not stretched to the window's bottom edge
                    bottomGap: Math.round(card.bottom - r.bottom),
                };
            });
            check(`${tag}: decision modal opens over the window`, !!m && !m.noRow, JSON.stringify(m));
            if (m && !m.noRow) {
                check(`${tag}: modal button row is not pinned (position)`, m.position !== 'sticky', m.position);
                check(`${tag}: no coloured band behind the buttons`, m.bg === 'rgba(0, 0, 0, 0)' || m.bg === 'transparent', m.bg);
                check(`${tag}: no top border band`, m.borderTop === '0px', m.borderTop);
                check(`${tag}: no negative margins pulling the row out of the card`, m.insideCard, JSON.stringify(m.marginInline));
            }
            await page.screenshot({ path: `${SHOTS}/modal-over-drawer-${lang}-${width}-${theme}.png` });
            check(`${tag}: no console or page errors`, errors.length === 0, errors.join(' | '));
            await ctx.close();
        }
    }
}
await browser.close();
done();
