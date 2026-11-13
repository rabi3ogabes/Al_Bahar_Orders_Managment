# Firebase push notifications from the dashboard (web and apps)

The owner sets Firebase up in the dashboard, with no server file or variable to edit: **Dashboard, Organization, Push notifications**
(`/admin/push`; needs the `settings.view` permission to look and `settings.edit` to change). It covers desktop browsers, Android Chrome,
iPhone/iPad Safari 16.4+ (installed to the Home Screen), the web store and the Flutter web app (`/mobile-web/`). The phone apps use the
same server key.

## Owner steps

All six steps are also on the page itself (open by default until everything is ready).

1. **Create a Firebase project.** console.firebase.google.com, Create a project (or open the one you use). Skip Google Analytics.
2. **Add a Web app.** Project overview, the `</>` (Web) icon, name it, Register app. No Firebase Hosting needed.
3. **Copy the app settings** (Project settings, gear icon, General, Your apps, SDK setup and configuration, Config). Paste
   `projectId` (also "Project ID" on that page), `apiKey`, `authDomain` (optional), `messagingSenderId`, `appId` into the form.
4. **Create the Web Push key.** Project settings, Cloud Messaging tab, Web configuration, Web Push certificates, Generate key pair.
   Paste the long public "Key pair" into "Web Push key (VAPID)".
5. **Download the server key.** Project settings, Service accounts tab, Generate new private key. On the Cloud Messaging tab make sure
   "Firebase Cloud Messaging API (V1)" says Enabled (if not: its menu, Manage API in Google Cloud Console, Enable).
6. **Upload the `.json` file** (or paste its text) in "Server key", press **Save changes**, then **Test connection**. Then press
   **Enable on this browser** and **Send a test notification**. Finally turn on the **Push notifications** switch at the top and delete
   the `.json` file from your computer.

What each field must look like (the form checks it and says what is wrong):

| Field | Format | Example (fake) |
|---|---|---|
| Project ID | lowercase letters, digits, dashes, 6 to 30 characters | `al-bahar-home` |
| API key | `AIza` + 35 characters | `AIzaSyA1b2C3d4E5f6G7h8I9j0K1l2M3n4O5p6Q` |
| Auth domain | host name, empty = `<project>.firebaseapp.com` | `al-bahar-home.firebaseapp.com` |
| Messaging sender ID | digits | `123456789012` |
| Web app ID | `1:<digits>:web:<hex>` (the **Web** app, not Android/iOS) | `1:123456789012:web:0a1b2c3d4e5f6a7b8c9d0e` |
| Web Push key | 87 characters, starts with `B` (a trailing `=` is dropped) | `BAswVXqfxOkOM1h9osf...` |
| Server key | the service-account JSON, same project as the Project ID | `{ "type": "service_account", ... }` |

The server key is checked on save (valid JSON, `type` = `service_account`, `project_id` equals the Project ID, `client_email` and a private
key OpenSSL can read), stored **sealed** (encrypted like every other secret setting) and never shown again: the page only says
"Configured" with the service-account e-mail, and offers Replace key / Remove key.

### The checklist at the top of the page

Server key, Web app settings, Web Push key (each ready or missing), registered devices per platform (web / Android / iPhone), the last
successful delivery and the last problem (both from `notification_logs`, channel `push`). **Test connection** gets a fresh OAuth token with
the saved key and asks FCM to `validate_only` a message sent to a topic: nothing reaches any device. Failures are explained in plain words
(key rejected or revoked / server clock wrong, API (V1) switched off, key not allowed to send, project not found, no internet,
Firebase down, rate limited) with Firebase's own message below.

The master switch acts at once and stops every push (web and apps) while keeping all settings. It cannot be turned on before a server key
exists. Installs that were set up with `FCM_PROJECT_ID` + `FCM_CREDENTIALS_PATH` keep working untouched (the switch counts as on until the
owner switches it explicitly); a value saved in the dashboard always wins over the server variables.

## Settings keys (group `push`, Settings service)

| Key | Type | Note |
|---|---|---|
| `push.enabled` | bool | master switch; unset = on only when the old server variables exist |
| `push.firebase.project_id` | text | |
| `push.firebase.api_key` | text | public |
| `push.firebase.auth_domain` | text | optional |
| `push.firebase.messaging_sender_id` | text | public |
| `push.firebase.app_id_web` | text | public |
| `push.firebase.vapid_key` | text | public (Web Push public key) |
| `push.firebase.service_account` | **secret** JSON | sealed, write-only; reduced to `type, project_id, private_key_id, private_key, client_email, client_id` |

`App\Domain\Notifications\Services\FirebaseConfig` is the only reader (`enabled()`, `configured()`, `hasServerCredentials()`,
`serviceAccount()`, `webConfig()`, `publicConfig()`, `status()`). `FcmClient` takes the project id and the key from it. The OAuth token is
cached per key, so a replaced key is used at once. Every save is audited (`settings.updated` with `[secret]` for the key, plus
`push.service_account_replaced/removed`, `push.switched_on/off`, `push.connection_tested`, `push.test_sent`, `push.browser_enabled`,
`push.device_removed`); secrets and tokens are never written to the audit log.

## Public endpoints and the service worker

| What | Route | Notes |
|---|---|---|
| Public config | `GET /api/v1/push-config` (route `api.v1.push-config`) | `{"data": {"enabled": bool, "web": {apiKey, authDomain, projectId, messagingSenderId, appId, vapidKey} \| null}}`; `web` only while push is on and the server key exists; `Cache-Control: public, max-age=300`; no cookies |
| Worker | `GET /firebase-messaging-sw.js` and `GET /mobile-web/firebase-messaging-sw.js` | same script; `text/javascript`, `Service-Worker-Allowed: /`, `Cache-Control: no-cache`; no session, no cookies |
| Register a token (customer) | `POST /api/v1/me/devices` (`api.v1.me.devices.store`) | see below |
| Remove a token (customer) | `DELETE /api/v1/me/devices` (`api.v1.me.devices.destroy`) | `{push_token}`; only the caller's own token |
| Staff apps | `POST/DELETE /api/v1/admin/devices` | same handler |
| Dashboard browser | `POST /admin/push/devices` (`admin.push.devices.store`) | session + CSRF, `settings.view`, 20 per minute |

Register body: `{platform: android|ios|web, push_token (max 512), locale?: en|ar, app?: store|mobile-web, device_id?, app_version?}`. It is
idempotent (same token = same row, refreshed and switched back on) and a token registered by someone else **moves** to the caller. For
`web` the browser family ("Chrome on Android") is read from the user agent; `app` says which web app asked. Limit: 20 registrations per
minute per person. `POST /api/v1/auth/logout` also accepts `push_token` (that device stops getting pushes), `logout-all` switches off all
of the person's devices.

The worker (`resources/push/firebase-messaging-sw.js`, template filled by `App\Support\FirebaseWorkerScript`) imports the Firebase compat
scripts from `https://www.gstatic.com/firebasejs/<version>/` where `<version>` is `FirebaseWorkerScript::SDK_VERSION`, kept equal to the
`firebase` npm version (a test checks `package.json`). The public settings are injected as one JSON value escaped for script context. It
draws data-only messages (the live order card: tag `order-<order_id>`, replaced in place, `renotify` off, silent for estimate-only
updates), leaves messages with a `notification` block to the Firebase SDK (so nothing shows twice), and on click focuses an open tab of the
site or opens the link; only same-site links are ever opened. nginx sends both worker paths to Laravel (`docker/nginx/default.conf`).
The app sets no Content-Security-Policy; if one is added, allow `https://www.gstatic.com` (script, for the worker import),
`https://fcm.googleapis.com`, `https://fcmregistrations.googleapis.com` and `https://firebaseinstallations.googleapis.com` (connect).

## How the server sends to a browser

`FcmClient::send($token, $title, $body, $data, $device)` and `sendProgress(..., $device)` choose the message by `UserDevice::platform`:
`web` gets a `webpush` block, other tokens get the Android/iOS blocks as before (no device passed = phone token, so old callers are
unchanged).

- General notification: `webpush.headers` (`TTL` 86400, `Urgency` normal), `webpush.notification` (title, body, icon, badge),
  `webpush.fcm_options.link` (absolute, https only) and `data` (`deep_link` is a same-site path).
- Live order card: data only (title, body, order_id, step, phase, deep_link ...), `Urgency` high (normal for `quiet` updates), the same
  TTL as the phones, `Topic` = the order id without dashes (the push service keeps only the newest update for an offline browser).
- Links: `albahar://orders/1` becomes `/orders/1`; own-site https links keep their path; anything else becomes `/`. Devices registered
  with `app = mobile-web` get `/mobile-web/#/orders/1` (the Flutter web app routes after the hash).
- A token FCM reports as `UNREGISTERED`, or `INVALID_ARGUMENT` blaming `message.token`, is switched off (`disabled_at`). A wrong
  project or a bad payload never disables devices.

## Notes per platform

- **Desktop** (Chrome, Edge, Firefox, Safari 16+) and **Android Chrome / Samsung Internet**: work in the browser tab after the person
  allows notifications. The site must use https.
- **iPhone / iPad (iOS 16.4+)**: web push only works for a site added to the Home Screen (Safari, Share, Add to Home Screen, open it
  from the new icon, then allow notifications). In a normal Safari tab there is no `PushManager` and no permission question; the page says so.
  Every push must show a notification (the worker always does for data-only messages).
- **Dashboard browser**: the worker is registered under scope `/admin/`, so the dashboard's token is separate from a customer token of the
  same browser. While the dashboard page is open and visible, a message arrives as a toast (browsers only draw notifications for hidden pages).

## Troubleshooting

| Symptom | Cause and fix |
|---|---|
| "Notifications are blocked" | The browser blocked the site. Lock icon next to the address, Notifications, Allow, reload. |
| No permission question on iPhone | Opened in a Safari tab. Add to Home Screen and open from the icon. |
| "The browser could not start the notification worker" | `/firebase-messaging-sw.js` is not reaching Laravel (nginx location missing, a CDN rewriting it to HTML), the page is not https, or the browser cannot reach `www.gstatic.com`. Open the URL: it must be JavaScript. |
| Worker scope problems | The worker must be registered under a path the file is allowed to control: the server sends `Service-Worker-Allowed: /`. The dashboard uses `/admin/`, the store `/`. |
| "Firebase refused to register this browser" (`token_failed`) | Wrong API key or Web Push key, the key's HTTP-referrer restriction excludes this site, or "Firebase Installations API" / "FCM Registration API" is disabled for the API key in Google Cloud. A Web Push key from another project gives the same error: all values must be from the same project. |
| VAPID mismatch | A token made with an old key cannot be used after the Web Push key changes: press Enable again (and customers re-enable). |
| Test connection: "API (V1) is switched off" | Cloud Messaging tab, API (V1), menu, Manage API in Google Cloud Console, Enable. |
| Test connection: "key not accepted" | The key was deleted or revoked, or the server clock is wrong (JWT time). Download a new key and check NTP. |
| Test says "removed" for a device | FCM no longer knows that token (browser data cleared, app uninstalled). It was switched off; enable the browser again. |
| Nothing arrives although the test works | The master switch is off, the person has no active device, or notification preferences block the channel. |
| CSP blocks something | See the Content-Security-Policy note above. |

## Checks

`php artisan test tests/Feature/Push` (settings, key validation, endpoints, worker script and escaping, device registration, FCM web
delivery, the admin page), `node --test tests/js` (the worker and the dashboard helpers), and the browser run
`tests/e2e-push/admin.mjs` (needs a running app and Chromium; the real Firebase SDK and the real worker run with Google's endpoints stubbed).
Not provable without a real Firebase project: Google accepting the key, and a real push reaching a device. The owner does that with
**Test connection** and **Send a test notification**.
