/**
 * Accessibility audit of the built web store (axe-core through Playwright): every public page type, English and Arabic, light and dark,
 * at 1280 px (and 375 px for English light). Reports axe violations, pages without exactly one h1, sideways overflow and console errors.
 * Not part of `npm test` (needs a running store with data). Dev tools only, nothing is added to the app's dependencies:
 *
 *   npm i --no-save playwright @axe-core/playwright     # once, in an empty temp folder is fine too
 *   STORE_URL=http://127.0.0.1:8000 node tests/e2e/a11y-audit.e2e.mjs
 *   STORE_PAGES='[["home","/"],["product","/products/some-slug"]]' STORE_URL=... node tests/e2e/a11y-audit.e2e.mjs   # your own pages
 *
 * Exit code 1 when anything is found. docs/guides/SEO.md ("Quality pass") lists the pages and the last results.
 */
import { createRequire } from 'node:module';
const require = createRequire(import.meta.url);
const { chromium } = require('playwright');
const AxeBuilder = require('@axe-core/playwright').default;

const BASE = process.env.STORE_URL ?? 'http://127.0.0.1:8000';
const PAGES = JSON.parse(process.env.STORE_PAGES ?? 'null') ?? [
  ['home', '/'], ['shop', '/shop'], ['category', '/shop?category=sofas'], ['search', '/shop?q=sofa'], ['deals', '/deals'], ['auctions', '/auctions'],
  ['cart', '/cart'], ['checkout', '/checkout'], ['sign-in', '/login'], ['cms', '/pages/about'], ['showrooms', '/showrooms'], ['404', '/no-such-page'],
];
const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'best-practice'];

const browser = await chromium.launch(process.env.CHROME_PATH ? { executablePath: process.env.CHROME_PATH, args: ['--no-sandbox'] } : {});
let problems = 0;
for (const [name, path] of PAGES) for (const lang of ['en', 'ar']) for (const theme of ['light', 'dark']) for (const width of lang === 'en' && theme === 'light' ? [1280, 375] : [1280]) {
  const ctx = await browser.newContext({ viewport: { width, height: width === 375 ? 780 : 800 }, reducedMotion: 'reduce' });
  await ctx.addInitScript(([l, t]) => { try { localStorage.setItem('albahar.locale', l); localStorage.setItem('albahar.theme', t); } catch { /* ignore */ } }, [lang, theme]);
  const page = await ctx.newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push(String(e).slice(0, 120)));
  page.on('console', (m) => { if (m.type() === 'error' && !/Failed to load resource/.test(m.text())) errors.push(m.text().slice(0, 120)); });
  await page.goto(BASE + path, { waitUntil: 'load' });
  await page.waitForSelector('h1', { timeout: 8000 }).catch(() => {});
  await page.waitForTimeout(800);
  const { violations } = await new AxeBuilder({ page }).withTags(TAGS).analyze();
  const facts = await page.evaluate(() => ({ h1: document.querySelectorAll('h1').length, overflow: document.documentElement.scrollWidth > innerWidth + 1 }));
  const found = violations.map((v) => `${v.id} (${v.nodes.length}): ${v.nodes[0].target.join(' ').slice(0, 80)}`);
  if (facts.h1 !== 1) found.push(`${facts.h1} h1 headings`);
  if (facts.overflow) found.push('sideways overflow');
  found.push(...errors.map((e) => `console: ${e}`));
  problems += found.length;
  console.log(`${found.length ? 'FAIL' : 'PASS'} ${name} ${lang} ${theme} ${width}px${found.length ? '\n   ' + found.join('\n   ') : ''}`);
  await ctx.close();
}
await browser.close();
console.log(problems ? `${problems} problem(s)` : 'No problems');
process.exit(problems ? 1 : 0);
