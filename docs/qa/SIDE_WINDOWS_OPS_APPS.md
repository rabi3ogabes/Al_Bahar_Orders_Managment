# Side windows in the driver and branch staff apps: audit and result

Scope: `mobile/lib/ops/**` (driver app and branch staff app). Rule: [`docs/guides/SIDE_WINDOWS.md`](../guides/SIDE_WINDOWS.md).
Every window uses the shared `showSidePanel()` (`lib/core/widgets/side_panel.dart`): a tall bottom sheet on phones, a panel
from the END edge (right in English, left in Arabic) from 720 px wide. No other sheet helper was added.

The ladder used for each flow: tooltip, popover/bottom action sheet (2 to 7 actions, no typing), side window (details, forms,
long lists), centred decision (confirm, never closed by tapping outside when destructive), full screen (big multi-step work).

## Flow by flow

| # | App | Flow | Before | After | Why |
|---|---|---|---|---|---|
| 1 | Driver | Open a delivery (card tap) | Nothing: the card was the only view, no way to see notes, branch, cash hint or copy the address | **Delivery window**: status and time, customer (name, phone, Call), address with notes and "Pick up from", Navigate, Copy address ("Copied" on the button), Open delivery map, payment (cash to collect in big type, or "nothing to collect"), photo-required note. Step buttons pinned at the bottom | Details the driver needs at the door, without leaving the list; one Back returns |
| 2 | Driver | Step buttons (Accept, Picked up, On my way, Mark delivered, Could not deliver, Return to branch) | Only on the card | Same `JobActionButtons` on the card, under the delivery map and pinned in the window | One implementation; a step is one tap wherever the driver is. "Delivered" is now "Mark delivered" (a verb) |
| 3 | Driver | Proof of delivery | Dismissible-by-hand bottom sheet with its own layout | **Proof window** (`showSidePanel`, `isForm`): recipient, cash, photo; "Confirm delivery" pinned; scrim tap and swipe never close it; close or Back with input asks "Discard changes?" (Keep editing focused); upload happens inside, so photo and text survive a failed upload | Typed input and a photo must never be lost by a stray tap |
| 4 | Driver | Reason for a failed delivery | Plain bottom sheet | **Reason window** (list of 6 reasons, one tap) then a centred question "Report as not delivered?" (Cancel focused, verb "Report") | Consistent window; the decision stays a modal |
| 5 | Driver | Return a failed delivery to the branch | Inline confirm dialog | Centred question "Handed back at the branch?" (Cancel focused, verb "Confirm return"), from the card or the window | One shared `opsConfirm` instead of three copies |
| 6 | Driver | Delivery map (`driver_job_map.dart`) | Full screen | **Stays full screen.** Back arrow in the app bar, big Navigate pinned at the bottom with the step buttons; the window hands over to it ("Open delivery map") | Live map with GPS trail: big, needs the whole screen |
| 7 | Driver | History job | Card only | Opens the same window read-only (no buttons, no Call, no Navigate) | See what was delivered, nothing to change |
| 8 | Staff | Open an order (card tap) | Nothing: the card was the only view | **Order window**: stage and payment chips, customer (name, phone, Call), address and notes, Copy address, Items and Total and payment, delivery (driver, delivery status, Call driver). Step buttons pinned | Staff can read the whole order without losing their tab |
| 9 | Staff | Order steps (Accept, Start preparing, Mark ready) and Assign / Change driver, Return to branch | Only on the card | Same `OrderActionButtons` on the card and pinned in the window; permissions unchanged (`orders.edit`, `deliveries.assign`, `deliveries.edit`) | Same rules as the card: a window never shows more than the page |
| 10 | Staff | Assign driver | Bottom sheet with its own layout | **Driver picker window** (above the order window): on-duty drivers first, then least busy; "For order #..." | Long list, needs the end panel on tablets |
| 11 | Staff | Drivers tab: tap a driver | Nothing | **Driver window**: duty, open jobs, delivery in hand, last seen, vehicle, battery, big Call pinned. Row call button still calls directly | Dispatchers ask "where is he, what is he doing" |
| 12 | Staff | Live map driver list (bottom sheet in `fleet_map_tab.dart`) | Draggable sheet kept in step with the map | **Unchanged** | Part of the map screen itself (tap a row flies the map); not a secondary flow |
| 13 | Staff | Add product (`add_product_screen.dart`) | Full screen | **Stays full screen** (many fields, up to 8 photos, AI description, multi-upload save). Exit (X) at the top start, "Discard changes?" with typed input or photos, Save pinned at the bottom, form limited to 640 px on tablets | Big multi-part form |
| 14 | Staff | Category picker inside Add product | Bottom sheet at 70 % height | **Category window** (searchable list in `showSidePanel`) | Long list: end panel on tablets |
| 15 | Staff | Photo source (camera or gallery) | Bottom sheet, 2 actions | **Unchanged** (action sheet) | 2 actions, no typing: lightest overlay that fits |
| 16 | Both | Replace typed description question, sign out, discard | Three separate `AlertDialog`s | One `opsConfirm` (question title, verb button, Cancel focused) and the shared `confirmDiscard` | Decisions stay centred modals |

Not in the code, so nothing to convert (verified by reading every file under `lib/ops/**`): cash hand-in, issue reporting, returns
screen, stock quick view, pickup pass or QR. "Return to branch" is the only return flow and it is a confirm dialog.

## Behaviour every window has

- Opens on tap, the page keeps its tab, scroll and filters. Closes with X, Esc, scrim tap, swipe down (phone) and system Back; Back
  closes only the top window (a proof or reason window opens above the delivery window and Back peels them one at a time).
- **Live**: `LiveRecord` (`lib/ops/ops_live.dart`) keeps the window on the newest copy of its record (real-time ping, 60 s poll, the
  action just taken). When the record leaves its list the window closes itself and the page says why ("This delivery is no longer
  in your list.", "Delivery recorded.", "This order is no longer in the branch queue.", "This driver is no longer in the branch
  list."). A list that is reloading, or whose last reload failed, never counts as "gone". An order follows its move from stage
  to stage (the lookup spans all five stage lists and is only trusted once all of them are up to date).
- Pinned primary action with a specific label; errors from a refused step show as a toast inside the window (the shared snack helpers
  already do this inside a panel), proof errors show next to the button.
- A server-ended session closes every window, even a proof window with typed input (tested).
- 48 dp targets, labelled controls, logical (start/end) padding and alignment only, light and dark, 1.3x text, reduced motion
  (from the shared helper).
- Keys kept: step buttons are found by their text as before; list cards keep `ValueKey('a-<id>')` / `ValueKey('<fulfillment>-<status>-<driver>')`.

## Left as it was, on purpose

- Delivery map, Add product, live fleet map and its list sheet (see rows 6, 12, 13).
- Realtime plumbing, tracker and foreground service, `lib/core/map/**`, `lib/core/live_order/**`.
- The popup menu (language, sign out): a 2-item popover.

## Tests

`flutter test test/ops`: 262 passed (198 before). New: `driver_windows_test.dart` (36), `staff_windows_test.dart` (28), one
session test. They cover, per converted flow: sheet at 390 px, end panel at 1024 px, left panel in Arabic, Back closes only the top
window, pinned action works and stays at the bottom, follows the record, closes itself with a message when the record leaves,
is not fooled by a reload in progress or an order in the middle of a stage change, proof window cannot be dismissed by a tap
outside, discard question, keyboard up, layout matrix (7 phone/language/text/theme variants) with the 48 dp and label guidelines.
Seen failing once with the protection removed: the "settled list" check and the proof window's dismiss lock.

## Needs the backend or the owner (not done here)

1. **Line items** are not in the driver job or the branch queue responses (`items_count` only), so neither window can list what is
   in the order. API change: add `items` (name, quantity, image) to `GET /admin/dispatch/driver/jobs` and `GET /admin/dispatch/queue`.
2. **Pickup pass / QR** and click-and-collect hand-over are not in these apps (see `DRIVER_STAFF_APPS_QA.md`, item 2).
3. `lib/core/widgets/side_panel.dart` now lifts the footer above the keyboard and shows panel toasts; the ops windows rely on both.
   Stacked windows (proof or reason above the delivery window) are used on purpose, so Back peels one at a time and the driver
   returns to the details after cancelling.

## Not verified

No real phone or tablet was available: the animations, the real keyboard, the real camera, the dialler and the navigation app are
covered by widget tests with fakes only.
