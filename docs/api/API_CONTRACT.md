# Al Bahar Home & Living — REST API v1 Contract

Base URL: `https://{host}/api/v1` · JSON only · UTF-8 · Times are ISO‑8601 with offset in **Asia/Qatar** (`2026-11-04T18:30:00+03:00`) · Money is always an object (never a float):

```json
{ "amount": "1250.50", "currency": "QAR", "formatted": "QAR 1,250.50" }
```
(`formatted` is localized: Arabic → `"1,250.50 ر.ق"`.)

The machine-readable spec is served at `/api/v1/openapi.json` (generated) and committed at `docs/api/openapi.yaml`.

## 1. Conventions

### Headers
| Header | Purpose |
|---|---|
| `Authorization: Bearer <access_token>` | Authenticated calls |
| `Accept-Language: ar` / `en` (or `X-Locale`, or `?lang=`) | Response language (messages, product names…) |
| `Idempotency-Key: <uuid>` | **Required** on `POST /checkout/orders`, `POST /orders/{id}/payments`, `POST /auctions/lots/{id}/bids` |
| `X-Request-ID` | Optional; echoed back. Always present on responses for support tickets |
| `X-Branch-ID` | *Not* an authorization mechanism — preferred branch is a normal query/body parameter |

### Success envelope
Single resource: `{ "data": { ... } }`
Collections (paginated):
```json
{
  "data": [ ... ],
  "links": { "first": "...", "last": null, "prev": null, "next": "..." },
  "meta": { "current_page": 1, "per_page": 20, "total": 134, "last_page": 7 }
}
```
Query: `page`, `per_page` (max 100), `sort` (e.g. `-created_at`, `price`), `filter[...]`.

### Error envelope (all 4xx/5xx)
```json
{ "message": "Some details need your attention.", "code": "validation_failed",
  "errors": { "phone": ["Please enter a valid Qatar mobile number."] },
  "context": { },           // optional, code-specific data (e.g. seconds for cooldown)
  "request_id": "a3b1…" }
```
| HTTP | `code` examples | Client behaviour |
|---|---|---|
| 401 | `unauthenticated`, `refresh_invalid`, `refresh_expired` | Try `POST /auth/refresh` once; on failure → sign-in |
| 403 | `forbidden`, `account_inactive` | Show message |
| 404 | `not_found` | Empty/not-found state |
| 409 | `phone_taken`, `quote_changed`, `idempotency_conflict` | Re-fetch, show changes |
| 422 | `validation_failed`, `insufficient_stock`, `otp_invalid`, `otp_expired`, `coupon_invalid`… | Inline field / banner |
| 429 | `too_many_requests`, `otp_cooldown`, `otp_attempts_exceeded` | Honour `Retry-After` / `context.seconds` |
| 5xx | `server_error` | Retry with backoff; show friendly error |

## 2. Authentication

| Method | Path | Body | Response `data` |
|---|---|---|---|
| POST | `/auth/otp/request` | `{phone, purpose?: "login"\|"password_reset"}` | `{sent, expires_in, resend_in, debug_code?}` (`debug_code` only in local dev) |
| POST | `/auth/otp/verify` | `{phone, code, device_name?, platform?: android\|ios\|web}` | `{status:"authenticated", user, tokens}` **or** `{status:"registration_required", registration_token, expires_in}` |
| POST | `/auth/register` | `{registration_token, name, email?, password?, password_confirmation?, locale?, referral_code?, marketing_consent?, device_name?, platform?}` | 201 `{status, user, tokens}` |
| POST | `/auth/login` | `{phone\|email, password, device_name?, platform?}` | `{status, user, tokens}` |
| POST | `/auth/password/reset` | `{phone, code, password, password_confirmation}` (code from `otp/request` with `purpose=password_reset`) | `{reset:true}` |
| POST | `/auth/refresh` | `{refresh_token}` | `{tokens}` — **rotates** the refresh token; reuse of an old one revokes all sessions of that login |
| POST | `/auth/logout` · `/auth/logout-all` | — | `{logged_out:true}` |

`tokens` = `{access_token, refresh_token, token_type:"Bearer", expires_in (seconds), session_id}`.
Phone input accepts `55123456`, `+974 5512 3456`, Arabic digits; the server normalises to E.164 `+97455123456`.

`user` (UserResource):
```json
{ "id": "uuid", "type": "customer", "name": "Noor", "phone": "+97455123456", "email": null,
  "phone_verified": true, "locale": "ar", "avatar_url": null, "date_of_birth": null, "gender": null,
  "referral_code": "ABX7K2QP", "marketing_consent": false, "has_password": false, "created_at": "…" }
```

## 3. Account (`auth:customer`)
| Method | Path | Notes |
|---|---|---|
| GET/PATCH | `/me` | PATCH `{name?, email?, locale?, date_of_birth?, gender?: male\|female, marketing_consent?}` |
| PUT | `/me/password` | `{current_password?, password, password_confirmation}` |
| POST/DELETE | `/me/devices` | `{platform, push_token, device_id?, app_version?}` / `{push_token}` |
| GET | `/me/sessions` · DELETE `/me/sessions/{id}` | |
| POST/DELETE | `/me/deletion-request` | `{reason?}` → `{id, status, scheduled_for}` (14-day grace, then anonymised) |
| GET/POST | `/me/addresses` · GET/PATCH/DELETE `/me/addresses/{id}` · POST `/me/addresses/{id}/default` | Address below |
| GET/PUT | `/me/notification-preferences` | `[{channel: push\|sms\|whatsapp\|email, category: transactional\|marketing\|auction, is_enabled}]` |
| GET | `/me/loyalty` | `{balance, tier, value: Money, lifetime_earned, transactions:[{type, points, balance_after, reason, created_at, expires_at}]}` |
| GET | `/me/referral` | `{code, share_url, referred_count, rewarded_count}` |

Address:
```json
{ "id":"uuid","label":"Home","recipient_name":"Noor","recipient_phone":"+97455123456",
  "delivery_zone": {"id":"uuid","name":"West Bay"}, "zone_number":"66","street_number":"850",
  "building_number":"12","unit_number":"4","area":"West Bay","city":"Doha","landmark":"Near …",
  "instructions":"Call on arrival","latitude":25.32,"longitude":51.53,"is_default":true }
```
Create/update body: same fields (`delivery_zone_id` instead of the object). `recipient_name`, `recipient_phone`, and either (`zone_number`+`street_number`+`building_number`) or (`latitude`+`longitude`) are required.

## 4. Branches
| GET | Path | Response |
|---|---|---|
| | `/branches?search=&pickup=1&delivery=1` | Branch[] (paginated) |
| | `/branches/nearby?lat=&lng=&radius_km=30` | Branch[] with `distance_km`, sorted ascending (only when the customer consents to location) |
| | `/branches/{id}` | Branch |
| | `/branches/{id}/hours` | `{timezone, weekly:[{day_of_week (0=Sun), is_closed, opens_at "09:00", closes_at "22:00", break_starts_at, break_ends_at}], holidays:[{name, type, starts_at, ends_at}]}` |
| | `/branches/{id}/pickup-slots?date=YYYY-MM-DD` | `[{id, date, starts_at "16:00", ends_at "18:00", available: 3, is_full}]` |
| | `/delivery-zones` | `[{id, name, zone_numbers[]}]` |

Branch:
```json
{ "id":"uuid","code":"WB","name":"West Bay Showroom","description":"…","phone":"+97444…","email":"…",
  "address":"…","area":"West Bay","latitude":25.32,"longitude":51.53,
  "pickup_enabled":true,"delivery_enabled":true,"cod_enabled":true,"pay_at_pickup_enabled":true,
  "opening": {"is_open":true,"reason":"open","closes_at":"…","next_open_at":null},
  "distance_km": 2.4 }
```

## 5. Catalog
| GET | Path | Notes |
|---|---|---|
| | `/home?branch_id=` | Ordered, admin-managed sections (see §10) |
| | `/categories` | Tree: `[{id, slug, name, image_url, icon_url, banner_url, children:[…]}]` |
| | `/categories/{id}` | Category + `filters: [{attribute:{code,name,type}, values:[{id, code, name, color_hex}]}]` |
| | `/brands` | `[{id, slug, name, logo_url}]` |
| | `/products` | Filters: `branch_id`, `category_id` (includes descendants), `brand_id[]`, `attribute_values[]` (ids), `price_min`, `price_max`, `in_stock=1`, `on_sale=1`, `featured=1`, `new_arrival=1`, `bestseller=1`, `q`. Sort: `relevance`, `price`, `-price`, `-created_at`, `-rating`, `-popularity` |
| | `/products/search?q=` | Same as `/products` with `q` required (Arabic/English, SKU, barcode, partial) |
| | `/products/{id or slug}?branch_id=` | ProductDetail |
| | `/products/{id}/availability?branch_id=` | `{branches:[{branch:{id,name}, variants:[{variant_id, available, in_stock, price: EffectivePrice}]}]}` — `available` is capped at 10 for display |
| | `/branches/{id}/products` | Products enabled at that branch |
| | `/products/{id}/reviews` · `/products/{id}/questions` | Paginated |

ProductCard (lists):
```json
{ "id":"uuid","slug":"oslo-3-seater-sofa","sku":"SOF-001","name":"Oslo 3-Seater Sofa",
  "brand":{"id":"uuid","name":"Nordic Living"},"image":{"url":"…","thumb_url":"…","alt":"…"},
  "price": EffectivePrice, "rating": {"average": 4.6, "count": 18},
  "badges":["new","sale","bestseller"], "in_stock": true, "has_variants": true }
```
EffectivePrice (always computed server-side for the requested/preferred branch):
```json
{ "regular": Money, "sale": Money|null, "final": Money, "discount_percent": 15,
  "sale_ends_at": "…"|null, "source": "variant_branch|product_branch|variant|product" }
```
ProductDetail = ProductCard + `{ short_description, description (HTML), category:{id,name,slug}, tags[],
specifications:[{label, value}], dimensions:{length,width,height,unit}, weight:{value,unit},
warranty:{duration_months, provider, terms}|null, requires_assembly, assembly_service_available, is_oversized,
images:[{url, thumb_url, alt, variant_id}], videos:[{url, source, title}],
attributes:[{code, name, type, values:[{id, code, name, color_hex}]}],
variants:[{id, sku, name, attribute_value_ids:[…], price: EffectivePrice, available_in_branch: bool, in_stock: bool, image_url}],
default_variant_id, availability:{branch_id, purchasable: bool, reason: null|"not_in_branch"|"out_of_stock"},
related:[ProductCard], share_url, seo:{title, description} }`.

## 6. Wishlist, reviews, alerts
| Method | Path | Body |
|---|---|---|
| GET | `/me/wishlist` | → `[{id, product: ProductCard, variant_id, added_at}]` |
| POST | `/me/wishlist` | `{product_id, variant_id?}` |
| DELETE | `/me/wishlist/{product_id}` | |
| POST | `/products/{id}/reviews` | `{rating 1-5, title?, body?}` (multipart `photos[]` ≤ 4) — verified-purchase flag set server-side; reviews are moderated |
| POST | `/reviews/{id}/report` | `{reason}` |
| POST | `/products/{id}/questions` | `{question}` |
| POST/DELETE | `/me/stock-alerts` | `{variant_id, type: back_in_stock\|price_drop, branch_id?}` |

## 7. Cart & checkout (`auth:customer`)
The cart is server-side and persistent. **All prices, stock, discounts and fees are computed by the server**; the client only sends identifiers and quantities.

| Method | Path | Body |
|---|---|---|
| GET | `/cart?branch_id=` | Cart |
| POST | `/cart/items` | `{variant_id, quantity}` (adds/merges) |
| PATCH | `/cart/items/{id}` | `{quantity}` |
| DELETE | `/cart/items/{id}` | |
| PUT | `/cart` | `{branch_id?, fulfillment_type?: delivery\|pickup, address_id?, coupon_code?\|null, loyalty_points?}` |
| POST | `/cart/merge` | `{guest_token}` (after login) |
| POST | `/checkout/quote` | CheckoutOptions → Quote |
| POST | `/checkout/orders` | CheckoutOptions + `{quote_hash, customer_notes?}` + header `Idempotency-Key` → 201 `{order: Order, payment: PaymentAction}` |

Cart:
```json
{ "id":"uuid","branch_id":"uuid","fulfillment_type":"delivery","address_id":"uuid","coupon_code":null,
  "items":[{"id":"uuid","variant_id":"uuid","product":ProductCard,"variant_name":"Grey / Linen",
            "quantity":2,"unit_price":EffectivePrice,"line_total":Money,
            "available":true,"available_quantity":8,"price_changed":false,"previous_price":null,
            "issue": null|"unavailable_in_branch"|"insufficient_stock"|"inactive"}],
  "items_count":2, "subtotal":Money, "warnings":["Price of X changed from … to …"] }
```
CheckoutOptions:
```json
{ "fulfillment_type":"delivery|pickup", "address_id":"uuid (delivery)", "branch_id":"uuid (pickup branch / preferred)",
  "pickup_slot_id":"uuid?", "pickup_date":"YYYY-MM-DD?", "delivery_slot_id":"uuid?", "delivery_date":"…?",
  "payment_method":"sadad|cod|pay_at_pickup", "coupon_code":"?", "loyalty_points":0,
  "allow_split":true, "express":false, "latitude":null, "longitude":null }
```
Quote:
```json
{ "quote_hash":"sha256…", "currency":"QAR",
  "fulfillments":[{"branch":{"id","name"}, "type":"delivery", "items":[{"variant_id","name","quantity","unit_price":EffectivePrice,"line_total":Money}],
                   "subtotal":Money, "delivery_fee":Money, "eta_minutes":120, "slot":{…}|null}],
  "is_split": false,
  "lines":[{"variant_id","name","variant_name","image_url","quantity","unit_price":EffectivePrice,"line_subtotal":Money,"discount":Money,"line_total":Money,"branch_id"}],
  "totals":{"subtotal":Money,"discount":Money,"delivery_fee":Money,"service_fee":Money,"cod_fee":Money,"tax":Money,"loyalty_discount":Money,"grand_total":Money},
  "discounts":[{"label":"Welcome 10%","code":"WELCOME10","amount":Money}],
  "payment_methods":[{"code":"sadad","available":true},{"code":"cod","available":false,"reason":"exceeds_cod_limit"}],
  "loyalty":{"balance":1200,"max_redeemable_points":600,"points_to_earn":350},
  "warnings":[{"code":"price_changed","message":"…"}], "errors":[{"code":"insufficient_stock","variant_id":"…","message":"…"}],
  "can_place_order": true, "expires_at":"…" }
```
Placing an order re-validates everything. If totals differ from `quote_hash` the server answers **409 `quote_changed`** with `context.quote` (the new Quote) — the app must show the new totals and ask the customer to confirm again. Retrying with the same `Idempotency-Key` returns the original order (no duplicate).

PaymentAction (returned with new orders and by `POST /orders/{id}/payments`):
```json
{ "payment_id":"uuid","method":"sadad","status":"pending",
  "action": "redirect|none", "redirect_url":"https://…/payments/sadad/{id}/start?signature=…",
  "return_url_scheme":"albahar://payments/{payment_id}/return", "expires_at":"…" }
```
For SADAD the app opens `redirect_url` in a secure in-app browser (Custom Tabs / SFSafariViewController). When the browser returns to `albahar://payments/{id}/return` the app **must poll** `GET /payments/{id}` until `status` ∈ `paid|failed|expired|cancelled` — the redirect itself never proves payment.

## 8. Orders (`auth:customer`)
| Method | Path | Notes |
|---|---|---|
| GET | `/orders?status=` | OrderSummary[] |
| GET | `/orders/{id}` | Order |
| GET | `/orders/{id}/timeline` | `[{kind: order\|fulfillment\|payment\|delivery, status, label, at, fulfillment_id?}]` |
| POST | `/orders/{id}/cancel` | `{reason_code, reason?}` → `{status: approved\|requested}` |
| POST | `/orders/{id}/reorder` | Adds still-available items to cart → Cart + `skipped:[…]` |
| GET | `/orders/{id}/invoice` | `application/pdf` |
| POST | `/orders/{id}/payments` | Retry online payment (Idempotency-Key) → PaymentAction |
| GET | `/payments/{id}` | `{id, status, method, amount: Money, paid_at, failure_reason}` |
| GET | `/orders/{id}/fulfillments/{fid}/pickup-pass` | `{qr_payload, expires_at, branch, status}` (only when `pickup_ready`) |
| POST | `/orders/{id}/returns` | `{items:[{order_item_id, quantity}], reason_code, reason?}` |

Order:
```json
{ "id":"uuid","number":"AB100123","status":"processing","status_label":"Processing",
  "payment_status":"paid","payment_method":"sadad","fulfillment_type":"delivery",
  "placed_at":"…","totals":{…same keys as quote…},"address":{…snapshot…},
  "items":[{"id","product_id","variant_id","sku","name","variant_name","image_url","quantity","unit_price":Money,"line_total":Money,"branch_id","fulfillment_id"}],
  "fulfillments":[{"id","number":"AB100123-1","branch":{"id","name","phone","latitude","longitude"},"type":"pickup","status":"pickup_ready","status_label":"Ready for pickup",
                   "slot":{"date","starts_at","ends_at"}|null,"delivery":{"tracking_number","status","provider","driver_name","driver_phone","eta"}|null,
                   "pickup":{"status","token_expires_at"}|null}],
  "can_cancel": false, "can_pay": false, "can_return": true, "can_review": true }
```
Order statuses: `pending_payment, confirmed, processing, partially_fulfilled, ready_for_pickup, out_for_delivery, completed, cancellation_requested, cancelled, partially_cancelled, returned`.
Fulfillment statuses: `pending, accepted, preparing, ready, dispatched, out_for_delivery, delivered, pickup_ready, picked_up, failed, cancelled, returned`.
Payment statuses: `pending, processing, authorized, paid, failed, cancelled, expired, partially_refunded, refunded`.

## 9. Auctions
| Method | Path | Notes |
|---|---|---|
| GET | `/auctions?status=upcoming\|live\|completed` | AuctionCard[] |
| GET | `/auctions/{id}` | Auction (incl. lots[], terms, my_registration) |
| GET | `/auctions/lots/{lotId}` | Lot (authoritative state + `server_time`) |
| GET | `/auctions/lots/{lotId}/bids` | Public history: `[{bid_number, amount: Money, bidder: "Bidder 12" \| "You", placed_at}]` |
| GET | `/auctions/lots/{lotId}/events?after_sequence=` | Event replay after reconnect |
| POST | `/auctions/{id}/register` | `{accept_terms:true}` → `{status, paddle_number, deposit_required: Money\|null, deposit_payment: PaymentAction\|null}` |
| POST | `/auctions/lots/{lotId}/bids` | `{amount}` + `Idempotency-Key` → `{bid:{…}, lot: Lot}` |
| POST/DELETE | `/auctions/lots/{lotId}/auto-bid` | `{max_amount}` |
| POST/DELETE | `/auctions/lots/{lotId}/watch` | Watchlist |
| GET | `/me/auctions/bids` · `/me/auctions/watchlist` · `/me/auctions/wins` | |
| POST | `/me/auctions/wins/{winnerId}/checkout` | `{fulfillment_type, address_id?, branch_id?, payment_method}` → `{order, payment}` |

Lot:
```json
{ "id":"uuid","lot_number":3,"title":"…","description":"…","image_url":"…","quantity":1,
  "status":"open","starting_price":Money,"current_price":Money|null,"min_next_bid":Money,"min_increment":Money,
  "buy_now_price":Money|null,"reserve_met":true|false|null,"bid_count":14,"last_sequence":57,
  "starts_at":"…","ends_at":"…","extension_count":1,"server_time":"…",
  "my_status": null|"leading"|"outbid"|"won"|"lost", "my_max_auto_bid": Money|null, "is_watching": false }
```
Real-time: Pusher protocol (Laravel Reverb) — public channel `auction-lot.{lotId}`, events `bid.accepted`, `lot.extended`, `lot.status` with payload `{sequence, lot: Lot (without my_*), bid?}`; private channel `private-users.{userId}` event `auction.outbid`/`auction.won`. **Clients must treat the REST lot state as authoritative**: on (re)connect, fetch `/auctions/lots/{id}` and replay `/events?after_sequence=` — never trust a local countdown.

## 10. Home, CMS, notifications, support
`GET /home?branch_id=` →
```json
{ "sections":[
  {"id":"uuid","type":"banners","layout":"hero","title":null,"items":[{"id","title","subtitle","image_url","link":{"type":"category","value":"uuid"}}]},
  {"type":"categories","layout":"chips","title":"Shop by room","items":[Category]},
  {"type":"featured_products|new_arrivals|best_sellers|recommended|product_collection","items":[ProductCard]},
  {"type":"brands","items":[Brand]},
  {"type":"promotions","items":[{"id","name","description","badge":"-20%","ends_at"}]},
  {"type":"upcoming_auctions|live_auctions","items":[AuctionCard]} ] }
```
Section order and content are entirely server-driven; unknown `type`s must be ignored by the client.

| Method | Path | Notes |
|---|---|---|
| GET | `/pages/{slug}` | `about, contact, faq, terms, privacy, returns, auction-terms` → `{slug, title, body (HTML), updated_at}` |
| GET | `/me/notifications` | `[{id, event, title, body, data:{deep_link}, read_at, created_at}]` + `meta.unread_count` |
| POST | `/me/notifications/{id}/read` · `/me/notifications/read-all` | |
| GET/POST | `/me/support/tickets` | `{category, subject, message, order_id?}` |
| GET | `/me/support/tickets/{id}` | Ticket + messages (internal notes never included) |
| POST | `/me/support/tickets/{id}/messages` | `{body}` |

Deep links: `albahar://products/{id}`, `albahar://orders/{id}`, `albahar://auctions/{id}`, `albahar://auctions/lots/{lotId}`, `albahar://categories/{id}`, `albahar://payments/{id}/return`, `albahar://support/{ticketId}`. HTTPS universal-link equivalents: `https://albahar.qa/app/...`.

Push payload (FCM `data`): `{ "event": "order.ready_for_pickup", "deep_link": "albahar://orders/…", "notification_id": "uuid" }`.
