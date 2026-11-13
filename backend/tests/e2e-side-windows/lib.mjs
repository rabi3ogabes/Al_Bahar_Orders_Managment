// Shared helpers for the side-window browser checks (not part of CI: they need a running app, a seeded database with a super admin and Chromium).
// Usage: BASE=http://127.0.0.1:8100 ADMIN_EMAIL=... ADMIN_PASSWORD=... node tests/e2e-side-windows/overlays.mjs
import { mkdirSync } from 'node:fs';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
export const { chromium } = (() => { try { return require('playwright'); } catch { return require('/opt/node-tools/node_modules/playwright'); } })();

export const BASE = process.env.BASE ?? 'http://127.0.0.1:8100';
export const SHOTS = process.env.SHOTS ?? '/tmp/side-window-shots';
mkdirSync(SHOTS, { recursive: true });

let failures = 0;
export const check = (name, ok, extra = '') => {
    if (!ok) failures += 1;
    console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${extra ? `  (${extra})` : ''}`);
};
export const done = () => { console.log(failures ? `\n${failures} check(s) failed` : '\nall checks passed'); process.exitCode = failures ? 1 : 0; };

/** Signs in once and returns the storage state to reuse. */
export async function signIn(browser) {
    const email = process.env.ADMIN_EMAIL;
    const password = process.env.ADMIN_PASSWORD;
    if (!email || !password) throw new Error('Set ADMIN_EMAIL and ADMIN_PASSWORD (a super admin of the seeded database).');
    const ctx = await browser.newContext();
    const page = await ctx.newPage();
    await page.goto(`${BASE}/admin/login`);
    await page.fill('#email', email);
    await page.fill('#password', password);
    await Promise.all([page.waitForURL(/\/admin(?!\/login)/, { timeout: 30000 }), page.click('button[type=submit]')]);
    const state = await ctx.storageState();
    await ctx.close();

    return state;
}

/** A page in the given language, theme and viewport; collects console / page errors (third-party blocks are ignored). */
export async function openPage(browser, state, { lang = 'en', theme = 'light', width = 1280, height = 800 } = {}) {
    const ctx = await browser.newContext({ storageState: state, viewport: { width, height }, colorScheme: theme });
    await ctx.addInitScript((t) => { try { localStorage.setItem('theme', t); } catch (e) { /* private mode */ } }, theme);
    const page = await ctx.newPage();
    const errors = [];
    page.on('pageerror', (e) => errors.push(`pageerror: ${e.message}`));
    page.on('console', (m) => { if (m.type() === 'error' && !/ERR_TUNNEL|ERR_NAME|Failed to load resource/.test(m.text())) errors.push(`console: ${m.text()}`); });
    await page.goto(`${BASE}/admin/locale/${lang}`);

    return { ctx, page, errors };
}

export const settle = async (page, ms = 450) => { await page.waitForLoadState('networkidle').catch(() => {}); await page.waitForTimeout(ms); };
