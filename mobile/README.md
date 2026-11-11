# Al Bahar Home & Living — Customer App (Flutter)

البحر للمنزل والمعيشة — the customer app for Android and iOS (Flutter Web-ready), built
against the Laravel REST API in `../backend` (`docs/api/API_CONTRACT.md`).
Arabic (RTL, default) and English (LTR), QAR, Asia/Qatar time.

- Flutter **3.47 stable** / Dart **3.13**
- State: **Riverpod 3** (hand-written `Notifier`/`AsyncNotifier`, no code generation)
- Routing: **go_router 18** (deep links, auth guard)
- Network: **dio 5** with auth/refresh, locale, request-id, idempotency and redacted logging
- Contract assumptions: [`docs/API_ASSUMPTIONS.md`](docs/API_ASSUMPTIONS.md)

---

## 1. Setup

```bash
cd mobile
flutter pub get          # l10n is generated automatically (flutter: generate: true)
flutter analyze          # must be clean
flutter test             # unit + widget tests
```

Generated localisation code (`lib/l10n/generated/`) is committed, so the project builds
without running a generator. If you edit `lib/l10n/app_en.arb` / `app_ar.arb`, run
`flutter gen-l10n` (or any `flutter run/build`) and commit the result. Keys must exist
in both files (a test enforces this).

## 2. Flavours & dart-defines

| Flavour | Entry point | Android applicationId | Default API |
|---|---|---|---|
| dev | `lib/main_dev.dart` | `qa.albahar.customer.dev` | `http://10.0.2.2:8000/api/v1` |
| staging | `lib/main_staging.dart` | `qa.albahar.customer.staging` | `https://staging-api.albahar.qa/api/v1` |
| prod | `lib/main_prod.dart` | `qa.albahar.customer` | `https://api.albahar.qa/api/v1` |

`lib/main.dart` reads `--dart-define=FLAVOR=…` instead (useful for web/CI).

| dart-define | Purpose | Default |
|---|---|---|
| `FLAVOR` | `dev` \| `staging` \| `prod` (only for `main.dart`) | `dev` |
| `API_BASE_URL` | REST base incl. `/api/v1` | per flavour (table above) |
| `REVERB_APP_KEY`, `REVERB_HOST`, `REVERB_PORT`, `REVERB_SCHEME` (`ws`/`wss`) | Laravel Reverb (Pusher protocol) for live auctions | per flavour |
| `PUSH_ENABLED` | `false` disables Firebase entirely | `true` (auto-disabled if Firebase is not configured) |
| `MAP_TILE_URL` | Map tile template (`{z}/{x}/{y}`) | OpenStreetMap — **replace for production** (OSM tile policy) |

Use a file per environment: `cp config/dev.example.json config/dev.json`, then

```bash
# Android emulator against a local backend (php artisan serve --host=0.0.0.0)
flutter run --flavor customerDev -t lib/main_dev.dart --dart-define-from-file=config/dev.json

# Physical device on the same Wi-Fi
flutter run --flavor customerDev -t lib/main_dev.dart --dart-define=API_BASE_URL=http://192.168.1.20:8000/api/v1

# Staging / prod
flutter run --flavor customerStaging -t lib/main_staging.dart --dart-define-from-file=config/staging.json
flutter run --release --flavor customerProd -t lib/main_prod.dart --dart-define-from-file=config/prod.json

# Web (compile smoke test; also runnable)
flutter build web --release --dart-define=FLAVOR=staging
```

> iOS: product flavours are implemented through dart-defines + entry points; the Xcode
> project has the single `Runner` scheme (run without `--flavor`). To give staging/dev
> different bundle ids on iOS, add `Debug-dev`/`Release-staging` … configurations and
> schemes in Xcode (see §8).

Dev builds allow cleartext HTTP (Android `src/dev/AndroidManifest.xml`); staging/prod are HTTPS-only.

## 3. Architecture

```
lib/
  main.dart, main_dev.dart, main_staging.dart, main_prod.dart, bootstrap.dart, app.dart
  core/
    config/      AppConfig (flavours, dart-defines, Reverb URI)
    network/     ApiClient (envelope unwrap → typed ApiException), AuthInterceptor (single-flight
                 refresh), Locale/RequestId/Idempotency helpers, RedactingLogInterceptor (debug only)
    storage/     SecureTokenStore (flutter_secure_storage), AppPreferences (shared_preferences)
    l10n/        context.l10n, describeError()
    theme/       tokens (palette, 8-pt spacing, radii, durations, AppColors extension), AppTheme
    router/      app_router (routes, redirects, auth guard), deep_links (links & push → route), app_shell
    push/        PushService (FCM register/refresh/unregister, foreground/background/terminated taps)
    realtime/    PusherClient (Pusher protocol v7 over web_socket_channel, reconnect + resubscribe)
    widgets/     design system: AppButton, AppTextField, PriceTag/MoneyText/AnimatedMoney, RatingStars/
                 RatingInput, Skeletons, Empty/Error/Offline states, SectionHeader, ProductCardView,
                 QuantityStepper, showAppBottomSheet, StatusChip, CountdownText, HtmlContent, paging
    models/ utils/   Money/EffectivePrice/ImageRef, Paginated, json readers, Qatar time & phone, haptics
  features/<feature>/{data, domain, presentation[/state]}
    auth, onboarding, home, catalog, branches, cart, checkout, orders, wishlist, auctions,
    notifications, profile, reviews, support, settings
  l10n/  app_en.arb, app_ar.arb (+ generated/)
```

Rules the code follows:

- **The server is authoritative.** The client sends identifiers and quantities only; every price,
  total, fee, stock level and auction state is rendered from the API (`Money.formatted`).
- User-scoped providers watch the session and reset on sign-in/out; catalog providers watch the
  locale and the preferred branch.
- Every list has skeleton, empty, error (offline/timeout/404/5xx with request id) states.
- Idempotency keys: one per confirmed `quote_hash` for orders, one per bid amount, one per payment
  retry — network retries reuse the key, so nothing is ever duplicated.

## 4. Screens

Splash · language · onboarding · phone OTP (request → verify → register if `registration_required`)
· password login · password reset · home (server-driven sections, unknown types ignored) ·
categories (nested) · category listing · search (debounced, recent) · product listing (filters
sheet, sort, infinite scroll) · product detail (zoomable gallery with hero, variant swatches/chips,
specs, dimensions, warranty, assembly, branch stock & price sheet, delivery/pickup chips, reviews,
Q&A, related, wishlist, share, back-in-stock alert) · branches (list/search, nearby with location
consent, map) · branch details (hours, holidays, services, call, directions) · cart (steppers,
swipe-to-remove, issue & price-change warnings, coupon, re-validation) · checkout (delivery/pickup,
address or showroom + slot, payment availability, loyalty points, split-fulfilment plan, full fee
breakdown, 409 `quote_changed` re-confirmation) · SADAD payment (in-app browser + polling) ·
orders (filters) · order detail (timeline, shipments, delivery tracking, pickup QR pass, cancel,
reorder, invoice PDF, return request, pay now) · wishlist · auctions (live/upcoming/ended) ·
auction detail (registration + terms + deposit) · live lot (realtime, server-time countdown,
animated bid, quick bids, confirm, auto-bid, watch, history) · my bids / watchlist / wins + win
checkout · notifications centre · account · edit profile & password · addresses (Qatar blue-plate
fields + map pin) · notification preferences · rewards & referral · write review (photos) ·
support tickets (list, create, thread) · CMS pages · settings (language, theme) · account
deletion (typed confirmation, 14-day grace, cancel) · sign out / sign out everywhere.

Deep links: `albahar://products|orders|auctions|auctions/lots|categories|payments/{id}/return|support/{id}`
and `https://albahar.qa/app/...` (see `core/router/deep_links.dart`).

## 5. Testing

```bash
flutter test                                  # 94 tests: unit, state, widget (EN + AR/RTL), app smoke
flutter test integration_test/app_smoke_test.dart -d <device-id>            # on a device/emulator
flutter test integration_test/app_smoke_test.dart -d <device-id> \
  --dart-define=LIVE_API=true --dart-define-from-file=config/staging.json  # against staging
```

| Suite | Covers |
|---|---|
| `test/core/network_test.dart` | error-envelope mapping (401/403/404/409/422/429/5xx/offline/timeout), headers, Laravel query encoding, **single-flight refresh** (3 concurrent 401s → 1 refresh), refresh failure → logout, retry-once, offline refresh keeps session, log redaction |
| `test/core/routing_test.dart` | deep-link parsing, redirects (language → onboarding → auth guard), notification payload → route, push foreground preference filtering, device register/unregister |
| `test/features/repositories_test.dart` | repositories via `http_mock_adapter` and a recording adapter (auth, catalog, home unknown sections, branches, cart, checkout idempotency, orders, auctions) |
| `test/features/state_test.dart` | checkout `quote_changed` flow + idempotency, payment-method/points correction, cart optimistic rollback, **branch switching**, session sign-in/out/expiry, live lot sequencing (dedupe, gap replay), server-time offset, bid idempotency, paging |
| `test/widgets/*` | design-system widgets in LTR and **RTL**, localisation (both locales, plurals, ARB parity), app boot smoke (EN/AR, deep link guard) |

## 6. Firebase (push) setup

The app runs without Firebase: initialisation is wrapped in `try/catch`, and push silently
disables itself if no configuration is present.

1. Create Firebase projects (recommended: one per environment) and add apps:
   Android `qa.albahar.customer` (+ `.dev`, `.staging`), iOS `qa.albahar.customer`.
2. Android: place `google-services.json` in `android/app/src/<flavor>/` (or `android/app/` for a
   single project). The Google Services Gradle plugin is applied automatically when a file exists.
3. iOS: add `ios/Runner/GoogleService-Info.plist` to the Runner target in Xcode. Enable
   *Push Notifications* and *Background Modes → Remote notifications* capabilities and upload an
   APNs key in Firebase (Project settings → Cloud Messaging).
   (Alternatively run `flutterfire configure` and pass `DefaultFirebaseOptions` in
   `FirebasePushMessaging.initialize`.)
4. The backend sends FCM messages with a `notification` block (title/body localised server-side)
   and `data: {event, deep_link, notification_id}`. Android channel id: `albahar_default`.
5. Token lifecycle: registered after sign-in (`POST /me/devices`), re-registered on refresh,
   removed on sign-out (`DELETE /me/devices`) **before** the session is revoked.

## 7. Deep links / universal links

- Android App Links: host `https://albahar.qa/.well-known/assetlinks.json` with the release
  signing SHA-256 for `qa.albahar.customer` (and staging/dev ids if desired).
- iOS Universal Links: host `https://albahar.qa/.well-known/apple-app-site-association`:
  ```json
  { "applinks": { "details": [ { "appIDs": ["<TEAM_ID>.qa.albahar.customer"], "components": [ { "/": "/app/*" } ] } ] } }
  ```
  `Runner.entitlements` already declares `applinks:albahar.qa`.
- Custom scheme `albahar://` is registered on both platforms (SADAD return URL).

## 8. Release builds & signing

### Android

```bash
keytool -genkey -v -keystore ~/albahar-upload.jks -keyalg RSA -keysize 2048 -validity 10000 -alias upload
cat > android/key.properties <<EOF
storePassword=…
keyPassword=…
keyAlias=upload
storeFile=/absolute/path/albahar-upload.jks
EOF
flutter build appbundle --release --flavor customerProd -t lib/main_prod.dart \
  --dart-define-from-file=config/prod.json --obfuscate --split-debug-info=build/symbols
```

`key.properties` and keystores are git-ignored. Use Play App Signing; keep the upload key in a
password manager/CI secret store. R8 minification is enabled (`proguard-rules.pro`).
`minSdk` ≥ 23; core library desugaring is enabled (required by flutter_local_notifications).

### iOS

1. Open `ios/Runner.xcworkspace` in Xcode, select team, bundle id `qa.albahar.customer`.
2. Capabilities: Push Notifications, Background Modes (Remote notifications), Associated Domains.
   Set `aps-environment` to `production` for App Store builds (Xcode does this per profile).
3. Add `en.lproj/InfoPlist.strings` and `ar.lproj/InfoPlist.strings` (already in `ios/Runner/`)
   to the Runner target once (drag into the project, "Localize…") so the home-screen name shows
   "البحر" in Arabic. `CFBundleLocalizations` already lists `ar`, `en`.
4. Build:
   ```bash
   flutter build ipa --release -t lib/main_prod.dart --dart-define-from-file=config/prod.json \
     --obfuscate --split-debug-info=build/symbols
   ```
   Upload with Xcode Organizer or Transporter.

## 9. Store privacy notes

Data the app collects / transmits (for Play Data safety & App Store privacy labels):

| Data | Purpose | Notes |
|---|---|---|
| Name, phone, email (optional), date of birth/gender (optional) | Account, orders, support | Linked to the user |
| Addresses & optional map pin | Delivery | Linked; location only when the user pins it |
| Approximate/precise location | Nearby showrooms, address pin | Requested on demand, not stored by the app, not used for tracking |
| Photos (camera/library) | Review photos | Only the photos the user selects |
| Purchase history, payments status | Orders | Card data is entered on SADAD's page, never in the app |
| Device push token, install id, app version | Notifications | Deleted on sign-out |
| Auction bids | Auctions | Public history shows masked bidder ids |

No third-party advertising/analytics SDKs are included. Tokens are stored in Keychain /
Android Keystore-backed storage; Android backups are disabled. Logs never contain tokens, OTPs
or passwords (debug-only redacting logger). Account deletion is available in-app
(Settings → Delete account) with a 14-day grace period, as required by both stores.
Map tiles default to OpenStreetMap; configure a commercial tile provider (`MAP_TILE_URL`) for
production traffic and update the attribution string if required.

## 10. Known limitations

See the hand-over report; in short: no device/emulator was available in the build environment
(Android/iOS builds are unverified beyond `flutter analyze`, tests and `flutter build web`),
iOS per-flavour schemes and the localized `InfoPlist.strings` registration need a one-time Xcode
step, Firebase config files must be added, and the API assumptions in
`docs/API_ASSUMPTIONS.md` should be confirmed against the backend.


## Driver and branch staff apps

Three apps share this codebase (own package id, name and icon each; they install side by side):

| App | Package | Entry point | Flavour (prod) |
|---|---|---|---|
| Customer | `qa.albahar.customer` | `lib/main.dart` | `customerProd` |
| Driver | `qa.albahar.driver` | `lib/main_driver.dart` | `driverProd` |
| Branch staff | `qa.albahar.staff` | `lib/main_staff.dart` | `staffProd` |

```bash
flutter run --flavor driverDev -t lib/main_driver.dart --dart-define=API_BASE_URL=http://10.0.2.2:8000/api/v1
flutter build apk --release --flavor staffProd -t lib/main_staff.dart --dart-define=FLAVOR=prod --dart-define=API_BASE_URL=https://<server>/api/v1
```

Both sign in with a staff **email + password** (`POST /admin/auth/login`). The driver account must be a *delivery driver* of at least one branch;
the staff account needs `orders.view`. Dispatch (auto or by a branch employee) is chosen per branch in the admin panel: **Branches > edit >
Who assigns deliveries**. The GitHub workflow "Android APK" publishes one release per app per run.
