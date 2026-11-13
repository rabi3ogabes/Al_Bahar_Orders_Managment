/* Firebase Cloud Messaging service worker for the web store and the web version of the mobile app.
 * The server fills in the public Firebase web settings (the owner types them in Dashboard → Push notifications); nothing here is secret.
 * A classic worker: it loads the Firebase compat scripts with importScripts. */
/* global importScripts, firebase, clients */
var SETTINGS = /*@settings*/ {};

importScripts(
    'https://www.gstatic.com/firebasejs/' + SETTINGS.sdk + '/firebase-app-compat.js',
    'https://www.gstatic.com/firebasejs/' + SETTINGS.sdk + '/firebase-messaging-compat.js'
);

firebase.initializeApp(SETTINGS.firebase);
var messaging = firebase.messaging();

self.addEventListener('install', function () { self.skipWaiting(); });
self.addEventListener('activate', function (event) { event.waitUntil(clients.claim()); });

/** A link from a push is only ever opened when it stays on this site; anything else falls back to the home page. */
function safeLink(raw) {
    try {
        if (typeof raw !== 'string' || raw === '') return '/';
        var url = new URL(raw, self.location.origin);
        if (url.origin !== self.location.origin) return '/';
        return url.pathname + url.search + url.hash;
    } catch (e) {
        return '/';
    }
}

function text(value, max) {
    return typeof value === 'string' ? value.slice(0, max) : '';
}

// Messages that carry a "notification" block are shown by the Firebase SDK itself; this handler draws the data-only ones
// (the live order progress). A page that is open and visible gets the message through onMessage instead, so nothing is drawn twice.
messaging.onBackgroundMessage(function (payload) {
    if (payload.notification) return;
    var data = payload.data || {};
    var title = text(data.title, 120) || SETTINGS.brand;
    var order = text(data.order_id, 64);
    var tag = text(data.tag, 64);
    var options = {
        body: text(data.body, 300),
        icon: safeLink(data.icon || SETTINGS.icon),
        badge: SETTINGS.icon,
        dir: 'auto',
        data: { deep_link: safeLink(data.deep_link), kind: text(data.kind, 40), order_id: order },
    };
    if (data.kind === 'order_progress' && order) {
        // One card per order: the next update replaces it in place and stays quiet.
        options.tag = 'order-' + order;
        options.renotify = false;
        options.silent = data.quiet === '1'; // an estimate-only update replaces the card without a sound
    } else if (tag) {
        options.tag = tag;
    }
    return self.registration.showNotification(title, options);
});

self.addEventListener('notificationclick', function (event) {
    var info = event.notification.data || {};
    if (info.FCM_MSG) return; // drawn by the SDK, which opens its own link
    event.notification.close();
    var target = safeLink(info.deep_link);
    event.waitUntil(clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (list) {
        for (var i = 0; i < list.length; i++) {
            var client = list[i];
            if (new URL(client.url).origin !== self.location.origin || typeof client.focus !== 'function') continue;
            return client.focus().then(function (focused) {
                if (!focused) return clients.openWindow(target);
                var current = new URL(focused.url);
                return current.pathname + current.search + current.hash === target || typeof focused.navigate !== 'function' ? focused : focused.navigate(target);
            });
        }
        return clients.openWindow(target);
    }));
});
