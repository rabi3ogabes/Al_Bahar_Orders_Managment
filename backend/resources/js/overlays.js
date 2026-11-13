// One overlay stack for the whole dashboard.
//
// Every open side window (<x-admin.dialog>), decision modal and themed confirm registers here. That gives the same behaviour everywhere:
//   - Esc and the browser/phone Back button close exactly the TOP overlay, never the page behind it;
//   - each overlay owns one history entry, so Back is "close the window" instead of "leave the page";
//   - a wire:navigate (or a redirect after Save) from inside a window first closes the windows and removes their history entries,
//     so the new page does not leave a stale entry behind and the page scroll is never left locked. A window with unsaved input asks
//     "Discard changes?" first;
//   - the toast area listens to the `overlays` event so it can move out of the way of a window's pinned action row.
//
// Livewire keeps its own data under history.state.alpine; we only add `__ov` (our depth) and always copy the rest of the state.

const stack = []; // open overlays, bottom -> top
const hist = []; // history entries we pushed and have not removed yet, bottom -> top: { id, depth, open, close, dirty }
let seq = 0; // callers keep only the numeric id (Alpine would wrap an object in a reactive proxy that no longer matches ours)
let pending = 0;
let timer = null;

const actual = () => (window.history.state && window.history.state.__ov) || 0;
const announce = () => window.dispatchEvent(new CustomEvent('overlays', { detail: { count: stack.length } }));
const isArabic = () => document.documentElement.lang.startsWith('ar');

// ── Server activity: a window that closes because a request just finished (Save) is not a user "close".
let inflight = 0;
let lastActivity = 0;
document.addEventListener('livewire:init', () => {
    window.Livewire.hook('commit', ({ respond, succeed, fail }) => {
        inflight++;
        let done = false;
        const end = () => {
            if (!done) { done = true; inflight = Math.max(0, inflight - 1); }
            lastActivity = performance.now();
        };
        respond(end);
        succeed(() => {
            end();
            window.dispatchEvent(new Event('lw-settled')); // the DOM is up to date: windows can look for validation errors
        });
        fail(end);
    });
});
/** True while a Livewire request is running or just finished (a state change right now came from the server). */
const serverBusy = () => inflight > 0 || performance.now() - lastActivity < 250;

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
    // Take the vanished entries out first: closing one may open a new entry (a dirty window re-claims its place), and that one must stay.
    const gone = [];
    while (hist.length && hist[hist.length - 1].depth > depth) gone.push(hist.pop());
    gone.forEach((entry) => {
        if (!entry.open) return;
        entry.open = false;
        const i = stack.indexOf(entry);
        if (i > -1) stack.splice(i, 1);
        entry.close(false);
    });
    announce();
    trim();
}

/**
 * Registers an open overlay and returns its id. `close(force)` must hide it (it is called on Esc/Back/navigation; a window with unsaved
 * input may ask first unless `force`). `dirty()` says whether it holds unsaved input. `initial` is true when the page was rendered with
 * the window already open (reload or a shared ?view= link): it re-claims the history entry it was opened on.
 */
function open({ close, dirty = () => false, initial = false }) {
    const entry = { id: ++seq, open: true, close, dirty, depth: 0 };
    if (initial && !hist.length && actual() > 0) {
        entry.depth = actual();
    } else if (pending > 0) {
        // A window was just closed in this same moment (a Save that closes an editor and opens the new record): its history step has not
        // been taken yet, so the new window takes over that entry instead of pushing another one.
        entry.depth = (hist.length ? hist[hist.length - 1].depth : actual() - pending) + 1;
        pending--;
    } else {
        entry.depth = (hist.length ? hist[hist.length - 1].depth : actual()) + 1;
        window.history.pushState({ ...(window.history.state || {}), __ov: entry.depth }, '');
    }
    hist.push(entry);
    stack.push(entry);
    announce();

    return entry.id;
}

const find = (id) => hist.find((h) => h.id === id) || stack.find((h) => h.id === id);
const isOpen = (id) => !!find(id)?.open;

/** The overlay was closed by the page itself (✕, scrim, Cancel, a finished Save): drop it and its history entry. */
function release(id) {
    const entry = find(id);
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
    list.forEach((entry) => entry.close(true));
    list.forEach((entry) => release(entry.id));

    // Wait for the history step and for the windows' slide-out (200 ms) so the page swap never runs under a half-hidden window.
    return new Promise((resolve) => {
        const settle = () => setTimeout(resolve, list.length ? 230 : 0);
        if (!pending) return settle();
        const done = () => { window.removeEventListener('popstate', done); settle(); };
        window.addEventListener('popstate', done);
        setTimeout(done, 800);
    });
}

/**
 * Themed confirmation (question + Cancel + the action's own verb; Cancel is focused, Esc/Back/scrim cancel). It is an overlay too, so it
 * works above a side window. Resolves true on the action, false otherwise.
 */
function confirmDialog({ question, ok, cancel, danger = false }) {
    return new Promise((resolve) => {
        const opener = document.activeElement;
        const wrap = document.createElement('div');
        wrap.className = 'fixed inset-0 z-[90] flex items-end justify-center p-4 sm:items-center';
        wrap.setAttribute('role', 'alertdialog');
        wrap.setAttribute('aria-modal', 'true');
        wrap.innerHTML = '<div class="absolute inset-0 bg-ink-950/60 backdrop-blur-sm" data-x="cancel"></div>'
            + '<div class="card relative w-full max-w-md p-6 shadow-[var(--shadow-lift)] outline-none" tabindex="-1" x-trap.noscroll.noautofocus="true"><p class="font-display text-xl font-semibold text-ink-900 dark:text-ivory" data-q></p>'
            + `<div class="mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end"><button type="button" class="btn-secondary min-h-11" data-x="cancel" data-cancel></button><button type="button" class="${danger ? 'btn-danger' : 'btn-primary'} min-h-11" data-x="ok"></button></div></div>`;
        wrap.querySelector('[data-q]').textContent = question;
        const cancelBtn = wrap.querySelector('[data-cancel]');
        const okBtn = wrap.querySelector('[data-x=ok]');
        cancelBtn.textContent = cancel || (isArabic() ? 'إلغاء' : 'Cancel');
        okBtn.textContent = ok || (isArabic() ? 'تأكيد' : 'Confirm');
        wrap.setAttribute('aria-label', question);

        let id = null;
        let settled = false;
        const onKey = (k) => {
            if (k.key === 'Tab') { k.preventDefault(); (document.activeElement === okBtn ? cancelBtn : okBtn).focus(); }
        };
        const finish = (yes) => {
            if (settled) return;
            settled = true;
            document.removeEventListener('keydown', onKey, true);
            window.Alpine.destroyTree(wrap); // releases the focus trap and the scroll lock (focus goes back to the opener)
            wrap.remove();
            release(id);
            opener?.focus?.();
            resolve(yes);
        };
        wrap.addEventListener('click', (c) => {
            const t = c.target.closest('[data-x]')?.dataset.x;
            if (t) finish(t === 'ok');
        });
        document.addEventListener('keydown', onKey, true);
        document.body.appendChild(wrap);
        id = open({ close: () => finish(false) });
        window.Alpine.initTree(wrap); // its own focus trap pauses the side window's trap underneath
        setTimeout(() => cancelBtn.focus(), 40);
    });
}

window.addEventListener('popstate', sync);

// Esc closes the top overlay only.
window.addEventListener('keydown', (e) => {
    if (e.key !== 'Escape' || e.defaultPrevented || e.isComposing || !stack.length) return;
    e.preventDefault();
    stack[stack.length - 1].close(false);
});

// wire:navigate and redirects: close the windows first (asking before unsaved input is thrown away), then go. The history never keeps a window's entry.
document.addEventListener('livewire:navigate', (e) => {
    if (e.detail?.history || !stack.length) return;
    e.preventDefault();
    const url = e.detail.url?.href;
    const unsaved = stack.some((entry) => entry.dirty());
    const proceed = () => closeAll().then(() => (url ? window.Livewire.navigate(url) : null));
    if (!unsaved) return proceed();
    confirmDialog({
        question: isArabic() ? 'تجاهل التغييرات غير المحفوظة؟' : 'Discard your changes?',
        ok: isArabic() ? 'تجاهل' : 'Discard',
        cancel: isArabic() ? 'متابعة التعديل' : 'Keep editing',
        danger: true,
    }).then((yes) => (yes ? proceed() : null));
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

window.Overlays = { open, release, closeAll, isOpen, confirm: confirmDialog, serverBusy, count: () => stack.length };
