import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    STALE_MS, Tween, ageParts, applyFull, applyMove, bearing, counts, detectStops, easeInOut, filterDrivers, glideDuration, gridCluster, haversine,
    indexAt, interpolate, isMoving, normaliseStops, isStale, lerpAngle, normAngle, normaliseConfig, normaliseDriver, normaliseMove, normaliseTrack, phoneHref,
    playbackRate, pushTrail, safeAttribution, safeTileUrl, shortestDelta, simplify, sortDrivers, statusOf, thin, trackAt, firstName, initials, toMs, num,
} from '../../resources/js/maps/logic.js';

const NOW = 1_800_000_000_000;
const row = (over = {}) => ({ id: 'd1', name: 'Ravi Kumar', phone: '+97455123456', on_duty: true, lat: 25.3, lng: 51.5, last_seen_at: new Date(NOW - 10_000).toISOString(), branches: ['West Bay'], open_jobs: 0, job: null, ...over });
const drv = (over = {}) => ({ ...normaliseDriver(row(), { now: NOW, serverNow: NOW }), ...over });

/* ───────────── numbers and dates ───────────── */

test('num and toMs read loose payload values and reject junk', () => {
    assert.equal(num('25.5'), 25.5);
    assert.equal(num(''), null);
    assert.equal(num(null), null);
    assert.equal(num(true), null);
    assert.equal(num('abc'), null);
    assert.equal(toMs('2026-01-01T00:00:00Z'), Date.parse('2026-01-01T00:00:00Z'));
    assert.equal(toMs(1_700_000_000), 1_700_000_000_000);
    assert.equal(toMs(1_700_000_000_123), 1_700_000_000_123);
    assert.equal(toMs('nope'), null);
    assert.equal(toMs(null), null);
});

/* ───────────── driver payloads ───────────── */

test('normaliseDriver reads the Livewire row and drops impossible positions', () => {
    const d = normaliseDriver(row({ heading: 370, speed_kmh: '31.5', accuracy_m: 8 }), { now: NOW, serverNow: NOW });
    assert.equal(d.id, 'd1');
    assert.equal(d.heading, 10);
    assert.equal(d.speed, 31.5);
    assert.equal(d.accuracy, 8);
    assert.equal(d.lat, 25.3);
    assert.equal(Math.round(NOW - d.seenAt), 10_000);
    assert.equal(normaliseDriver(row({ lat: 0, lng: 0 }), { now: NOW }).lat, null);
    assert.equal(normaliseDriver(row({ lat: 95 }), { now: NOW }).lat, null);
    assert.equal(normaliseDriver(row({ lat: null, lng: null }), { now: NOW }).lat, null);
    assert.equal(normaliseDriver({ name: 'no id' }), null);
    assert.equal(normaliseDriver(null), null);
});

test('age_seconds beats the clock: the server age is used even when the viewer clock is wrong', () => {
    const d = normaliseDriver(row({ age_seconds: 200, last_seen_at: '1999-01-01T00:00:00Z' }), { now: NOW });
    assert.equal(NOW - d.seenAt, 200_000);
    assert.equal(isStale(d, NOW), true);
    const skew = normaliseDriver(row({ last_seen_at: new Date(NOW + 3_600_000 - 5_000).toISOString() }), { now: NOW, serverNow: NOW + 3_600_000 });
    assert.equal(isStale(skew, NOW), false);
});

test('normaliseDriver reads the job, its destination and the ETA when the server provides them', () => {
    const d = normaliseDriver(row({ open_jobs: 1, job: { delivery_id: 'x', status: 'out_for_delivery', order_number: 'AB-1', order_id: 'o9', destination: { lat: 25.31, lng: 51.52 }, eta_minutes: 12 } }), { now: NOW });
    assert.deepEqual([d.job.orderNumber, d.job.orderId, d.job.status, d.job.etaMinutes], ['AB-1', 'o9', 'out_for_delivery', 12]);
    assert.deepEqual(d.job.destination, { lat: 25.31, lng: 51.52 });
    assert.equal(statusOf(d), 'out');
    assert.equal(normaliseDriver(row({ job: { status: 'assigned', order_number: 'AB-2', destination: { lat: 0, lng: 0 } } }), { now: NOW }).job.destination, null);
});

test('statusOf: off duty, free, assigned, carrying', () => {
    assert.equal(statusOf(drv({ onDuty: false })), 'offline');
    assert.equal(statusOf(drv()), 'idle');
    assert.equal(statusOf(drv({ openJobs: 1 })), 'assigned');
    assert.equal(statusOf(drv({ job: { status: 'accepted' } })), 'assigned');
    assert.equal(statusOf(drv({ job: { status: 'picked_up' } })), 'out');
});

test('normaliseMove accepts a driver.moved frame and refuses broken ones', () => {
    const m = normaliseMove({ driver_id: 'd1', lat: 25.31, lng: 51.51, heading: 90, speed_kmh: 30, at: new Date(NOW).toISOString(), order_id: 'o1', delivery_status: 'out_for_delivery' }, { now: NOW });
    assert.deepEqual([m.id, m.lat, m.heading, m.speed, m.fixMs, m.deliveryStatus], ['d1', 25.31, 90, 30, NOW, 'out_for_delivery']);
    assert.equal(normaliseMove({ driver_id: 'd1', lat: 'x', lng: 1 }), null);
    assert.equal(normaliseMove({ lat: 25, lng: 51 }), null);
    assert.equal(normaliseMove(null), null);
});

/* ───────────── out-of-order guard ───────────── */

test('applyMove ignores a frame older than the position already shown', () => {
    const prev = drv({ fixMs: NOW, lat: 25.3, lng: 51.5 });
    const late = normaliseMove({ driver_id: 'd1', lat: 25.2, lng: 51.4, at: new Date(NOW - 5_000).toISOString() }, { now: NOW });
    assert.equal(applyMove(prev, late), null);
    const newer = normaliseMove({ driver_id: 'd1', lat: 25.31, lng: 51.5, at: new Date(NOW + 3_000).toISOString() }, { now: NOW + 3_000 });
    const next = applyMove(prev, newer);
    assert.equal(next.lat, 25.31);
    assert.equal(next.fixMs, NOW + 3_000);
    const same = normaliseMove({ driver_id: 'd1', lat: 25.3, lng: 51.5, at: new Date(NOW).toISOString() }, { now: NOW });
    assert.notEqual(applyMove(prev, same), null);
});

test('applyMove derives a heading from movement when the frame has none, and marks the driver as moving', () => {
    const prev = drv({ fixMs: NOW, heading: null, seenAt: NOW });
    const m = normaliseMove({ driver_id: 'd1', lat: 25.301, lng: 51.5, at: new Date(NOW + 2_000).toISOString() }, { now: NOW + 2_000 });
    const next = applyMove(prev, m);
    assert.ok(next.heading < 1 || next.heading > 359, `north, got ${next.heading}`);
    assert.equal(next.movedAt, NOW + 2_000);
    assert.equal(isMoving({ ...next, speed: null }, NOW + 5_000), true);
    assert.equal(isMoving({ ...next, speed: null }, NOW + 60_000), false);
});

test('applyMove updates the job status of the order the driver is carrying', () => {
    const prev = drv({ job: { status: 'accepted', orderNumber: 'AB-1', orderId: 'o1', deliveryId: 'x', destination: null, etaMinutes: null, etaAt: null }, fixMs: NOW });
    const m = normaliseMove({ driver_id: 'd1', lat: 25.31, lng: 51.5, at: new Date(NOW + 1000).toISOString(), delivery_status: 'out_for_delivery', order_id: 'o1' }, { now: NOW });
    assert.equal(statusOf(applyMove(prev, m)), 'out');
});

test('applyFull keeps a newer live position over an older refresh but takes duty and job from the refresh', () => {
    const prev = drv({ fixMs: NOW + 5_000, lat: 25.4, lng: 51.6, seenAt: NOW });
    const next = normaliseDriver(row({ lat: 25.3, lng: 51.5, on_duty: false, open_jobs: 2, last_seen_at: new Date(NOW).toISOString() }), { now: NOW, serverNow: NOW });
    const merged = applyFull(prev, next);
    assert.deepEqual([merged.lat, merged.lng], [25.4, 51.6]);
    assert.equal(merged.onDuty, false);
    assert.equal(merged.openJobs, 2);
    const fresher = normaliseDriver(row({ lat: 25.5, lng: 51.7, last_seen_at: new Date(NOW + 9_000).toISOString() }), { now: NOW, serverNow: NOW + 9_000 });
    assert.equal(applyFull(prev, fresher).lat, 25.5);
    assert.equal(applyFull(prev, normaliseDriver(row({ lat: null, lng: null }), { now: NOW })).lat, 25.4);
    assert.equal(applyFull(null, next).trail.length, 1);
});

test('pushTrail is bounded and skips repeats', () => {
    let t = [];
    for (let i = 0; i < 60; i += 1) t = pushTrail(t, { lat: 25 + i * 0.001, lng: 51 });
    assert.equal(t.length, 40);
    assert.equal(t.at(-1).lat, 25.059);
    assert.equal(pushTrail(t, { lat: 25.059, lng: 51 }).length, 40);
    assert.equal(pushTrail([], { lat: null, lng: null }).length, 0);
});

/* ───────────── stale, moving, counts, filters ───────────── */

test('stale after the threshold, with a configurable limit', () => {
    const d = normaliseDriver(row({ age_seconds: 91 }), { now: NOW });
    assert.equal(isStale(d, NOW), true);
    assert.equal(isStale(normaliseDriver(row({ age_seconds: 89 }), { now: NOW }), NOW), false);
    assert.equal(isStale(d, NOW, 120_000), false);
    assert.equal(isStale({ ...d, seenAt: null }, NOW), true);
    assert.equal(STALE_MS, 90_000);
});

test('counts: on duty, moving, idle and stale', () => {
    const list = [
        drv({ id: 'a', speed: 30 }),
        drv({ id: 'b' }),
        drv({ id: 'c', seenAt: NOW - 300_000 }),
        drv({ id: 'd', onDuty: false }),
        drv({ id: 'e', openJobs: 1, speed: 0 }),
    ];
    const c = counts(list, NOW);
    assert.deepEqual(c, { onDuty: 4, moving: 1, idle: 3, stale: 1, total: 5 });
});

test('filterDrivers searches name, order number and branch (accent and case blind) and filters by status', () => {
    const list = [
        drv({ id: 'a', name: 'Ravi Kumar', branches: ['West Bay'], job: { orderNumber: 'AB-1001', status: 'out_for_delivery' } }),
        drv({ id: 'b', name: 'Joseph Mathew', branches: ['Al Wakrah'] }),
        drv({ id: 'c', name: 'سالم', branches: ['Lusail'], onDuty: false }),
        drv({ id: 'd', name: 'Stale Sam', seenAt: NOW - 400_000 }),
    ];
    const ids = (f) => filterDrivers(list, f, NOW).map((d) => d.id);
    assert.deepEqual(ids({ query: 'ravi' }), ['a']);
    assert.deepEqual(ids({ query: 'ab-1001' }), ['a']);
    assert.deepEqual(ids({ query: 'wakrah' }), ['b']);
    assert.deepEqual(ids({ query: 'سالم' }), ['c']);
    assert.deepEqual(ids({ status: 'out' }), ['a']);
    assert.deepEqual(ids({ status: 'offline' }), ['c']);
    assert.deepEqual(ids({ status: 'stale' }), ['d']);
    assert.deepEqual(ids({ status: 'idle', query: 'jos' }), ['b']);
    assert.equal(ids({}).length, 4);
});

test('sortDrivers: carrying first, then assigned, free, off duty; fresh before stale; then by name', () => {
    const list = [
        drv({ id: 'off', name: 'A', onDuty: false }),
        drv({ id: 'idle', name: 'B' }),
        drv({ id: 'idleStale', name: 'A', seenAt: NOW - 500_000 }),
        drv({ id: 'asg', name: 'C', openJobs: 1 }),
        drv({ id: 'out', name: 'D', job: { status: 'picked_up' } }),
    ];
    assert.deepEqual(sortDrivers(list, NOW).map((d) => d.id), ['out', 'asg', 'idle', 'idleStale', 'off']);
});

test('ageParts picks a readable unit', () => {
    assert.deepEqual(ageParts(2_000), { unit: 'now', n: 0 });
    assert.deepEqual(ageParts(40_000), { unit: 'second', n: 40 });
    assert.deepEqual(ageParts(180_000), { unit: 'minute', n: 3 });
    assert.deepEqual(ageParts(7_300_000), { unit: 'hour', n: 2 });
    assert.deepEqual(ageParts(200_000_000), { unit: 'day', n: 2 });
    assert.deepEqual(ageParts(null), { unit: 'none', n: 0 });
});

/* ───────────── heading and animation ───────────── */

test('heading interpolation takes the short way round', () => {
    assert.equal(normAngle(-90), 270);
    assert.equal(normAngle(725), 5);
    assert.equal(shortestDelta(350, 10), 20);
    assert.equal(shortestDelta(10, 350), -20);
    assert.equal(shortestDelta(0, 180), 180);
    assert.ok(Math.abs(shortestDelta(lerpAngle(350, 10, 0.5), 0)) < 1e-9);
    assert.ok(Math.abs(shortestDelta(lerpAngle(10, 350, 0.5), 0)) < 1e-9);
    assert.equal(lerpAngle(90, 100, 1), 100);
});

test('bearing and distance behave on known points', () => {
    const a = { lat: 25.3, lng: 51.5 };
    assert.ok(Math.abs(bearing(a, { lat: 25.4, lng: 51.5 })) < 0.01);
    assert.ok(Math.abs(bearing(a, { lat: 25.3, lng: 51.6 }) - 90) < 0.1);
    assert.ok(Math.abs(haversine(a, { lat: 25.31, lng: 51.5 }) - 1111.9) < 3);
    assert.equal(haversine(a, a), 0);
});

test('easeInOut runs 0 to 1 and is monotonic', () => {
    assert.equal(easeInOut(0), 0);
    assert.equal(easeInOut(1), 1);
    assert.ok(Math.abs(easeInOut(0.5) - 0.5) < 1e-12);
    assert.ok(easeInOut(0.25) < easeInOut(0.5));
    assert.equal(easeInOut(-2), 0);
    assert.equal(easeInOut(7), 1);
});

test('interpolate blends position and heading; a missing heading takes the other end', () => {
    const r = interpolate({ lat: 0, lng: 0, heading: 350 }, { lat: 10, lng: 20, heading: 10 }, 0.5);
    assert.deepEqual([r.lat, r.lng], [5, 10]);
    assert.ok(r.heading < 1e-9 || r.heading > 360 - 1e-9);
    assert.equal(interpolate({ lat: 0, lng: 0, heading: null }, { lat: 1, lng: 1, heading: 40 }, 0.3).heading, 40);
    assert.equal(interpolate({ lat: 0, lng: 0, heading: 40 }, { lat: 1, lng: 1, heading: null }, 0.3).heading, 40);
});

test('Tween glides from the current spot, never jumps, and finishes exactly on the target', () => {
    const t = new Tween({ lat: 25, lng: 51, heading: 0 });
    assert.equal(t.at(0).done, true);
    t.retarget({ lat: 25, lng: 51, heading: 0 }, { lat: 26, lng: 52, heading: 90 }, 1000, 1000);
    assert.deepEqual(t.at(1000), { lat: 25, lng: 51, heading: 0, done: false });
    const mid = t.at(1500);
    assert.ok(Math.abs(mid.lat - 25.5) < 1e-9 && Math.abs(mid.heading - 45) < 1e-6 && !mid.done);
    assert.deepEqual(t.at(2000), { lat: 26, lng: 52, heading: 90, done: true });
    assert.equal(t.at(9000).done, true);
    // A new fix arrives mid-glide: start from where the marker is, not from the old start.
    t.retarget(mid, { lat: 25.6, lng: 51.6, heading: 90 }, 1500, 1000);
    assert.equal(t.at(1500).lat, mid.lat);
});

test('glideDuration: ~1 s for normal moves, none for reduced motion, tiny moves and huge jumps', () => {
    assert.equal(glideDuration(120), 1000);
    assert.equal(glideDuration(120, { reduced: true }), 0);
    assert.equal(glideDuration(0.5), 0);
    assert.equal(glideDuration(5000), 0);
    assert.equal(glideDuration(120, { base: 700 }), 700);
});

/* ───────────── clustering ───────────── */

test('gridCluster groups points sharing a cell and leaves the pinned driver alone', () => {
    const project = (lat, lng) => ({ x: lng * 1000, y: lat * 1000 });
    const items = [
        { id: 'a', lat: 1, lng: 1, status: 'out' },
        { id: 'b', lat: 1.01, lng: 1.01, status: 'idle' },
        { id: 'c', lat: 1.02, lng: 1.02, status: 'idle' },
        { id: 'far', lat: 9, lng: 9, status: 'idle' },
    ];
    const r = gridCluster(items, project, 64);
    assert.equal(r.clusters.length, 1);
    assert.deepEqual(r.clusters[0].ids.sort(), ['a', 'b', 'c']);
    assert.equal(r.clusters[0].count, 3);
    assert.equal(r.clusters[0].status, 'idle');
    assert.ok(Math.abs(r.clusters[0].lat - 1.01) < 1e-9);
    assert.deepEqual(r.singles, ['far']);
    const pinned = gridCluster(items, project, 64, new Set(['a']));
    assert.deepEqual(pinned.clusters[0].ids.sort(), ['b', 'c']);
    assert.ok(pinned.singles.includes('a'));
});

test('gridCluster is stable for the same input and splits as the zoom grows', () => {
    const items = Array.from({ length: 100 }, (_, i) => ({ id: `d${i}`, lat: 25 + (i % 10) * 0.001, lng: 51 + Math.floor(i / 10) * 0.001, status: 'idle' }));
    const coarse = (lat, lng) => ({ x: lng * 5000, y: lat * 5000 });
    const fine = (lat, lng) => ({ x: lng * 500000, y: lat * 500000 });
    const a = gridCluster(items, coarse, 64);
    const b = gridCluster(items, coarse, 64);
    assert.deepEqual(a, b);
    const c = gridCluster(items, fine, 64);
    assert.ok(a.clusters.length < c.clusters.length + c.singles.length);
    assert.equal(a.clusters.reduce((s, x) => s + x.count, 0) + a.singles.length, 100);
    assert.equal(c.singles.length, 100);
});

/* ───────────── routes ───────────── */

test('simplify keeps the shape of a route and its ends', () => {
    const line = Array.from({ length: 200 }, (_, i) => ({ lat: 25 + i * 0.0001, lng: 51 + (i % 2 ? 0.0000005 : 0) }));
    const s = simplify(line, 8);
    assert.equal(s.length, 2);
    assert.equal(s[0], line[0]);
    assert.equal(s.at(-1), line.at(-1));
    const corner = [{ lat: 25, lng: 51 }, { lat: 25.01, lng: 51 }, { lat: 25.01, lng: 51.01 }];
    assert.equal(simplify(corner, 8).length, 3);
    assert.equal(simplify(corner, 0).length, 3);
    assert.equal(simplify([{ lat: 1, lng: 1 }], 8).length, 1);
});

test('thin keeps the last point and the requested size', () => {
    const pts = Array.from({ length: 1000 }, (_, i) => ({ i }));
    const t = thin(pts, 100);
    assert.equal(t.length, 100);
    assert.equal(t[0].i, 0);
    assert.equal(t.at(-1).i, 999);
    assert.equal(thin(pts.slice(0, 5), 100).length, 5);
});

test('detectStops finds a dwell and ignores a pass-through', () => {
    const t0 = NOW;
    const pts = [];
    for (let i = 0; i < 10; i += 1) pts.push({ lat: 25 + i * 0.001, lng: 51, t: t0 + i * 30_000 }); // driving
    for (let i = 0; i < 8; i += 1) pts.push({ lat: 25.01 + (i % 2) * 0.00005, lng: 51, t: t0 + 300_000 + i * 30_000 }); // 3.5 min at one place
    for (let i = 0; i < 5; i += 1) pts.push({ lat: 25.011 + i * 0.001, lng: 51, t: t0 + 600_000 + i * 30_000 });
    const stops = detectStops(pts, { radiusM: 35, minMs: 120_000 });
    assert.equal(stops.length, 1);
    assert.ok(stops[0].ms >= 200_000 && stops[0].ms <= 240_000, `ms ${stops[0].ms}`);
    assert.ok(Math.abs(stops[0].lat - 25.01) < 0.0002);
    assert.equal(detectStops(pts.slice(0, 10), {}).length, 0);
    assert.equal(detectStops([], {}).length, 0);
});

test('normaliseTrack sorts, drops junk and invents times for untimed points', () => {
    const t = normaliseTrack([{ lat: 25.2, lng: 51.5, at: '2026-01-01T10:05:00Z' }, { lat: 25.1, lng: 51.5, at: '2026-01-01T10:00:00Z' }, { lat: 'x', lng: 1 }, { lat: 0, lng: 0, at: '2026-01-01T10:01:00Z' }]);
    assert.equal(t.length, 2);
    assert.equal(t[0].lat, 25.1);
    const untimed = normaliseTrack([{ lat: 25, lng: 51 }, { lat: 25.1, lng: 51 }, { lat: 25.2, lng: 51 }]);
    assert.deepEqual(untimed.map((p) => p.t - untimed[0].t), [0, 5000, 10000]);
    assert.deepEqual(normaliseTrack(null), []);
});

test('indexAt and trackAt locate a vehicle on its recorded trip', () => {
    const pts = [{ lat: 25, lng: 51, t: 1000 }, { lat: 25.01, lng: 51, t: 2000 }, { lat: 25.02, lng: 51, t: 4000 }];
    assert.equal(indexAt(pts, 500), -1);
    assert.equal(indexAt(pts, 1000), 0);
    assert.equal(indexAt(pts, 3999), 1);
    assert.equal(indexAt(pts, 9999), 2);
    const before = trackAt(pts, 0);
    assert.equal(before.started, false);
    assert.equal(before.lat, 25);
    const mid = trackAt(pts, 3000);
    assert.ok(Math.abs(mid.lat - 25.015) < 1e-9);
    assert.ok(mid.started && !mid.finished);
    assert.ok(Math.abs(mid.heading) < 0.5 || mid.heading > 359.5);
    const end = trackAt(pts, 99999);
    assert.ok(end.finished);
    assert.equal(end.lat, 25.02);
    assert.equal(trackAt([], 1), null);
});

test('playbackRate: x1 plays a long day in about a minute, short trips are not slower than 5x', () => {
    assert.equal(playbackRate(6 * 3600_000, 1), 360);
    assert.equal(playbackRate(6 * 3600_000, 4), 1440);
    assert.equal(playbackRate(60_000, 1), 5);
    assert.equal(playbackRate(20 * 3600_000, 2), 1200);
    assert.equal(playbackRate(1e12, 1), 600);
});

/* ───────────── configuration and safe strings ───────────── */

test('normaliseConfig falls back to OpenStreetMap around Doha when MapConfig is missing or malformed', () => {
    for (const bad of [undefined, null, 'x', 7, {}, { tiles: 'x' }, { tiles: { light_url: 'javascript:alert(1)/{z}/{x}/{y}' }, center: { lat: 'a' } }]) {
        const c = normaliseConfig(bad);
        assert.match(c.tiles.lightUrl, /^https:\/\/tile\.openstreetmap\.org\/\{z\}\/\{x\}\/\{y\}\.png$/);
        assert.deepEqual(c.center, { lat: 25.2854, lng: 51.531 });
        assert.equal(c.zoom, 11);
        assert.equal(c.tiles.darkUrl, '');
        assert.match(c.tiles.attribution, /OpenStreetMap/);
        assert.equal(c.staleMs, 90_000);
    }
});

test('normaliseConfig reads the MapConfig payload', () => {
    const c = normaliseConfig({
        provider: 'carto',
        tiles: { light_url: 'https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png', dark_url: 'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', attribution: '&copy; <a href="https://carto.com/attributions">CARTO</a>', subdomains: 'abcd', max_zoom: 20, retina: true },
        center: { lat: 25.3, lng: 51.4 }, zoom: 12, stale_after_seconds: 120,
    });
    assert.equal(c.provider, 'carto');
    assert.equal(c.tiles.subdomains, 'abcd');
    assert.equal(c.tiles.maxZoom, 20);
    assert.equal(c.tiles.retina, true);
    assert.match(c.tiles.darkUrl, /dark_all/);
    assert.deepEqual(c.center, { lat: 25.3, lng: 51.4 });
    assert.equal(c.zoom, 12);
    assert.equal(c.staleMs, 120_000);
    const same = normaliseConfig({ tiles: { light_url: 'https://a/{z}/{x}/{y}.png', dark_url: 'https://a/{z}/{x}/{y}.png' } });
    assert.equal(same.tiles.darkUrl, '', 'identical dark URL means "no dark tiles"');
    assert.equal(normaliseConfig({ tiles: { light_url: 'https://{s}.x/{z}/{x}/{y}.png' } }).tiles.subdomains, 'abc');
    assert.equal(normaliseConfig({ tiles: { light_url: 'https://x/{z}/{x}/{y}.png', max_zoom: 99 } }).tiles.maxZoom, 22);
});

test('safeTileUrl accepts https and same-site templates only', () => {
    assert.equal(safeTileUrl('https://t.example/{z}/{x}/{y}.png'), 'https://t.example/{z}/{x}/{y}.png');
    assert.equal(safeTileUrl('/stub/{z}/{x}/{y}.png'), '/stub/{z}/{x}/{y}.png');
    assert.equal(safeTileUrl('https://t.example/{y}/{x}/{z}.png?key=1&r={r}'), 'https://t.example/{y}/{x}/{z}.png?key=1&r={r}');
    for (const bad of ['javascript:alert(1)', 'https://t.example/tiles.png', 'ftp://t/{z}/{x}/{y}', 'https://t/{z}/{x}/{y} onerror=1', '"https://t/{z}/{x}/{y}"', 5, null]) {
        assert.equal(safeTileUrl(bad, 'FB'), 'FB', String(bad));
    }
});

test('safeAttribution keeps text and http(s) links, drops everything else', () => {
    assert.equal(safeAttribution('&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'),
        '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">OpenStreetMap</a> contributors');
    assert.equal(safeAttribution('<img src=x onerror=alert(1)>hi <script>x</script>'), 'hi x');
    assert.equal(safeAttribution('<a href="javascript:alert(1)">x</a>'), 'x');
    assert.equal(safeAttribution('<a href="https://x.test/?a=1&b=2" onclick="y()">z</a>'), '<a href="https://x.test/?a=1&amp;b=2" target="_blank" rel="noopener noreferrer">z</a>');
    assert.equal(safeAttribution('Tom & Jerry <b>bold</b>'), 'Tom &amp; Jerry bold');
    assert.equal(safeAttribution('<a href="https://x.test/">unclosed'), '<a href="https://x.test/" target="_blank" rel="noopener noreferrer">unclosed</a>');
    assert.equal(safeAttribution('5 > 3 "q"'), '5 &gt; 3 &quot;q&quot;');
    assert.equal(safeAttribution(null), '');
});

test('phoneHref only ever builds a tel: link from a real number', () => {
    assert.equal(phoneHref('+974 5512 3456'), 'tel:+97455123456');
    assert.equal(phoneHref('55123456'), 'tel:55123456');
    assert.equal(phoneHref('javascript:alert(1)'), null);
    assert.equal(phoneHref('12'), null);
    assert.equal(phoneHref(null), null);
    assert.equal(phoneHref('+974++55123456'), 'tel:+97455123456');
});

test('names: first name for partners, initials for avatars', () => {
    assert.equal(firstName('Ravi Kumar'), 'Ravi');
    assert.equal(firstName('  Joseph  '), 'Joseph');
    assert.equal(firstName(null), '');
    assert.equal(initials('Ravi Kumar'), 'RK');
    assert.equal(initials('Joseph'), 'J');
    assert.equal(initials(''), '?');
});

test('a live frame carries the order number, vehicle and age like the REST rows (one parser for push and poll)', () => {
    const m = normaliseMove({ driver_id: 'd1', lat: 25.31, lng: 51.51, at: new Date(NOW).toISOString(), age_seconds: 4, order_id: 'o1', order_number: 'AB-9', vehicle: 'Van · 12', delivery_status: 'picked_up', accuracy_m: 7 }, { now: NOW });
    assert.equal(NOW - m.seenAt, 4000);
    assert.deepEqual([m.orderNumber, m.vehicle, m.accuracy], ['AB-9', 'Van · 12', 7]);
    const next = applyMove(drv({ fixMs: NOW - 9000 }), m);
    assert.deepEqual([next.job.orderNumber, next.job.status, next.vehicle], ['AB-9', 'picked_up', 'Van · 12']);
});

test('normaliseStops reads the server stops (ISO times, duration) and drops broken ones', () => {
    const s = normaliseStops([
        { lat: 25.3, lng: 51.5, from: '2026-01-01T10:00:00Z', to: '2026-01-01T10:04:00Z', duration_s: 240 },
        { lat: 25.31, lng: 51.51, to: '2026-01-01T11:00:00Z', duration_s: 180 },
        { lat: 0, lng: 0, from: '2026-01-01T10:00:00Z', to: '2026-01-01T10:04:00Z' },
        { lat: 25.3, lng: 51.5, from: '2026-01-01T10:05:00Z', to: '2026-01-01T10:04:00Z' },
        null,
    ]);
    assert.equal(s.length, 2);
    assert.equal(s[0].ms, 240_000);
    assert.equal(s[1].from, Date.parse('2026-01-01T11:00:00Z') - 180_000);
    assert.deepEqual(normaliseStops(undefined), []);
});
