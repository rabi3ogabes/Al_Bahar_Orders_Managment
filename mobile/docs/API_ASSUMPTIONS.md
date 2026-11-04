# API assumptions (mobile ↔ Laravel `/api/v1`)

The app is built strictly against `docs/api/API_CONTRACT.md`. Where the contract is
silent or ambiguous, the client made the choices below. **Every parser is lenient**
(missing optional fields never crash; ints/strings are coerced), so most of these are
"preferred shape" notes, not hard requirements. Items marked **(required)** are ones the
backend must match for the feature to work.

## 1. Environments & transport

| Flavour | `API_BASE_URL` default | Reverb (Pusher protocol) default |
|---|---|---|
| dev | `http://10.0.2.2:8000/api/v1` (Android emulator → host) | `ws://10.0.2.2:8080/app/albahar-dev` |
| staging | `https://staging-api.albahar.qa/api/v1` | `wss://staging-ws.albahar.qa:443/app/albahar-staging` |
| prod | `https://api.albahar.qa/api/v1` | `wss://ws.albahar.qa:443/app/albahar` |

All are overridable with `--dart-define` (see README). Hostnames are placeholders until DNS is final.

- `Accept-Language` is always `ar` or `en`; `X-Request-ID` is a UUID v4 per request (kept on the single retry after a token refresh).
- Query arrays use Laravel syntax `brand_id[]=a&brand_id[]=b`; booleans are sent as `1`/`0`; empty/null params are omitted.
- Non-paginated list endpoints may return either `{data: [...]}` or a paginated envelope — both are accepted.

## 2. Auth & session

- Refresh: `POST /auth/refresh {refresh_token}` → `{data: {tokens}}` (also accepts tokens directly in `data`). A refresh is attempted once per request on any 401 (except on the auth endpoints themselves, which are sent without a bearer token).
- Only a **definitive** refresh rejection (401/403/422) ends the session; a network failure during refresh keeps the tokens so an offline user is not logged out.
- `device_name` is a generic label (`Android device`, `iPhone / iPad`, `Web browser`) — no device model fingerprinting.
- After sign-in, if `user.locale` differs from the language chosen in the app, the app sends `PATCH /me {locale}`.
- `PATCH /me` may send `email: null` to clear the email.
- `PUT /me/password` omits `current_password` when `user.has_password` is false (first-time password).

## 3. Realtime (Laravel Reverb)

- **(required)** Private-channel auth endpoint: `POST {API_BASE_URL}/broadcasting/auth` with the bearer token and JSON `{socket_id, channel_name}` → `{auth: "key:signature"}`. In Laravel: `Broadcast::routes(['prefix' => 'api/v1', 'middleware' => ['auth:sanctum']])`.
- **(required)** Event names are exactly `bid.accepted`, `lot.extended`, `lot.status` (public `auction-lot.{lotId}`) and `auction.outbid`, `auction.won` (private `private-users.{userId}`) — i.e. events use `broadcastAs()`; no `App\Events\…` FQCN and no leading dot.
- Public payload: `{sequence, lot, bid?}` (JSON-encoded string or object). `lot` omits `my_*`; the client keeps the last known `my_*` values and re-fetches `GET /auctions/lots/{id}` when (a) another bidder bids while we were `leading`, (b) a `lot.status` event finishes the lot, or (c) a private `auction.*` event for this lot arrives. Private payloads include `lot_id` (or `lot.id`).
- `GET /auctions/lots/{id}/events?after_sequence=N` returns `[{sequence, type, lot, bid}]` (type = event name), ascending or not (client sorts). Sequences are per-lot, contiguous, starting at 1; `Lot.last_sequence` is the latest applied.
- Client protocol: on first connect → replay `after_sequence = last_sequence`; on reconnect → full `GET` lot + bids, then replay. Duplicates (`sequence <= last`) are dropped; a gap triggers replay; a failed replay triggers a full resync. Countdowns use `server_time` offset only.

## 4. Catalog & home

- Every purchasable product has ≥ 1 variant (simple products expose a single default variant) because `POST /cart/items` requires `variant_id`. A product with no variants is shown as unavailable.
- Optional `ProductDetail.fulfillment: {delivery: bool, pickup: bool}`; when absent both chips are shown and the server decides at checkout.
- Reviews: `{id, rating, title?, body?, author_name | author.name, is_verified_purchase, photos: [{url, thumb_url}], created_at}`. Report body: `{reason: "inappropriate"}`.
- Questions: `{id, question, answer?, created_at}`.
- `POST /me/stock-alerts` is sent with `type: back_in_stock` and the preferred `branch_id`.
- Home banner `link.type` ∈ `category | product | auction | lot | search | collection | products | page | brand | url`. `collection`/`products` values are a query string for `/products` (e.g. `featured=1`). Product sections may carry `see_all: {query map}`; otherwise "See all" is derived from the type (`featured=1`, `new_arrival=1`, `bestseller=1`). Promotions may carry a `link`. Sections with no items are skipped; unknown types are ignored.
- `GET /branches` is requested with `per_page=50`; `/branches/nearby` may be paginated or a plain array.

## 5. Cart & checkout

- **(preferred)** All cart mutations (`POST /cart/items`, `PATCH|DELETE /cart/items/{id}`, `PUT /cart`, `POST /cart/merge`) return the full **Cart** in `data`. If not, the client re-fetches `GET /cart`.
- `PUT /cart` is sent with only the changed keys; `{coupon_code: null}` removes the coupon; an invalid coupon is a 422 with `errors.coupon_code` (or `code: coupon_invalid` + message).
- Cart is user-scoped (requires auth). Guests browse freely and are asked to sign in when adding to cart; `POST /cart/merge` is implemented in the repository but unused (no guest cart token exists in the contract).
- When the preferred branch changes the app sends `PUT /cart {branch_id}` and re-renders with the server response. Existing orders are never touched.
- `Cart.warnings` may be strings or `{message}` objects.
- `CheckoutOptions` is sent with only the keys relevant to the fulfilment type (pickup keys omitted for delivery and vice versa). The client auto-selects the first available payment method and clamps `loyalty_points` to `quote.loyalty.max_redeemable_points`, then re-quotes so the totals shown always match what will be submitted.
- **Idempotency**: one `Idempotency-Key` per *confirmed quote_hash*. Network retries of the same confirmation reuse it; after a `409 quote_changed` the new quote gets a new key (same key + different body would be an `idempotency_conflict`).
- `409 quote_changed` carries the new Quote in `context.quote`; if missing the client re-quotes.
- `POST /orders/{id}/payments` body is `{method?}` (omitted → server default).
- `GET /payments/{id}` may include `order_id` — used when the app is re-opened by the SADAD return URL without in-memory context. Polling: every 3 s, up to 3 min, until `paid|failed|expired|cancelled` (also stops on `refunded/partially_refunded`).

## 6. Orders

- `GET /orders` rows: `{id, number, status, status_label, payment_status, fulfillment_type, placed_at, grand_total | totals.grand_total, items_count?, thumbnails[]? | items[].image_url}`.
- **(preferred)** `?status=` accepts a comma-separated list (tabs: Active = `pending_payment,confirmed,processing,partially_fulfilled,ready_for_pickup,out_for_delivery,cancellation_requested`; Completed = `completed`; Cancelled = `cancelled,partially_cancelled,returned`).
- Cancel `reason_code` ∈ `changed_mind, ordered_by_mistake, delivery_too_long, found_better_price, other`.
- Return `reason_code` ∈ `damaged, wrong_item, not_as_described, changed_mind, other`.
- `POST /orders/{id}/reorder` → `{cart: Cart, skipped: [...]}` (Cart fields at the top level are also accepted).
- `Fulfillment.delivery.tracking_url` (optional) opens carrier tracking in an in-app browser.
- Pickup pass is fetched only when a fulfilment is `pickup_ready`/`ready`; the QR payload is never logged.
- Invoice: `GET /orders/{id}/invoice` returns PDF bytes (bearer auth); the app shares/saves it via the OS share sheet.

## 7. Auctions

- `AuctionCard`: `{id, title, description?, image_url, status: upcoming|live|completed, starts_at, ends_at, lots_count, type?, server_time?}`.
- `Auction` adds `{lots: [Lot], terms: HTML string | {body}, requires_registration (default true), deposit_required: Money?, my_registration: {status, paddle_number, deposit_required, deposit_payment}}`. `status` ∈ `registered | approved | active` (can bid), `pending | pending_deposit | rejected`.
- `Lot` may include `auction_id`. Bid entries: `{bid_number, amount, bidder ("Bidder 12" | "You"), placed_at, is_mine?}`.
- Bid errors: 422 with `errors.amount` (e.g. `bid_too_low`), 409 for lot-state conflicts — the app re-fetches the lot after either.
- `POST /auctions/lots/{id}/auto-bid` / `DELETE` and `watch` / `DELETE watch`: response bodies are ignored; the lot is re-fetched.
- `GET /me/auctions/bids` → `[{id, amount, placed_at, is_auto, lot: Lot}]`; `GET /me/auctions/watchlist` → `[Lot]` or `[{lot: Lot}]`; `GET /me/auctions/wins` → `[{id, lot, amount | winning_amount, status (pending_checkout|paid|…), checkout_deadline | pay_by, order_id?}]`.
- Win checkout also sends an `Idempotency-Key` (not required by the contract; harmless).

## 8. Account, notifications, support

- `PUT /me/notification-preferences` body: `{preferences: [{channel, category, is_enabled}]}`; response is the updated list (if empty, the client keeps its optimistic state).
- Push-channel preferences are cached on device so **foreground** banners for disabled categories are suppressed. Category is derived from the event prefix: `marketing.|promotion.|campaign.` → marketing, `auction.|lot.` → auction, otherwise transactional. Background/terminated delivery is filtered server-side.
- `POST /me/devices {platform, push_token, device_id, app_version}`: `push_token` is the identifier delivered by `FirebaseMessaging.onRegistered` (firebase_messaging 17 replaced `getToken()`); `device_id` is a random per-install UUID; `app_version` is `version+build`. `DELETE /me/devices` sends `{push_token}` as a JSON body and is called **before** `POST /auth/logout`.
- FCM `data` payload: `{event, deep_link, notification_id}`; fallbacks when `deep_link` is missing: `order_id`, `lot_id`, `auction_id`, `ticket_id`, `product_id`. Opening a notification calls `POST /me/notifications/{id}/read`.
- `GET /me/notifications` exposes `meta.unread_count`; the badge requests `per_page=1` to read it.
- Loyalty `tier` may be a string or `{name}`. Referral `share_url` falls back to `https://albahar.qa`.
- Support tickets: `{id, number?, subject, status: open|pending|awaiting_customer|resolved|closed, category, order_id?, created_at, updated_at|last_message_at, messages: [{id, body, author_type: customer|staff, author_name?, created_at}]}`. `category` ∈ `order, payment, delivery, product, auction, account, other`. `POST …/messages` returns the created message (otherwise the thread is re-fetched).
- `GET /pages/{slug}` is public (sent without bearer); slugs used: `about, contact, faq, terms, privacy, returns, auction-terms`.
- `POST /me/deletion-request {reason?}` → `{id, status, scheduled_for}`; `DELETE` cancels.

## 9. Formatting

- Money is never computed client-side; `Money.formatted` is rendered verbatim. The bid counter animation interpolates digits only *during* the 650 ms animation and then shows the server string.
- Dates are ISO‑8601 with offset; the app renders them in Asia/Qatar using a fixed UTC+03:00 offset (Qatar has no DST), independent of the device time zone.
- Branch hours `day_of_week` uses 0 = Sunday; `opens_at` etc. are `HH:mm` in Asia/Qatar.
