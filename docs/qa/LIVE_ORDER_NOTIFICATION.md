# Live order notification: customer app (Android renderer and Dart controller)

The server side (payload, `GET /orders/active-progress`, FCM message) is described in
[../guides/LIVE_ORDER_NOTIFICATION.md](../guides/LIVE_ORDER_NOTIFICATION.md). This page is the app side: what the customer
sees, how it is built, what works without Firebase, how it was tested and what still has to be checked on a phone.

## 1. What the customer sees (Android)

One card per order in the shade, updated in place until the order ends:

- a rounded brand tile (the app icon), a bold headline ("Arrives at 9:20pm-9:35pm") and a status line ("Your order has been placed");
- a four-stage segmented bar: check circle (placed), store (preparing), scooter (on the way), map pin (delivered). Pickup
  orders use placed, preparing, ready (bag) and collected. Done stages are brand circles with a check, the current stage a
  brand circle with its icon, later stages grey. The segment after the current stage is filled by `sub` (0..1, default a first
  hint of 15%, ready 50%);
- collapsed: tile, headline, status line and a slim version of the bar; expanded: the full bar and, while on the road, a
  "Driver: Ahmed . Motorbike" line. Actions are system buttons (so 48 dp targets): "Track order" (opens `/orders/{id}` through
  the app's deep link) and, only while the order is on the road and a phone number came with it, "Call driver" (opens the dialer);
- first appearance: heads-up with sound (channel `albahar_order_live`, importance high). Every later update is silent
  (`onlyAlertOnce`), also when the push says `quiet = 1`;
- ongoing while the order is active (no foreground service). When it ends it becomes a normal, swipeable card: "Delivered at
  9:31 PM" (or "Collected at", "Order cancelled", "Delivery failed") with every stage filled (cancelled/failed: grey bar, a cross
  at the stage reached). The system removes it 30 minutes later (12 hours as a safety net for an active one);
- two or more active orders get one "N orders in progress" summary on top;
- a card the customer swipes away is not brought back by later updates of that order;
- Arabic is right to left (bar mirrored, scooter turned), text and times in the app's own language (not the phone's), light and
  dark follow the shade (the brand colour is lightened in dark mode until it keeps 3:1 against the surface, glyph colours keep 4.5:1);
- large text: headline and status ellipsize, the slim bar is dropped from the 48 dp collapsed card above font scale 1.15.

## 2. Architecture

```
FCM data push ─┬─(app closed / background)─> LiveOrderFcmReceiver (Kotlin) ───────────┐
               └─(app open)─> PushService ─> LiveOrderController ─┐                    │
Reverb order.updated (private-users.{id}) ───────────────────────┤                    ├─> LiveOrderNotifier
GET /orders/active-progress (start, resume, online, every 60 s) ─┤  (Dart, single     │   (Kotlin: RemoteViews,
order just placed (local, optimistic) ───────────────────────────┘   source of truth)  │    bar bitmap, channel)
                                                   │  MethodChannel albahar/live_order ┘
                                                   └─> fallback: flutter_local_notifications progress (iOS, tests, no channel)
```

Dart (`mobile/lib/core/live_order/`):

| File | Job |
|---|---|
| `live_order.dart` | `LiveOrder`: parsing (tolerant), phase/step/sub, ordering (`supersedes`), `signature`, channel map |
| `live_order_texts.dart` | headline, status line, ETA window, stage names (ARB, EN/AR, Doha time, optional Arabic-Indic digits) |
| `live_order_platform.dart` | `MethodChannelLiveOrderPlatform`: `show`, `cancel`, `cancelAll`, `areEnabled`, `permission`, `requestPermission`, `openSettings`; "unsupported" without a native side |
| `live_order_api.dart` | `GET /orders/active-progress`; on 404/405/422 builds the same cards from `/orders` (+ `/orders/{id}/tracking` for the estimate) |
| `live_order_controller.dart` | the controller, its providers and `liveOrderBootProvider` (sign-in/out, realtime channel, network, language/brand) |
| `notification_permission.dart` | when to ask, the rationale sheet, the settings variant |

Kotlin (`mobile/android/app/src/main/kotlin/qa/albahar/customer/liveorder/`): `LiveOrderModel` (parse, order, segments),
`LiveOrderColors` (WCAG maths), `LiveOrderBarRenderer` (bar, slim bar, tile bitmaps), `LiveOrderText` (fallback words, locale),
`LiveOrderNotifier` (post, update in place, group, timeouts, dismissal), `LiveOrderStore` (per-order memory in private prefs),
`LiveOrderPermissions`, `LiveOrderChannel` (method channel, registered by `MainActivity`), `LiveOrderReceivers`
(`LiveOrderFcmReceiver`, `LiveOrderDismissReceiver`). Layouts `live_order_collapsed.xml` / `live_order_expanded.xml`, vector
glyphs `ic_live_*.xml`, strings in `values/` and `values-ar/`.

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
  driver and staff apps still ask). The token is registered anyway; data pushes arrive and show once permission is granted.

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

| | No Firebase (today: CI builds `PUSH_ENABLED=false`, no `google-services.json`) | With Firebase |
|---|---|---|
| Card right after placing an order | yes (local, optimistic, native renderer) | yes |
| Updates while the app process lives | yes: Reverb events and the 60 s re-read | yes |
| Updates with the app killed or frozen by the OS | no (the OS stops Dart in the background) | yes: `LiveOrderFcmReceiver` |
| Rebuilt after restart / resume | yes: saved ids plus `active-progress` | yes |
| iOS | plain local notification per update (thread id `order`) | plus the server's APNs alert with `thread-id` |

iOS Live Activities / Dynamic Island need a widget extension and ActivityKit push tokens: not built. The iOS app keeps the
standard alert; the Dart controller and payload are already what a Live Activity would consume.

## 5. Tests and checks

- Dart: `test/core/live_order/` (119 tests): parsing of the legacy push, the final server push (all strings, brand JSON,
  `quiet`) and missing or invalid fields; step/sub mapping and status codes; ordering and de-duplication; ETA formatting EN/AR
  (Doha time, Western digits by default, Arabic-Indic as an option); the controller under `fake_async` (placed, push, realtime,
  re-read, stale finals, lookups, restart, sign-out, offline, error, language/brand/permission redraws, fallback to the plain
  notification); `RestLiveOrderApi` incl. the 404 fallback; the method channel with a mocked handler; the permission policy table
  and the sheet (EN, AR/RTL, text scale 2, 48 dp buttons); the wiring (`liveOrderBootProvider`, `PushService`).
- Kotlin, done here without an Android SDK: every `.kt` file (including `MainActivity`) compiles with `-Werror` against the
  Android 14 framework jar and the Flutter embedding jar, with a generated `R` from the real resources (so every `R.*` reference
  exists); the pure logic (`LiveOrderModel`, `LiveOrderColors`: parsing, ordering, segment fill, contrast for 5 brand colours in
  light/dark) was run on the JVM (about 50 checks); the layouts use only classes `RemoteViews` may inflate, `View.setLayoutDirection`
  was confirmed `@RemotableViewMethod`; EN/AR strings have the same keys and format arguments; the bar geometry was rendered in a
  Python port to check proportions, colours, RTL and dark mode against the reference.
- **Not possible here:** `flutter build apk --debug --flavor customerDev` stops with "No Android SDK found" (`dl.google.com` is
  blocked in this sandbox), so AAPT2 resource linking, the real device and the shade rendering were not exercised. The repo has
  no JVM unit-test setup, so none was added.

## 6. Check on a phone before release (Android 13+ and one older, light and dark, English and Arabic)

1. Fresh install, sign in: no permission dialog. Place an order: the rationale sheet appears over the order page; "Allow" shows the
   system dialog; the card appears. "Not now": no dialog, and the sheet does not return the next day.
2. Card: tile, headline, status line, bar as in section 1; expand it; tap the card and "Track order": the order page opens, also
   from a cold start.
3. Move the order in the admin: the same card updates in place, silently, with no second card.
4. Swipe the card away, then move the order on: it does not come back. Cancel an order: grey bar and "Order cancelled".
5. Two orders: summary card and two children. Deliver one: "Delivered at hh:mm", swipeable; gone after 30 minutes.
6. Arabic: right to left, scooter faces left, times in the app language even when the phone is English. Font size largest: no clipping.
7. Turn notifications off in the system settings and place an order: the settings variant of the sheet, "Open settings" opens them.
8. With Firebase connected: kill the app, move the order, the card updates; the Dart handler must not add a second card.

## 7. Known limits and follow-ups

- Without Firebase the card stops updating when Android freezes the app in the background (no foreground service by design).
- Android 7.x (API 24-25) cannot time a notification out: an expired card is removed the next time the app touches the notifier.
- The logo tile is the bundled launcher icon. A remote logo from the dashboard theme (`images.logo`) would need Dart to download,
  resize and pass the bytes; not done.
- "Delivered at" for a card first learnt from a re-read uses the card's build time, which the server sets to "now" (within 30 minutes).
- iOS Live Activities (section 4) are not built.
