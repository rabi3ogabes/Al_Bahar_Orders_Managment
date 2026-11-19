import test from 'node:test';
import assert from 'node:assert/strict';
import { backoffMs, createLock, createRefresher } from '../src/lib/sessionSync.ts';

// Two browser tabs share one saved sign-in and one server that rotates the refresh token and punishes a used one coming back.

const tok = (n) => ({ access_token: `access-${n}`, refresh_token: `refresh-${n}`, token_type: 'Bearer', expires_in: 3600, session_id: 's1' });

/** The server: refresh tokens work once. A second use of an old one is "theft" and ends the whole sign-in. */
function fakeServer(first = 1) {
  const s = { current: first, used: new Set(), revoked: false, calls: 0, mode: 'ok' /* ok | down | busy | refuse | forbidden | garbage */, delay: 0 };
  s.refresh = async (refreshToken) => {
    s.calls += 1;
    if (s.delay) await new Promise((r) => setTimeout(r, s.delay));
    if (s.mode === 'down') return { kind: 'transient' };
    if (s.mode === 'busy') return { kind: 'transient' };
    if (s.mode === 'refuse' || s.mode === 'forbidden') return { kind: 'invalid' };
    if (s.revoked) return { kind: 'invalid' };
    if (s.used.has(refreshToken)) { s.revoked = true; return { kind: 'invalid' }; }
    if (refreshToken !== `refresh-${s.current}`) return { kind: 'invalid' };
    s.used.add(refreshToken);
    s.current += 1;
    return { kind: 'ok', tokens: tok(s.current) };
  };
  return s;
}

/** The Web Locks API in miniature: one holder at a time, the rest wait their turn (shared by every tab). */
function fakeLocks() {
  let tail = Promise.resolve();
  return { request: (_name, run) => { const mine = tail.then(run); tail = mine.then(() => undefined, () => undefined); return mine; } };
}

/** A tab: its own memory, the shared saved tokens, the shared lock. */
function tab(shared, server, locks, clock = { t: 1_000_000 }) {
  const t = { memory: shared.saved, applied: [] };
  t.apply = (next) => { t.applied.push(next); t.memory = next; shared.saved = next; };
  t.refresher = createRefresher({ stored: () => shared.saved, apply: t.apply, attempt: server.refresh, withLock: (run) => locks.request('x', run), now: () => clock.t });
  return t;
}

test('two tabs whose access token expired together refresh once, and both end signed in with the same new tokens', async () => {
  const server = fakeServer(1); server.delay = 10;
  const shared = { saved: tok(1) };
  const locks = fakeLocks();
  const a = tab(shared, server, locks); const b = tab(shared, server, locks);

  const [ra, rb] = await Promise.all([a.refresher.refresh('access-1'), b.refresher.refresh('access-1')]);

  assert.deepEqual([ra, rb], ['ok', 'ok']);
  assert.equal(server.calls, 1, 'only one refresh reached the server');
  assert.equal(server.revoked, false, 'the server never saw an old refresh token again');
  assert.equal(shared.saved.access_token, 'access-2');
  assert.equal(a.memory.access_token, 'access-2');
  assert.equal(b.memory.access_token, 'access-2');
});

test('the same tab asking three times at once shares one refresh', async () => {
  const server = fakeServer(1); server.delay = 10;
  const shared = { saved: tok(1) };
  const a = tab(shared, server, fakeLocks());

  const out = await Promise.all([a.refresher.refresh('access-1'), a.refresher.refresh('access-1'), a.refresher.refresh('access-1')]);

  assert.deepEqual(out, ['ok', 'ok', 'ok']);
  assert.equal(server.calls, 1);
});

test('a request that was refused with an older token is answered from the newer saved tokens, with nothing sent', async () => {
  const server = fakeServer(2);
  const shared = { saved: tok(2) }; // another tab already refreshed
  const a = tab(shared, server, fakeLocks());

  assert.equal(await a.refresher.refresh('access-1'), 'ok');
  assert.equal(server.calls, 0);
  assert.equal(a.memory.access_token, 'access-2');
});

test('when the tab that held the lock is done, the waiting tab sees its tokens and does not refresh again', async () => {
  const server = fakeServer(1); server.delay = 20;
  const shared = { saved: tok(1) };
  const locks = fakeLocks();
  const a = tab(shared, server, locks); const b = tab(shared, server, locks);
  const first = a.refresher.refresh('access-1');
  const second = b.refresher.refresh('access-1'); // asks while A is still on the network

  assert.deepEqual(await Promise.all([first, second]), ['ok', 'ok']);
  assert.equal(server.calls, 1);
  // A third request that had been sent with the very first token and only now comes back refused is answered the same way.
  assert.equal(await a.refresher.refresh('access-1'), 'ok');
  assert.equal(server.calls, 1);
});

test('signing out in another tab ends it here too, without asking the server', async () => {
  const server = fakeServer(1);
  const shared = { saved: null }; // the other tab signed out
  const a = tab(shared, server, fakeLocks());
  a.memory = tok(1);

  assert.equal(await a.refresher.refresh('access-1'), 'invalid');
  assert.equal(server.calls, 0);
  assert.equal(a.memory, null);
});

test('only a clear refusal (401 or 403) from the refresh call signs the person out', async () => {
  for (const mode of ['refuse', 'forbidden']) {
    const server = fakeServer(1); server.mode = mode;
    const shared = { saved: tok(1) };
    const a = tab(shared, server, fakeLocks());

    assert.equal(await a.refresher.refresh('access-1'), 'invalid', mode);
    assert.equal(shared.saved, null, mode);
  }
});

test('a dropped connection, a busy server or a deploy keeps the sign-in and waits longer each time', async () => {
  const server = fakeServer(1); server.mode = 'down';
  const shared = { saved: tok(1) };
  const clock = { t: 1_000_000 };
  const a = tab(shared, server, fakeLocks(), clock);

  assert.equal(await a.refresher.refresh('access-1'), 'transient');
  assert.equal(shared.saved.access_token, 'access-1', 'tokens are kept');
  assert.equal(a.applied.length, 0, 'nothing was changed or cleared');
  assert.equal(server.calls, 1);

  // Inside the wait the server is left alone.
  clock.t += 1_000;
  assert.equal(await a.refresher.refresh('access-1'), 'transient');
  assert.equal(server.calls, 1);

  // After the first wait (2 s) it tries again: still down, so the next wait is 4 s.
  clock.t += 1_500;
  assert.equal(await a.refresher.refresh('access-1'), 'transient');
  assert.equal(server.calls, 2);
  clock.t += 3_000;
  assert.equal(await a.refresher.refresh('access-1'), 'transient');
  assert.equal(server.calls, 2, '3 s later is still inside the 4 s wait');

  // The server is back: the next try after the wait works and the person never noticed.
  server.mode = 'ok';
  clock.t += 2_000;
  assert.equal(await a.refresher.refresh('access-1'), 'ok');
  assert.equal(server.calls, 3);
  assert.equal(shared.saved.access_token, 'access-2');
  assert.equal(server.revoked, false);
});

test('the wait grows 2, 4, 8, 16, 30 seconds and stops there', () => {
  assert.deepEqual([1, 2, 3, 4, 5, 6, 20].map(backoffMs), [2_000, 4_000, 8_000, 16_000, 30_000, 30_000, 30_000]);
});

test('a refresh that fails is not retried by a second tab inside its own wait either: the lock hands out one try at a time', async () => {
  const server = fakeServer(1); server.mode = 'busy'; server.delay = 10;
  const shared = { saved: tok(1) };
  const locks = fakeLocks();
  const a = tab(shared, server, locks); const b = tab(shared, server, locks);

  const out = await Promise.all([a.refresher.refresh('access-1'), b.refresher.refresh('access-1')]);

  assert.deepEqual(out, ['transient', 'transient']);
  assert.equal(shared.saved.access_token, 'access-1');
});

// ── The lock ─────────────────────────────────────────────────────────────────────────────────────────────────────────

const memoryLease = () => { const m = new Map(); return { get: (k) => m.get(k) ?? null, set: (k, v) => void m.set(k, v), remove: (k) => void m.delete(k), m }; };
const realClock = (id) => ({ now: Date.now, sleep: (ms) => new Promise((r) => setTimeout(r, ms)), random: Math.random, id });

test('the Web Locks API is used when the browser has it', async () => {
  const seen = [];
  const lock = createLock({ locks: { request: (name, run) => { seen.push(name); return run(); } }, lease: null, ...realClock('a') });
  assert.equal(await lock(async () => 7), 7);
  assert.equal(seen.length, 1);
});

test('without it a lease in storage lets only one tab work at a time', async () => {
  const lease = memoryLease();
  const a = createLock({ locks: null, lease, ...realClock('a') });
  const b = createLock({ locks: null, lease, ...realClock('b') });
  let inside = 0; let overlap = false; const order = [];
  const work = (name) => async () => { inside += 1; overlap ||= inside > 1; order.push(`${name}+`); await new Promise((r) => setTimeout(r, 60)); order.push(`${name}-`); inside -= 1; };

  await Promise.all([a(work('a')), b(work('b'))]);

  assert.equal(overlap, false);
  assert.equal(order.length, 4);
  assert.equal(order[0].slice(1), '+'); assert.equal(order[1].slice(1), '-'); // one finishes before the other starts
  assert.equal(lease.m.size, 0, 'the lease is given back');
});

test('a lease left by a tab that closed runs out and is taken over', async () => {
  const lease = memoryLease();
  lease.set('albahar.session.refresh.lease', JSON.stringify({ owner: 'dead-tab', until: Date.now() - 1 }));
  const a = createLock({ locks: null, lease, ...realClock('a') });
  assert.equal(await a(async () => 'done'), 'done');
});

test('a tab never waits for ever on a holder that does not let go', async () => {
  const lease = memoryLease();
  lease.set('albahar.session.refresh.lease', JSON.stringify({ owner: 'stuck', until: Date.now() + 3_600_000 }));
  let skew = 0;
  const a = createLock({ locks: null, lease, now: () => Date.now() + skew, sleep: async () => { skew += 30_000; }, random: () => 0, id: 'a' });
  assert.equal(await a(async () => 'went on'), 'went on');
  assert.ok(lease.get('albahar.session.refresh.lease').includes('stuck'), 'the other tab\'s lease is left alone');
});

test('with no lock and no storage the work just runs', async () => {
  const a = createLock({ locks: null, lease: null, ...realClock('a') });
  assert.equal(await a(async () => 1), 1);
});
