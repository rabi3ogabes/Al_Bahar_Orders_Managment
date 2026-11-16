# Firebase push notifications from the dashboard (web and apps)

The owner sets Firebase up in the dashboard, with no server file or variable to edit: **Dashboard, Organization, Push notifications**
(`/admin/push`; needs the `settings.view` permission to look and `settings.edit` to change). It covers desktop browsers, Android Chrome,
iPhone/iPad Safari 16.4+ (installed to the Home Screen) and the web store. The phone apps use the
same server key and, since the phone-app settings below, **start Firebase from values typed in the dashboard** (no `google-services.json` or
`GoogleService-Info.plist` has to be built into the apps). The server can also send to the web build of the mobile app (`app = mobile-web`,
worker at `/mobile-web/firebase-messaging-sw.js`), but the Flutter app does not start push on the web yet (`FirebaseStarter` answers "not
available" there), so today only the store registers browsers.

## Owner steps

All eight steps are also on the page itself (open by default until everything is ready).

1. **Create a Firebase project.** console.firebase.google.com, Create a project (or open the one you use). Skip Google Analytics.
2. **Add a Web app.** Project overview, the `</>` (Web) icon, name it, Register app. No Firebase Hosting needed.
3. **Copy the app settings** (Project settings, gear icon, General, Your apps, SDK setup and configuration, Config). Paste
   `projectId` (also "Project ID" on that page), `apiKey`, `authDomain` (optional), `messagingSenderId`, `appId` into the form.
4. **Create the Web Push key.** Project settings, Cloud Messaging tab, Web configuration, Web Push certificates, Generate key pair.
   Paste the long public "Key pair" into "Web Push key (VAPID)".
5. **Download the server key.** Project settings, Service accounts tab, Generate new private key. On the Cloud Messaging tab make sure
   "Firebase Cloud Messaging API (V1)" says Enabled (if not: its menu, Manage API in Google Cloud Console, Enable).
6. **Add the three Android apps.** Project overview, the Android icon. Register one app per package name: `qa.albahar.customer`
   (customer app), `qa.albahar.driver` (driver app), `qa.albahar.staff` (staff app). **Every app has its own App ID**; two apps sharing
   one is the commonest mistake (see "If push does not arrive"). Download `google-services.json` (Project settings, Your apps), press
   **Upload google-services.json** in "Phone apps" on the dashboard page: it fills the three App IDs and the Android API key by package
   name (you still press Save changes). By hand: copy `mobilesdk_app_id` of the entry whose `package_name` matches each app into its
   "Android app ID" field, and `current_key` into "Android API key" (one key serves all three). The file itself is not needed in the app.
7. **Add the iPhone app.** Register an iOS app with bundle ID `qa.albahar.customer`. From its `GoogleService-Info.plist` copy `GOOGLE_APP_ID`
   into "iPhone app ID" and `API_KEY` into "iPhone API key". Then **Project settings, Cloud Messaging tab, Apple app configuration, APNs
   authentication key, Upload**: the `.p8` key from the Apple Developer account (Certificates, Identifiers and Profiles, Keys) with its Key
   ID and the Team ID. **Without the APNs key an iPhone never receives a push.**
8. **Upload the `.json` file** (or paste its text) in "Server key", press **Save changes**, then **Test connection**. Then press
   **Enable on this browser** and **Send a test notification**. Finally turn on the **Push notifications** switch at the top and delete
   the `.json` file from your computer.

Only the iPhone customer app exists today (the driver and staff apps are Android only). The page checklist shows "Android apps n of 3 ready"
and "iPhone app ready".

What each field must look like (the form checks it and says what is wrong):

| Field | Format | Example (fake) |
|---|---|---|
| Project ID | lowercase letters, digits, dashes, 6 to 30 characters | `al-bahar-home` |
| API key | `AIza` + 35 characters | `AIzaSyA1b2C3d4E5f6G7h8I9j0K1l2M3n4O5p6Q` |
| Auth domain | host name, empty = `<project>.firebaseapp.com` | `al-bahar-home.firebaseapp.com` |
| Messaging sender ID | digits | `123456789012` |
| Web app ID | `1:<digits>:web:<hex>` (the **Web** app, not Android/iOS) | `1:123456789012:web:0a1b2c3d4e5f6a7b8c9d0e` |
| Web Push key | 87 characters, starts with `B` (a trailing `=` is dropped) | `BAswVXqfxOkOM1h9osf...` |
| Android API key | `AIza` + 35 characters (`current_key` in `google-services.json`) | `AIzaSyB2c3D4e5F6g7H8i9J0k1L2m3N4o5P6q7R` |
| iPhone API key | `AIza` + 35 characters (`API_KEY` in `GoogleService-Info.plist`) | `AIzaSyC3d4E5f6G7h8I9j0K1l2M3n4O5p6Q7r8S` |
| Android app ID (customer, driver, staff) | `1:<digits>:android:<hex>` (`mobilesdk_app_id`) | `1:123456789012:android:0a1b2c3d4e5f6a7b8c9d0e` |
| iPhone app ID (customer) | `1:<digits>:ios:<hex>` (`GOOGLE_APP_ID`) | `1:123456789012:ios:0a1b2c3d4e5f6a7b8c9d0e` |
| Server key | the service-account JSON, same project as the Project ID | `{ "type": "service_account", ... }` |

The number after `1:` in every app ID is the project number: all of them must match each other and the Messaging sender ID (the page
refuses a mix). A phone app does not need the Web fields: the sender ID is taken from its app ID.

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

## If push does not arrive

Open **Dashboard, Organization, Push notifications**. Three sections tell you what is wrong, without a phone in your hand:

1. **Push health** (one card per phone app). Green "Ready" means saved values look right and a phone has registered. "Needs a fix" lists
   the reasons in plain words. The red banner "Two apps share one App ID" means two of the apps hold the same App ID: register each package as
   its own Android app in Firebase and use "Upload google-services.json" (or copy each app's own `mobilesdk_app_id`). With a shared App ID
   only one package can register and Google may refuse the others.
2. **What the phones report.** Each phone app tells the server when it cannot start push, cannot get a token, or cannot register it, and
   when it succeeds (no name, number, token or key is sent). Each line shows the app, the step, a code, how many phones, when, the phone's
   own words, **what it means and what to do**. The last 7 days; a phone that failed and then registered fine is not counted. "Phones that
   registered" counts the successes per app. Open the app once on a phone with the newest build and look again after a few seconds.
3. **Send a test to a phone.** Pick "My own devices" or any registered phone (customer, driver, staff), optionally with the sample live
   order card (customer app on Android), press **Send test**. You see Firebase's answer in plain words and its technical code. With no
   registered phone it says so and points to the section above. The same from the server: `php artisan push:test [user] [--card]`
   (user = id, phone number or e-mail; without it, the phone that registered last).

**"Sent" is not "seen" (Android 13+).** Google can accept a push (`pushed 1 of 1 phones`) and the phone still shows nothing: from Android 13 the
person must allow notifications first, and a phone that was never asked (or said no) keeps every push hidden. The inbox message still
arrives. The phone tells the server which it is (`user_devices.notification_permission`: `granted`, `denied`, `not_asked`; an older app sends
nothing and counts as "not reported"), and the dashboard now shows it: the **Phones** line and a warning in the customer's message window,
*Reached N phones, notifications off on M* on a sent message, and a count in a bulk send (see
[CUSTOMER_MESSAGES.md](CUSTOMER_MESSAGES.md)). The fix is on the phone: ask the customer to allow notifications in the app
(**Settings → Notifications**) or in the phone's own settings for the app.

What each report code means and the exact fix (the dashboard shows the same, in English and Arabic):

| Code (step) | Meaning | What to do in Firebase / Google Cloud |
|---|---|---|
| `server_unreachable` (options) | the phone could not read `/api/v1/push-config` | check the site answers; a few phones: nothing, the app retries |
| `push_off` (options) | push is off or the server key is missing | save the server key, switch "Push notifications" on |
| `app_not_set` (options) | this app has no App ID or API key saved | fill "Android app ID" for it and "Android API key", Save changes |
| `invalid_app_id`, `init_failed` (init) | Firebase refuses the settings | Project settings, Your apps: copy that app's own App ID |
| `api_key_blocked` (token) | the API key does not allow this app | Google Cloud, APIs & Services, Credentials, the Android key: Application restrictions = None, or Android apps with all three packages and each build's SHA-1 |
| `api_key_invalid` (token) | wrong key | paste `current_key` again |
| `api_disabled` (token) | a Google API is off or not allowed for the key | APIs & Services, Library: enable **Firebase Installations API** and **FCM Registration API**; key "API restrictions": none, or include both |
| `fis_auth_error`, `invalid_argument` (token) | Firebase refused the install | App ID vs package, key restrictions (see above) |
| `service_not_available`, `timeout`, `no_token` (token) | Firebase unreachable or no answer | one phone: network / Google Play services; every phone: see the two rows above |
| `apns_missing` (token) | iPhone has no Apple token | upload the APNs key in Firebase, real iPhone |
| `permission_denied` (permission) | notifications are off for the app on the phone | phone Settings, Apps, Notifications; the customer app asks after the first order |
| `permission_not_asked` (permission) | the customer has not been asked yet (Android 13+): a push arrives silently | nothing; the app asks after the first order, or allow it in the phone settings to test |
| `server_error`, `rejected`, `no_network`, `unauthorized` (register) | the phone had its token but the server did not take it | look at the server log around the time shown |
| `ok` (registered) | all good | use "Send a test" |

The phone side (`mobile/lib/core/push/`): `FirebaseStarter` reads the settings and starts Firebase; `PushTokenFetcher` gets the token with
`getToken()` (the FCM registration token the server addresses; firebase_messaging 17 deprecates it for `register()` + `onRegistered`, but
that stream delivers the installation id, "FID", which is only the first part of the token) and falls back to the installation id only when
`getToken()` gives nothing or is gone; `PushService` registers it and asks again after 30 seconds, 2 minutes and 10 minutes while the app
stays open; `PushDiagnostics` posts the reports. Every report is told at most once per run and once a day per install, never blocks
start-up and never throws.

`POST /api/v1/push-diagnostics` is public (a phone with no Firebase has no token to be authenticated by) and strict: `{app: customer|driver|staff,
platform: android|ios, stage: options|init|permission|token|register|registered, code: ^[a-z0-9][a-z0-9_.-]{1,47}$, message?: ≤200 chars,
app_version?, os_version?, install_id}`; other fields are ignored; the message is scrubbed again on the server (API keys, e-mail addresses,
long tokens and numbers replaced); no user, token or IP address is stored. One row per install, app, stage and code per day (`count` goes
up), table `push_diagnostics`, removed after 30 days by `push:prune-devices`. Rate limits: 60 a minute per address, 40 an hour per install,
600 a minute overall. Always answers `202 {"data": {"accepted": true}}`.

Native devices now record which app they belong to (`user_devices.app` = `customer`, `driver` or `staff`, read from the `X-App` header
every app call carries), which is how the page counts devices per app.

Real time without push: the customer's open app also hears `notification.created` on `private-users.{id}` (the unread count rides in it),
and while the socket is blocked it reads the badge every 30 seconds, so the inbox follows the server even where push does not work.

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
| `push.firebase.api_key_android` / `push.firebase.api_key_ios` | text | public; one per platform |
| `push.firebase.app_id_android_customer` / `_driver` / `_staff`, `push.firebase.app_id_ios_customer` | text | public; one Firebase app per package / bundle ID |
| `push.firebase.service_account` | **secret** JSON | sealed, write-only; reduced to `type, project_id, private_key_id, private_key, client_email, client_id` |

The same page has a separate section, **iPhone live activity**, for the Apple push key (`push.apns.*`: Team ID, Key ID, bundle ID, environment and the sealed
`.p8`) that lets the server update the order on iPhone lock screens and in the Dynamic Island, with its own switch, Save and Test. It does not use Firebase;
see [IOS_LIVE_ACTIVITY.md](IOS_LIVE_ACTIVITY.md).

`App\Domain\Notifications\Services\FirebaseConfig` is the only reader (`enabled()`, `configured()`, `hasServerCredentials()`,
`serviceAccount()`, `webConfig()`, `publicConfig()`, `status()`). `FcmClient` takes the project id and the key from it. The OAuth token is
cached per key, so a replaced key is used at once. Every save is audited (`settings.updated` with `[secret]` for the key, plus
`push.service_account_replaced/removed`, `push.switched_on/off`, `push.connection_tested`, `push.test_sent`, `push.browser_enabled`,
`push.device_removed`); secrets and tokens are never written to the audit log.

## Public endpoints and the service worker

| What | Route | Notes |
|---|---|---|
| Public config | `GET /api/v1/push-config` (route `api.v1.push-config`); with `?platform=android\|ios&app=customer\|driver\|staff` it also returns `native`: `{projectId, messagingSenderId, apiKey, appId}` for that phone app, or `null` |  `{"data": {"enabled": bool, "web": {apiKey, authDomain, projectId, messagingSenderId, appId, vapidKey} \| null}}`; `web` only while push is on and the server key exists; `Cache-Control: public, max-age=300`; no cookies |
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

- **One active token per phone.** `device_id` is the install id the app makes once. When a phone registers a *new* token under the same
  `device_id` (Firebase rotated it, app data was cleared) every older active token with that `device_id` is switched off, because the old
  token can stay valid at Google for a while and the phone would ring twice. Browsers are exempt (one browser holds several tokens: the
  store and the dashboard have their own worker scope).
- **Language per phone.** `locale` is the app's language (`en` or `ar`; without it the request's `Accept-Language`). The customer app sends
  it with every registration and **again whenever the customer switches language**, so the live order card, journeys and staff messages
  follow the app, not only the account. Every queued push (`SendNotificationJob`) carries the message rendered in each supported language and
  each phone gets its own; campaigns carry both the Arabic and the English title/body.
- **Stale tokens.** `php artisan push:prune-devices` (daily 03:45 Qatar time, `--days=60`) switches off tokens whose app has not registered
  for 60 days (the apps register at every start, the store at least weekly). Rows are kept (the referral shared-device check reads them).
  FCM answers `UNREGISTERED`, an invalid token or `SENDER_ID_MISMATCH` (a token made for another Firebase project) switch a device off at once.
- A signed-out phone: the app asks the server to remove its token on sign-out. When the session simply ends (expired, revoked) the server
  cannot be asked; the phone forgets its token and banner preferences locally, and the next person who signs in on it moves the token to
  themselves. Until then, the 60-day clean-up is the backstop.

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
- A token FCM reports as `UNREGISTERED`, as `SENDER_ID_MISMATCH`, or `INVALID_ARGUMENT` blaming `message.token`, is switched off
  (`disabled_at`). A wrong project, a refused key or a bad payload never disables devices.

## What `FcmClient::send()` puts in a push (phones)

`send($token, $title, $body, $data, $device)` never throws. It answers `{ok, unregistered, retry, status, error}` and, when it failed, FCM's own `code` (`UNREGISTERED`, `SENDER_ID_MISMATCH`, `THIRD_PARTY_AUTH_ERROR` ...) and a clipped `detail` (the log line "Last problem" says it).

| Part | Value | Why |
|---|---|---|
| `notification` | title (max 120 characters) and body (max 500), cut with an ellipsis | FCM refuses a message above 4 KB; Arabic and emoji are counted in characters, never split |
| `data` | every value a string (`null` left out, arrays as JSON) plus `category` (`transactional` / `marketing` / `auction`) | FCM accepts strings only; the app files a banner under the right preference |
| `android.priority`, `android.ttl` | `high`, 86400 s | an update must not arrive on the third day |
| `android.notification.channel_id` | `albahar_default` (orders, payments, support, staff/driver pushes), `albahar_auctions`, `albahar_offers` (campaigns, stock, cart, journeys, staff messages) | the customer can mute offers without muting order updates; an older app without a channel falls back to the manifest default |
| `apns.headers.apns-expiration` | now + 86400 s | same |

Data keys the apps read: `event`, `category`, `deep_link`, `notification_id` (the inbox entry: opening the push marks it read), `log_id` /
`delivery_id` (identify one send: a repeated delivery is shown once), `kind = order_progress` (the live card, see
[LIVE_ORDER_NOTIFICATION.md](LIVE_ORDER_NOTIFICATION.md)). `coupon` and `number` are informational.

What happens when Google is not having a good day:

- An expired OAuth token (HTTP 401) is replaced once and the message sent again; a token service that answers without a token is an
  error and is never cached. OAuth is cached 50 minutes per key.
- A timeout, a connection error, 429, 5xx, `UNAVAILABLE`, `INTERNAL` or `QUOTA_EXCEEDED` comes back as `retry: true`.
  `SendNotificationJob` then **fails the job when nothing at all was delivered** (the queue retries with backoff 10 s, 1 min, 5 min, 15 min,
  5 attempts), and **finishes as sent when at least one device has the message** (a retry would ring that device a second time).
- A bulk send (campaign) is one queued job per customer: one customer's error or one slow phone never stops the others.

## Android channels, icons and colour (customer app)

`android/app/src/main/kotlin/qa/albahar/customer/PushChannels.kt` creates three channels when the app starts: **Orders and updates**
(`albahar_default`, high, also the manifest default), **Auctions** (`albahar_auctions`, high), **Offers and news** (`albahar_offers`,
default importance, no pop-up). Names and descriptions are `push_channel_*` strings, translated in `values-ar`, so the system settings
show them in the phone's language. The ids are mirrored in `FcmClient` (`CHANNEL_*`) and `lib/core/push/push_service.dart`
(`PushChannels`); change all three together. The small icon is `ic_stat_notify` (one per app flavour) and the accent colour
`notification_accent`, both in the manifest `meta-data`.

## Permission and the first notification (customer app)

- **Android 13+**: a token needs no permission, so the app registers without asking. The explanation sheet ("Follow your order live") is
  shown after the first order, with the system dialog behind it; if the system dialog is gone for good the sheet opens the settings. It
  waits two weeks between "not now" answers and appears three times at most (see `notificationPromptFor`).
- **iPhone**: the app asks for **provisional** authorization at sign-in, which shows no dialog and lets order news arrive quietly in the
  notification centre from the first minute. The same explanation sheet after the first order then asks for real alerts (the system dialog
  appears behind it); a customer who answered no to the system is offered the settings instead, never a second dialog.
- A customer who allows notifications in the system settings later is registered when the app next comes to the front. The driver and
  staff apps ask the normal question at sign-in and do not ask again until the permission is on.

## One alert per change

The live order card announces every order change (`order.created`, `.confirmed`, `.ready_for_pickup`, `.out_for_delivery`, `.completed`,
`.cancelled`) on Android and iPhone. While push is set up and the customer has an active device, `NotificationService` therefore leaves
the classic **push** of those six events out (in-app, SMS and e-mail are unchanged); otherwise the classic push is sent as before. The card
follows the customer's "order updates" (transactional) push preference like the classic push does. To change this, edit
`NotificationCatalog::LIVE_CARD_EVENTS`.

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
| A phone gets nothing after not being opened for two months | `push:prune-devices` switched its token off; opening the app registers it again. |
| Pushes show under "Miscellaneous" on Android | The app build predates `PushChannels.kt`; update the app. |
| A queued push stays "queued" with an `FCM unavailable` error | Google was unreachable or busy; the queue retries (see above). Check `notification_logs.error`. |
| `SENDER_ID_MISMATCH` in a log, devices switched off | The tokens were made for another Firebase project (the project or the phone apps' settings changed). The apps register again at their next start. |
| CSP blocks something | See the Content-Security-Policy note above. |

## Checks

`php artisan test tests/Feature/Push` (settings, key validation, endpoints, worker script and escaping, device registration, FCM web
delivery, the admin page, and `PushDeliveryTest`: channels, message size, OAuth refresh, busy or unreachable Firebase, language per phone,
one alert per change, one token per install, stale tokens, bulk sends), in the app `flutter test test/core/push` (registration, language,
permission, sign-out and account switch, repeated delivery, iPhone permission sheet, deep links) and `test/core/live_order`,
`node --test tests/js` (the worker and the dashboard helpers), and the browser run
`tests/e2e-push/admin.mjs` (needs a running app and Chromium; the real Firebase SDK and the real worker run with Google's endpoints stubbed).
Not provable without a real Firebase project: Google accepting the key, and a real push reaching a device. The owner does that with
**Test connection** and **Send a test notification**. Also not provable without a phone: that Android creates the three channels
(`PushChannels.kt` is not built by the Flutter tests), that the iPhone shows no dialog for provisional authorization, and that the
messaging SDK reports the token again when a second person signs in on the same install (the app falls back to the install's last token).
