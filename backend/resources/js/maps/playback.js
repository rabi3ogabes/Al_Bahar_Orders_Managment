// Route history playback for the partner page: the trips of one day (or of one order) drawn on the map and replayed on a shared
// timeline with play / pause, x1 x2 x4, a scrubber, start and end markers and stops with their durations. Read-only.

import { L, claimHost, createBaseMap } from './base.js';
import { DriverMarker, dotIcon, onActivate, paintDriver } from './markers.js';
import { clockText, durationText, h, icon, numberFormat } from './dom.js';
import { fmt } from './text.js';
import { detectStops, firstName, normaliseStops, normaliseTrack, pathLengthM, playbackRate, simplify, thin, trackAt } from './logic.js';

/** Leaflet rebuilds a divIcon element every time a marker is added, so the accessible name is set on each add. */
const labelOnAdd = (marker, label) => marker.on('add', () => marker.getElement()?.setAttribute('aria-label', label));

const PALETTE = ['#c7253f', '#b08d57', '#3b82b8', '#2f8f6f', '#7c5cbf', '#d1662e', '#2b8a9e', '#a23b8e'];
const SPEEDS = [1, 2, 4];

/**
 * @param {object} o
 * @param {HTMLElement} o.host
 * @param {object} o.config
 * @param {object} o.text
 * @param {'en'|'ar'} o.lang
 * @param {Array} o.tracks   [{ id, label, sub, points: [{lat, lng, at|t, speed}], stops?: [{lat, lng, from, to}] }] (first names only)
 * @param {boolean} o.loading
 */
export function createPlayback(o) {
    const { host, text, lang } = o;
    claimHost(host);
    const base = createBaseMap({ host, config: o.config, text, lang, kind: 'playback', cooperative: 'full', label: text.pb_title });
    const { map, ui } = base;
    const nf = numberFormat(lang);

    let tracks = [];
    let t0 = 0;
    let t1 = 0;
    let cur = 0;
    let playing = false;
    let factor = 1;
    let raf = 0;
    let last = 0;
    let destroyed = false;
    const layers = L.layerGroup().addTo(map);

    /* ───────────── Controls ───────────── */

    const playBtn = h('button', { type: 'button', class: 'abm-pb__play', on: { click: () => (playing ? pause() : play()) } }, icon('play', 20));
    const restartBtn = h('button', { type: 'button', class: 'abm-iconbtn', 'aria-label': text.pb_restart, title: text.pb_restart, on: { click: () => { seek(t0); } } }, icon('restart', 18));
    const speedBtns = SPEEDS.map((f) => h('button', { type: 'button', class: 'abm-seg__b', 'aria-pressed': String(f === 1), 'aria-label': `${text.pb_speed}: x${f}`, on: { click: () => setSpeed(f) } }, `x${nf.format(f)}`));
    const speeds = h('div', { class: 'abm-seg', role: 'group', 'aria-label': text.pb_speed, title: text.pb_rate }, speedBtns);
    const range = h('input', { type: 'range', class: 'abm-pb__range', min: '0', max: '1000', step: '1', value: '0', 'aria-label': text.pb_scrub });
    range.addEventListener('input', () => seek(t0 + (Number(range.value) / 1000) * (t1 - t0)));
    const clock = h('output', { class: 'abm-pb__clock' });
    const trips = h('div', { class: 'abm-pb__trips', role: 'group', 'aria-label': text.pb_trips });
    const bar = h('div', { class: 'abm-pb', hidden: true },
        h('div', { class: 'abm-pb__row' }, playBtn, restartBtn, speeds, h('div', { class: 'abm-pb__scrub' }, range), clock),
        trips);

    const emptyTitle = h('strong', { class: 'abm-empty__t' }, text.pb_no_routes);
    const empty = h('div', { class: 'abm-empty', hidden: true, role: 'status' },
        h('span', { class: 'abm-empty__i', 'aria-hidden': 'true' }, icon('route', 22)),
        h('div', {}, emptyTitle, h('span', { class: 'abm-empty__p' }, text.pb_no_routes_hint)));
    const skeleton = h('div', { class: 'abm-skel', role: 'status', 'aria-label': text.pb_loading, hidden: !o.loading },
        h('span', { class: 'abm-skel__bar' }), h('span', { class: 'abm-skel__bar abm-skel__bar--s' }));
    ui.append(bar, empty, skeleton);
    base.addTool({ name: 'fit', label: text.fit_all, onClick: () => fit() });
    base.addFullscreenTool();

    /* ───────────── Tracks ───────────── */

    function buildTrack(raw, i) {
        const points = normaliseTrack(raw.points);
        if (points.length < 1) return null;
        const color = raw.color ?? PALETTE[i % PALETTE.length];
        const line = simplify(points, 4);
        const stops = raw.stops ? normaliseStops(raw.stops) : detectStops(points);
        const full = L.polyline(line.map((p) => [p.lat, p.lng]), { color, weight: 4, opacity: 0.32, lineCap: 'round', interactive: false });
        const done = L.polyline([], { color, weight: 5.5, opacity: 0.95, lineCap: 'round', interactive: false });
        const first = points[0];
        const lastP = points[points.length - 1];
        const startM = L.marker([first.lat, first.lng], { icon: dotIcon('start', { name: 'play' }), keyboard: false }).bindTooltip(`${text.start} · ${clockText(first.t, lang)}`, { direction: 'top', offset: [0, -4], className: 'abm-tip', opacity: 1 });
        const endM = L.marker([lastP.lat, lastP.lng], { icon: dotIcon('end', { name: 'flag' }), keyboard: false }).bindTooltip(`${text.end} · ${clockText(lastP.t, lang)}`, { direction: 'top', offset: [0, -4], className: 'abm-tip', opacity: 1 });
        labelOnAdd(startM, text.start);
        labelOnAdd(endM, text.end);
        const stopMs = stops.map((s, k) => {
            const m = L.marker([s.lat, s.lng], { icon: dotIcon('stop', String(k + 1)), keyboard: true, riseOnHover: true });
            m.bindTooltip(h('div', { class: 'abm-tip__b' }, h('strong', {}, `${fmt(text.stop_n, { n: nf.format(k + 1) })} · ${durationText(s.ms, text, lang)}`), h('span', {}, `${clockText(s.from, lang)} – ${clockText(s.to, lang)}`)), { direction: 'top', offset: [0, -4], className: 'abm-tip', opacity: 1 });
            onActivate(m, () => seek(s.from));
            labelOnAdd(m, `${fmt(text.stop_n, { n: nf.format(k + 1) })}, ${durationText(s.ms, text, lang)}`);
            return m;
        });
        const dm = new DriverMarker({ id: String(raw.id ?? i), lat: first.lat, lng: first.lng, heading: null }, { tooltip: () => raw.label ?? '' });
        return {
            id: String(raw.id ?? i), label: String(raw.label ?? ''), sub: String(raw.sub ?? ''), color, points, latlngs: points.map((p) => [p.lat, p.lng]), stops,
            full, done, startM, endM, stopMs, dm, visible: true, distanceM: pathLengthM(points),
        };
    }

    function clearLayers() {
        layers.clearLayers();
        tracks.forEach((t) => t.dm.marker.remove());
    }

    function setTracks(list) {
        pause();
        clearLayers();
        tracks = (list ?? []).map(buildTrack).filter(Boolean);
        skeleton.hidden = true;
        const has = tracks.length > 0;
        empty.hidden = has;
        bar.hidden = !has;
        trips.replaceChildren();
        if (!has) {
            t0 = 0;
            t1 = 0;
            cur = 0;
            return;
        }
        t0 = Math.min(...tracks.map((t) => t.points[0].t));
        t1 = Math.max(...tracks.map((t) => t.points[t.points.length - 1].t));
        if (t1 <= t0) t1 = t0 + 60_000;
        cur = t0;
        tracks.forEach((t, i) => {
            const chip = h('button', { type: 'button', class: 'abm-chip', 'aria-pressed': 'true', title: text.pb_trip_toggle, 'aria-label': `${t.label}. ${text.pb_trip_toggle}`, on: { click: () => toggleTrack(t, chip) } },
                h('span', { class: 'abm-chip__dot' }),
                h('span', { class: 'abm-chip__t' }, t.label),
                h('span', { class: 'abm-chip__m' }, [t.sub, fmt(text.pb_km, { n: nf.format(Math.round(t.distanceM / 100) / 10) }), t.stops.length ? `${text.pb_stops} ${nf.format(t.stops.length)}` : ''].filter(Boolean).join(' · ')));
            chip.firstElementChild.style.setProperty('background', t.color);
            t.chip = chip;
            trips.append(chip);
            attachTrack(t);
        });
        fit();
        render();
    }

    function attachTrack(t) {
        [t.full, t.done, t.startM, t.endM, ...t.stopMs].forEach((l) => layers.addLayer(l));
        layers.addLayer(t.dm.marker);
        t.dm.el.dataset.status = 'out';
    }

    function detachTrack(t) {
        [t.full, t.done, t.startM, t.endM, ...t.stopMs].forEach((l) => layers.removeLayer(l));
        layers.removeLayer(t.dm.marker);
    }

    function toggleTrack(t, chip) {
        t.visible = !t.visible;
        chip.setAttribute('aria-pressed', String(t.visible));
        if (t.visible) attachTrack(t);
        else detachTrack(t);
        render();
    }

    function fit() {
        const pts = tracks.filter((t) => t.visible).flatMap((t) => t.points.map((p) => ({ lat: p.lat, lng: p.lng })));
        base.fit(thin(pts, 300), { maxZoom: 16, padding: [48, 48], paddingBottomRight: [48, 200] });
    }

    /* ───────────── Timeline ───────────── */

    function render() {
        if (!tracks.length) return;
        for (const t of tracks) {
            if (!t.visible) continue;
            const at = trackAt(t.points, cur);
            if (!at.started) {
                t.dm.marker.getElement()?.style.setProperty('display', 'none');
                t.done.setLatLngs([]);
                continue;
            }
            t.dm.marker.getElement()?.style.removeProperty('display');
            t.dm.apply({ lat: at.lat, lng: at.lng, heading: at.heading });
            t.done.setLatLngs([...t.latlngs.slice(0, Math.max(0, at.index + 1)), [at.lat, at.lng]]);
            paintDriver(t.dm.el, {
                status: 'out', stale: at.finished, moving: playing && !at.finished, selected: false, highlighted: false, heading: at.heading ?? undefined,
                tag: firstName(t.label), label: `${t.label}, ${clockText(cur, lang)}`,
            });
        }
        const frac = t1 === t0 ? 0 : (cur - t0) / (t1 - t0);
        range.value = String(Math.round(frac * 1000));
        range.setAttribute('aria-valuetext', clockText(cur, lang));
        clock.textContent = `${clockText(cur, lang)} / ${clockText(t1, lang)}`;
    }

    function seek(ms) {
        cur = Math.min(t1, Math.max(t0, ms));
        render();
    }

    function frame(now) {
        if (!playing || destroyed) return;
        const dt = Math.min(100, now - last);
        last = now;
        cur += dt * playbackRate(t1 - t0, factor);
        if (cur >= t1) {
            cur = t1;
            render();
            pause();
            base.toast.show(text.pb_all_done, 2500);
            return;
        }
        render();
        raf = requestAnimationFrame(frame);
    }

    function play() {
        if (!tracks.length || playing) return;
        if (cur >= t1) cur = t0;
        playing = true;
        last = performance.now();
        setPlayIcon();
        raf = requestAnimationFrame(frame);
    }

    function pause() {
        playing = false;
        cancelAnimationFrame(raf);
        setPlayIcon();
        if (tracks.length) render();
    }

    function setPlayIcon() {
        playBtn.replaceChildren(icon(playing ? 'pause' : 'play', 20));
        const label = playing ? text.pb_pause : text.pb_play;
        playBtn.setAttribute('aria-label', label);
        playBtn.title = label;
        playBtn.setAttribute('aria-pressed', String(playing));
    }

    function setSpeed(f) {
        factor = f;
        speedBtns.forEach((b, i) => b.setAttribute('aria-pressed', String(SPEEDS[i] === f)));
    }

    base.onCleanup(() => cancelAnimationFrame(raf));
    setPlayIcon();
    if (o.tracks) setTracks(o.tracks);

    const controller = {
        setTracks,
        setLoading(on) {
            skeleton.hidden = !on;
            if (on) {
                pause();
                empty.hidden = true;
            }
        },
        play,
        pause,
        seek,
        fit,
        get playing() { return playing; },
        get map() { return map; },
        destroy() {
            if (destroyed) return;
            destroyed = true;
            cancelAnimationFrame(raf);
            base.destroy();
            claimHost(host, null);
        },
    };
    claimHost(host, controller);
    return controller;
}
