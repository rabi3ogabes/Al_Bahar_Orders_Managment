// Pure logic of the web maps (no DOM, no Leaflet, no timers) so it can be unit tested with `npm test`.
// Everything the fleet map, the partner map and the route cards decide lives here: reading driver payloads, stale / moving
// detection, the out-of-order guard, heading interpolation, grid clustering, polyline thinning, stop detection and playback.

export const STALE_MS = 90_000;
export const MOVING_KMH = 2;
export const MOVED_RECENT_MS = 20_000;
export const MOVED_MIN_M = 10;
export const SNAP_DISTANCE_M = 1500;
export const TRAIL_POINTS = 40;
export const DEFAULT_CENTER = Object.freeze({ lat: 25.2854, lng: 51.531 });
export const OSM_URL = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';
export const OSM_ATTRIBUTION = '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors';

const EARTH_M = 6371008.8;
const rad = (d) => (d * Math.PI) / 180;

/** A finite number, or null for anything else (empty strings, NaN, null, objects). */
export const num = (v) => {
    if (v === null || v === undefined || v === '' || typeof v === 'boolean') return null;
    const n = typeof v === 'number' ? v : Number(v);
    return Number.isFinite(n) ? n : null;
};

/** ISO string, epoch ms or epoch seconds to epoch ms; null when it cannot be read. */
export const toMs = (v) => {
    if (v === null || v === undefined || v === '') return null;
    if (typeof v === 'number') return Number.isFinite(v) ? (v < 1e11 ? v * 1000 : v) : null;
    const t = Date.parse(String(v));
    return Number.isNaN(t) ? null : t;
};

export const clamp = (v, lo, hi) => Math.min(hi, Math.max(lo, v));

export const validPoint = (lat, lng) => lat !== null && lng !== null && Math.abs(lat) <= 90 && Math.abs(lng) <= 180 && !(lat === 0 && lng === 0);

/* ───────────── Geometry ───────────── */

export function haversine(a, b) {
    const dLat = rad(b.lat - a.lat);
    const dLng = rad(b.lng - a.lng);
    const h = Math.sin(dLat / 2) ** 2 + Math.cos(rad(a.lat)) * Math.cos(rad(b.lat)) * Math.sin(dLng / 2) ** 2;
    return 2 * EARTH_M * Math.asin(Math.min(1, Math.sqrt(h)));
}

/** Initial bearing from a to b in degrees, 0 = north, clockwise. */
export function bearing(a, b) {
    const y = Math.sin(rad(b.lng - a.lng)) * Math.cos(rad(b.lat));
    const x = Math.cos(rad(a.lat)) * Math.sin(rad(b.lat)) - Math.sin(rad(a.lat)) * Math.cos(rad(b.lat)) * Math.cos(rad(b.lng - a.lng));
    return normAngle((Math.atan2(y, x) * 180) / Math.PI);
}

export const normAngle = (a) => ((a % 360) + 360) % 360;

/** Signed shortest rotation from one heading to another, in (-180, 180]. */
export function shortestDelta(from, to) {
    const d = normAngle(to - from);
    return d > 180 ? d - 360 : d;
}

export const lerpAngle = (from, to, t) => normAngle(from + shortestDelta(from, to) * t);

export const easeInOut = (t) => 0.5 - Math.cos(Math.PI * clamp(t, 0, 1)) / 2;

export function interpolate(a, b, t) {
    const heading = a.heading === null || a.heading === undefined ? (b.heading ?? null) : b.heading === null || b.heading === undefined ? a.heading : lerpAngle(a.heading, b.heading, t);
    return { lat: a.lat + (b.lat - a.lat) * t, lng: a.lng + (b.lng - a.lng) * t, heading };
}

/** A glide between two positions, sampled by time. Time is passed in, so it is deterministic in tests. */
export class Tween {
    constructor(at) {
        this.from = { ...at };
        this.to = { ...at };
        this.start = 0;
        this.dur = 0;
    }

    /** Starts a new glide from where the marker is right now, so a new fix never makes it jump. */
    retarget(current, to, now, dur) {
        this.from = { ...current };
        this.to = { ...to };
        this.start = now;
        this.dur = Math.max(0, dur);
    }

    at(now) {
        if (this.dur <= 0) return { ...this.to, done: true };
        const p = clamp((now - this.start) / this.dur, 0, 1);
        return { ...interpolate(this.from, this.to, easeInOut(p)), done: p >= 1 };
    }
}

/** How long the next glide lasts. Reduced motion and big jumps (a GPS gap, a first fix) snap; tiny moves just set. */
export function glideDuration(distanceM, { reduced = false, base = 1000 } = {}) {
    if (reduced || distanceM > SNAP_DISTANCE_M || distanceM < 1.5) return 0;
    return base;
}

/* ───────────── Driver payloads ───────────── */

const JOB_OUT = new Set(['picked_up', 'out_for_delivery']);

function readJob(raw) {
    const j = raw.job;
    if (!j && !raw.order_number && !raw.active_order) return null;
    const src = j ?? raw.active_order ?? {};
    const status = src.status ?? raw.delivery_status ?? null;
    const dest = src.destination ?? raw.destination ?? null;
    const dlat = num(dest?.lat ?? dest?.latitude);
    const dlng = num(dest?.lng ?? dest?.longitude);
    return {
        deliveryId: src.delivery_id ?? null,
        orderId: src.order_id ?? raw.order_id ?? null,
        orderNumber: src.order_number ?? src.number ?? raw.order_number ?? null,
        status: status === null ? null : String(status),
        destination: validPoint(dlat, dlng) ? { lat: dlat, lng: dlng } : null,
        etaMinutes: num(src.eta_minutes ?? raw.eta_minutes),
        etaAt: toMs(src.eta_at ?? raw.eta_at),
    };
}

function readTrail(raw) {
    const t = raw.trail;
    if (!Array.isArray(t)) return [];
    return t.map((p) => (Array.isArray(p) ? { lat: num(p[0]), lng: num(p[1]) } : { lat: num(p?.lat), lng: num(p?.lng) })).filter((p) => validPoint(p.lat, p.lng));
}

/**
 * A full driver row (Livewire / partner fleet payload) to the shape the maps use.
 * `serverNow` (ms) lets the age be worked out without trusting the viewer's clock when the row has no `age_seconds`.
 */
export function normaliseDriver(raw, { now = Date.now(), serverNow = null } = {}) {
    if (!raw || typeof raw !== 'object') return null;
    const id = raw.id ?? raw.driver_id;
    if (id === null || id === undefined || id === '') return null;
    const lat = num(raw.lat ?? raw.latitude);
    const lng = num(raw.lng ?? raw.longitude);
    const hasPos = validPoint(lat, lng);
    const fixMs = toMs(raw.at ?? raw.last_seen_at ?? raw.seen_at);
    const age = num(raw.age_seconds);
    const ageMs = age !== null ? Math.max(0, age * 1000) : fixMs !== null ? Math.max(0, (serverNow ?? now) - fixMs) : null;
    const job = readJob(raw);
    const heading = num(raw.heading);
    const phone = typeof raw.phone === 'string' ? raw.phone : null;
    return {
        id: String(id),
        name: String(raw.name ?? '').slice(0, 80),
        phone,
        vehicle: raw.vehicle ? String(typeof raw.vehicle === 'object' ? (raw.vehicle.label ?? raw.vehicle.plate ?? raw.vehicle.type ?? '') : raw.vehicle).slice(0, 60) || null : null,
        onDuty: raw.on_duty === undefined ? true : Boolean(raw.on_duty),
        lat: hasPos ? lat : null,
        lng: hasPos ? lng : null,
        heading: heading === null ? null : normAngle(heading),
        speed: num(raw.speed_kmh ?? raw.speed),
        accuracy: num(raw.accuracy_m ?? raw.accuracy),
        fixMs,
        seenAt: ageMs === null ? null : now - ageMs,
        movedAt: null,
        branches: Array.isArray(raw.branches) ? raw.branches.map((b) => String(b)).slice(0, 8) : [],
        openJobs: Math.max(0, Math.trunc(num(raw.open_jobs) ?? (job ? 1 : 0))),
        job,
        trail: readTrail(raw),
    };
}

/** A live "driver.moved" frame (partial: a position and a little context). Null when it is not usable. */
export function normaliseMove(raw, { now = Date.now() } = {}) {
    if (!raw || typeof raw !== 'object') return null;
    const id = raw.driver_id ?? raw.id;
    const lat = num(raw.lat);
    const lng = num(raw.lng);
    if (id === null || id === undefined || id === '' || !validPoint(lat, lng)) return null;
    const heading = num(raw.heading);
    return {
        id: String(id),
        name: raw.name ? String(raw.name).slice(0, 80) : null,
        lat,
        lng,
        heading: heading === null ? null : normAngle(heading),
        speed: num(raw.speed_kmh ?? raw.speed),
        accuracy: num(raw.accuracy_m ?? raw.accuracy),
        fixMs: toMs(raw.at) ?? now,
        seenAt: now,
        orderId: raw.order_id ?? null,
        deliveryStatus: raw.delivery_status ? String(raw.delivery_status) : null,
    };
}

const moved = (a, b) => a.lat !== null && b.lat !== null && haversine(a, b) >= MOVED_MIN_M;

/** Appends a point to a bounded trail unless it is (almost) where the trail already ends. */
export function pushTrail(trail, p, max = TRAIL_POINTS) {
    if (p.lat === null || p.lng === null) return trail;
    const last = trail[trail.length - 1];
    if (last && haversine(last, p) < 3) return trail;
    const next = trail.length >= max ? trail.slice(trail.length - max + 1) : trail.slice();
    next.push({ lat: p.lat, lng: p.lng });
    return next;
}

/**
 * Merges a partial live move into the driver we already hold. Returns null when the frame is older than the position we
 * show (out-of-order delivery, or a slow poll overtaking the socket), so a late frame can never pull a marker backwards.
 */
export function applyMove(prev, move) {
    if (!prev) return null;
    if (prev.fixMs !== null && move.fixMs < prev.fixMs) return null;
    const hasMoved = moved(prev, move);
    const heading = move.heading ?? (hasMoved ? bearing(prev, move) : prev.heading);
    const job = prev.job || move.deliveryStatus || move.orderId
        ? { ...(prev.job ?? { deliveryId: null, orderId: null, orderNumber: null, status: null, destination: null, etaMinutes: null, etaAt: null }), status: move.deliveryStatus ?? prev.job?.status ?? null, orderId: move.orderId ?? prev.job?.orderId ?? null }
        : prev.job;
    return {
        ...prev,
        name: prev.name || move.name || '',
        lat: move.lat,
        lng: move.lng,
        heading,
        speed: move.speed,
        accuracy: move.accuracy ?? prev.accuracy,
        fixMs: move.fixMs,
        seenAt: move.seenAt,
        movedAt: hasMoved ? move.seenAt : prev.movedAt,
        onDuty: true,
        job,
        trail: pushTrail(prev.trail, move),
    };
}

/**
 * Merges a full row into what we hold. The row is the truth for duty, jobs, names and branches; the position only wins when it is at
 * least as new as ours (a row fetched a moment before a live frame arrived must not rewind the marker).
 */
export function applyFull(prev, next) {
    if (!prev) return { ...next, trail: next.trail.length ? next.trail : next.lat === null ? [] : [{ lat: next.lat, lng: next.lng }] };
    const keepPos = next.lat === null || (prev.lat !== null && prev.fixMs !== null && (next.fixMs === null || next.fixMs < prev.fixMs));
    if (keepPos) {
        return { ...next, lat: prev.lat, lng: prev.lng, heading: prev.heading, speed: prev.speed, accuracy: prev.accuracy, fixMs: prev.fixMs, seenAt: prev.seenAt, movedAt: prev.movedAt, trail: prev.trail };
    }
    const hasMoved = moved(prev, next);
    let trail = next.trail.length > prev.trail.length ? next.trail : prev.trail;
    trail = pushTrail(trail, next);
    return { ...next, heading: next.heading ?? (hasMoved ? bearing(prev, next) : prev.heading), movedAt: hasMoved ? (next.seenAt ?? Date.now()) : prev.movedAt, trail };
}

export function isStale(d, now = Date.now(), staleMs = STALE_MS) {
    return d.seenAt === null || now - d.seenAt > staleMs;
}

export function isMoving(d, now = Date.now(), staleMs = STALE_MS) {
    if (!d.onDuty || d.lat === null || isStale(d, now, staleMs)) return false;
    if (d.speed !== null && d.speed >= MOVING_KMH) return true;
    return d.movedAt !== null && now - d.movedAt < MOVED_RECENT_MS;
}

/** offline (off duty) | idle (free) | assigned (job not picked up yet) | out (carrying the order). */
export function statusOf(d) {
    if (!d.onDuty) return 'offline';
    if (d.job?.status && JOB_OUT.has(d.job.status)) return 'out';
    if (d.job || d.openJobs > 0) return 'assigned';
    return 'idle';
}

export function counts(drivers, now = Date.now(), staleMs = STALE_MS) {
    let onDuty = 0;
    let movingN = 0;
    let idle = 0;
    let stale = 0;
    for (const d of drivers) {
        if (!d.onDuty) continue;
        onDuty += 1;
        if (isStale(d, now, staleMs)) stale += 1;
        else if (isMoving(d, now, staleMs)) movingN += 1;
        if (statusOf(d) === 'idle') idle += 1;
    }
    return { onDuty, moving: movingN, idle, stale, total: drivers.length };
}

const fold = (s) => String(s ?? '').toLowerCase().normalize('NFKD').replace(/[ً-ٰٟ̀-ͯ]/g, '').trim();

/** Search text (name, order number, branch) and a status filter: all | out | assigned | idle | moving | stale | offline. */
export function filterDrivers(list, { query = '', status = 'all' } = {}, now = Date.now(), staleMs = STALE_MS) {
    const q = fold(query);
    return list.filter((d) => {
        if (q) {
            const hay = fold([d.name, d.job?.orderNumber, d.vehicle, ...d.branches].join(' '));
            if (!hay.includes(q)) return false;
        }
        switch (status) {
            case 'all': return true;
            case 'moving': return isMoving(d, now, staleMs);
            case 'stale': return d.onDuty && isStale(d, now, staleMs);
            default: return statusOf(d) === status;
        }
    });
}

const RANK = { out: 0, assigned: 1, idle: 2, offline: 3 };

/** List order: carrying an order, assigned, free, off duty; then name. Stale drivers drop below fresh ones of the same group. */
export function sortDrivers(list, now = Date.now(), staleMs = STALE_MS) {
    return list.slice().sort((a, b) => RANK[statusOf(a)] - RANK[statusOf(b)] || Number(isStale(a, now, staleMs)) - Number(isStale(b, now, staleMs)) || a.name.localeCompare(b.name));
}

/** The age of a fix as a unit and a count, for "just now / 40 s / 3 min / 2 h / 1 d ago". */
export function ageParts(ms) {
    if (ms === null || ms === undefined || !Number.isFinite(ms)) return { unit: 'none', n: 0 };
    const s = Math.max(0, Math.round(ms / 1000));
    if (s < 5) return { unit: 'now', n: 0 };
    if (s < 60) return { unit: 'second', n: s };
    if (s < 3600) return { unit: 'minute', n: Math.round(s / 60) };
    if (s < 86400) return { unit: 'hour', n: Math.round(s / 3600) };
    return { unit: 'day', n: Math.round(s / 86400) };
}

/* ───────────── Clustering ───────────── */

const STATUS_PRIORITY = ['out', 'assigned', 'idle', 'offline'];

/**
 * Groups points that share a screen cell. `project(lat, lng)` returns pixel coordinates at the current zoom; `pinned` ids (the selected
 * driver) are never absorbed into a cluster.
 */
export function gridCluster(items, project, cell = 64, pinned = new Set()) {
    const cells = new Map();
    const singles = [];
    for (const it of items) {
        if (pinned.has(it.id)) {
            singles.push(it.id);
            continue;
        }
        const p = project(it.lat, it.lng);
        const key = `${Math.floor(p.x / cell)}:${Math.floor(p.y / cell)}`;
        const bucket = cells.get(key);
        if (bucket) bucket.push(it);
        else cells.set(key, [it]);
    }
    const clusters = [];
    for (const [key, members] of cells) {
        if (members.length === 1) {
            singles.push(members[0].id);
            continue;
        }
        const tally = {};
        members.forEach((m) => { tally[m.status] = (tally[m.status] ?? 0) + 1; });
        const status = STATUS_PRIORITY.slice().sort((a, b) => (tally[b] ?? 0) - (tally[a] ?? 0) || STATUS_PRIORITY.indexOf(a) - STATUS_PRIORITY.indexOf(b))[0];
        clusters.push({
            key,
            ids: members.map((m) => m.id),
            count: members.length,
            lat: members.reduce((s, m) => s + m.lat, 0) / members.length,
            lng: members.reduce((s, m) => s + m.lng, 0) / members.length,
            status,
        });
    }
    return { clusters, singles };
}

/* ───────────── Routes ───────────── */

/** Douglas-Peucker on a local flat projection. Keeps the first and last point; objects are returned untouched. */
export function simplify(points, toleranceM = 8) {
    const n = points.length;
    if (n <= 2 || toleranceM <= 0) return points.slice();
    const ky = 110540;
    const kx = 111320 * Math.cos(rad(points[0].lat));
    const xs = points.map((p) => p.lng * kx);
    const ys = points.map((p) => p.lat * ky);
    const keep = new Uint8Array(n);
    keep[0] = 1;
    keep[n - 1] = 1;
    const stack = [[0, n - 1]];
    while (stack.length) {
        const [a, b] = stack.pop();
        const dx = xs[b] - xs[a];
        const dy = ys[b] - ys[a];
        const len2 = dx * dx + dy * dy;
        let worst = 0;
        let at = -1;
        for (let i = a + 1; i < b; i += 1) {
            let t = len2 === 0 ? 0 : ((xs[i] - xs[a]) * dx + (ys[i] - ys[a]) * dy) / len2;
            t = clamp(t, 0, 1);
            const d = Math.hypot(xs[i] - (xs[a] + t * dx), ys[i] - (ys[a] + t * dy));
            if (d > worst) {
                worst = d;
                at = i;
            }
        }
        if (worst > toleranceM && at > 0) {
            keep[at] = 1;
            stack.push([a, at], [at, b]);
        }
    }
    return points.filter((_, i) => keep[i] === 1);
}

/** At most `max` points, evenly spread, always keeping the last one. */
export function thin(points, max) {
    if (points.length <= max || max < 2) return points.slice();
    const step = (points.length - 1) / (max - 1);
    return Array.from({ length: max }, (_, i) => points[Math.round(i * step)]);
}

/** Places where a vehicle stayed within `radiusM` for at least `minMs`. Points need `lat`, `lng` and `t` (ms). */
export function detectStops(points, { radiusM = 35, minMs = 120_000 } = {}) {
    const stops = [];
    let i = 0;
    while (i < points.length) {
        let j = i + 1;
        while (j < points.length && haversine(points[i], points[j]) <= radiusM) j += 1;
        const last = points[j - 1];
        if (j - i >= 2 && last.t - points[i].t >= minMs) {
            const run = points.slice(i, j);
            stops.push({
                lat: run.reduce((s, p) => s + p.lat, 0) / run.length,
                lng: run.reduce((s, p) => s + p.lng, 0) / run.length,
                from: points[i].t,
                to: last.t,
                ms: last.t - points[i].t,
            });
            i = j;
        } else {
            i += 1;
        }
    }
    return stops;
}

/** Reads a route payload ([{lat, lng, at|t, speed}], oldest first) into timed points; unusable points are dropped. */
export function normaliseTrack(points) {
    if (!Array.isArray(points)) return [];
    const out = [];
    for (const p of points) {
        const lat = num(p?.lat ?? p?.[0]);
        const lng = num(p?.lng ?? p?.[1]);
        const t = toMs(p?.t ?? p?.at ?? p?.recorded_at ?? p?.[2]);
        if (!validPoint(lat, lng)) continue;
        out.push({ lat, lng, t, speed: num(p?.speed ?? p?.speed_kmh) });
    }
    // Points without a time get evenly spaced pseudo times so playback still runs.
    if (out.length && out.some((p) => p.t === null)) {
        const known = out.filter((p) => p.t !== null);
        const base = known.length ? known[0].t : 0;
        out.forEach((p, i) => { p.t ??= base + i * 5000; });
    }
    return out.sort((a, b) => a.t - b.t);
}

/** Index of the last point at or before t (binary search); -1 before the first point. */
export function indexAt(points, t) {
    let lo = 0;
    let hi = points.length - 1;
    let ans = -1;
    while (lo <= hi) {
        const mid = (lo + hi) >> 1;
        if (points[mid].t <= t) {
            ans = mid;
            lo = mid + 1;
        } else {
            hi = mid - 1;
        }
    }
    return ans;
}

/** Where a recorded vehicle is at time t. `started` / `finished` tell whether t is inside the recorded trip. */
export function trackAt(points, t) {
    if (!points.length) return null;
    const i = indexAt(points, t);
    if (i < 0) return { ...points[0], heading: headingAfter(points, 0), index: -1, started: false, finished: false };
    if (i >= points.length - 1) return { ...points[points.length - 1], heading: headingAfter(points, points.length - 1), index: points.length - 1, started: true, finished: true };
    const a = points[i];
    const b = points[i + 1];
    const f = b.t === a.t ? 0 : (t - a.t) / (b.t - a.t);
    const hd = haversine(a, b) > 2 ? bearing(a, b) : headingAfter(points, i);
    return { lat: a.lat + (b.lat - a.lat) * f, lng: a.lng + (b.lng - a.lng) * f, t, speed: a.speed, heading: hd, index: i, started: true, finished: false };
}

function headingAfter(points, i) {
    for (let k = Math.max(0, i); k < points.length - 1; k += 1) if (haversine(points[k], points[k + 1]) > 2) return bearing(points[k], points[k + 1]);
    for (let k = Math.min(i, points.length - 1); k > 0; k -= 1) if (haversine(points[k - 1], points[k]) > 2) return bearing(points[k - 1], points[k]);
    return null;
}

/** Playback speed: x1 plays the whole timeline in about a minute (never slower than 5x real time), x2 and x4 are multiples of that. */
export function playbackRate(durationMs, factor = 1) {
    const base = clamp(durationMs / 60_000, 5, 600);
    return base * factor;
}

export function pathLengthM(points) {
    let m = 0;
    for (let i = 1; i < points.length; i += 1) m += haversine(points[i - 1], points[i]);
    return m;
}

/* ───────────── Map configuration and safe strings ───────────── */

/** A tile URL template: http(s) or same-site path, no spaces, quotes or tags, with {z}, {x} and {y}. */
export function safeTileUrl(v, fallback = '') {
    if (typeof v !== 'string') return fallback;
    const u = v.trim();
    return /^(https?:\/\/|\/)[^\s"'<>]+$/.test(u) && ['{z}', '{x}', '{y}'].every((k) => u.includes(k)) ? u : fallback;
}

/**
 * Attribution is shown as HTML by the map control, so only plain text and <a href="http(s)://..."> links survive; everything
 * else is dropped and text is escaped. Entities such as &copy; are kept.
 */
export function safeAttribution(html) {
    const src = typeof html === 'string' ? html.slice(0, 600) : '';
    const out = [];
    let depth = 0;
    for (const part of src.split(/(<[^>]*>)/g)) {
        if (part === '') continue;
        if (part.startsWith('<')) {
            const open = part.match(/^<a\s[^>]*href\s*=\s*["'](https?:\/\/[^"'\s<>]+)["'][^>]*>$/i);
            if (open && depth < 1) {
                out.push(`<a href="${open[1].replace(/&/g, '&amp;')}" target="_blank" rel="noopener noreferrer">`);
                depth += 1;
            } else if (/^<\/a\s*>$/i.test(part) && depth > 0) {
                out.push('</a>');
                depth -= 1;
            }
            continue;
        }
        out.push(part.replace(/&(?!(?:[a-z]{2,8}|#\d{1,6}|#x[\da-f]{1,5});)/gi, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'));
    }
    while (depth > 0) {
        out.push('</a>');
        depth -= 1;
    }
    return out.join('');
}

/** MapConfig::payload() read defensively: any missing or malformed part falls back to OpenStreetMap around Doha. */
export function normaliseConfig(raw) {
    const t = raw && typeof raw === 'object' && raw.tiles && typeof raw.tiles === 'object' ? raw.tiles : {};
    const lightUrl = safeTileUrl(t.light_url ?? t.url, OSM_URL);
    const darkUrl = safeTileUrl(t.dark_url, '');
    const lat = num(raw?.center?.lat);
    const lng = num(raw?.center?.lng);
    const subs = typeof t.subdomains === 'string' ? t.subdomains : Array.isArray(t.subdomains) ? t.subdomains.join('') : '';
    return {
        provider: typeof raw?.provider === 'string' ? raw.provider : 'osm',
        tiles: {
            lightUrl,
            darkUrl: darkUrl && darkUrl !== lightUrl ? darkUrl : '',
            attribution: safeAttribution(typeof t.attribution === 'string' && t.attribution.trim() ? t.attribution : OSM_ATTRIBUTION),
            subdomains: /^[a-z0-9]+$/i.test(subs) ? subs : (lightUrl + darkUrl).includes('{s}') ? 'abc' : '',
            maxZoom: clamp(Math.trunc(num(t.max_zoom) ?? 19), 3, 22),
            retina: t.retina === true || t.retina === 1 || t.retina === '1',
        },
        center: validPoint(lat, lng) ? { lat, lng } : { ...DEFAULT_CENTER },
        zoom: clamp(Math.trunc(num(raw?.zoom) ?? 11), 2, 18),
        staleMs: clamp(Math.round((num(raw?.stale_after_seconds) ?? STALE_MS / 1000) * 1000), 30_000, 900_000),
    };
}

/** tel: href that cannot carry anything but a phone number. */
export function phoneHref(phone) {
    const digits = String(phone ?? '').replace(/[^\d+]/g, '').replace(/(?!^)\+/g, '');
    return digits.replace(/\D/g, '').length >= 5 ? `tel:${digits}` : null;
}

/** First name only (the partner page never shows more). */
export const firstName = (name) => String(name ?? '').trim().split(/\s+/)[0] ?? '';

/** "AB" from "Ravi Kumar" for the avatar bubble. */
export function initials(name) {
    const parts = String(name ?? '').trim().split(/\s+/).filter(Boolean);
    if (!parts.length) return '?';
    return (parts[0][0] + (parts.length > 1 ? parts[parts.length - 1][0] : '')).toUpperCase();
}
