// A compact map card for one delivery: the recorded route, where it started (branch), the driver's last position and the customer's
// destination, with the estimated arrival when the server has one. Used on the admin order page and in the partner order drawer.

import { L, claimHost, createBaseMap } from './base.js';
import { DriverMarker, dotIcon, pinIcon, paintDriver } from './markers.js';
import { agoText, clockText, h, icon, numberFormat } from './dom.js';
import { fmt } from './text.js';
import { detectStops, firstName, normaliseDriver, normaliseTrack, simplify, validPoint, num } from './logic.js';

const LINE_COLORS = ['#8a1538', '#3b82b8', '#2f8f6f', '#7c5cbf'];

/**
 * @param {object} o
 * @param {HTMLElement} o.host
 * @param {object} o.config
 * @param {object} o.text
 * @param {'en'|'ar'} o.lang
 * @param {Array<{points: Array, label?: string}>} o.routes
 * @param {{lat: number, lng: number}|null} o.destination
 * @param {{lat: number, lng: number, name?: string}|null} o.origin
 * @param {object|null} o.driver     { name, lat, lng, heading, speed, age_seconds | at }
 * @param {{minutes?: number, at?: string|number}|null} o.eta
 */
export function createRouteCard(o) {
    const { host, text, lang } = o;
    claimHost(host);
    const base = createBaseMap({ host, config: o.config, text, lang, kind: 'card', cooperative: 'full', label: text.route_title });
    const { map, ui } = base;
    const nf = numberFormat(lang);
    const layers = L.layerGroup().addTo(map);
    let destroyed = false;
    let driverMarker = null;

    const etaText = h('span', { class: 'abm-badge__t' });
    const eta = h('div', { class: 'abm-badge', hidden: true }, icon('clock', 15), etaText);
    const seenText = h('span', { class: 'abm-badge__t' });
    const seen = h('div', { class: 'abm-badge abm-badge--muted', hidden: true }, icon('route', 15), seenText);
    const badges = h('div', { class: 'abm-chips' }, eta, seen);
    const none = h('div', { class: 'abm-empty abm-empty--low', hidden: true, role: 'status' },
        h('span', { class: 'abm-empty__i', 'aria-hidden': 'true' }, icon('route', 22)),
        h('strong', { class: 'abm-empty__t' }, text.route_none));
    ui.append(badges, none);
    base.addTool({ name: 'fit', label: text.fit_all, onClick: () => fit() });
    base.addFullscreenTool();

    let bounds = [];

    function fit() {
        base.fit(bounds, { maxZoom: 16, padding: [44, 44] });
    }

    function update(data = {}) {
        if (destroyed) return;
        layers.clearLayers();
        driverMarker?.marker.remove();
        driverMarker = null;
        bounds = [];
        const dest = data.destination && validPoint(num(data.destination.lat), num(data.destination.lng)) ? { lat: num(data.destination.lat), lng: num(data.destination.lng) } : null;
        const origin = data.origin && validPoint(num(data.origin.lat), num(data.origin.lng)) ? { lat: num(data.origin.lat), lng: num(data.origin.lng), name: data.origin.name } : null;
        let any = false;

        (data.routes ?? []).forEach((r, i) => {
            const pts = normaliseTrack(r.points);
            if (pts.length < 2) return;
            any = true;
            const color = LINE_COLORS[i % LINE_COLORS.length];
            const line = simplify(pts, 5).map((p) => [p.lat, p.lng]);
            L.polyline(line, { color, weight: 5, opacity: 0.9, lineCap: 'round', interactive: false }).addTo(layers);
            const first = pts[0];
            const lastP = pts[pts.length - 1];
            L.marker([first.lat, first.lng], { icon: dotIcon('start', { name: 'play' }), keyboard: false })
                .bindTooltip(`${text.start} · ${clockText(first.t, lang)}`, { direction: 'top', offset: [0, -4], className: 'abm-tip', opacity: 1 }).addTo(layers);
            if (!data.driver) {
                L.marker([lastP.lat, lastP.lng], { icon: dotIcon('end', { name: 'flag' }), keyboard: false })
                    .bindTooltip(`${text.end} · ${clockText(lastP.t, lang)}`, { direction: 'top', offset: [0, -4], className: 'abm-tip', opacity: 1 }).addTo(layers);
            }
            detectStops(pts, { radiusM: 40, minMs: 120_000 }).forEach((s, k) => {
                L.marker([s.lat, s.lng], { icon: dotIcon('stop', String(k + 1)), keyboard: false })
                    .bindTooltip(fmt(text.stop_n, { n: nf.format(k + 1) }), { direction: 'top', offset: [0, -4], className: 'abm-tip', opacity: 1 }).addTo(layers);
            });
            pts.forEach((p) => bounds.push({ lat: p.lat, lng: p.lng }));
        });

        if (origin) {
            const m = L.marker([origin.lat, origin.lng], { icon: pinIcon('branch', 'store'), keyboard: false });
            if (origin.name) m.bindTooltip(String(origin.name), { direction: 'top', offset: [0, -6], className: 'abm-tip', opacity: 1 });
            m.addTo(layers);
            bounds.push(origin);
        }
        if (dest) {
            L.marker([dest.lat, dest.lng], { icon: pinIcon('destination', 'home'), keyboard: false })
                .bindTooltip(text.destination_label, { direction: 'top', offset: [0, -6], className: 'abm-tip', opacity: 1 }).addTo(layers);
            bounds.push(dest);
        }

        seen.hidden = true;
        const d = data.driver ? normaliseDriver({ id: 'driver', on_duty: true, ...data.driver }, { now: Date.now() }) : null;
        if (d && d.lat !== null) {
            any = true;
            driverMarker = new DriverMarker(d, { tooltip: () => firstName(d.name) });
            driverMarker.marker.addTo(layers);
            paintDriver(driverMarker.el, {
                status: 'out', stale: d.seenAt === null || Date.now() - d.seenAt > base.cfg.staleMs, moving: d.speed !== null && d.speed >= 2, selected: false, highlighted: false,
                heading: d.heading ?? undefined, tag: '', label: `${text.route_driver}: ${firstName(d.name)}`,
            });
            bounds.push({ lat: d.lat, lng: d.lng });
            if (d.seenAt !== null) {
                seen.hidden = false;
                seenText.textContent = `${text.route_last_seen}: ${agoText(Date.now() - d.seenAt, lang, text)}`;
            }
        }

        const minutes = data.eta?.minutes !== undefined && data.eta?.minutes !== null ? num(data.eta.minutes) : null;
        const etaAtMs = data.eta?.at !== undefined && data.eta?.at !== null ? (typeof data.eta.at === 'number' ? data.eta.at : Date.parse(data.eta.at)) : null;
        if (minutes !== null || (etaAtMs !== null && Number.isFinite(etaAtMs))) {
            eta.hidden = false;
            etaText.textContent = `${text.route_eta}: ${minutes !== null ? fmt(text.card_eta_min, { n: nf.format(Math.max(1, Math.round(minutes))) }) : clockText(etaAtMs, lang)}`;
        } else {
            eta.hidden = true;
        }
        none.hidden = any;
        fit();
    }

    update(o);

    const controller = {
        update,
        fit,
        get map() { return map; },
        destroy() {
            if (destroyed) return;
            destroyed = true;
            base.destroy();
            claimHost(host, null);
        },
    };
    claimHost(host, controller);
    return controller;
}
