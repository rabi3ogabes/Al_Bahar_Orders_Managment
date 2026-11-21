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
| POST/DELETE | `/me/devices` | `{platform, push_token, device_id?, app_version?, notification_permission?: granted\|denied\|not_asked}` / `{push_token}`. The app is read from the `X-App` header (`customer`/`driver`/`staff`). The same for the staff apps at `/admin/devices`. `notification_permission` is what the phone says about showing notifications (Android 13+ hides every push until the person allows it): it is stored with the token and the time it was said, and the dashboard tells the team when a push would not show. Optional: an older app that leaves it out (or sends `null`) keeps whatever was stored, never turned into "off". Any other value is refused with the usual `422` (`errors.notification_permission`) and nothing is stored, like any other unknown value of this endpoint. The response is unchanged: `201 {data:{id, registered}}`. See [DEVICE_NOTIFICATION_STATE.md](DEVICE_NOTIFICATION_STATE.md) |
| POST | `/push-diagnostics` (public, no token) | A phone app says why push does not work on it, or that it does: `{app: customer\|driver\|staff, platform: android\|ios, stage: options\|init\|permission\|token\|register\|registered, code (lowercase machine code), message? (≤200), app_version?, os_version?, install_id}` → `202 {data:{accepted:true}}`. No person, token, key or address is stored. See [FIREBASE_PUSH.md](../guides/FIREBASE_PUSH.md) |
| socket | `private-users.{id}` · `notification.created` | A new inbox entry: `{notification_id, event, category, title, body, deep_link, unread_count, created_at}`; the apps move the badge and read the list again |
| POST | `/me/live-activities` · `/me/live-activities/start-token` · DELETE `/me/live-activities` | iPhone Live Activity tokens (the order on the lock screen / Dynamic Island): `{order_id, push_token, environment?: production\|sandbox, device_id?, locale?, app_version?}` (own order only, else 404) / `{push_token, ...}` / `{push_token? \| order_id? \| device_id?}`. See [IOS_LIVE_ACTIVITY.md](../guides/IOS_LIVE_ACTIVITY.md) |
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
| | `/branches/{id}/pickup-slots?date=YYYY-MM-DD` | `[{id, date, starts_at "16:00", ends_at "18:00", capacity, available: 3, is_full}]` |
| | `/branches/{id}/delivery-slots?date=YYYY-MM-DD` | same shape; 404 when the branch does not deliver |
| | `/branches/{id}/{pickup,delivery}-slots?from=YYYY-MM-DD&to=YYYY-MM-DD[&only_available=1]` | **a span in one call**: `{data:[{date, available, slots:[slot…]}], meta:{from,to}}`, one entry per date that has slots; `only_available=1` drops dates with no free place. Dates run today … +29 days, span ≤ 30 days, `date` and `from/to` can't be combined (422) |
| | `/delivery-zones` | `[{id, name, zone_numbers[]}]` |
| | `/map-config` | `{provider, tiles{light_url,dark_url,attribution,attribution_text,subdomains,max_zoom,retina}, center{lat,lng}, zoom, stale_after_seconds}`: the map provider chosen in Settings; cacheable 5 min (see [MAPS.md](../guides/MAPS.md)) |
| | `/app-config` | `{designed_by{en,ar}, intro{slides[3]{title{en,ar}, body{en,ar}}}, returns_enabled, alternate_phone_enabled, ops{status_style}}`: the "Designed by" lines of the customer app intro (Settings → General & brand; an empty string means hide), the admin-written words of the three intro slides (`intro.slides`, a text or `null` = use the app's own words; see [INTRO_SCREENS.md](INTRO_SCREENS.md)), whether customers may return orders (Settings → Checkout & fees) and whether the alternative phone number is asked for (Settings → General & brand, default on; see `HOME_BANNERS_AND_ALT_PHONE.md`); public, cacheable 1 min |

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
| | `/home?branch_id=&platform=` (alias `/homepage`) | Ordered, admin-managed sections (see §10); `platform=web` for the web store, apps send nothing |
| | `/categories` | Tree: `[{id, slug, name, image_url, icon_url, banner_url, children:[…]}]` |
| | `/categories/{id}` | Category + `filters: [{attribute:{code,name,type}, values:[{id, code, name, color_hex}]}]` |
| | `/brands` | `[{id, slug, name, logo_url}]` |
| | `/products` | Filters: `branch_id`, `category_id` (includes descendants), `brand_id[]`, `attribute_values[]` (ids), `price_min`, `price_max`, `in_stock=1`, `on_sale=1`, `featured=1`, `new_arrival=1`, `bestseller=1`, `room={slug}` (see §5b), `q`. Sort: `relevance`, `price`, `-price`, `-created_at`, `-rating`, `-popularity` |
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
  "badges":["new","sale","bestseller"], "in_stock": true, "has_variants": true,
  "extra_delivery_fee": "50.00", "extra_delivery_fee_mode": "per_unit" }
```
`extra_delivery_fee` (string money, `"0.00"` = none) and `extra_delivery_fee_mode` (`per_unit` = charged for every unit, `per_line` = charged once whatever the
quantity) are on every product card and on the detail. Clients show a small note on the product page when the fee is above zero ("Extra delivery fee QAR 50.00
per item" / "… for this item"). Delivery orders only; pickup never pays it. See [CHECKOUT_FEES_V2.md](CHECKOUT_FEES_V2.md).
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

## 5b. Shop by room
Guide: [ALERTS_AFTER_DELIVERY_AND_ROOMS.md](../guides/ALERTS_AFTER_DELIVERY_AND_ROOMS.md). Rooms are managed in the dashboard (Content > Shop by room). A product is in a room when it is in one of the room's categories (sub-categories included) or carries one of the room's tags (case-insensitive).

| Method | Path | Notes |
|---|---|---|
| GET | `/rooms?branch_id=` (public, `Cache-Control: public, max-age=60`, `Vary: Accept-Language`) | `data: [Room]` in the owner's order. A room with no product the shopper can see at that branch (any branch when none is given) is **left out**, so every room returned has a page with products. |
| GET | `/products?room={slug}` | The normal product list restricted to that room (combine with every other filter and sort). An unknown or switched-off slug returns an empty list, not an error. |

```json
Room = { "id": "uuid", "slug": "bedroom", "name": "Bedroom", "description": "Sheets, duvets…"|null,
  "image_url": "…"|null,   // 1000 px WebP; a room without its own picture borrows its first category's artwork
  "thumb_url": "…"|null,   // 480 px WebP of the room's own picture; null when the picture is borrowed (use image_url)
  "products_count": 34,
  "link": { "type": "room", "value": "bedroom" } }
```
`name` and `description` follow `Accept-Language` (`en`/`ar`). The home feed carries the same tiles as a section (see §10, `rooms`). Clients open a room as the shop filtered by `room=slug`; web address `/shop?room={slug}`.

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

## 6b. Back-in-stock and price-drop alerts
Guide: [ALERTS_AFTER_DELIVERY_AND_ROOMS.md](../guides/ALERTS_AFTER_DELIVERY_AND_ROOMS.md). A customer asks to be told when a variant is back in stock (`back_in_stock`: one message, then the alert is done) or when its price falls (`price_drop`: keeps watching, tells again only for a further fall). The owner's switches decide what exists: back-in-stock is on, price alerts are off until switched on.

| Method | Path | Body / notes |
|---|---|---|
| GET | `/stock-alerts/config` (public, 60 s) | `{back_in_stock: bool, price_drop: bool, price_drop_percent: int, price_drop_min_amount: "5.00"}`. Show the price choice only when `price_drop` is true. |
| GET | `/me/stock-alerts` | `data: [Alert]` newest first (max 100) + `meta: {count, limit, back_in_stock, price_drop}`. Never cached. |
| POST | `/me/stock-alerts` | `{variant_id, type: back_in_stock\|price_drop, branch_id?}` → **201** `{subscribed: true, alert: Alert}` when new, **200** with the same alert when the customer already had it (nothing changes: it keeps its date and its starting price). An alert that fired or expired and is asked again starts over (201). 30/min. |
| DELETE | `/me/stock-alerts` | `{variant_id, type}` → `{subscribed: false}` (the product page toggle; cancelling what is not there is fine). |
| DELETE | `/me/stock-alerts/{id}` | → `{subscribed: false}`. Idempotent; another customer's id changes nothing and answers the same. |

```json
Alert = { "id": "uuid", "type": "back_in_stock|price_drop",
  "product": { "id": "uuid", "slug": "oslo-sofa", "name": "Oslo Sofa", "image_url": "…"|null, "thumb_url": "…"|null },
  "variant": { "id": "uuid", "name": "Grey"|null }, "branch_id": "uuid"|null,
  "reference_price": Money|null,   // price alert: the price the customer last saw (moves to the price they were told about)
  "current_price": Money|null,     // what they would pay now (sale and a running deal included); null = cannot be bought at that branch
  "in_stock": true, "since": "2026-11-19T12:00:00+03:00", "expires_at": "2027-02-17T12:00:00+03:00" }
```
Errors: 422 `alerts_disabled` (that kind of alert is switched off), 422 `already_available` (a back-in-stock alert for something that can be bought now at that branch), 422 `too_many_alerts` (100 active alerts), 404 `product_not_found` (inactive product or variant), 422 `validation_failed`. The message that arrives is the catalogue event `stock.back_in_stock` or `stock.price_drop` with `data.deep_link = albahar://products/{id}`.

When a message is sent: back in stock, once, when the variant can be bought at the alert's branch (any branch when none was named) and it is still true when the job looks; price drop, when the price now is lower than `reference_price` by at least `price_drop_percent` **and** `price_drop_min_amount`, the product is in stock, and the customer got fewer than the weekly cap; never in the quiet hours (Settings > Notifications); a price drop goes only to customers who agreed to marketing messages.

## 7. Cart & checkout (`auth:customer`)
The cart is server-side and persistent. **All prices, stock, discounts and fees are computed by the server**; the client only sends identifiers and quantities.

| Method | Path | Body |
|---|---|---|
| GET | `/cart?branch_id=` | Cart |
| POST | `/cart/items` | `{variant_id, quantity, assembly?: bool}` (adds/merges; `assembly` also adds assembly and installation, see 7b) |
| PATCH | `/cart/items/{id}` | `{quantity?, assembly?: bool}` (send at least one; `assembly` alone switches assembly on or off for the line, see 7b) |
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
            "issue": null|"unavailable_in_branch"|"insufficient_stock"|"inactive",
            "assembly": null|{"selected":true,"fee_each":Money,"amount":Money|null}}],
  "items_count":2, "subtotal":Money, "assembly_total":Money, "warnings":["Price of X changed from … to …"] }
```
CheckoutOptions:
```json
{ "fulfillment_type":"delivery|pickup", "address_id":"uuid (delivery)", "branch_id":"uuid (pickup branch / preferred)",
  "pickup_slot_id":"uuid?", "pickup_date":"YYYY-MM-DD?", "delivery_slot_id":"uuid?", "delivery_date":"YYYY-MM-DD?",
  "payment_method":"sadad|cod|pay_at_pickup", "coupon_code":"?", "loyalty_points":0,
  "allow_split":true, "express":false, "latitude":null, "longitude":null,
  "alternate_phone":"+97455987654|null", "no_alternate_phone":false }
```
`allow_split` is honoured only while the admin setting `checkout.show_split_shipment` is on; with it off the server never splits and ignores
the field. The quote answers `split_choice` (bool): true only when that setting is on AND more than one active branch holds part of the
cart, so clients show the "allow split shipments" switch only then. `alternate_phone` / `no_alternate_phone` (delivery only; pickup ignores
them) carry the customer's second number for the driver: send one of the two once the customer answered; omit both for older clients (the
number saved on the profile is then used). Errors `alternate_phone_required|invalid|same` come back as quote errors. `PATCH /me` accepts
`alternate_phone` (null or empty clears it) and `GET /me` returns it to its owner only. While the dashboard switch `alternate_phone_enabled` (`/app-config`) is off all of this is ignored and `alternate_phone` is returned as `null` everywhere (stored numbers are kept). Slot rows carry `bookable_until` (ISO-8601, +03:00): the
last moment the time can be booked.
**Delivery slots.** When a single-branch delivery order is quoted and that branch publishes delivery slots, a slot is
required: the fulfillment carries `slot_required: true` and, until `delivery_slot_id` + `delivery_date` are sent, the
quote has error `slot_required` (`can_place_order: false`). Pick the slot from
`GET /branches/{fulfillments[0].branch.id}/delivery-slots?date=…`. A taken/invalid slot yields `slot_unavailable`
(re-fetch slots). Split orders (several branches) cannot use a slot (warning `slot_ignored_for_split`).
Capacity is reserved when the order is placed and released if it is cancelled.

Quote:
```json
{ "quote_hash":"sha256…", "currency":"QAR",
  "fulfillments":[{"branch":{"id","name"}, "type":"delivery", "items":[{"variant_id","name","quantity","unit_price":EffectivePrice,"line_total":Money}],
                   "subtotal":Money, "delivery_fee":Money, "extra_delivery_fee":Money, "delivery_extras":[DeliveryExtra], "eta_minutes":120, "slot":{"id","date":"YYYY-MM-DD","starts_at":"16:00","ends_at":"18:00"}|null,
                   "slot_required":true}],
  "is_split": false,
  "lines":[{"variant_id","name","variant_name","image_url","quantity","unit_price":EffectivePrice,"line_subtotal":Money,"discount":Money,"line_total":Money,"branch_id"}],
  "delivery_extras":[{"product_id":"uuid","name":"Corner sofa","mode":"per_unit|per_line","quantity":2,"fee_each":Money,"amount":Money,"branch_id":"uuid"}],
  "totals":{"subtotal":Money,"discount":Money,"delivery_fee":Money,"extra_delivery_fee":Money,"assembly_fee":Money,"service_fee":Money,"cod_fee":Money,"tax":Money,"loyalty_discount":Money,"grand_total":Money},
  "discounts":[{"label":"Welcome 10%","code":"WELCOME10","amount":Money}],
  "payment_methods":[{"code":"sadad","available":true,"reason":null},
                     {"code":"cod","available":false,"reason":"outside_cod_limits","limits":{"min":Money|null,"max":Money|null}},
                     {"code":"pay_at_pickup","available":false,"reason":"pickup_only"}],
  "loyalty":{"balance":1200,"max_redeemable_points":600,"points_to_earn":350},
  "warnings":[{"code":"price_changed","message":"…"}], "errors":[{"code":"insufficient_stock","variant_id":"…","message":"…"}],
  "can_place_order": true, "expires_at":"…" }
```
**Fees (CHECKOUT_FEES_V2.md).** `totals.delivery_fee` is the whole delivery fee: zone fee + oversized surcharge + the extra delivery fees of bulky products + express.
`totals.extra_delivery_fee` is the part that comes from products (`"0.00"` when none) and `delivery_extras` lists them (empty when none), so a client can show
"Extra delivery fee for bulky items" under the delivery line; per fulfillment the same two fields sit on `fulfillments[]`. A free-delivery coupon waives the whole
`delivery_fee` (the discount line has `free_delivery: true`). `totals.cod_fee` is **always `"0.00"`**: cash on delivery costs nothing extra (the key stays only so
older app builds keep working; never show a row for it). There is **no minimum order**: the quote never answers `below_min_order` (clients should still show an
unknown error `message` as a normal error); `above_max_order` (the zone's maximum) still exists.

Placing an order re-validates everything. If totals differ from `quote_hash` the server answers **409 `quote_changed`** with `context.quote` (the new Quote) — the app must show the new totals and ask the customer to confirm again. Retrying with the same `Idempotency-Key` returns the original order (no duplicate).

PaymentAction (returned with new orders and by `POST /orders/{id}/payments`):
```json
{ "payment_id":"uuid","method":"sadad","status":"pending",
  "action": "redirect|none", "redirect_url":"https://…/payments/sadad/{id}/start?signature=…",
  "return_url_scheme":"albahar://payments/{payment_id}/return", "expires_at":"…" }
```
For SADAD the app opens `redirect_url` in a secure in-app browser (Custom Tabs / SFSafariViewController). When the browser returns to `albahar://payments/{id}/return` the app **must poll** `GET /payments/{id}` until `status` ∈ `paid|failed|expired|cancelled` — the redirect itself never proves payment.

## 7b. Delivery date promise and assembly (3.1, 3.5)
Full design and the owner's switches: [DELIVERY_PROMISE_ASSEMBLY_AND_SHARING.md](../guides/DELIVERY_PROMISE_ASSEMBLY_AND_SHARING.md). **Server-computed, never trusted from the client:**
the client shows `message` (already in the request's language, `Accept-Language`) and may use the structured values. A missing date is an honest answer, never an error.

| Method | Path | Notes |
|---|---|---|
| GET | `/products/{id}/delivery-promise?variant_id=&quantity=&assembly=&fulfillment_type=&branch_id=&address_id=&delivery_zone_id=&zone_number=&latitude=&longitude=` | Public. One product (its chosen or default variant) times `quantity`. `Cache-Control: public, max-age=60`, `Vary: Accept-Language`. 404 `item_unavailable_simple` when the product has no active variant |
| POST | `/cart/promise` | Public. `{lines:[{variant_id, quantity, assembly?}], allow_split?, fulfillment_type?, branch_id?, address_id?, delivery_zone_id?, zone_number?, latitude?, longitude?}`. A guest must send `lines`; a signed-in caller may omit them and gets the promise for their own server cart. `no-store` |
| GET | `/cart/promise` | `auth:customer`. The promise for the signed-in customer's own cart (its assembly choices, branch and address) |

All three are rate limited (`delivery-promise`). `address_id` counts only when it is the signed-in caller's own (any other id is ignored). A `branch_id` that is not an
active branch is ignored. Quantity is limited by `platform.checkout.max_quantity_per_line`. Answers are cached on the server for 30 seconds per identical question and language.

BasketPromise (`data`):
```json
{ "enabled": true, "fulfillment_type": "delivery|pickup",
  "status": "scheduled|partial|unconfirmed|disabled",
  "message": "Delivery tomorrow, 4 pm - 6 pm", "detail": "…"|null,
  "all_by": {"date":"YYYY-MM-DD","label":"All delivered by Sat 22 Nov"}|null,
  "is_split": false, "location_known": true, "generated_at": "2026-11-21T12:00:00+03:00",
  "parcels":[{"branch":{"id","name"}, "type":"delivery|pickup", "status":"scheduled|unconfirmed",
              "reason": null|"out_of_stock"|"item_unavailable"|"branch_closed"|"not_offered"|"no_date",
              "kind": null|"slot"|"same_day"|"day"|"ready", "date":"YYYY-MM-DD"|null, "label":"tomorrow"|null,
              "message":"…", "detail":"…"|null, "slot":{"id","starts_at","ends_at"}|null,
              "items":[{"variant_id","quantity"}]}] }
```
* `enabled: false` (`status: "disabled"`, `message: null`) when the dashboard switch is off: clients draw nothing.
* `location_known` is false for a delivery question with no address or zone yet: the date is then the best showroom's, so show "The exact date depends on your address."
* A split basket gives one parcel per showroom; `all_by` is the latest of them. `status: "partial"` means some parcels have a date and some do not.
* The same object comes back on the quote: `promise` (basket) and `fulfillments[].promise` (that parcel's `parcels[]` entry), `fulfillments[].earliest_date` (YYYY-MM-DD, the first day the date picker may offer).

**Assembly and installation.** A product offers it when `assembly_service_available` is true (it has an assembly fee above zero and the dashboard switch is on); the fee per unit is on
the product card/detail as `assembly_fee` (Money|null) and the product's extra lead time as `lead_time_days` (detail).
* Choose it with `assembly: true` on `POST /cart/items` (adds the line with it) or `PATCH /cart/items/{id}` with `{assembly: true|false}` alone. A product that does not offer it answers
  **422 `assembly_not_offered`**. The choice is per cart line, kept when the quantity changes, and a guest merge keeps it if either side chose it.
* The fee is **per unit, delivery only**, no discount and no tax. A pickup cart keeps the choice but charges nothing (`amount: null`).
* **Quote:** `lines[].assembly` (`{fee_each, amount}`|null), `fulfillments[].assembly_fee`, `totals.assembly_fee` (already inside `grand_total`). The quote hash changes only when assembly is selected, so a
  changed choice is the usual 409 `quote_changed`.
* **Order:** `items[].assembly` (`{fee_each, amount}`|null, frozen at placement) and `totals.assembly_fee`. The invoice shows it as its own line. It follows the units: reducing, removing, replacing or
  cancelling units, and a cancelled parcel, refund the fee for exactly those units; the fee is part of the amount split across parcels and cash on delivery.
* **Staff and driver:** order and dispatch rows carry `assembly_ordered` (bool); the driver job's `requires_assembly` is also true when the customer paid for it.
* **Date effect:** a delivery with assembly adds the dashboard's *Extra days when assembly is chosen* to the promised date.

Errors: quote error **`slot_too_soon`** (in `errors[]`, `can_place_order: false`, so an order cannot be placed either) when the chosen date or slot is earlier than the promise allows (re-fetch the
slots from `fulfillments[].earliest_date`); **422 `assembly_not_offered`** as above.

## 8. Orders (`auth:customer`)
| Method | Path | Notes |
|---|---|---|
| GET | `/orders?status=` | OrderSummary[] |
| GET | `/orders/active-progress` | The live order card (`OrderProgress`) of each of my orders in progress or finished < 30 min ago, newest first, max 10: `{data: [OrderProgress]}`. Same object as the `order_progress` push; see [LIVE_ORDER_NOTIFICATION.md](../guides/LIVE_ORDER_NOTIFICATION.md) |
| GET | `/orders/{id}` | Order |
| GET | `/orders/{id}/timeline` | `[{kind: order\|fulfillment\|payment\|delivery, status, label, at, fulfillment_id?}]` |
| GET | `/orders/{id}/tracking` | Live driver, destination, branch and arrival estimate for the customer's own order (see [MAPS.md](../guides/MAPS.md)) |
| POST | `/orders/{id}/cancel` | `{reason_code, reason?}` → `{status: approved\|requested}` |
| POST | `/orders/{id}/reorder` | Adds still-available items to cart → Cart + `skipped:[…]` |
| GET | `/orders/{id}/invoice` | `application/pdf` |
| POST | `/orders/{id}/payments` | Retry online payment (Idempotency-Key) → PaymentAction |
| GET | `/payments/{id}` | `{id, status, method, amount: Money, paid_at, failure_reason}` |
| GET | `/orders/{id}/fulfillments/{fid}/pickup-pass` | `{qr_payload, expires_at, branch, status}` (only when `pickup_ready`) |
| POST | `/orders/{id}/returns` | Send a return or damage claim: JSON or multipart `{items:[{order_item_id, quantity}], reason_code, reason?, photos[]?}` (see 8.1) → ReturnClaim (201; 200 when it repeats a claim already made) |
| GET | `/returns/{id}` | ReturnClaim (your own; any other id answers 404) |
| POST | `/returns/{id}/evidence` | Photos and/or a note while the claim waits for the team (8.1) |
| POST | `/returns/{id}/cancel` | Cancel the claim before it is decided |
| GET | `/returns/{id}/photos/{photoId}` | One photo of your claim (`image/jpeg`, private, bearer token) |
| POST | `/orders/{id}/substitutions/{requestId}` | `{answer: accept\|decline}` → `{status, replayed, order}` (8.2) |

Order:
```json
{ "id":"uuid","number":"AB100123","status":"processing","status_label":"Processing",
  "payment_status":"paid","payment_method":"sadad","fulfillment_type":"delivery",
  "placed_at":"…","totals":{…same keys as quote…},"delivery_extras":[{"product_id","name","mode","quantity","fee_each":Money,"amount":Money}],"address":{…snapshot…},
  "items":[{"id","product_id","variant_id","sku","name","variant_name","image_url","quantity","unit_price":Money,"line_total":Money,"branch_id","fulfillment_id"}],
  "fulfillments":[{"id","number":"AB100123-1","branch":{"id","name","phone","latitude","longitude"},"type":"pickup","status":"pickup_ready","status_label":"Ready for pickup",
                   "slot":{"date","starts_at","ends_at"}|null,"delivery":{"tracking_number","status","provider","driver_name","driver_phone","eta"}|null,
                   "pickup":{"status","token_expires_at"}|null}],
  "can_cancel": false, "can_pay": false, "can_return": true, "can_review": true,
  "return_request": {"id","number","status","status_label","requested_at","decided_at":"…"|null,"decision_note":"…"|null}|null,
  "progress": OrderProgress|null }
```
Per item line: `returnable_quantity` (units that can still be asked back right now: handed over, inside the return window, not already in an open request).
`can_return` is true only while `returnable_quantity` is above zero for some line **and** no return request is waiting on the team; `return_request` is the latest request (or null),
and `decision_note` is the note the team typed when it approved or rejected, so show it to the customer.
`delivery.driver_phone` is sent only while the driver has the order (delivery assigned, accepted, picked up or out for delivery), never for pickup orders and never after the delivery ended.

**Why a payment method is unavailable** (`payment_methods[].reason`, `null` when available): `temporarily_unavailable` (online payment is off), `delivery_only` (cash on delivery needs a delivery order),
`not_offered_in_area` (the branch or delivery zone does not take cash), `outside_cod_limits` (the basket is under the minimum or over the maximum for cash on delivery; `limits.min` / `limits.max` carry the numbers so
the app can say "available up to QAR 5,000"), `pickup_only` and `not_offered_at_branch` (pay at pickup). Clients must show a plain sentence for every code and never print the raw code; when no method is available
offer a way out (pay at the showroom, call the branch).
Order statuses: `pending_payment, confirmed, processing, partially_fulfilled, ready_for_pickup, out_for_delivery, completed, cancellation_requested, cancelled, partially_cancelled, returned`.
Fulfillment statuses: `pending, accepted, preparing, ready, dispatched, out_for_delivery, delivered, pickup_ready, picked_up, failed, cancelled, returned`.
Payment statuses: `pending, processing, authorized, paid, failed, cancelled, expired, partially_refunded, refunded`.

### 8.1 Return and damage claims

`POST /orders/{id}/returns` (JSON, or `multipart/form-data` with `photos[]`; the apps send multipart with an `Idempotency-Key` header, throttled 20 per minute).

| Field | Rules |
|---|---|
| `items[]` | 1 to 50 of `{order_item_id (uuid, distinct), quantity (1+)}`; every line must be delivered or collected, inside the claim window, with no more pieces than were handed over and are not already in another claim; all lines from one branch (422 on `items` otherwise) |
| `reason_code` | `damaged`, `wrong_item`, `missing_item`, `quality`, `changed_mind`, `other` (an older app's `not_as_described` is read as `quality`) |
| `reason` | optional note, at most 500 characters (any language) |
| `photos[]` | at most 5, JPEG / PNG / WebP by real content, 8 MB each. **`damaged` and `wrong_item` need at least one** (422 on `photos`) |

Answers `201` with the claim (`200` with the same claim when the `Idempotency-Key`, or an identical claim sent in the last two minutes, was already used; the same key on another order is `409 idempotency_key_reused`). A window that closed, a piece already claimed, or a disabled switch is `422` / `403` as before.

ReturnClaim (also `order.return_request` = the latest, and `order.returns[]` = all, newest first):
```json
{ "id":"uuid","number":"RT26001","order_id":"uuid","status":"requested","status_label":"Requested",
  "kind":"damaged","reason_code":"damaged","reason_label":"Damaged on arrival","evidence_required":true,"note":"Cracked leg",
  "requested_at":"…","decided_at":null,"decision_note":null,
  "items":[{"order_item_id","quantity","name","variant_name","image_url"}],
  "photos":[{"id","path":"returns/{claim}/photos/{id}","at"}],"max_photos":5,"messages":[{"text","at"}],
  "can_add_evidence":true,"can_cancel":true,
  "method":null,"method_note":null,"branch":{"id","name","phone"},
  "refund":{"status":"requested|approved|processing|processed|rejected|failed","amount":Money,"method":"original|manual_cash|manual_bank_transfer","processed_at":null}|null,
  "timeline":[{"key":"received","state":"done","at":"…","detail":null}, … ] }
```
`status`: `requested`, `under_review`, `approved`, `rejected`, `received`, `refunded`, `closed`, `cancelled`. `method` (set by the team when it approves): `pickup`, `branch` (bring it to the branch) or `none` (nothing comes back). **Timeline** `key`s, in order: `received`, `under_review`, `decision` (before it is decided) / `approved` / `rejected`, `handover` (before) / `pickup` / `branch`, `item_received`, `refunded`, or `cancelled`; `state`: `done`, `current` (what it waits for), `upcoming`, `stopped` (where it ended); `detail` carries the team's reply, the pickup window, or nothing. Clients write their own words for the keys.
`photos[].path` is **relative to `/api/v1`** and the file is private: fetch it with the bearer token (`GET /returns/{id}/photos/{photoId}`, `image/jpeg`), never link it. Any claim or photo that is not the signed-in customer's answers `404`.

`POST /returns/{id}/evidence` (multipart: `photos[]` and/or `reason`) works while the status is `requested` or `under_review`; the same picture or the same words again change nothing; more than 5 photos in all is `422` on `photos`; after the decision `409 claim_not_editable`. `POST /returns/{id}/cancel` withdraws the claim (twice is once; after the decision `409 claim_not_editable`) and frees its pieces.

Order additions: `can_return` is true while any piece can still be reported (it no longer waits for an open claim to end), `return_window: {days, until}` (`until`: the last day for the pieces still reportable, ISO-8601 +03:00, or `null`; the days are a dashboard setting, default 14: "You can report a problem until <date>"), `returns[]`. Each claim step sends the customer a push and an in-app message: `return.received` (inbox only), `return.under_review`, `return.approved`, `return.rejected`, `return.item_received`; the refund itself is `refund.processed`.

### 8.2 Substitution consent

When the branch replaces a line with another product (or one whose own price is above what was ordered) the order carries a question:
```json
"substitution_requests":[{ "id":"uuid","status":"waiting",
  "original":{"name","variant_name","quantity":2,"image_url","total":Money},
  "replacement":{"name","variant_name","quantity":2,"image_url","total":Money},
  "price_difference":{"amount":"-800.00",…},"fallback":"remove","asked_at":"…","expires_at":"…","answered_at":null,"can_answer":true }],
"waiting_for_customer": true
```
`status`: `waiting`, `accepted`, `declined`, `expired_removed`, `expired_kept`, `cancelled`. `price_difference` is what the customer pays more (a negative amount: less) than for the line they ordered and is **never above zero**. `fallback` is what happens at `expires_at`: `remove` (the replacement is taken out and refunded) or `keep` (it stays at no extra cost). `expires_at` counts the branch's opening hours (Settings, default 30 minutes).

`POST /orders/{id}/substitutions/{requestId}` body `{answer: "accept"|"decline"}` (throttled 30 per minute) → `{data:{status, replayed, order: Order}}`. The same answer again is `200` with `replayed: true`; another answer after it is settled is `409 substitution_already_answered`; after the deadline `409 substitution_expired` (the question was settled by the fallback; read the order); a closed question (order moved on, line changed) `409 substitution_closed`; another customer's order or question `404`. A "no" runs the normal adjustment: the replacement is removed (revision reason `substitute_declined`, or `substitute_expired` on timeout), stock released, a refund request raised for finance when something was paid, the revised invoice issued, and the customer told with `order.items_changed`. While a question is open the branch cannot mark that part ready (`409 substitution_pending`), and `GET /admin/dispatch/queue` rows carry `waiting_for_customer: {count, since, deadline_at}` (`null` when none). Notification events: `order.substitution_offer` (push + in-app, replaces the plain "order updated" for that revision) and `order.substitution_kept`.

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

## 9b. Deals (countdown offers)
Full contract: [DEALS.md](DEALS.md). Public; a signed-in customer's token only adds `customer_remaining`.

| Method | Path | Notes |
|---|---|---|
| GET | `/deals?home=1&limit_products=8&branch_id=` | `{server_time, live:[Deal], upcoming:[Deal]}`: `live` ends-soonest first (a sold-out deal stays 15 min), `upcoming` by start; paused and ended deals are never listed |
| GET | `/deals/{id}?page=&branch_id=` | One `Deal` with `products:{data:[ProductCard], meta}` (24 a page) and `server_time`; 404 `deal_not_found` when ended or paused |

* **Counting down:** use `ends_at` with the server clock (`server_time` of the same response), never the phone clock alone; `seconds_left` is the same figure at the moment of the response.
* **The `deal` block** is on every ProductCard, on ProductDetail (variant rows also carry `deal_price`) and on cart lines (`items[].deal`, with `units_at_deal_price`, `units_at_regular_price`, `note`). `price.final` always stays the normal price: show `deal.deal_price` with the normal price struck through while `phase` is `live`.
* **Quote / order:** `totals.deal_savings`, `lines[].deal` (`units_at_deal_price`, `units_at_regular_price`, `savings`, `note`) and `discounts[].source` (`deal`|`promotion`); placed orders expose `items[].deal` and `totals.deal_savings`. Units beyond a limit are simply charged at the normal price.
* **409 `deal_changed`** on `POST /checkout/orders` (a deal ended, sold out or hit a limit between the quote and the order) carries `context: {deal_id, product_id, quote}` exactly like `quote_changed`: show "A deal changed, your total was updated" and ask the customer to confirm the new total.
* The footer menu (`GET /theme` → `footer`) has `center`: `none|auctions|deals`; see DEALS.md section 6.

## 10. Home, CMS, notifications, support
`GET /home?branch_id=&platform=` →
```json
{ "sections":[
  {"id":"uuid","type":"banners","layout":"carousel","title":null,"subtitle":null,
   "slider":{"autoplay":true,"interval":5,"aspect":"16:9","dots":true,"show_titles":true,"rounded":true},
   "items":[{"id","title","subtitle","image_url","link":null|{"type":"category|page|product|brand|auction|url|search","value":"…"}}]},
  {"type":"categories","layout":"chips","title":"Shop by room","items":[Category]},
  {"type":"featured_products|new_arrivals|best_sellers|recommended|product_collection","items":[ProductCard]},
  {"type":"brands","items":[Brand]},
  {"type":"rooms","layout":"grid","title":"Shop by room","items":[Room]},   // same Room objects as GET /rooms (§5b); the section is left out of the feed when no room has products
  {"type":"promotions","items":[{"id","name","description","badge":"-20%","ends_at"}]},
  {"type":"upcoming_auctions|live_auctions","items":[AuctionCard]} ] }
```
Section order and content are entirely server-driven; unknown `type`s must be ignored by the client. A `banners` section is a slider whose slides are the `items` (a `null` link means the slide is not tappable); the full rules are in `HOME_BANNERS_AND_ALT_PHONE.md`.

| Method | Path | Notes |
|---|---|---|
| GET | `/pages/{slug}` | `about, contact, faq, terms, privacy, returns, auction-terms` → `{slug, title, body (HTML), updated_at}` |
| GET | `/me/notifications` | `[{id, event, title, body, data:{deep_link}, read_at, created_at}]` + `meta.unread_count` |
| POST | `/me/notifications/{id}/read` · `/me/notifications/read-all` | |
| GET/POST | `/me/support/tickets` | `{category, subject, message, order_id?}` |
| GET | `/me/support/tickets/{id}` | Ticket + messages (internal notes never included) |
| POST | `/me/support/tickets/{id}/messages` | `{body}` |

Deep links: `albahar://products/{id}`, `albahar://orders/{id}`, `albahar://auctions/{id}`, `albahar://auctions/lots/{lotId}`, `albahar://categories/{id}`, `albahar://payments/{id}/return`, `albahar://support/{ticketId}`, `albahar://products/{id}?review=1` (opens the product with the review window ready; sent by the after-delivery journey; web `/products/{slug}?review=1`). HTTPS universal-link equivalents: `https://albahar.qa/app/...`.

Push payload (FCM `data`): `{ "event": "order.ready_for_pickup", "deep_link": "albahar://orders/…", "notification_id": "uuid" }`.
The customer's live order card is a separate data-only push, `{ "kind": "order_progress", "order_id", "step", "total", "title", "body", "eta_from", "eta_to", "seq", … }` (all values strings): see [LIVE_ORDER_NOTIFICATION.md](../guides/LIVE_ORDER_NOTIFICATION.md).
