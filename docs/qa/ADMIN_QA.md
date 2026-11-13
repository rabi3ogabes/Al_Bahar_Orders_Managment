# Admin dashboard QA log

Scope: every route in `backend/routes/admin.php` (66 pages incl. login/2FA), Livewire components in `backend/app/Livewire/Admin`.
Method: local copy (own DB `albahar_qa_admin`, seeded demo data + orders placed through the New order page), headless Chromium crawl of every
route at 1440 and 390 px, EN/AR (RTL), light/dark (console + Livewire errors, overflow, screenshots reviewed), form fuzzing (XSS string, 450-char
Arabic + emoji, NUL byte/RTL-override/template strings) on 22 create dialogs, query counts per page, static audit of every public Livewire action
for permission/branch checks, locked properties and mass assignment, plus Pest tests (`tests/Feature/AdminQa`).

| # | Page / form | Check | Bug (severity) | Fix | Status |
|---|---|---|---|---|---|
| 1 | All admin pages (Livewire updates) | permission re-check on every action | **High**: Livewire only re-runs its own short list of persistent middleware, so `staff` and `permission:*` did not run on update requests; a staff member suspended or stripped of a role after a page loaded could keep running that page's actions (proved live: 200 after suspend) | `AppServiceProvider`: `Livewire::addPersistentMiddleware([EnsureStaff, RequirePermission])`; tests `LivewireGuardTest` | Fixed |
| 2 | Any text field (admin, API, store) | NUL byte input | **High**: `\0` in any string made PostgreSQL reject the insert/audit row, 500 (also on public API login) | new `StripNullBytes` global middleware | Fixed |
| 3 | HTML editors (product description, CMS pages) | XSS | **High**: regex sanitiser let `<a href=javascript:...>` (unquoted) and entity-encoded schemes (`java&#9;script:`) through into public pages | `ProductService::sanitizeHtml` rewritten as DOM allow-list (tags, no attributes except safe `href`, `rel=noopener`) | Fixed |
| 4 | All list pages | `perPage` public property | Medium: client could request an unbounded page size | `#[Locked]` on `WithAdminTable::$perPage` | Fixed |
| 5 | Order > Internal notes | permission | Medium: read-only Auditor (orders.view) could add notes | `OrderShow::addNote` requires `orders.edit` on an order branch, form hidden otherwise | Fixed |
| 6 | Employee editor (create/edit) | uniqueness | Medium: e-mail differing by case, or phone differing by spaces, passed validation then hit the DB unique key (500) | normalise before validating | Fixed |
| 7 | Zones, Suppliers | code uniqueness | Medium: `westbay` vs stored `WESTBAY` -> 500 | upper-case before validating | Fixed |
| 8 | Refund / price / amount inputs | money parse | Medium: `12.34567` or `1e3` returned a raw 422 error popup (the API-style mapping cannot name a Livewire field) | Livewire `exception` hook turns `InvalidMoney` / numeric-overflow into an error toast | Fixed |
| 9 | Loyalty screen vs Settings | rules | Medium: the two screens disagreed (expiry 0 allowed in Settings, which expires points instantly; value rounded to 2 decimals, limits 10/100 vs 100/1000) | one set of limits, up to 4 decimals kept | Fixed |
| 10 | Fleet map | JS | Medium: page error "Map container is already initialized" (`init()` ran twice: Alpine auto-call + `x-init`) | removed `x-init` | Fixed |
| 11 | New order | layout | Medium: at 1440 px the product grid was 3 columns inside the narrow column beside the docked cart; names and Add buttons clipped | grid 2 columns, 3 only >= 1800 px | Fixed |
| 12 | Destructive buttons (`wire:confirm`, ~25) | confirm wording | Low: browser OK/Cancel box | themed dialog: question + Cancel + the action's own verb, Esc closes, focus kept (`resources/js/app.js`) | Fixed |
| 13 | Dashboard (AR) | text | Low: Latin comma after the greeting | locale-aware separator | Fixed |
| 14 | Validation (AR) | lang | Low: password-rule messages fell back to English | added `password.*` to `lang/ar/validation.php` | Fixed |
| 15 | Local build | env | `public/build` was stale (docked cart and header labels missing) | `npm run build` (not tracked) | n/a |

Checked and fine: all 66 routes render without console/Livewire errors in EN/AR, light/dark, 1440/390 (no page-level horizontal scroll;
wide tables scroll inside their own container); XSS strings are escaped in lists; 22 create dialogs survive hostile input; branch scoping and
`authorizeBranch` present on every mutating action (deliveries, pickup, refunds, stock, orders, payments, employees); Locked ids on detail
components; uploads limited to jpg/png/webp (real mime) with size caps, SVG excluded; settings/role changes audited; 0 N+1 (3-72 queries per
page, `/admin/orders/create` 72 batched); money uses `Money` (half-up, 4-decimal parser).

## Unfixed / owner decisions
- **Save disabled until dirty**: only App footer and Journeys do it; the other forms keep Save enabled. A generic fix needs per-form dirty state; left for a decision.
- **Back button closing the top overlay** is not implemented (Esc and the close button work).
- **Password change does not sign out other web sessions** (API tokens are revoked). Needs `AuthenticateSession` middleware.
- Search boxes on several lists treat `%`/`_` as wildcards (StockIndex escapes them); harmless, not changed.
- An order that spans branches shows every branch's lines to a manager of one of them (parent-order design).
- Test failures outside this scope seen while running `tests/Feature/Catalog`: `CatalogManagementTest` "circular category nesting" (API category tree) and `PhotoStudioTest` "AI writer not set up" (502 from the sandbox network); not touched by these changes.
- Live toasts/Reverb could not be exercised (no Reverb server locally); fleet map tiles blocked by the sandbox proxy.
