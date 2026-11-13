// Marker builders in the Al Bahar style, and the animation engine that glides driver markers between fixes.
// Everything dynamic is set with textContent / CSS custom properties; markup here is constant.

import { L } from './base.js';
import { Tween, glideDuration, haversine } from './logic.js';
import { h, icon, svgFrom } from './dom.js';

const VEHICLE = `<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false">
  <path class="abm-g-body" d="M12 2.2c-2.7 0-4.6.8-5 2.2L6.2 9v10c0 1.4 1 2.2 2.2 2.2h7.2c1.2 0 2.2-.8 2.2-2.2V9l-.8-4.6c-.4-1.4-2.3-2.2-5-2.2z"/>
  <path class="abm-g-cut" d="M8.4 7.1c.3-.8 1.6-1.3 3.6-1.3s3.3.5 3.6 1.3l.6 2.6H7.8z"/>
  <rect class="abm-g-cut" x="8.2" y="15.1" width="7.6" height="2.6" rx="1"/>
</svg>`;

const DROP = 'M16 1.5C8.6 1.5 2.5 7.4 2.5 14.7c0 9.7 13.5 25.6 13.5 25.6S29.5 24.4 29.5 14.7C29.5 7.4 23.4 1.5 16 1.5z';

/** The DOM of one driver marker. Painted later with paintDriver(). */
function buildDriverEl(initialsText) {
    return h('div', { class: 'abm-dm' },
        h('span', { class: 'abm-dm__pulse' }),
        h('span', { class: 'abm-dm__beam' }),
        h('span', { class: 'abm-dm__badge' }, h('span', { class: 'abm-dm__glyph' }, svgFrom(VEHICLE))),
        h('span', { class: 'abm-dm__tag' }, initialsText ?? ''));
}

/** Applies the visual state to a driver marker element; only touches what changed. */
export function paintDriver(el, s) {
    if (!el) return;
    if (el.dataset.status !== s.status) el.dataset.status = s.status;
    el.classList.toggle('is-stale', s.stale);
    el.classList.toggle('is-moving', s.moving);
    el.classList.toggle('is-selected', s.selected);
    el.classList.toggle('is-hl', s.highlighted);
    if (s.heading !== undefined && s.heading !== null) {
        const deg = Math.round(s.heading * 2) / 2;
        if (el._h !== deg) {
            el._h = deg;
            el.style.setProperty('--h', `${deg}deg`);
        }
        el.classList.add('has-heading');
    } else {
        el.classList.remove('has-heading');
    }
    const tag = el.lastElementChild;
    if (tag && tag.textContent !== s.tag) tag.textContent = s.tag;
    const wrap = el.parentElement;
    if (wrap && s.label && wrap.getAttribute('aria-label') !== s.label) wrap.setAttribute('aria-label', s.label);
}

/**
 * Runs `fn` when a marker is clicked or, for keyboard users, when Enter or Space is pressed on it. Leaflet 1.9 only turns Enter into a
 * click for popups, so a focused marker would otherwise do nothing.
 */
export function onActivate(marker, fn) {
    marker.on('click', fn);
    marker.on('keypress', (e) => {
        const k = e.originalEvent;
        if (k?.key === 'Enter' || k?.key === ' ') {
            k.preventDefault();
            fn();
        }
    });
}

function driverIcon(el) {
    return L.divIcon({ className: 'abm-icon', html: el, iconSize: [44, 44], iconAnchor: [22, 22] });
}

/** A driver marker plus the tween that moves it. */
export class DriverMarker {
    constructor(d, { onClick, onHover, tooltip }) {
        this.id = d.id;
        this.el = buildDriverEl('');
        this.pos = { lat: d.lat, lng: d.lng, heading: d.heading };
        this.tween = new Tween(this.pos);
        this.marker = L.marker([d.lat, d.lng], { icon: driverIcon(this.el), keyboard: true, riseOnHover: true });
        if (onClick) onActivate(this.marker, () => onClick(this.id));
        this.marker.on('mouseover', () => onHover?.(this.id, true));
        this.marker.on('mouseout', () => onHover?.(this.id, false));
        this.marker.bindTooltip(() => tooltip(this.id), { direction: 'top', offset: [0, -24], opacity: 1, className: 'abm-tip' });
    }

    /** Starts a glide to the new fix (or snaps). Returns true when an animation frame is needed. */
    moveTo(target, now, reduced) {
        const dist = haversine(this.pos, target);
        const dur = glideDuration(dist, { reduced });
        if (dur === 0) {
            this.tween = new Tween(target);
            this.apply({ ...target, heading: target.heading ?? this.pos.heading });
            return false;
        }
        this.tween.retarget(this.pos, { lat: target.lat, lng: target.lng, heading: target.heading ?? this.pos.heading }, now, dur);
        return true;
    }

    /** One animation frame; true when the glide has finished. */
    frame(now) {
        const p = this.tween.at(now);
        this.apply(p);
        return p.done;
    }

    apply(p) {
        this.pos = { lat: p.lat, lng: p.lng, heading: p.heading };
        this.marker.setLatLng([p.lat, p.lng]);
        if (p.heading !== null && p.heading !== undefined) {
            const deg = Math.round(p.heading * 2) / 2;
            if (this.el._h !== deg) {
                this.el._h = deg;
                this.el.style.setProperty('--h', `${deg}deg`);
            }
        }
    }
}

/** One requestAnimationFrame loop for every gliding marker, so 200 drivers cost one frame callback. */
export class Mover {
    constructor(onFrame) {
        this.active = new Set();
        this.raf = 0;
        this.onFrame = onFrame;
        this.tick = this.tick.bind(this);
    }

    add(m) {
        this.active.add(m);
        if (!this.raf) this.raf = requestAnimationFrame(this.tick);
    }

    remove(m) {
        this.active.delete(m);
    }

    tick() {
        const now = performance.now();
        for (const m of this.active) {
            const done = m.frame(now);
            this.onFrame?.(m);
            if (done) this.active.delete(m);
        }
        this.raf = this.active.size ? requestAnimationFrame(this.tick) : 0;
    }

    stop() {
        cancelAnimationFrame(this.raf);
        this.raf = 0;
        this.active.clear();
    }
}

/** A numbered bubble that stands for several drivers at once. */
export function clusterIcon(count, status) {
    const size = count >= 50 ? 56 : count >= 15 ? 50 : 44;
    const el = h('div', { class: 'abm-cl', 'data-status': status }, h('span', { class: 'abm-cl__n' }, String(count)));
    return L.divIcon({ className: 'abm-icon', html: el, iconSize: [size, size], iconAnchor: [size / 2, size / 2] });
}

/** Teardrop pin for a branch or a customer address. */
export function pinIcon(kind, glyph) {
    const el = h('div', { class: `abm-pin abm-pin--${kind}` },
        svgFrom(`<svg viewBox="0 0 32 42" width="34" height="44" aria-hidden="true" focusable="false"><path class="abm-pin__drop" d="${DROP}"/></svg>`),
        h('span', { class: 'abm-pin__glyph' }, icon(glyph, 15)));
    return L.divIcon({ className: 'abm-icon', html: el, iconSize: [34, 44], iconAnchor: [17, 42], tooltipAnchor: [0, -38] });
}

/** Small round marker on a route: start, end, or a numbered stop. */
export function dotIcon(kind, content) {
    const inner = typeof content === 'string' ? h('span', { class: 'abm-dot__n' }, content) : icon(content.name, 13);
    const el = h('div', { class: `abm-dot abm-dot--${kind}` }, inner);
    return L.divIcon({ className: 'abm-icon', html: el, iconSize: [30, 30], iconAnchor: [15, 15], tooltipAnchor: [0, -14] });
}
