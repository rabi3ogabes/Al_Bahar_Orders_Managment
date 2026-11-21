// The live operations board (Operations > Live board): keeps the page fresh and welcomes new cards. Pure logic is in ops-board-core.js.
//
// Updates come from three places, none of them a full page load:
//  - the layout's order feed (resources/js/live-toasts.js) announces every live order event as a window event, "ops-order-live" (markup: x-on:ops-order-live.window);
//  - this component joins the branch channels the server picked for the person (private-branches.{id}: a part or delivery moved) and refreshes when one speaks;
//  - a timer: slow while the socket is connected, quick when it is not. The indicator says which, and turns red when the board stopped updating at all.
// Every refresh is `$wire.$refresh()`: Livewire morphs the lanes in place, so cards slide into place without a flicker.
// The new-order SOUND and TOAST are the layout's (once per order, on every page); this component only highlights the new card, so nothing is announced twice.
import { FRESH_MS, clockText, isStale, linkState, linkText, pollSeconds, welcome } from './ops-board-core.js';

const reduced = () => window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false;
const store = {
    get: (k) => { try { return localStorage.getItem(k); } catch { return null; } },
    set: (k, v) => { try { localStorage.setItem(k, v); } catch { /* private mode: the choice just is not remembered */ } },
};

document.addEventListener('alpine:init', () => {
    window.Alpine.data('opsBoard', (boot) => ({
        link: 'polling',
        stale: false,
        sound: false,
        fullscreen: false,
        clock: '',
        text: boot.text,
        poll: boot.poll,
        timer: null,
        tick: null,
        debounce: null,
        seen: null,
        observer: null,
        echo: null,
        joined: new Set(),
        failed: new Set(),
        lock: null,
        onState: null,
        onVisible: null,
        onFullscreen: null,

        get linkText() {
            return linkText(this.link, this.stale, this.text, this.poll);
        },

        init() {
            this.sound = store.get('live_toasts_sound') === '1';
            this.connect();
            this.arm();
            this.observer = new MutationObserver(() => this.look());
            this.observer.observe(this.$root, { childList: true, subtree: true });
            this.look();
            this.tick = setInterval(() => this.beat(), 1000);
            this.beat();
            this.onVisible = () => {
                if (document.hidden) return;
                this.kick(true);
                if (boot.wall) this.keepAwake();
            };
            document.addEventListener('visibilitychange', this.onVisible);
            this.onFullscreen = () => { this.fullscreen = document.fullscreenElement != null; };
            document.addEventListener('fullscreenchange', this.onFullscreen);
            if (boot.wall) this.keepAwake();
        },

        destroy() {
            clearInterval(this.timer);
            clearInterval(this.tick);
            clearTimeout(this.debounce);
            this.observer?.disconnect();
            document.removeEventListener('visibilitychange', this.onVisible);
            document.removeEventListener('fullscreenchange', this.onFullscreen);
            this.disconnect();
            this.lock?.release?.().catch(() => {});
        },

        // ── The socket ─────────────────────────────────────────────────────────────────────────────────────────
        connect() {
            this.echo = typeof window.echo === 'function' ? window.echo() : null;
            const connection = this.echo?.connector?.pusher?.connection;
            this.onState = () => this.report();
            connection?.bind('state_change', this.onState);
            for (const name of boot.channels ?? []) {
                if (!this.echo) break;
                this.joined.add(name);
                const channel = this.echo.private(name);
                channel.listen('.dispatch.updated', () => this.kick());
                channel.error?.(() => { this.failed.add(name); this.report(); });
            }
            this.report();
        },

        disconnect() {
            this.echo?.connector?.pusher?.connection?.unbind('state_change', this.onState);
            for (const name of this.joined) {
                try { this.echo?.leave(name); } catch { /* the socket is already closed */ }
            }
            this.joined.clear();
            this.failed.clear();
        },

        report() {
            const next = linkState({ hasEcho: this.echo !== null, state: this.echo?.connector?.pusher?.connection?.state, joined: this.joined.size, failed: this.failed.size });
            if (next === this.link) return;
            this.link = next;
            this.arm();
        },

        // ── Refreshing ─────────────────────────────────────────────────────────────────────────────────────────
        /** (Re)starts the timer for the current link. Nothing is fetched while the tab is hidden; it is caught up when the tab is shown again. */
        arm() {
            clearInterval(this.timer);
            this.timer = setInterval(() => { if (!document.hidden) this.$wire.$refresh(); }, pollSeconds(this.link, this.poll) * 1000);
        },

        /** Something moved: refresh soon, once for a burst (or at once with `now`). */
        kick(now = false) {
            clearTimeout(this.debounce);
            if (now) { this.$wire.$refresh(); return; }
            this.debounce = setTimeout(() => this.$wire.$refresh(), 600);
        },

        /** Every second: the shop clock (wall display) and whether the board has stopped updating. */
        beat() {
            if (boot.wall) this.clock = clockText(new Date(), document.documentElement.lang);
            const rendered = Number(this.$root.dataset.rendered) * 1000;
            this.stale = Number.isFinite(rendered) && rendered > 0 ? isStale(rendered, Date.now(), this.link, this.poll) : false;
        },

        // ── New cards ──────────────────────────────────────────────────────────────────────────────────────────
        /** Marks the cards that have just arrived in the New or Needs attention lane. */
        look() {
            const cards = [...this.$root.querySelectorAll('[data-card]')].map((el) => ({ id: el.dataset.card, lane: el.dataset.lane, el }));
            const result = welcome(this.seen, cards);
            this.seen = result.seen;
            for (const c of cards.filter((x) => result.fresh.includes(x.id))) this.mark(c.el);
        },

        mark(el) {
            el.dataset.fresh = '1';
            if (!reduced()) {
                el.animate({ opacity: [0, 1], transform: ['translateY(10px) scale(.97)', 'none'] }, { duration: 360, easing: 'cubic-bezier(.2, .9, .25, 1.15)' });
            }
            setTimeout(() => { delete el.dataset.fresh; }, FRESH_MS);
        },

        goTo(lane) {
            this.$refs.lanes?.querySelector(`[data-lane-box="${lane}"]`)?.scrollIntoView({ inline: 'start', block: 'nearest', behavior: reduced() ? 'auto' : 'smooth' });
        },

        // ── The wall display ──────────────────────────────────────────────────────────────────────────────────
        /** The same switch as the speaker in the top bar (that bar is not on the wall display): the choice is kept in this browser and the layout's toasts play the sound. */
        toggleSound() {
            this.sound = !this.sound;
            store.set('live_toasts_sound', this.sound ? '1' : '0');
            window.dispatchEvent(new CustomEvent('live-sound', { detail: { on: this.sound } }));
        },

        toggleFullscreen() {
            if (document.fullscreenElement) document.exitFullscreen?.().catch(() => {});
            else document.documentElement.requestFullscreen?.().catch(() => {});
        },

        /** A screen in the branch must not go to sleep. */
        async keepAwake() {
            try { this.lock = await navigator.wakeLock?.request('screen'); } catch { /* not supported, or the tab is hidden: the screen may sleep */ }
        },
    }));
});
