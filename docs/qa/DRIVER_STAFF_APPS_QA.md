# Driver and branch staff apps: QA log

Scope: `mobile/lib/ops/**`, `lib/main_driver.dart`, `lib/main_staff.dart` and the ops parts of `lib/core` (push, update gate, token refresh).
Every action was checked against the backend controller, service and enum it calls (`DispatchController`, `OrderOpsController`,
`DeliveryService`, `OrderService`, `PaymentService::recordCollection`, `FulfillmentStatus`, `DeliveryStatus`, `PermissionRegistry`).

Result: `flutter analyze` 0 issues. `flutter test` 405 passed (136 of them are the ops tests added here, in `mobile/test/ops/`, next to the 5 that existed).
Backend `DispatchTest` 11 passed (1 added).

Test matrix used by the screen tests (`test/ops/ops_test_kit.dart`): 360x640 and 390x844 phones, 768x1024 tablet; English (LTR) and Arabic
(RTL); text scale 1.0 and 1.3; light and dark. Each screen is checked for no overflow or exception, the Android 48 dp tap-target guideline and the
labelled-target guideline.

## Bugs found and fixed

Severity: H = loses data, money or the driver's work, or exposes a wrong state; M = wrong or confusing behaviour; L = polish.

| # | Screen / form | Bug | Sev | Fix | Status |
|---|---|---|---|---|---|
| 1 | Push (shared core) | `register()` ran before `start()` finished and the app registered the token only on a sign-in event, so a saved session never registered for push after a cold start (customer app too) | H | `PushService.start()` is one memoised future that `register()` awaits; `OpsApp.initState` registers when a session is already saved; token-refresh listener is replaced instead of stacked | Fixed |
| 2 | Token refresh (shared core) | A 401 on a multipart upload (proof photo) replayed an already-sent `FormData` and failed with "already finalized" | H | `AuthInterceptor` replays `FormData.clone()` | Fixed |
| 3 | Delivery proof | Upload ran after the sheet closed: any failure lost the photo and the typed name; double submit possible | H | Upload happens inside the sheet; it stays open with an inline error and the photo kept; button blocks double taps; 120 s send timeout for photos | Fixed |
| 4 | Delivery proof (cash) | Cash field accepted anything; an unparsable value was silently sent as "no cash" and the server answered with an obscure 422; Arabic digits were not understood | H | Required, numeric, two decimals, not above the amount due, Arabic and Persian digits normalised | Fixed |
| 5 | Driver jobs | A failed or rescheduled delivery stayed in "My deliveries" with no button at all (server allows `failed -> out_for_delivery` and return to branch) | H | "Try delivery again" and "Return to branch" (with confirmation) | Fixed |
| 6 | Staff queue (client and `DispatchController`) | A failed delivery disappeared from every staff list, so nobody could bring it back | H | Backend: `failed` stage and `failed` count in the overview (1 test); app: Failed tab, "Delivery failed" tag, "Return to branch" with confirmation, gated on `deliveries.edit` | Fixed |
| 7 | Add product | A failed photo upload left a created product; tapping Save again created a duplicate product | H | Save continues from where it stopped (product id and uploaded photos remembered), progress "Uploading photo 2 of 3" | Fixed |
| 8 | Add product | AI describe timed out at 30 s while the server may ask Claude twice (up to ~2 min) | M | 150 s receive timeout for that call only | Fixed |
| 9 | Add product | With one photo slot left the gallery crashed silently (`pickMultiImage(limit: 1)` is invalid) | M | Single picker for the last slot | Fixed |
| 10 | Add product | Price 0 accepted; Arabic digits dropped by the filter | M | Price must be above zero; Arabic digits and decimal mark accepted and sent as plain digits; title max 255 like the API | Fixed |
| 11 | Add product | AI result silently overwrote typed descriptions; AI result applied to disposed controllers if the screen was closed meanwhile | M | Confirm before replacing; `mounted` check after the call | Fixed |
| 12 | Add product | Leaving was allowed while saving (orphaned half-uploaded product); category load error had no retry; studio request failure was silent | M | Back blocked with a message while saving; tap the field to retry; "product saved, studio request not sent" message | Fixed |
| 13 | Session | A session ended by the server left pushed screens (Add product) and sheets open above the sign-in screen, with no explanation | H | Navigator pops to root on sign-out; sign-in screen shows "Your session ended" | Fixed |
| 14 | Session | Sign-out only cleared the device: the token stayed valid on the server; a driver stayed "on duty" with a stale position; a wrong-app account kept a live token | H | Server logout (best effort), driver goes off duty first (with confirmation), refused accounts are logged out on the server | Fixed |
| 15 | Login | Failures showed the literal text "Error" (offline, timeout, 429); 426/429/422 not mapped; email not trimmed or spaces allowed; no autofill-friendly flags; show-password button had no label; Enter could double submit; staff with two-factor could not sign in at all | H | `describeError` mapping (offline, timeout, 429 with seconds, 422 field message), whitespace denied and trimmed, autocorrect off, labelled toggle, busy guard, two-factor code field when the server asks for it | Fixed |
| 16 | Driver duty | Duty switch could be tapped repeatedly and waited up to 12 s for a GPS fix | M | Busy spinner, one request, GPS wait capped at 4 s | Fixed |
| 17 | Driver location | Location refused or switched off: silent, driver looked on duty but was invisible; stream error left the tracker "running"; a standing driver sent nothing so the map showed a grey marker after 15 min | H | Red card with "Open settings", re-checked when the app returns to the foreground; stream errors stop the tracker and show the card; 60 s keep-alive repeats a recent fix (never one older than 10 min) | Fixed |
| 18 | Driver and staff lists | A single failed poll replaced the whole list with an error screen (every minute on a weak network); empty lists could not be pulled to refresh on Android | H | Last good list stays with a "Could not refresh" strip; empty lists are pullable | Fixed |
| 19 | Job and order cards | After a refused step (409 already taken or moved, 403) the card kept a stale button | M | Lists reload after every action, success or not; server message shown | Fixed |
| 20 | Staff home | Buttons shown to employees the API would refuse (view-only staff, no drivers permission); every real-time ping triggered 6 reloads | M | Buttons and tabs follow `orders.edit`, `deliveries.assign`, `deliveries.view`, `deliveries.edit`, `catalog.create`; pings debounced to one reload | Fixed |
| 21 | Fleet map | Map showed drivers of all the user's branches while the app showed one; a live ping stayed on the map forever even when the refresh was newer; hard-coded colours; call button announced the phone number instead of "Call <name>" | M | Fleet request carries the app's branch; newest of ping and refresh wins; palette colours; labelled | Fixed |
| 22 | Texts | Address words ("Zone", "Street", "Bldg"), the currency and the call label were English in Arabic mode | M | Address and money are formatted from `OpsStrings` in both languages; a test fails if a screen hard-codes them again | Fixed |
| 23 | Driver cards | Scheduled time was parsed but never shown; Call and Navigate could overflow at 1.3x text; phone dialler or maps failure threw an uncaught error | M | Scheduled time in Qatar time; buttons share the row; failure shows a message | Fixed |
| 24 | App bars | No language switch after sign-in; sign-out was an unlabelled icon; title could overflow | L | Menu (language, sign out with confirmation); title ellipsised | Fixed |
| 25 | Dark mode | Duty card, driver tiles and count tiles used fixed light colours | L | Theme colours | Fixed |
| 26 | Polling | Timers kept polling while the app was in the background and nothing refreshed on return | L | Poll only in the foreground, refresh when the app resumes | Fixed |

## Checked, no bug found

- Role and branch scope: `/admin/me` permissions, `branchFor` and `scope()` on the server; branch staff cannot read another branch (existing backend test); driver endpoints refuse non-drivers with 403 (`driver_not_eligible`), shown as "not set up as a delivery driver".
- Status steps match `DeliveryStatus` and `FulfillmentStatus` (accept, pickup, out-for-delivery, deliver, fail, return; accept, preparing, ready; assign). COD collection needs `payments.create` (granted to `delivery_employee`) and must equal the amount due.
- Login: `throttle:login` (5/min per email and IP) returns 429; the app shows the wait time. Tokens live in secure storage; refresh is single-flight and rotates.
- 426: `AppIdentityInterceptor` raises the update gate; tested from an API call inside the staff app.
- Heartbeat payload ranges (`speed_kmh`, `heading`, `accuracy`) fit the server validation; 60 requests per minute limit is far above one fix per 8 s.
- Unsafe requests are never retried automatically (only the single 401 replay after a token refresh).
- Controllers and timers: location subscription, keep-alive timer, polling timers, lifecycle listener, debouncer, map controller and every text controller are disposed (tested for the tracker and polling).

## Tests added (`mobile/test/ops/`)

- `driver_screens_test.dart`: 37 tests. Layout matrix (7 variants), loading, empty, offline, stale list, pull to refresh, duty switch and tracker lifecycle, location refused and restored, every job step, double tap, 409 and 403, failed delivery retry and return, fail reason with confirmation, proof sheet (photo required, cash validation, Arabic digits, double tap, offline keeps data, 422 message, camera refused, close with data asks, keyboard up in 7 variants).
- `staff_screens_test.dart`: 34 tests. Layout matrix, permission gating, step buttons, 409, 403, double tap, assign, failed tab and return, empty and stale states, drivers tab, live map layouts, newest position wins, branch passed to the fleet request.
- `add_product_test.dart`: 24 tests. Layout matrix, price and title validation, Arabic digits, keyboard types, half-failed save continues, 422, no leaving while saving, studio failure message, photo limit (single picker for the last slot), AI replace confirm, AI timeout and 429, closing during AI, leave confirmation, category retry.
- `session_and_wire_test.dart`: 30 tests. Login (empty, trimmed email, wrong password, 429, offline, 422, two-factor, wrong-app account, wrong permissions, double submit, autofill flags, Arabic and back), session expiry closing screens, sign-out (staff and driver), push registration on a saved session and the start/register race, 426 gate, wire format (multipart fields, timeouts, 401 replay of a photo upload, reason codes, product payload).
- `ops_strings_and_tracker_test.dart`: 11 tests. Every `OpsStrings` pair has English and Arabic with matching placeholders, no hard-coded currency or address words, amount formatter and parser, tracker timing with a fake clock (minimum gap, keep-alive, no timer after stop, stale fix not repeated, stream error reported).
- Backend: `DispatchTest` "keeps a failed delivery in the branch queue so staff can bring it back".

## Unfixed items and owner decisions

1. **Offline queue.** The driver app has no offline action queue. A step, a delivery proof or a cash confirmation made without a connection fails with the offline message and nothing is lost on screen (the proof sheet stays open with the photo), but the driver must retry when the network is back. A durable queue needs a decision on cash and photo handling while offline (replay order, conflicts with a dispatcher reassigning the job).
2. **Pickup scan, QR and handover.** The staff app only covers delivery orders (the server queue lists `type = delivery`). Click-and-collect verification (`POST /admin/pickup/verify`, `pickup/{reservation}/handover`) is not in the app and there is no QR scanner dependency in `pubspec.yaml`. Decision: add a scanner package (camera permission and store review) or keep pickup on the dashboard.
3. **Order detail and per-order notes.** Orders and deliveries open in a side window (customer, address and notes, payment, driver, and the items of the parcel: name, variant, quantity still to deliver; see `SIDE_WINDOWS_OPS_APPS.md`). Per-order staff notes do not exist in the API.
4. **Signature proof.** The server accepts `proof_type`; the app offers a photo only.
5. **Multi-branch staff.** The staff app shows the first branch of the user (by `sort_order`). A branch switcher needs the branches from `/admin/me`; branch-limited staff are unaffected.
6. **Photo studio approvals** (`photo-requests`, `photo-results/use|discard`) exist on the server and the dashboard only. The app can ask for a studio copy when adding a product.
7. **Texts are not in ARB.** The two apps keep their strings in `lib/ops/ops_strings.dart` (English and Arabic side by side, checked by a test for parity and placeholders). Moving about 190 strings to the shared ARB files would conflict with the customer app work in the same files; decision for the owner.
8. **AI description is HTML.** The server returns `<p>...</p>` and the product form shows the raw tags in plain text fields (the storefront renders the HTML). A rich-text editor or tag stripping is a product decision.
9. **Assigning an off-duty driver** is allowed by the server (`staffAssign` does not check duty) and by the app picker, which marks the driver "Off duty". Decision: block it or keep the manager's override.
10. **Map tiles** use the configured `MAP_TILE_URL` (OpenStreetMap by default); production traffic needs a tile provider with a licence.
