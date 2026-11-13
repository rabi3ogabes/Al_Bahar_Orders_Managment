import test from 'node:test';
import assert from 'node:assert/strict';
import {
  addUser, createDedupe, detectSupport, helpFamily, helpOrder, isDismissed, needsRegistration, normalizeMessage,
  parseConfig, parseStoredToken, parseUsers, promptApplies, removeUser, resolveState, REREGISTER_AFTER_MS,
} from '../src/lib/webpush-core.ts';

const IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1';
const IPHONE_OLD = 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_3 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.3 Mobile/15E148 Safari/604.1';
const IPAD_DESKTOP = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Safari/605.1.15';
const CHROME = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';
const EDGE = `${CHROME} Edg/124.0`;
const FIREFOX = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:125.0) Gecko/20100101 Firefox/125.0';
const MAC_SAFARI = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Safari/605.1.15';

const env = (o = {}) => ({ secure: true, serviceWorker: true, pushManager: true, notification: true, ua: CHROME, platform: 'Win32', touchPoints: 0, standalone: false, ...o });
const WEB = { apiKey: 'k', authDomain: 'p.firebaseapp.com', projectId: 'p', messagingSenderId: '1', appId: 'a', vapidKey: 'v' };
const input = (o = {}) => ({ support: 'ok', config: { enabled: true, web: WEB }, permission: 'default', optedIn: false, error: false, ...o });

test('parseConfig: accepts the API envelope and the inner object', () => {
  assert.deepEqual(parseConfig({ data: { enabled: true, web: WEB } }), { enabled: true, web: WEB });
  assert.deepEqual(parseConfig({ enabled: true, web: WEB }), { enabled: true, web: WEB });
});
test('parseConfig: incomplete or off means off, never half on', () => {
  assert.equal(parseConfig({ data: { enabled: true, web: { ...WEB, vapidKey: '' } } }).enabled, false);
  assert.equal(parseConfig({ data: { enabled: true, web: null } }).enabled, false);
  assert.equal(parseConfig({ data: { enabled: false, web: WEB } }).enabled, false);
  assert.equal(parseConfig({ data: { enabled: 'yes', web: WEB } }).enabled, false);
  assert.deepEqual(parseConfig(null), { enabled: false, web: null });
  assert.deepEqual(parseConfig('nope'), { enabled: false, web: null });
});
test('parseConfig: authDomain is optional and values are trimmed', () => {
  const c = parseConfig({ data: { enabled: true, web: { ...WEB, authDomain: undefined, apiKey: '  k  ' } } });
  assert.equal(c.web.authDomain, null);
  assert.equal(c.web.apiKey, 'k');
});

test('detectSupport: desktop and Android browsers with the APIs are ok', () => {
  assert.equal(detectSupport(env()), 'ok');
  assert.equal(detectSupport(env({ ua: 'Mozilla/5.0 (Linux; Android 14) Chrome/124.0 Mobile Safari/537.36', platform: 'Linux armv81', touchPoints: 5 })), 'ok');
});
test('detectSupport: insecure pages and missing APIs are unsupported', () => {
  assert.equal(detectSupport(env({ secure: false })), 'unsupported');
  assert.equal(detectSupport(env({ serviceWorker: false })), 'unsupported');
  assert.equal(detectSupport(env({ pushManager: false })), 'unsupported');
  assert.equal(detectSupport(env({ notification: false })), 'unsupported');
});
test('detectSupport: iPhone in a tab needs the Home Screen install, even though PushManager is missing there', () => {
  assert.equal(detectSupport(env({ ua: IPHONE, platform: 'iPhone', touchPoints: 5, pushManager: false })), 'ios_needs_install');
});
test('detectSupport: installed iPhone web app is ok; if the APIs are still missing it is unsupported', () => {
  assert.equal(detectSupport(env({ ua: IPHONE, platform: 'iPhone', touchPoints: 5, standalone: true })), 'ok');
  assert.equal(detectSupport(env({ ua: IPHONE, platform: 'iPhone', touchPoints: 5, standalone: true, pushManager: false })), 'unsupported');
});
test('detectSupport: iOS older than 16.4 cannot do it at all (installing would not help)', () => {
  assert.equal(detectSupport(env({ ua: IPHONE_OLD, platform: 'iPhone', touchPoints: 5 })), 'unsupported');
});
test('detectSupport: iPad pretending to be a Mac is still iOS', () => {
  assert.equal(detectSupport(env({ ua: IPAD_DESKTOP, platform: 'MacIntel', touchPoints: 5, pushManager: false })), 'ios_needs_install');
  assert.equal(detectSupport(env({ ua: IPAD_DESKTOP, platform: 'MacIntel', touchPoints: 5, standalone: true })), 'ok');
  // A real Mac (no touch) is not iOS.
  assert.equal(detectSupport(env({ ua: MAC_SAFARI, platform: 'MacIntel', touchPoints: 0 })), 'ok');
});

test('resolveState: the whole matrix', () => {
  assert.equal(resolveState(input({ support: 'unsupported' })), 'unsupported');
  assert.equal(resolveState(input({ support: 'unsupported', config: null })), 'unsupported');
  assert.equal(resolveState(input({ config: null })), 'disabled_by_admin');
  assert.equal(resolveState(input({ config: { enabled: false, web: null } })), 'disabled_by_admin');
  assert.equal(resolveState(input({ support: 'ios_needs_install' })), 'ios_needs_install');
  assert.equal(resolveState(input({ support: 'ios_needs_install', config: null })), 'disabled_by_admin');
  assert.equal(resolveState(input({ permission: 'denied', optedIn: true })), 'denied');
  assert.equal(resolveState(input({ permission: 'granted', optedIn: true, error: true })), 'error');
  assert.equal(resolveState(input({ permission: 'granted', optedIn: true })), 'granted');
  assert.equal(resolveState(input({ permission: 'granted', optedIn: false })), 'default');
  assert.equal(resolveState(input({ permission: 'default', optedIn: true })), 'default');
  assert.equal(resolveState(input()), 'default');
});

test('helpFamily and helpOrder: own browser first', () => {
  const e = (ua, platform = 'Win32', touchPoints = 0) => ({ ua, platform, touchPoints });
  assert.equal(helpFamily(e(CHROME)), 'chrome');
  assert.equal(helpFamily(e(EDGE)), 'chrome');
  assert.equal(helpFamily(e(FIREFOX)), 'firefox');
  assert.equal(helpFamily(e(MAC_SAFARI, 'MacIntel')), 'safari');
  assert.equal(helpFamily(e(IPHONE, 'iPhone', 5)), 'ios');
  assert.equal(helpFamily(e('curl/8')), 'chrome');
  assert.deepEqual(helpOrder('firefox'), ['firefox', 'chrome', 'safari', 'ios']);
  assert.equal(new Set(helpOrder('ios')).size, 4);
});

test('parseStoredToken: only a complete record counts', () => {
  assert.deepEqual(parseStoredToken('{"token":"t","userId":"u","at":5}'), { token: 't', userId: 'u', at: 5 });
  assert.equal(parseStoredToken('{"token":"t","userId":"u"}').at, 0);
  for (const bad of [null, '', 'not json', '{}', '{"token":"","userId":"u"}', '{"token":"t"}', '[]']) assert.equal(parseStoredToken(bad), null);
});
test('needsRegistration: new token, other customer, or a week old', () => {
  const stored = { token: 't', userId: 'u', at: 1_000 };
  assert.equal(needsRegistration(null, 't', 'u', 2_000), true);
  assert.equal(needsRegistration(stored, 't', 'u', 2_000), false);
  assert.equal(needsRegistration(stored, 't2', 'u', 2_000), true);
  assert.equal(needsRegistration(stored, 't', 'u2', 2_000), true);
  assert.equal(needsRegistration(stored, 't', 'u', 1_000 + REREGISTER_AFTER_MS - 1), false);
  assert.equal(needsRegistration(stored, 't', 'u', 1_000 + REREGISTER_AFTER_MS), true);
});

test('opted-in users: parse, add once, remove, cap', () => {
  assert.deepEqual(parseUsers(null), []);
  assert.deepEqual(parseUsers('garbage'), []);
  assert.deepEqual(parseUsers('{"a":1}'), []);
  assert.deepEqual(parseUsers('["a",1,"","b"]'), ['a', 'b']);
  assert.deepEqual(addUser(['a'], 'a'), ['a']);
  assert.deepEqual(addUser(['a'], 'b'), ['a', 'b']);
  assert.deepEqual(removeUser(['a', 'b'], 'a'), ['b']);
  const many = Array.from({ length: 25 }, (_, i) => `u${i}`);
  assert.equal(addUser(many.slice(0, 20), 'new').length, 20);
  assert.equal(addUser(many.slice(0, 20), 'new').at(-1), 'new');
});

test('isDismissed: 14 days from the click', () => {
  const day = 86_400_000;
  const now = 100 * day;
  assert.equal(isDismissed(null, now), false);
  assert.equal(isDismissed('', now), false);
  assert.equal(isDismissed('abc', now), false);
  assert.equal(isDismissed(String(now - 1000), now), true);
  assert.equal(isDismissed(String(now - 13 * day), now), true);
  assert.equal(isDismissed(String(now - 14 * day), now), false);
  assert.equal(isDismissed(String(now - 30 * day), now), false);
});
test('promptApplies: only where the customer can act', () => {
  assert.equal(promptApplies('default', false), true);
  assert.equal(promptApplies('ios_needs_install', false), true);
  for (const s of ['unsupported', 'disabled_by_admin', 'denied', 'granted', 'error']) assert.equal(promptApplies(s, false), false, s);
  assert.equal(promptApplies('default', true), false);
});

test('normalizeMessage: data keys win, notification block is the fallback, empty is dropped', () => {
  assert.deepEqual(
    normalizeMessage({ notification: { title: 'N', body: 'NB' }, data: { title: 'T', body: 'B', order_id: '7', kind: 'order_progress', step: '2', phase: 'on_the_way', deep_link: '/orders/7' } }),
    { title: 'T', body: 'B', orderId: '7', kind: 'order_progress' });
  assert.deepEqual(normalizeMessage({ notification: { title: 'N', body: 'NB' } }), { title: 'N', body: 'NB', orderId: null, kind: null });
  assert.deepEqual(normalizeMessage({ data: { title: 'Only title' } }), { title: 'Only title', body: '', orderId: null, kind: null });
  for (const bad of [null, undefined, {}, { data: {} }, { notification: {} }, 'x', 5]) assert.equal(normalizeMessage(bad), null);
});

test('createDedupe: another source inside the window is a duplicate; the same source is not', () => {
  const d = createDedupe(3000);
  assert.equal(d.claim('push', 'o1', 1000), true);
  assert.equal(d.claim('socket', 'o1', 1400), false); // the socket repeats what the push just showed
  assert.equal(d.claim('push', 'o1', 1800), true); // a second real change on the same channel still shows
  assert.equal(d.claim('socket', 'o1', 2500), false);
  assert.equal(d.claim('socket', 'o2', 2500), true); // other orders are independent
  assert.equal(d.claim('socket', 'o1', 4800), true); // window over (last claim was at 1800 + 3000)
});
test('createDedupe: works in either order and never blocks the first signal', () => {
  const d = createDedupe(3000);
  assert.equal(d.claim('socket', 'o1', 0), true);
  assert.equal(d.claim('push', 'o1', 200), false);
  assert.equal(d.claim('socket', 'o1', 900), true);
});
