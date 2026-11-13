# Web push in the storefront (browser notifications)

Customers can get live order updates as browser notifications on desktop (Chrome, Edge, Firefox, Safari), Android Chrome, and iPhone/iPad
(Safari 16.4+, **only after the store is added to the Home Screen**). The admin switches it on in the dashboard (Push notifications); the
customer turns it on per device. Nothing is shown to guests.

## How it fits together

| Piece | Where | Notes |
|---|---|---|
| Public Firebase web config | `GET /api/v1/push-config` -> `{data:{enabled, web:{apiKey, authDomain, projectId, messagingSenderId, appId, vapidKey}\|null}}` | Read with no token. Anything incomplete counts as "off". |
| Service worker | `GET /firebase-messaging-sw.js` (served by the backend, config injected) | Registered at scope `/`. **Not** part of the storefront build. The only worker the store registers; no offline caching. |
| Device registration | `POST /api/v1/me/devices` `{platform:'web', app:'store', push_token, locale, device_id}`; `DELETE /api/v1/me/devices` `{push_token}` | Sent only when something changed (new token, other account, or a week old). |
| Message keys | `title, body, order_id, deep_link, step, phase, kind` | Foreground: toast + same query refresh as the live socket. Background: the worker draws it. |

Code: `storefront/src/lib/webpush-core.ts` (pure decisions, unit tested), `lib/webpush.tsx` (browser work, store, hooks, `<WebPush/>` mounted
in `App.tsx`), `components/PushUI.tsx` (settings card, soft prompt, iOS install guide, blocked-browser help). Small hooks elsewhere:
`lib/api.ts` (`onBeforeLogout`), `lib/auth.tsx` (`logout` runs it first), `lib/realtime.tsx` (toast de-duplication),
`pages/Account.tsx` (card on Notifications, prompt on Orders), `pages/Order.tsx` (prompt after `?placed=1` / a paid return).
PWA: `public/manifest.webmanifest`, `public/icons/*` (from the app icon `mobile/assets/images/app_icon_1024.png`), tags in `index.html`.

## States

`resolveState` order: unsupported -> disabled_by_admin -> ios_needs_install -> denied -> error -> granted/default.

| State | When | Account -> Notifications card | Soft prompt |
|---|---|---|---|
| `unsupported` | not HTTPS, no Service Worker / Push / Notification API, iOS older than 16.4, or Firebase says the browser cannot | Plain "cannot show notifications" text, no switch. Config is not even fetched. | hidden |
| `disabled_by_admin` | `enabled:false`, config missing/incomplete, or the config request failed | "Not available right now", no switch | hidden |
| `ios_needs_install` | iPhone/iPad (incl. iPad "desktop" mode) in a normal Safari tab | 3 steps with icons + an SVG picture of Safari's Share button (dismissible, remembered) | shown as "Show me how" -> opens the card |
| `denied` | browser permission is `denied` | Disabled switch + steps for the visitor's browser first, the others under "Steps for other browsers" (Chrome/Edge/Android, Safari Mac, Firefox, iPhone/iPad Settings) | hidden |
| `error` | worker did not start / token failed / server did not save | Plain reason (`push.err.sw/token/server`) + Try again | stays visible after a failed Enable until dismissed |
| `default` | not turned on for this account on this device (browser permission may still be `default` or `granted`) | Switch off; Enable asks the browser only if needed | shown |
| `granted` | browser allows it **and** this account opted in on this device | Switch on | hidden |

Notes: opt-in is per account per device (`albahar.push.users`), so a second account on a shared browser is not enabled automatically. An
installed iPhone web app (`navigator.standalone` or `display-mode: standalone`) behaves like any other supported browser.

## Flows

- **Enable** (click only): `Notification.requestPermission()` first (still the click's gesture), then lazy-load Firebase, register the worker
  at `/`, wait until it is active, `getToken({vapidKey, serviceWorkerRegistration})`, `POST /me/devices`, remember token + opt-in, attach `onMessage`.
- **Disable**: `DELETE /me/devices` must succeed (otherwise the switch stays on and says so), then forget opt-in/token, then revoke the browser token
  at Firebase in the background. A later Enable waits for that revoke.
- **App start / after sign-in** (silent, only if permission is granted and the account opted in): refresh the token, re-POST only if changed;
  a rotated token deletes the old one on the server.
- **Sign out**: `runBeforeLogout()` removes this browser's token from the server while the session still exists (3 s cap), the account stays opted in
  so signing in again re-registers silently.
- **Permission changed in browser settings**: checked on focus/visibility. Blocked -> device removed from the server, state `denied`. Allowed again -> silent re-register.
- **Foreground message**: toast (`title · body`), refresh `['orders']`, `['notifications']`, `['order', id]`, `['timeline', id]`. The live socket and
  a push for the same order show **one** toast when they arrive within 3 s of each other (different sources only; two real changes on one channel still show twice).
- **Soft prompt**: a card (never a browser popup) about 1.2 s after the page, on the order page after `?placed=1` (or a paid return) and on Account -> Orders. "Not now" hides it for 14 days.

localStorage keys: `albahar.push.token` `{token,userId,at}`, `albahar.push.users`, `albahar.push.device`, `albahar.push.dismissed`, `albahar.push.ios_hint`. All reads/writes are try/catch.

## Cost until used

No Firebase code and no worker unless a customer taps Enable (or already has push on). Main chunk grew **364.49 -> 382.79 kB (112.51 -> 118.05 kB gzip)**,
almost all of it the English + Arabic copy and the small store; the order chunk grew 38.38 -> 47.34 kB (the card/prompt UI, shared with Account). Firebase is three lazy
chunks, **56.7 kB (about 17.8 kB gzip)** in total, fetched on demand. Verified: a signed-in visit with push off makes no Google request, no chunk request and registers no worker.

## Dependency note

`firebase` is pinned to **12.19.0** (exact). Its tree must contain a single `@firebase/app`. Installing 13.x first and then switching left a second copy
(`firebase/node_modules/@firebase/app` 0.16.2 next to a hoisted 0.16.4); the bundle then had two Firebase apps and `getMessaging` failed with
"Service messaging is not available". A fresh `npm ci` from the committed lockfile is correct (`npm ls @firebase/app` shows one version). `getToken`/`deleteToken` are marked
deprecated in recent SDKs in favour of FID registration, but the server contract (`push_token`) is an FCM registration token, so they stay until the backend sends to FIDs.
`npm audit` reports the Firestore/gRPC advisories that ship inside the `firebase` package; that code is never imported or bundled (only `firebase/app` and `firebase/messaging`).

## Verified here (headless Chromium 153 against a local stub of the backend)

Run with the production build served from `storefront/dist`, `/api/v1` stubbed, the Firebase Installations and FCM registration endpoints answered by test routes,
the browser permission prompt and push subscription stubbed in an init script. The **real Firebase SDK ran** (worker registration, activation wait, `getToken`, `onMessage`, `deleteToken`).

- `npm test` (node:test, `tests/webpush-core.test.mjs`): 19 pass. Config parsing, iOS/iPadOS/old-iOS detection, the full state matrix, help order, stored token/opt-in, 7-day re-register, 14-day snooze, message normalising, toast de-duplication in both orders.
- Browser, 95 checks, 0 failures: card states (default, granted, denied, iOS tab, iOS installed, unsupported, admin off, error + retry), enable posts the device exactly once with the right body,
  reload does not re-post, foreground push toast + list refresh, disable deletes with the token, re-enable without a new browser prompt, logout deletes **before** the session ends, sign-in again
  re-registers, permission revoked/restored while open, token rotation removes the old token, second account not auto-enabled, soft prompt (not on first paint, appears, Not now, 14-day memory, comes back, Enable from the prompt, orders list, iOS variant),
  guests get nothing and no config request, Arabic/RTL (direction, switch thumb mirrored, no sideways scroll at 390 and 1440), 44 px targets, manifest + icons are real PNGs of the declared sizes,
  no worker registered by merely browsing, no page errors.
- Screenshots (390 and 1440, light, dark, Arabic): `/tmp/claude-0/-home-user-Al-Bahar-Orders-Managment/df5a4cdd-ceec-5aef-a969-9f474fcb6a56/scratchpad/webpush/shots/`.

## Needs a real Firebase project (not provable here)

1. A real token from FCM for a real VAPID key and a real message delivered to Chrome/Firefox/Edge desktop and Android Chrome (background notification drawn by the worker, tap opens `deep_link`).
2. Safari on macOS and on an installed iPhone/iPad (16.4+): the Push API behaviour, the permission prompt from the Home Screen app, and the iOS Settings path in the denied help.
3. The worker script itself (`/firebase-messaging-sw.js` from the backend) loading the Firebase compat scripts from gstatic, and the page/worker SDK versions working together.
4. Add to Home Screen on real iOS from the new manifest (name, icon, standalone launch at `/`).
5. A real order-progress push arriving while the store is open (foreground path with real FCM payload shape) and the socket + push toast de-duplication end to end (unit tested; the socket side was not run).

## Known limits and choices

- A forced sign-out (refresh token rejected) cannot call the API, so the device row stays until the next sign-in moves it or FCM reports the token dead. The backend should disable a user's devices when their session is revoked.
- If the admin changes the Firebase project, existing customers need to turn it off and on again (their token belongs to the old project).
- The "Send me a test" button is not built: the backend has no test-push endpoint for customers. Add it to `PushSettingsCard` when one exists.
- No offline support and no other worker, by design. `display: standalone` only makes the store installable; iOS needs it for push.
- Not verified here: the production nginx must answer `/store/manifest.webmanifest` with `application/manifest+json` (stock nginx `mime.types` maps `.webmanifest` to it; the local test server sent it). Check once after deploy with `curl -I`.
