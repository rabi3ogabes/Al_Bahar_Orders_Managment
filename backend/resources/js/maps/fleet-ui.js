// The DOM of the fleet map around the canvas: the driver panel (stats, search, filter, list; a bottom sheet on narrow screens),
// the selected-driver card, the connection chip, the legend, and the empty / error / loading overlays.
// Builders return elements plus small update functions that only touch what changed.

import { h, icon } from './dom.js';
import { fmt } from './text.js';

const STATUSES = ['all', 'out', 'assigned', 'idle', 'moving', 'stale', 'offline'];
const STAT_KEYS = ['onDuty', 'moving', 'idle', 'stale'];

/** Sets text only when it changed (cheap to call every tick). */
export function setText(el, value) {
    const v = value ?? '';
    if (el.textContent !== v) el.textContent = v;
}

export function buildPanel({ text, onQuery, onStatus, onToggleSheet, onListKey }) {
    const handle = h('button', { type: 'button', class: 'abm-panel__handle', 'aria-expanded': 'false', 'aria-controls': 'abm-list', on: { click: onToggleSheet } },
        h('span', { class: 'abm-panel__grab', 'aria-hidden': 'true' }),
        h('span', { class: 'abm-panel__handle-row' },
            icon('users', 18),
            h('span', { class: 'abm-panel__handle-title' }, text.drivers),
            h('span', { class: 'abm-panel__handle-count' }),
            h('span', { class: 'abm-panel__chev', 'aria-hidden': 'true' }, icon('chevron', 18))));

    const stats = {};
    const statEls = STAT_KEYS.map((k) => {
        const n = h('span', { class: 'abm-stat__n' }, '0');
        stats[k] = n;
        return h('div', { class: `abm-stat abm-stat--${k}` }, n, h('span', { class: 'abm-stat__l' }, text[{ onDuty: 'stat_on_duty', moving: 'stat_moving', idle: 'stat_idle', stale: 'stat_stale' }[k]]));
    });

    const search = h('input', { type: 'search', class: 'abm-input abm-input--search', placeholder: text.search_ph, 'aria-label': text.search_label, autocomplete: 'off', spellcheck: 'false', enterkeyhint: 'search' });
    search.addEventListener('input', () => onQuery(search.value));
    const select = h('select', { class: 'abm-input abm-input--select', 'aria-label': text.status_filter },
        STATUSES.map((s) => h('option', { value: s }, text[`filter_${s}`])));
    select.addEventListener('change', () => onStatus(select.value));

    const list = h('ul', { class: 'abm-list', id: 'abm-list' });
    list.addEventListener('keydown', onListKey);
    const note = h('p', { class: 'abm-panel__note', role: 'status' });
    const body = h('div', { class: 'abm-panel__body' },
        h('div', { class: 'abm-stats' }, statEls),
        h('div', { class: 'abm-panel__filters' },
            h('label', { class: 'abm-search' }, h('span', { class: 'abm-search__i' }, icon('search', 16)), search),
            select),
        list, note);

    const el = h('aside', { class: 'abm-panel', 'aria-label': text.drivers }, handle, body);
    return {
        el, list, note, search, select, stats,
        handleCount: handle.querySelector('.abm-panel__handle-count'),
        setOpen(open) {
            el.dataset.open = open ? 'true' : 'false';
            handle.setAttribute('aria-expanded', String(open));
            handle.setAttribute('aria-label', open ? text.sheet_close : text.sheet_open);
        },
        isOpen: () => el.dataset.open === 'true',
    };
}

/** One list row; updated in place by updateRow(). */
export function buildRow(id, onSelect) {
    const avatar = h('span', { class: 'abm-avatar' });
    const name = h('span', { class: 'abm-row__name' });
    const sub = h('span', { class: 'abm-row__sub' });
    const meta = h('span', { class: 'abm-row__meta' });
    const age = h('span', { class: 'abm-row__age' });
    const btn = h('button', { type: 'button', class: 'abm-row', data: { id }, on: { click: () => onSelect(id) } },
        avatar, h('span', { class: 'abm-row__main' }, name, sub, meta), age);
    const li = h('li', { class: 'abm-list__item' }, btn);
    return { li, btn, avatar, name, sub, meta, age };
}

export function updateRow(r, v) {
    r.btn.dataset.status = v.status;
    r.btn.classList.toggle('is-stale', v.stale);
    r.btn.classList.toggle('is-moving', v.moving);
    r.btn.classList.toggle('is-nopos', !v.hasPosition);
    r.btn.setAttribute('aria-pressed', String(v.selected));
    setText(r.avatar, v.initials);
    setText(r.name, v.name);
    setText(r.sub, v.sub);
    setText(r.meta, v.meta);
    setText(r.age, v.age);
    if (r.btn.getAttribute('aria-label') !== v.label) r.btn.setAttribute('aria-label', v.label);
}

/** The selected-driver card. `kv` rows are shown only when they have a value. */
export function buildCard({ text, onClose, onFollow, onZoom, onCopy }) {
    const avatar = h('span', { class: 'abm-avatar abm-avatar--lg' });
    const name = h('h3', { class: 'abm-card__name' });
    const status = h('span', { class: 'abm-pill' });
    const close = h('button', { type: 'button', class: 'abm-iconbtn', 'aria-label': text.card_close, title: text.card_close, on: { click: onClose } }, icon('close', 18));
    const head = h('div', { class: 'abm-card__head' }, avatar, h('div', { class: 'abm-card__title' }, name, status), close);

    const kvDefs = [['order', 'card_order'], ['speed', 'card_speed'], ['eta', 'card_eta'], ['updated', 'card_updated'], ['vehicle', 'card_vehicle'], ['branch', 'card_branch'], ['accuracy', 'card_accuracy']];
    const kv = {};
    const grid = h('dl', { class: 'abm-card__grid' });
    kvDefs.forEach(([k, label]) => {
        const dd = h('dd', { class: 'abm-kv__v' });
        const wrap = h('div', { class: `abm-kv abm-kv--${k}`, hidden: true }, h('dt', { class: 'abm-kv__k' }, text[label]), dd);
        kv[k] = { wrap, dd };
        grid.append(wrap);
    });

    const open = h('a', { class: 'abm-btn abm-btn--primary', hidden: true, rel: 'noopener' }, icon('open', 16), h('span', { class: 'abm-btn__t' }, text.card_open_order));
    const viewBtn = h('button', { type: 'button', class: 'abm-btn abm-btn--primary', hidden: true }, icon('open', 16), h('span', { class: 'abm-btn__t' }, text.card_view_order));
    const call = h('a', { class: 'abm-btn', hidden: true }, icon('phone', 16), h('span', { class: 'abm-btn__t' }));
    const phoneText = h('span', { class: 'abm-phone', dir: 'ltr' });
    const copy = h('button', { type: 'button', class: 'abm-iconbtn', 'aria-label': text.card_copy, title: text.card_copy, on: { click: onCopy } }, icon('copy', 16));
    const callRow = h('div', { class: 'abm-card__call', hidden: true }, call, phoneText, copy);
    const callNote = h('p', { class: 'abm-card__note' }, text.card_call_note);
    callRow.append(callNote);

    const follow = h('button', { type: 'button', class: 'abm-btn', 'aria-pressed': 'false', on: { click: onFollow } }, icon('follow', 16), h('span', { class: 'abm-btn__t' }, text.short_follow));
    const zoom = h('button', { type: 'button', class: 'abm-btn abm-btn--icon', 'aria-label': text.card_zoom, title: text.card_zoom, on: { click: onZoom } }, icon('fit', 18));
    const actions = h('div', { class: 'abm-card__actions' }, open, viewBtn, follow, zoom);

    const el = h('section', { class: 'abm-card', role: 'group', tabindex: '-1', hidden: true }, head, grid, actions, callRow);
    return {
        el, avatar, name, status, kv, open, viewBtn, call, callRow, phoneText, follow, zoom, copy,
        setFollow(on, canFollow) {
            follow.setAttribute('aria-pressed', String(on));
            follow.disabled = !canFollow;
            const name = on ? text.follow_off : canFollow ? text.follow_on : text.follow_pick;
            follow.setAttribute('aria-label', name);
            follow.title = name;
            setText(follow.querySelector('.abm-btn__t'), on ? text.short_following : text.short_follow);
        },
    };
}

/** The small chip that says how positions arrive. */
export function buildConnection(text) {
    const dot = h('span', { class: 'abm-conn__dot', 'aria-hidden': 'true' });
    const label = h('span', { class: 'abm-conn__t' });
    const el = h('div', { class: 'abm-conn', role: 'status', 'aria-live': 'polite', tabindex: '0' }, dot, label);
    return {
        el,
        set(state, seconds) {
            const key = { live: 'conn_live', reconnecting: 'conn_reconnecting', polling: 'conn_polling' }[state] ?? 'conn_polling';
            el.dataset.state = state;
            setText(label, fmt(text[key], { s: seconds }));
            const hint = fmt(text[`${key}_hint`], { s: seconds });
            el.title = hint;
            el.setAttribute('aria-label', `${fmt(text[key], { s: seconds })}. ${hint}`);
        },
    };
}

export function buildLegend(text, withDestination) {
    const items = [['idle', text.legend_idle], ['assigned', text.legend_assigned], ['out', text.legend_out], ['stale', text.legend_stale], ['offline', text.legend_offline], ['branch', text.legend_branch]];
    if (withDestination) items.push(['destination', text.legend_destination]);
    const ul = h('ul', { class: 'abm-legend__list' }, items.map(([k, label]) => h('li', { class: 'abm-legend__item' }, h('span', { class: `abm-legend__sw abm-legend__sw--${k}`, 'aria-hidden': 'true' }), label)));
    return h('div', { class: 'abm-legend', hidden: true, role: 'group', 'aria-label': text.legend }, ul);
}

/** Overlay shown over the map: loading skeleton, empty state, and the refresh error. */
export function buildOverlays(text, { onRetry }) {
    const skeleton = h('div', { class: 'abm-skel', role: 'status', 'aria-label': text.loading },
        h('span', { class: 'abm-skel__bar' }), h('span', { class: 'abm-skel__bar abm-skel__bar--s' }), h('span', { class: 'abm-skel__pin' }));
    const empty = h('div', { class: 'abm-empty', hidden: true, role: 'status' },
        h('span', { class: 'abm-empty__i', 'aria-hidden': 'true' }, icon('users', 22)),
        h('div', {}, h('strong', { class: 'abm-empty__t' }, text.empty_title), h('span', { class: 'abm-empty__p' }, text.empty_text)));
    const errText = h('span', { class: 'abm-err__t' }, text.refresh_error);
    const retry = h('button', { type: 'button', class: 'abm-btn abm-btn--sm', on: { click: onRetry } }, icon('refresh', 14), h('span', { class: 'abm-btn__t' }, text.retry));
    const error = h('div', { class: 'abm-err', hidden: true, role: 'alert' }, icon('alert', 18), errText, retry);
    return { skeleton, empty, error, errText, retry };
}
