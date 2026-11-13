# Web maps: QA log

Scope: the admin live fleet map, the partner page map (live drivers, route replay, order drawer route) and the compact route card on the
admin order page. All three use one module, `backend/resources/js/maps/` (Leaflet 1.9.4 only, no plugins, one lazy chunk of about 57 kB
plus Leaflet), styled by `backend/resources/css/maps.css`. Map settings (tile provider, centre, stale limit) come from
`App\Support\MapConfig::payload()`; positions come in the shapes of `DriverPosition` / `RouteHistory`.

Result: `npm run build` clean; `npm test` 73 passed (41 new for the maps: 38 in `tests/js/maps-logic.test.mjs`, 3 in `maps-guards.test.mjs`);
PHP 51 feature tests passed in the affected areas (`FleetMapTest` 3, `OrderRouteCardTest` 4, `Partners` folder 9 incl. 2 new, plus `SalesScreensTest` and `DispatchTest`); Playwright smoke scripts in
`backend/tests/e2e-maps/` all green (about 110 checks, see below). Screenshots examined are in
`/tmp/claude-0/-home-user-Al-Bahar-Orders-Managment/df5a4cdd-ceec-5aef-a969-9f474fcb6a56/scratchpad/maps-web/`.

## What the maps do now

- **Tiles**: from MapConfig, light / dark by the page theme and switched live on the theme toggle (real dark tiles when the provider has them,
  otherwise a CSS tint). Retina through `{r}`. The attribution control is kept (text and `https` links only, everything else escaped). When no tile
  loads, the map shows a soft grid background and says "Map tiles unavailable" once; markers still work. Tile requests keep the `Referer`
  (nothing sets a `referrerPolicy`; a guard test checks it).
- **Markers**: driver marker (dark badge, vehicle glyph that turns to the heading, heading beak, status ring: free / assigned / on the way,
  pulsing ring while moving, grey and no beak when stale, name tag at zoom 14+), branch pin, customer pin, start / end / numbered stop dots, count
  bubbles. Movement is one shared `requestAnimationFrame` loop: about 1 s glide from where the marker is now, shortest-way heading, snap for
  reduced motion and for jumps over 1.5 km, frames older than the shown position are ignored.
- **Clustering** (own grid, no plugin) only above 40 visible drivers and below zoom 16; the selected driver is never absorbed.
- **Fleet map**: fits drivers and branches on load, "show everything", follow driver (keeps the driver in the middle 40 percent, stops on drag), search
  by driver / order / branch, status filter, 4 counters (on duty, moving, free, no signal), list synced with the map (click row = fly to and
  highlight, hover = highlight, arrow keys in the list), route trail (last 40 points, fading) and a dashed line to the customer for the selected driver,
  layers (branches, names, trail), legend, full screen (real API, in-page fallback), empty state (branches stay), skeleton, refresh error with Retry.
- **Connection chip**: Live (Reverb connected), Reconnecting (poll every 15 s), Updating every N s (no socket / channel refused). Polling runs only
  while the tab is visible, every 30 s while live (jobs and assignments are not broadcast), every 15 s while not.
- **Selected driver card**: name, status, order number, speed, last update, vehicle, branch, accuracy, ETA when the server has one, "Open order", Call
  (a `tel:` link with the number as text, a copy button and a note that desktops cannot dial), Follow, Zoom.
- **Partner page**: same module, read only: first names only, no phone, no branches in the card; list in a bottom sheet at 390 px; "Live | History"
  switch; history replays the trips of the selected day (play / pause, x1 x2 x4, scrubber, start / end, stops with durations, per trip toggles, x1 plays the
  day in about a minute); order drawer shows the same route card with the server's stops and totals; polling every 10 s, paused while hidden; the map
  card can be collapsed (remembered).
- **Admin order page**: per delivery that has recorded positions or a driver on the road, a compact route card (route, branch, customer pin, stops,
  last position, ETA, "1.8 km in 23 min"), built when it scrolls into view.
- **Accessibility**: every control is a labelled 44 px button, markers are focusable buttons with names that open on Enter / Space, `aria-pressed` on
  toggles, `aria-live` chip and toasts, focus ring, no motion under `prefers-reduced-motion`, logical sides everywhere (tools at the inline end, list at
  the inline start), Leaflet canvas stays LTR, tooltips follow the page direction.
- **Security**: no key in JS beyond MapConfig; all text goes through `textContent`; no inline handlers; a guard test fails on `innerHTML`, `eval` or
  `referrerPolicy` in the module; tile URL and attribution are re-validated on the client (`safeTileUrl`, `safeAttribution`).

## Bugs found and fixed

Severity: H = map or data wrong or lost, M = confusing or unreliable, L = polish.

| # | Where | Bug | Sev | Fix |
|---|---|---|---|---|
| 1 | Admin fleet (old) | `x-data="fleetMap(@js($payload), ...)"` changed on every Livewire render, so Livewire rebuilt the Alpine component each refresh and `init()` ran again on the same container ("Map container is already initialized"); in my first version this looped forever (refresh, rebuild, refresh) | H | `x-data` is now a constant; start-up data rides on the `wire:ignore` element; later data arrives by the `fleet-updated` event. `claimHost()` destroys any earlier map on a host, teardown on `livewire:navigating`, `pagehide` suspends and `pageshow` resumes. Checked: 2 Livewire refreshes, navigate away and back, back / forward all leave exactly one map |
| 2 | Admin fleet (old) | Channels were fixed at page load; changing the branch filter kept listening to the old channels, and any frame of a driver not on the map caused a full Livewire refresh (a storm with 20 drivers) | M | Channels come with every `fleet-updated` and are re-joined; unknown drivers trigger at most one refresh per 10 s |
| 3 | Admin fleet (old) | Always polled every 15 s, also while the tab was hidden and also while Reverb was connected; connection chip only knew on / off; a refused private channel looked "connected" | M | One poll timer (15 s without socket, 30 s with), paused while hidden, immediate refresh on return; chip shows Live / Reconnecting / Updating every N s; refused channels count as polling |
| 4 | Admin fleet (old) | Name and order number went into tooltip HTML unescaped (`<strong>${d.name}</strong>`); a driver called `<img onerror=...>` would run script | H | All text via `textContent`; guard test |
| 5 | Admin / partner (old) | Moving markers jumped; a late frame or a slow poll could pull a marker back; stale drivers looked the same as live ones; no heading | M | Tween between fixes, out-of-order guard on the fix time, stale after `stale_after_seconds` (grey, "last seen 3 min ago"), heading from the frame or from the movement |
| 6 | Partner page (old) | Map was 288 px in a 380 px column, list under it, one pin colour for any state; no route replay; drawer map had no stops or totals | M | Rebuilt (see above) |
| 7 | Partner (old) | Full driver names and (in the order drawer) the full driver name were sent to partners | M | Server sends first names only (`PartnerReport::fleet()` and `deliveryRow()`); test added |
| 8 | Maps module | `:where(.abm) button` reset had higher specificity than the component rules: "View order" and the play button were dark on dark | M | Zero-specificity reset |
| 9 | Maps module | A focused marker did nothing on Enter or Space (Leaflet 1.9 only turns Enter into a click for popups) | M | `onActivate()` handles click, Enter and Space on markers, clusters, branch and stop markers |
| 10 | Maps module | Reverb retries flap the socket state (connecting, unavailable, ...); every flap restarted the poll timer so the 15 s fallback never fired | H | The timer restarts only when the interval changes (checked with Reverb stopped: one refresh in 17 s, none while hidden, immediate on return) |
| 11 | Maps module | Initial load failure left the skeleton over the error bar, so Retry could not be clicked | M | Error replaces the skeleton and says "The drivers could not be loaded" before the first load |
| 12 | Maps module | A failed map chunk could not be retried in the same page (the browser remembers a failed import) | M | Retry reloads the page |
| 13 | Maps module | Selecting a driver put them under the card or off the top edge on phones (sheet height was measured mid-transition) | M | Driver is placed beside / above the card using the closed sheet height |
| 14 | Maps module | 9 map tools covered a 390 px map; the opened sheet hid the attribution; the live-order pill covered the sheet's chevron | M | On narrow maps the secondary tools fold behind one "more" button and hide while the sheet is open; attribution moves above the sheet; chevron sits next to the title; page gets bottom padding |
| 15 | Maps module | Arabic times showed "09:46 م" reversed in the scrubber | L | 24 h clock |
| 16 | Maps module | Wheel zoom and one-finger drag captured page scroll in the partner page | L | Wheel zoom after focus, one finger scrolls the page on touch (hint shown once), full screen restores full control |

Not a bug but worth knowing: the stale limit is `platform.tracking.stale_after_seconds` (120 s in `config/platform.php`, sent as `stale_after_seconds` in
MapConfig); 90 s is only the fallback when the config does not say. Set the config to 90 to get 90 s on every client.

## Tests

JS (`npm test`): `tests/js/maps-logic.test.mjs` covers heading interpolation, easing and tween, glide rules, stale and moving detection, the out-of-order
guard (`applyMove` / `applyFull`), grid clustering, polyline thinning, stop detection, track lookup, playback rate, MapConfig fallbacks, tile URL and
attribution sanitising, phone links. `tests/js/maps-guards.test.mjs` guards `referrerPolicy`, the partner referrer meta and HTML injection.

PHP: `FleetMapTest` (event payload, order id, channels, branch pins, server clock, static x-data), `OrderRouteCardTest` (route, destination, branch, live
driver, ETA, first name only, none when nothing recorded, element keying), `PartnerStatsTest` (day routes endpoint behind the password, first names
only, map config and shop pins on the page). Run with a database of their own: `DB_DATABASE=albahar_qa_maps_web_t php artisan test <filter>` (the test
run recreates the database).

Playwright (`backend/tests/e2e-maps/`, not part of CI; needs the app, a seeded database and Reverb; stub tiles are generated, the sandbox has no
internet): `fleet.mjs` (34 checks: layout, filters, card, follow, layers, full screen, dark, idempotent init, branch filter, Arabic / RTL, phone, tile
failure), `partner.mjs` (live, history, playback, empty day, drawer route, Arabic, dark, phone), `order.mjs` (route card on the order page, desktop and
phone), `fleet-socket.mjs` (Reverb stopped and restarted, hidden tab), `fleet-scale.mjs` (205 drivers, 40 moves per second), `states.mjs` (skeleton,
empty, error and retry, chunk failure, reduced motion, keyboard), `theme-tiles.mjs` (separate dark tiles, hostile attribution). Helpers: `lib.mjs`,
`emit-moves.php` and `emit-many.php` send real `driver.moved` frames.

Scale (205 drivers, headless Chromium): first markers 0.76 s after navigation, 20 cluster bubbles, about 2000 DOM nodes, frame time p50 16.7 ms and
p95 16.7 ms (max 33 ms) while 40 moves per second arrive, no long tasks; zooming in dissolves clusters (20 to 8, 4 to 189 single markers).

## Screenshots examined

`fleet-desktop-light`, `fleet-desktop-card`, `fleet-desktop-dark`, `fleet-desktop-legend`, `fleet-desktop-rtl-card`, `fleet-phone`, `fleet-phone-sheet`,
`fleet-phone-card`, `fleet-tiles-unavailable`, `fleet-scale-clusters`, `fleet-scale-zoomed`, `fleet-socket-down`, `partner-desktop-live`, `-card`,
`-history`, `-playback`, `-history-empty`, `-drawer-route`, `-ar`, `-ar-history`, `-dark`, `-dark-history`, `partner-phone-live`, `-sheet`, `-card`,
`order-route-desktop`, `order-route-phone`, `states-empty`, `states-error`, `states-chunk-failed`, `theme-tiles-light`, `theme-tiles-dark`.

## Not done, and limits

- Real map tiles could not be loaded in the sandbox (no internet); layout, markers, theme switching and failure handling were checked with generated
  tiles. Check the chosen provider once on the live site (key restricted to the domain).
- A real phone was not available: touch behaviour was checked with Chromium touch emulation (two-finger hint, sheet swipe, tap targets), not on hardware.
- No automated accessibility engine (axe) was available; contrast, names, focus and keyboard use were checked by hand and by the scripts above.
- The map kit is not covered by CI; the Playwright scripts are run by hand.
