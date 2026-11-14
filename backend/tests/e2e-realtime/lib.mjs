// Helpers for the real-time order status end-to-end run (not part of CI: it needs a running stack, see README.md).
// Three actors (customer, branch staff, driver) each open a real WebSocket to Reverb with the same client library the web store
// uses (pusher-js), authorise their private channels with their own bearer token, and the script times every status change.
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const require = createRequire(import.meta.url);
const Pusher = (() => {
    for (const p of ['pusher-js', resolve(here, '../../../storefront/node_modules/pusher-js')]) {
        try { return require(p); } catch { /* try the next location */ }
    }
    throw new Error('pusher-js not found: run `npm ci` in storefront/ first');
})();

export const BASE = process.env.BASE ?? 'http://127.0.0.1:8071';
export const WS_HOST = process.env.WS_HOST ?? '127.0.0.1';
export const WS_PORT = Number(process.env.WS_PORT ?? 8081);
export const WS_KEY = process.env.WS_KEY ?? 'localkey';
export const PASSWORD = process.env.DEMO_PASSWORD ?? 'AlBahar#Demo2026';

export const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
export const now = () => Number(process.hrtime.bigint() / 1000n) / 1000; // ms, monotonic

export async function api(method, path, { token, body, form, headers = {} } = {}) {
    const t0 = now();
    const res = await fetch(`${BASE}/api/v1${path}`, {
        method,
        headers: {
            Accept: 'application/json', 'X-App-Build': '999',
            ...(token ? { Authorization: `Bearer ${token}` } : {}),
            ...(body ? { 'Content-Type': 'application/json' } : {}),
            ...headers,
        },
        body: form ?? (body ? JSON.stringify(body) : undefined),
    });
    const t1 = now();
    const text = await res.text();
    let json = null;
    try { json = JSON.parse(text); } catch { /* not json */ }
    return { status: res.status, json, text, sent: t0, done: t1 };
}

export async function must(method, path, opts) {
    const r = await api(method, path, opts);
    if (r.status >= 300) throw new Error(`${method} ${path} -> ${r.status} ${r.text.slice(0, 300)}`);
    return r;
}

export async function loginCustomer(email = 'noor@example.com') {
    const r = await must('POST', '/auth/login', { body: { email, password: PASSWORD, platform: 'android' } });
    return { token: r.json.data.tokens.access_token, user: r.json.data.user };
}

export async function loginStaff(email) {
    const r = await must('POST', '/admin/auth/login', { body: { email, password: PASSWORD, platform: 'android' } });
    return { token: r.json.data.tokens.access_token, user: r.json.data.user };
}

/** A real socket for one actor. Records every event it hears, with the time it arrived. */
export class Actor {
    constructor(name, token) {
        this.name = name;
        this.token = token;
        this.events = [];
        this.waiters = [];
        this.subscribed = new Map();
        this.authFailures = [];
        this.pusher = new Pusher(WS_KEY, {
            cluster: 'mt1', wsHost: WS_HOST, wsPort: WS_PORT, wssPort: WS_PORT, forceTLS: false, enabledTransports: ['ws'],
            disableStats: true,
            channelAuthorization: {
                endpoint: `${BASE}/api/v1/broadcasting/auth`, transport: 'ajax',
                headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
            },
        });
    }

    connected() {
        return new Promise((resolveOk, reject) => {
            if (this.pusher.connection.state === 'connected') return resolveOk();
            const t = setTimeout(() => reject(new Error(`${this.name}: socket did not connect`)), 8000);
            this.pusher.connection.bind('connected', () => { clearTimeout(t); resolveOk(); });
        });
    }

    /** Subscribes and resolves when the server accepted the channel (the authorisation worked), or rejects with the status. */
    join(channel) {
        return new Promise((resolveOk, reject) => {
            const ch = this.pusher.subscribe(channel);
            this.subscribed.set(channel, ch);
            ch.bind('pusher:subscription_succeeded', () => resolveOk(ch));
            ch.bind('pusher:subscription_error', (e) => { this.authFailures.push({ channel, status: e?.status ?? e }); reject(new Error(`${this.name}: ${channel} refused (${JSON.stringify(e)})`)); });
            ch.bind_global((event, data) => {
                if (event.startsWith('pusher')) return;
                const ev = { actor: this.name, channel, event, data, at: now() };
                this.events.push(ev);
                this.waiters = this.waiters.filter((w) => !(w.match(ev) && (w.resolve(ev), true)));
            });
        });
    }

    /** Resolves with the first event matching `match` that arrives (or already arrived after `since`). */
    waitFor(match, { since = 0, timeout = 8000, label = 'event' } = {}) {
        const seen = this.events.find((e) => e.at >= since && match(e));
        if (seen) return Promise.resolve(seen);
        return new Promise((resolveOk, reject) => {
            const t = setTimeout(() => { this.waiters = this.waiters.filter((w) => w !== waiter); resolveOk(null); void reject; }, timeout);
            const waiter = { match: (e) => e.at >= since && match(e), resolve: (e) => { clearTimeout(t); resolveOk(e); } };
            this.waiters.push(waiter);
        });
    }

    close() { this.pusher.disconnect(); }
}

/** A 1x1 PNG, the proof photo of a delivery. */
export const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64');

export function proofForm(extra = {}) {
    const f = new FormData();
    f.append('proof', new Blob([PNG], { type: 'image/png' }), 'proof.png');
    for (const [k, v] of Object.entries(extra)) f.append(k, v);
    return f;
}

export const median = (xs) => { const s = [...xs].sort((a, b) => a - b); return s.length ? s[Math.floor(s.length / 2)] : NaN; };
export const fmt = (n) => (Number.isFinite(n) ? `${Math.round(n)} ms` : 'none');
