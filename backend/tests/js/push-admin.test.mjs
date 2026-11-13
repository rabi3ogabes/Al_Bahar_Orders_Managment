// The dashboard's web push helpers that do not need a browser (resources/js/push-admin.js).
import assert from 'node:assert/strict';
import test from 'node:test';
import { WORKER_SCOPE, WORKER_URL, enable, errorCode, listen, supportState } from '../../resources/js/push-admin.js';

const IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';
const ALL = { hasServiceWorker: true, hasPush: true, hasNotification: true };

test('the worker is the one the store uses, registered under the dashboard path', () => {
    assert.equal(WORKER_URL, '/firebase-messaging-sw.js');
    assert.equal(WORKER_SCOPE, '/admin/');
});

test('support: a browser with the three APIs is ok', () => {
    assert.equal(supportState({ userAgent: 'Chrome', ...ALL }), 'ok');
    assert.equal(supportState({ userAgent: IPHONE, standalone: true, ...ALL }), 'ok');
});

test('support: Safari in an iPhone or iPad tab has no push until it is added to the Home Screen', () => {
    assert.equal(supportState({ userAgent: IPHONE, hasServiceWorker: true }), 'ios_install');
    assert.equal(supportState({ userAgent: 'Mozilla/5.0 (Macintosh)', platform: 'MacIntel', maxTouchPoints: 5, hasServiceWorker: true }), 'ios_install'); // iPadOS posing as a Mac
    assert.equal(supportState({ userAgent: IPHONE, standalone: true, hasServiceWorker: true }), 'unsupported'); // installed but no PushManager: too old
});

test('support: anything else without the APIs is unsupported', () => {
    assert.equal(supportState({ userAgent: 'Mozilla/5.0 (Windows NT 10.0) Chrome/50' }), 'unsupported');
    assert.equal(supportState(), 'unsupported');
    assert.equal(supportState({ userAgent: 'x', hasServiceWorker: true, hasPush: true }), 'unsupported');
});

test('error codes map to the messages the page shows', () => {
    assert.equal(errorCode(Object.assign(new Error('x'), { code: 'albahar/denied' })), 'denied');
    assert.equal(errorCode(Object.assign(new Error('x'), { code: 'albahar/save_failed' })), 'save_failed');
    assert.equal(errorCode({ code: 'messaging/permission-blocked' }), 'denied');
    assert.equal(errorCode({ code: 'messaging/failed-service-worker-registration' }), 'worker_failed');
    assert.equal(errorCode({ name: 'SecurityError' }), 'worker_failed');
    assert.equal(errorCode({ code: 'messaging/token-subscribe-failed' }), 'token_failed');
    assert.equal(errorCode({ name: 'AbortError' }), 'token_failed');
    assert.equal(errorCode({ name: 'InvalidAccessError' }), 'token_failed');
    assert.equal(errorCode(new Error('boom')), 'unknown');
    assert.equal(errorCode(null), 'unknown');
    assert.equal(errorCode(undefined), 'unknown');
});

test('enable refuses before touching the browser when there is no saved config', async () => {
    await assert.rejects(enable({ config: null, endpoint: '/x', csrf: 't' }), (e) => errorCode(e) === 'no_config');
});

test('enable says unsupported when the browser has no notifications or workers', async () => {
    await assert.rejects(enable({ config: { apiKey: 'a' }, endpoint: '/x', csrf: 't' }), (e) => errorCode(e) === 'unsupported');
});

test('listen does nothing without a config or a browser', async () => {
    await listen({ config: null, onMessage: () => assert.fail('no message expected') });
    await listen({ config: { apiKey: 'a' }, onMessage: () => assert.fail('no message expected') });
});
