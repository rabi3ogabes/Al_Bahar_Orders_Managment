# Live order notification (customer app)

A Talabat-style card in the customer's notification shade: a four-segment progress bar, a headline with the arrival window
("Arrives at 9:20pm-9:35pm") and a status line ("Being prepared at Al Markhiya"). It is **one card per order**, updated in
place on every meaningful change, in the language of each phone.

The server builds the card (`App\Domain\Orders\Services\OrderProgress`) and sends it as a Firebase push
(`PushNotifier` → `FcmClient::sendProgress`). The Android app draws the card itself from the push data; iOS shows a normal
alert. The same object is available over the API, so the app can rebuild its cards after a restart.

| Where | What |
|---|---|
| FCM push (`data.kind = "order_progress"`) | the card, on every change |
| `GET /api/v1/orders/active-progress` | the card for each of my orders in progress |
| `GET /api/v1/orders/{id}` → `data.progress` | the card of that order (`null` when there is nothing to show) |

## 1. The payload

One object, one shape everywhere. In JSON (API) the numbers are numbers; in the FCM push every value is a **string**,
`null` values are **left out**, and `brand` is a **JSON string** (`{"primary":"#8A1538","accent":"#B3254F"}`).
Clients should read numbers with a tolerant parser (`int.tryParse('$v')`) so one code path serves both.

```json
{
  "kind": "order_progress",
  "order_id": "01a1d649-06c8-72d2-b51c-b85f7e63552d",
  "number": "AB100014",
  "type": "delivery",
  "step": 1,
  "total": 4,
  "sub": 0.4,
  "phase": "preparing",
  "detail": "driver_accepted",
  "step_label": "Preparing",
  "title": "Arrives at 6:35pm-6:55pm",
  "body": "Ahmed is heading to Al Markhiya to pick up your order.",
  "eta_label": "6:35pm-6:55pm",
  "eta_from": "2026-11-14T15:35:00Z",
  "eta_to": "2026-11-14T15:55:00Z",
  "driver_name": "Ahmed",
  "driver_phone": "+97455123456",
  "vehicle": "White Toyota Hilux",
  "seq": 1794668400000,
  "updated_at": "2026-11-14T15:00:00Z",
  "deep_link": "/orders/01a1d649-06c8-72d2-b51c-b85f7e63552d",
  "done": "0",
  "brand": { "primary": "#8A1538", "accent": "#B3254F" }
}
```

| Field | Meaning |
|---|---|
| `kind` | always `order_progress`; how the app tells this push from others |
| `order_id`, `number` | the order. Use `order_id` as the notification id so an update replaces the card |
| `type` | `delivery` or `pickup` |
| `step`, `total` | stage 0..3 of 4: the filled segments of the bar (see section 2) |
| `sub` | 0..1, how far through the current stage the order is. **Omitted** when there is no estimate to measure against (just placed, a booked slot, finished) |
| `phase` | `placed` `preparing` `on_the_way` `ready` `delivered` `collected` `cancelled` `failed` |
| `detail` | finer state, for icons or analytics: `pending_payment` `placed` `preparing` `driver_assigned` `driver_accepted` `picked_up` `out_for_delivery` `ready` `delivered` `collected` `cancelled` `failed` |
| `step_label` | short name of the current stage, in the device language ("Preparing"; for pickups "Ready for pickup" / "Collected") |
| `title` | the headline: the arrival window when there is one, else a status headline |
| `body` | the status line |
| `eta_label` | the window alone, ready to show: `6:35pm-6:55pm` / `6:35 م - 6:55 م` (with "tomorrow, " or a date in front when it is not today) |
| `eta_from`, `eta_to` | the window, ISO 8601 UTC. `null` (left out in FCM) when there is no window |
| `driver_name` | the driver's **first name**, once a driver is assigned |
| `driver_phone` | E.164, only while the delivery is active (assigned, accepted, picked up, out for delivery). Never after delivery |
| `vehicle` | the driver's vehicle text, while the delivery is active |
| `seq` | the time this card was built, in milliseconds. A client keeps the card with the **larger `seq`** and drops older ones (pushes can arrive out of order) |
| `updated_at` | the same moment, ISO 8601 UTC |
| `deep_link` | `/orders/{id}`, the screen to open on tap |
| `done` | `"1"` when the card is final (delivered, collected, cancelled, failed), else `"0"`: stop showing progress and let the user dismiss it |
| `brand` | `primary` and `accent` colours of the live theme (so seasonal looks recolour the card). Always valid `#RRGGBB` |
| `quiet` | **FCM only.** `"1"` when the push only moved the arrival window (nothing else changed): update the card silently, do not alert again |

Dates and times are **Asia/Qatar**, 12-hour, with Latin digits in both languages (the same as the store): `9:20pm` in English,
`9:20 م` in Arabic. A window that starts tomorrow reads "Arrives tomorrow, 9:00am-10:30am" / "تصل غداً بين 9:00 ص و 10:30 ص";
later days show a short date ("Arrives Tue 17 Nov, ...").

## 2. Stages and phases

| `step` | Delivery | Pickup | `phase` |
|---|---|---|---|
| 0 | placed | placed | `placed` |
| 1 | preparing (also while a driver is assigned / heading to the branch) | preparing | `preparing` |
| 2 | on the way (picked up, out for delivery) | ready for pickup | `on_the_way` / `ready` |
| 3 | delivered | collected | `delivered` / `collected` |

- `cancelled`: step 0, `done = 1`, title "Order cancelled".
- `failed` (delivery unsuccessful): step 2, `done = 1`. If the branch re-attempts, the next push shows the road again with `done = 0`.
- An order waiting for online payment is `placed` with `detail = pending_payment`, no window, body "Waiting for your payment."
- A customer's cancellation request keeps the current stage and changes the body to "Your cancellation request is being reviewed."
- **Several branches in one order:** the card shows the **least advanced** part (a failed delivery wins a tie) and the **latest** arrival window.
- A returned order has no card (`progress` is `null`).

## 3. The arrival window

First rule that applies:

1. **Booked slot** (delivery or pickup): the slot's window, while its end is still ahead. After it has passed the order is late and the estimate below takes over.
2. **Driver on the road with a fresh position** (at most 2 minutes old) and a destination: minutes from the tracking estimator
   (`EtaService`: road time from the routing server in Settings → Integrations → "Routing service URL", else straight line; see [MAPS.md](MAPS.md)).
   Page loads (`active-progress`, order detail) never wait on the routing server; they use the straight-line estimate unless a road estimate is already cached.
3. **On the road without a position:** the typical delivery time minus the time since pickup (at least 3 minutes).
4. **Before pickup (delivery):** the later of "now" and "accepted + preparation time", plus the typical delivery time.
   Settings → **Checkout** → *Typical preparation time* (`orders.default_prep_minutes`, default **25**) and
   *Typical delivery time* (`orders.default_delivery_minutes`, default **20**).
5. **Before pickup (pickup order):** accepted + the branch's own *pickup preparation time* (Branches → edit, default 60 minutes).

The point estimate becomes a window of **± 7 minutes, widened to whole 5-minute marks** (9:27 → 9:20-9:35); once the order is on the road the margin shrinks to a third of the minutes left (3 to 7).
The window never starts in the past and is at least 5 minutes wide. Estimates are cached **30 s per order**; nothing here waits on an external call except the push worker, which may ask the routing server (3 s limit, then straight line).

`sub` = share of time elapsed inside the current stage: preparing runs from accepted to ready; on the way runs from pickup to the estimated arrival.

## 4. When a push is sent

`PushNotifier::refreshOrder()` is the single entry point. It rebuilds the card and **sends only if something changed**:

- the card's state changed since the last push: stage, `detail` (driver assigned / accepted / picked up / out for delivery ...), driver, `done`; or
- the window end moved by **5 minutes or more** (`platform.live_order.shift_minutes`). This push is marked `quiet = "1"` (iOS: passive, no sound).

It is called from: order placed (`OrderPlaced`), order status change (`OrderUpdated`), a branch moving its part (`DispatchUpdated`, kind `order`),
every delivery step (assigned, accepted, picked up, out for delivery, delivered, failed), cancellation, and driver position ticks
(`OrderDriverMoved`, **at most once per 2 minutes per order**, `platform.live_order.tick_seconds`). Duplicates are harmless: the state check, a per-order lock and a unique queue job make it idempotent.

- A finished order stops being updated **30 minutes after it finished** (and drops out of `active-progress`).
- Everything runs on the `notifications` queue; the order flow never waits for it and never sees an error from it. If Firebase fails the card is simply sent again at the next change.
- Each device gets the card in **its own language** (`user_devices.locale`: `en`, `ar`, `ar_QA` ...; anything else falls back to English).
- Driver and staff pushes (new delivery, order ready, delivery failed) are unchanged.

## 5. The FCM message

One HTTP v1 message carries both platforms' instructions.

**Android** — data only, high priority. There is **no `notification` block**: the app must draw the card itself so the bar and the in-place update work in the background.

```json
{ "message": {
  "token": "<device token>",
  "data": { "kind": "order_progress", "order_id": "...", "step": "1", "total": "4", "title": "...", "body": "...", "seq": "1794668400000", "brand": "{\"primary\":\"#8A1538\",\"accent\":\"#B3254F\"}", "...": "..." },
  "android": { "priority": "high", "collapse_key": "order-<order_id>", "ttl": "14400s" },
  "apns": { "headers": { "apns-push-type": "alert", "apns-priority": "10", "apns-collapse-id": "order-<order_id>", "apns-expiration": "<now + 4 h>" },
            "payload": { "aps": { "alert": { "title": "...", "body": "..." }, "thread-id": "order-<order_id>", "interruption-level": "active", "sound": "default" } } }
} }
```

- `collapse_key` / `apns-collapse-id`: a phone that was offline gets only the newest update per order. (FCM keeps at most 4 collapse keys per phone at a time; with more than 4 orders in progress the oldest ones may be dropped while offline. The app recovers them from `active-progress`.)
- `ttl` 4 hours: an update that cannot be delivered within 4 hours is discarded.
- iOS: alert with `thread-id`, so the cards of one order stack together. A `quiet` update uses `apns-priority 5`, `interruption-level passive` and no sound.
- Android must not alert twice for the same order: set `onlyAlertOnce` on the notification (client side) and treat `quiet = "1"` as a silent update.
- Web tokens (`user_devices.platform = web`) get the same data through Web Push when the web push setup is present.

## 6. The API

`GET /api/v1/orders/active-progress` (customer token): `{ "data": [ card, ... ] }` — the **caller's own** orders that are in progress or finished
less than 30 minutes ago, newest first, at most 10 (`platform.live_order.max_active`), orders placed within the last 7 days. Language: `Accept-Language`
(the apps send it), or `?lang=` / `X-Locale`. Call it when the app starts and when it comes back to the foreground, and redraw one card per entry.
Another customer's orders never appear; staff tokens get 403.

`GET /api/v1/orders/{id}` now includes `progress` (the same card, or `null`). The list `GET /api/v1/orders` does not.

## 7. Firebase set-up (owner, one time)

The server skips push silently until Firebase is connected (`FcmClient::configured()`).

1. **Create the project.** console.firebase.google.com → *Add project* → name it (for example "Al Bahar") → Google Analytics is optional.
2. **Add the Android apps.** Project overview → *Add app* → Android, once for each package id:
   - `qa.albahar.customer` (customer app, this notification)
   - `qa.albahar.driver` (driver app)
   - `qa.albahar.staff` (staff app)

   Only the package name is required; skip the SHA-1 for now. If you also build the dev or staging flavours, register `qa.albahar.customer.dev`, `qa.albahar.customer.staging` (and the same suffixes for driver and staff) in the same project.
   The build fails for a flavour whose package id is missing from the file.
3. **Download `google-services.json`.** Project settings → General → *Your apps* → *google-services.json*. The file holds **every** app registered in the project, so download it again after adding the last app. Save it as:
   - `mobile/android/app/src/prod/google-services.json` for the production flavours (`customerProd`, `driverProd`, `staffProd`);
   - `mobile/android/app/src/staging/google-services.json` and `mobile/android/app/src/dev/google-services.json` for those flavours (the same file works if all ids are registered in this project).

   (If you prefer a single file for all flavours, `mobile/android/app/google-services.json` works too. The Gradle script applies the Google Services plugin when it finds either location.)
   These paths are in `.gitignore`: do not commit them.
4. **Create the server key.** Project settings → *Service accounts* → *Generate new private key* → a JSON file. Never commit it.
   Upload it to the server outside the web root (for example `/etc/albahar/firebase-service-account.json`, readable by the app user) and set, in the app's environment (panel → Docker app → Environment), then restart:

   ```
   FCM_PROJECT_ID=<your Firebase project id, for example albahar-prod>
   FCM_CREDENTIALS_PATH=/etc/albahar/firebase-service-account.json
   ```

   Instead of the two variables you can use the admin dashboard: **Push notifications** page → switch on, enter the project id and paste the service-account JSON (it is stored sealed and never shown again). A value saved there wins over the variables.

   The queue worker must run with the `notifications` queue (the `docker-compose.yml` worker does: `queue:work redis --queue=default,notifications,exports`), because pushes are sent by queued jobs.
5. **Build the apps with push on.** CI builds currently pass `--dart-define=PUSH_ENABLED=false` because the runner has no `google-services.json`. To switch it on:
   1. Encode the file: `base64 -w0 mobile/android/app/src/prod/google-services.json` and save the output as a GitHub secret named `GOOGLE_SERVICES_JSON_BASE64` (repository → Settings → Secrets and variables → Actions → New repository secret).
   2. In `.github/workflows/android-apk.yml` add this step before *Build APK*:

      ```yaml
      - name: Firebase config
        env:
          GS_B64: ${{ secrets.GOOGLE_SERVICES_JSON_BASE64 }}
        run: |
          if [ -n "$GS_B64" ]; then
            echo "$GS_B64" | base64 -d > android/app/src/prod/google-services.json
            echo "PUSH=true" >> "$GITHUB_ENV"
          else
            echo "::warning::GOOGLE_SERVICES_JSON_BASE64 is not set: building without push."
            echo "PUSH=false" >> "$GITHUB_ENV"
          fi
      ```

   3. In both build steps (*Build APK* and *Build app bundle*) change `--dart-define=PUSH_ENABLED=false` to `--dart-define=PUSH_ENABLED=${{ env.PUSH }}`.

   For a local build nothing is needed beyond the file from step 3 (`flutter run --flavor customerDev -t lib/main_dev.dart`).
6. **iOS** (when the iOS app is built): add the iOS app in Firebase, upload an APNs key (Project settings → Cloud Messaging → Apple app configuration), and add `GoogleService-Info.plist` to the Runner target.
7. **Check it.** Place a test order in the customer app with a registered device, then in the admin move it through *Accepted → Preparing → Ready*, assign a driver and run the driver's steps. A card should appear within seconds and update in place.
   On the server, `php artisan tinker` → `app(\App\Domain\Notifications\Services\FcmClient::class)->configured()` must return `true`.

## 8. Privacy

- The card is built for, and sent to, the customer who placed the order only; the API lists only the caller's own orders.
- The driver appears by **first name**. The **driver's phone number** is included (so the card can offer "Call driver") only while that customer's delivery is active, and is dropped as soon as it is delivered or fails. It travels through Firebase like any push data. If the business prefers not to share it, return `null` for `driver_phone` in `OrderProgress::present()`; nothing else depends on it.
- No address, no totals and no items are in the card.

## 9. Tests

`backend/tests/Feature/Commerce/LiveOrderProgressTest.php` (stages, texts, times, ETA, driver, `seq`), `LiveOrderProgressApiTest.php` (endpoint, authorisation, language, order detail),
`LiveOrderPushTest.php` (FCM shape, devices and languages, when it sends, throttling, failures, events, the whole journey), and the older `RealtimePushTest.php` (driver and staff pushes).

```bash
cd backend && php artisan test --filter='LiveOrder|RealtimePush'
```
