# App maps: QA log

Scope: every map in the three Flutter apps (customer, driver, branch staff) and the driver location tracker.
Code: `mobile/lib/core/map/` (the shared toolkit), `features/orders/presentation/order_tracking_screen.dart` and `tracking_*.dart`,
`features/profile/presentation/map_pin_picker.dart`, `features/branches/presentation/branch_widgets.dart` (`BranchMap`),
`ops/driver/driver_job_map.dart`, `ops/driver/driver_tracker.dart`, `ops/staff/fleet_map_tab.dart`, `ops/staff/fleet_logic.dart`.
Server contract: `docs/guides/MAPS.md` (`GET /map-config`, tracking, fleet, location, `driver.moved`). Every field the app reads is optional,
so an older server still works.

Result at the end of this pass: `flutter analyze` 0 issues; `flutter test` 742 passed, 0 failed (405 at the start; the 218 map tests listed under "Tests" are part of the rest, other helpers added theirs too).
No dependency was added: `flutter_map` 8.3.2 already ships a disk tile cache (`NetworkTileProvider` with the built-in caching provider,
honouring the tile server's cache headers), retries and request cancelling; `url_launcher` and `fake_async` were already there.

## What every map now has (one toolkit, `lib/core/map/`)

| Piece | Behaviour |
|---|---|
| `AlBaharMap` + `AlBaharMapController` | The only map widget. Tiles from `/map-config` (cached copy in preferences, fallback OpenStreetMap + Doha), light and dark tile URLs follow the theme live, a night filter is applied when the server has no dark style, retina only when the template has `{r}`, `User-Agent: AlBahar/<version> (qa.albahar.customer\|driver\|staff) flutter_map`. Soft background colour; after 4 failed tiles one "Map tiles unavailable" note (7 s, once per map). The credit line is always visible, 48 dp tall, tappable (sheet with the full text and https links). Controls (48 dp, labelled, tooltip only where an Overlay exists): show everything, follow, compass (only while turned), zoom, my location; placed with `PositionedDirectional` (end corner, left in Arabic). `bottomInset` / `topInset` keep fits, controls and the credit line clear of sheets; a changing inset re-frames the markers unless the person has moved the map. Animated camera moves (reduced motion = jump). |
| `DriverMarkersLayer` | Marker glide of ~1 s between positions along the short arc for the heading, one controller for all drivers, a retargeted glide continues from where the marker is (no jump), reports older than the one shown are ignored (`GeoFix.isNewerThan`), grey with "Last seen N min ago" after the stale threshold (server `stale_after_seconds`, 90 s without one), ages by itself every 10 s, pulsing ring only while moving and live (not with reduced motion), count badges above 40 drivers (grid clustering that follows the zoom), selected driver on top and never clustered. |
| Markers (`map_markers.dart`) | Drawn with `CustomPaint`, no image assets: brand disc with a top-down bike / car / van glyph (from the `vehicle` text) turning to the heading, heading arrow head, branch / address / picked pins (tip on the coordinate), "you are here" dot, cluster badge. Labels are text-scale capped at 1.15; every marker has its own semantics node. Only the disc and the name are touchable, so close drivers can be hit. |
| `routeLayers`, `appendTrail`, `myLocationLayers` | Soft white casing + dashed remaining line, solid trail of the last 40 real positions (repeats dropped), accuracy circle in metres. |
| `GeoFix` | One parser for REST, push and the phone's own GPS: `lat/lng`, `heading`, `speed_kmh` (also `speed`), `accuracy_m` (also `accuracy`), `at`, `age_seconds`. Age counts on from when the answer arrived, so a wrong phone clock cannot make a live driver look stale. |
| `MapNavigation` / `startNavigation` | Google Maps, Apple Maps (iOS), Waze through their URL schemes with the https link as fallback; only installed apps are offered, one app starts at once, several show a chooser (last used first). |

## Bugs found and fixed

Severity: H = wrong or dangerous result for a customer, driver or dispatcher; M = wrong or confusing behaviour; L = polish.

| # | Where | Bug | Sev | Fix | Status |
|---|---|---|---|---|---|
| 1 | Customer tracking | A driver who stopped reporting looked live forever: no age, no stale state, no "last seen" | H | Position carries `age_seconds`; grey marker, grey ETA, "Last seen N min ago"; threshold from the server | Fixed |
| 2 | Customer tracking | Showed the driver's full name (`driver.name`) | M | First name only (`name` is the first name on the new server; older answers are cut at the first space) | Fixed |
| 3 | Customer tracking | No arrival time, distance, vehicle, call button, order steps; waiting / not assigned / delivered / unavailable states were one line of text | M | ETA chip ("Arriving in ~12 min"), distance, call (or copy the number), steps, every state has its own panel | Fixed |
| 4 | Customer tracking | The map never stopped polling and listening after delivery; kept running in the background | M | Delivered / closed: polling and channel left; app in background: channel left, polling paused, one refresh on return; socket reconnect refreshes at once | Fixed |
| 5 | Customer tracking | A pin jumped on every push; a late push could move it back | M | Glide + newest-fix rule | Fixed |
| 6 | Customer tracking | The panel (a floating card) covered the markers; no bottom inset | M | Panel is part of the page, its measured height is the map's `bottomInset` | Fixed |
| 7 | All maps | Tile URL hard-coded in the app (dart-define), no dark tiles, "Open Street Map" credit not tappable, wrong `User-Agent` package (`qa.albahar.staff` for the driver app too) | H | Server-driven config with cache and fallback; correct package per app; credit sheet with the licence link | Fixed (see owner decision 1) |
| 8 | All maps | `FloatingActionButton.small` with fixed `heroTag`s ("recenter", "locate"), no semantics on pins or the map, markers unlabelled | M | Shared round buttons, every control and marker labelled; the map surface carries a label (it failed the "tappable widgets need a label" guideline once the markers had their own nodes) | Fixed |
| 9 | Branch map | Only the first branch's location was framed (others off screen); pins 48 px, selection did not move the map; the tab's bottom card hid the controls | M | All located branches framed, selected pin larger, selecting glides, card height passed as inset | Fixed |
| 10 | Address picker | A pin abroad was accepted and only the server's 422 said why (shown in a generic line); old pin outside Qatar saved without a word | M | Qatar box check: confirm disabled with the reason; the form refuses a stored pin abroad; server 422 on `latitude` / `longitude` shown under the pin card | Fixed |
| 11 | Address picker | "My location" gave one message for every refusal; "blocked for good" had no way to the settings; GPS off was reported as "denied" | M | Four distinct messages; settings button on "blocked" and "GPS off" | Fixed |
| 12 | Address picker | The pin was an icon with a magic `padding: 44`, tip not on the centre; nothing showed the exact point | L | Tip is the exact centre, the pin lifts while the map moves, a small ring marks the spot | Fixed |
| 13 | Staff fleet | "Quiet" threshold was 15 minutes; pins teleported; no filters, counts, list sync, clustering or connection state; a driver tap area of 96 x 70 hid neighbours | H | Server threshold (90 s default), glide, chips (all / moving / waiting / not live) with counts, bottom sheet list in step with the map, clustering above 40, connection pill (Live / Connecting / Reconnecting / Offline), refresh button + pull to refresh | Fixed |
| 14 | Staff fleet | A driver with a job showed no destination | M | Selecting a driver draws the dashed way to the address and frames both | Fixed |
| 15 | Driver app | No map: the "Navigate" button opened a Google web URL only; no choice of app, no feedback when nothing opened | M | Delivery map (position, address, dashed way, distance, rough arrival time, big Navigate with app chooser, job card with the step buttons inside); failure says so | Fixed |
| 16 | Driver tracker | Heading `0` was sent while standing (every parked driver pointed north) | M | Heading only above 1 m/s | Fixed |
| 17 | Driver tracker | A 400 m wide fix became the position | H | Fixes worse than 100 m are dropped (counted); keep-alive repeats the last good fix only | Fixed |
| 18 | Driver tracker | No fix time sent: a report that waited in a slow connection looked live; overlapping requests piled up on a bad network | M | `recorded_at` (UTC) sent; retried without it on `location_in_future` (phone clock ahead) so tracking never goes dark; one request in flight | Fixed |
| 19 | Driver tracker | Foreground-service channel name was the English default | L | Localised (EN / AR) | Fixed |
| 20 | Driver tracker | Nothing slowed down while standing | L | Idle after 90 s without moving: one report per 30 s (the 60 s keep-alive stays) | Fixed (decision 4) |
| 21 | Native | Android 11+ could not detect Waze / Google navigation (`<queries>` missing); iOS had no `waze` query scheme | M | Added to `AndroidManifest.xml` and `Info.plist` | Fixed |
| 22 | Found by the new tests | Info strip of the delivery map and the status pill of the tracking panel overflowed at 360 px with text x1.3 | M | `Wrap` / `Flexible` | Fixed |
| 23 | Found by the new tests | A map in a `TabBarView`: horizontal drags might switch the tab | M | Verified: the map takes the drag, the tab stays (test) | No change needed |

## Owner decisions and open points

1. **Tile provider licence.** The public `tile.openstreetmap.org` servers are only a fallback: their policy does not allow production traffic. Pick MapTiler, Stadia, Carto or a custom provider in the admin (Settings, Integrations, map) and restrict its key to the app ids and the domains. Until then the apps show OpenStreetMap with its credit.
2. **No road route.** The dashed line is the straight line from the driver to the address (and the trail is the last real positions). The server has a routing service for the customer's ETA only. Drawing the road needs route geometry in the tracking and job answers (the `route` endpoint returns the driven history, not the way ahead).
3. **Driver ETA is an estimate** (straight-line distance at 30 km/h, or the real speed above 12 km/h) and says so on screen. The customer ETA is the server's.
4. **Idle mode only slows the reports, not the GPS.** Restarting the Android location stream from the background is not allowed on recent Android versions (foreground-service start restriction), so the stream settings never change while running. A battery reading (`battery`) is not sent: that needs a plugin (`battery_plus`), not added without approval.
5. **Stale threshold.** The app uses the server's `stale_after_seconds` (120 s today) from `/map-config` and the tracking answer; the 90 s default only applies before the server has ever answered.
6. **iOS driver and staff apps.** The iOS project has one target (the customer app, `qa.albahar.customer`) and its `Info.plist` has no `UIBackgroundModes: location`; the driver app's background tracking is Android only until iOS targets for the two staff apps are created (`geolocator` ignores background updates without that mode, so nothing crashes).
7. **Address text from a pin** (reverse geocoding): no API exists, so none is shown.
8. **Heading while standing.** Customers see the last heading kept; a standing driver's marker never spins on GPS drift.

## What the server needs

Nothing blocking. Read from the final contract: `delivery_status`, `live`, `delivered_at`, `stale_after_seconds`, `driver.{name,vehicle,lat,lng,heading,speed_kmh,accuracy_m,at,age_seconds}`, `eta`, fleet rows with `job.destination`, `driver.moved` payloads, `recorded_at` on the location call. The customer driver phone comes from the order's delivery details (`driver_phone`), since the tracking answer never carries it (privacy).
Not read yet (optional): `meta.stale_after_seconds` of the fleet answer (the app uses the map config value), `battery`.

## Tests

New (all fake tile providers, no network, no plugins):

- `test/core/map/map_geo_test.dart` (25): angle maths and heading interpolation across 0, haversine and bearing, Qatar box, `GeoFix` parsing (current, short and rubbish shapes), staleness with a wrong phone clock, out-of-order rule, clustering.
- `test/core/map/map_config_test.dart` (21): tile config parsing (subdomains as a string, https only, dark style, night filter), saved copy and refresh (first launch, cache, error, offline, once per launch), user agent, credit line parsing and safety.
- `test/core/map/al_bahar_map_test.dart` (24): live theme switch of the tile URL, night filter, User-Agent, tile failure note (once), credit line (visible, 48 dp, above the sheet, opens), controls (labels, 48 dp, EN / AR, text x1.3, dark, RTL side, no-Overlay tree, compass), fit clear of the sheet, re-fit rules, glide vs reduced motion, follow mode and drag, queued calls, pin tip, route layers.
- `test/core/map/driver_markers_test.dart` (18): glide start / middle / end, short-arc heading, out-of-order, retarget without a jump, reduced motion, stale grey and "last seen", ageing by itself, server threshold, selected on top, clustering (40 vs 45, selected exempt), glyph choice, painters.
- `test/core/map/map_nav_test.dart` (12): URLs per platform, installed apps only, last used first, fallback chain, chooser, failure.
- `test/ops/tracker_maps_test.dart` (14): accuracy filter, payload (km/h, heading only while moving, rounded accuracy, fix time), idle after 90 s and back, keep-alive, one request in flight, stop / dispose leave nothing running, platform settings (Android channel and text EN / AR, iOS background), heartbeat retry on `location_in_future`, no retry on `location_too_old`.
- `test/ops/fleet_map_test.dart` (23): sorting and counts, server threshold, newest position wins, old and new fleet answers, layout matrix (7 variants, 48 dp, labels), framing clear of the sheet, filters with counts, list <-> map sync, destination line and pin, live pushes (late ones ignored), push without time, clustering and zoom-in on a badge, empty state, refresh button, failed refresh keeps the list, connection pill states, Arabic, map drag inside the staff app does not change tab.
- `test/ops/driver_job_map_test.dart` (20): ETA, distance and estimate note, speed-based ETA, no position, no pin, bad pin, trail growth, late position ignored, subscription cancelled, layout matrix; from the driver app: map button, embedded card without a second Navigate, step taken on the map, map closes when the job leaves the list, Navigate hands over / reports failure, address-search fallback.
- `test/features/tracking_logic_test.dart` (22): payload shapes (current, nested, first version, broken), stages and steps, newest fix, heading rule, distance to go, panel texts for every state in EN and AR.
- `test/features/order_tracking_screen_test.dart` (27): live screen (markers, panel above the markers, ETA, pushes, camera follows until the person moves the map, 12 s poll, background pause and resume, reconnect refresh, stale, call and copy, steps), not assigned, waiting, delivered (frozen), cancelled, no coordinates, server failure + retry, failed refresh keeps the picture, offline, layout matrix, Arabic, leaving the screen.
- `test/features/map_pin_picker_test.dart` (12): confirm inside Qatar, blocked abroad, centre pin and lift, my location (allowed, refused, blocked for good + settings, GPS off), layouts, saved-pin preview, branch map framing, selection glide, preview map.
- Existing ops tests updated for gliding markers and the new fakes (`test/helpers/map_fakes.dart`, `test/ops/ops_test_kit.dart`).

Translations: new texts are in `lib/l10n/app_en.arb` / `app_ar.arb` (map and tracking) and `lib/ops/ops_strings.dart` (fleet, delivery map); the existing parity tests for both pass.

## Not done

- Road-following route (decision 2) and a driver "arrived" button: the job card keeps its existing step buttons (accept, picked up, on my way, delivered); there is no separate arrival step in the server's delivery flow.
- Reverse geocoding for the address pin (no API).
- iOS targets for the driver and staff apps (decision 6).
- A real-device check of the foreground service and of the Waze / Google Maps hand-over: they need a phone; the logic around them is tested with fakes.
