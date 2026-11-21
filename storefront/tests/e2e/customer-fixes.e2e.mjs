/**
 * Browser check of the customer-experience fixes: the sign-in that survives two tabs (and a deploy), the "no way to pay" checkout, the colour / material
 * filters, the return status card, the driver's call button, the invoice download after the sign-in expired, and the terms notice at sign-up.
 * Every response is mocked, so only the built store has to be served:
 *
 *   npx vite build --outDir /tmp/cx-dist --emptyOutDir && npx vite preview --outDir /tmp/cx-dist --port 4175 &
 *   STORE_URL=http://127.0.0.1:4175 DIST=/tmp/cx-dist SHOTS=/tmp/cx-shots node tests/e2e/customer-fixes.e2e.mjs
 *
 * Not part of `npm test` (needs Chromium). Exit code 1 on any failure.
 */
import { createRequire } from 'node:module';
import { mkdirSync, readFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
const { chromium } = createRequire(import.meta.url)('playwright');

const BASE = process.env.STORE_URL ?? 'http://127.0.0.1:4175';
const DIST = process.env.DIST ?? new URL('../../dist', import.meta.url).pathname;
const SHOTS = process.env.SHOTS ?? join(tmpdir(), 'cx-shots');
mkdirSync(SHOTS, { recursive: true });
const INDEX = readFileSync(join(DIST, 'index.html'), 'utf8');
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

let failures = 0;
const check = (ctx, name, ok, extra = '') => { if (!ok) failures += 1; console.log(`${ok ? 'PASS' : 'FAIL'} [${ctx}] ${name}${extra ? ` (${extra})` : ''}`); };

const money = (v, lang = 'en') => ({ amount: Number(v).toFixed(2), currency: 'QAR', formatted: lang === 'ar' ? `${Number(v).toLocaleString('en-GB', { minimumFractionDigits: 2 })} ر.ق` : `QAR ${Number(v).toLocaleString('en-GB', { minimumFractionDigits: 2 })}` });
const BRANCHES = [
  { id: 'br1', code: 'DOH', name: 'Doha Showroom', description: null, phone: '+97444123456', email: null, address: 'Salwa Road', area: 'Doha', zone_number: '55', latitude: null, longitude: null, pickup_enabled: true, delivery_enabled: true, cod_enabled: true, pay_at_pickup_enabled: true, opening: { is_open: true, reason: 'open', closes_at: null, next_open_at: null } },
];
const USER = { id: 'u1', type: 'customer', name: 'Sara Al Thani', phone: '+97455123456', alternate_phone: null, email: null, phone_verified: true, locale: 'en', avatar_url: null, date_of_birth: null, gender: null, referral_code: 'SARA', marketing_consent: false, has_password: true, created_at: '2026-01-01' };
const ADDRESS = { id: 'a1', label: 'Home', recipient_name: 'Sara Al Thani', recipient_phone: '+97455123456', delivery_zone: { id: 'z1', name: 'Zone 1' }, zone_number: '55', street_number: '12', building_number: '4', unit_number: null, area: 'West Bay', city: 'Doha', landmark: null, instructions: null, latitude: null, longitude: null, is_default: true, summary: 'Zone 55, Street 12, Building 4' };
const card = (lang) => ({ id: 'p1', slug: 'oslo-sofa', sku: 'S1', name: lang === 'ar' ? 'أريكة أوسلو' : 'Oslo Sofa', short_description: 'x', brand: null, image: null, price: { regular: money(4100, lang), sale: null, final: money(4100, lang), discount_percent: 0, sale_ends_at: null, source: 'base' }, rating: { average: 0, count: 0 }, badges: [], in_stock: true, purchasable: true, has_variants: false, variant_id: 'v1' });
const cartOf = (lang) => ({ id: 'c', branch_id: 'br1', fulfillment_type: 'delivery', address_id: null, coupon_code: null, loyalty_points: 0, items_count: 2, subtotal: money(8200, lang), warnings: [],
  items: [{ id: 'ci1', variant_id: 'v1', product: card(lang), deal: null, variant_name: 'Grey', quantity: 2, unit_price: card(lang).price, line_total: money(8200, lang), available: true, available_quantity: 9, price_changed: false, previous_price: null, issue: null }] });

function quoteOf(lang, body, sc) {
  const M = (v) => money(v, lang);
  const pickup = body.fulfillment_type === 'pickup';
  const methods = sc.methods ?? [
    { code: 'sadad', available: false, reason: 'temporarily_unavailable' },
    { code: 'cod', available: false, reason: 'outside_cod_limits', limits: { min: null, max: money(5000, lang) } },
    { code: 'pay_at_pickup', available: pickup, reason: pickup ? null : 'pickup_only' },
  ];
  return {
    quote_hash: `h-${body.fulfillment_type}`, currency: 'QAR', fulfillment_type: body.fulfillment_type, payment_method: body.payment_method || null, is_split: false, errors: [], can_place_order: true,
    fulfillments: [{ branch: { id: 'br1', name: 'Doha Showroom', area: 'Doha' }, type: body.fulfillment_type, items: [], subtotal: M(8200), delivery_fee: M(pickup ? 0 : 0), eta_minutes: 120, slot: null, slot_required: false, allocation_reason: 'x' }],
    lines: [], totals: { subtotal: M(8200), discount: M(0), delivery_fee: M(0), extra_delivery_fee: M(0), service_fee: M(0), tax: M(0), loyalty_discount: M(0), grand_total: M(8200), deal_savings: M(0) },
    delivery_extras: [], discounts: [], payment_methods: methods, loyalty: { balance: 0, max_redeemable_points: 0, points_applied: 0, points_to_earn: 0 }, warnings: [],
  };
}

const orderOf = (lang, sc) => ({
  id: 'o1', number: 'AB-1001', status: 'completed', status_label: 'Completed', payment_status: 'paid', payment_method: 'cod', fulfillment_type: 'delivery', items_count: 2, grand_total: money(8200, lang), placed_at: new Date().toISOString(), thumbnail_url: null,
  totals: { subtotal: money(8200, lang), discount: money(0, lang), delivery_fee: money(0, lang), grand_total: money(8200, lang) }, discounts: [], address: null, customer_notes: null,
  items: [{ id: 'oi1', product_id: 'p1', variant_id: 'v1', sku: 'S1', name: 'Oslo Sofa', variant_name: 'Grey', image_url: null, quantity: 2, quantity_returned: 0, returnable_quantity: sc.returnable ?? 2, fulfillment_id: 'f1', unit_price: money(4100, lang), line_total: money(8200, lang), deal: null }],
  fulfillments: [{ id: 'f1', number: 'F1', type: 'delivery', status: sc.fStatus ?? 'delivered', status_label: 'Delivered', branch: { id: 'br1', name: 'Doha Showroom', phone: '+97444123456', latitude: null, longitude: null }, subtotal: money(8200, lang), delivery_fee: money(0, lang), slot: null,
    delivery: sc.driverPhone === undefined ? null : { tracking_number: 'T1', status: 'out_for_delivery', status_label: 'On the way', provider: 'internal', driver_name: 'Omar', driver_phone: sc.driverPhone, eta: null }, pickup: null, timestamps: {} }],
  can_cancel: false, can_pay: false, can_return: sc.canReturn ?? false, return_request: sc.returnRequest ?? null,
});

/** One browser context with a mocked store: a server that knows which access token is valid and rotates (and punishes a reused) refresh token. */
async function install(ctx, sc) {
  const lang = sc.lang ?? 'en';
  const S = { access: 'a2', refresh: 'r2', revoked: false, refreshCalls: 0, refreshBodies: [], quotes: [], productQueries: [], invoice401s: 0, returnPosts: [], requests: [], ...sc.state };
  await ctx.addInitScript(([l, tokens]) => {
    try { if (!sessionStorage.getItem('cx-boot')) { sessionStorage.setItem('cx-boot', '1'); localStorage.setItem('albahar.locale', l); if (tokens && !localStorage.getItem('albahar.tokens')) localStorage.setItem('albahar.tokens', JSON.stringify(tokens)); } } catch { /* ignore */ }
  }, [lang, sc.signed === false ? null : { access_token: sc.staleAccess ?? 'a1', refresh_token: sc.staleRefresh ?? 'r2', token_type: 'Bearer', expires_in: 3600, session_id: 's' }]);
  await ctx.route((u) => u.origin === new URL(BASE).origin && !u.pathname.startsWith('/store/') && !u.pathname.startsWith('/api/'), (route) => {
    if (route.request().resourceType() === 'document') return route.fulfill({ status: 200, contentType: 'text/html', body: INDEX });
    return route.fulfill({ status: 404, body: '' });
  });
  await ctx.route('**/api/v1/**', async (route) => {
    const req = route.request(); const url = new URL(req.url()); const path = url.pathname.replace(/^.*\/api\/v1/, ''); const method = req.method();
    const json = (data, status = 200) => route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(data) });
    S.requests.push(`${method} ${path}`);
    const bearer = (req.headers().authorization ?? '').replace(/^Bearer /, '');
    const authed = !S.revoked && bearer === S.access;
    const needAuth = () => json({ message: 'Unauthenticated.', code: 'unauthenticated' }, 401);
    if (path === '/auth/refresh') {
      S.refreshCalls += 1; const body = JSON.parse(req.postData() || '{}'); S.refreshBodies.push(body.refresh_token);
      if (sc.refreshDelay) await sleep(sc.refreshDelay);
      if (S.refreshMode === 'down') return json({ message: 'Service unavailable', code: 'unavailable' }, 503);
      if (S.refreshMode === 'busy') return json({ message: 'Too many', code: 'too_many_requests' }, 429);
      if (S.refreshMode === 'refuse') return json({ message: 'Invalid', code: 'refresh_invalid' }, 401);
      if (S.revoked || body.refresh_token !== S.refresh) { S.revoked = true; return json({ message: 'Invalid', code: 'refresh_invalid' }, 401); } // a used token coming back: the whole sign-in ends
      const n = Number(S.access.slice(1)) + 1; S.access = `a${n}`; S.refresh = `r${n}`;
      return json({ data: { tokens: { access_token: S.access, refresh_token: S.refresh, token_type: 'Bearer', expires_in: 3600, session_id: 's' } } });
    }
    if (path === '/auth/logout') return json({ data: { logged_out: true } });
    if (path === '/theme') return json({ message: 'none' }, 404);
    if (path === '/realtime-config') return json({ data: { enabled: false, key: null, auth_endpoint: '', path: '' } });
    if (path === '/app-config') return json({ data: { alternate_phone_enabled: false } });
    if (path === '/branches') return json({ data: BRANCHES });
    if (path === '/referrals/status') return json({ data: { enabled: false } });
    if (path === '/me' && method === 'GET') { if (sc.meDelay) await sleep(sc.meDelay); return authed ? json({ data: { ...USER, locale: lang } }) : needAuth(); }
    if (path === '/me/addresses') return authed ? json({ data: [ADDRESS] }) : needAuth();
    if (path === '/cart') return authed ? json({ data: cartOf(lang) }) : needAuth();
    if (path === '/cart/suggestions') return json({ data: [] });
    if (path === '/checkout/quote') { const b = JSON.parse(req.postData() || '{}'); S.quotes.push(b); return authed ? json({ data: quoteOf(lang, b, sc) }) : needAuth(); }
    if (/\/branches\/[^/]+\/(delivery|pickup)-slots$/.test(path)) return json({ data: [] });
    if (path === '/categories') return json({ data: [{ id: 'c-sofas', slug: 'sofas', name: lang === 'ar' ? 'كنب' : 'Sofas', description: null, image_url: null, icon_url: null, banner_url: null, children: [] }, { id: 'c-dining', slug: 'dining', name: 'Dining', description: null, image_url: null, icon_url: null, banner_url: null, children: [] }] });
    const cm = path.match(/^\/categories\/([^/]+)$/);
    if (cm) return json({ data: { id: cm[1], slug: cm[1], name: cm[1], filters: [
      { attribute: { code: 'colour', name: lang === 'ar' ? 'اللون' : 'Colour', type: 'color' }, values: [{ id: 11, code: 'grey', name: lang === 'ar' ? 'رمادي' : 'Grey', color_hex: '#888888' }, { id: 12, code: 'beige', name: lang === 'ar' ? 'بيج' : 'Beige', color_hex: '#d8c7a5' }] },
      { attribute: { code: 'material', name: lang === 'ar' ? 'الخامة' : 'Material', type: 'select' }, values: [{ id: 21, code: 'linen', name: lang === 'ar' ? 'كتان' : 'Linen', color_hex: null }] }] } });
    if (path === '/brands') return json({ data: [] });
    if (path === '/products' || path === '/products/search') { S.productQueries.push(url.search); return json({ data: [card(lang)], meta: { current_page: 1, last_page: 1, total: 1, per_page: 24 } }); }
    if (path === '/orders/o1') return authed ? json({ data: orderOf(lang, sc) }) : needAuth();
    if (path === '/orders/o1/timeline') return authed ? json({ data: [] }) : needAuth();
    if (path === '/orders/o1/invoice') {
      if (!authed) { S.invoice401s += 1; return needAuth(); }
      return route.fulfill({ status: 200, contentType: 'application/pdf', body: '%PDF-1.4 fake' });
    }
    if (path === '/orders/o1/returns' && method === 'POST') { S.returnPosts.push(JSON.parse(req.postData() || '{}')); return sc.returnReply ? json(sc.returnReply.body, sc.returnReply.status) : json({ data: { id: 'rr1', number: 'RT1', status: 'requested' } }, 201); }
    if (path === '/orders') return json({ data: [], meta: { current_page: 1, last_page: 1, total: 0 } });
    return json({ data: [], meta: { current_page: 1, total: 0, last_page: 1 } });
  });
  return S;
}

async function open(browser, sc, path, { w = 1280 } = {}) {
  const ctx = await browser.newContext({ viewport: { width: w, height: 900 }, acceptDownloads: true });
  const S = await install(ctx, sc);
  const page = await ctx.newPage();
  const errors = [];
  page.on('console', (m) => { if (m.type() === 'error' && !/^Failed to load resource/.test(m.text())) errors.push(`console: ${m.text()}`); });
  page.on('pageerror', (e) => errors.push(`pageerror: ${e.message}`));
  if (path) await page.goto(`${BASE}${path}`);
  return { ctx, page, S, errors };
}
const tokensOf = (page) => page.evaluate(() => { try { return JSON.parse(localStorage.getItem('albahar.tokens') || 'null'); } catch { return 'bad'; } });
const shot = (page, name) => page.screenshot({ path: join(SHOTS, `${name}.png`), fullPage: true });
const noScroll = (page) => page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth);

/* ───────────── 1. The sign-in across two tabs ───────────── */
async function sessions(browser) {
  // Two tabs open at once, the access token has expired: one refresh, both tabs end signed in. A second refresh with the old token would sign everyone out.
  {
    const { ctx, page, S, errors } = await open(browser, { staleAccess: 'a1', meDelay: 150, refreshDelay: 400 }, null);
    const page2 = await ctx.newPage();
    page2.on('pageerror', (e) => errors.push(`pageerror2: ${e.message}`));
    await Promise.all([page.goto(`${BASE}/cart`), page2.goto(`${BASE}/cart`)]);
    await sleep(2500);
    check('two tabs', 'one refresh for both tabs', S.refreshCalls === 1, `calls=${S.refreshCalls}`);
    check('two tabs', 'the server never saw an old refresh token again (nobody was signed out)', !S.revoked);
    const t1 = await tokensOf(page), t2 = await tokensOf(page2);
    check('two tabs', 'both tabs hold the new tokens', t1?.access_token === 'a3' && t2?.access_token === 'a3', `${t1?.access_token} ${t2?.access_token}`);
    check('two tabs', 'both still show the signed-in account button', (await page.getByLabel('My account').count()) > 0 && (await page2.getByLabel('My account').count()) > 0);
    // Another tab refreshing keeps this tab's memory in step (no refresh of its own on the next call).
    await page.evaluate(() => fetch('/api/v1/me', { headers: { Authorization: `Bearer ${JSON.parse(localStorage.getItem('albahar.tokens')).access_token}` } }));
    check('two tabs', 'no extra refresh later', S.refreshCalls === 1);
    check('two tabs', 'no page errors', errors.length === 0, errors.slice(0, 2).join(' | '));
    await ctx.close();
  }
  // A deploy in progress (the refresh answers 503): the person stays signed in, nothing scary, and the next try after the wait works.
  {
    const { ctx, page, S } = await open(browser, { staleAccess: 'a1', state: { refreshMode: 'down' } }, '/cart');
    await sleep(1800);
    const t = await tokensOf(page);
    check('503', 'tokens are kept after a failed refresh', t?.access_token === 'a1' && t?.refresh_token === 'r2', JSON.stringify(t));
    S.refreshMode = null;
    // Nobody reloads the page: the account is asked again by itself and, the server being back, the signed-in button comes back.
    await page.waitForSelector('[aria-label="My account"]', { timeout: 20_000 });
    const t2 = await tokensOf(page);
    check('503', 'the account is asked again by itself and the signed-in button comes back', true);
    check('503', 'the sign-in goes on with the renewed tokens and nobody was signed out', t2?.access_token === 'a3' && !S.revoked, JSON.stringify(t2));
    await ctx.close();
  }
  // 429 on the refresh: kept too.
  {
    const { ctx, page } = await open(browser, { staleAccess: 'a1', state: { refreshMode: 'busy' } }, '/cart');
    await sleep(1500);
    const t = await tokensOf(page);
    check('429', 'tokens are kept', t?.access_token === 'a1');
    await ctx.close();
  }
  // A clear refusal ends the sign-in, in this tab and the other one.
  {
    const { ctx, page, S } = await open(browser, { staleAccess: 'a1', state: { refreshMode: 'refuse' } }, null);
    const page2 = await ctx.newPage();
    await page.goto(`${BASE}/cart`); await sleep(1500);
    await page2.goto(`${BASE}/cart`); await sleep(800);
    check('401', 'the tokens are cleared', (await tokensOf(page)) === null && (await tokensOf(page2)) === null);
    check('401', 'the sign-in button is back', (await page.getByLabel('Sign in').count()) > 0);
    check('401', 'refused once is not asked again and again', S.refreshCalls <= 2, `calls=${S.refreshCalls}`);
    await ctx.close();
  }
  // Signing out in one tab signs out the other at once.
  {
    const { ctx, page } = await open(browser, { staleAccess: 'a2' }, null);
    const page2 = await ctx.newPage();
    await Promise.all([page.goto(`${BASE}/cart`), page2.goto(`${BASE}/cart`)]);
    await sleep(1200);
    check('logout', 'both tabs start signed in', (await page.getByLabel('My account').count()) > 0 && (await page2.getByLabel('My account').count()) > 0);
    await page.evaluate(() => localStorage.removeItem('albahar.tokens')); // what the sign-out of one tab leaves behind
    await sleep(800);
    check('logout', 'the other tab is signed out too', (await page2.getByLabel('Sign in').count()) > 0 && (await page2.getByLabel('My account').count()) === 0);
    await ctx.close();
  }
}

/* ───────────── 2. No way to pay ───────────── */
async function noPayment(browser) {
  for (const [lang, w] of [['en', 1280], ['en', 375], ['ar', 1280], ['ar', 375]]) {
    const id = `no payment ${lang} ${w}`;
    const { ctx, page, errors } = await open(browser, { lang, staleAccess: 'a2' }, '/checkout', { w });
    await page.waitForSelector('[data-testid=no-payment]', { timeout: 15_000 });
    const text = await page.locator('main, #root').first().innerText();
    if (lang === 'en') {
      check(id, 'cash on delivery says the limit and the basket', text.includes('Cash on delivery is available up to QAR 5,000.00. Your basket is QAR 8,200.00.'));
      check(id, 'online payment says it is not available now', text.includes('Online payment is not available right now'));
    } else {
      check(id, 'the sentences are Arabic with the numbers', /5,000\.00/.test(text) && /8,200\.00/.test(text) && /الدفع عند الاستلام متاح حتى/.test(text));
      check(id, 'right to left', await page.evaluate(() => document.documentElement.dir === 'rtl'));
    }
    check(id, 'no raw key shown', !/pay\.(reason|none)\./.test(text));
    const panel = page.locator('[data-testid=no-payment]');
    check(id, 'the panel offers the showroom to collect from', (await panel.getByRole('button').count()) >= 1);
    const tel = await panel.locator('a[href^="tel:"]').getAttribute('href');
    check(id, 'the call link uses the showroom number the API sent', tel === 'tel:+97444123456', String(tel));
    const wa = await panel.locator('a[href^="https://wa.me/"]').getAttribute('href');
    check(id, 'the WhatsApp link carries the basket and the total', !!wa && decodeURIComponent(wa).includes(lang === 'ar' ? 'أريكة أوسلو' : 'Oslo Sofa') && decodeURIComponent(wa).includes('/products/oslo-sofa') && /8,200\.00/.test(decodeURIComponent(wa)));
    const place = page.getByRole('button', { name: /Pay securely|Place order|ادفع بأمان|تأكيد الطلب/ }).first();
    check(id, 'the order button waits', await place.isDisabled());
    check(id, 'no sideways scroll', await noScroll(page));
    check(id, 'no console errors', errors.length === 0, errors.slice(0, 2).join(' | '));
    if (w === 375) await shot(page, `nopay-${lang}`);
    // Choosing the showroom switches the order to pickup.
    await panel.getByRole('button').first().click();
    await sleep(900);
    const pickupNow = await page.locator('[role=radio][aria-checked=true]').count();
    check(id, 'choosing the showroom moves the order to pickup and leaves the panel', (await page.locator('[data-testid=no-payment]').count()) === 0 && pickupNow > 0);
    await ctx.close();
  }
  // Under the minimum: "from QAR 300".
  {
    const { ctx, page } = await open(browser, { staleAccess: 'a2', methods: [{ code: 'sadad', available: true, reason: null }, { code: 'cod', available: false, reason: 'outside_cod_limits', limits: { min: money(9000), max: null } }, { code: 'pay_at_pickup', available: false, reason: 'pickup_only' }] }, '/checkout');
    await page.waitForSelector('text=Cash on delivery is available from QAR 9,000.00', { timeout: 15_000 });
    check('cod minimum', 'says where cash on delivery starts', true);
    check('cod minimum', 'no panel while online payment works', (await page.locator('[data-testid=no-payment]').count()) === 0);
    await ctx.close();
  }
  // A reason from a newer server and a missing key never show their code.
  {
    const { ctx, page } = await open(browser, { staleAccess: 'a2', methods: [{ code: 'sadad', available: true, reason: null }, { code: 'cod', available: false, reason: 'brand_new_reason' }, { code: 'pay_at_pickup', available: false, reason: null }] }, '/checkout');
    await page.waitForSelector('text=Not available for this order.', { timeout: 15_000 });
    const text = await page.locator('#root').innerText();
    check('unknown reason', 'shows the plain sentence, not the code', !/brand_new_reason|pay\.reason/.test(text));
    await ctx.close();
  }
}

/* ───────────── 3. Colour / material filters ───────────── */
async function filters(browser) {
  for (const [lang, w] of [['en', 1280], ['ar', 375]]) {
    const id = `filters ${lang} ${w}`;
    const { ctx, page, S, errors } = await open(browser, { lang, signed: false }, '/shop?category=sofas', { w });
    if (w < 1024) { await page.getByRole('button', { name: /Filters|التصفية|الفلاتر/ }).first().click(); }
    await page.waitForSelector('[data-testid=attribute-filters]:visible', { timeout: 15_000 });
    const grey = page.locator('[data-testid=attribute-filters]:visible').getByRole('button', { name: lang === 'ar' ? 'رمادي' : 'Grey' });
    check(id, 'colour and material choices are shown', (await page.locator('[data-testid=attribute-filters]:visible [role=group]').count()) === 2);
    check(id, 'a colour has its swatch', (await grey.locator('span[aria-hidden]').count()) === 1);
    await grey.click();
    await page.locator('[data-testid=attribute-filters]:visible').getByRole('button', { name: lang === 'ar' ? 'كتان' : 'Linen' }).click();
    if (w < 1024) { await page.getByRole('button', { name: /Show|عرض/ }).last().click(); }
    await sleep(900);
    check(id, 'the choices are in the address', new URL(page.url()).searchParams.get('attr') === '11,21', page.url());
    check(id, 'the products are asked with attribute_values[]', S.productQueries.some((q) => q.includes('attribute_values%5B%5D=11') && q.includes('attribute_values%5B%5D=21')), S.productQueries.at(-1));
    const chips = await page.locator('button:has(svg.lucide-x)').allInnerTexts();
    check(id, 'each choice is a chip that can be removed', chips.some((c) => /Colour: Grey|اللون: رمادي/.test(c)) && chips.some((c) => /Material: Linen|الخامة: كتان/.test(c)), chips.join(' | '));
    if (w >= 1024) {
      await page.locator('[data-testid=attribute-filters]:visible').getByRole('button', { name: /^Clear$|^مسح$/ }).click();
      await sleep(600);
      check(id, 'Clear takes every choice off', new URL(page.url()).searchParams.get('attr') === null, page.url());
      await shot(page, `filters-${lang}`);
    }
    check(id, 'no sideways scroll', await noScroll(page));
    check(id, 'no console errors', errors.length === 0, errors.slice(0, 2).join(' | '));
    await ctx.close();
  }
  // A shared link opens with the choices already on.
  {
    const { ctx, page, S } = await open(browser, { signed: false }, '/shop?category=sofas&attr=12');
    await page.waitForSelector('[data-testid=attribute-filters]:visible');
    const pressed = await page.locator('[data-testid=attribute-filters]:visible button[aria-pressed=true]').allInnerTexts();
    check('shared link', 'the chosen colour is shown as chosen', pressed.length === 1 && /Beige/.test(pressed[0]), pressed.join(','));
    check('shared link', 'the list was asked with it', S.productQueries.some((q) => q.includes('attribute_values%5B%5D=12')));
    await ctx.close();
  }
  // A search across the shop still offers the choices.
  {
    const { ctx, page } = await open(browser, { signed: false }, '/shop?q=sofa');
    await page.waitForSelector('[data-testid=attribute-filters]:visible', { timeout: 15_000 });
    check('search', 'colour choices are offered for a search too', (await page.locator('[data-testid=attribute-filters]:visible [role=group]').count()) === 2);
    await ctx.close();
  }
}

/* ───────────── 4. The order: return status, driver, invoice ───────────── */
async function order(browser) {
  const statuses = { requested: /received on .* Our team will review it/, approved: /was approved on/, rejected: /could not accept/, received: /received your returned items/, refunded: /refund has been raised/, closed: /closed/ };
  for (const [status, re] of Object.entries(statuses)) {
    const rr = { id: 'rr1', number: 'RT1', status, requested_at: '2026-11-03T10:00:00+03:00', decided_at: status === 'requested' ? null : '2026-11-04T09:00:00+03:00', decision_note: status === 'rejected' ? 'A used sofa cannot be taken back.' : null };
    const { ctx, page, errors } = await open(browser, { staleAccess: 'a2', canReturn: status === 'rejected', returnRequest: rr }, '/orders/o1');
    await page.waitForSelector('[data-testid=return-status]', { timeout: 15_000 });
    const text = await page.locator('[data-testid=return-status]').innerText();
    check(`return ${status}`, 'the card says where the request stands', re.test(text), text.replace(/\n/g, ' / '));
    if (status === 'rejected') check('return rejected', 'the team\'s note is shown', text.includes('A used sofa cannot be taken back.'));
    check(`return ${status}`, status === 'rejected' ? 'the button is back when the server allows another request' : 'the Return button is hidden', status === 'rejected' ? (await page.getByRole('button', { name: 'Report a problem or return' }).count()) === 1 : (await page.getByRole('button', { name: 'Report a problem or return' }).count()) === 0);
    check(`return ${status}`, 'no console errors', errors.length === 0, errors.slice(0, 2).join(' | '));
    if (status === 'requested') { await shot(page, 'return-requested'); }
    await ctx.close();
  }
  // The form: only what can still be returned, a stepper, the server's refusal in plain words.
  {
    const { ctx, page, S } = await open(browser, { staleAccess: 'a2', canReturn: true, returnable: 1, returnReply: { status: 422, body: { message: 'These items can\'t be returned.', code: 'http_error', request_id: 'r' } } }, '/orders/o1');
    await page.getByRole('button', { name: 'Report a problem or return' }).click();
    await page.waitForSelector('text=Up to 1 can be returned');
    check('return form', 'the stepper stops at what can still be returned', true);
    await page.getByRole('button', { name: /Increase|\+/ }).first().click();
    await page.getByText('I changed my mind').click(); // a damaged item needs a photo; a change of mind does not
    await page.getByRole('button', { name: /Send to our team/ }).click();
    await page.waitForSelector('[role=alert]');
    const alert = await page.locator('[role=alert]').first().innerText();
    check('return form', 'a refusal is explained, not just "can\'t be returned"', /may already be in a request|return period/.test(alert), alert);
    check('return form', 'the request carried the line, the reason and the quantity', S.returnPosts.length === 1 && S.returnPosts[0].items[0].quantity === 1 && S.returnPosts[0].reason_code === 'damaged');
    await ctx.close();
  }
  // The driver's number: a call button only while the server sends one.
  for (const [phone, expectButton] of [['+97455123499', true], [null, false]]) {
    const { ctx, page } = await open(browser, { staleAccess: 'a2', fStatus: 'out_for_delivery', driverPhone: phone }, '/orders/o1');
    await page.waitForSelector('text=Oslo Sofa');
    const link = page.locator('[data-testid=call-driver]');
    check(`driver ${phone ? 'phone' : 'no phone'}`, expectButton ? 'the call button is shown with the driver\'s number' : 'no call button', expectButton ? (await link.getAttribute('href')) === 'tel:+97455123499' && /Omar/.test(await link.innerText()) : (await link.count()) === 0);
    await ctx.close();
  }
  // The invoice after the sign-in expired: renewed first, then downloaded.
  {
    const { ctx, page, S } = await open(browser, { staleAccess: 'a2' }, '/orders/o1');
    await page.waitForSelector('text=Oslo Sofa');
    // Make this tab's access token stale without touching the server's idea of the valid one.
    await page.evaluate(() => { const t = JSON.parse(localStorage.getItem('albahar.tokens')); localStorage.setItem('albahar.tokens', JSON.stringify({ ...t, access_token: 'old' })); });
    S.access = 'a2'; S.refresh = 'r2';
    await page.reload(); await page.waitForSelector('text=Oslo Sofa');
    await page.evaluate(() => { const t = JSON.parse(localStorage.getItem('albahar.tokens')); localStorage.setItem('albahar.tokens', JSON.stringify({ ...t, access_token: 'expired' })); });
    await page.reload(); await page.waitForSelector('text=Oslo Sofa');
    await page.evaluate(() => { const t = JSON.parse(localStorage.getItem('albahar.tokens')); localStorage.setItem('albahar.tokens', JSON.stringify({ ...t, access_token: 'expired-again' })); });
    const [dl] = await Promise.all([page.waitForEvent('download', { timeout: 10_000 }).catch(() => null), page.getByRole('button', { name: /Download invoice/ }).click()]);
    check('invoice', 'the file downloads after the sign-in was renewed', !!dl && dl.suggestedFilename() === 'AlBahar-AB-1001.pdf', dl ? dl.suggestedFilename() : 'no download');
    check('invoice', 'it went through one refresh', S.refreshCalls >= 1 && !S.revoked, `calls=${S.refreshCalls}`);
    await ctx.close();
  }
  // The invoice that cannot come: a precise sentence.
  {
    const { ctx, page } = await open(browser, { staleAccess: 'a2' }, '/orders/o1');
    await page.waitForSelector('text=Oslo Sofa');
    await page.route('**/api/v1/orders/o1/invoice', (r) => r.fulfill({ status: 404, contentType: 'application/json', body: JSON.stringify({ message: 'Not found', code: 'not_found' }) }));
    await page.getByRole('button', { name: /Download invoice/ }).click();
    await page.waitForSelector('text=We could not find an invoice for this order', { timeout: 8000 });
    check('invoice 404', 'says no invoice was found and what to do', true);
    await ctx.close();
  }
}

/* ───────────── 5. Sign-up notice ───────────── */
async function signup(browser) {
  for (const lang of ['en', 'ar']) {
    const id = `terms ${lang}`;
    const { ctx, page, errors } = await open(browser, { lang, signed: false }, '/shop', { w: 375 });
    await page.getByLabel(lang === 'ar' ? 'تسجيل الدخول' : 'Sign in').first().click();
    const note = page.locator('[data-testid=auth-legal]');
    await note.waitFor({ timeout: 8000 }).catch(async () => { await shot(page, `signup-${lang}-failed`); throw new Error(`the notice did not appear (see ${SHOTS}/signup-${lang}-failed.png)`); });
    const links = await note.locator('a').evaluateAll((as) => as.map((a) => [a.getAttribute('href'), a.getAttribute('target'), a.getAttribute('rel')]));
    check(id, 'the notice links to the terms and the privacy policy, in new tabs', links.length === 2 && links[0][0] === '/pages/terms' && links[1][0] === '/pages/privacy' && links.every((l) => l[1] === '_blank' && /noopener/.test(l[2])), JSON.stringify(links));
    check(id, 'the sentence reads whole', lang === 'en' ? /By continuing you agree to our Terms and Privacy Policy\./.test(await note.innerText()) : /بالمتابعة فإنك توافق على الشروط وسياسة الخصوصية\./.test(await note.innerText()), await note.innerText());
    check(id, 'no console errors', errors.length === 0, errors.slice(0, 2).join(' | '));
    await ctx.close();
  }
}

const browser = await chromium.launch({ executablePath: process.env.CHROME ?? undefined });
const only = process.env.ONLY ? process.env.ONLY.split(',') : ['sessions', 'noPayment', 'filters', 'order', 'signup'];
const steps = { sessions, noPayment, filters, order, signup };
for (const name of only) { try { await steps[name](browser); } catch (e) { failures += 1; console.log(`FAIL [${name}] threw: ${e.message}`); } }
await browser.close();
console.log(failures ? `\n${failures} check(s) failed` : '\nAll checks passed');
process.exit(failures ? 1 : 0);
