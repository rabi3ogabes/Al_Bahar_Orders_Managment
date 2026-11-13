/**
 * Browser check of the cart panel ("Add to cart" opens a panel over the page; it never navigates).
 * Not part of `npm test` (that runs the fast unit tests). Needs a built store served together with its API, and Playwright:
 *
 *   STORE_URL=http://127.0.0.1:4173 node tests/e2e/cart-panel.e2e.mjs            # guests (browser cart)
 *   STORE_URL=... E2E_PHONE=+97455123456 E2E_PASSWORD=... node tests/e2e/cart-panel.e2e.mjs   # also signed in (server cart, Checkout navigates)
 *
 * Runs at 375 px (bottom sheet) and 1280 px (end-side drawer), in English and Arabic. Exit code 1 if anything fails.
 */
import { createRequire } from 'node:module';
const { chromium } = createRequire(import.meta.url)('playwright');

const BASE = process.env.STORE_URL ?? 'http://127.0.0.1:4173';
const API = `${BASE}/api/v1`;
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const CONTEXTS = [
  { name: '1280 EN', w: 1280, h: 800, lang: 'en' }, { name: '1280 AR', w: 1280, h: 800, lang: 'ar' },
  { name: '375 EN', w: 375, h: 760, lang: 'en', phone: true }, { name: '375 AR', w: 375, h: 760, lang: 'ar', phone: true },
];
const TEXT = {
  en: { add: 'Add to cart', added: 'Added to cart', checkout: 'Checkout', keep: 'Keep shopping', more: 'Increase quantity' },
  ar: { add: 'أضف إلى السلة', added: 'تمت الإضافة إلى السلة', checkout: 'إتمام الشراء', keep: 'متابعة التسوّق', more: 'زيادة الكمية' },
};
let failures = 0;
const check = (ctx, name, ok, extra = '') => { if (!ok) failures += 1; console.log(`${ok ? 'PASS' : 'FAIL'} [${ctx}] ${name}${extra ? ` (${extra})` : ''}`); };

async function signIn() {
  if (!process.env.E2E_PHONE) return null;
  const r = await fetch(`${API}/auth/login`, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify({ phone: process.env.E2E_PHONE, password: process.env.E2E_PASSWORD, device_name: 'e2e', platform: 'web' }) });
  const tokens = (await r.json()).data.tokens;
  return { tokens, headers: { 'Content-Type': 'application/json', Accept: 'application/json', Authorization: `Bearer ${tokens.access_token}` } };
}
/** Every run starts from an empty server cart. */
async function emptyCart(session) {
  const cart = (await (await fetch(`${API}/cart`, { headers: session.headers })).json()).data;
  for (const i of cart.items) await fetch(`${API}/cart/items/${i.id}`, { method: 'DELETE', headers: session.headers });
}

async function run(browser, c, session) {
  const t = TEXT[c.lang];
  if (session) await emptyCart(session);
  const ctx = await browser.newContext({ viewport: { width: c.w, height: c.h }, hasTouch: !!c.phone, isMobile: !!c.phone });
  await ctx.addInitScript(([lang, tokens]) => { localStorage.setItem('albahar.locale', lang); localStorage.setItem('albahar.theme', 'light'); if (tokens) localStorage.setItem('albahar.tokens', JSON.stringify(tokens)); }, [c.lang, session?.tokens ?? null]);
  await ctx.route('**/api/v1/realtime-config', (r) => r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: { enabled: false, key: null, auth_endpoint: '', path: '' } }) }));
  const page = await ctx.newPage();
  const label = `${c.name}${session ? ' signed in' : ' guest'}`;
  await page.goto(`${BASE}/shop`);
  await page.waitForSelector('article');
  await sleep(500);
  if (!session) await page.evaluate(() => localStorage.removeItem('albahar.guest_cart'));
  // the server answers slowly: the page must not wait for it
  await page.route('**/api/v1/cart/items**', async (route) => { await sleep(1200); await route.continue(); });
  await page.evaluate(() => { window.__still = 'here'; });

  const card = page.locator('article').filter({ has: page.locator(`button[aria-label="${t.add}"]`) }).nth(1);
  const addBtn = card.locator(`button[aria-label="${t.add}"]`);
  await card.scrollIntoViewIfNeeded();
  await page.evaluate(() => window.scrollBy(0, 120));
  await sleep(300);
  const url0 = page.url();
  const y0 = await page.evaluate(() => Math.round(scrollY));
  const badge = () => page.locator('header button[aria-label$=")"]').first().getAttribute('aria-label');
  const dialog = page.locator('[role=dialog]');

  if (!c.phone) await card.hover();
  await addBtn.click();
  await sleep(150);
  check(label, 'header badge shows 1 at once, before the server answers', /\(1\)/.test(await badge() ?? ''));
  await dialog.first().waitFor();
  await sleep(600);
  check(label, 'cart panel opened, no navigation, no reload', (await dialog.count()) === 1 && page.url() === url0 && (await page.evaluate(() => window.__still)) === 'here');
  const lines = dialog.locator('[data-testid=cart-line]');
  check(label, '"Added to cart" with the item (photo, name, price) and quantity 1', (await dialog.getByText(t.added, { exact: true }).count()) >= 1 && (await lines.count()) === 1 && (await lines.first().locator('img, [role=img]').count()) >= 1 && (await lines.first().locator('[data-testid=line-qty]').innerText()) === '1');
  const footer = await dialog.locator('div.border-t.bg-paper button').allInnerTexts();
  check(label, 'footer has exactly two buttons: Checkout and Keep shopping', footer.length === 2 && footer.some((x) => x.includes(t.checkout)) && footer.some((x) => x.includes(t.keep)), JSON.stringify(footer));
  const sub1 = await dialog.getByTestId('cart-subtotal').innerText();
  await lines.first().getByRole('button', { name: t.more }).click();
  await sleep(150);
  check(label, 'quantity + changes the subtotal and the badge immediately', (await lines.first().locator('[data-testid=line-qty]').innerText()) === '2' && (await dialog.getByTestId('cart-subtotal').innerText()) !== sub1 && /\(2\)/.test(await badge() ?? ''));
  await sleep(6000);
  check(label, 'still open after 6 s (no auto-hide)', (await dialog.count()) === 1);

  await dialog.getByRole('button', { name: t.keep }).click();
  await sleep(900);
  check(label, 'Keep shopping: closed, same page, same scroll position', (await dialog.count()) === 0 && page.url() === url0 && Math.abs((await page.evaluate(() => Math.round(scrollY))) - y0) < 3, `scroll ${y0} -> ${await page.evaluate(() => Math.round(scrollY))}`);
  check(label, 'Keep shopping: focus is back on the Add button', (await page.evaluate(() => document.activeElement?.getAttribute('aria-label'))) === t.add);

  if (!c.phone) await card.hover();
  await addBtn.click();
  await dialog.first().waitFor();
  await sleep(2600);
  check(label, 'Add again on the same item: one line, quantity 3 (2 + 1), badge 3', (await lines.count()) === 1 && (await lines.first().locator('[data-testid=line-qty]').innerText()) === '3' && /\(3\)/.test(await badge() ?? ''));
  if (session) {
    const cart = (await (await fetch(`${API}/cart`, { headers: session.headers })).json()).data;
    check(label, 'server cart agrees: one line, quantity 3', cart.items.length === 1 && cart.items[0].quantity === 3, JSON.stringify(cart.items.map((i) => i.quantity)));
  } else {
    const rows = await page.evaluate(() => JSON.parse(localStorage.getItem('albahar.guest_cart') || '[]'));
    check(label, 'browser cart agrees: one row, quantity 3', rows.length === 1 && rows[0].qty === 3);
  }

  await page.keyboard.press('Escape');
  await sleep(900);
  check(label, 'Esc closes it', (await dialog.count()) === 0 && page.url() === url0);
  await addBtn.click();
  await dialog.first().waitFor();
  await sleep(700);
  const box = await dialog.first().boundingBox();
  await page.mouse.click(c.phone ? c.w / 2 : (box.x > 100 ? 10 : c.w - 10), c.phone ? 20 : 300);
  await sleep(900);
  check(label, 'outside click closes it', (await dialog.count()) === 0 && page.url() === url0);
  await addBtn.click();
  await dialog.first().waitFor();
  await sleep(700);
  await page.goBack();
  await sleep(900);
  check(label, 'Back closes only the panel (still on the same page)', (await dialog.count()) === 0 && page.url() === url0, page.url());

  await addBtn.click();
  await dialog.first().waitFor();
  await sleep(700);
  await dialog.getByRole('button', { name: t.checkout }).click();
  await sleep(2600);
  if (session) check(label, 'Checkout goes to the checkout page', page.url().endsWith('/checkout'), page.url());
  else check(label, 'Checkout as a guest asks to sign in first (same guard as the cart page)', page.url() === url0 && (await dialog.count()) >= 1);
  await ctx.close();
}

const browser = await chromium.launch();
const session = await signIn();
for (const c of CONTEXTS) await run(browser, c, null);
if (session) for (const c of CONTEXTS) await run(browser, c, session);
await browser.close();
console.log(failures ? `\n${failures} check(s) failed` : '\nall checks passed');
process.exit(failures ? 1 : 0);
