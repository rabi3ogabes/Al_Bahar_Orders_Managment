import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    MAX_CHARS, MIN_CHARS, RECENT_MAX, clean, createSearchLoop, flatten, isOpenShortcut, isPlainClick, isSlashShortcut, isTyping, looksLikePhone, plan, recentSearches, step,
} from '../../resources/js/command-palette-core.js';

// ── What was typed ───────────────────────────────────────────────────────────────────────────────────────────────

test('clean collapses spaces, drops control characters and cuts a very long query', () => {
    assert.equal(clean('  ab   100 \n\t123 '), 'ab 100 123');
    assert.equal(clean('a\u0000b\u001fc'), 'a b c');
    assert.equal(clean(null), '');
    assert.equal(clean(undefined), '');
    assert.equal(clean('x'.repeat(500)).length, MAX_CHARS);
    assert.equal(clean('فاطمة '.repeat(100)).length <= MAX_CHARS, true);
});

test('plan: under two characters there is nothing to ask, from two there is', () => {
    assert.equal(MIN_CHARS, 2);
    for (const q of ['', ' ', 'a', ' a ', '😀'.slice(0, 1)]) assert.equal(plan(q), 'short', JSON.stringify(q));
    for (const q of ['ab', 'AB100123', '55', 'فا', '😀😀']) assert.equal(plan(q), 'search', JSON.stringify(q));
});

// ── Shortcuts ────────────────────────────────────────────────────────────────────────────────────────────────────

test('Ctrl/Cmd+K opens, with any keyboard language, but not with other modifiers or while composing', () => {
    assert.equal(isOpenShortcut({ key: 'k', ctrlKey: true }), true);
    assert.equal(isOpenShortcut({ key: 'K', metaKey: true }), true);
    assert.equal(isOpenShortcut({ key: 'ل', code: 'KeyK', ctrlKey: true }), true);   // the Arabic layout: same physical key
    assert.equal(isOpenShortcut({ key: 'k' }), false);
    assert.equal(isOpenShortcut({ key: 'k', ctrlKey: true, shiftKey: true }), false);
    assert.equal(isOpenShortcut({ key: 'k', ctrlKey: true, altKey: true }), false);
    assert.equal(isOpenShortcut({ key: 'k', ctrlKey: true, isComposing: true }), false);
    assert.equal(isOpenShortcut(null), false);
});

test('"/" opens only when nobody is typing, and never with a modifier', () => {
    const body = { tagName: 'BODY' };
    assert.equal(isSlashShortcut({ key: '/' }, body), true);
    assert.equal(isSlashShortcut({ key: '/' }, null), true);
    assert.equal(isSlashShortcut({ key: '/' }, { tagName: 'INPUT', type: 'text' }), false);
    assert.equal(isSlashShortcut({ key: '/' }, { tagName: 'INPUT' }), false);
    assert.equal(isSlashShortcut({ key: '/' }, { tagName: 'TEXTAREA' }), false);
    assert.equal(isSlashShortcut({ key: '/' }, { tagName: 'SELECT' }), false);
    assert.equal(isSlashShortcut({ key: '/' }, { tagName: 'DIV', isContentEditable: true }), false);
    assert.equal(isSlashShortcut({ key: '/' }, { tagName: 'INPUT', type: 'checkbox' }), true);   // a tick box does not take text
    assert.equal(isSlashShortcut({ key: '/' }, { tagName: 'BUTTON' }), true);
    assert.equal(isSlashShortcut({ key: '/', ctrlKey: true }, body), false);
    assert.equal(isSlashShortcut({ key: '/', metaKey: true }, body), false);
    assert.equal(isSlashShortcut({ key: '?' }, body), false);
    assert.equal(isSlashShortcut({ key: '/', isComposing: true }, body), false);
});

test('isTyping knows the things text goes into', () => {
    assert.equal(isTyping({ tagName: 'INPUT', type: 'search' }), true);
    assert.equal(isTyping({ tagName: 'INPUT', type: 'email' }), true);
    assert.equal(isTyping({ tagName: 'INPUT', type: 'submit' }), false);
    assert.equal(isTyping({ tagName: 'A' }), false);
    assert.equal(isTyping(undefined), false);
});

test('only modified clicks keep the browser\'s own behaviour', () => {
    assert.equal(isPlainClick({ button: 0 }), true);
    assert.equal(isPlainClick({}), true);
    for (const e of [{ ctrlKey: true }, { metaKey: true }, { shiftKey: true }, { altKey: true }, { button: 1 }]) assert.equal(isPlainClick(e), false);
});

// ── The list the arrow keys walk ─────────────────────────────────────────────────────────────────────────────────

const item = (id, title = id) => ({ id, title, subtitle: '', badge: null, meta: null, url: `/x/${id}`, icon: 'bag', ltr: false });

test('flatten lists every result in screen order and puts a group\'s "see all" after its results', () => {
    const groups = [
        { key: 'orders', label: 'Orders', items: [item('a'), item('b')], more: { label: 'See all', url: '/orders?search=x' } },
        { key: 'products', label: 'Products', items: [item('c')], more: null },
        { key: 'commands', label: 'Go to', items: [], more: null },
    ];
    const rows = flatten(groups);
    assert.deepEqual(rows.map((r) => [r.i, r.g, r.kind, r.key]), [[0, 0, 'item', 'orders:a'], [1, 0, 'item', 'orders:b'], [2, 0, 'more', 'orders:more'], [3, 1, 'item', 'products:c']]);
    assert.equal(rows[2].item.url, '/orders?search=x');
    assert.deepEqual(flatten([]), []);
    assert.deepEqual(flatten(null), []);
    assert.deepEqual(flatten(undefined), []);
});

test('step wraps around at both ends and starts at the first (Down) or the last (Up) row', () => {
    assert.equal(step(-1, 1, 5), 0);
    assert.equal(step(-1, -1, 5), 4);
    assert.equal(step(4, 1, 5), 0);
    assert.equal(step(0, -1, 5), 4);
    assert.equal(step(2, 1, 5), 3);
    assert.equal(step(2, -1, 5), 1);
    assert.equal(step(0, 1, 0), -1);
    assert.equal(step(3, 1, 0), -1);
    assert.equal(step(0, 1, 1), 0);
});

// ── Recent searches ──────────────────────────────────────────────────────────────────────────────────────────────

const fakeStorage = () => {
    const data = new Map();
    return { getItem: (k) => (data.has(k) ? data.get(k) : null), setItem: (k, v) => void data.set(k, String(v)), removeItem: (k) => void data.delete(k), data };
};

test('recent searches: newest first, no duplicates (any case), at most six, per person', () => {
    const st = fakeStorage();
    const mine = recentSearches(st, 'u1');
    assert.deepEqual(mine.list(), []);
    mine.add('cash');
    mine.add('AB100123');
    mine.add('CASH');
    assert.deepEqual(mine.list(), ['CASH', 'AB100123']);
    for (const q of ['one1', 'two2', 'three3', 'four4', 'five5']) mine.add(q);
    assert.equal(mine.list().length, RECENT_MAX);
    assert.equal(mine.list()[0], 'five5');
    assert.deepEqual(recentSearches(st, 'u2').list(), []);   // another person on the same browser sees nothing of it
    assert.notEqual([...st.data.keys()].find((k) => k.endsWith('.u1')), undefined);
});

test('recent searches never keep a phone number, a single letter or an empty query', () => {
    const mine = recentSearches(fakeStorage(), 'u1');
    for (const q of ['+974 5512 3456', '55123456', '٥٥١٢٣٤٥٦', '974-55123456', 'a', '', '   ']) mine.add(q);
    assert.deepEqual(mine.list(), []);
    mine.add('5512');                                          // a piece of a number is an order-number piece too: kept
    assert.deepEqual(mine.list(), ['5512']);
    assert.equal(looksLikePhone('hello 55123456'), false);
    assert.equal(looksLikePhone('+974 5512 3456'), true);
});

test('recent searches can be removed one by one and cleared', () => {
    const mine = recentSearches(fakeStorage(), 'u1');
    mine.add('alpha');
    mine.add('beta');
    assert.deepEqual(mine.remove('alpha'), ['beta']);
    assert.deepEqual(mine.clear(), []);
    assert.deepEqual(mine.list(), []);
});

test('recent searches survive broken or missing storage', () => {
    const throwing = { getItem() { throw new Error('blocked'); }, setItem() { throw new Error('full'); }, removeItem() { throw new Error('blocked'); } };
    const a = recentSearches(throwing, 'u1');
    assert.deepEqual(a.list(), []);
    assert.deepEqual(a.add('alpha'), []);   // nothing is kept, nothing breaks
    assert.deepEqual(a.clear(), []);
    const none = recentSearches(null, 'u1');
    assert.deepEqual(none.add('alpha'), []);
    const corrupt = fakeStorage();
    corrupt.setItem('albahar.admin.search.recent.u1', '{not json');
    assert.deepEqual(recentSearches(corrupt, 'u1').list(), []);
    corrupt.setItem('albahar.admin.search.recent.u1', JSON.stringify([1, null, 'ok', '']));
    assert.deepEqual(recentSearches(corrupt, 'u1').list(), ['ok']);
});

// ── The loop that asks the server ────────────────────────────────────────────────────────────────────────────────

/** A fake clock and a fake server, so the loop is tested without waiting. */
function rig() {
    const timers = [];
    let now = 0;
    const events = [];
    const calls = [];
    const pending = [];
    const loop = createSearchLoop({
        url: '/admin/search',
        emit: (e) => events.push(e),
        setTimer: (fn, ms) => { const t = { fn, at: now + ms, dead: false }; timers.push(t); return t; },
        clearTimer: (t) => { if (t) t.dead = true; },
        makeController: () => { const c = { aborted: false, signal: {}, abort() { c.aborted = true; } }; c.signal = c; return c; },
        fetchJson: (url, signal) => new Promise((resolve, reject) => {
            const call = { url, signal, resolve, reject };
            calls.push(call);
            pending.push(call);
        }),
    });
    const advance = (ms) => {
        now += ms;
        timers.filter((t) => !t.dead && t.at <= now).sort((a, b) => a.at - b.at).forEach((t) => { t.dead = true; t.fn(); });
    };
    const flush = () => new Promise((r) => setImmediate(r));
    const types = () => events.map((e) => e.type);

    return { loop, events, calls, advance, flush, types };
}

test('typing waits for a pause: a burst of keys is one request, for the last text', async () => {
    const r = rig();
    for (const t of ['ab', 'ab1', 'ab10', 'ab100']) { r.loop.ask(t); r.advance(100); }
    assert.equal(r.calls.length, 0);
    r.advance(200);
    assert.equal(r.calls.length, 1);
    assert.equal(r.calls[0].url, '/admin/search?q=ab100');
    r.calls[0].resolve({ status: 200, body: { q: 'ab100', state: 'ready', groups: [{ key: 'orders', items: [] }] } });
    await r.flush();
    assert.deepEqual(r.events.at(-1), { type: 'results', q: 'ab100', state: 'ready', groups: [{ key: 'orders', items: [] }] });
});

test('a new question aborts the one on its way, and a late answer to the old one is thrown away', async () => {
    const r = rig();
    r.loop.ask('ab1');
    r.advance(300);
    const first = r.calls[0];
    r.loop.ask('ab100');
    r.advance(300);
    const second = r.calls[1];
    assert.equal(first.signal.aborted, true);
    second.resolve({ status: 200, body: { q: 'ab100', state: 'ready', groups: [] } });
    await r.flush();
    first.resolve({ status: 200, body: { q: 'ab1', state: 'ready', groups: [{ key: 'old', items: [] }] } });   // arrives late
    await r.flush();
    const results = r.events.filter((e) => e.type === 'results');
    assert.equal(results.length, 1);
    assert.equal(results[0].q, 'ab100');
});

test('an aborted request is not an error', async () => {
    const r = rig();
    r.loop.ask('ab1');
    r.advance(300);
    r.loop.ask('ab2');
    r.calls[0].reject(Object.assign(new Error('aborted'), { name: 'AbortError' }));
    await r.flush();
    assert.equal(r.types().includes('error'), false);
});

test('under two characters nothing is asked: idle for an empty box, short for one letter, and a running request is cancelled', async () => {
    const r = rig();
    r.loop.ask('');
    r.loop.ask('a');
    r.advance(1000);
    assert.equal(r.calls.length, 0);
    assert.deepEqual(r.types(), ['idle', 'short']);
    r.loop.ask('ab');
    r.advance(300);
    r.loop.ask('a');                                    // the person deleted a letter: the answer for "ab" must not come back
    r.calls[0].resolve({ status: 200, body: { q: 'ab', state: 'pages', groups: [] } });
    await r.flush();
    assert.equal(r.events.filter((e) => e.type === 'results').length, 0);
    assert.equal(r.types().at(-1), 'short');
});

test('ask({now}) skips the pause (Enter, "Try again")', () => {
    const r = rig();
    r.loop.ask('ab100', { now: true });
    assert.equal(r.calls.length, 1);
});

test('a slow answer shows the skeleton after a moment; a quick one never does', async () => {
    const r = rig();
    r.loop.ask('ab100');
    r.advance(220);                                     // the request starts
    r.advance(100);
    assert.equal(r.types().includes('slow'), false);
    r.calls[0].resolve({ status: 200, body: { groups: [] } });
    await r.flush();
    r.advance(1000);
    assert.equal(r.types().includes('slow'), false);

    const s = rig();
    s.loop.ask('ab100');
    s.advance(220);
    s.advance(200);
    assert.equal(s.types().includes('slow'), true);
});

test('429 is "throttled" with the wait the server asked for; other failures are an error', async () => {
    const r = rig();
    r.loop.ask('ab100', { now: true });
    r.calls[0].resolve({ status: 429, body: { retry_after: '12' } });
    await r.flush();
    assert.deepEqual(r.events.at(-1), { type: 'throttled', retryAfter: 12 });

    for (const reply of [{ status: 500, body: null }, { status: 200, body: null }, { status: 200, body: { nope: true } }, { status: 403, body: { message: 'x' } }]) {
        const e = rig();
        e.loop.ask('ab100', { now: true });
        e.calls[0].resolve(reply);
        await e.flush();
        assert.equal(e.events.at(-1).type, 'error', JSON.stringify(reply));
    }
    const down = rig();
    down.loop.ask('ab100', { now: true });
    down.calls[0].reject(new TypeError('Failed to fetch'));
    await down.flush();
    assert.deepEqual(down.events.at(-1), { type: 'error', status: 0 });
});

test('stop() cancels the pause and the request (the window was closed)', async () => {
    const r = rig();
    r.loop.ask('ab100');
    r.loop.stop();
    r.advance(1000);
    assert.equal(r.calls.length, 0);
    r.loop.ask('ab100', { now: true });
    r.loop.stop();
    assert.equal(r.calls[0].signal.aborted, true);
    r.calls[0].resolve({ status: 200, body: { groups: [] } });
    await r.flush();
    assert.equal(r.events.filter((e) => e.type === 'results').length, 0);
});

test('the query is sent encoded: Arabic, symbols and SQL are only text', () => {
    const r = rig();
    r.loop.ask("فاطمة & 100% '; DROP", { now: true });
    assert.equal(r.calls[0].url, `/admin/search?q=${encodeURIComponent("فاطمة & 100% '; DROP")}`);
});
