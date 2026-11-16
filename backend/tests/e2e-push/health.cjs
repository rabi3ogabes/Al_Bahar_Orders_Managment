// Browser check of the dashboard Push page's "why does push not arrive" sections: push health per phone app (and the shared App ID warning),
// what the phones report, the test-to-a-phone panel and "Fill from google-services.json". EN and AR at 375, 768 and 1280 px, no sideways
// scroll, no console errors. Not part of CI: it needs a running app on a database seeded with a super admin, two Android apps sharing one
// App ID, a few devices and some push_diagnostics rows, and Chromium.
// Usage: BASE=http://127.0.0.1:8104 ADMIN_EMAIL=... ADMIN_PASSWORD=... SHOTS=/tmp/push-health-shots node tests/e2e-push/health.cjs
const path = require('path');
let chromium;
try { ({ chromium } = require('playwright')); } catch { ({ chromium } = require('/opt/node-tools/node_modules/playwright')); }
const BASE = process.env.BASE || 'http://127.0.0.1:8104';
const SHOTS = process.env.SHOTS || path.join(__dirname, 'shots');
require('fs').mkdirSync(SHOTS, { recursive: true });
const EMAIL = process.env.ADMIN_EMAIL || 'qa@albahar.test';
const PASSWORD = process.env.ADMIN_PASSWORD || '';
const results = [];
const check = (name, ok, extra = '') => { results.push(ok); console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${extra ? `  (${extra})` : ''}`); };

(async () => {
  const browser = await chromium.launch();
  const ctx0 = await browser.newContext();
  const p0 = await ctx0.newPage();
  await p0.goto(`${BASE}/admin/login`);
  await p0.fill('#email', EMAIL);
  await p0.fill('#password', PASSWORD);
  await Promise.all([p0.waitForURL(/\/admin(?!\/login)/, { timeout: 30000 }), p0.click('button[type=submit]')]);
  const state = await ctx0.storageState();
  await ctx0.close();

  for (const lang of ['en', 'ar']) {
    for (const [w, h] of [[375, 900], [768, 1000], [1280, 900]]) {
      const ctx = await browser.newContext({ storageState: state, viewport: { width: w, height: h } });
      const page = await ctx.newPage();
      const errors = [];
      page.on('pageerror', (e) => errors.push(`pageerror: ${e.message}`));
      page.on('console', (m) => { if (m.type() === 'error' && !/ERR_TUNNEL|ERR_NAME|Failed to load resource|gstatic|firebase/i.test(m.text())) errors.push(`console: ${m.text()}`); });
      await page.goto(`${BASE}/admin/locale/${lang}`);
      await page.goto(`${BASE}/admin/push`);
      await page.waitForLoadState('networkidle').catch(() => {});
      await page.waitForTimeout(600);
      const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
      check(`${lang} ${w}: no sideways scroll`, overflow <= 1, `overflow ${overflow}`);
      check(`${lang} ${w}: duplicate banner shown`, await page.locator('[role=alert]').filter({ hasText: lang === 'en' ? 'share one App ID' : 'معرّف واحد' }).count() > 0);
      check(`${lang} ${w}: health cards (3 android + ios + web)`, await page.locator('article[aria-labelledby^="health-"]').count() === 5);
      check(`${lang} ${w}: phone reports listed`, await page.locator('#push-reports').count() === 1 && await page.locator('li[wire\\:key^="prob-"]').count() >= 3);
      check(`${lang} ${w}: test panel present`, await page.locator('#phoneTarget').count() === 1);
      await page.screenshot({ path: path.join(SHOTS, `push-${lang}-${w}.png`), fullPage: true });
      check(`${lang} ${w}: no console errors`, errors.length === 0, errors.slice(0, 2).join(' | '));
      await ctx.close();
    }
  }

  // Interaction (EN, desktop): import a google-services.json, send a test (FCM is unreachable here, so the plain "could not reach" answer shows), dark theme.
  const ctx = await browser.newContext({ storageState: state, viewport: { width: 1280, height: 900 } });
  const page = await ctx.newPage();
  await page.goto(`${BASE}/admin/locale/en`);
  await page.goto(`${BASE}/admin/push`);
  await page.waitForLoadState('networkidle').catch(() => {});
  const gs = JSON.stringify({ project_info: { project_number: '704229358509', project_id: 'albaharqatar' }, client: ['customer', 'driver', 'staff'].map((a, i) => ({ client_info: { mobilesdk_app_id: `1:704229358509:android:${(0xabc000 + i * 1111).toString(16)}aa11bb22cc`, android_client_info: { package_name: `qa.albahar.${a}` } }, api_key: [{ current_key: 'AIzaSyB2c3D4e5F6g7H8i9J0k1L2m3N4o5P6q7R' }] })) });
  await page.setInputFiles('input[type=file][accept="application/json,.json"] >> nth=0', { name: 'google-services.json', mimeType: 'application/json', buffer: Buffer.from(gs) });
  await page.waitForTimeout(1500);
  const ids = await Promise.all(['appIdAndroidCustomer', 'appIdAndroidDriver', 'appIdAndroidStaff'].map((id) => page.inputValue(`#${id}`)));
  check('import fills three different App IDs', new Set(ids).size === 3 && ids.every((v) => /^1:704229358509:android:/.test(v)), ids.join(' '));
  check('import: duplicate warning on the fields is gone', await page.locator('.field-error').filter({ hasText: 'also used by' }).count() === 0);
  await page.screenshot({ path: path.join(SHOTS, 'push-en-1280-imported.png'), fullPage: false });

  await page.selectOption('#phoneTarget', { index: 1 });
  await page.click('button:has-text("Send test")');
  await page.waitForFunction(() => /HTTP \d+/.test(document.querySelector('#push-phone-test')?.closest('section')?.innerText ?? ''), null, { timeout: 40000 }).catch(() => {});
  const resultText = await page.locator('section:has(#push-phone-test)').innerText();
  check('test result shows plain words', /Firebase|server could not reach|not ready/i.test(resultText), resultText.slice(-160).replace(/\n/g, ' | '));
  await page.locator('#push-phone-test').scrollIntoViewIfNeeded();
  await page.screenshot({ path: path.join(SHOTS, 'push-en-1280-test.png') });

  await page.evaluate(() => { document.documentElement.classList.add('dark'); document.documentElement.setAttribute('data-theme', 'dark'); try { localStorage.setItem('theme', 'dark'); } catch (e) {} });
  await page.reload();
  await page.waitForLoadState('networkidle').catch(() => {});
  await page.screenshot({ path: path.join(SHOTS, 'push-en-1280-dark.png'), fullPage: true });
  await ctx.close();
  await browser.close();
  console.log(results.every(Boolean) ? '\nall checks passed' : `\n${results.filter((r) => !r).length} check(s) failed`);
})().catch((e) => { console.error(e); process.exit(1); });
