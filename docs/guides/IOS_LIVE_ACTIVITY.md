# iPhone Live Activity: the order on the lock screen and in the Dynamic Island

The same four-stage card that Android shows in the notification shade ([LIVE_ORDER_NOTIFICATION.md](LIVE_ORDER_NOTIFICATION.md)), as an iOS
**Live Activity**: it sits on the lock screen and in the Dynamic Island and moves by itself, like a match score. The customer sees the
arrival window, the status line, the four-segment progress bar, the driver's first name and a countdown to the driver's arrival, in
English or Arabic (right to left), in the maroon of the live theme, in light and dark.

**Status: written, not yet built.** The Swift code, the Flutter wiring and the server are in the repository and the server and Flutter parts
are tested. Nothing was compiled or run on an iPhone (there is no Xcode where this was written), and the Xcode project was deliberately not
edited: adding a widget extension by hand in `project.pbxproj` would risk breaking every iOS build. Until the Xcode steps in section 3 are
done, the iPhone app is exactly as it was (it shows the normal alert) and the Dart side answers "no Live Activity". Section 7 lists what
still has to be checked on a real phone.

| Where | What |
|---|---|
| `mobile/ios/LiveOrderWidget/` | the widget extension's Swift sources (not part of the Xcode project yet) |
| `mobile/ios/Runner/LiveOrderBridge.swift` | the app's method-channel bridge (not part of the Xcode project yet) |
| `mobile/ios/Runner/AppDelegate.swift` | finds the bridge by name, so the app builds with or without it |
| `mobile/ios/Runner/Info.plist` | `NSSupportsLiveActivities`, `NSSupportsLiveActivitiesFrequentUpdates` |
| `mobile/lib/core/live_order/live_activity_*.dart` | Dart wrapper, token registrar, wiring into `LiveOrderController` |
| `backend/app/Domain/Notifications/Services/Apns*.php`, `LiveActivity*.php` | Apple push client, config, token store, the pusher |
| Dashboard, Organization, Push notifications, **iPhone live activity** | the Apple key, Test, switch |

## 1. How it works

```
order changes ──> OrderProgress (the card)  ──> PushNotifier ──┬─> FCM  : the normal alert / Android card
                                                               └─> APNs : Live Activity update / end   (api.push.apple.com, HTTP/2)

iPhone app (in front) ── LiveOrderController ── albahar/live_activity ──> LiveOrderBridge ──> ActivityKit
                                  │                                           │ Activity.request(pushType: .token)
                                  │          POST /me/live-activities  <──────┘ push token (hex) of the running activity
                                  └─ sign-out: endAll() + DELETE /me/live-activities?device_id=...
```

1. The customer places an order with the app open. `LiveOrderController` hands the state to the bridge, which **starts** the activity
   (`Activity.request`, `pushType: .token`). ActivityKit only lets an app in the foreground do that.
2. Apple gives the activity a **push token**. The bridge reports it to Dart, `LiveActivityRegistrar` posts it to
   `POST /api/v1/me/live-activities` (for the signed-in customer only).
3. Every time the server builds a card for that order (`PushNotifier::refreshOrder`, the same trigger as the FCM card) it also sends an
   ActivityKit push to the tokens of that order: `update` while the order is going, `end` when it is final. Nothing about this needs Firebase.
4. While the app is open, Dart also updates the activity directly from the same state, so the lock screen is right even before a push
   arrives. When the app is closed only the server's pushes move it.
5. On sign-out the app ends every activity and asks the server to forget this phone's tokens.

`PushNotifier` sends the Live Activity update **in addition to** the normal alert (the alert is still the one notification for a change;
the Live Activity update carries no alert of its own). A customer who switched "order updates" push off in the app gets neither.

## 2. Server

### Apple key (owner, once)

Dashboard, **Organization, Push notifications**, section **iPhone live activity** (same page as Firebase; needs `settings.edit`). The page
explains every step in English and Arabic; in short:

1. developer.apple.com/account, **Membership details**: copy the **Team ID** (10 letters and digits).
2. **Certificates, Identifiers & Profiles, Identifiers**: `qa.albahar.customer` must have **Push Notifications** ticked (Xcode does this).
3. **Keys**, the **+** button: name it "Al Bahar push", tick **Apple Push Notifications service (APNs)**, Continue, Register, **Download**.
   Apple lets you download the `.p8` file **once**. The page shows the **Key ID** (10 letters and digits; also in the file name `AuthKey_<Key ID>.p8`).
4. On the dashboard page: Team ID, Key ID, bundle ID `qa.albahar.customer`, environment **Automatic**, upload (or paste) the `.p8`, **Save changes**.
5. **Test**: signs a provider token with the saved key and asks Apple to deliver a Live Activity push to a device token that cannot exist.
   Apple checks the key, team, key ID and topic *before* it looks at the device, so the answer `BadDeviceToken` proves the setup (the page says
   "Connected"). Nothing reaches any phone. Failures are explained in plain words: key / key ID / team not accepted, server clock wrong,
   topic (bundle ID) not allowed, rate limited, Apple down, server cannot reach Apple, **PHP without HTTP/2**.
6. Turn the **switch** on. It cannot be turned on before everything is saved. Removing the key turns it off.

The Apple key is separate from the Firebase key. **Server requirement:** Apple speaks HTTP/2 only, so PHP's curl must be built with
HTTP/2 (nghttp2); the project's Docker image (`php:8.3-fpm-alpine`) normally is (check with
`php -r 'var_dump(curl_version()["features"] & CURL_VERSION_HTTP2);'`, which must not print `int(0)`). If **Test** says "no HTTP/2" ask the host for such a build.

| Setting (group `push`, sealed where noted) | |
|---|---|
| `push.apns.enabled` | master switch for Live Activities (off until switched on; independent of the Firebase switch) |
| `push.apns.team_id`, `push.apns.key_id` | 10 characters each |
| `push.apns.bundle_id` | default `qa.albahar.customer`; the topic sent to Apple is `<bundle id>.push-type.liveactivity` |
| `push.apns.environment` | `auto` (each token says whether it is a sandbox or a production token), `production` or `sandbox` (forces one Apple host) |
| `push.apns.private_key` | **secret**, PEM of the `.p8`, sealed like the Firebase key, write-only, never shown or logged |

`App\Domain\Notifications\Services\ApnsConfig` is the only reader. Saves are audited (`push.apns_key_replaced/removed`, `push.apns_switched_on/off`,
`push.apns_tested`, and `settings.updated` with `[secret]`); the key and the tokens are never written to a log.

### Tokens

Table `live_activity_tokens` (migration `2026_11_15_300000`): one row per token, `kind = activity` (the push token of ONE running activity, with its
`order_id`) or `start` (the phone's push-to-start token, iOS 17.2+, no order), `environment` (`production` | `sandbox`), `device_id`, `locale`,
`disabled_at`. A token is unique; reporting it again refreshes the row. Customer endpoints (Sanctum, `customer` ability), under `me`:

| Request | Meaning |
|---|---|
| `POST /api/v1/me/live-activities` `{order_id, push_token, environment?, device_id?, locale?, app_version?}` | report the token of a running activity. **The order must be the caller's own** (404 otherwise, nothing is stored). The newest token of one activity on one phone is the only one that stays on (Apple rotates them). 30 per minute. |
| `POST /api/v1/me/live-activities/start-token` `{push_token, ...}` | report the phone's push-to-start token (newest per phone). 10 per minute. |
| `DELETE /api/v1/me/live-activities` `{push_token?` or `order_id?` or `device_id?}` | switch off one token, an order's activities, or everything this phone reported (sign-out). Only the caller's own tokens. |

`push_token` is hexadecimal, 32 to 250 characters. A token is never returned by any endpoint or written to the audit log. `push:prune-devices`
(daily) deletes activity tokens older than `platform.live_order.activity_token_keep_days` (2) and switched-off ones, and switches off push-to-start
tokens not seen for 60 days. Staff tokens (403) and logged-out callers (401) cannot use these routes.

### What is sent

`LiveActivityPusher` sends one push per token, in the **language that token was reported with** (`en` or `ar`):

```
POST https://api.push.apple.com/3/device/<token>        (sandbox: api.sandbox.push.apple.com, per token or forced in the dashboard)
authorization: bearer <ES256 provider token: iss = Team ID, kid = Key ID, kept 40 min>
apns-push-type: liveactivity
apns-topic: qa.albahar.customer.push-type.liveactivity
apns-priority: 10 (stage change, end, start) | 5 (the bar, the window or the countdown moved)
apns-expiration: now + 1 h
{ "aps": { "timestamp": <the card's seq in seconds>, "event": "update" | "end",
           "content-state": { ... },  "stale-date": now + 30 min      (update)
                                      "dismissal-date": now + 30 min  (end) } }
```

`content-state` (the Swift `LiveOrderAttributes.ContentState`; a value the card does not have is left out, times are Unix seconds):

| Key | Meaning |
|---|---|
| `phase`, `detail` | `placed` `preparing` `on_the_way` `ready` `delivered` `collected` `cancelled` `failed` and the finer state |
| `step`, `total` | stage 0..3 of 4 (the filled segments) |
| `sub` | 0..1, how far through the stage (omitted when there is no estimate) |
| `title`, `body` | the headline ("Arrives at 6:35pm-6:55pm") and status line, in the customer's language |
| `step_label` | short stage name |
| `eta_label`, `eta_from`, `eta_to` | the arrival window as text and as times |
| `eta_at` | the driver's arrival as one moment, only while on the road: the widget counts down to it by itself |
| `driver_first_name` | first name only |
| `done` | the order is over |
| `lang` | `en` or `ar`: language and reading direction |

It never carries the driver's phone number, the vehicle, the address or the customer's number: a Live Activity is readable on a locked
screen. (The Dart side drops `driver_phone` before it reaches Swift as well.)

- **Order of updates:** `timestamp` is the card's `seq` in seconds; Apple drops an update older than the one it applied last.
- **Final order:** `event: end` with the last state; the activity stays on the lock screen 30 minutes (`keep_after_done_minutes`) and the token is switched off.
- **Errors, never into the order flow:** `410`/`Unregistered`, and `400 BadDeviceToken` while each token's own environment is trusted, switch the token
  off. A refused key or topic (`InvalidProviderToken`, `TopicDisallowed`...) never switches a token off. `429` and `5xx`/no connection are retried
  `platform.live_order.apns_retries` (2) times with `apns_backoff_ms` (250 x attempt); `ExpiredProviderToken` replaces the provider token once and
  sends again. A failed update counts as "not sent", so the next change of the card tries again. Everything runs on the `notifications` queue.
- **Remote start (off by default):** `platform.live_order.activity_remote_start` (config/platform.php). When on, an order that has no running
  activity gets a `start` push through the phone's push-to-start token (with `attributes-type: LiveOrderAttributes`, the attributes, an `alert` and
  `input-push-token: 1`), once per order and phone, so the activity can appear while the app is closed (iOS 17.2+). It is off because a phone that
  starts the activity itself (app open) and a server that starts it at the same moment would show it twice; turn it on after testing on a device (7.5).

Tests: `cd backend && vendor/bin/pest tests/Feature/Push/ApnsClientTest.php tests/Feature/Push/LiveActivityTokensTest.php
tests/Feature/Push/LiveActivityPushTest.php tests/Feature/Push/PushAdminApnsTest.php` (HTTP/2 request, headers, ES256 signature verified with the
key's public half, host per environment, retries and back-off, 410 and BadDeviceToken retirement, refused key, expired provider token, the Test
button's every answer, token endpoints and ownership, rate limit, pruning, the update / end / start payloads, languages, priorities, no private data,
Firebase and Apple independent of each other, the dashboard section, sealed key, audit, permissions).

## 3. Xcode steps (once, on a Mac)

Xcode 15 or later; an iPhone with iOS 16.2 or later to try it (the Dynamic Island needs an iPhone 14 Pro or later, the lock screen any iPhone).
**Do not edit `project.pbxproj` by hand.**

1. Open **`mobile/ios/Runner.xcworkspace`** (the workspace, not the project).
2. **File, New, Target...**, iOS, **Widget Extension**. Product Name **`LiveOrderWidget`**, Team = the app's team. Untick **Include Live Activity**,
   **Include Configuration App Intent** and (Xcode 16) **Include Control**. Finish. When asked to activate the new scheme, choose **Cancel** (keep
   the Runner scheme). The new target is called `LiveOrderWidgetExtension`, bundle ID `qa.albahar.customer.LiveOrderWidget`, and is embedded in Runner automatically.
3. In the new `LiveOrderWidget` group, delete every Swift file the template made (`LiveOrderWidget.swift`, `LiveOrderWidgetBundle.swift`,
   `LiveOrderWidgetLiveActivity.swift`, `AppIntent.swift`, `LiveOrderWidgetControl.swift`): **Move to Trash**. Keep `Info.plist` and `Assets.xcassets`.
4. Add the extension's sources: right-click the `LiveOrderWidget` group, **Add Files to "Runner"...**, select the five files of
   `mobile/ios/LiveOrderWidget/` (untick **Copy items if needed**).
   - `LiveOrderWidgetBundle.swift`, `LiveOrderLiveActivity.swift`, `LiveOrderViews.swift`, `LiveOrderStyle.swift`: target **LiveOrderWidgetExtension** only.
   - `LiveOrderAttributes.swift`: **both** `Runner` and `LiveOrderWidgetExtension` (the app starts the activity, the extension draws it; the JSON keys in this file are the server contract).
5. Add the app's bridge: right-click the `Runner` group, **Add Files to "Runner"...**, `mobile/ios/Runner/LiveOrderBridge.swift`, target **Runner** only.
6. **Deployment targets.** `LiveOrderWidgetExtension`: General, Minimum Deployments **iOS 16.2**. Runner may stay at 15.0: every ActivityKit call in
   the bridge is behind `#available(iOS 16.2, *)`, and on older systems the bridge answers "unavailable". Runner, Build Settings, **Other Linker Flags**:
   add `-weak_framework ActivityKit`, so an iOS 15 phone does not need the framework to open the app.
7. **Signing.** Select `LiveOrderWidgetExtension`, Signing & Capabilities: same Team, automatic signing (Xcode registers the App ID
   `qa.albahar.customer.LiveOrderWidget`). With manual signing create that identifier and a profile for it in the Apple Developer account. The extension needs
   no capability of its own; the **Runner** target keeps **Push Notifications** and **Background Modes, Remote notifications** (already there).
   `Info.plist` of Runner already has `NSSupportsLiveActivities` and `NSSupportsLiveActivitiesFrequentUpdates` (both true).
8. Build and run the **Runner** scheme on a real iPhone (`flutter run` from `mobile/` works; so does `flutter build ios`). A simulator can show the
   activity but never receives Apple pushes.

If the extension is not added, nothing breaks: `AppDelegate` looks the bridge up by name (`NSClassFromString("LiveOrderBridge")`), finds nothing, and
Dart treats every call as "unavailable". If only the bridge is added without the extension, `Activity.request` fails and the answer is again "unavailable".

## 4. What the customer sees

- **Lock screen:** the Al Bahar mark (sun over two waves, drawn in code) and the headline ("Arrives at 6:35pm-6:55pm"), the status line, on the
  right a countdown ("Arrives in 12:34", counts down by itself) while the driver is on the road, the four-stage bar (check, store, truck or bag,
  pin or check; done stages are filled circles with a check, the current stage has a soft ring, later stages are grey glyphs; a cancelled or failed
  order is grey with a cross) and, once a driver is on the job, a chip "Driver: Ahmed". Text colours follow the system light or dark; the brand colour is
  lightened in dark mode until it keeps 3:1 against the surface (the same rule as the Android card).
- **Dynamic Island:** compact (the mark on the left, the countdown or the stage glyph on the right), minimal (the stage glyph), expanded (mark, headline,
  countdown, the bar and the status line with the driver chip). It is always dark.
- **Arabic:** right to left (the bar and every glyph that has a direction are mirrored), Arabic words, Western digits like the rest of the app. The language is
  the app's, not the iPhone's: texts come from the server per token, the few fixed words are in `LiveOrderStyle.swift`.
- **Tap:** opens the order (`albahar://orders/<id>`, the app's own deep link).
- **Ending:** a finished order shows its last state ("Delivered at 9:31 PM") for 30 minutes, then Apple removes it; the customer can also swipe it away.
  Apple ends an activity itself after 8 hours.
- **Off switches:** Settings, Al Bahar, Live Activities (the system's), and the app's order-update notifications. With Live Activities off the app falls back to the
  normal notification.

## 5. Flutter

`lib/core/live_order/live_activity_platform.dart` (`MethodChannelLiveActivityPlatform`, channel `albahar/live_activity`: `isAvailable`, `show`, `end`,
`endAll`, `pendingTokens`, and Swift to Dart `token`), `live_activity_registrar.dart` (`RestLiveActivityApi`, `LiveActivityRegistrar`) and the wiring in
`live_order_controller.dart` (`liveActivityPlatformProvider`, `liveActivityRegistrarProvider`).

- Every call is best effort and never throws: not an iPhone, iOS before 16.2, Live Activities off, no widget in the build, a platform error: the answer is
  "unavailable", and the controller then uses the plain progress notification, as before. When the activity carries the order, the plain notification is not shown too.
- Tokens are reported only while a customer is signed in, once each (again when one changes, when the app's language changes, and after a failure when the app comes
  back to the front or the network returns). Sign-out ends every activity, takes this phone's tokens back and clears what was kept.
- Android and the web are untouched (the Android card is `qa.albahar.customer.liveorder`).

Tests: `flutter test test/core/live_order/live_activity_platform_test.dart test/core/live_order/live_activity_registrar_test.dart
test/core/live_order/live_order_activity_test.dart` (the channel with a fake native side: start, update, end, "unavailable" without a native side or on Android,
tokens in both directions, no phone number; the registrar signed in and out, repeats, failures, language change; the controller on an iPhone, fallback, sign-out).

## 6. Limits Apple sets

- An activity can only be **started** by the app while it is in the foreground (or remotely, iOS 17.2+, with the push-to-start token).
- At most a few activities per app at once; an activity lives at most 8 hours.
- High-priority pushes have an hourly budget. `NSSupportsLiveActivitiesFrequentUpdates` raises it; the customer can switch "Frequent updates" off
  in the settings. The server sends the bar and countdown moves at priority 5, which is not counted. The card pushes at most every 15 seconds per order anyway.
- The activity's content is limited to about 4 KB per push; ours is a few hundred bytes.

## 7. What could not be verified here, and what to check on a phone

Nothing in section 3 or in the Swift files was compiled or run. The Swift is written against the ActivityKit / WidgetKit APIs of iOS 16.2 (and 17.2 for
push-to-start) and reviewed line by line, but expect to fix small compile errors on the first build. In particular check:

1. **It builds.** The extension and the Runner target compile; the weak link for ActivityKit is set. SF Symbol names (`storefront`, `box.truck`, `bag`,
   `mappin.and.ellipse`) exist in the SDK you use; a missing one draws nothing and does not crash.
2. **Start.** Place an order on the phone: the activity appears on the lock screen and the island; the row appears in `live_activity_tokens`
   (`select kind, environment, order_id, last_pushed_at from live_activity_tokens order by created_at desc limit 5;`) a moment later.
3. **Push updates.** Move the order in the admin (Accepted, Preparing, Ready, assign a driver, the driver's steps) with the app in the background and then closed:
   the lock screen moves, the countdown runs and stops at zero, the final state ends the activity. Xcode builds are **sandbox** tokens, TestFlight and the store are
   **production**: the Apple environment stays on Automatic.
4. **Arabic and dark.** Switch the app language and the iPhone's appearance: right to left, mirrored bar, readable maroon in dark, no clipped text with the largest text size.
5. **Remote start** (iPhone on iOS 17.2+): with `activity_remote_start` on, kill the app, place an order from the website: the activity should start by itself and its token
   should be reported at the next launch. If the app is never woken by Apple to report the new activity's token, the activity still starts but then only the
   updates the app sends itself reach it; leave the option off if that is what you see.
6. **Sign-out** ends the activity and the rows are switched off.

## 8. Troubleshooting

| Symptom | Cause and fix |
|---|---|
| Test: "Apple did not accept the key..." | Key ID, Team ID and the `.p8` do not belong together, or the key was revoked. |
| Test: "not allowed for this app" | The bundle ID is wrong, or the key was created without Apple Push Notifications service (APNs). |
| Test: "PHP cannot speak HTTP/2" | curl without nghttp2. Ask the host. |
| Test works, nothing arrives | The switch is off, no `live_activity_tokens` row (the widget is not in the build, Live Activities are off in the iPhone settings, the app was not in front when the order was placed), or sandbox vs production (leave the environment on Automatic). |
| Tokens keep being switched off | `BadDeviceToken`: an Xcode (sandbox) token sent to production. Environment Automatic fixes it; a forced environment never switches tokens off but then they fail. |
| Updates arrive late or not at all after many | Apple's hourly budget: keep `NSSupportsLiveActivitiesFrequentUpdates`, check "Frequent updates" in the iPhone's settings for the app. |
| Two activities for one order | Remote start is on and the app also started it: turn `activity_remote_start` off (see 2). |
