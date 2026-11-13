// One overlay stack for the whole dashboard.
//
// Every open side window (<x-admin.dialog>), decision modal and themed confirm registers here. That gives the same behaviour everywhere:
//   - Esc and the browser/phone Back button close exactly the TOP overlay, never the page behind it;
//   - each overlay owns one history entry, so Back is "close the window" instead of "leave the page";
//   - a wire:navigate (or a redirect after Save) from inside a window first closes the windows and removes their history entries,
//     so the new page does not leave a stale entry behind and the page scroll is never left locked;
//   - the toast area listens to the `overlays` event so it can move out of the way of a window's pinned action row.
//
// Livewire keeps its own data under history.state.alpine; we only add `__ov` (our depth) and always copy the rest of the state.

const stack = []; // open overlays, bottom -> top
const hist = []; // history entries we pushed and have not removed yet, bottom -> top: { depth, open, close }
let pending = 0;
let timer = null;

const actual = () => (window.history.state && window.history.state.__ov) || 0;
const announce = () => window.dispatchEvent(new CustomEvent('overlays', { detail: { count: stack.length } }));

/** Removes the history entries of closed overlays that sit on top of the history (one batched step back). */
function trim() {
    let n = 0;
    while (hist.length && !hist[hist.length - 1].open) {
        hist.pop();
        n++;
    }
    if (n) {
        pending += n;
        timer ??= setTimeout(() => {
            const steps = pending;
            pending = 0;
            timer = null;
            if (steps > 0) window.history.go(-steps);
        }, 0);
    }
}

/** The browser went back (or forward): overlays whose entry is gone are closed, top first. */
function sync() {
    const depth = actual();
    while (hist.length && hist[hist.length - 1].depth > depth) {
        const entry = hist.pop();
        if (entry.open) {
            entry.open = false;
            const i = stack.indexOf(entry);
            if (i > -1) stack.splice(i, 1);
            entry.close();
        }
    }
    announce();
    trim();
}

/**
 * Registers an open overlay. `close` must hide it (it is called on Esc/Back/navigation). `initial` is true when the page was
 * rendered with the window already open (reload or a shared ?view= link): it re-claims the history entry it was opened on.
 */
function open({ close, initial = false }) {
    const entry = { open: true, close, depth: 0 };
    if (initial && !hist.length && actual() > 0) {
        entry.depth = actual();
    } else {
        entry.depth = (hist.length ? hist[hist.length - 1].depth : actual()) + 1;
        window.history.pushState({ ...(window.history.state || {}), __ov: entry.depth }, '');
    }
    hist.push(entry);
    stack.push(entry);
    announce();

    return entry;
}

/** The overlay was closed by the page itself (✕, scrim, Cancel, a finished Save): drop it and its history entry. */
function release(entry) {
    if (!entry || !entry.open) return;
    entry.open = false;
    const i = stack.indexOf(entry);
    if (i > -1) stack.splice(i, 1);
    announce();
    trim();
}

/** Closes every overlay (top first) and resolves once the history has settled, so a navigation can start cleanly. */
function closeAll() {
    const list = stack.slice().reverse();
    list.forEach((entry) => entry.close());
    list.forEach(release);

    return new Promise((resolve) => {
        if (!pending) return setTimeout(resolve, 0);
        const done = () => { window.removeEventListener('popstate', done); resolve(); };
        window.addEventListener('popstate', done);
        setTimeout(done, 800);
    });
}

window.addEventListener('popstate', sync);

// Esc closes the top overlay only.
window.addEventListener('keydown', (e) => {
    if (e.key !== 'Escape' || e.defaultPrevented || e.isComposing || !stack.length) return;
    e.preventDefault();
    stack[stack.length - 1].close();
});

// wire:navigate and redirects: close the windows first, then go (the history never keeps a window's entry).
document.addEventListener('livewire:navigate', (e) => {
    if (e.detail?.history || !stack.length) return;
    e.preventDefault();
    const url = e.detail.url?.href;
    closeAll().then(() => (url ? window.Livewire.navigate(url) : null));
});

// A reload (or Forward) can leave an overlay marker on the current entry with nothing open: remove it so Back is not a no-op.
const sweep = () => {
    if (!hist.length && actual() > 0) {
        const { __ov, ...rest } = window.history.state;
        window.history.replaceState(rest, '');
    }
};
document.addEventListener('livewire:initialized', () => setTimeout(sweep, 0));
document.addEventListener('livewire:navigated', () => setTimeout(sweep, 0));

window.Overlays = { open, release, closeAll, count: () => stack.length };
