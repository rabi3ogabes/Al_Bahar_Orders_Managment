// Live order toasts: a bottom-end stack of animated notifications for new orders and order status changes.
// Framework free; pure logic lives in live-toasts-core.js. All text is set with textContent (payloads are never parsed as HTML).
import './../css/live-toasts.css';
import { MAX_VISIBLE, TTL_MS, createStack, fill, normalise, toneFor } from './live-toasts-core.js';

const ICONS = {
    bag: '<path d="M6 7h12l1 13H5L6 7z"/><path d="M9 7a3 3 0 0 1 6 0"/>',
    check: '<circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/>',
    flame: '<path d="M12 3c1 4 5 5 5 10a5 5 0 0 1-10 0c0-2 1-3 2-4 0 2 1 3 2 3 0-3-1-5 1-9z"/>',
    truck: '<path d="M3 6h11v10H3z"/><path d="M14 10h4l3 3v3h-7"/><circle cx="7" cy="18" r="2"/><circle cx="17" cy="18" r="2"/>',
    flag: '<path d="M5 21V4"/><path d="M5 4h12l-2 4 2 4H5"/>',
    x: '<circle cx="12" cy="12" r="9"/><path d="m9 9 6 6M15 9l-6 6"/>',
    alert: '<path d="M12 3 2 20h20L12 3z"/><path d="M12 10v4M12 17h.01"/>',
    undo: '<path d="M9 14 4 9l5-5"/><path d="M4 9h10a6 6 0 0 1 0 12h-3"/>',
    clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
    bell: '<path d="M6 16v-5a6 6 0 0 1 12 0v5l2 2H4l2-2z"/><path d="M10 21h4"/>',
    bellOff: '<path d="M6 16v-5a6 6 0 0 1 9-5.2M18 11v5l2 2H4l2-2"/><path d="M10 21h4"/><path d="M3 3l18 18"/>',
    soundOn: '<path d="M4 9v6h4l5 4V5L8 9H4z"/><path d="M16 9a4 4 0 0 1 0 6"/>',
    soundOff: '<path d="M4 9v6h4l5 4V5L8 9H4z"/><path d="m17 9 4 6M21 9l-4 6"/>',
    arrow: '<path d="m9 6 6 6-6 6"/>',
    close: '<path d="m6 6 12 12M18 6 6 18"/>',
};
const svg = (name) => `<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">${ICONS[name] ?? ICONS.bell}</svg>`;
const el = (tag, cls, txt) => { const n = document.createElement(tag); if (cls) n.className = cls; if (txt !== undefined && txt !== null) n.textContent = txt; return n; };
const store = {
    get: (k) => { try { return localStorage.getItem(k); } catch { return null; } },
    set: (k, v) => { try { localStorage.setItem(k, v); } catch { /* private mode: the choice just is not remembered */ } },
};
const reduced = () => window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false;
const isRtl = () => getComputedStyle(document.documentElement).direction === 'rtl';
const SPRING = 'cubic-bezier(.2, .9, .25, 1.15)';

/**
 * @param {object} opts
 * @param {(key: string, vars?: object) => string} opts.t     translations: new_order, status_update, view, close, sound_on, sound_off, mute, unmute, more,
 *                                                            controls, delivery, pickup, cod, sadad, pay_at_pickup, by and status.<order status>
 * @param {(evt: object) => string|null} [opts.href]          where "View" goes (an <a> is rendered); or
 * @param {(evt: object) => void} [opts.onView]               a click handler (a <button> is rendered)
 * @param {(evts: object[]) => void} [opts.onEvents]          called with every accepted event (e.g. to refresh data)
 * @param {boolean} [opts.navigate]                           add wire:navigate to the link (Livewire pages)
 */
export function createLiveToasts(opts) {
    const { t, href, onView, onEvents, navigate = false } = opts;
    const stack = createStack({ max: MAX_VISIBLE });
    const nodes = new Map(); // id -> { node, bar }
    let muted = store.get('live_toasts_muted') === '1';
    let sound = store.get('live_toasts_sound') === '1';
    let missed = 0;
    let audio = null;

    const root = el('div', 'lt-root');
    const more = el('div', 'lt-more'); more.hidden = true;
    const list = el('ul', 'lt-stack'); list.setAttribute('role', 'status'); list.setAttribute('aria-live', 'polite'); list.setAttribute('aria-relevant', 'additions');
    const controls = el('div', 'lt-controls'); controls.setAttribute('role', 'group'); controls.setAttribute('aria-label', t('controls'));
    const soundBtn = el('button', 'lt-ctl'); soundBtn.type = 'button';
    const muteBtn = el('button', 'lt-ctl'); muteBtn.type = 'button';
    const badge = el('span', 'lt-badge'); badge.hidden = true;
    controls.append(soundBtn, muteBtn);
    root.append(more, list, controls);

    const renderControls = () => {
        soundBtn.innerHTML = svg(sound ? 'soundOn' : 'soundOff');
        soundBtn.setAttribute('aria-pressed', String(sound));
        soundBtn.setAttribute('aria-label', sound ? t('sound_off') : t('sound_on')); soundBtn.title = soundBtn.getAttribute('aria-label');
        muteBtn.innerHTML = svg(muted ? 'bellOff' : 'bell');
        muteBtn.setAttribute('aria-pressed', String(muted));
        muteBtn.setAttribute('aria-label', muted ? t('unmute') : t('mute')); muteBtn.title = muteBtn.getAttribute('aria-label');
        badge.hidden = !(muted && missed > 0); badge.textContent = missed > 99 ? '99+' : String(missed);
        muteBtn.append(badge);
    };
    const renderMore = () => { more.hidden = stack.queued === 0; if (stack.queued) more.textContent = fill(t('more'), { n: stack.queued }); };
    const renderDepth = () => [...list.children].forEach((n, i) => n.style.setProperty('--depth', String(i)));

    /** FLIP: run a DOM change, then slide every surviving toast from its old position to its new one. */
    const flip = (mutate) => {
        const before = new Map([...list.children].map((n) => [n, n.getBoundingClientRect().top]));
        mutate();
        if (reduced()) return;
        [...list.children].forEach((n) => {
            if (!before.has(n)) return;
            const dy = before.get(n) - n.getBoundingClientRect().top;
            if (dy) n.animate({ translate: [`0 ${dy}px`, '0 0'] }, { duration: 380, easing: SPRING });
        });
    };

    const chime = () => {
        if (!sound || muted) return;
        try {
            audio ??= new (window.AudioContext || window.webkitAudioContext)();
            if (audio.state === 'suspended') audio.resume();
            const now = audio.currentTime;
            [[880, 0], [1318.5, 0.11]].forEach(([f, d]) => {
                const o = audio.createOscillator(); const g = audio.createGain();
                o.type = 'sine'; o.frequency.value = f; o.connect(g); g.connect(audio.destination);
                g.gain.setValueAtTime(0.0001, now + d); g.gain.exponentialRampToValueAtTime(0.06, now + d + 0.02); g.gain.exponentialRampToValueAtTime(0.0001, now + d + 0.35);
                o.start(now + d); o.stop(now + d + 0.4);
            });
        } catch { /* audio is a nicety; never break the toast */ }
    };

    const money = (e) => {
        const n = Number(e.total);
        if (!Number.isFinite(n)) return '';
        const loc = document.documentElement.lang === 'ar' ? 'ar-QA' : 'en-US';
        return `${e.currency} ${n.toLocaleString(loc, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
    };
    const statusText = (s) => { const k = `status.${s}`; const v = t(k); return v && v !== k ? v : String(s ?? '').replace(/_/g, ' '); };

    const build = (e) => {
        const { tone, icon } = toneFor(e);
        const li = el('li', `lt-toast lt-${tone}`); li.dataset.id = e.id;
        const url = href?.(e);
        const main = url ? el('a', 'lt-main') : el('button', 'lt-main');
        if (url) { main.href = url; if (navigate) main.setAttribute('wire:navigate', ''); } else main.type = 'button';
        main.addEventListener('click', () => { if (!url) onView?.(e); dismiss(e.id); });
        const ic = el('span', 'lt-icon'); ic.innerHTML = svg(icon);
        const body = el('span'); body.style.display = 'block'; body.style.minInlineSize = '0';
        body.append(el('span', 'lt-kicker', e.type === 'placed' ? t('new_order') : t('status_update')));
        const row = el('span', 'lt-row'); row.append(el('span', 'lt-title', `#${e.number}`), el('span', 'lt-total', money(e)));
        body.append(row);
        if (e.type === 'status') {
            const pill = el('span', 'lt-pill');
            if (e.previous) { pill.append(el('span', 'lt-prev', statusText(e.previous))); const a = el('i'); a.innerHTML = svg('arrow'); a.style.display = 'inline-flex'; pill.append(a); }
            pill.append(el('span', 'lt-next', statusText(e.status)));
            body.append(pill);
        } else {
            const chips = el('span', 'lt-chips');
            if (e.fulfilment) chips.append(el('span', 'lt-chip', t(e.fulfilment)));
            if (e.paymentMethod) chips.append(el('span', 'lt-chip', t(e.paymentMethod)));
            if (chips.children.length) body.append(chips);
        }
        const rtl = isRtl();
        const place = rtl && e.branchAr ? e.branchAr : e.branch;
        const meta = [place, e.customer ? fill(t('by'), { name: e.customer }) : null].filter(Boolean).join(' · ');
        if (meta) body.append(el('span', 'lt-meta', meta));
        const go = el('span', 'lt-go'); go.append(document.createTextNode(t('view'))); const ar = el('i'); ar.innerHTML = svg('arrow'); ar.style.display = 'inline-flex'; go.append(ar);
        body.append(go);
        main.append(ic, body);
        const close = el('button', 'lt-close'); close.type = 'button'; close.innerHTML = svg('close'); close.setAttribute('aria-label', t('close')); close.title = t('close');
        close.addEventListener('click', () => dismiss(e.id));
        const bar = el('span', 'lt-bar'); bar.style.setProperty('--lt-start', rtl ? 'right' : 'left'); bar.setAttribute('aria-hidden', 'true');
        li.append(main, close, bar);
        return { li, bar };
    };

    const show = (e) => {
        const { li, bar } = build(e);
        flip(() => { list.prepend(li); renderDepth(); });
        if (!reduced()) li.animate({ opacity: [0, 1], translate: ['0 28px', '0 0'], scale: ['.94', '1'] }, { duration: 460, easing: SPRING });
        const timer = bar.animate({ transform: ['scaleX(1)', 'scaleX(0)'] }, { duration: TTL_MS, easing: 'linear', fill: 'forwards' });
        timer.addEventListener('finish', () => dismiss(e.id));
        const pause = () => timer.pause();
        const resume = () => { if (!li.matches(':hover') && !li.matches(':focus-within') && !document.hidden) timer.play(); };
        li.addEventListener('mouseenter', pause); li.addEventListener('focusin', pause);
        li.addEventListener('mouseleave', resume); li.addEventListener('focusout', resume);
        nodes.set(e.id, { li, timer, resume });
    };

    function dismiss(id) {
        const n = nodes.get(id); if (!n) return;
        nodes.delete(id);
        n.timer.cancel();
        const finish = () => {
            flip(() => { n.li.remove(); renderDepth(); });
            const next = stack.remove(id);
            if (next) show(next);
            renderMore();
        };
        if (reduced()) { finish(); return; }
        const dx = isRtl() ? -40 : 40;
        n.li.animate({ opacity: [1, 0], translate: ['0 0', `${dx}px 0`] }, { duration: 240, easing: 'cubic-bezier(.4, 0, 1, 1)', fill: 'forwards' }).addEventListener('finish', finish);
    }

    const onVisibility = () => nodes.forEach((n) => (document.hidden ? n.timer.pause() : n.resume()));
    document.addEventListener('visibilitychange', onVisibility);

    soundBtn.addEventListener('click', () => {
        sound = !sound; store.set('live_toasts_sound', sound ? '1' : '0'); renderControls();
        if (sound) { const was = muted; muted = false; chime(); muted = was; } // a click is the user gesture that unlocks audio; play a sample
    });
    muteBtn.addEventListener('click', () => {
        muted = !muted; store.set('live_toasts_muted', muted ? '1' : '0'); missed = 0;
        if (muted) { stack.clear(); [...nodes.keys()].forEach((id) => dismiss(id)); renderMore(); }
        renderControls();
    });

    renderControls();
    document.body.append(root);

    return {
        /** Accepts one raw payload (the broadcast frame or a poll item). */
        push(raw) {
            const e = normalise(raw);
            if (!e) return;
            const r = stack.add(e);
            if (!r.accepted) return;
            onEvents?.([e]);
            if (muted) { stack.clear(); missed += 1; renderControls(); return; }
            if (r.shown) show(e);
            renderMore(); chime();
        },
        /** Re-reads the labels (after a language switch). */
        refresh() { controls.setAttribute('aria-label', t('controls')); renderControls(); },
        destroy() {
            document.removeEventListener('visibilitychange', onVisibility);
            nodes.forEach((n) => n.timer.cancel()); nodes.clear(); stack.clear(); root.remove(); audio?.close?.();
        },
    };
}
