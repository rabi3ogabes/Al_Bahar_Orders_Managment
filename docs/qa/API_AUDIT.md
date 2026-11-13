# API audit (HTTP layer)

Scope: `routes/api*.php`, `app/Http/**`, the domain services they call, webhooks (SADAD, delivery providers, n8n), the partner share link,
push registration, rate limits, config. 207 `/api/v1` routes were enumerated with `php artisan route:list --json`; every one was swept with
garbage path parameters, array/oversized query parameters and empty bodies (`tests/Feature/Api/RouteRobustnessTest.php`), as customer, staff and guest.

Severity: H = security or data-integrity, M = wrong behaviour users can hit, L = hardening. Status: Fixed (with a regression test), Owner (decision needed), Client (not a server bug).

## Findings

| # | Area / endpoint | Check | Finding | Sev | Fix | Status |
|---|---|---|---|---|---|---|
| 1 | Every `{id}` route (`orders/{id}`, `me/sessions/{id}`, `auctions/lots/{lotId}`, partner/webhook bindings…) | malformed uuid | PostgreSQL `22P02` bubbled up as HTTP 500 (and aborted the transaction) | M | `bootstrap/app.php` maps `22P02` to 404 | Fixed |
| 2 | Auction bid / auto-bid, any `Money::of` caller | validation of money | `1e3`, `.5`, 14+ digit amounts gave 500 (`InvalidArgumentException`, `TypeError` int overflow, numeric overflow `22003`) | M | `Money::of` limited to 13 integer digits and throws `InvalidMoney`; `InvalidMoney` and `22003` render as 422 `invalid_amount` | Fixed |
| 3 | `POST auctions/lots/{lot}/bids` | idempotent replay | Retrying a *rejected* bid with the same key answered 200 "replayed" (looked like success) | M | `BidEngine` re-throws the stored rejection (also for the in-transaction replay) | Fixed |
| 4 | All `/api/v1/admin/*` list endpoints | query validation | `?search[]=x`, 5 kB strings, non-uuid `branch_id` reached query builders: 500 on orders, branches, catalog, inventory (stock, movements, suppliers) | M | `ValidateStaffQuery` middleware (`staff.query`): 422 field errors, 120-char cap | Fixed |
| 5 | `POST admin/refunds/{refund}/reject` | race / state machine | Rejection used a stale model without a lock: a concurrent approve/process could be overwritten by "rejected" | H | reject runs under `lockForUpdate` and re-checks status (409) | Fixed |
| 6 | `POST admin/payments/{payment}/refunds` | idempotency | Key reused for another payment returned that other payment's refund (leak); header unvalidated (300-char key = 500) | M | cross-payment reuse is 409 `idempotency_key_reused`; header validated (400 `idempotency_key_required`) | Fixed |
| 7 | `POST admin/orders/{order}/notes` | IDOR (write) | `fulfillment_id` could point to another order's fulfillment | M | `Rule::exists(...)->where('order_id', …)` | Fixed |
| 8 | `POST orders/{id}/cancel` and staff `decideCancellation` | double submit | no lock: a double tap created two cancellation requests; two approvers could both decide | M | `OrderService` runs both under row locks in a transaction | Fixed |
| 9 | `POST orders/{id}/returns` | over-claim | same item twice in one request, or a second request, could claim more than handed over; no return window server-side (UI said 14 days) | H | locked transaction, `distinct` lines, quantity minus open requests, `OrderPresenter::returnWindowOpen` (14 days) used by API and `can_return` | Fixed |
| 10 | `PATCH me/addresses/{id}` | validation / data | PATCH of one field (label, default) failed 422 (`zone_number` required); `is_default: true` left two default addresses; unlimited addresses | M | location rules only when sent; previous default demoted in a transaction; cap of 25 | Fixed |
| 11 | `POST auth/register`, `PATCH me` | email uniqueness | `unique:users,email` is case-sensitive while login is case-insensitive: `BOSS@x` next to staff `boss@x` made staff login ambiguous (login DoS) | H | e-mails stored lower-case (model mutator), requests lower-case before the unique rule | Fixed |
| 12 | `auth/login`, `otp/request`, `password/reset` throttles | rate-limit bypass | limiter keyed on the raw phone string: `+974 5512 3456`, `55123456`, `٥٥١٢٣٤٥٦` were different buckets; `phone[]=x` crashed the limiter (500) | H | limiter key is the normalised phone/e-mail; non-strings handled (422) | Fixed |
| 13 | `auth/register` | race | two sign-ups for one number: loser got a unique-violation 500 | L | caught, answered 409 `phone_taken` | Fixed |
| 14 | Support tickets, reviews, Q&A, review reports, auction registration, change password | rate limits | no throttle (spam / brute force of current password with a stolen token) | M | per-user `throttle:N,60` on each | Fixed |
| 15 | `POST webhooks/delivery/{provider}` | webhook input | signed payload without `reference` matched deliveries with NULL reference (`where(col, null)` = IS NULL) and could move them | M | missing reference is ignored | Fixed |
| 16 | `POST webhooks/sadad` | unauthenticated storage | invalid callbacks stored the whole body (up to 30 MB) per request | L | forged payloads truncated to 8 kB / key list | Fixed |
| 17 | Admin product image upload | decompression bomb | tiny file declaring a giant canvas exhausted memory in GD | L | `getimagesize` guard (8000 px side, 40 MP) | Fixed |
| 18 | `me/support/tickets` (list, show) | contract | storefront reads `created_at` (blank dates), apps read `order_id`/`updated_at`/`status_label` on detail; server omitted them | M | fields added to both responses | Fixed |
| 19 | `Idempotency-Key` handling | consistent envelope | bid and payment retry answered code `http_error`, checkout `idempotency_key_required` | L | one helper `ApiController::idempotencyKey()`; all money endpoints give 400 `idempotency_key_required` | Fixed |
| 20 | Proxy trust | IP spoofing | `trustProxies('*')`: if the edge appends to `X-Forwarded-For`, a client can fake its IP and dodge every IP-keyed limit (login, OTP, partner unlock) | H | `TRUSTED_PROXIES` env (default `*` kept so nothing breaks); documented in `.env.example` | Owner |
| 21 | OTP test mode | auth bypass if left on | `OTP_TEST_CODE` set with `SMS_DRIVER` != http makes every code that value and returns it as `debug_code`; not blocked in production | H | not changed (owner uses it as the temporary mode) | Owner |
| 22 | Force-update gate (426) | bypass | only enforced when `X-App*` headers are sent; omitting them skips the gate (by design) | L | none | Owner |
| 23 | Storefront `npm audit` | dependency | 3 high: `react-router-dom` / `@remix-run/router` (open redirect GHSA-2j2x-hqr9-3h42) | M | storefront is not ours to edit | Client |

Checked and found sound (no change): token abilities (customer vs staff cannot cross), staff branch scoping (`AccessControl`, `BranchScope`, per-service
branch checks on orders, deliveries, pickups, refunds, transfers, stocktakes, purchase orders, employees with no self-elevation), refresh-token rotation and reuse
detection, OTP hashing / attempts / expiry, password change and reset revoke sessions, checkout idempotency (advisory lock + unique key), coupon and promotion limits
(atomic `UPDATE … WHERE times_used < limit`), loyalty redeem and referral qualification (row locks), payment state machine (late failure never overrides paid,
amount mismatch held, duplicates ignored, signature on the callback checksum), delivery and n8n webhooks (HMAC on the raw body, `hash_equals`, 5-minute window),
partner link (token + password, throttled, session fingerprint), SSRF (n8n URL admin-only and https-only, no user-supplied fetches), `composer audit` (clean),
backend `npm audit` (clean), money is integer minor units everywhere, `ilike` on public search escapes `%` `_`.

## Tests added

`tests/Feature/Api/RouteRobustnessTest.php` (2 tests, 6 cases: garbage path params and array/oversized/wildcard query params, customer/staff/guest) and
`tests/Feature/Api/ApiAuditTest.php` (24 test functions, 26 cases with data sets covering findings 2-19 plus the customer-isolation matrix, token-channel separation and branch isolation).
Each fix test was seen failing with the fix removed (cancellation double-tap is a sequential guard; the lock itself is covered by the existing concurrency suite).

## Unfixed / owner decisions

1. Set `TRUSTED_PROXIES` to the real load balancer / CDN addresses before go-live (finding 20).
2. Clear `OTP_TEST_CODE` and set a real SMS driver before go-live (finding 21).
3. Decide whether the 426 gate should also apply to requests without `X-App*` headers (finding 22).
4. Staff list endpoints still use `ilike '%term%'` without escaping `%` and `_` (staff-only, no injection, only broader matches).
5. `Redis` health test fails in the sandbox (no Redis); `tests/Concurrency` must be run on its own (`php artisan test tests/Concurrency`): its `race()` helper is not loaded under `--parallel`.

## Client-side notes (not edited here)

* storefront `types.ts`: `Address.zone_number` is typed `string` but the API returns `null` for map-pin addresses; `BranchHours.holidays[].date` does not exist (server sends `starts_at`/`ends_at`/`name`/`type`; the field is unused today).
* storefront: upgrade `react-router-dom` (finding 23).
* mobile: no path or field mismatches found in the customer repositories against the routes and resources; `getPage` tolerates the un-paginated ticket/notification lists.
