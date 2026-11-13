# Maps and live tracking: the backend contract

One map configuration, one position shape, one staleness rule for the admin dashboard, the partner page, the web store and the apps.

## Map settings (Settings → Integrations)
| Setting | Meaning |
|---|---|
| Map provider | `osm` (default, **testing only**: OpenStreetMap's policy forbids heavy production use), `maptiler`, `stadia` (both need a key), `carto` (free for non-commercial use), `custom` |
| API key | Secret (encrypted, never shown). Used by MapTiler and Stadia |
| Custom tiles (light / dark) | `custom` only. `https://…` containing `{z}` `{x}` `{y}`; `{s}` `{r}` `{key}` are also allowed, nothing else in braces. No user name, password, localhost or loopback/metadata IP. Dark empty = light tiles in dark mode |
| Credit line | Plain text or `<a href="https://…">links</a>` only (everything else is escaped). Empty = the provider's own line, which its licence requires |
| Sharp tiles, max zoom, default centre and zoom | Defaults: Doha 25.2854, 51.5310, zoom 11 |
| Routing service URL | Optional OSRM-compatible base URL (`https`, no query). Server-side only, never sent to clients. Empty/unreachable = straight-line ETA |

**Switch provider:** pick it, paste the key, Save, press **Test tiles** (fetches one real tile and reports the result). A key-only provider without a key shows OpenStreetMap until the key is saved (`MapConfig::problem()` explains why). Changes reach everyone within seconds: saving drops the server cache, clients refetch at most every 5 minutes.
**Key safety:** tile URLs of MapTiler/Stadia carry the key in the query string, as those providers intend. In the provider console restrict the key to the website domains and the app IDs (package name / bundle ID). Nothing else secret is ever in the payload.

## `GET /api/v1/map-config` (public, `Cache-Control: public, max-age=300`, ETag/304)
Same payload as `App\Support\MapConfig::payload()` (use that in Blade: `@json(\App\Support\MapConfig::payload())`), wrapped in `data`:
```json
{"data": {"provider": "osm",
  "tiles": {"light_url": "https://…/{z}/{x}/{y}{r}.png", "dark_url": "…", "attribution": "© <a href=\"https://…\">…</a>", "attribution_text": "© …",
            "subdomains": "abc", "max_zoom": 19, "retina": true},
  "center": {"lat": 25.2854, "lng": 51.531}, "zoom": 11, "stale_after_seconds": 120}}
```
- `dark_url` is always present (equals `light_url` when the provider has no dark style).
- Placeholders left for the client: `{z} {x} {y}`, `{s}` (one letter of `subdomains`; `""` = none), `{r}` (`@2x` on high-density screens; only present when `retina` is true).
- `attribution` is safe to render as HTML; `attribution_text` is the same line as plain text.
- `provider` is the one actually in use (it reads `osm` while a key-only provider has no key).

## Position shape (everywhere)
`lat, lng, heading (0-359|null), speed_kmh|null, accuracy_m|null, at (ISO 8601, when the GPS fix was taken), age_seconds, stale`. `stale` is true when `age_seconds > stale_after_seconds` (120): grey the marker out and say "last seen". Realtime payloads carry the same field names, so one parser serves push and poll.

## Driver ingestion: `POST /api/v1/admin/dispatch/driver/location` (driver app, 60/min)
Body: `latitude, longitude` (required), `speed_kmh 0-300, heading 0-360, accuracy 0-32767 (m), battery 0-100, recorded_at` (ISO 8601 fix time; default now).
- **422** `location_out_of_area` (not a real position in Qatar plus about 100 km margin; 0,0 included), `location_in_future` (more than 60 s ahead), `location_too_old` (older than 10 min), or field errors.
- **200** `{"data": {"ok": true, "recorded": bool, "reason": null|"off_duty"|"out_of_order"|"low_accuracy"|"rate_limited"|"duplicate"}}`. Not recorded is never an error; apps must not retry.
- Nothing is kept while the driver is off duty with no order on the road (location is private outside work). A fix older than the held one is ignored; accuracy worse than 500 m only proves the app is alive; the latest position is always updated; a breadcrumb + broadcast happen at most every 3 s; a fix within 5 m of the last breadcrumb is a duplicate (a standing driver is still stored every 45 s).
- `PUT /admin/dispatch/driver/duty` also accepts `vehicle` (max 60, e.g. "Motorbike · 123456"; `null` clears it) and validates its position the same way.
- Breadcrumbs (`driver_locations`) go to the delivery furthest along (out for delivery > picked up > accepted). `drivers:prune-locations` (daily): strays after 7 days, order routes after 365.

## Realtime (`driver.moved`)
- **Staff**: `private-fleet` (company-wide staff) and `private-branches.{id}` (each branch the driver works for). `{driver_id, name, vehicle, lat, lng, heading, speed_kmh, accuracy_m, battery, at, age_seconds, order_id, order_number, delivery_status}`.
- **Customer**: `private-orders.{orderId}` (only the customer who placed it), sent only while the order is picked up / out for delivery. `{order_id, name (first name), vehicle, lat, lng, heading, speed_kmh, accuracy_m, at, age_seconds, delivery_status}`. No driver id, phone, battery or order number.

## Staff fleet: `GET /api/v1/admin/dispatch/fleet[?branch_id]` (`deliveries.view`, branch-scoped; `?branch_id` can only narrow)
Drivers on duty, plus any driver still carrying an order after switching off. `data[]`: `id, name, phone, vehicle, on_duty, lat, lng, heading, speed_kmh, accuracy_m, battery, at, age_seconds, stale, last_seen_at, branches[], open_jobs, job`; `job` = `{delivery_id, status, order_id, order_number, destination: {lat,lng}|null}` or null. `meta: {at, stale_after_seconds}`. A driver who has not reported yet has `lat/lng` null and `stale` true. The dashboard's `FleetMap` component gets the same rows.

## Customer tracking: `GET /api/v1/orders/{id}/tracking` (own order only; anyone else, a malformed id or a staff token gets 404/403/401)
```json
{"data": {"order_id": "…", "number": "…", "status": "…", "tracking": true, "live": true, "delivery_status": "out_for_delivery", "delivered_at": null,
  "driver": {"name": "Ahmed", "vehicle": "Honda · 123456", "status": "out_for_delivery", "lat": 25.28, "lng": 51.52, "heading": 90,
             "speed_kmh": 31.5, "accuracy_m": 9, "at": "…", "age_seconds": 20, "stale": false},
  "destination": {"lat": 25.3, "lng": 51.5}, "branch": {"name": "…", "lat": 25.2, "lng": 51.4},
  "eta": {"minutes": 7, "distance_m": 3100, "updated_at": "…", "source": "estimate"}, "stale_after_seconds": 120}}
```
- Driver (first name only) appears once the order is **picked up**; before that `driver`, `eta` are null and only `delivery_status` (`accepted`) shows. `tracking` = a driver block with a position exists; `live` = the order is on the road now.
- For **10 minutes after delivery** the driver block remains with `live: false`, `delivery_status: "delivered"`, `delivered_at` and the last position recorded *for that delivery* (never where the driver went next); then `driver` is null.
- `driver.lat/lng` are null (and `stale` true) when the phone has not reported yet.
- `eta.source` is `osrm` (routing service answered within 3 s) or `estimate` (straight line × 1.35 at 28 km/h). Cached 30 s per delivery; a failing routing service is left alone for a minute. The response never waits on it for longer than 3 s and never fails because of it.

## Route history
- `GET /api/v1/admin/deliveries/{delivery}/route` (bearer) and `GET /admin/deliveries/{delivery}/route` (dashboard session, route name `admin.deliveries.route`). Needs `deliveries.view` on the delivery's branch. `data`: `delivery_id, status, order_id, order_number, driver{id,name}, destination, branch{name,lat,lng}, live` (position shape while on the road, else null) and
  `route: {points[], stops[], distance_m, duration_s, started_at, ended_at, raw_count}`.
  `points[]` = `{lat, lng, at, speed_kmh, heading}` oldest first, Douglas–Peucker simplified to at most 300 (first and last always kept); `stops[]` = `{lat, lng, from, to, duration_s}` where the driver stood within 40 m for 2+ minutes (at most 50); `distance_m`/`duration_s` describe the full recording.
- **Partner page** (`/partner/{token}/…`, secret link + password session, each route has its own rate-limit bucket): `fleet` → `data: {drivers[], at, stale_after_seconds}` with `id, name (first name only), vehicle, on_duty, lat, lng, heading, speed_kmh, accuracy_m, at, age_seconds, stale, last_seen_at, job{order_number, status, destination}` (no phone, battery or branches); `orders/{id}` → `deliveries[]` each with `route` (the same simplified points), `stops`, `distance_m`, `duration_s`, `started_at`, `ended_at`, plus the order's `destination`.

## Geo hygiene
`App\Support\Geo`: `distanceM/distanceKm` (haversine), `bearing`, `validPoint` (rejects 0,0), `inBounds` (`QATAR_BOUNDS` + margin), `pointInRing` (delivery-zone polygons, `[lng, lat]` order), `simplify/simplifyTo` (Douglas–Peucker), `pathLengthM`, `tile`. Address pins and branch coordinates are a pair (both or neither) inside Qatar's box (lat 24.4–26.3, lng 50.7–51.7), in the API and the dashboard forms.

## Privacy
Location is collected only on duty or with an order on the road; the customer sees the driver's first name and position for their own order only, until 10 minutes after delivery; breadcrumbs are pruned (7 days stray, 1 year for order routes); partner pages never show phone numbers.

## Files
`app/Support/{MapConfig,Geo}.php`, `app/Domain/Fulfillment/Services/{DispatchService,TrackingService,RouteHistory,DriverPosition}.php`, `…/Services/Eta/*`, `…/Events/{DriverMoved,OrderDriverMoved}.php`, controllers `MapConfigController`, `Admin/{DispatchController,DeliveryRouteController}`, `PartnerController`; tuning constants in `config/platform.php` (`map`, `tracking`); migration `2026_01_01_002400_add_motion_to_driver_profiles.php`; tests `tests/Feature/Maps/*`, `tests/Unit/Support/GeoTest.php`.
