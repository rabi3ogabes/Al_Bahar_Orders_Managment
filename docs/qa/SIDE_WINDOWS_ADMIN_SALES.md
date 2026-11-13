# Side windows: Sales, Finance and Auctions (dashboard)

Rule (see `docs/guides/SIDE_WINDOWS.md`): quick views, details and edit forms open in a window beside the page, so the list keeps its place and one Back closes the window. A page is only for large multi-section editors, printable documents and live tools.

Scope: `resources/views/livewire/admin/{sales,finance,auctions}` and their Livewire classes. Every full-page route is unchanged (notifications, e-mails and shared links still land on them).

## How it is built

| Piece | What it does |
|---|---|
| `x-admin.dialog` (shared) | End-side window (left in Arabic), bottom sheet on phones, `variant="modal"` for decisions. |
| `x-admin.drawer-bar` | First row of a record window: status badges, "Open full page", and it tells the server about every close (✕, Esc, scrim, Back) so `?view=` leaves the address bar. When the window closes only because the page is navigating away, it sends nothing (sending a request then raised Alpine errors). |
| `HasRecordWindow` (trait) | A list opens a record in a window (`?view=<id>`, Livewire `#[Url]` + `#[Locked]`). Only the server opens it, after the record's own gate; a stale id, another branch's record or a non-UUID opens nothing. The record stays in the window after closing, so it slides out with its content, and a counter changes the nested component's key on each open, so data is fresh. |
| `PeeksAtOrder` (trait) + `partials/order-peek` + `x-admin.order-peek-link` | Any list can open an order in a window. A window inside a window (customer or payment) asks for it with the `peek-order` event, so the order lands on top and Back closes only the top one. |
| `OrderShow`, `CustomerShow`, `PaymentShow` with `drawer: true` | The page's own component in compact mode (no page chrome, one column, short lists instead of pages, no route map). Same data, same permissions (`viewableBy()` is the page's gate, used by both), same actions with their own audit entries and confirms. A successful action dispatches `record-changed`, so the list behind refreshes its row. |
| Nested decisions | Modal over the window. The modal's button row is wrapped once more because the shared `.drawer-body form > div:last-child` rule otherwise also matches a modal that sits inside a window (visible as a beige band under the buttons). |

## Decisions

| Flow | Before | After | Why |
|---|---|---|---|
| Orders list, row | Link to `/admin/orders/{id}` | Window with the full order (state, lines, actions, payments, returns, timeline, notes), `?view=<order id>`, "Open full page" | Staff triage many orders in a row; the list keeps filters, page and scroll. |
| Order window actions | Page | Accept / prepare / ready / pickup ready / arrange delivery / cancel (part or whole) / notes / cancellation decision, same permission + audit | Same component. Cancel, return, cancel-part are decision modals with a question title and verb button; "arrange delivery" is a form (window on the page, modal over a window). |
| Customers list, row | Link to `/admin/customers/{id}` | Window: contact (masked as on the page), 4 figures, recent orders, addresses, loyalty (adjust points), consents; suspend / reactivate through a modal | Quick look-ups. An order tapped inside opens on top. |
| Payments list, row | Link to `/admin/payments/{id}` | Window: details, refunds, collections, provider ledger; re-check, resolve, request refund (modal) | Finance checks one payment after another. |
| Reconciliation, payment link | Link to the payment page | Same payment window (`?view=`) | Same flow, same component. |
| Returns list, row | Already a dialog (now a window) | `?view=<return id>`, decision inputs in the body and the step's buttons pinned in the footer, order opens on top | Needed a URL; the pinned primary action. |
| Deliveries board, pickup counter, refunds, cash, fleet map: order number | Link to the order page | Order window over the list (fleet map: the driver card's "open order" opens it above the map) | Dispatch, counter and finance staff never leave their board. |
| Deliveries: create / assign / reschedule | Dialog | Window (forms); "mark failed" and "cancel delivery" are modals with question titles | Forms vs decisions. |
| Refunds: reject / mark paid out; Cash: receive, review, bank | Dialog | Reject is a modal; the rest are windows | Same rule. |
| Reconciliation: resolve | Dialog | Window (form with a note) | Form. |
| Driver console: deliver / fail sheet | Hand-made bottom sheet | Shared window (bottom sheet on a phone; Back, Esc, focus trap, pinned buttons) | One behaviour everywhere. |
| Auctions list, row | Link to the editor | Quick view window: status, window, rules, lots (console link per lot), Publish for drafts, "Edit auction" | A glance without opening a large form. |
| Auction editor: add / edit lot | Dialog | Window; fields in two columns so prices and dates fit | Form. Cancel auction stays a modal. |
| Auction console: open, pause, extend, cancel, retract, block | Dialog | Modals with verb buttons | Every step is a decision about a live lot. |

## Stays a page (and why)

- Auction editor (English and Arabic content, schedule, rules, lots): large multi-section form. The list's quick view links to it.
- Auction live console: a live tool with a feed and a clock. Reached from lot rows and the "live now" cards.
- New order and the order cart: checkout-like flow (the cart is already a side panel). Pickup scanner: a camera tool.
- Order invoice: printable document.
- Full pages of an order, customer and payment: kept for deep links; each window offers "Open full page".
- Small links from inside a window to another screen (customer profile, a payment, a return, dispatch) open that screen's list with its own window (`?view=`), or the page when there is no window for it.

## Verification

- Pest: `tests/Feature/Admin/SideWindowsSalesTest.php` and `SideWindowsFinanceTest.php` (31 tests). Per flow: opens from `?view=`, shows the record, clears `view` on close, nothing for another branch / a non-UUID / a made-up id, personal data masked as on the page, actions inside work and write their audit entry and dispatch `record-changed` (the page does not), read-only roles are refused. Seen failing once with the order gate removed (6 failures), then restored. Whole `tests/Feature/Admin` (251) and `tests/Feature/AdminQa` (21) pass.
- Browser (Playwright, own database): every window at 1280 and 375 px, English and Arabic, light and dark: no console errors, no horizontal overflow. Open then Esc, scrim, ✕ and browser Back each close exactly the top overlay and `?view=` is cleared; focus returns to the row (a click on a plain cell too); deep link `?view=` opens the window and Back closes it without leaving the page; "Open full page" then Back returns to the list with the window open; a modal over a window closes alone on Esc and on Back; a customer window with an order on top closes top first; nested confirm (`wire:confirm`) over a window closes alone.
- Fleet map: the window opens above the map (stacking checked) and a click on an order link inside the map section opens it without leaving the page (checked with an injected link; no driver with a live position was available for the real card).
- Not exercised: live toasts and map tiles (no Reverb server, the proxy blocks tiles), real SADAD payments.
