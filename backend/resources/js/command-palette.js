// The dashboard's global search (Ctrl/Cmd+K, "/" and the Search button in the top bar).
// Pure logic is in command-palette-core.js (tested with `npm test`); this file wires it to the page with Alpine.
// All text from the server is set with x-text (never parsed as HTML); the only x-html is the icon markup the server itself draws from its own icon table.
import { createSearchLoop, flatten, isOpenShortcut, isPlainClick, isSlashShortcut, recentSearches, step } from './command-palette-core.js';

const storage = () => {
    try { return window.localStorage; } catch { return null; }
};

const fetchJson = async (url, signal) => {
    const res = await fetch(url, { signal, credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
    let body = null;
    try { body = await res.json(); } catch { /* a login page or an error page is not an answer */ }
    if (res.status === 429) body = { retry_after: res.headers.get('Retry-After') };

    return { status: res.status, body };
};

document.addEventListener('alpine:init', () => {
    window.Alpine.data('commandPalette', (boot) => ({
        open: false,
        q: '',
        /** idle | short | results | throttled | error */
        phase: 'idle',
        busy: false,
        slow: false,
        state: 'ready',
        view: [],
        rows: [],
        active: -1,
        recent: [],
        announce: '',
        text: boot.text,
        handle: null,
        opener: null,
        loop: null,
        store: null,

        init() {
            this.store = recentSearches(storage(), boot.user);
            this.loop = createSearchLoop({ url: boot.url, fetchJson, emit: (e) => this.apply(e) });
        },

        destroy() {
            this.loop?.stop();
            if (this.handle !== null) window.Overlays?.release(this.handle);
        },

        /** Ctrl/Cmd+K opens it, and closes it again when it is already open; "/" and the button only open. */
        toggle(e) {
            if (this.open && e?.detail?.toggle) this.hide();
            else if (!this.open) this.show();
        },

        show() {
            this.opener = document.activeElement;
            this.q = '';
            this.recent = this.store.list();
            this.reset('idle');
            this.open = true;
            this.handle = window.Overlays?.open({ close: () => this.hide() }) ?? null;
            this.$nextTick(() => setTimeout(() => this.$refs.input?.focus(), 30));
        },

        hide() {
            if (!this.open) return;
            this.open = false;
            this.loop.stop();
            if (this.handle !== null) window.Overlays?.release(this.handle);
            this.handle = null;
            const back = this.opener && document.contains(this.opener) ? this.opener : document.querySelector('[data-command-palette-trigger]');
            this.opener = null;
            setTimeout(() => back?.focus?.({ preventScroll: true }), 0);
        },

        reset(phase) {
            this.phase = phase;
            this.busy = false;
            this.slow = false;
            this.view = [];
            this.rows = [];
            this.active = -1;
            this.announce = '';
        },

        input() {
            this.loop.ask(this.q);
        },

        /** Enter and "Try again" ask at once instead of waiting for a pause. */
        askNow() {
            this.loop.ask(this.q, { now: true });
        },

        apply(e) {
            switch (e.type) {
                case 'idle': this.recent = this.store.list(); this.reset('idle'); break;
                case 'short': this.reset('short'); break;
                case 'loading': this.busy = true; if (this.phase !== 'results') this.phase = 'loading'; break;
                case 'slow': this.slow = this.busy; break;
                case 'throttled': this.busy = false; this.slow = false; this.phase = 'throttled'; this.view = []; this.rows = []; this.active = -1; break;
                case 'error': this.busy = false; this.slow = false; this.phase = 'error'; this.view = []; this.rows = []; this.active = -1; break;
                case 'results': {
                    this.busy = false;
                    this.slow = false;
                    this.phase = 'results';
                    this.state = e.state;
                    this.rows = flatten(e.groups);
                    this.view = e.groups.map((group, g) => ({ ...group, rows: this.rows.filter((r) => r.g === g) }));
                    this.active = this.rows.length ? 0 : -1;
                    const n = this.rows.filter((r) => r.kind === 'item').length;
                    this.announce = (n === 0 ? this.text.results_none : n === 1 ? this.text.results_one : this.text.results_many).replace(':count', String(n));
                    break;
                }
                default: break;
            }
        },

        key(e) {
            if (e.isComposing) return;
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                this.active = step(this.active, e.key === 'ArrowDown' ? 1 : -1, this.rows.length);
                this.$nextTick(() => document.getElementById(`cp-o-${this.active}`)?.scrollIntoView({ block: 'nearest' }));
            } else if (e.key === 'Enter') {
                e.preventDefault();
                const row = this.rows[this.active];
                if (row) this.pick(null, row);
                else this.askNow();
            }
        },

        /** Opens a result. A plain click or Enter goes there inside the dashboard (the side window of that record opens on arrival); a ctrl/cmd/shift click keeps the browser's own behaviour. */
        pick(e, row) {
            if (e && !isPlainClick(e)) return;
            e?.preventDefault();
            if (row.kind === 'item' && this.q.trim() !== '') this.store.add(this.q);
            const url = row.item.url;
            if (window.Livewire?.navigate) window.Livewire.navigate(url);
            else window.location.assign(url);
        },

        useRecent(q) {
            this.q = q;
            this.$refs.input?.focus();
            this.askNow();
        },

        forget(q) {
            this.recent = this.store.remove(q);
        },

        clearRecent() {
            this.recent = this.store.clear();
            this.$refs.input?.focus();
        },

        clearInput() {
            this.q = '';
            this.loop.ask('');
            this.$refs.input?.focus();
        },
    }));
});

// The two shortcuts work on every dashboard page, once, however many times the page is swapped by wire:navigate. Where there is no palette (the login page) nothing is taken from the browser.
window.addEventListener('keydown', (e) => {
    const toggle = isOpenShortcut(e);
    if (!toggle && !isSlashShortcut(e, document.activeElement)) return;
    if (!document.querySelector('[data-command-palette]')) return;
    e.preventDefault();
    window.dispatchEvent(new CustomEvent('open-command-palette', { detail: { toggle } }));
});
