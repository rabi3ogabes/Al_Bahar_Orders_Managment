// Small DOM helpers shared by every map. Text is only ever set with textContent / text nodes, never as HTML, so nothing a
// server sends (names, order numbers) can inject markup. Icons are constant SVG strings.

import { ageParts } from './logic.js';
import { fmt } from './text.js';

const SVG_NS = 'http://www.w3.org/2000/svg';

/** h('button', { class: 'x', type: 'button', 'aria-label': 'y', on: { click } }, 'text', childElement) */
export function h(tag, attrs = {}, ...kids) {
    const el = document.createElement(tag);
    for (const [k, v] of Object.entries(attrs ?? {})) {
        if (v === null || v === undefined || v === false) continue;
        if (k === 'class') el.className = v;
        else if (k === 'on') for (const [ev, fn] of Object.entries(v)) el.addEventListener(ev, fn);
        else if (k === 'data') for (const [dk, dv] of Object.entries(v)) el.dataset[dk] = dv;
        else if (k === 'text') el.textContent = v;
        else el.setAttribute(k, v === true ? '' : String(v));
    }
    for (const kid of kids.flat()) {
        if (kid === null || kid === undefined || kid === false) continue;
        el.append(kid instanceof Node ? kid : document.createTextNode(String(kid)));
    }
    return el;
}

const ICONS = {
    plus: 'M12 5v14M5 12h14',
    minus: 'M5 12h14',
    fit: 'M3 7V5a2 2 0 0 1 2-2h2M17 3h2a2 2 0 0 1 2 2v2M21 17v2a2 2 0 0 1-2 2h-2M7 21H5a2 2 0 0 1-2-2v-2M12 11.2a.8.8 0 1 0 0 1.6.8.8 0 0 0 0-1.6z',
    follow: 'M12 3v3M12 18v3M3 12h3M18 12h3M12 8.5a3.5 3.5 0 1 0 0 7 3.5 3.5 0 0 0 0-7z',
    fullscreen: 'M15 3h6v6M9 21H3v-6M21 3l-7 7M3 21l7-7',
    exit: 'M4 14h6v6M20 10h-6V4M14 10l7-7M3 21l7-7',
    search: 'M11 4a7 7 0 1 0 0 14 7 7 0 0 0 0-14zM21 21l-4.3-4.3',
    store: 'M4 9l1.5-5h13L20 9M4 9v10a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1V9M4 9h16M9 20v-6h6v6',
    tag: 'M20.6 13.4l-7.2 7.2a2 2 0 0 1-2.8 0L3 13V3h10l7.6 7.6a2 2 0 0 1 0 2.8zM7.5 7.5h.01',
    trail: 'M5 19c0-4 4-4 4-8s4-4 4-6M13 19h6M16 16l3 3-3 3',
    legend: 'M12 3l9 5-9 5-9-5 9-5zM3 13l9 5 9-5',
    phone: 'M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2z',
    copy: 'M9 9h10a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H9a1 1 0 0 1-1-1V10a1 1 0 0 1 1-1zM5 15H4a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1v1',
    open: 'M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5',
    close: 'M6 6l12 12M18 6L6 18',
    play: 'M7 4.5l12 7.5-12 7.5z',
    pause: 'M7 4.5h3.5v15H7zM13.5 4.5H17v15h-3.5z',
    restart: 'M3 12a9 9 0 1 0 3-6.7L3 8M3 3v5h5',
    chevron: 'M6 15l6-6 6 6',
    route: 'M6 19a2 2 0 1 0 0-4 2 2 0 0 0 0 4zM18 9a2 2 0 1 0 0-4 2 2 0 0 0 0 4zM8 17h6a3 3 0 0 0 0-6h-4a3 3 0 0 1 0-6h6',
    clock: 'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18zM12 7v5l3 2',
    alert: 'M10.3 3.9L2.4 18a2 2 0 0 0 1.7 3h15.8a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0zM12 9v4M12 17h.01',
    home: 'M3 11l9-8 9 8M5 10v10h5v-6h4v6h5V10',
    gauge: 'M12 14l4-4M3.3 17a10 10 0 1 1 17.4 0',
    refresh: 'M21 12a9 9 0 1 1-3-6.7L21 8M21 3v5h-5',
    users: 'M16 21v-1a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v1M9 3a4 4 0 1 0 0 8 4 4 0 0 0 0-8zM22 21v-1a4 4 0 0 0-3-3.9M16 3.1a4 4 0 0 1 0 7.8',
    flag: 'M5 21V4M5 4h12l-2 4 2 4H5',
    more: 'M5 12h.01M12 12h.01M19 12h.01',
};
const FILLED = new Set(['play', 'pause']);

/** A 24px outline icon; decorative (aria-hidden). */
export function icon(name, size = 18) {
    const svg = document.createElementNS(SVG_NS, 'svg');
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('width', String(size));
    svg.setAttribute('height', String(size));
    svg.setAttribute('aria-hidden', 'true');
    svg.setAttribute('focusable', 'false');
    svg.setAttribute('class', 'abm-i');
    const p = document.createElementNS(SVG_NS, 'path');
    p.setAttribute('d', ICONS[name] ?? '');
    if (FILLED.has(name)) {
        p.setAttribute('fill', 'currentColor');
        p.setAttribute('stroke', 'none');
    } else {
        p.setAttribute('fill', 'none');
        p.setAttribute('stroke', 'currentColor');
        p.setAttribute('stroke-width', '2');
        p.setAttribute('stroke-linecap', 'round');
        p.setAttribute('stroke-linejoin', 'round');
    }
    svg.append(p);
    return svg;
}

/** An SVG element from a constant markup string (never from data). */
export function svgFrom(markup) {
    const t = document.createElement('template');
    t.innerHTML = markup.trim();
    return t.content.firstElementChild;
}

/* ───────────── Language, numbers, time ───────────── */

const localeFor = (lang) => (lang === 'ar' ? 'ar-u-nu-latn' : 'en-GB');

export function numberFormat(lang, opts) {
    return new Intl.NumberFormat(localeFor(lang), opts);
}

/** "3 min ago" in the page language: Intl does the grammar (Arabic dual and plural forms included). */
export function agoText(ms, lang, text) {
    const { unit, n } = ageParts(ms);
    if (unit === 'none') return text.no_location;
    if (unit === 'now') return text.just_now;
    try {
        return new Intl.RelativeTimeFormat(localeFor(lang), { numeric: 'always', style: 'short' }).format(-n, unit);
    } catch {
        return `${n} ${unit}`;
    }
}

export function clockText(ms, lang, withSeconds = false) {
    if (ms === null || ms === undefined) return '';
    return new Date(ms).toLocaleTimeString(localeFor(lang), { hour: '2-digit', minute: '2-digit', second: withSeconds ? '2-digit' : undefined, hourCycle: 'h23', timeZone: 'Asia/Qatar' });
}

/** 7 min / 1 h 05 min / 40 s, in the page language. */
export function durationText(ms, text, lang) {
    const nf = numberFormat(lang);
    const s = Math.max(0, Math.round(ms / 1000));
    if (s < 60) return fmt(text.dur_s, { n: nf.format(s) });
    const m = Math.round(s / 60);
    if (m < 60) return fmt(text.dur_min, { n: nf.format(m) });
    return fmt(text.dur_h, { h: nf.format(Math.floor(m / 60)), m: nf.format(m % 60) });
}

/* ───────────── Environment ───────────── */

export const prefersReducedMotion = () => {
    try {
        return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    } catch {
        return false;
    }
};

export const isDark = () => document.documentElement.classList.contains('dark');

export const pageLang = () => (document.documentElement.lang || 'en').toLowerCase().startsWith('ar') ? 'ar' : 'en';

export const pageDir = () => (document.documentElement.dir === 'rtl' ? 'rtl' : 'ltr');

/** Runs `fn` at most once per animation frame, however often it is asked. */
export function frameThrottle(fn) {
    let queued = false;
    const run = () => {
        queued = false;
        fn();
    };
    const call = () => {
        if (queued) return;
        queued = true;
        requestAnimationFrame(run);
    };
    call.cancel = () => { queued = false; };
    return call;
}

/** Runs `fn` now, then at most once per `ms`; a call made during the wait runs once when it ends. */
export function lazy(fn, ms) {
    let last = 0;
    let timer = null;
    const call = () => {
        const wait = last + ms - Date.now();
        if (wait <= 0) {
            last = Date.now();
            fn();
        } else if (timer === null) {
            timer = setTimeout(() => {
                timer = null;
                last = Date.now();
                fn();
            }, wait);
        }
    };
    call.cancel = () => { clearTimeout(timer); timer = null; };
    return call;
}

/** A tiny in-map toast (aria-live) that replaces itself. Returns { show(message, ms), destroy() }. */
export function createToast(parent) {
    const el = h('div', { class: 'abm-toast', role: 'status', 'aria-live': 'polite', hidden: true });
    parent.append(el);
    let timer = null;
    return {
        show(message, ms = 5000) {
            el.textContent = message;
            el.hidden = false;
            clearTimeout(timer);
            timer = setTimeout(() => { el.hidden = true; }, ms);
        },
        destroy() {
            clearTimeout(timer);
            el.remove();
        },
    };
}
