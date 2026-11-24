/**
 * Lighthouse scores of the built web store, mobile and desktop (and Arabic / dark for the home page), as a table.
 * Measure a production build served like production (compressed text, immutable asset headers), never the live site. Not part of `npm test`.
 *
 *   npm i --no-save lighthouse            # dev tool only
 *   STORE_URL=http://127.0.0.1:8100 CHROME_PATH=/usr/bin/google-chrome node tests/e2e/lighthouse-audit.e2e.mjs
 *   STORE_PAGES='[["home","/"]]' ...      # your own pages
 *
 * Runs one page at a time (parallel runs distort the scores) and takes about a minute per run; scores move a few points between runs.
 * A page that answers 404 (the real 404 page) cannot be scored by Lighthouse and is skipped. docs/guides/SEO.md ("Quality pass") holds the last results.
 */
import { execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const bin = require.resolve('lighthouse/cli/index.js');
const BASE = process.env.STORE_URL ?? 'http://127.0.0.1:8000';
const PAGES = JSON.parse(process.env.STORE_PAGES ?? 'null') ?? [
  ['home', '/'], ['shop', '/shop'], ['category', '/shop?category=sofas'], ['deals', '/deals'], ['auctions', '/auctions'], ['cart', '/cart'], ['checkout', '/checkout'], ['cms', '/pages/about'],
];
const extra = [['en', 'light'], ['ar', 'light'], ['en', 'dark']];

console.log('| Page | Device | Language, theme | Performance | Accessibility | Best practices | SEO | LCP | CLS | TBT |\n|---|---|---|---|---|---|---|---|---|---|');
for (const [name, path] of PAGES) for (const device of ['mobile', 'desktop']) for (const [lang, theme] of extra) {
  if ((lang !== 'en' || theme !== 'light') && (name !== 'home' || device !== 'mobile')) continue;
  const flags = ['--headless=new', '--no-sandbox', `--lang=${lang}`, theme === 'dark' ? '--blink-settings=preferredColorScheme=1' : ''].filter(Boolean).join(' ');
  const args = [bin, BASE + path, '--output=json', '--quiet', `--chrome-flags=${flags}`, '--only-categories=performance,accessibility,best-practices,seo', ...(device === 'desktop' ? ['--preset=desktop'] : [])];
  try {
    const j = JSON.parse(execFileSync(process.execPath, args, { maxBuffer: 1 << 28, timeout: 180000, env: process.env }).toString());
    const c = j.categories, a = j.audits, pct = (k) => Math.round(c[k].score * 100);
    console.log(`| ${name} | ${device} | ${lang} ${theme} | ${pct('performance')} | ${pct('accessibility')} | ${pct('best-practices')} | ${pct('seo')} | ${(a['largest-contentful-paint'].numericValue / 1000).toFixed(1)} s | ${a['cumulative-layout-shift'].numericValue.toFixed(3)} | ${Math.round(a['total-blocking-time'].numericValue)} ms |`);
  } catch (e) {
    console.log(`| ${name} | ${device} | ${lang} ${theme} | not scored: ${String(e.message).split('\n')[0].slice(0, 80)} |`);
  }
}
