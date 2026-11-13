# Side windows — catalog, inventory, marketing, content, organization, insights (dashboard)

Owner request: quick views, details and small edits open in a window beside the page (like the cart) instead of a page you must Back out of.
Rules and the shared component: `docs/guides/SIDE_WINDOWS.md`. Scope of this log: everything under `resources/views/livewire/admin/{catalog,content,inventory,marketing,organization,insights,branches}`,
the dashboard, the admin layout and the shared admin components. (Sales, Finance and Auctions are logged by their own agent.)

## 1. Shared pieces added or changed

| Piece | What it does |
|---|---|
| `resources/js/overlays.js` (new) | One stack for every window, decision and themed confirm. Esc and Back close **only the top one**; each window owns one history entry; a `wire:navigate` or a redirect from inside a window first closes the windows (the history never keeps a stale entry, the page scroll is never left locked); a window holding unsaved input asks first. |
| `components/admin/dialog.blade.php` | Uses the stack. `requestClose()` is the one way out for ✕, scrim, Cancel, Esc and Back: **"Discard your changes?"** (Keep editing is focused) when the window holds unsaved input; a Save that closes it from the server never asks. Focus moves in (first field; Cancel in a decision; nothing on touch screens, so the keyboard does not pop up) and returns to the opener. `wire:model.live` makes a record window sync its close at once (clears `?view=`). A non-form button that saves marks itself `data-saves`; instant switches mark themselves `data-no-dirty`. |
| `resources/css/app.css` (`.drawer-body`) | The form's last row is pinned to the bottom edge even for a short form, solid background, one row on phones (Cancel + action side by side; with 3 buttons the main one takes the first row). Unlayered so it beats the forms' own `flex-col-reverse`. **Never matches a decision modal nested inside a window** (`form:not(.card, [role=alertdialog] form)`), nor an in-page card form embedded in a window. |
| `layouts/admin.blade.php` | Toasts are at `z-[80]` (above windows 68 and decisions 72, below the themed confirm 90) and move up above the pinned row while a window is open. |
| `resources/js/app.js` | The themed `wire:confirm` is now an overlay too (own focus trap): it works above a window, Esc/Back close only it, focus returns to the button. |
| `Concerns/WithRecordView` (new) | `?view=<id>` record window for a list: `openView`, `closeView`, `viewable()` with the **same scoping as the page replaced**, a made-up or non-UUID id never opens and leaves no trace, the id is re-checked on every render. |
| `lang/{en,ar}/drawer_admin.php` (new) | New wording only (no existing key touched). |

Bugs found and fixed on the way: the journeys template editor printed `{{ {{ $ph }} }}` instead of the placeholder names (Blade escape), and the two-button action rows of the new drawers stacked two tall buttons on phones.

## 2. What opens beside the page now

| Flow | Before | After | Why |
|---|---|---|---|
| Products list → a product | full editor page | **Quick view window** (`?view=`): photo, names, category/brand, price, stock per branch the viewer may see, switches Visible in store / Featured / New arrival / Bestseller (save at once, audited through `ProductService`, "Saved" toast, the list row refreshes), variants and upsell counts, primary action "Open full editor" | quick look and the common flips need no page; the editor stays a page: large multi-tab (content, pricing, variants, media, upsell, specs, history) |
| Purchase orders → a PO | detail page | **Window** (`?view=`) with the same `PurchaseOrderShow` component embedded (`embedded=true`): lines, submit / approve / reject / receive / close / cancel, receipts, timeline, "Open full page". Creating a PO now opens it beside the list instead of redirecting | one component = same actions, branch scoping and audit as the page; Back is "close the window" |
| Transfers → a transfer | detail page | **Window** with the embedded `TransferShow` (approve with quantities, dispatch, receive, complete, reject/cancel with reason). Creating a transfer opens it beside the list | same as above |
| Stock counts → a session | count screen | **Summary window**: status, branch, who/when, progress bar, uncounted / variances / net variance, primary action "Continue counting" (or "Open count screen") | the scan-and-count screen (barcode focus, wide table, paging) is a working screen and stays a page; starting a count still goes straight to it |
| Issue log → an issue | ad-hoc drawer (no URL, no Back) | **Window on the shared component** (`?view=`), resolution box and status buttons pinned at the bottom | now Back/Esc/URL like every window |
| Support desk → a ticket | split view; on phones the thread replaced the list with an in-page back button | **Ticket window** (`?ticket=` kept): conversation scrolls, assign / priority / resolve on top, the reply box pinned at the bottom (Internal note, status after reply) | Back closes the ticket on every device; the queue stays in view |

Already side windows (kept, now on the unified behaviour, checked in the browser): attributes, brands, categories, product price and variant editors, banners, homepage sections, notification templates, CMS pages, looks (themes), adjustments, new PO, thresholds, new count, suppliers, new transfer, campaigns, journey step and template editors, promotions and coupons, zones, issue report.
Decisions (centred, `variant="modal"`, never closed by the scrim): design switch, referral reject, force app update, remove push key, rotate partner link, discard changes.

## 3. What stays a page, and why

| Page | Why |
|---|---|
| Product editor | big multi-tab editor (the quick view is the window) |
| Stock count screen | barcode-driven working screen |
| Employee editor, Role editor | security-sensitive forms: per-branch role assignment and the permission matrix; the list already shows branches, roles, last sign-in and status inline, so a window would only repeat the row |
| Branch editor | multi-section editor (details, hours, delivery zones) |
| Settings, Reports, Audit log, App footer, Appearance | whole-screen content, not records opened from a list |
| Referrals, Loyalty, Reviews, Promotions, Campaigns, Journeys | no page behind a row: the row shows everything inline and the edit/decision already opens a window |
| Dashboard | its tiles and tables link to Sales pages (the Sales agent owns those windows) |

## 4. Tests

`backend/tests/Feature/Admin/SideWindowsAdminOtherTest.php` (37 tests) plus the two inventory tests updated for "create opens the record window instead of redirecting".
Covers: dialog markup (role `dialog` vs `alertdialog`, every width class, end-side slide classes for LTR/RTL, `wire:model.live` sync, pinned-row CSS excludes nested decisions); for products, purchase orders, transfers, stock counts, issues and support tickets: opens from `?view=`/`?ticket=` and on click, right record, other branch / made-up id / non-UUID / no permission opens nothing, instant changes audited, list told to refresh, closing clears the id, locked `embedded`, read-only viewers cannot change.
Run (own database): `DB_DATABASE=albahar_sidewin_b php artisan test --parallel --processes=3 tests/Feature/Admin` → see the final report for the counts.

## 5. Browser checks (Playwright, local copy on an own database)

Repeatable scripts in `backend/tests/e2e-side-windows/` (not part of CI: need a running app, a seeded database and Chromium):
- `overlays.mjs` — Back / Esc / scrim / ✕ close exactly the top window, one history entry each, no stale entry after Esc or after navigating out of a window, focus returns, scroll never left locked, toast above the window and clear of its action row, "Discard changes?" flow (✕, Esc, Back, Discard, Keep editing, successful Save asks nothing, validation error keeps protection), a `wire:confirm` above a window (Esc and Back close only the confirm). Run for EN 1280 px and AR 375 px.
- `modal-over-drawer.mjs` — a decision modal opened over a window (Customers → Suspend) has no sticky band, no border, no negative margins at 375 and 1280 px, English and Arabic, light and dark (48 checks). It was seen failing (sticky, tinted band) with the CSS exclusion removed, then passing again.
- Every dialog in scope opened at 1280 and 375 px, EN and AR, light and dark: no horizontal overflow, no console errors, Esc closes, focus returns, pinned row at the bottom edge, screenshots reviewed.

## 6. Not verified / left

- Live toasts over Reverb (no socket server locally); fleet map tiles are blocked by the sandbox proxy (Sales scope).
- Reload while a window with `?view=` is open re-uses its history entry; reloading a window that is *not* URL-synced (plain editors) closes it and removes its history marker, so Back is not a dead step.
- `wire:poll` requests in flight at the moment of ✕ are treated like a Save (no discard question). None of the windows poll.
