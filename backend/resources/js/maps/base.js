// The shared map shell used by the fleet map, the partner map, the playback map and the route cards: a Leaflet map with themed
// tiles (light / dark by the page theme, switched live), a graceful "tiles unavailable" fallback, logical-side tools and
// keyboard-friendly controls, fullscreen, and a clean teardown so it can be created again after wire:navigate or bfcache.

import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import '../../css/maps.css';
import { normaliseConfig } from './logic.js';
import { createToast, frameThrottle, h, icon, isDark, pageDir, prefersReducedMotion } from './dom.js';

const hosts = new WeakMap();

/** Hands a host element to one controller at a time: whatever was mounted there before is destroyed and the element emptied. */
export function claimHost(host, controller) {
    const previous = hosts.get(host);
    if (previous && previous !== controller) {
        try { previous.destroy(); } catch { /* already gone */ }
    }
    if (controller) hosts.set(host, controller);
    else hosts.delete(host);
}

export { L };

/**
 * @param {object} o
 * @param {HTMLElement} o.host      empty container the map fills (its height decides the map height)
 * @param {object} o.config         MapConfig::payload() (read defensively)
 * @param {object} o.text           dictionary from text.js
 * @param {string} o.lang           'en' | 'ar'
 * @param {string} o.kind           css modifier: fleet | partner | card
 * @param {'none'|'touch'|'full'} o.cooperative  how much the map yields to page scrolling
 */
export function createBaseMap({ host, config, text, lang, kind = 'fleet', cooperative = 'none', label }) {
    const cfg = normaliseConfig(config);
    const reduced = prefersReducedMotion();
    const root = h('div', { class: `abm abm--${kind}`, dir: pageDir() });
    const shell = h('div', { class: 'abm-shell' });
    const stage = h('div', { class: 'abm-stage' });
    const canvas = h('div', { class: 'abm-canvas', dir: 'ltr', role: 'application', 'aria-label': label || text.map_label });
    const ui = h('div', { class: 'abm-ui' });
    const tools = h('div', { class: 'abm-tools', role: 'toolbar', 'aria-label': text.map_label, 'aria-orientation': 'vertical' });
    ui.append(tools);
    stage.append(canvas, ui);
    shell.append(stage);
    root.append(shell);
    host.replaceChildren(root);

    const touch = Boolean(L.Browser.mobile || window.matchMedia?.('(pointer: coarse)')?.matches);
    const map = L.map(canvas, {
        zoomControl: false,
        attributionControl: false,
        zoomSnap: 0.5,
        zoomDelta: 1,
        wheelPxPerZoomLevel: 100,
        minZoom: 3,
        maxZoom: cfg.tiles.maxZoom,
        fadeAnimation: !reduced,
        zoomAnimation: !reduced,
        markerZoomAnimation: !reduced,
        inertia: !reduced,
        scrollWheelZoom: cooperative !== 'full',
        dragging: !(touch && cooperative !== 'none'),
        keyboard: true,
    });
    map.setView([cfg.center.lat, cfg.center.lng], cfg.zoom);
    L.control.attribution({ prefix: '<a href="https://leafletjs.com" target="_blank" rel="noopener noreferrer">Leaflet</a>', position: 'bottomright' }).addTo(map);
    map.attributionControl.addAttribution(cfg.tiles.attribution);

    const toast = createToast(ui);
    const cleanups = [];
    const on = (target, ev, fn, opts) => {
        target.addEventListener(ev, fn, opts);
        cleanups.push(() => target.removeEventListener(ev, fn, opts));
    };

    /* ───────────── Tiles, theme and the unavailable fallback ───────────── */

    let layer = null;
    let layerDark = null;
    let ok = 0;
    let bad = 0;
    let noticeShown = false;

    const setUnavailable = (down) => {
        stage.classList.toggle('abm--no-tiles', down);
        if (down && !noticeShown) {
            noticeShown = true;
            toast.show(`${text.tiles_unavailable}. ${text.tiles_unavailable_hint}`, 7000);
        }
    };

    const buildLayer = (url) => {
        const next = L.tileLayer(url, {
            subdomains: cfg.tiles.subdomains || 'abc',
            maxZoom: cfg.tiles.maxZoom,
            detectRetina: cfg.tiles.retina,
            keepBuffer: 2,
            updateWhenIdle: touch,
            errorTileUrl: '',
            crossOrigin: false,
        });
        next.on('tileload', () => { ok += 1; if (stage.classList.contains('abm--no-tiles')) setUnavailable(false); });
        next.on('tileerror', () => { bad += 1; });
        next.on('load', () => { if (ok === 0 && bad > 0) setUnavailable(true); });
        return next;
    };

    const applyTheme = (dark) => {
        const distinct = cfg.tiles.darkUrl !== '';
        const wantDark = dark && distinct;
        stage.classList.toggle('abm--dark', dark);
        canvas.classList.toggle('abm--tint', dark && !distinct);
        if (layer && layerDark === wantDark) return;
        const next = buildLayer(wantDark ? cfg.tiles.darkUrl : cfg.tiles.lightUrl);
        next.addTo(map);
        if (layer) {
            const old = layer;
            const drop = () => { if (map.hasLayer(old)) map.removeLayer(old); };
            next.once('load', drop);
            setTimeout(drop, 4000);
        }
        layer = next;
        layerDark = wantDark;
    };
    applyTheme(isDark());

    const themeObserver = new MutationObserver(() => applyTheme(isDark()));
    themeObserver.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
    cleanups.push(() => themeObserver.disconnect());

    /* ───────────── Size ───────────── */

    const resizeListeners = [];
    const resize = frameThrottle(() => {
        map.invalidateSize({ pan: false });
        const size = map.getSize();
        resizeListeners.forEach((fn) => fn(size));
    });
    const ro = typeof ResizeObserver === 'function' ? new ResizeObserver(resize) : null;
    ro?.observe(canvas);
    cleanups.push(() => ro?.disconnect());

    /* ───────────── Cooperative gestures (page scroll wins until the visitor asks for the map) ───────────── */

    let hintShown = false;
    const hint = (message) => {
        if (hintShown) return;
        hintShown = true;
        toast.show(message, 3500);
    };
    if (cooperative === 'full' && !touch) {
        map.on('focus', () => map.scrollWheelZoom.enable());
        map.on('blur', () => map.scrollWheelZoom.disable());
    }
    if (touch && cooperative !== 'none') {
        on(canvas, 'touchstart', (e) => { if (e.touches.length === 1 && !stage.classList.contains('abm--fs')) hint(text.two_fingers ?? 'Use two fingers to move the map'); }, { passive: true });
    }

    /* ───────────── Full screen ───────────── */

    let fsButton = null;
    const fsActive = () => document.fullscreenElement === root || root.classList.contains('abm--pseudo-fs');
    const syncFs = () => {
        const on = fsActive();
        stage.classList.toggle('abm--fs', on);
        document.documentElement.classList.toggle('abm-lock', root.classList.contains('abm--pseudo-fs'));
        if (fsButton) {
            fsButton.setAttribute('aria-pressed', String(on));
            const name = on ? text.fullscreen_exit : text.fullscreen;
            fsButton.setAttribute('aria-label', name);
            fsButton.title = name;
            fsButton.replaceChildren(icon(on ? 'exit' : 'fullscreen', 20));
        }
        if (cooperative !== 'none') {
            if (on) { map.dragging.enable(); map.scrollWheelZoom.enable(); } else if (touch) map.dragging.disable();
        }
        resize();
        setTimeout(() => map.invalidateSize({ pan: false }), 250);
    };
    const toggleFullscreen = async () => {
        if (fsActive()) {
            root.classList.remove('abm--pseudo-fs');
            if (document.fullscreenElement) await document.exitFullscreen().catch(() => {});
            syncFs();
            return;
        }
        if (root.requestFullscreen) {
            try {
                await root.requestFullscreen();
                return;
            } catch { /* fall back to the in-page version below */ }
        }
        root.classList.add('abm--pseudo-fs');
        syncFs();
    };
    on(document, 'fullscreenchange', syncFs);
    on(document, 'keydown', (e) => { if (e.key === 'Escape' && root.classList.contains('abm--pseudo-fs')) toggleFullscreen(); });
    cleanups.push(() => {
        root.classList.remove('abm--pseudo-fs');
        document.documentElement.classList.remove('abm-lock');
        if (document.fullscreenElement === root) document.exitFullscreen?.().catch(() => {});
    });

    /* ───────────── Tools (logical side: inline-end) ───────────── */

    /** A 44px icon button in the map toolbar. */
    const addTool = ({ name, label: aria, onClick, pressed, group, secondary, className }) => {
        const b = h('button', { type: 'button', class: `abm-tool${className ? ` ${className}` : ''}`, 'aria-label': aria, title: aria, on: { click: onClick } }, icon(name, 20));
        if (pressed !== undefined) b.setAttribute('aria-pressed', String(pressed));
        if (group) b.dataset.group = group;
        if (secondary) b.dataset.secondary = '1';
        tools.append(b);
        return b;
    };
    const addSeparator = () => tools.append(h('span', { class: 'abm-tools__sep', role: 'separator' }));

    addTool({ name: 'plus', label: text.zoom_in, onClick: () => map.zoomIn() });
    addTool({ name: 'minus', label: text.zoom_out, onClick: () => map.zoomOut() });
    addSeparator();

    return {
        L, map, cfg, text, lang, root, shell, stage, canvas, ui, tools, toast, reduced, touch,
        addTool,
        addSeparator,
        /** On narrow maps the secondary tools (layers, legend, follow) fold behind one "more" button. */
        addMoreTool() {
            const b = addTool({
                name: 'more', label: text.more_tools, pressed: false, className: 'abm-tool--more',
                onClick: () => {
                    const open = tools.dataset.more !== 'true';
                    tools.dataset.more = String(open);
                    b.setAttribute('aria-pressed', String(open));
                },
            });
            return b;
        },
        addFullscreenTool() {
            fsButton = addTool({ name: 'fullscreen', label: text.fullscreen, onClick: toggleFullscreen, pressed: false });
            return fsButton;
        },
        toggleFullscreen,
        isFullscreen: fsActive,
        on,
        onCleanup: (fn) => cleanups.push(fn),
        onResized: (fn) => resizeListeners.push(fn),
        /** fitBounds that never zooms past maxZoom and respects reduced motion. */
        fit(points, { maxZoom = 15, padding = [48, 48], paddingBottomRight } = {}) {
            const pts = points.filter((p) => p && Number.isFinite(p.lat) && Number.isFinite(p.lng)).map((p) => [p.lat, p.lng]);
            if (!pts.length) {
                map.setView([cfg.center.lat, cfg.center.lng], cfg.zoom, { animate: !reduced });
                return;
            }
            const opts = { maxZoom, animate: !reduced, paddingTopLeft: padding };
            opts.paddingBottomRight = paddingBottomRight ?? padding;
            if (pts.length === 1) map.setView(pts[0], Math.min(maxZoom, 15), { animate: !reduced });
            else map.fitBounds(L.latLngBounds(pts), opts);
        },
        flyTo(latlng, zoom = 16) {
            if (reduced) map.setView(latlng, zoom, { animate: false });
            else map.flyTo(latlng, zoom, { duration: 0.8 });
        },
        destroy() {
            cleanups.splice(0).reverse().forEach((fn) => { try { fn(); } catch { /* element already detached */ } });
            toast.destroy();
            try { map.remove(); } catch { /* already removed */ }
            canvas._leaflet_id = undefined;
            root.remove();
        },
    };
}
