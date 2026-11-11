# Architecture

Al Bahar Home & Living is a **single-company, multi-branch** home & furniture retailer in Qatar (not a marketplace).
One Laravel modular monolith serves the **REST API** (`/api/v1`) for the Flutter apps and the **Blade + Livewire admin
dashboard** (`/admin`). PostgreSQL is the single source of truth; Redis provides cache, queues, locks and sessions;
Laravel Reverb distributes real-time auction updates.

```
 Flutter app (Android/iOS/Web-ready) ──HTTPS──▶ Nginx ──▶ PHP-FPM (Laravel 13)
                                    ──WSS───▶ Nginx ──▶ Reverb (Pusher protocol)        ┌─ PostgreSQL 16 (system of record)
 Staff browser (Livewire admin) ────HTTPS──▶ Nginx ──▶ PHP-FPM                            ├─ Redis 7 (cache, queue, locks, sessions)
 SADAD / couriers ──signed webhooks──▶ /webhooks/*                                       └─ S3-compatible object storage (media)
 Queue workers (notifications, exports) · Scheduler (expiry, auctions tick, reconciliation)
```

## Repository layout

| Path | Contents |
|---|---|
| `backend/app/Domain/<Module>` | Domain models, services, enums, events — **all business rules live here** |
| `backend/app/Http/Controllers/Api/V1` | Thin REST controllers (validation → service → presenter) |
| `backend/app/Livewire/Admin` | Admin dashboard screens (call the same domain services) |
| `backend/database/migrations` | One migration per phase; constraints & triggers in SQL |
| `backend/tests` | `Unit`, `Feature` (transactional), `Concurrency` (multi-process, real row locks) |
| `mobile/` | Flutter customer app (Riverpod, go_router, dio) |
| `docs/` | Spec, API contract + OpenAPI, database docs, operations, guides |
| `docker/`, `docker-compose.yml`, `ops/`, `.github/workflows` | Runtime, deployment, CI/CD |

## Modules (`app/Domain`)

Identity (auth, OTP, tokens, RBAC) · Branches (hours, zones, slots, employees) · Catalog · Pricing · Inventory ·
Checkout (cart, quote, allocation) · Orders · Payments · Fulfillment (pickup, delivery, providers) · Auctions ·
Marketing (promotions, loyalty, referrals, reviews, campaigns) · Notifications · Cms · Support · Reports · Audit · Settings.
Modules communicate through services and **after-commit domain events** (`OrderPlaced`, `OrderStatusChanged`,
`PaymentStatusChanged`, `AuctionWon`, `AuctionOutbid`, `BackInStock`, `PriceChanged`, `CustomerRegistered`).

## Non-negotiable rules and where they are enforced

| Rule | Enforcement |
|---|---|
| Never trust client prices/stock/discounts/payment | `QuoteService` re-reads everything; order placement re-quotes and compares a **quote hash** (409 `quote_changed`) |
| Branch-scoped authorization | `AccessControl` + `BranchScope` derived from role assignments; client `branch_id` can only *narrow*; every mutation re-authorised for the record's branch |
| Money is decimal-safe | `NUMERIC(12,2)` columns; `App\Support\Money` integer minor units (no floats) |
| Stock never negative / fully audited | `InventoryLedger` is the only writer; row locks; DB `CHECK (reserved <= on_hand)`; immutable `stock_movements` (trigger) |
| Idempotency | Checkout (`orders.user_id+idempotency_key` + advisory lock), payment retry, refunds, bids (`auction_bids.user_id+idempotency_key`), webhooks (`provider+event_id`) |
| Auction integrity | Lot row lock per bid, monotonic `bid_number`, persisted before broadcast, server time only |
| Audit | `AuditLogger` (redacted) for auth, staff/roles, prices, stock, orders, payments, refunds, auctions, settings, exports; append-only trigger |
| Times | Stored UTC (`timestamptz`), evaluated/presented in `Asia/Qatar` (`BusinessTime`) |

## Key business policies

**Price precedence** (`PriceResolver`): variant+branch → product+branch → variant → product; the first level with a
regular price wins and its sale price applies only inside its window. Availability is checked first; a product disabled
in a branch is *never* priced from another level.

**Fulfillment allocation** (`FulfillmentAllocator`): selected branch if it can supply everything → single branch by zone
priority/distance → greedy split (lines may split by quantity). Splits are shown to the customer with each branch's fee
before confirmation.

**Stock deduction point**: reserved at order placement (online payments: reservation expires with the 30-min payment
window); deducted when goods leave the branch (delivery: dispatch/pick-up by driver; pickup: handover, partial supported).
Cancellation before that releases exactly once; returns re-add stock as `customer_return`.

**Promotion stacking**: free-delivery always combines; stackable item promotions apply cumulatively by priority; the best
single non-stackable is compared with the stackable total and the larger saving wins; one coupon per order; usage limits
enforced atomically at placement and reversed on cancellation.

**Auction tie policy**: equal proxy maximums → earliest maximum wins. Anti-sniping extends `ends_at` for bids inside the
window (bounded by `max_extensions`). Winner = highest accepted amount, then lowest `bid_number` (reproducible).

## State machines

### Order (parent) — derived from fulfillments
`pending_payment → confirmed → processing → (ready_for_pickup | out_for_delivery | partially_fulfilled) → completed`
plus `cancellation_requested` (sticky until decided), `cancelled`, `partially_cancelled`, `returned`.

### Fulfillment (per branch)
```
pending → accepted → preparing → ready → dispatched → out_for_delivery → delivered → returned
                               ↘ pickup_ready → picked_up → returned
dispatched/out_for_delivery → failed → dispatched | returned | cancelled
pending/accepted/preparing/ready/pickup_ready → cancelled
```
Online (SADAD) orders cannot progress past `pending` until paid.

### Payment (independent of order)
`pending → processing → (authorized →) paid → partially_refunded → refunded`; `pending/processing → failed | cancelled | expired`.
Late success after expiry is flagged `late_capture` for finance review — never resurrects the order.

### Delivery
`pending → assigned → accepted → picked_up → out_for_delivery → delivered`; `failed → rescheduled | returning → returned`; `cancelled`.

### Refund
`requested → approved → processing → processed | failed`; `requested → rejected`. Dual control (approver ≠ requester).

### Auction lot
`pending → open ⇄ paused → sold | unsold`; `cancelled`. Winner: `pending_payment → paid | defaulted` (→ next bidder / relist / none).

### Inventory documents
Transfer `draft → pending_approval → approved (reserved) → in_transit → partially_received → received → completed` (+ rejected/cancelled).
Purchase order `draft → pending_approval → approved → partially_received → received → closed` (+ cancelled/rejected).
Adjustment `pending_approval → posted | rejected`. Stocktake `counting → submitted → posted | cancelled`.

## Security model

- Customers: OTP (HMAC-hashed, 5-min TTL, 5 attempts, cooldown, hourly cap) or password; Sanctum access tokens (60 min)
  + rotating refresh tokens (SHA-256 stored, reuse revokes the family).
- Staff: separate login channel (web session + optional API tokens with `staff` ability), TOTP 2FA with recovery codes
  and replay protection, strong password policy, no self-elevation, grants limited to the granter's own authority.
- Secrets: env/secret store only; settings marked secret are encrypted and masked; logs & stored provider payloads are
  redacted (`Redactor`); OTP codes are never logged.
- Webhooks: SADAD checksum verification + optional status API; courier HMAC signatures; idempotent event tables.
- Headers: CSP-friendly assets (self-hosted fonts), `X-Frame-Options`, `nosniff`, HSTS on TLS; CSRF on admin; throttles
  on OTP, login, reset, checkout, bids, webhooks, exports.
