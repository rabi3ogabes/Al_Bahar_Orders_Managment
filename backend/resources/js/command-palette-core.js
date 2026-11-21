// Pure logic of the dashboard's global search (Ctrl/Cmd+K): no DOM, so it is tested with `npm test` (tests/js/command-palette-core.test.mjs).
// The Alpine component in command-palette.js only wires these functions to the page.

/** The same limits as the server (App\Domain\Search\Support\SearchQuery): under two characters there is nothing to ask. */
export const MIN_CHARS = 2;
export const MAX_CHARS = 64;
export const DEBOUNCE_MS = 220;
/** A request that has not answered after this long shows the skeleton; a quick answer never flashes it. */
export const SLOW_MS = 160;
export const RECENT_MAX = 6;

/** Cleans what was typed the way the server will read it: spaces collapsed, cut to the longest query. */
export const clean = (q) => String(q ?? '').replace(/[\u0000-\u001f\u007f]+/g, ' ').replace(/\s+/g, ' ').trim().slice(0, MAX_CHARS);

/** What to do for the text in the box: nothing yet ("short"), or ask the server ("search"). */
export const plan = (q) => (clean(q).length < MIN_CHARS ? 'short' : 'search');

/** Ctrl/Cmd+K anywhere, whatever the keyboard language (the physical K key), never while composing text. */
export function isOpenShortcut(e) {
    if (!e || e.isComposing || e.altKey || e.shiftKey) return false;
    return Boolean((e.ctrlKey || e.metaKey) && (String(e.key).toLowerCase() === 'k' || e.code === 'KeyK'));
}

/** "/" opens the search too, but only when the person is not typing into something, and never with a modifier. */
export function isSlashShortcut(e, active) {
    if (!e || e.isComposing || e.ctrlKey || e.metaKey || e.altKey || e.key !== '/') return false;
    return !isTyping(active);
}

/** Is this element something the person types into (so a "/" or a letter belongs to it)? */
export function isTyping(el) {
    if (!el || typeof el !== 'object') return false;
    const tag = String(el.tagName ?? '').toLowerCase();
    if (tag === 'textarea' || tag === 'select') return true;
    if (tag === 'input') return !['button', 'checkbox', 'radio', 'submit', 'reset', 'range', 'color', 'file', 'image'].includes(String(el.type ?? 'text').toLowerCase());
    return el.isContentEditable === true;
}

/**
 * The answer of the server as one flat list of rows in screen order, so the arrow keys walk through it: every result, and the "see all" link of
 * a group right after its results. Each row knows its group and its position (`i`), which is also the number in its element id.
 *
 * @param {Array<{key: string, label: string, items: object[], more: {label: string, url: string}|null}>} groups
 * @returns {Array<{i: number, g: number, kind: 'item'|'more', key: string, item: object}>}
 */
export function flatten(groups) {
    const rows = [];
    (Array.isArray(groups) ? groups : []).forEach((group, g) => {
        (group.items ?? []).forEach((item) => rows.push({ i: rows.length, g, kind: 'item', key: `${group.key}:${item.id}`, item }));
        if (group.more) rows.push({ i: rows.length, g, kind: 'more', key: `${group.key}:more`, item: { id: 'more', title: group.more.label, subtitle: '', badge: null, meta: null, url: group.more.url, icon: 'arrow-right', ltr: false } });
    });
    return rows;
}

/** The row to highlight after an arrow key: it wraps around at both ends; -1 means "none yet" and Down starts at the first row. */
export function step(current, delta, length) {
    if (length <= 0) return -1;
    if (current < 0) return delta > 0 ? 0 : length - 1;
    return (current + delta + length) % length;
}

/** Only modified clicks (a new tab, a new window, a download) keep the browser's own behaviour. */
export const isPlainClick = (e) => !(e.ctrlKey || e.metaKey || e.shiftKey || e.altKey || (e.button ?? 0) > 0);

/** Looks like somebody's phone number (eight or more digits): never kept among the recent searches. */
export const looksLikePhone = (q) => (String(q).replace(/[٠-٩۰-۹]/g, '5').match(/\d/g) ?? []).length >= 8 && /^[+\d\s().\-٠-٩۰-۹]+$/.test(q);

/**
 * The recent searches of ONE person, kept in this browser only (localStorage, under their id), newest first, at most RECENT_MAX, no duplicates,
 * and never a phone number. Storage can be missing or full (private mode): then nothing is kept and nothing breaks.
 *
 * @param {Storage|null} storage
 * @param {string} userId
 */
export function recentSearches(storage, userId) {
    const key = `albahar.admin.search.recent.${userId || 'anon'}`;
    const read = () => {
        try {
            const v = JSON.parse(storage?.getItem(key) ?? '[]');
            return Array.isArray(v) ? v.filter((x) => typeof x === 'string' && x !== '').slice(0, RECENT_MAX) : [];
        } catch {
            return [];
        }
    };
    const write = (list) => {
        try {
            storage?.setItem(key, JSON.stringify(list.slice(0, RECENT_MAX)));
        } catch { /* storage full or blocked: the list just is not kept */ }
        return read();
    };
    return {
        list: read,
        add(q) {
            const v = clean(q);
            if (v.length < MIN_CHARS || looksLikePhone(v)) return read();
            return write([v, ...read().filter((x) => x.toLowerCase() !== v.toLowerCase())]);
        },
        remove: (q) => write(read().filter((x) => x !== q)),
        clear() {
            try { storage?.removeItem(key); } catch { /* nothing to clear */ }
            return [];
        },
    };
}

/**
 * Asks the server, one request at a time that matters. Typing waits for a pause (`debounce`), a newer question aborts the one still on its way,
 * and an answer that is no longer the latest is dropped, so a slow answer for "ab1" can never overwrite the answer for "ab1001".
 *
 * `fetchJson(url, signal)` resolves to {status, body}. `emit` gets one of
 *   {type: 'idle'}  {type: 'short'}  {type: 'loading'}  {type: 'slow'}
 *   {type: 'results', q, state, groups}  {type: 'throttled', retryAfter}  {type: 'error', status}
 * Timers are injectable for the tests.
 */
export function createSearchLoop({ url, fetchJson, emit, debounce = DEBOUNCE_MS, slow = SLOW_MS, setTimer = setTimeout, clearTimer = clearTimeout, makeController = () => new AbortController() }) {
    let timer = null;
    let slowTimer = null;
    let controller = null;
    let seq = 0;

    const stop = () => {
        if (timer !== null) clearTimer(timer);
        if (slowTimer !== null) clearTimer(slowTimer);
        timer = slowTimer = null;
        controller?.abort();
        controller = null;
        seq += 1;
    };

    const run = async (q) => {
        const mine = ++seq;
        controller?.abort();
        controller = makeController();
        emit({ type: 'loading' });
        slowTimer = setTimer(() => { if (mine === seq) emit({ type: 'slow' }); }, slow);
        try {
            const { status, body } = await fetchJson(`${url}?q=${encodeURIComponent(q)}`, controller.signal);
            if (mine !== seq) return;
            if (status === 429) return emit({ type: 'throttled', retryAfter: Number(body?.retry_after ?? 0) || null });
            if (status < 200 || status >= 300 || !body || !Array.isArray(body.groups)) return emit({ type: 'error', status });
            emit({ type: 'results', q, state: body.state ?? 'ready', groups: body.groups });
        } catch (err) {
            if (mine !== seq || err?.name === 'AbortError') return;
            emit({ type: 'error', status: 0 });
        } finally {
            if (mine === seq && slowTimer !== null) { clearTimer(slowTimer); slowTimer = null; }
        }
    };

    return {
        /** The text changed: ask after a pause (or at once with `now`, for Enter and "Try again"). */
        ask(text, { now = false } = {}) {
            const q = clean(text);
            stop();
            if (plan(q) === 'short') return emit({ type: q === '' ? 'idle' : 'short' });
            emit({ type: 'loading' });
            if (now) return run(q);
            const mine = seq;
            timer = setTimer(() => { timer = null; if (mine === seq) run(q); }, debounce);
        },
        stop,
    };
}
