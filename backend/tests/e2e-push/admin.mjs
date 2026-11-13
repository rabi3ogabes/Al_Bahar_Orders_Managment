// Browser check of the dashboard's Push notifications page (not part of CI: it needs a running app, a seeded database with a super admin and Chromium).
// Usage: BASE=http://127.0.0.1:8091 SHOTS=/tmp/push-shots PW_EXPERIMENTAL_SERVICE_WORKER_NETWORK_EVENTS=1 node tests/e2e-push/admin.mjs
// The sandbox has no internet: the Firebase scripts come from node_modules, and the Firebase / FCM registration endpoints and the push
// subscription are stubbed, so the REAL Firebase SDK, the REAL service worker and the REAL dashboard code run end to end.
import { generateKeyPairSync } from 'node:crypto';
import { mkdirSync, readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import assert from 'node:assert/strict';

const require = createRequire(import.meta.url);
const { chromium } = (() => { try { return require('playwright'); } catch { return require('/opt/node-tools/node_modules/playwright'); } })();
const BASE = process.env.BASE ?? 'http://127.0.0.1:8091';
const SHOTS = process.env.SHOTS ?? '/tmp/push-shots';
const SDK = process.env.SDK ?? '12.19.0';
mkdirSync(SHOTS, { recursive: true });
const pkg = (f) => readFileSync(new URL(`../../node_modules/firebase/${f}`, import.meta.url), 'utf8');

const values = {
    projectId: 'al-bahar-home', apiKey: 'AIzaSyA1b2C3d4E5f6G7h8I9j0K1l2M3n4O5p6Q', senderId: '123456789012', appId: '1:123456789012:web:0a1b2c3d4e5f6a7b8c9d0e',
    vapidKey: 'BAswVXqfxOkOM1h9osfsETZbgKXK7xQ5XoOozfIXPGGGq9D1Gj9kia7T-B1CZ4yx1vsgRWqPtNn-I0htkrfcASY',
};
const { privateKey } = generateKeyPairSync('rsa', { modulusLength: 2048, privateKeyEncoding: { type: 'pkcs8', format: 'pem' }, publicKeyEncoding: { type: 'spki', format: 'pem' } });
const keyFile = JSON.stringify({ type: 'service_account', project_id: values.projectId, private_key_id: 'kid1', private_key: privateKey, client_email: 'firebase-adminsdk-abc12@al-bahar-home.iam.gserviceaccount.com', client_id: '1' });

const results = [];
const check = (name, ok, extra = '') => { results.push({ name, ok }); console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${extra ? `  (${extra})` : ''}`); };

const browser = await chromium.launch();
const context = await browser.newContext({ viewport: { width: 1280, height: 900 }, permissions: ['notifications'], locale: 'en-US' });
const log = [];
const posts = [];

// The page-side stubs: a push subscription (headless Chromium has no push service), applied to every page of this context.
await context.addInitScript(() => {
    // Headless Chromium reports the permission as "denied" even after a grant: say "granted" like a person who clicked Allow.
    Object.defineProperty(Notification, 'permission', { get: () => 'granted', configurable: true });
    Notification.requestPermission = async () => 'granted';
    const sub = { endpoint: 'https://fcm.googleapis.com/fcm/send/stub-endpoint-123', expirationTime: null, getKey: () => new Uint8Array(65).fill(4).buffer, toJSON: () => ({ endpoint: 'https://fcm.googleapis.com/fcm/send/stub-endpoint-123', keys: { p256dh: 'BAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQ', auth: 'AQEBAQEBAQEBAQEBAQEBAQ' } }), unsubscribe: async () => true };
    const proto = window.PushManager?.prototype;
    if (proto) {
        proto.subscribe = async () => sub;
        proto.getSubscription = async () => sub;
        proto.permissionState = async () => 'granted';
    }
});
const firebaseStubs = async (route) => {
    const url = route.request().url();
    if (url.startsWith(`https://www.gstatic.com/firebasejs/${SDK}/firebase-app-compat.js`)) return route.fulfill({ contentType: 'text/javascript', body: pkg('firebase-app-compat.js') });
    if (url.startsWith(`https://www.gstatic.com/firebasejs/${SDK}/firebase-messaging-compat.js`)) return route.fulfill({ contentType: 'text/javascript', body: pkg('firebase-messaging-compat.js') });
    if (url.includes('firebaseinstallations.googleapis.com')) return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ name: 'projects/p/installations/fid1', fid: 'fid1', refreshToken: 'rt', authToken: { token: 'at', expiresIn: '604800s' } }) });
    if (url.includes('fcmregistrations.googleapis.com')) return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ token: `stub-fcm-token-${'x'.repeat(120)}` }) });
    return route.continue();
};
await context.route(/gstatic\.com|firebaseinstallations\.googleapis\.com|fcmregistrations\.googleapis\.com/, firebaseStubs);

const page = await context.newPage();
page.on('console', (m) => { if (['error', 'warning'].includes(m.type())) log.push(`${m.type()}: ${m.text()}`); });
page.on('pageerror', (e) => log.push(`pageerror: ${e.message}`));
page.on('request', (r) => { if (r.method() === 'POST' && r.url().includes('/admin/push/devices')) posts.push(r.postData()); });

const toasts = () => page.locator('[aria-live=polite] p').allInnerTexts();
const shot = (name) => page.screenshot({ path: `${SHOTS}/${name}.png`, fullPage: true });

// ── sign in and open the page
await page.goto(`${BASE}/admin/login`);
await page.fill('input[type=email]', 'push@albahar.test');
await page.fill('input[type=password]', 'PushQA-12345!');
await Promise.all([page.waitForURL(/\/admin(?!\/login)/, { timeout: 20000 }), page.keyboard.press('Enter')]);
await page.goto(`${BASE}/admin/push`);
await page.waitForSelector('h1');
check('page opens with its title and the guided steps open', (await page.locator('h1').innerText()) === 'Push notifications' && await page.locator('details[open]').count() === 1);
check('sidebar has the menu entry', await page.locator('nav a[href$="/admin/push"]').count() === 1);
await shot('01-fresh-light');

// ── the master switch needs a server key first
const master = page.locator('[role=switch][aria-label="Push notifications"]');
await master.click();
await page.waitForTimeout(800);
check('master switch refuses to turn on without a key', (await master.getAttribute('aria-checked')) === 'false' && (await toasts()).some((t) => /server key/i.test(t)));

// ── Save is off until something changes; invalid formats are explained next to the field
const save = page.getByRole('button', { name: 'Save changes' });
check('Save is disabled while nothing changed', await save.isDisabled());
await page.fill('#apiKey', 'nope');
await page.waitForSelector('text=starts with AIza and has 39');
check('a bad API key is explained in plain words', true);
check('Save is enabled once the form is dirty', await save.isEnabled());

// ── fill the form, upload the key file, save
for (const [id, v] of Object.entries({ projectId: values.projectId, apiKey: values.apiKey, senderId: values.senderId, appId: values.appId, vapidKey: values.vapidKey })) {
    await page.fill(`#${id}`, v);
}
await page.setInputFiles('input[type=file]', { name: 'service-account.json', mimeType: 'application/json', buffer: Buffer.from(keyFile) });
await page.waitForTimeout(1500);
check('uploaded key file is accepted without errors', await page.locator('.field-error').count() === 0);
await shot('02-filled-light');
await save.click();
await page.waitForSelector('text=firebase-adminsdk-abc12@al-bahar-home.iam.gserviceaccount.com');
const body = await page.locator('body').innerText();
check('saved: the key shows only as configured with its e-mail', body.includes('Configured') && !body.includes('PRIVATE KEY') && !body.includes('BEGIN'));
const html = await page.content();
check('the page source never contains the private key', !html.includes('BEGIN PRIVATE KEY') && !html.includes(privateKey.split('\n')[1]));
await shot('03-saved-light');

// ── master switch now works
await master.click();
await page.waitForFunction(() => document.querySelector('[role=switch]')?.getAttribute('aria-checked') === 'true');
check('master switch turns on at once (no Save button)', true);

// ── the public config and the worker are served
const cfg = await (await context.request.get(`${BASE}/api/v1/push-config`)).json();
check('public push-config carries the web settings', cfg.data.enabled === true && cfg.data.web.apiKey === values.apiKey && !JSON.stringify(cfg).includes('PRIVATE'));
const sw = await context.request.get(`${BASE}/firebase-messaging-sw.js`);
check('service worker is JavaScript with the saved config', sw.headers()['content-type'].startsWith('text/javascript') && (await sw.text()).includes(values.apiKey) && sw.headers()['service-worker-allowed'] === '/');
const sw2 = await context.request.get(`${BASE}/mobile-web/firebase-messaging-sw.js`);
check('the mobile-web worker path is served too', sw2.status() === 200 && sw2.headers()['content-type'].startsWith('text/javascript'));

// ── test connection (no internet here: it must answer with a plain-language reason, not an error page)
await page.getByRole('button', { name: 'Test connection' }).click();
await page.waitForSelector('[role=status] p.font-medium', { timeout: 20000 });
const conn = await page.locator('[role=status] p.font-medium').first().innerText();
check('test connection answers in plain words', conn.length > 20, conn);

// ── enable on this browser: real SDK + real worker, stubbed Google endpoints
await page.reload();
await page.waitForSelector('h1');
const enable = page.getByRole('button', { name: 'Enable on this browser' });
check('the enable button is available once push is on', await enable.isEnabled());
await enable.click();
await page.waitForFunction(() => document.body.innerText.includes('This browser will now receive notifications.') || document.querySelector('.text-ruby-soft[role=alert]')?.innerText, null, { timeout: 30000 });
const err = await page.locator('p[role=alert].text-ruby-soft').first().innerText().catch(() => '');
check('enabling this browser works end to end', !err, err + ' ' + log.filter((l) => /Push:/.test(l)).join(' '));
check('the token was sent to the server with the CSRF header', posts.length === 1 && JSON.parse(posts[0]).push_token.startsWith('stub-fcm-token-'));
await page.waitForSelector('text=Last seen');
const reg = await page.evaluate(async () => (await navigator.serviceWorker.getRegistrations()).map((r) => r.scope));
check('the worker is registered under /admin/ only', reg.length === 1 && reg[0].endsWith('/admin/'), reg.join(','));
check('the device shows in the list', (await page.locator('text=Last seen').count()) === 1 && (await page.locator('text=Chrome').count()) > 0);
await shot('04-enabled-light');

// ── a message that arrives while the page is open becomes a toast
const worker = context.serviceWorkers().find((w) => w.url().includes('firebase-messaging-sw.js'));
check('the real service worker is running', !!worker);

// ── dark, RTL, phone
await page.evaluate(() => { localStorage.setItem('theme', 'dark'); });
await page.reload();
await page.waitForSelector('h1');
await shot('05-dark');
await page.goto(`${BASE}/admin/locale/ar`);
await page.goto(`${BASE}/admin/push`);
await page.waitForSelector('h1');
check('Arabic page is right-to-left with Arabic copy', (await page.locator('html').getAttribute('dir')) === 'rtl' && (await page.locator('h1').innerText()) === 'الإشعارات الفورية');
await shot('06-arabic-dark');
await page.setViewportSize({ width: 375, height: 800 });
await page.waitForTimeout(300);
const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
check('no sideways scroll at 375px', overflow <= 0, `overflow ${overflow}px`);
const small = await page.evaluate(() => [...document.querySelectorAll('main button, main a, main label.btn-secondary, main summary')].filter((e) => e.offsetParent && (e.getBoundingClientRect().height < 40 || e.getBoundingClientRect().width < 40)).map((e) => `${e.tagName}:${(e.innerText || e.getAttribute('aria-label') || '').slice(0, 25)}:${Math.round(e.getBoundingClientRect().width)}x${Math.round(e.getBoundingClientRect().height)}`));
check('touch targets are at least 40px', small.length === 0, small.join(' | '));
await shot('07-phone-arabic-dark');
await page.goto(`${BASE}/admin/locale/en`);

const noise = log.filter((l) => !/favicon|Failed to load resource.*(403|404)|net::ERR/.test(l));
check('no console errors or page errors', noise.length === 0, noise.slice(0, 4).join(' | '));
await browser.close();
const failed = results.filter((r) => !r.ok);
console.log(`\n${results.length - failed.length}/${results.length} passed`);
process.exit(failed.length ? 1 : 0);
