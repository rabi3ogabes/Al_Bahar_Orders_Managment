// 200+ drivers on the fleet map: first paint, clustering, DOM size and frame cost while ~40 live moves per second arrive.
// Needs the seeded QA database plus 200 extra drivers, and Reverb. Run the mover in the background: php tests/e2e-maps/emit-many.php 14 40
import { spawn } from 'node:child_process';
import { BASE, chromium, login, shot, stubTiles, watch } from './lib.mjs';

const results = [];
const check = (name, ok, extra = '') => { results.push([name, ok]); console.log(`${ok ? 'PASS' : 'FAIL'} ${name}${extra ? ` (${extra})` : ''}`); };
const browser = await chromium.launch();
const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
const page = await ctx.newPage();
const log = watch(page);
await stubTiles(page);
await login(page);
const t0 = Date.now();
await page.goto(`${BASE}/admin/fleet`);
await page.waitForSelector('.abm .leaflet-marker-icon', { timeout: 30000 });
const firstPaint = Date.now() - t0;
await page.waitForTimeout(1200);
const stats = await page.evaluate(() => ({
    rows: document.querySelectorAll('.abm-row').length,
    drivers: document.querySelectorAll('.abm-dm').length,
    clusters: document.querySelectorAll('.abm-cl').length,
    domNodes: document.querySelectorAll('.abm *').length,
}));
console.log('first paint ms', firstPaint, JSON.stringify(stats));
check('200+ rows in the list', stats.rows >= 200, String(stats.rows));
check('clusters stand in for dense drivers (more than 40 markers)', stats.clusters > 0 && stats.drivers + stats.clusters < stats.rows, `${stats.clusters} clusters, ${stats.drivers} single`);
await shot(page, 'fleet-scale-clusters');

// frame cost while live moves arrive
await page.evaluate(() => {
    window.__frames = [];
    let last = performance.now();
    const tick = (t) => { window.__frames.push(t - last); last = t; requestAnimationFrame(tick); };
    requestAnimationFrame(tick);
    window.__long = [];
    new PerformanceObserver((l) => l.getEntries().forEach((e) => window.__long.push(e.duration))).observe({ entryTypes: ['longtask'] });
});
const mover = spawn('php', ['tests/e2e-maps/emit-many.php', '10', '40'], { cwd: new URL('../../', import.meta.url).pathname });
await page.waitForTimeout(9000);
mover.kill();
const m = await page.evaluate(() => {
    const f = window.__frames.slice(5).sort((a, b) => a - b);
    return { n: f.length, p50: f[Math.floor(f.length * 0.5)], p95: f[Math.floor(f.length * 0.95)], max: f[f.length - 1], long: window.__long.length, longMax: Math.max(0, ...window.__long) };
});
console.log('frames', JSON.stringify(m));
check('frame p95 under 34 ms (30+ fps) with 40 moves/s', m.p95 < 34, `p50 ${m.p50?.toFixed(1)} p95 ${m.p95?.toFixed(1)} max ${m.max?.toFixed(1)}`);
check('no long tasks over 120 ms', m.longMax < 120, `${m.long} long tasks, max ${m.longMax.toFixed(0)} ms`);

// zoom in: clusters dissolve
for (let i = 0; i < 4; i++) { await page.click('.abm-tool[aria-label="Zoom in"]'); await page.waitForTimeout(500); }
await page.waitForTimeout(700);
const after = await page.evaluate(() => ({ clusters: document.querySelectorAll('.abm-cl').length, drivers: document.querySelectorAll('.abm-dm').length }));
check('zooming in dissolves clusters into drivers', after.clusters < stats.clusters && after.drivers > stats.drivers, JSON.stringify(after));
await shot(page, 'fleet-scale-zoomed');
// click a cluster
console.log('console:', log.join(' | ') || 'clean');
await browser.close();
process.exit(results.every(([, ok]) => ok) ? 0 : 1);
