import { test } from 'node:test';
import assert from 'node:assert/strict';
import { STALE_MIN_S, clockText, isStale, linkState, linkText, pollSeconds, welcome } from '../../resources/js/ops-board-core.js';

const poll = { live: 30, down: 10 };
const text = { live: 'Live', reconnecting: 'Reconnecting…', polling: 'Updating every :s s', stale: 'Not updating' };

test('linkState: live only while the socket is connected; no socket or a refused channel means timer polling', () => {
    assert.equal(linkState({ hasEcho: false }), 'polling');
    assert.equal(linkState({ hasEcho: true, state: 'connected' }), 'live');
    assert.equal(linkState({ hasEcho: true, state: 'connected', joined: 3, failed: 1 }), 'live');
    assert.equal(linkState({ hasEcho: true, state: 'connected', joined: 3, failed: 3 }), 'polling');
    for (const state of ['connecting', 'initialized', 'unavailable', 'disconnected']) assert.equal(linkState({ hasEcho: true, state }), 'reconnecting', state);
    assert.equal(linkState({ hasEcho: true, state: 'failed' }), 'polling');
    assert.equal(linkState({ hasEcho: true }), 'reconnecting');
});

test('the timer is slow when live and quick otherwise', () => {
    assert.equal(pollSeconds('live', poll), 30);
    assert.equal(pollSeconds('reconnecting', poll), 10);
    assert.equal(pollSeconds('polling', poll), 10);
});

test('stale: not re-drawn for three polls (and at least a minute)', () => {
    const t0 = 1_000_000;
    assert.equal(isStale(t0, t0 + 10_000, 'polling', poll), false);
    assert.equal(isStale(t0, t0 + STALE_MIN_S * 1000, 'polling', poll), false);             // exactly a minute is still fine
    assert.equal(isStale(t0, t0 + STALE_MIN_S * 1000 + 1, 'polling', poll), true);
    assert.equal(isStale(t0, t0 + 80_000, 'live', poll), false);                           // live polls every 30 s: 90 s before it is late
    assert.equal(isStale(t0, t0 + 91_000, 'live', poll), true);
});

test('the indicator says live, reconnecting, the polling interval, or that it is not updating', () => {
    assert.equal(linkText('live', false, text, poll), 'Live');
    assert.equal(linkText('reconnecting', false, text, poll), 'Reconnecting…');
    assert.equal(linkText('polling', false, text, poll), 'Updating every 10 s');
    assert.equal(linkText('live', true, text, poll), 'Not updating');
});

test('welcome: the first look learns, later looks welcome only new orders and cards that just went wrong', () => {
    const first = welcome(null, [{ id: 'a', lane: 'new' }, { id: 'b', lane: 'preparing' }]);
    assert.deepEqual(first.fresh, []);
    assert.equal(first.seen.size, 2);

    const second = welcome(first.seen, [{ id: 'a', lane: 'new' }, { id: 'b', lane: 'ready' }, { id: 'c', lane: 'new' }, { id: 'd', lane: 'preparing' }, { id: 'e', lane: 'attention' }]);
    assert.deepEqual(second.fresh, ['c', 'e']);                                              // c is a new order, e arrived broken; d only appeared mid-way (not announced), b only moved on

    const third = welcome(second.seen, [{ id: 'a', lane: 'attention' }, { id: 'b', lane: 'ready' }, { id: 'c', lane: 'new' }, { id: 'e', lane: 'attention' }]);
    assert.deepEqual(third.fresh, ['a']);                                                    // a turned red: welcomed once; e was already there
    const fourth = welcome(third.seen, [{ id: 'a', lane: 'attention' }]);
    assert.deepEqual(fourth.fresh, []);
    assert.equal(welcome(new Map(), []).fresh.length, 0);
});

test('welcome: a card that leaves and comes back to the board counts as new again', () => {
    const a = welcome(new Map([['x', 'new']]), []);
    const b = welcome(a.seen, [{ id: 'x', lane: 'new' }]);
    assert.deepEqual(b.fresh, ['x']);
});

test('the shop clock uses Qatar time and Latin digits in both languages', () => {
    const noon = new Date('2026-11-19T09:05:00Z');
    assert.equal(clockText(noon, 'en'), '12:05');
    assert.equal(clockText(noon, 'ar'), '12:05');
    assert.equal(clockText(noon, 'ar-QA'), '12:05');
    assert.equal(clockText(noon, 'en', 'Not/AZone'), '');
});
