# API assumptions (mobile ↔ Laravel `/api/v1`)

The app was first built against `docs/api/API_CONTRACT.md`. Every item below has since been
checked against the real backend (`backend/routes/api*.php`, `app/Http/Controllers/Api/V1/**`,
the presenters in `app/Domain/*/Services/*Presenter.php`, `app/Http/Resources/V1/*`).
**Every parser is lenient** (missing optional fields never crash; ints/strings are coerced).

Status legend:

- **✓ verified**: the backend already matches what the app does.
- **fixed in app**: the backend differed, and the app now follows the backend. Covered by
  `test/features/backend_contract_test.dart` (backend-shaped JSON) unless noted.
- **backend gap**: the backend lacks something. The app degrades gracefully, and the exact
  need is listed in [Backend gaps](#backend-gaps).

Money is always `App\Support\Money::jsonSerialize()` = `{amount: "1250.50", currency: "QAR",
formatted: "QAR 1,250.50" | "1,250.50 ر.ق"}`. Prices are `EffectivePrice::toArray()` =
`{regular, sale, final, discount_percent, sale_ends_at, source}`. ✓ verified.

## 1. Environments & transport

| Flavour | `API_BASE_URL` default | Reverb (Pusher protocol) default |
|---|---|---|
| dev | `http://10.0.2.2:8000/api/v1` (Android emulator → host) | `ws://10.0.2.2:8080/app/albahar-dev` |
| staging | `https://staging-api.albahar.qa/api/v1` | `wss://staging-ws.albahar.qa:443/app/albahar-staging` |
| prod | `https://api.albahar.qa/api/v1` | `wss://ws.albahar.qa:443/app/albahar` |

All are overridable with `--dart-define` (see README). Hostnames are placeholders until DNS is final.

| Item | Status |
|---|---|
| `Accept-Language` `ar`/`en`, `X-Request-ID` per request; error envelope `{message, code, errors?, context?, request_id}` (`bootstrap/app.php`) | ✓ verified |
| Query arrays as `brand_id[]=…`, booleans `1`/`0`, empty params omitted | ✓ verified (`brand_id`/`attribute_values` are validated as arrays) |
| Non-paginated lists are `{data: [...]}`; paginated lists carry `meta.{current_page,last_page,per_page?,total}` | ✓ verified. Unpaginated: categories, brands, questions, wishlist, tickets, addresses, nearby branches, bids, my bids/watchlist/wins, timeline, pickup slots, zones. Some `meta` blocks have no `per_page`, which is tolerated. |

## 2. Auth & session

| Item | Status |
|---|---|
| `POST /auth/otp/request {phone, purpose}` → `{sent, expires_in, resend_in, debug_code?}` | ✓ verified |
| `POST /auth/otp/verify {phone, code, device_name, platform}` → `{status: authenticated, user, tokens}` or `{status: registration_required, registration_token, expires_in}` | ✓ verified |
| `POST /auth/register {registration_token, name, email?, password?+password_confirmation, locale?, referral_code?, marketing_consent}` | ✓ verified |
| `POST /auth/login {phone | email, password}`, `POST /auth/password/reset {phone, code, password, password_confirmation}` | ✓ verified |
| Refresh `POST /auth/refresh {refresh_token}` → `{data: {tokens}}`; tokens `{access_token, refresh_token, token_type, expires_in, session_id}` | ✓ verified. Definitive failures are 401 (`refresh_invalid`/`refresh_expired`) or 403 (`account_inactive`). |
| Only 401/403/422 on refresh end the session; a network failure keeps the tokens | ✓ verified |
| `device_name` generic label | ✓ verified (free text ≤ 120) |
| `PATCH /me {locale}` after sign-in; `email: null` clears; `UserResource` fields incl. `has_password` | ✓ verified |
| `PUT /me/password` omits `current_password` when `has_password` is false | ✓ verified (`nullable` when the user has no password) |

## 3. Realtime (Laravel Reverb)

| Item | Status |
|---|---|
| Private-channel auth at `/api/v1/broadcasting/auth`; private `auction.outbid` / `auction.won`; replayed event types | ✓ verified earlier (out of scope here) |
| Public event names `bid.accepted`, `lot.extended`, `lot.status` on `auction-lot.{lotId}` | ✓ verified (`LotUpdated::broadcastAs`) |
| Public payload `{sequence, lot, bid?}` | **fixed in app.** The real payload is `{sequence, type, lot, events: [{sequence, type, payload}]}` and has **no `bid` row**. When a `bid.accepted` carries no bid row, the app re-reads `GET /auctions/lots/{id}/bids` and, if the viewer takes part, re-fetches the lot for `my_status`. `lot` omits `my_*`, and the client keeps its own values. Tested in `state_test.dart`. |
| `GET /auctions/lots/{id}/events?after_sequence=N` | ✓ verified. Rows are `{sequence, type, payload, at}` (no `lot`/`bid`), with `meta.{last_sequence, server_time}`. The app re-fetches the lot after applying them. |
| Sequences per lot, contiguous; `Lot.last_sequence`; one broadcast may cover several events (its `sequence` is the last), and the app handles that as a gap → replay | ✓ verified |

## 4. Catalog & home

| Item | Status |
|---|---|
| Every product has ≥ 1 variant | ✓ verified earlier (out of scope here) |
| `ProductDetail.fulfillment: {delivery, pickup}` | **backend gap.** Not sent. The app shows both chips, and the server decides at checkout. |
| Product card/detail/variant/availability fields | ✓ verified (`CatalogPresenter::card/detail`, `CatalogController::availability`). The card also has `purchasable`. Attribute value ids are integers (coerced to strings). |
| Reviews row | **fixed in app.** Real shape: `{id, rating, title, body, author: "Noor" (string, first name), verified_purchase, created_at, photos: ["url", …]}`, with `meta.{total,current_page,last_page}`. |
| Review report body | **fixed in app.** `reason` must be one of `spam, offensive, irrelevant, fake, other`. The app now sends `offensive` (it used to send `inappropriate`, which got a 422). |
| Questions | **fixed in app.** A plain list (latest 50) `{id, question, answer, answered_at}`. There is no `created_at`, so the app uses `answered_at`. `POST` needs `question` with at least 5 characters. |
| `POST /me/stock-alerts {variant_id, type: back_in_stock, branch_id?}` | ✓ verified |
| Home `GET /home?branch_id=` → `{sections: [{id, type, layout, title, subtitle, items}]}`, empty sections dropped server-side | ✓ verified |
| Banner `link.type` | ✓ verified. The backend uses `product, category, brand, auction, url, page`, all of which the app handles. `collection/products/search/lot` are never sent but stay supported. |
| Section `see_all`, promotion `link` | **backend gap** (optional). Neither is sent. "See all" is derived from the section type, and promotions are not tappable. |
| `GET /branches?per_page=50` (paginated resource); `/branches/nearby?lat&lng&radius_km` plain list with `distance_km` | ✓ verified |
| Branch hours `day_of_week` 0 = Sunday, `HH:mm`; pickup slots `{id, date, starts_at, ends_at, capacity, available, is_full}` | ✓ verified |

## 5. Cart & checkout

| Item | Status |
|---|---|
| Cart mutations (`POST/PATCH/DELETE /cart/items`, `PUT /cart`, `POST /cart/merge`) return the full Cart | ✓ verified (`CartService::present`) |
| Invalid coupon on `PUT /cart` → 422 `errors.coupon_code` | **fixed in app + backend gap.** `PUT /cart` stores any code without checking it. Coupon problems only appear as quote warnings `{code: coupon_invalid|coupon_expired|coupon_limit_reached|coupon_not_eligible|coupon_first_order_only|coupon_min_basket|coupon_not_combinable, message}`. After `PUT /cart`, the app now calls `POST /checkout/quote {coupon_code}`. If that reports a `coupon_*` issue, the app removes the code again (`PUT /cart {coupon_code: null}`) and shows the message inline. If the quote cannot judge the code (for example, delivery with no address yet), the checkout quote warnings show the problem later. |
| `PUT /cart` keys `branch_id, fulfillment_type, address_id, coupon_code, loyalty_points` | ✓ verified |
| Cart is user-scoped; `POST /cart/merge {guest_token}` unused | ✓ verified |
| `Cart.warnings` | ✓ verified (strings) |
| Quote request keys incl. `pickup_slot_id/pickup_date/delivery_slot_id/delivery_date/allow_split/express`; response `{quote_hash, fulfillments, lines, totals, discounts, payment_methods: [{code, available, reason}], loyalty: {balance, max_redeemable_points, points_applied, points_to_earn}, warnings, errors, hints, can_place_order, expires_at}` | ✓ verified (`Quote::toArray`) |
| `POST /checkout/orders`: `Idempotency-Key` header (16–128 `[A-Za-z0-9-_]`, so a UUID works), `quote_hash` (64 chars), `payment_method` required → `{order, payment, replayed}` | ✓ verified |
| `409 quote_changed` with `context.quote` | ✓ verified |
| `POST /orders/{id}/payments {method?}` | ✓ verified. The backend ignores `method` and needs `Idempotency-Key`. It returns the PaymentAction `{payment_id, method, status, action: redirect|none, redirect_url, return_url_scheme, expires_at}`. |
| `GET /payments/{id}` includes `order_id`; terminal statuses | ✓ verified (`pending, processing, authorized, paid, failed, cancelled, expired, partially_refunded, refunded`) |

## 6. Orders

| Item | Status |
|---|---|
| `GET /orders` row | **fixed in app.** Real row: `{id, number, status, status_label, payment_status, payment_method, fulfillment_type, items_count, grand_total, placed_at, thumbnail_url}`. The app now reads `thumbnail_url`. |
| `?status=` comma-separated list | **fixed in app + backend gap.** The backend does `where('status', $s)` with **one** value, so a comma list matches nothing. A single-status tab (Completed) is still sent as `?status=completed`. The Active and Cancelled groups page through the unfiltered list (`per_page=50`) and filter on the device, reading up to 5 pages ahead so scrolling never stalls on an empty page. |
| Cancel `reason_code` | ✓ verified (the backend also accepts `payment_issue`). The response is `{status, order}`. |
| Return `reason_code`; body `{items: [{order_item_id, quantity}], reason_code, reason?}` | ✓ verified |
| `POST /orders/{id}/reorder` → `{cart, skipped: [{name, sku}]}` | ✓ verified |
| `Fulfillment.delivery.tracking_url` | **backend gap** (optional). Not sent, so the app hides the "Track" action. Delivery is `{tracking_number, status, status_label, provider, driver_name, eta}`. |
| Pickup pass only for `pickup_ready`/`ready` → `{qr_payload, expires_at, status, branch: {id, name}, fulfillment_number}` (409 if not ready) | ✓ verified |
| Invoice `GET /orders/{id}/invoice` returns a PDF (409 while `pending_payment`) | ✓ verified |
| Order statuses for the tabs, fulfilment statuses | ✓ verified (`OrderStatus`, `FulfillmentStatus` enums) |

## 7. Auctions

| Item | Status |
|---|---|
| `AuctionCard` | **fixed in app.** Real card: `{id, reference, type, status: upcoming|live|completed, title, cover_image_url, starts_at, ends_at, lots_count, deposit: Money?}`. The app now reads `cover_image_url` (it read `image_url`) and `deposit`. |
| `Auction` detail | **fixed in app.** It adds `{description, terms: string, requires_registration, requires_verified_phone, winner_payment_hours, cod_allowed, pickup_allowed, delivery_allowed, my_registration: {status, paddle_number}?, lots, server_time}`. The deposit is the card's `deposit`, not `deposit_required`. Bidder `status` ∈ `approved` (can bid), `pending_deposit`, `blocked`. |
| `POST /auctions/{id}/register {accept_terms: true}` → `{status, paddle_number, deposit_required, deposit_payment}` | ✓ verified |
| Lot fields | ✓ verified (`AuctionPresenter::lot`, incl. `auction_id`) |
| Bid history `GET /auctions/lots/{id}/bids` | **fixed in app.** A plain list (latest 100, newest first) `{bid_number, amount, type: manual|auto, bidder, placed_at}` with **no `is_mine`**. The viewer's bids say `bidder: "You"` / `"أنت"`, and the app now recognises both. The app no longer sends `per_page`. |
| Bid placement `POST /auctions/lots/{id}/bids {amount}` with `Idempotency-Key` → `{bid: {bid_number, amount, status, placed_at}, lot, replayed}` | **fixed in app.** The bid echo has no `bidder`, so the app now marks it as the user's own. |
| Bid errors | ✓ verified (behaviour). The real errors are `422 bid_outbid_min` and `409 bid_*` (`context.min_next_bid`), with no `errors.amount`. The app re-fetches the lot after either and shows the server `message`. |
| Auto-bid / watch responses ignored, lot re-fetched | ✓ verified (auto-bid returns the Lot, watch returns `{watching}`) |
| `GET /me/auctions/bids` | **fixed in app + backend gap.** It returns a list of **Lot** (one per lot the user bid on, with `my_status`), not `{id, amount, lot}`. The app shows the lot's current price because the user's own top bid amount is not provided. |
| `GET /me/auctions/watchlist` → `[Lot]` | ✓ verified |
| `GET /me/auctions/wins` | **fixed in app.** Real row: `{id, status, amount, payment_due_at, order_id, lot}`. `status` ∈ `pending_confirmation, pending_payment, paid, defaulted, declined, cancelled, fulfilled`. "Checkout" now shows only for `pending_payment` with no order and a deadline still in the future, which mirrors `AuctionService::checkoutWin`. |
| Win checkout `POST /me/auctions/wins/{id}/checkout {fulfillment_type, address_id?, branch_id?, payment_method}` → `{order, payment}`; `Idempotency-Key` is sent but not required | ✓ verified |

## 8. Account, notifications, support

| Item | Status |
|---|---|
| Notification preferences `GET`/`PUT /me/notification-preferences {preferences: [{channel, category, is_enabled}]}` → full list (channels `push, sms, whatsapp, email, in_app` × categories `transactional, marketing, auction`) | ✓ verified |
| Push category derived from the event name | **fixed in app.** It now mirrors `NotificationCatalog`: `stock.*`, `cart.abandoned` and `campaign` are marketing, and `auction.*` is auction. |
| `POST /me/devices {platform, push_token, device_id, app_version}`; `DELETE /me/devices {push_token}` before logout | ✓ verified |
| FCM `data` `{event, deep_link, notification_id}`; deep links `albahar://orders/{id}`, `albahar://products/{id}`, `albahar://auctions/lots/{id}`, `albahar://support/{id}` | ✓ verified. `deep_link` may be `""`. |
| Opening a push calls `POST /me/notifications/{notification_id}/read` | **backend gap.** The push `notification_id` is the `notification_logs` id, not the in-app `notifications` id, so the call is a harmless no-op (200). The in-app inbox still marks items read correctly. |
| `GET /me/notifications` rows `{id, event, title, body, data, read_at, created_at}` and `meta.unread_count` | ✓ verified |
| Loyalty `{balance, tier: "classic"|…, lifetime_earned, value: Money, transactions}`; referral `{code, share_url, referred_count, rewarded_count, …}` | ✓ verified |
| Support ticket list | **fixed in app.** It is a plain list `{id, number, subject, category, status, status_label, updated_at}`. Statuses are `open, pending_customer, pending_staff, resolved, closed`, and `pending_customer` now shows as "Awaiting reply". |
| Support ticket detail messages | **fixed in app.** The real fields are `{id, from: customer|staff, body, created_at}`. Before the fix, staff replies appeared as the customer's own. No `author_name` is sent. |
| `POST /me/support/tickets {category, subject, message, order_id?}` → `{id, number, status}`; categories also include `return` | ✓ verified |
| `POST …/messages {body}` returns the whole ticket, and the app re-fetches it | ✓ verified |
| `GET /pages/{slug}` public → `{slug, title, body, updated_at}`; seeded slugs `about, contact, faq, terms, privacy, returns, auction-terms` | ✓ verified |
| `POST /me/deletion-request {reason?}` → `{id, status, scheduled_for}`; `DELETE` cancels | ✓ verified |
| Addresses CRUD + `POST /me/addresses/{id}/default` | ✓ verified (needs `zone_number` + street + building, or lat/lng, or `delivery_zone_id`) |

## 9. Formatting

| Item | Status |
|---|---|
| `Money.formatted` rendered verbatim; never computed client-side | ✓ verified |
| ISO‑8601 dates with offset (`BusinessTime::iso`), rendered in Asia/Qatar (UTC+03:00) | ✓ verified |
| Branch hours `day_of_week` 0 = Sunday, `HH:mm` | ✓ verified |

## Backend gaps

Each gap gives what the app would use if the backend added it. The app works without it today.

1. **`GET /orders?status=`**: accept several statuses, as `status=a,b` or `status[]=a&status[]=b`, so the app can stop filtering on the device.
2. **`PUT /cart {coupon_code}`**: validate the code and reply `422 {code: coupon_*, errors: {coupon_code: [message]}}`. Alternatively, the cart response could include `coupon_error: {code, message}`.
3. **`GET /me/auctions/bids`**: add the user's own highest bid per lot, e.g. `my_highest_bid: Money` (and optionally `my_last_bid_at`) on each lot row.
4. **Bid rows** (`GET /auctions/lots/{id}/bids`, the bid echo in `POST …/bids`) need `is_mine: bool` so the app does not have to match the localised "You" string. The public `bid.accepted` broadcast also needs an anonymised row `bid: {bid_number, amount: Money, type, bidder, placed_at}` so the app does not have to re-fetch the bid list.
5. **FCM data `notification_id`**: send the in-app `notifications.id`, which `POST /me/notifications/{id}/read` expects, instead of the `notification_logs` id.
6. Optional: **`ProductDetail.fulfillment: {delivery: bool, pickup: bool}`**, **`Fulfillment.delivery.tracking_url`**, home **`sections[].see_all`** (query map) and **promotion `link`**.
