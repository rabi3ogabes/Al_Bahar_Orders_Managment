// The Firebase messaging service worker (resources/push/firebase-messaging-sw.js), run in a sandbox with the browser pieces faked.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const template = readFileSync(new URL('../../resources/push/firebase-messaging-sw.js', import.meta.url), 'utf8');
const SETTINGS = {
    sdk: '10.14.1', brand: 'Al Bahar', icon: '/mobile-web/icons/Icon-192.png',
    firebase: { apiKey: 'AIzaTest', authDomain: 'p.firebaseapp.com', projectId: 'p', messagingSenderId: '1', appId: '1:1:web:a' },
};

function boot({ clientList = [] } = {}) {
    const listeners = {};
    const shown = [];
    const opened = [];
    const imported = [];
    const state = { init: null, background: null };
    const self = {
        location: { origin: 'https://albahar.example' },
        addEventListener: (type, fn) => { listeners[type] = fn; },
        skipWaiting: () => {},
        registration: { showNotification: (title, options) => { shown.push({ title, options }); return Promise.resolve(); } },
    };
    const clients = {
        claim: () => Promise.resolve(),
        matchAll: () => Promise.resolve(clientList),
        openWindow: (url) => { opened.push(url); return Promise.resolve(null); },
    };
    const sandbox = {
        self, clients, URL,
        importScripts: (...urls) => imported.push(...urls),
        firebase: { initializeApp: (c) => { state.init = c; }, messaging: () => ({ onBackgroundMessage: (fn) => { state.background = fn; } }) },
    };
    vm.runInNewContext(template.replace('/*@settings*/ {}', JSON.stringify(SETTINGS)), sandbox);

    return { listeners, shown, opened, imported, state };
}

const click = (sw, data) => {
    let wait; let closed = false;
    sw.listeners.notificationclick({ notification: { data, close: () => { closed = true; } }, waitUntil: (p) => { wait = p; } });

    return { done: wait ?? Promise.resolve(), closed: () => closed };
};

test('loads the pinned Firebase compat scripts and starts the app with the saved settings', () => {
    const sw = boot();
    assert.deepEqual(sw.imported, [
        'https://www.gstatic.com/firebasejs/10.14.1/firebase-app-compat.js',
        'https://www.gstatic.com/firebasejs/10.14.1/firebase-messaging-compat.js',
    ]);
    assert.deepEqual(JSON.parse(JSON.stringify(sw.state.init)), SETTINGS.firebase);
    assert.equal(typeof sw.state.background, 'function');
});

test('draws the live order card with one tag per order that updates quietly in place', async () => {
    const sw = boot();
    await sw.state.background({ data: { kind: 'order_progress', order_id: 'abc-123', title: 'Order AB100', body: 'On the way', deep_link: '/orders/abc-123', step: '2' } });
    assert.equal(sw.shown.length, 1);
    const { title, options } = sw.shown[0];
    assert.equal(title, 'Order AB100');
    assert.equal(options.body, 'On the way');
    assert.equal(options.tag, 'order-abc-123');
    assert.equal(options.renotify, false);
    assert.equal(options.silent, false);
    assert.equal(options.icon, '/mobile-web/icons/Icon-192.png');
    assert.equal(options.data.deep_link, '/orders/abc-123');

    await sw.state.background({ data: { kind: 'order_progress', order_id: 'abc-123', title: 'T', body: 'B', quiet: '1' } });
    assert.equal(sw.shown[1].options.tag, 'order-abc-123');
    assert.equal(sw.shown[1].options.silent, true);
});

test('leaves messages that carry a notification block to the Firebase SDK, so nothing shows twice', async () => {
    const sw = boot();
    await sw.state.background({ notification: { title: 'Hi', body: 'There' }, data: { deep_link: '/' } });
    assert.equal(sw.shown.length, 0);
});

test('shows other data-only messages with their title and body, or the brand name when there is no title', async () => {
    const sw = boot();
    await sw.state.background({ data: { title: 'Sale', body: 'Today only', tag: 'promo' } });
    await sw.state.background({ data: { body: 'No title' } });
    await sw.state.background({});
    assert.equal(sw.shown[0].title, 'Sale');
    assert.equal(sw.shown[0].options.tag, 'promo');
    assert.equal(sw.shown[1].title, 'Al Bahar');
    assert.equal(sw.shown[2].title, 'Al Bahar'); // iOS needs every push to show something
});

test('caps long text and ignores non-string values', async () => {
    const sw = boot();
    await sw.state.background({ data: { title: 'x'.repeat(500), body: 'y'.repeat(900), tag: 7, order_id: { a: 1 } } });
    assert.equal(sw.shown[0].title.length, 120);
    assert.equal(sw.shown[0].options.body.length, 300);
    assert.equal(sw.shown[0].options.tag, undefined);
});

for (const [raw, expected] of [
    ['/orders/9', '/orders/9'],
    ['/mobile-web/#/orders/9', '/mobile-web/#/orders/9'],
    ['https://albahar.example/products/7?x=1', '/products/7?x=1'],
    ['https://evil.example/phish', '/'],
    ['//evil.example/x', '/'],
    ['javascript:alert(1)', '/'],
    ['data:text/html,hi', '/'],
    ['', '/'],
    [undefined, '/'],
    [42, '/'],
]) {
    test(`only ever opens a link on this site: ${JSON.stringify(raw)} -> ${expected}`, async () => {
        const sw = boot();
        await sw.state.background({ data: { title: 'T', deep_link: raw, icon: raw } });
        assert.equal(sw.shown[0].options.data.deep_link, expected);
        const c = click(sw, sw.shown[0].options.data);
        await c.done;
        assert.deepEqual(sw.opened, [expected]);
        assert.equal(c.closed(), true);
    });
}

test('a click focuses the open tab of this site and takes it to the link', async () => {
    const calls = [];
    const tab = { url: 'https://albahar.example/orders', focus: () => { calls.push('focus'); return Promise.resolve(tab); }, navigate: (u) => { calls.push(`navigate ${u}`); return Promise.resolve(tab); } };
    const sw = boot({ clientList: [{ url: 'https://evil.example/x', focus: () => calls.push('WRONG') }, tab] });
    await click(sw, { deep_link: '/orders/5' }).done;
    assert.deepEqual(calls, ['focus', 'navigate /orders/5']);
    assert.deepEqual(sw.opened, []);
});

test('a click on the tab that already shows the link only focuses it', async () => {
    const calls = [];
    const tab = { url: 'https://albahar.example/orders/5', focus: () => { calls.push('focus'); return Promise.resolve(tab); }, navigate: () => calls.push('navigate') };
    const sw = boot({ clientList: [tab] });
    await click(sw, { deep_link: '/orders/5' }).done;
    assert.deepEqual(calls, ['focus']);
});

test('a click opens a window when no tab of this site is open', async () => {
    const sw = boot({ clientList: [{ url: 'https://elsewhere.example/', focus: () => Promise.resolve() }] });
    await click(sw, { deep_link: '/orders/5' }).done;
    assert.deepEqual(sw.opened, ['/orders/5']);
});

test('leaves clicks on notifications the Firebase SDK drew to the SDK', () => {
    const sw = boot();
    const c = click(sw, { FCM_MSG: { notification: {} } });
    assert.equal(c.closed(), false);
    assert.deepEqual(sw.opened, []);
});
