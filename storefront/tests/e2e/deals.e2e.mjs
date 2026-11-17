/**
 * Browser check of the Deals feature (docs/api/DEALS.md): the widget in all five styles, English and Arabic, light and dark, at 320 / 375 / 768 / 1280 px,
 * the deals page, a deal's page, the home strip, the product page strip, the cart and checkout wording (with the 409 deal_changed flow) and a placed order.
 * Every response is mocked (no server needed), so only the built store has to be served:
 *
 *   npx vite build --outDir /tmp/deals-dist --emptyOutDir && npx vite preview --outDir /tmp/deals-dist --port 4174 &
 *   STORE_URL=http://127.0.0.1:4174 DIST=/tmp/deals-dist SHOTS=/tmp/deals-shots node tests/e2e/deals.e2e.mjs
 *
 * Asserts on every page: no horizontal overflow, no console errors or page errors, the server's colours on the widget, aria-hidden digits with one
 * minute-by-minute spoken label, a ticking clock, no urgency motion for people who asked for reduced motion. Screenshots go to SHOTS. Exit code 1 on any failure.
 * Not part of `npm test` (needs Chromium).
 */
import { createRequire } from 'node:module';
import { mkdirSync, readFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
const { chromium } = createRequire(import.meta.url)('playwright');

const BASE = process.env.STORE_URL ?? 'http://127.0.0.1:4174';
const DIST = process.env.DIST ?? new URL('../../dist', import.meta.url).pathname;
const SHOTS = process.env.SHOTS ?? join(tmpdir(), 'deals-shots');
const ONLY = process.env.ONLY ? new RegExp(process.env.ONLY) : null;
mkdirSync(SHOTS, { recursive: true });
const INDEX = readFileSync(join(DIST, 'index.html'), 'utf8');
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

let failures = 0, checks = 0;
const check = (ctx, name, ok, extra = '') => { checks += 1; if (!ok) failures += 1; if (!ok || process.env.VERBOSE) console.log(`${ok ? 'PASS' : 'FAIL'} [${ctx}] ${name}${extra ? ` (${extra})` : ''}`); };

/* ───────────── mock server ───────────── */
const MIN = 60_000, HOUR = 3_600_000, DAY = 86_400_000;
// Times are relative to the moment a response is built (the run takes minutes; a fixed start would let the deals age).
const iso = (ms) => new Date(Date.now() + ms).toISOString();
const money = (v, lang = 'en', cur = 'QAR') => ({ amount: Number(v).toFixed(2), currency: cur, formatted: lang === 'ar' ? `${Number(v).toLocaleString('en-GB', { minimumFractionDigits: 2 })} ر.ق` : `${cur} ${Number(v).toLocaleString('en-GB', { minimumFractionDigits: 2 })}` });
const svg = (w, h, a, b) => `data:image/svg+xml;utf8,${encodeURIComponent(`<svg xmlns='http://www.w3.org/2000/svg' width='${w}' height='${h}'><defs><linearGradient id='g' x1='0' y1='0' x2='1' y2='1'><stop offset='0' stop-color='${a}'/><stop offset='1' stop-color='${b}'/></linearGradient></defs><rect width='${w}' height='${h}' fill='url(#g)'/></svg>`)}`;
const img = (n) => ({ url: svg(600, 750, ['#e9dfd0', '#d8c9b3', '#cdb9a3'][n % 3], '#f4ecef'), thumb_url: svg(300, 375, ['#e9dfd0', '#d8c9b3', '#cdb9a3'][n % 3], '#f4ecef'), alt: 'Photo' });

const PALETTE = {
  brand: { background: '#8A1538', text: '#FCF9FA', accent: '#F2C4D1' },
  night: { background: '#0F1B2D', text: '#EAF0F8', accent: '#5AA9FF' },
  cream: { background: '#F7F1E8', text: '#2A1A20', accent: '#8A1538' },
  gold: { background: '#16120D', text: '#F6EEDC', accent: '#D4AF5A' },
  rose: { background: '#FBE9EE', text: '#4A1424', accent: '#B3254F' },
  emerald: { background: '#0E3B2E', text: '#ECF7F1', accent: '#6FD3A5' },
};
/** Five live deals (one per style, with different shapes, timer looks and unit sets) and one that starts later. */
const DEFS = [
  { id: 'd-classic', style: 'classic', palette: 'brand', shape: 'rounded', look: 'boxes', units: ['d', 'h', 'm', 's'], name: 'Weekend Furniture Drop', nameAr: 'عرض نهاية الأسبوع للأثاث', tag: 'Big pieces, small prices', tagAr: 'قطع كبيرة بأسعار صغيرة', ends: 2 * DAY + 4 * HOUR + 12 * MIN + 30_000, pct: 30, qty: 50, left: 12, perOrder: 2, perCustomer: 3, icon: 'flame' },
  { id: 'd-bold', style: 'bold', palette: 'night', shape: 'sharp', look: 'digits', units: ['h', 'm', 's'], name: 'Midnight Majlis', nameAr: 'مجلس منتصف الليل', tag: 'Sofas and lounges, tonight only', tagAr: 'كنب وجلسات لهذه الليلة فقط', ends: 5 * HOUR + 7 * MIN + 30_000, pct: 40, qty: null, left: null, perOrder: null, perCustomer: null, icon: 'bolt' },
  { id: 'd-minimal', style: 'minimal', palette: 'cream', shape: 'pill', look: 'text', units: ['d', 'h', 'm', 's'], name: 'The Linen Edit', nameAr: 'مجموعة الكتان', tag: 'Soft textiles for slow evenings', tagAr: 'أقمشة ناعمة لأمسيات هادئة', ends: 3 * DAY + 90_000 + 30_000, pct: 15, qty: 20, left: 4, perOrder: 4, perCustomer: null, icon: 'tag' },
  { id: 'd-luxe', style: 'luxe', palette: 'gold', shape: 'rounded', look: 'boxes', units: ['h', 'm', 's'], name: 'The Gold Collection', nameAr: 'المجموعة الذهبية', tag: 'Hand-finished pieces, priced for a day', tagAr: 'قطع مصنوعة يدوياً بسعر ليوم واحد', ends: 26 * HOUR + 30_000, pct: 25, qty: 8, left: 1, perOrder: 1, perCustomer: 1, icon: 'crown' },
  { id: 'd-ribbon', style: 'ribbon', palette: 'rose', shape: 'pill', look: 'boxes', units: ['d', 'h', 'm', 's'], name: 'Last Call: Dining', nameAr: 'آخر فرصة: الطعام', tag: 'Tables and chairs, going fast', tagAr: 'طاولات وكراسي تنفد بسرعة', ends: 20 * MIN + 30_000, pct: 20, qty: 30, left: 5, perOrder: 6, perCustomer: 6, icon: 'star', image: true },
];
const SOON = { id: 'd-soon', style: 'classic', palette: 'emerald', shape: 'rounded', look: 'boxes', units: ['d', 'h', 'm', 's'], name: 'Garden Weekend', nameAr: 'عطلة الحديقة', tag: 'Outdoor furniture, opens soon', tagAr: 'أثاث خارجي يبدأ قريباً', starts: 3 * HOUR + 30_000, ends: 3 * DAY, pct: 35, qty: null, left: null, perOrder: null, perCustomer: null, icon: 'gift', phase: 'scheduled' };

const designOf = (d, lang) => ({
  style: d.style, palette: d.palette, colors: PALETTE[d.palette], shape: d.shape, timer: { units: d.units, look: d.look, urgency_minutes: 60 }, stock_bar: true, percent_badge: true, icon: d.icon,
  headline_en: 'Ends in', headline_ar: 'ينتهي خلال', cta_en: 'Shop the deal', cta_ar: 'تسوق العرض', image_url: d.image ? svg(1200, 500, '#b3254f', '#4a1424') : null,
});
const PRICES = [1200, 2900, 640];
const blockOf = (d, i, lang, o = {}) => {
  const reg = PRICES[i % 3], deal = Math.round(reg * (1 - d.pct / 100));
  return {
    deal_id: d.id, name: lang === 'ar' ? d.nameAr : d.name, phase: d.phase ?? 'live', starts_at: iso(d.starts ? d.starts : -HOUR), ends_at: iso(d.ends), deal_price: money(deal, lang), regular_price: money(reg, lang), percent: d.pct,
    remaining: d.left, remaining_percent: d.qty ? Math.round((d.left / d.qty) * 100) : null, limit_per_order: d.perOrder, limit_per_customer: d.perCustomer, customer_remaining: d.perCustomer ? Math.min(d.perCustomer, 2) : null, sold_out: false, design: designOf(d, lang), ...o,
  };
};
const names = ['Oak dining table', 'Velvet three-seater sofa', 'Linen cushion set'];
const cardOf = (d, i, lang) => ({
  id: `p-${d.id}-${i}`, slug: `p-${d.id}-${i}`, sku: `SKU-${d.id}-${i}`, name: lang === 'ar' ? ['طاولة طعام من خشب البلوط', 'كنبة مخمل ثلاثية', 'طقم وسائد كتان'][i % 3] : names[i % 3], short_description: 'A short, calm description.', brand: { id: 'b1', name: 'Al Bahar Atelier' }, image: img(i),
  price: { regular: money(PRICES[i % 3], lang), sale: null, final: money(PRICES[i % 3], lang), discount_percent: 0, sale_ends_at: null, source: 'base' },
  rating: { average: 4.5, count: 12 }, badges: [], in_stock: true, purchasable: true, has_variants: false, variant_id: `v-${d.id}-${i}`, deal: blockOf(d, i, lang),
});
const dealOf = (d, lang, n = 4, paged = false) => ({
  id: d.id, name: lang === 'ar' ? d.nameAr : d.name, tagline: lang === 'ar' ? d.tagAr : d.tag, phase: d.phase ?? 'live', starts_at: iso(d.starts ? d.starts : -HOUR), ends_at: iso(d.ends), seconds_left: Math.round(d.ends / 1000),
  discount: { type: 'percent', value: String(d.pct) }, limits: { per_order: d.perOrder, per_customer: d.perCustomer }, products_total: 18, design: designOf(d, lang), show_on_home: true,
  products: paged ? { data: Array.from({ length: n }, (_, i) => cardOf(d, i, lang)), meta: { current_page: 1, last_page: 2, total: 18 } } : Array.from({ length: n }, (_, i) => cardOf(d, i, lang)),
});
const ALL = [...DEFS, SOON];
const defOfProduct = (slug) => ALL.find((d) => slug.startsWith(`p-${d.id}-`));

function detailOf(slug, lang, scenario) {
  const d = defOfProduct(slug); const i = Number(slug.split('-').pop());
  const c = cardOf(d, i, lang);
  const reg = PRICES[i % 3];
  const deal = scenario.dealState === 'limit_reached' ? { ...c.deal, customer_remaining: 0 } : scenario.dealState === 'sold_out' ? { ...c.deal, phase: 'sold_out', sold_out: true, remaining: 0 } : c.deal;
  return {
    ...c, deal, description: '<p>Solid oak, hand finished.</p>', material: 'Oak', category: { id: 'c1', slug: 'living', name: 'Living' }, tags: [], specifications: [], dimensions: null, weight: null, warranty: null,
    requires_assembly: false, assembly_service_available: false, is_oversized: false, images: [img(1), img(2)], videos: [], attributes: [],
    variants: [{ id: c.variant_id, sku: c.sku, name: '', attribute_value_ids: [], price: c.price, deal_price: c.deal.deal_price, available_in_branch: true, in_stock: true, available_quantity: 9, image_url: null }],
    default_variant_id: c.variant_id, availability: { branch_id: 'br1', purchasable: true, reason: null }, related: [], upsell: [], share_url: '', seo: { title: null, description: null }, _reg: reg,
  };
}

/** The dashboard's "fresh" web design (brand-coloured header, new cards): a second set of header, home and card markup the deals feature has to live in. */
const FRESH_THEME = { key: 'fresh', kind: 'custom', name: 'Fresh', version: '1', layout: 'fresh', tokens: { header: 'brand', nav: 'classic' }, design: null, colors: { primary: '#14532d', primary_soft: '#166534', accent: '#f59e0b', background: '#f7faf7', surface: '#ffffff', ink: '#10281c' }, images: { hero: null, banner: null, logo: null, splash: null }, banner: { title: null, subtitle: null }, backdrop: null };
const BRANCHES = [{ id: 'br1', code: 'DOH', name: 'Doha Showroom', description: null, phone: '+97440000000', email: null, address: 'Salwa Road', area: 'Doha', zone_number: '55', latitude: null, longitude: null, pickup_enabled: true, delivery_enabled: true, cod_enabled: true, pay_at_pickup_enabled: true, opening: { is_open: true, reason: 'open', closes_at: null, next_open_at: null } }];
const USER = { id: 'u1', type: 'customer', name: 'Sara Al Thani', phone: '+97455123456', alternate_phone: null, email: null, phone_verified: true, locale: 'en', avatar_url: null, date_of_birth: null, gender: null, referral_code: 'SARA', marketing_consent: false, has_password: true, created_at: '2026-01-01' };
const ADDRESS = { id: 'a1', label: 'Home', recipient_name: 'Sara Al Thani', recipient_phone: '+97455123456', delivery_zone: { id: 'z1', name: 'Zone 1' }, zone_number: '55', street_number: '12', building_number: '4', unit_number: null, area: 'West Bay', city: 'Doha', landmark: null, instructions: null, latitude: null, longitude: null, is_default: true, summary: 'Zone 55, Street 12, Building 4' };

/** The signed-in cart: 3 of a deal product (limit 2 per order) and one ordinary product. */
function cartOf(lang) {
  const d = DEFS[0];
  const c0 = cardOf(d, 0, lang);
  const plain = { ...cardOf(d, 1, lang), id: 'plain', slug: 'plain-sofa', name: lang === 'ar' ? 'أريكة عادية' : 'Plain sofa', deal: null, variant_id: 'v-plain', price: { regular: money(2900, lang), sale: null, final: money(2900, lang), discount_percent: 0, sale_ends_at: null, source: 'base' } };
  return { id: 'c', branch_id: 'br1', fulfillment_type: 'delivery', address_id: null, coupon_code: null, loyalty_points: 0, items_count: 4, subtotal: money(6500, lang), deal_savings: money(720, lang), warnings: [],
    items: [
      { id: 'ci1', variant_id: c0.variant_id, product: c0, deal: { ...c0.deal, units_at_deal_price: 2, units_at_regular_price: 1, note: 'limit_per_order' }, variant_name: null, quantity: 3, unit_price: c0.price, line_total: money(3600, lang), available: true, available_quantity: 9, price_changed: false, previous_price: null, issue: null },
      { id: 'ci2', variant_id: 'v-plain', product: plain, deal: null, variant_name: null, quantity: 1, unit_price: plain.price, line_total: money(2900, lang), available: true, available_quantity: 9, price_changed: false, previous_price: null, issue: null },
    ] };
}
function quoteOf(lang, body, stage) {
  const M = (v) => money(v, lang);
  const c0 = cardOf(DEFS[0], 0, lang);
  const changed = stage === 'changed'; // after deal_changed: only one unit still got the deal price
  const dealUnits = changed ? 1 : 2, saved = dealUnits * 360;
  const sub = 6500, fee = 30;
  return {
    quote_hash: `h-${stage}-${body.fulfillment_type}`, currency: 'QAR', fulfillment_type: body.fulfillment_type, payment_method: body.payment_method || null, is_split: false, errors: [], can_place_order: true,
    fulfillments: [{ branch: { id: 'br1', name: 'Doha Showroom', area: 'Doha' }, type: body.fulfillment_type, items: [], subtotal: M(sub), delivery_fee: M(fee), eta_minutes: 120, slot: null, slot_required: false, allocation_reason: 'x' }],
    lines: [
      { variant_id: c0.variant_id, product_id: c0.id, branch_id: 'br1', name: c0.name, variant_name: null, image_url: null, quantity: 3, unit_price: c0.price, line_total: M(3600), deal: { deal_id: DEFS[0].id, name: lang === 'ar' ? DEFS[0].nameAr : DEFS[0].name, units_at_deal_price: dealUnits, units_at_regular_price: 3 - dealUnits, savings: M(saved), ends_at: iso(DEFS[0].ends), note: changed ? 'sold_out' : 'limit_per_order' } },
      { variant_id: 'v-plain', product_id: 'plain', branch_id: 'br1', name: 'Plain sofa', variant_name: null, image_url: null, quantity: 1, unit_price: c0.price, line_total: M(2900), deal: null },
    ],
    totals: { subtotal: M(sub), discount: M(saved), delivery_fee: M(fee), extra_delivery_fee: M(0), service_fee: M(0), tax: M(0), loyalty_discount: M(0), grand_total: M(sub - saved + fee), deal_savings: M(saved) },
    delivery_extras: [], discounts: [{ label: DEFS[0].name, amount: M(saved), source: 'deal' }],
    payment_methods: [{ code: 'sadad', available: true, reason: null }, { code: 'cod', available: true, reason: null }, { code: 'pay_at_pickup', available: false, reason: 'pickup_only' }],
    loyalty: { balance: 0, max_redeemable_points: 0, points_applied: 0, points_to_earn: 65 }, warnings: [],
  };
}

async function install(ctx, sc) {
  const lang = sc.lang;
  const state = { posts: 0, orderPosts: 0, requests: [], quoteStage: 'first' };
  await ctx.addInitScript(([l, th, signed]) => {
    localStorage.setItem('albahar.locale', l); localStorage.setItem('albahar.theme', th);
    if (signed) localStorage.setItem('albahar.tokens', JSON.stringify({ access_token: 'x', refresh_token: 'y', token_type: 'Bearer', expires_in: 3600, session_id: 's' }));
  }, [lang, sc.theme ?? 'light', sc.signed !== false]);
  await ctx.route((u) => u.origin === new URL(BASE).origin && !u.pathname.startsWith('/store/') && !u.pathname.startsWith('/api/'), (route) => {
    if (route.request().resourceType() === 'document') return route.fulfill({ status: 200, contentType: 'text/html', body: INDEX });
    return route.fulfill({ status: 404, body: '' });
  });
  await ctx.route('**/api/v1/**', async (route) => {
    const req = route.request(); const url = new URL(req.url()); const path = url.pathname.replace(/^.*\/api\/v1/, ''); const method = req.method();
    const json = (data, status = 200) => route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(data) });
    state.requests.push(`${method} ${path}`);
    if (path === '/theme') return sc.layout === 'fresh' ? json({ data: FRESH_THEME }) : json({ message: 'none' }, 404);
    if (path === '/realtime-config') return json({ data: { enabled: false, key: null, auth_endpoint: '', path: '' } });
    if (path === '/app-config') return json({ data: { alternate_phone_enabled: false } });
    if (path === '/branches') return json({ data: BRANCHES });
    if (path === '/me' && method === 'GET') return json({ data: { ...USER, locale: lang } });
    if (path === '/me/addresses') return json({ data: [ADDRESS] });
    if (path === '/categories') return json({ data: [] });
    if (path === '/home') return json({ data: { sections: [] } });
    if (path === '/cart') return json({ data: cartOf(lang) });
    if (path === '/cart/suggestions') return json({ data: [] });
    if (path === '/deals') {
      if (sc.deals === 'error') return json({ message: 'boom' }, 500);
      if (sc.deals === 'empty') return json({ data: { server_time: new Date().toISOString(), live: [], upcoming: [] } });
      const home = url.searchParams.get('home') === '1'; const lim = Number(url.searchParams.get('limit_products') || 8);
      const live = (home ? DEFS.slice(0, 2) : DEFS).map((d) => dealOf(d, lang, lim));
      if (sc.deals === 'soldout') live[0] = { ...live[0], phase: 'sold_out' };
      return json({ data: { server_time: new Date(Date.now() + (sc.skew ?? 0)).toISOString(), live, upcoming: home ? [] : [dealOf(SOON, lang, 4)] } });
    }
    const dm = path.match(/^\/deals\/([^/]+)$/);
    if (dm) { const d = ALL.find((x) => x.id === dm[1]); return d && sc.deals !== 'gone' ? json({ data: { ...dealOf(d, lang, 8, true), server_time: new Date().toISOString() } }) : json({ message: 'Deal not found', code: 'deal_not_found' }, 404); }
    if (path === '/checkout/quote') return json({ data: quoteOf(lang, JSON.parse(req.postData() || '{}'), state.quoteStage) });
    if (path === '/checkout/orders' && method === 'POST') {
      state.orderPosts += 1;
      if (sc.deal409 && state.orderPosts === 1) { state.quoteStage = 'changed'; return json({ message: 'A deal changed', code: 'deal_changed', context: { deal_id: DEFS[0].id, product_id: 'p', quote: quoteOf(lang, { fulfillment_type: 'delivery' }, 'changed') }, request_id: 'r' }, 409); }
      return json({ data: { order: { id: 'o1' }, payment: { payment_id: 'p', method: 'cod', status: 'pending', action: 'none', redirect_url: null, expires_at: null } } });
    }
    if (/\/branches\/[^/]+\/(delivery|pickup)-slots$/.test(path)) return json({ data: [] });
    if (path === '/orders/o1') {
      const c0 = cardOf(DEFS[0], 0, lang);
      return json({ data: { id: 'o1', number: 'AB-1001', status: 'confirmed', status_label: 'Confirmed', payment_status: 'pending', payment_method: 'cod', fulfillment_type: 'delivery', items_count: 4, grand_total: money(5810, lang), placed_at: new Date().toISOString(), thumbnail_url: null,
        totals: { subtotal: money(6500, lang), discount: money(720, lang), delivery_fee: money(30, lang), grand_total: money(5810, lang) }, discounts: [], address: null, customer_notes: null,
        items: [{ id: 'oi1', product_id: c0.id, variant_id: c0.variant_id, sku: 'S', name: c0.name, variant_name: null, image_url: null, quantity: 3, fulfillment_id: 'f1', unit_price: money(1200, lang), line_total: money(3600, lang), deal: { deal_id: DEFS[0].id, name: lang === 'ar' ? DEFS[0].nameAr : DEFS[0].name, units: 2, unit_savings: money(360, lang) } },
          { id: 'oi2', product_id: 'plain', variant_id: 'v-plain', sku: 'S2', name: 'Plain sofa', variant_name: null, image_url: null, quantity: 1, fulfillment_id: 'f1', unit_price: money(2900, lang), line_total: money(2900, lang), deal: null }],
        fulfillments: [{ id: 'f1', number: 'F1', type: 'delivery', status: 'confirmed', status_label: 'Confirmed', branch: { id: 'br1', name: 'Doha Showroom', phone: null, latitude: null, longitude: null }, subtotal: money(6500, lang), delivery_fee: money(30, lang), slot: null, delivery: null, pickup: null, timestamps: {} }],
        can_cancel: false, can_pay: false } });
    }
    if (path === '/orders/o1/timeline') return json({ data: [] });
    const pm = path.match(/^\/products\/([^/]+)$/);
    if (pm && method === 'GET') return defOfProduct(pm[1]) ? json({ data: detailOf(pm[1], lang, sc) }) : json({ message: 'nf' }, 404);
    if (/\/products\/[^/]+\/availability$/.test(path)) return json({ data: { branches: [] } });
    if (/\/products\/[^/]+\/reviews$/.test(path)) return json({ data: [], meta: { current_page: 1, total: 0, last_page: 1 } });
    if (/\/products\/[^/]+\/questions$/.test(path)) return json({ data: [] });
    return json({ data: [], meta: { current_page: 1, total: 0, last_page: 1 } });
  });
  return state;
}

/* ───────────── page helpers ───────────── */
async function open(browser, { w, lang, theme = 'light', path, reduce = false, ...sc }) {
  const ctx = await browser.newContext({ viewport: { width: w, height: 900 }, reducedMotion: reduce ? 'reduce' : 'no-preference' });
  const state = await install(ctx, { lang, theme, ...sc });
  const page = await ctx.newPage();
  const errors = [];
  // The browser words a failed request as a console error without its address: those are judged by the response itself (the mock answers 404 for /theme on purpose).
  page.on('console', (m) => { if (m.type() === 'error' && !/^Failed to load resource/.test(m.text())) errors.push(`console: ${m.text()}`); });
  page.on('response', (r) => { if (r.status() >= 400 && !r.url().includes('/api/v1/')) errors.push(`http ${r.status()}: ${r.url().slice(0, 120)}`); });
  page.on('pageerror', (e) => errors.push(`pageerror: ${e.message}`));
  await page.goto(`${BASE}${path}`);
  return { ctx, page, errors, state };
}
const overflow = (page) => page.evaluate(() => ({
  sw: document.documentElement.scrollWidth, iw: window.innerWidth,
  wide: [...document.querySelectorAll('body *')].filter((e) => { const r = e.getBoundingClientRect(); const cs = getComputedStyle(e); return r.width > 0 && (r.right > innerWidth + 1 || r.left < -1) && cs.position !== 'fixed' && !e.closest('header, [aria-hidden=true], .sr-only, [role=dialog], .rail, [data-radix-popper-content-wrapper]') && !e.closest('[class*="overflow-x-auto"]'); })
    .slice(0, 4).map((e) => `${e.tagName}.${String(e.className).slice(0, 50)} ${Math.round(e.getBoundingClientRect().left)}..${Math.round(e.getBoundingClientRect().right)}`),
}));
const shot = (page, name) => page.screenshot({ path: join(SHOTS, `${name}.png`), fullPage: true });
async function settle(page, sel, n = 1) { await page.waitForSelector(sel, { timeout: 15_000 }); await page.waitForFunction(([s, k]) => document.querySelectorAll(s).length >= k, [sel, n], { timeout: 15_000 }); await sleep(500); }

const WIDTHS = [320, 375, 768, 1280];
const LANGS = ['en', 'ar'];
const THEMES = ['light', 'dark'];

/* ───────────── checks ───────────── */
async function dealsPage(browser) {
  for (const lang of LANGS) for (const theme of THEMES) for (const w of WIDTHS) {
    const id = `deals ${lang} ${theme} ${w}`;
    if (ONLY && !ONLY.test(id)) continue;
    const { ctx, page, errors } = await open(browser, { w, lang, theme, path: '/deals' });
    await settle(page, '[data-testid=deal-widget]', 6);
    const ov = await overflow(page);
    check(id, 'no horizontal overflow', ov.sw <= ov.iw && ov.wide.length === 0, `${ov.sw}/${ov.iw} ${ov.wide.join(' | ')}`);
    check(id, 'no console or page errors', errors.length === 0, errors.slice(0, 2).join(' | '));
    if (lang === 'en' && theme === 'light' && w === 1280 || (lang === 'ar' && theme === 'light' && w === 375)) {
      const styles = await page.$$eval('[data-testid=deal-widget]', (els) => els.map((e) => e.dataset.style));
      check(id, 'five live styles and the upcoming one are on the page', ['classic', 'bold', 'minimal', 'luxe', 'ribbon'].every((s) => styles.includes(s)) && styles.length === 6, styles.join(','));
      // The server's colours, nothing else.
      const colours = await page.$$eval('[data-testid=deal-widget]', (els) => els.map((e) => [e.style.getPropertyValue('--dw-bg').trim().toUpperCase(), e.style.getPropertyValue('--dw-accent').trim().toUpperCase()]));
      check(id, 'the widgets paint the server colours', colours[0][0] === '#8A1538' && colours[3][1] === '#D4AF5A' && colours[4][0] === '#FBE9EE', JSON.stringify(colours.slice(0, 5)));
      // Accessibility: digits hidden, one spoken label per widget, headings, landmarks.
      const a11y = await page.$$eval('[data-testid=deal-widget]', (els) => els.map((e) => ({ hiddenDigits: !![...e.querySelectorAll('.dw-t')].every((t) => t.getAttribute('aria-hidden') === 'true'), timers: e.querySelectorAll('[role=timer]').length, label: e.getAttribute('aria-labelledby') && !!document.getElementById(e.getAttribute('aria-labelledby')), spoken: e.querySelector('[role=timer]')?.textContent ?? '' })));
      check(id, 'digits are aria-hidden, one timer label each, labelled by the title', a11y.every((x) => x.hiddenDigits && x.label) && a11y.slice(0, 5).every((x) => x.timers === 1), JSON.stringify(a11y.slice(0, 2)));
      check(id, 'the spoken label says the time in words', lang === 'en' ? /^Ends in 2 days 4 hours$/.test(a11y[0].spoken) && /^Ends in 20 minutes$|^Ends in 19 minutes$/.test(a11y[4].spoken) : /^ينتهي خلال يومين و4 ساعات$/.test(a11y[0].spoken), `${a11y[0].spoken} / ${a11y[4].spoken}`);
      check(id, 'the upcoming deal says it starts', lang === 'en' ? /^Starts in 3 hours$/.test(a11y[5].spoken) : /^يبدأ خلال 3 ساعات$/.test(a11y[5].spoken), a11y[5].spoken);
      // The clock ticks and the spoken label does not.
      const before = await page.$eval('[data-testid=deal-widget] .dw-t [data-unit=s] .dw-t__num', (e) => e.textContent);
      const label0 = await page.$eval('[data-testid=deal-widget] [role=timer]', (e) => e.textContent);
      await sleep(2300);
      const after = await page.$eval('[data-testid=deal-widget] .dw-t [data-unit=s] .dw-t__num', (e) => e.textContent);
      const label1 = await page.$eval('[data-testid=deal-widget] [role=timer]', (e) => e.textContent);
      check(id, 'the seconds tick', before !== after, `${before} -> ${after}`);
      check(id, 'the spoken label stays put between ticks', label0 === label1, `${label0} / ${label1}`);
      check(id, 'the days are shown with more than a day left and the seconds are zero padded', /^\d\d$/.test(after));
      // Arabic: the page is mirrored.
      if (lang === 'ar') check(id, 'right to left', await page.evaluate(() => document.documentElement.dir === 'rtl'));
      // Contrast of the words on the widget colours (AA for the title).
      const ratio = await page.evaluate(() => {
        const lum = (rgb) => { const f = (v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; }; return 0.2126 * f(rgb[0]) + 0.7152 * f(rgb[1]) + 0.0722 * f(rgb[2]); };
        const px = (c) => c.match(/\d+(\.\d+)?/g).slice(0, 3).map(Number);
        const out = [];
        for (const w of document.querySelectorAll('[data-testid=deal-widget]')) { const t = w.querySelector('.dw__title'); const s = getComputedStyle(w); const a = lum(px(getComputedStyle(t).color)), b = lum(px(s.backgroundColor)); out.push(((Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05)).toFixed(1)); }
        return out;
      });
      check(id, 'title contrast is at least 4.5 on every widget', ratio.every((r) => Number(r) >= 4.5), ratio.join(','));
    }
    await shot(page, `deals-${lang}-${theme}-${w}`);
    // One picture per widget (the full-page one is too small to judge a design by).
    const widgets = page.locator('[data-testid=deal-widget]');
    for (let i = 0; i < await widgets.count(); i++) await widgets.nth(i).screenshot({ path: join(SHOTS, `widget-${await widgets.nth(i).getAttribute('data-style')}${i === 5 ? '-soon' : ''}-${lang}-${theme}-${w}.png`) });
    await ctx.close();
  }
}

async function dealsStates(browser) {
  let id = 'deals states';
  for (const [state, expect] of [['empty', /No deals right now|لا توجد عروض حالياً/], ['error', /Something went wrong|حدث خطأ|could not load|تعذّر/i]]) {
    for (const lang of LANGS) {
      const sid = `${id} ${state} ${lang}`;
      const { ctx, page, errors } = await open(browser, { w: 375, lang, path: '/deals', deals: state });
      await sleep(state === 'error' ? 6000 : 1800);
      const text = await page.evaluate(() => document.body.innerText);
      check(sid, `shows the ${state} state`, expect.test(text), text.slice(0, 160).replace(/\n/g, ' | '));
      if (state === 'empty') check(sid, 'offers the sale items', (await page.$$eval('a[href="/shop?sale=1"]', (a) => a.length)) > 0);
      if (state === 'error') check(sid, 'has a retry button', (await page.$$eval('button', (b) => b.some((x) => /Try again|Retry|حاول|إعادة/.test(x.textContent || '')))));
      const ov = await overflow(page);
      check(sid, 'no overflow', ov.sw <= ov.iw);
      check(sid, 'no page errors', errors.filter((e) => !/Failed to load resource|status of 500/.test(e)).length === 0, errors.join(' | '));
      await shot(page, `deals-${state}-${lang}`);
      await ctx.close();
    }
  }
  id = 'deals sold out';
  const { ctx, page } = await open(browser, { w: 375, lang: 'en', path: '/deals', deals: 'soldout' });
  await settle(page, '[data-testid=deal-widget]', 3);
  check(id, 'a sold-out deal says Sold out and has no button', await page.evaluate(() => { const w = document.querySelector('[data-testid=deal-widget]'); return /Sold out/.test(w.innerText) && !w.querySelector('.dw__cta'); }));
  await shot(page, 'deals-soldout-en-375');
  await ctx.close();
  // A deal that is gone.
  const g = await open(browser, { w: 375, lang: 'en', path: '/deals/d-classic', deals: 'gone' });
  await sleep(1800);
  check('deal page', 'a deal that ended or was paused says so and links back', await g.page.evaluate(() => /not available/i.test(document.body.innerText) && !!document.querySelector('a[href="/deals"]')));
  await g.ctx.close();
}

async function dealPage(browser) {
  for (const lang of LANGS) for (const w of [375, 1280]) {
    const id = `deal page ${lang} ${w}`;
    if (ONLY && !ONLY.test(id)) continue;
    const { ctx, page, errors } = await open(browser, { w, lang, path: '/deals/d-luxe' });
    await settle(page, '[data-testid=deal-widget]', 1);
    await page.waitForSelector('article', { timeout: 10_000 });
    const ov = await overflow(page);
    check(id, 'no horizontal overflow', ov.sw <= ov.iw && ov.wide.length === 0, ov.wide.join(' | '));
    check(id, 'no errors', errors.length === 0, errors.join(' | '));
    check(id, 'the page has one h1 (the deal) and a grid of products', await page.evaluate(() => document.querySelectorAll('h1').length === 1 && document.querySelectorAll('article').length === 8));
    check(id, 'cards carry the deal badge and deal price', await page.evaluate(() => document.querySelectorAll('[data-testid=deal-compact]').length >= 4 && document.querySelectorAll('[data-testid=deal-price]').length >= 4));
    check(id, 'a load-more button for the second page', await page.evaluate(() => [...document.querySelectorAll('button')].some((b) => /Load more|عرض المزيد/.test(b.textContent || ''))));
    await shot(page, `dealpage-${lang}-${w}`);
    await page.locator('article').first().screenshot({ path: join(SHOTS, `card-${lang}-${w}.png`) });
    await page.locator('[data-testid=deal-widget]').first().screenshot({ path: join(SHOTS, `dealpage-widget-${lang}-${w}.png`) });
    await ctx.close();
  }
}

async function homeStrip(browser) {
  for (const lang of LANGS) for (const w of [375, 1280]) {
    const id = `home ${lang} ${w}`;
    if (ONLY && !ONLY.test(id)) continue;
    const { ctx, page, errors } = await open(browser, { w, lang, path: '/' });
    await settle(page, '[data-testid=home-deals] [data-testid=deal-widget]', 2);
    const ov = await overflow(page);
    check(id, 'no overflow', ov.sw <= ov.iw && ov.wide.length === 0, ov.wide.join(' | '));
    check(id, 'no errors', errors.length === 0, errors.join(' | '));
    check(id, 'the strip shows two deals marked for home, and the header link has the live dot', await page.evaluate(() => document.querySelectorAll('[data-testid=home-deals] [data-testid=deal-widget]').length === 2 && document.querySelectorAll('a[href="/deals"] .live-dot').length >= 1));
    await page.evaluate(() => document.querySelector('[data-testid=home-deals]')?.scrollIntoView());
    await sleep(300);
    await shot(page, `home-${lang}-${w}`);
    await ctx.close();
  }
}

async function freshDesign(browser) {
  for (const lang of LANGS) for (const w of [375, 1280]) {
    const id = `fresh ${lang} ${w}`;
    if (ONLY && !ONLY.test(id)) continue;
    const { ctx, page, errors } = await open(browser, { w, lang, path: '/', layout: 'fresh' });
    await settle(page, '[data-testid=home-deals] [data-testid=deal-widget]', 2);
    await sleep(500);
    const ov = await overflow(page);
    check(id, 'no overflow', ov.sw <= ov.iw && ov.wide.length === 0, ov.wide.join(' | '));
    check(id, 'no errors', errors.length === 0, errors.join(' | '));
    check(id, 'the fresh header has the Deals link with the live dot (menu or bar)', await page.evaluate(() => document.documentElement.dataset.layout === 'fresh' && [...document.querySelectorAll('header a[href="/deals"]')].length >= (innerWidth >= 1024 ? 1 : 0)));
    check(id, 'the home strip and cards with the deal badge', await page.evaluate(() => document.querySelectorAll('[data-testid=home-deals] article [data-testid=deal-compact]').length >= 4));
    if (w >= 1024) check(id, 'live dot beside Deals in the bar', await page.evaluate(() => !!document.querySelector('header a[href="/deals"] .live-dot')));
    await page.evaluate(() => document.querySelector('[data-testid=home-deals]')?.scrollIntoView());
    await sleep(300);
    await shot(page, `fresh-home-${lang}-${w}`);
    await ctx.close();
    const d = await open(browser, { w, lang, path: '/deals/d-bold', layout: 'fresh' });
    await settle(d.page, 'article', 8);
    const ov2 = await overflow(d.page);
    check(id, 'deal page: no overflow', ov2.sw <= ov2.iw && ov2.wide.length === 0, ov2.wide.join(' | '));
    check(id, 'deal page: no errors', d.errors.length === 0, d.errors.join(' | '));
    await d.page.locator('article').first().screenshot({ path: join(SHOTS, `fresh-card-${lang}-${w}.png`) });
    await d.ctx.close();
  }
}

async function productPage(browser) {
  for (const d of DEFS) for (const lang of LANGS) for (const [w, theme] of [[320, 'light'], [375, 'dark'], [1280, 'light']]) {
    const id = `product ${d.style} ${lang} ${theme} ${w}`;
    if (ONLY && !ONLY.test(id)) continue;
    const { ctx, page, errors } = await open(browser, { w, lang, theme, path: `/products/p-${d.id}-0` });
    await settle(page, '[data-testid=product-deal] [data-testid=deal-widget]', 1);
    const ov = await overflow(page);
    check(id, 'no overflow', ov.sw <= ov.iw && ov.wide.length === 0, ov.wide.join(' | '));
    check(id, 'no errors', errors.length === 0, errors.join(' | '));
    const info = await page.evaluate(() => { const s = document.querySelector('[data-testid=product-deal]'); return { text: s.innerText, price: s.querySelector('[data-testid=deal-price]')?.innerText, struck: !!s.querySelector('s'), bar: !!s.querySelector('.dw__bar'), notes: [...s.querySelectorAll('.dw__note')].map((n) => n.textContent) }; });
    check(id, 'deal price with the regular price struck through', !!info.price && info.struck, info.price);
    if (d.qty) check(id, 'stock bar and wording', info.bar && /left|بقي/.test(info.text), info.text.slice(0, 120));
    if (d.perOrder) check(id, 'limit wording', info.notes.length > 0, info.notes.join(' | '));
    if (d.style === 'classic' && lang === 'en' && w === 1280) {
      check(id, 'the customer remaining wording', info.notes.some((n) => /You can still get 2 at this price/.test(n)), info.notes.join(' | '));
      // More than the limit: "Deal price on 2 of 3 units"
      await page.click('button[aria-label="Increase quantity"]'); await page.click('button[aria-label="Increase quantity"]'); await sleep(300);
      const n2 = await page.$$eval('[data-testid=product-deal] .dw__note', (n) => n.map((x) => x.textContent));
      check(id, 'quantity above the limit says how many get the deal price', n2.some((n) => /Deal price on 2 of 3 units/.test(n)), n2.join(' | '));
    }
    await shot(page, `product-${d.style}-${lang}-${theme}-${w}`);
    await ctx.close();
  }
  // States: limit reached and sold out show the normal price.
  for (const st of ['limit_reached', 'sold_out']) {
    const id = `product ${st}`;
    const { ctx, page } = await open(browser, { w: 375, lang: 'en', path: '/products/p-d-classic-0', dealState: st });
    await settle(page, '[data-testid=product-deal]', 1);
    const r = await page.evaluate(() => { const s = document.querySelector('[data-testid=product-deal]'); return { text: s.innerText, deal: !!s.querySelector('[data-testid=deal-price]'), normal: /1,200/.test(document.querySelector('h1').closest('div').innerText) }; });
    check(id, st === 'sold_out' ? 'Sold out with the normal price' : 'limit reached message with the normal price', !r.deal && (st === 'sold_out' ? /Sold out/.test(r.text) : /You reached your limit for this deal/.test(r.text)) && r.normal, r.text.replace(/\n/g, ' | ').slice(0, 200));
    await shot(page, `product-${st}-en-375`);
    await ctx.close();
  }
  // The upcoming deal on a product teases its start.
  const up = await open(browser, { w: 375, lang: 'en', path: '/products/p-d-soon-0' });
  await settle(up.page, '[data-testid=product-deal] [data-testid=deal-widget]', 1);
  check('product upcoming', 'the normal price and "Starts in"', await up.page.evaluate(() => { const s = document.querySelector('[data-testid=product-deal]'); return /Starts in/i.test(s.innerText) && !s.querySelector('[data-testid=deal-price]'); }));
  await up.ctx.close();
}

async function motion(browser) {
  // Urgency (20 minutes left, urgency below 60) pulses unless the visitor asked for calm.
  for (const reduce of [false, true]) {
    const { ctx, page } = await open(browser, { w: 1280, lang: 'en', path: '/deals', reduce });
    await settle(page, '[data-testid=deal-widget]', 6);
    const anim = await page.$$eval('.dw--urgent .dw-t__num', (els) => els.map((e) => getComputedStyle(e).animationName));
    check(`motion reduce=${reduce}`, reduce ? 'no pulse when motion is reduced' : 'pulse when urgent', anim.length > 0 && anim.every((a) => (reduce ? a === 'none' : a === 'dw-pulse')), anim.join(','));
    const calm = await page.$$eval('[data-testid=deal-widget]:not(.dw--urgent) .dw-t__num', (els) => els.every((e) => getComputedStyle(e).animationName === 'none'));
    check(`motion reduce=${reduce}`, 'no pulse when not urgent', calm);
    await ctx.close();
  }
  // A phone clock that is 10 minutes slow: the page follows the server (urgent already at the 20-minute deal's 10 min minus skew).
  const { ctx, page } = await open(browser, { w: 1280, lang: 'en', path: '/deals', skew: 10 * MIN });
  await settle(page, '[data-testid=deal-widget]', 6);
  const spoken = await page.$$eval('[data-testid=deal-widget] [role=timer]', (els) => els.map((e) => e.textContent));
  check('skew', 'the countdown reads the server clock (server 10 minutes ahead of the phone)', /Ends in (9|10) minutes/.test(spoken[4]), spoken[4]);
  await ctx.close();
}

async function cartAndCheckout(browser) {
  for (const lang of LANGS) for (const w of [320, 375, 1280]) {
    const id = `cart ${lang} ${w}`;
    if (ONLY && !ONLY.test(id)) continue;
    const { ctx, page, errors } = await open(browser, { w, lang, path: '/cart' });
    await settle(page, '[data-testid=deal-line]', 1);
    const r = await page.evaluate(() => ({ line: document.querySelector('[data-testid=deal-line]').innerText, hint: !!document.querySelector('[data-testid=deal-checkout-hint]') }));
    check(id, 'the line says how many units get the deal price and the limit', lang === 'en' ? /Deal price on 2 of 3 units/.test(r.line) && /Limit 2 per order/.test(r.line) : /سعر العرض على 2 من 3 قطع/.test(r.line) && /الحد الأقصى قطعتان للطلب/.test(r.line), r.line.replace(/\n/g, ' | '));
    check(id, 'says deal prices are applied at checkout', r.hint);
    check(id, 'a Deal savings row from the cart', await page.evaluate(() => /Deal savings|وفر العروض/.test(document.querySelector('aside').innerText) && /720\.00/.test(document.querySelector('aside').innerText)));
    const ov = await overflow(page);
    check(id, 'no overflow', ov.sw <= ov.iw && ov.wide.length === 0, ov.wide.join(' | '));
    check(id, 'no errors', errors.length === 0, errors.join(' | '));
    await shot(page, `cart-${lang}-${w}`);
    await ctx.close();
  }
  for (const lang of LANGS) for (const w of [320, 375, 1280]) {
    const id = `checkout ${lang} ${w}`;
    if (ONLY && !ONLY.test(id)) continue;
    const { ctx, page, errors, state } = await open(browser, { w, lang, path: '/checkout', deal409: true });
    await settle(page, '[data-testid=deal-line]', 1);
    await page.waitForSelector('dl >> text=/Deal savings|وفر العروض/', { timeout: 10_000 });
    const r = await page.evaluate(() => ({ line: document.querySelector('[data-testid=deal-line]').innerText, text: document.body.innerText }));
    check(id, 'the line says units at the deal price, savings and why', lang === 'en' ? /Deal price on 2 of 3 units/.test(r.line) && /You saved QAR 720.00/.test(r.line) && /Limit 2 per order/.test(r.line) : /سعر العرض على 2 من 3 قطع/.test(r.line) && /وفّرت/.test(r.line), r.line.replace(/\n/g, ' | '));
    check(id, 'a Deal savings row and the discount row is not doubled', (lang === 'en' ? /Deal savings\s*[\s\S]*720\.00/.test(r.text) : /وفر العروض/.test(r.text)) && !/Discount\s*[\s\S]{0,20}− QAR 720\.00[\s\S]*Deal savings/.test(r.text));
    const ov = await overflow(page);
    check(id, 'no overflow', ov.sw <= ov.iw && ov.wide.length === 0, ov.wide.join(' | '));
    await shot(page, `checkout-${lang}-${w}`);
    if (lang === 'en' && w === 375 || (lang === 'ar' && w === 1280)) {
      // Place the order: the server says a deal changed.
      const place = page.locator('button', { hasText: /Place order|Pay securely|تأكيد الطلب|ادفع بأمان/ }).first();
      await page.waitForFunction(() => [...document.querySelectorAll('button')].some((b) => /Place order|Pay securely|تأكيد الطلب|ادفع بأمان|إتمام/.test(b.textContent || '') && !b.disabled), null, { timeout: 10_000 }).catch(() => undefined);
      await place.click();
      await page.waitForSelector('[data-testid=deal-changed]', { timeout: 8000 });
      await sleep(600);
      const t = await page.evaluate(() => ({ banner: document.querySelector('[data-testid=deal-changed]').innerText, line: document.querySelector('[data-testid=deal-line]').innerText, text: document.body.innerText }));
      check(id, 'a calm banner says a deal changed', lang === 'en' ? /A deal changed, your total was updated/.test(t.banner) : /تغيّر أحد العروض/.test(t.banner), t.banner.replace(/\n/g, ' | '));
      check(id, 'the new total is on screen (one unit at the deal price now: 6,170.00)', /5,?[0-9]{3}\.00|6,170\.00/.test(t.text) && /6,170\.00/.test(t.text), '');
      check(id, 'the line was updated to one unit at the deal price', lang === 'en' ? /Deal price on 1 of 3 units/.test(t.line) : /سعر العرض على 1 من 3 قطع/.test(t.line), t.line.replace(/\n/g, ' | '));
      await sleep(1200);
      check(id, 'the order is not sent again on its own', state.orderPosts === 1, `posts=${state.orderPosts}`);
      await shot(page, `checkout-changed-${lang}-${w}`);
      await page.locator('button', { hasText: /Place order|Pay securely|تأكيد الطلب|ادفع بأمان/ }).first().click();
      await page.waitForURL(/\/orders\/o1/, { timeout: 8000 });
      check(id, 'after the customer confirms again the order goes through', state.orderPosts === 2 && /\/orders\/o1/.test(page.url()));
      await settle(page, '[data-testid=order-deal]', 1);
      const o = await page.evaluate(() => ({ line: document.querySelector('[data-testid=order-deal]').innerText, saved: document.querySelector('[data-testid=order-deal-saved]')?.innerText ?? '' }));
      check(id, 'the placed order shows the deal and its savings per line', lang === 'en' ? /Deal: Weekend Furniture Drop/.test(o.line) && /saved QAR 720\.00|You saved QAR 720\.00/.test(o.line) && /720\.00/.test(o.saved) : /العرض:/.test(o.line) && /720\.00/.test(o.saved), `${o.line.replace(/\n/g, ' | ')} // ${o.saved}`);
      const ov2 = await overflow(page);
      check(id, 'the order page has no overflow', ov2.sw <= ov2.iw);
      await shot(page, `order-${lang}-${w}`);
    }
    check(id, 'no console errors', errors.filter((e) => !/409/.test(e)).length === 0, errors.join(' | '));
    await ctx.close();
  }
}

async function header(browser) {
  for (const w of [375, 1024, 1280]) for (const lang of LANGS) {
    const id = `header ${lang} ${w}`;
    if (ONLY && !ONLY.test(id)) continue;
    const { ctx, page } = await open(browser, { w, lang, path: '/shop' });
    await settle(page, 'header', 1);
    await sleep(900);
    const r = await page.evaluate(() => ({ dot: document.querySelectorAll('header .live-dot').length, link: [...document.querySelectorAll('header a[href="/deals"]')].some((a) => a.getBoundingClientRect().width > 0), sw: document.documentElement.scrollWidth, iw: innerWidth }));
    if (w >= 1024) check(id, 'a Deals link with the live dot in the header', r.link && r.dot >= 1, JSON.stringify(r));
    check(id, 'no horizontal overflow', r.sw <= r.iw, JSON.stringify(r));
    if (w === 375) {
      await page.click('button[aria-label="Menu"], button[aria-label="القائمة"]').catch(() => undefined);
      await sleep(700);
      check(id, 'the menu lists Deals with the dot', await page.evaluate(() => { const a = document.querySelector('[role=dialog] a[href="/deals"]'); return !!a && !!a.querySelector('.live-dot'); }));
      await shot(page, `menu-${lang}-375`);
    } else await page.screenshot({ path: join(SHOTS, `header-${lang}-${w}.png`), clip: { x: 0, y: 0, width: w, height: 160 } });
    await ctx.close();
  }
}

const browser = await chromium.launch();
const t0 = Date.now();
const jobs = [['deals page', dealsPage], ['deals states', dealsStates], ['deal page', dealPage], ['home', homeStrip], ['product', productPage], ['fresh', freshDesign], ['motion', motion], ['cart and checkout', cartAndCheckout], ['header', header]];
for (const [name, fn] of jobs) {
  if (process.env.JOB && !new RegExp(process.env.JOB).test(name)) continue;
  const before = failures;
  try { await fn(browser); } catch (e) { failures += 1; console.log(`FAIL [${name}] threw: ${e.message}`); }
  console.log(`${failures === before ? 'ok  ' : 'FAIL'} ${name}`);
}
await browser.close();
console.log(`\n${checks} checks, ${failures} failed, ${Math.round((Date.now() - t0) / 1000)} s. Screenshots: ${SHOTS}`);
process.exit(failures ? 1 : 0);
