# Live order notification: customer app (Android renderer and Dart controller)

The server side (payload, `GET /orders/active-progress`, FCM message) is described in
[../guides/LIVE_ORDER_NOTIFICATION.md](../guides/LIVE_ORDER_NOTIFICATION.md). This page is the app side: what the customer
sees, how it is built, what works without Firebase, how it was tested and what still has to be checked on a phone.

## 1. What the customer sees (Android)

One card per order in the shade, updated in place until the order ends. It carries **one brand mark**: the system's own header shows the small icon
(the Al Bahar sun over two waves as a monochrome glyph, `ic_stat_notify`, tinted with the live brand colour) and the app name; the card's content has no
logo of its own. (Before, the content also drew a 40 dp tile of the app icon, so the notification showed two Al Bahar logos: the header icon and the tile.
`LiveOrderBarRenderer.logoTile` and `R.id.live_logo` are gone; no `setLargeIcon` is used anywhere.)

- a bold headline ("Arrives at 9:20pm-9:35pm"), a status line ("Your order has been placed") and a four-stage segmented bar: check circle (placed), store
  (preparing), scooter (on the way), map pin (delivered). Pickup orders use placed, preparing, ready (bag) and collected. Done stages are brand circles with a
  check, the current stage a brand circle with its icon and a soft ring, later stages grey. The segment after the current stage is filled by `sub` (0..1, default a
  first hint of 15%, ready 50%);
- collapsed: headline, status line and a slim 4 dp version of the bar; expanded: the full bar and, once a driver is on the job, a **driver chip** ("Driver: Ahmed ·
  White Toyota Hilux"). Actions are system buttons (so 48 dp targets): "Track order" (opens `/orders/{id}` through the app's deep link) and, while the order is
  active and a phone number came with it, "Call driver" (opens the dialer);
- **alerts:** heads-up with sound (channel `albahar_order_live`, importance high) when the card first appears and when the order moves to **another stage**
  (placed, preparing, on the way, delivered, cancelled...); everything else (the bar moving, the window shifting, the countdown, a re-read) is silent
  (`onlyAlertOnce`);
- **lock screen and Always-On Display:** an active card is posted with `VISIBILITY_PRIVATE` plus a **public version** (headline, the plain sentence for the stage, the
  slim bar: no driver name, no phone number, no address, which the card never carries anyway). A phone that shows everything shows the full card; one that hides
  sensitive content shows the public version. It is ongoing (not swipeable from the shade) while active; a finished card is `VISIBILITY_PUBLIC`, swipeable and
  removed by the system after 30 minutes (12 hours as a safety net for an active one). Android 14+ lets the customer swipe an ongoing card away anyway: it is then
  not brought back by later updates of that order;
- **countdown:** while the driver is on the road the header counts down to the arrival (mm:ss) by itself. When it reaches zero a one-shot, inexact alarm
  (`LiveOrderCountdownReceiver`) draws the card again without the chronometer, so it never counts below zero; a newer estimate moves the alarm;
- **Android 16 (API 36) Live Updates:** `compileSdk`/`targetSdk` are the Flutter 3.47 defaults, 36, so `LiveOrderPromoted` uses the platform's `Notification.ProgressStyle`
  (three 100-unit legs, the tracker icon is the current stage as a round badge) and asks for promotion (`setRequestPromotedOngoing`, with
  `POST_PROMOTED_NOTIFICATIONS` in the manifest): a status-bar chip (the countdown, else the arrival time via `setShortCriticalText`) and the rich lock-screen card. It has
  no custom views (a promoted notification may not), the same title, text, actions, group and timeouts, and falls back to the custom card on any failure; older Android
  keeps the custom card. minSdk is unchanged (24, from Flutter's default with the app's own floor of 23);
- two or more active orders get one "N orders in progress" summary on top;
- a card the customer swipes away is not brought back by later updates of that order;
- Arabic is right to left (bar mirrored, scooter turned, the layout direction is set from the app's language, not the phone's), text and times in the app's own
  language (not the phone's), light and dark follow the shade (the brand colour is lightened in dark mode until it keeps 3:1 against the surface, glyph colours keep 4.5:1);
- large text: headline and status ellipsize (one line collapsed, two expanded), the slim bar is dropped from the collapsed card above font scale 1.15. Spacing is on a 4 dp grid;
- on an iPhone the same card is a Live Activity on the lock screen and in the Dynamic Island: [../guides/IOS_LIVE_ACTIVITY.md](../guides/IOS_LIVE_ACTIVITY.md).

## 2. Architecture

```
FCM data push ─┬─(app closed / background)─> LiveOrderFcmReceiver (Kotlin) ───────────┐
               └─(app open)─> PushService ─> LiveOrderController ─┐                    │
Reverb order.updated (private-users.{id}) ───────────────────────┤                    ├─> LiveOrderNotifier
GET /orders/active-progress (start, resume, online, every 60 s) ─┤  (Dart, single     │   (Kotlin: RemoteViews,
order just placed (local, optimistic) ───────────────────────────┘   source of truth)  │    bar bitmap, channel)
                                                   │  MethodChannel albahar/live_order ┘
                                                   └─> fallback: flutter_local_notifications progress (iOS without a Live Activity, tests, no channel)

iPhone: LiveOrderController ─> LiveActivityPlatform (channel albahar/live_activity) ─> LiveOrderBridge.swift ─> ActivityKit (lock screen, Dynamic Island)
        Apple's activity tokens ─> LiveActivityRegistrar ─> POST /me/live-activities ─> server ─> APNs `liveactivity` pushes (see IOS_LIVE_ACTIVITY.md)
```

Dart (`mobile/lib/core/live_order/`):

| File | Job |
|---|---|
| `live_order.dart` | `LiveOrder`: parsing (tolerant), phase/step/sub, ordering (`supersedes`), `signature`, channel map |
| `live_order_texts.dart` | headline, status line, ETA window, stage names (ARB, EN/AR, Doha time, optional Arabic-Indic digits) |
| `live_order_platform.dart` | `MethodChannelLiveOrderPlatform`: `show`, `cancel`, `cancelAll`, `areEnabled`, `permission`, `requestPermission`, `openSettings`; "unsupported" without a native side |
| `live_order_api.dart` | `GET /orders/active-progress`; on 404/405/422 builds the same cards from `/orders` (+ `/orders/{id}/tracking` for the estimate) |
| `live_order_controller.dart` | the controller, its providers and `liveOrderBootProvider` (sign-in/out, realtime channel, network, language/brand) |
| `live_activity_platform.dart` | the iPhone Live Activity: `show` (start or update, a finished order ends), `end`, `endAll`, token events; "unavailable" anywhere else |
| `live_activity_registrar.dart` | reports Apple's tokens to the server for the signed-in customer only, takes them back at sign-out |
| `notification_permission.dart` | when to ask, the rationale sheet, the settings variant |

Kotlin (`mobile/android/app/src/main/kotlin/qa/albahar/customer/liveorder/`): `LiveOrderModel` (parse, order, segments, `toMap`),
`LiveOrderColors` (WCAG maths), `LiveOrderBarRenderer` (bar, slim bar, stage badge bitmaps; no logo), `LiveOrderText` (fallback words, locale, the
lock-screen public wording), `LiveOrderNotifier` (post, update in place, alert rule, lock-screen public version, group, timeouts, dismissal, the end of the
countdown), `LiveOrderPromoted` (Android 16 progress style), `LiveOrderStore` (per-order memory in private prefs, incl. the last state as JSON for the countdown end),
`LiveOrderPermissions`, `LiveOrderChannel` (method channel, registered by `MainActivity`), `LiveOrderReceivers` (`LiveOrderFcmReceiver`,
`LiveOrderDismissReceiver`, `LiveOrderCountdownReceiver`). Layouts `live_order_collapsed.xml` / `live_order_expanded.xml`, `live_chip_bg.xml` (driver chip),
vector glyphs `ic_live_*.xml`, the small icon `ic_stat_notify.xml`, strings in `values/` and `values-ar/`.

Deviations from the first brief, on purpose:

- **Framework `Notification.Builder` + `Notification.DecoratedCustomViewStyle`, not `NotificationCompat`.** The app's minSdk is
  24 (Flutter default, and `flutter_local_notifications` needs 24), where they are the same thing underneath; it adds no
  androidx dependency and the code could be compiled against the Android 14 framework here.
- **Pushes with the app closed are drawn by a Kotlin receiver, not by the Dart background isolate.** `firebase_messaging` runs
  the Dart handler in a separate engine that only has registered plugins, so an app-level method channel does not exist there.
  `LiveOrderFcmReceiver` listens to the same `com.google.android.c2dm.intent.RECEIVE` broadcast as FlutterFire's receiver and
  draws the card in milliseconds. The Dart background handler returns early on Android (a second card would be wrong). When the
  app is open Dart also sees the push; the notifier drops that duplicate.
- **The headline is the server's `title`.** The final server payload puts the full headline in `title` ("Arrives at
  6:35pm-6:55pm") and only the window in `eta_label`. Order of preference: `title`, `eta_label`, a window built from
  `eta_from/eta_to`, "Order #number". Finished orders always say "Delivered at <time of the card>".
- **No permission dialog at sign-in in the customer app** (`PushService.askPermissionOnRegister` is false for `/me/devices`; the
  driver and staff apps still ask). The token is registered anyway; data pushes arrive and show once permission is granted. On an
  iPhone the app asks for *provisional* authorization at sign-in (quiet delivery, no dialog) and the same rationale sheet after the first
  order asks for real alerts (`MessagingPermissionPlatform`, `lib/core/live_order/live_order_platform.dart`).

## 3. Rules

**Order of updates** (same code in Dart and Kotlin): a finished order stays as it first finished (a re-read would restamp the
time); two versioned states are ordered by `seq` (the server's is a millisecond clock), then `updated_at`; an unversioned state
(realtime event, the order just placed) never goes backwards and never reopens a finished order; a newer version that says the
same thing (same `signature`: phase, step, sub in 5% steps, title, body, window, driver) only moves the version on, so the 60 s
re-read does not redraw the card. Language or brand changes redraw with `force`.

**Controller**: signed out means nothing is shown, and signing out cancels every card and forgets the saved ids. Active ids
(`{orderId: seq}`) are saved in preferences, so a restart picks them up: they are re-read, and an id the server no longer lists
is looked up once (`GET /orders/{id}`) and finished, or its card removed. A finished order found by a re-read is announced only if
the app was following it or it ended in the last 30 minutes. A timer re-reads every 60 s only while an order is active (and the
phone is online); offline or on a server error the cards stay as they are. Realtime `order.updated` carries only a status, so the
phase is derived from it, then the server is asked for the estimate 600 ms later; a repeat of the stage already shown is ignored.

**Permission**: `PushService` no longer prompts. After an order is placed (`continueAfterOrder`, used by checkout and auction
wins) a rationale sheet is shown on the next frame. Denied but askable: sheet, then the system dialog; shown again after 14
days, three times at most. Denied for good or switched off: a "Notifications are off" sheet that opens the app's notification
settings, once, once more after 30 days. Never at cold start, never blocks navigation, never throws.

## 4. What works without Firebase, and with it

| | No Firebase (push not set up in the dashboard yet) | With Firebase (set up in Dashboard → Push notifications; the apps start it from the server) |
|---|---|---|
| Card right after placing an order | yes (local, optimistic, native renderer) | yes |
| Updates while the app process lives | yes: Reverb events and the 60 s re-read | yes |
| Updates with the app killed or frozen by the OS | no (the OS stops Dart in the background) | yes: `LiveOrderFcmReceiver` |
| Rebuilt after restart / resume | yes: saved ids plus `active-progress` | yes |
| iOS | plain local notification per update (thread id `order`) | plus the server's APNs alert with `thread-id` |

iOS Live Activities / Dynamic Island (widget extension, ActivityKit, Apple push) are written but not yet built into the Xcode project: until the steps of
[../guides/IOS_LIVE_ACTIVITY.md](../guides/IOS_LIVE_ACTIVITY.md) are done the iOS app keeps the standard alert and the Dart side treats the Live Activity as unavailable.

## 5. Tests and checks

- Dart: `test/core/live_order/` (119 tests): parsing of the legacy push, the final server push (all strings, brand JSON,
  `quiet`) and missing or invalid fields; step/sub mapping and status codes; ordering and de-duplication; ETA formatting EN/AR
  (Doha time, Western digits by default, Arabic-Indic as an option); the controller under `fake_async` (placed, push, realtime,
  re-read, stale finals, lookups, restart, sign-out, offline, error, language/brand/permission redraws, fallback to the plain
  notification); `RestLiveOrderApi` incl. the 404 fallback; the method channel with a mocked handler; the permission policy table
  and the sheet (EN, AR/RTL, text scale 2, 48 dp buttons); the wiring (`liveOrderBootProvider`, `PushService`).
  The iPhone Live Activity adds `live_activity_platform_test.dart` (the channel with a fake native side, "unavailable" without one or on Android, tokens both
  ways, no phone number), `live_activity_registrar_test.dart` (signed in and out, repeats, failures, language change) and `live_order_activity_test.dart`
  (the controller on an iPhone: start, update, end, fallback, sign-out).
- Kotlin, done here without an Android SDK: every `.kt` file (including `MainActivity`) compiles with `-Werror` against the
  Android 14 framework jar and the Flutter embedding jar, with a generated `R` from the real resources (so every `R.*` reference
  exists); the pure logic (`LiveOrderModel`, `LiveOrderColors`: parsing, ordering, segment fill, contrast for 5 brand colours in
  light/dark) was run on the JVM (about 50 checks); the layouts use only classes `RemoteViews` may inflate, `View.setLayoutDirection`
  was confirmed `@RemotableViewMethod`; EN/AR strings have the same keys and format arguments; the bar geometry was rendered in a
  Python port to check proportions, colours, RTL and dark mode against the reference.
- Kotlin, the second pass (logo removal, lock screen, countdown end, Android 16): the same `-Werror` compile of every `.kt` against the Android 14 jar with the
  regenerated `R` (every new `R.id` / `R.drawable` exists); the Android 16 platform calls (`Notification.ProgressStyle`, `setRequestPromotedOngoing`,
  `setShortCriticalText`) are **not in that jar** and were type-checked only against stand-ins written from the API 36 reference, so they are the one part the
  CI build can still reject; the JVM checks now also cover the JSON round trip of a stored card (`LiveOrder.toMap` and `LiveOrderPayload`), the Android 16 progress
  mapping (`LiveOrderPromoted.progress`) and the contrast of the brand colours in light and dark; the XML (layouts, vector small icon, chip shape, manifest) is well formed.
- **Not possible here:** `flutter build apk --debug --flavor customerDev` stops with "No Android SDK found" (`dl.google.com` is
  blocked in this sandbox), so AAPT2 resource linking, the real device and the shade rendering were not exercised. The repo has
  no JVM unit-test setup, so none was added.

## 6. Check on a phone before release (Android 13+ and one older, light and dark, English and Arabic)

1. Fresh install, sign in: no permission dialog. Place an order: the rationale sheet appears over the order page; "Allow" shows the
   system dialog; the card appears. "Not now": no dialog, and the sheet does not return the next day.
2. Card: **one** Al Bahar mark (the sun-and-waves small icon in the header, no tile in the content), headline, status line, bar as in section 1; expand it
   (driver chip once a driver is assigned); tap the card and "Track order": the order page opens, also from a cold start.
3. Move the order in the admin: the same card updates in place with no second card; it makes a sound only when the stage changes (accepted, on the way,
   delivered...), never for the bar, the window or the countdown.
4. Swipe the card away, then move the order on: it does not come back. Cancel an order: grey bar and "Order cancelled".
5. Two orders: summary card and two children. Deliver one: "Delivered at hh:mm", swipeable; gone after 30 minutes.
6. Arabic: right to left, scooter faces left, times in the app language even when the phone is English. Font size largest: no clipping.
7. Turn notifications off in the system settings and place an order: the settings variant of the sheet, "Open settings" opens them.
8. With Firebase connected: kill the app, move the order, the card updates; the Dart handler must not add a second card.
9. One alert per change: with a phone registered, moving the order through its stages produces the card only; no second
   "Order confirmed / out for delivery" notification next to it (iPhone: one alert per stage).
10. Android system settings, Al Bahar, Notifications: three channels in the phone's language ("Orders and updates", "Auctions",
    "Offers and news", الطلبات والتحديثات / المزادات / العروض والأخبار). Mute "Offers and news": order updates still arrive.
11. iPhone, fresh install, sign in: no dialog. Place an order: the sheet, then the system dialog. Say no: the next order offers the
    settings, never another dialog. Allow notifications later in the iPhone settings, come back to the app: pushes arrive.
12. Switch the app language in Settings: the next card and push arrive in the new language without restarting the app.
13. Sign out, sign in as another customer on the same phone: the first customer's pushes stop; the second's arrive.
14. Lock the phone with an order on the road: the card is on the lock screen and the Always-On Display. Settings, Notifications, "Sensitive notifications" (or "Hide
    sensitive content"): the lock screen then shows only the headline, the stage sentence and the slim bar, no driver name.
15. Let the countdown reach zero with no new estimate: within a few seconds the header shows the time instead of a negative timer.
16. Android 16 only: the order shows as a Live Update (status-bar chip with the countdown or the arrival time, rich lock-screen card with the segmented progress and the
    stage badge). Settings, Notifications, Live updates, switch it off for the app: the card stays as a normal progress notification without the chip.
17. Dark theme, Arabic, font size at the largest: nothing clipped in the collapsed or expanded card, the driver chip ellipsizes a long vehicle name.

## 7. Known limits and follow-ups

- Without Firebase the card stops updating when Android freezes the app in the background (no foreground service by design).
- Android 7.x (API 24-25) cannot time a notification out: an expired card is removed the next time the app touches the notifier.
- The card has no logo of its own (one brand mark: the system header's small icon). A remote logo from the dashboard theme (`images.logo`) is not used: a
  notification small icon must be a monochrome resource, so it follows the app build, not the dashboard.
- Android 16: the countdown chip and the lock-screen presentation are the platform's, and Android decides whether to promote (the customer can switch Live updates off).
  An Android 16 phone without the Live Updates switch simply shows the platform's progress notification.
- "Delivered at" for a card first learnt from a re-read uses the card's build time, which the server sets to "now" (within 30 minutes).
- iOS Live Activities are written (Swift, bridge, Dart, server) but were never compiled or run: see [../guides/IOS_LIVE_ACTIVITY.md](../guides/IOS_LIVE_ACTIVITY.md), section 7.
