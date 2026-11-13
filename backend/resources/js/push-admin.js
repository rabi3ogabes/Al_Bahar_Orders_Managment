// The dashboard's web push: "Enable on this browser" (asks permission, registers the service worker, hands the token to the server) and
// a listener that turns a message received while this page is open into a toast. Loaded lazily; the Firebase SDK is only fetched when it is
// really needed. The service worker is the same /firebase-messaging-sw.js the store uses, registered under /admin/ so the dashboard's token
// is separate from a customer token in the same browser.

export const WORKER_URL = '/firebase-messaging-sw.js';
export const WORKER_SCOPE = '/admin/';
const APP_NAME = 'albahar-admin-push';

/** "ok", "ios_install" (iPhone/iPad Safari tab: install to the Home Screen first) or "unsupported". Pure, so it is unit tested. */
export function supportState({ userAgent = '', platform = '', maxTouchPoints = 0, standalone = false, hasServiceWorker = false, hasPush = false, hasNotification = false } = {}) {
    if (hasServiceWorker && hasPush && hasNotification) return 'ok';
    const ios = /iPhone|iPad|iPod/.test(userAgent) || (platform === 'MacIntel' && maxTouchPoints > 1);

    return ios && !standalone ? 'ios_install' : 'unsupported';
}

/** Short codes the page translates (push_admin.browser_err.*). */
export function errorCode(err) {
    const code = String(err?.code ?? '');
    const name = String(err?.name ?? '');
    if (code.startsWith('albahar/')) return code.slice('albahar/'.length);
    if (code === 'messaging/permission-blocked') return 'denied';
    if (code === 'messaging/failed-service-worker-registration' || name === 'SecurityError') return 'worker_failed';
    if (code.startsWith('messaging/') || name === 'AbortError' || name === 'InvalidAccessError') return 'token_failed';

    return 'unknown';
}

const fail = (code) => Object.assign(new Error(code), { code: `albahar/${code}` });

async function messagingFor(config) {
    const [{ getApps, getApp, initializeApp }, { getMessaging }] = await Promise.all([import('firebase/app'), import('firebase/messaging')]);
    const options = { apiKey: config.apiKey, authDomain: config.authDomain, projectId: config.projectId, messagingSenderId: config.messagingSenderId, appId: config.appId };
    const app = getApps().some((a) => a.name === APP_NAME) ? getApp(APP_NAME) : initializeApp(options, APP_NAME);

    return getMessaging(app);
}

/**
 * Must be called from a click: the permission question comes first, before any await, because Safari only allows it right after a tap.
 * Resolves with the token once the server has stored it; rejects with an error that errorCode() understands.
 */
export async function enable({ config, endpoint, csrf, fetchImpl = fetch }) {
    if (!config) throw fail('no_config');
    if (typeof Notification === 'undefined' || !('serviceWorker' in navigator)) throw fail('unsupported');
    const permission = await Notification.requestPermission();
    if (permission === 'denied') throw fail('denied');
    if (permission !== 'granted') throw fail('dismissed');

    let registration;
    try {
        await navigator.serviceWorker.register(WORKER_URL, { scope: WORKER_SCOPE });
        registration = await navigator.serviceWorker.ready;
    } catch {
        throw fail('worker_failed');
    }
    const { getToken, isSupported } = await import('firebase/messaging');
    if (!(await isSupported())) throw fail('unsupported');
    const token = await getToken(await messagingFor(config), { vapidKey: config.vapidKey, serviceWorkerRegistration: registration });
    if (!token) throw fail('token_failed');

    const res = await fetchImpl(endpoint, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' },
        body: JSON.stringify({ push_token: token }),
    });
    if (!res.ok) throw fail('save_failed');

    return token;
}

let listening = false;

/** While this page is visible the browser draws no notification itself; show the message through onMessage({title, body}) instead. */
export async function listen({ config, onMessage }) {
    if (listening || !config || typeof Notification === 'undefined' || Notification.permission !== 'granted' || !('serviceWorker' in navigator)) return;
    const registration = await navigator.serviceWorker.getRegistration(WORKER_SCOPE);
    if (!registration || !registration.scope.endsWith(WORKER_SCOPE)) return; // this browser was never enabled for the dashboard
    listening = true;
    const { onMessage: subscribe } = await import('firebase/messaging');
    subscribe(await messagingFor(config), (payload) => {
        const n = payload.notification ?? {};
        const d = payload.data ?? {};
        onMessage({ title: n.title ?? d.title ?? '', body: n.body ?? d.body ?? '' });
    });
}
