// The fleet map when the real-time link goes away: honest chip, 15 s polling of the Livewire component, no polling while the tab is
// hidden, back to "Live" (with an immediate refresh) when Reverb returns.
// REVERB_STOP and REVERB_START are shell commands that stop / start the Reverb server of the test app.
import { execSync } from 'node:child_process';
import { BASE, chromium, login, shot, stubTiles, watch } from './lib.mjs';

const STOP = process.env.REVERB_STOP ?? 'pkill -f "reverb:start"';
const START = process.env.REVERB_START;
if (!START) throw new Error('REVERB_START (command that starts Reverb in the background) is required');
const results = [];
const check = (name, ok, extra = '') => { results.push([name, ok]); console.log(`${ok ? 'PASS' : 'FAIL'} ${name}${extra ? ` (${extra})` : ''}`); };
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

const browser = await chromium.launch();
const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
const page = await ctx.newPage();
const log = watch(page);
await stubTiles(page);
let updates = 0;
page.on('request', (r) => { if (r.url().includes('/livewire') && r.method() === 'POST') updates += 1; });
await login(page);
await page.goto(`${BASE}/admin/fleet`);
await page.waitForSelector('.abm .leaflet-marker-icon');
await page.waitForFunction(() => document.querySelector('.abm-conn')?.dataset.state === 'live', null, { timeout: 15000 });
check('starts live', true);

execSync(STOP, { stdio: 'ignore', shell: '/bin/bash' });
await page.waitForFunction(() => document.querySelector('.abm-conn')?.dataset.state !== 'live', null, { timeout: 20000 });
const down = await page.locator('.abm-conn').innerText();
check('chip says reconnecting and names the interval', /Reconnecting.*15/.test(down), down);
await shot(page, 'fleet-socket-down');
updates = 0;
await sleep(17000);
check('component refreshed by polling while the socket is down (15 s)', updates >= 1, `${updates} request(s)`);

// Hidden tab: no polling; shown again: refresh at once.
await page.evaluate(() => { Object.defineProperty(document, 'hidden', { configurable: true, get: () => true }); document.dispatchEvent(new Event('visibilitychange')); });
updates = 0;
await sleep(17000);
check('no polling while the tab is hidden', updates === 0, `${updates}`);
await page.evaluate(() => { Object.defineProperty(document, 'hidden', { configurable: true, get: () => false }); document.dispatchEvent(new Event('visibilitychange')); });
await sleep(1500);
check('refresh as soon as the tab is visible again', updates >= 1, `${updates}`);

execSync(START, { stdio: 'ignore', shell: '/bin/bash' });
await page.waitForFunction(() => document.querySelector('.abm-conn')?.dataset.state === 'live', null, { timeout: 45000 });
check('back to live when Reverb returns', true, await page.locator('.abm-conn').innerText());
console.log('console:', log.filter((l) => !/WebSocket|ws:\/\//i.test(l)).join(' | ') || 'clean (socket errors ignored)');
await browser.close();
process.exit(results.every(([, ok]) => ok) ? 0 : 1);
