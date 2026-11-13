import test from 'node:test';
import assert from 'node:assert/strict';
import {
  OverlayStack, depthOf, isOrderId, isSlug, openedByUs, readParam, stripOverlayParams, withDepth, withParam, withoutParam, URL_MARK,
} from '../src/lib/overlay.ts';

/** A browser history in miniature: entries, a cursor, and popstate that only fires when the test says the traversal finished. */
function fakeHistory() {
  const entries = [{ state: null }];
  let i = 0;
  let queued = 0;
  const listeners = [];
  const h = {
    get state() { return entries[i].state; },
    pushState(s) { entries.splice(i + 1); entries.push({ state: s }); i += 1; },
    replaceState(s) { entries[i] = { state: s }; },
    back() { queued += 1; },
    get length() { return entries.length; },
    get index() { return i; },
    onPop(fn) { listeners.push(fn); },
    /** Finish the traversals queued by `back()` (the page's own) one at a time. */
    settle() { while (queued > 0) { queued -= 1; i = Math.max(0, i - 1); listeners.forEach((f) => f()); } },
    /** The person presses Back. */
    userBack() { i = Math.max(0, i - 1); listeners.forEach((f) => f()); },
    /** The router follows a link: a new entry whose state knows nothing about our windows. */
    navigatePush(s) { entries.splice(i + 1); entries.push({ state: s }); i += 1; },
    navigateReplace(s) { entries[i] = { state: s }; },
  };
  return h;
}
function setup() {
  const h = fakeHistory();
  const stack = new OverlayStack(h);
  h.onPop(() => stack.handlePop());
  const closed = [];
  const open = (name) => { const e = stack.register(() => closed.push(name)); stack.push(e); return e; };
  return { h, stack, closed, open };
}

/* ───────────── URL parameters ───────────── */

test('withParam / withoutParam / readParam round-trip and keep other params', () => {
  assert.equal(withParam('', 'order', 'abc'), '?order=abc');
  assert.equal(withParam('?sort=price', 'quick', 'sofa'), '?sort=price&quick=sofa');
  assert.equal(withParam('?quick=a', 'quick', 'b'), '?quick=b');
  assert.equal(withoutParam('?sort=price&quick=sofa', 'quick'), '?sort=price');
  assert.equal(withoutParam('?quick=sofa', 'quick'), '');
  assert.equal(readParam('?quick=sofa', 'quick'), 'sofa');
  assert.equal(readParam('?quick=', 'quick'), null);
  assert.equal(readParam('', 'quick'), null);
});

test('stripOverlayParams drops only the window params', () => {
  assert.equal(stripOverlayParams('?category=sofas&quick=x&order=y'), '?category=sofas');
  assert.equal(stripOverlayParams('?order=y'), '');
  assert.equal(stripOverlayParams(''), '');
  assert.equal(stripOverlayParams('?q=a%20b'), '?q=a+b');
});

test('isOrderId / isSlug accept only what the API produces', () => {
  assert.ok(isOrderId('0198f2a4-7b3c-7a2e-9d1f-3c4b5a6d7e8f'));
  assert.ok(!isOrderId('../etc/passwd'));
  assert.ok(!isOrderId('1'));
  assert.ok(!isOrderId(null));
  assert.ok(isSlug('oak-dining-table'));
  assert.ok(isSlug('طاولة-طعام'));
  assert.ok(!isSlug('a/b'));
  assert.ok(!isSlug('<script>'));
  assert.ok(!isSlug(''));
  assert.ok(!isSlug('x'.repeat(200)));
});

test('openedByUs reads the router state mark', () => {
  assert.ok(openedByUs({ [URL_MARK]: 'order' }, 'order'));
  assert.ok(!openedByUs({ [URL_MARK]: 'order' }, 'quick'));
  assert.ok(!openedByUs({}, 'order'));
  assert.ok(!openedByUs(null, 'order'));
});

test('depthOf / withDepth keep router state and ignore junk', () => {
  assert.equal(depthOf(null), 0);
  assert.equal(depthOf({ idx: 3 }), 0);
  assert.equal(depthOf({ __ovDepth: -2 }), 0);
  assert.equal(depthOf({ __ovDepth: 2 }), 2);
  assert.deepEqual(withDepth({ idx: 3, key: 'k' }, 1), { idx: 3, key: 'k', __ovDepth: 1 });
});

/* ───────────── Back stack ───────────── */

test('open pushes exactly one entry; closing from the page takes it back out', () => {
  const { h, stack, open } = setup();
  const e = open('cart');
  assert.equal(h.length, 2);
  assert.equal(depthOf(h.state), 1);
  stack.release(e); // ✕ / Esc / scrim
  h.settle();
  assert.equal(h.index, 0);
  assert.equal(depthOf(h.state), 0);
  assert.equal(stack.size, 0);
});

test('Back closes the window and does not call onClose twice', () => {
  const { h, stack, closed, open } = setup();
  const e = open('cart');
  h.userBack();
  assert.deepEqual(closed, ['cart']);
  stack.release(e); // the component's cleanup after it closed itself
  h.settle();
  assert.equal(h.index, 0);
  assert.deepEqual(closed, ['cart']);
});

test('nested windows: Back closes only the top one, then the next', () => {
  const { h, stack, closed, open } = setup();
  const a = open('drawer');
  const b = open('confirm');
  assert.equal(depthOf(h.state), 2);
  h.userBack();
  assert.deepEqual(closed, ['confirm']);
  assert.equal(stack.size, 1);
  stack.release(b);
  h.settle();
  assert.equal(depthOf(h.state), 1);
  h.userBack();
  assert.deepEqual(closed, ['confirm', 'drawer']);
  stack.release(a);
  h.settle();
  assert.equal(h.index, 0);
});

test('closing the top by ✕ leaves the window below open and its entry in place', () => {
  const { h, stack, closed, open } = setup();
  open('drawer');
  const b = open('confirm');
  stack.release(b);
  h.settle();
  assert.deepEqual(closed, []);
  assert.equal(stack.size, 1);
  assert.equal(depthOf(h.state), 1);
});

test('StrictMode: opening, closing and reopening in one tick leaves one entry', () => {
  const { h, stack } = setup();
  const first = stack.register(() => {});
  stack.release(first); // cleanup before the tick
  const second = stack.register(() => {});
  stack.push(first); // the first timer was cleared in the hook; a stale call must still do nothing
  stack.push(second);
  assert.equal(h.length, 2);
  assert.equal(stack.size, 1);
});

test('closing before the entry was pushed never touches history', () => {
  const { h, stack } = setup();
  const e = stack.register(() => {});
  stack.release(e);
  stack.push(e);
  assert.equal(h.length, 1);
  assert.equal(h.index, 0);
});

test('a window opened while our own back() is still travelling waits for it (no instant close)', () => {
  const { h, stack, closed, open } = setup();
  const a = open('quick');
  stack.release(a); // quick view closes ...
  const b = stack.register(() => closed.push('cart')); // ... and the cart opens in the same tick
  stack.push(b);
  assert.equal(h.length, 2); // not pushed yet
  h.settle();
  assert.equal(h.length, 2);
  assert.equal(depthOf(h.state), 1); // pushed after the pop settled
  assert.equal(stack.size, 1);
  assert.deepEqual(closed, []);
  h.userBack();
  assert.deepEqual(closed, ['cart']);
});

test('a router link inside the window (replace) leaves the destination entry alone', () => {
  const { h, stack, open } = setup();
  const e = open('cart');
  h.navigateReplace({ usr: null, key: 'k2', idx: 1 }); // navigate(to, { replace: true }) swaps the marker entry for the page
  stack.release(e); // route change closes the cart
  h.settle();
  assert.equal(h.index, 1);
  assert.equal(depthOf(h.state), 0);
});

test('a router link with push leaves the old marker behind and does not step back', () => {
  const { h, stack, open } = setup();
  const e = open('menu');
  h.navigatePush({ usr: null, key: 'k3', idx: 2 });
  stack.release(e);
  h.settle();
  assert.equal(h.index, 2);
});

test('dirty form: Back keeps the window, a re-armed entry makes the next Back work again', () => {
  const { h, stack, closed } = setup();
  let askedLeave = 0;
  let e = stack.register(() => { askedLeave += 1; });
  stack.push(e);
  h.userBack(); // popped: the page asks "Discard changes?" instead of closing
  assert.equal(askedLeave, 1);
  stack.release(e); // effect re-runs with a new rearm key ...
  e = stack.register(() => { askedLeave += 1; });
  stack.push(e); // ... and pushes a fresh entry
  assert.equal(depthOf(h.state), 1);
  assert.equal(h.length, 2);
  h.userBack();
  assert.equal(askedLeave, 2);
  assert.deepEqual(closed, []);
});

test('a long jump back closes every window above it, top first', () => {
  const { h, closed, open } = setup();
  open('a');
  open('b');
  open('c');
  // history.go(-3)
  h.userBack(); h.userBack(); h.userBack();
  assert.deepEqual(closed, ['c', 'b', 'a']);
});

test('depth continues from the entry the router created', () => {
  const { h, open } = setup();
  h.navigatePush({ usr: null, key: 'x', idx: 1 });
  open('drawer');
  assert.deepEqual(h.state, { usr: null, key: 'x', idx: 1, __ovDepth: 1 });
});
