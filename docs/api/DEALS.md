# Deals: countdown offers (contract for server, dashboard, web store and customer app)

Owner request (verbatim intent): on the customer app the admin can add / edit a button on the footer menu, placed in the
**middle**, that leads to **Auctions or Deals** (the admin decides). A **Deal** is an offer with a countdown
`dd:hh:mm:ss` and a limited quantity, to push the customer to order now. The admin can customise the design of the deal
widget. A deal can cover one product or many. The admin can create many deals, each with its own countdown. Limits:
x units **per order**, and/or x units **per customer account**.

Name in the product: **Deals** (Arabic: **العروض**). Not to be confused with the older `Promotion` (coupon / percentage
rules): both keep working. A deal is a customer-facing countdown offer; a promotion is a pricing rule.

## 1. Words and rules (read first)

* **Deal** = one countdown + a set of products + a price rule + limits + a widget design.
* **Deal price** is a per-unit price that applies only to units the customer is still allowed to buy at that price.
  Units beyond a limit are simply charged at the normal price. Nobody is blocked from buying more. The cart says it plainly:
  "Deal price on 2 of 3 · limit 2 per order".
* **Phases** (computed, never stored): `scheduled` (switched on, before `starts_at`), `live` (inside the window and at least
  one product still has units), `sold_out` (inside the window but every product with a quantity is sold out), `ended`
  (past `ends_at`, or the admin pressed "End now"), `paused` (switched off by the admin, not ended).
* A product cannot be in two deals whose windows overlap (switched-on, not ended). Saving fails with a plain message that
  names the other deal. This keeps the price a customer sees deterministic.
* **Quantity** (`quantity_total`) is per product inside the deal; empty = unlimited. `quantity_sold` counts units claimed by
  placed orders and goes back when an order is cancelled, refunded in full, expires unpaid or fails payment.
* **Limits** are per product inside the deal. `limit_per_order` (units of one product in one order) and
  `limit_per_customer` (units of one product over the customer's whole account during the deal, from orders that were not
  released). A product row may override the deal-level limit. Empty = no limit.
* **Deal units are not discounted twice**: other automatic promotions and coupon item discounts do not apply to units that
  got a deal price. Free-delivery coupons still work (they only waive delivery).
* Deals apply to **online orders** (customer app and web store). Staff-made orders (dashboard "New order", branch app) and
  auctions do not apply deals. Recorded in `decisions`; the owner can change it later.
* Money is handled like everywhere else: `Money` value, JSON `{amount, currency, formatted}`, never floats.
* Time: all instants are ISO-8601 with offset (BusinessTime::iso). Clients compute the countdown from `ends_at` using the
  server clock offset (`server_time` in every deals response), never from the phone clock alone.

## 2. Data model (new tables, new domain `App\Domain\Deals`)

`deals`: `id` uuid, name and tagline per language (same translation convention as Auction / Promotion), `is_active` bool,
`starts_at` nullable (null = from the moment it is switched on), `ends_at` required (after `starts_at`, in the future when
activating), `ended_at` nullable (set by "End now"), `discount_type` (`percent` | `amount_off` | `fixed_price`),
`discount_value` decimal (percent 1 to 95; amount_off > 0; unused for fixed_price), `limit_per_order` nullable unsigned
small int, `limit_per_customer` nullable unsigned small int, `show_on_home` bool, `sort_order`, `design` json (section 4),
timestamps, soft delete not needed (an ended deal with orders is kept; a deal with no redemptions can be deleted).

`deal_products`: `id`, `deal_id` FK, `product_id` FK, `deal_price` nullable decimal (required when `discount_type` =
fixed_price; overrides the computed price for that product otherwise only when set), `quantity_total` nullable unsigned int,
`quantity_sold` unsigned int default 0 with a **CHECK (quantity_total IS NULL OR quantity_sold <= quantity_total)**,
`limit_per_order` nullable, `limit_per_customer` nullable, `sort_order`, unique (`deal_id`, `product_id`).

`deal_redemptions`: `id`, `deal_id`, `deal_product_id`, `order_id`, `user_id`, `product_id`, `units` unsigned int,
`unit_savings` decimal, `released_at` nullable, `created_at`. Indexes for "units this customer already bought in this deal
product" (`user_id`, `deal_product_id`, partial on `released_at IS NULL`) and for "release by order" (`order_id`).

Prices: `percent` and `amount_off` are computed per variant from its **current regular/sale final price** (never below
0.01); `fixed_price` uses the product row's `deal_price` for every variant of that product whose normal price is higher
(a variant whose normal price is already lower than the deal price gets no deal). Deal price is rounded half-up to 2 decimals.

## 3. Money flow (server-authoritative)

1. **Display** (public, cacheable): catalog product cards and details carry a `deal` block (below). `price.final` keeps its
   normal meaning. Apps and store show `deal.deal_price` with the normal price struck through while the deal is `live`.
2. **Quote** (cart / checkout): `DealEngine` runs before `PromotionEngine`. For each line it decides how many units get the
   deal price: `min(quantity, limit_per_order, limit_per_customer remaining for this signed-in customer, units still
   available in the deal)`. The discount per unit = normal unit price − deal price. It is returned as an `AppliedDiscount`
   with source `deal` (label = deal name) and shown in `totals` as `deal_savings`. PromotionEngine skips the deal-priced units.
3. **Claim** (place order, inside the order transaction): lock the `deal_products` rows involved (in id order), re-evaluate,
   then claim with an atomic update and insert `deal_redemptions`. If what can be claimed is fewer units than the customer
   was shown, nothing is placed and the server answers `409 deal_changed` with the fresh quote, so the app shows "A deal
   changed, your total was updated" and the customer confirms again. No surprise price, ever.
4. **Invariants that tests must prove**: never oversell (`quantity_sold <= quantity_total`, also under two orders for the
   last unit); the per-account limit holds across orders and across two simultaneous orders; the paid total equals the
   confirmed quote; release is idempotent (cancel twice releases once); a deal that ended while the customer was in
   checkout gives `deal_changed`, not a silent price change.
5. **Release**: cancel, full refund, payment failed, unpaid expiry, abandoned-then-expired all call `DealService::release($order)`.
6. Orders keep a snapshot: each order line stores the deal id, deal name and per-unit savings it got (so invoices and the
   revised-invoice flow for missing items stay correct; a removed unit on an order adjustment returns its deal unit).

## 4. The widget design (`deals.design`, strictly validated, same tokens in dashboard preview, store and app)

```json
{
  "style": "classic | bold | minimal | luxe | ribbon",
  "palette": "brand | gold | night | emerald | rose | custom",
  "colors": { "background": "#RRGGBB", "text": "#RRGGBB", "accent": "#RRGGBB" },
  "shape": "rounded | sharp | pill",
  "timer": { "units": ["d","h","m","s"], "look": "boxes | digits | text", "urgency_minutes": 60 },
  "stock_bar": true,
  "percent_badge": true,
  "icon": "flame | bolt | timer | tag | gift | star | crown",
  "headline_en": "Ends in", "headline_ar": "ينتهي خلال",
  "cta_en": "Shop the deal", "cta_ar": "تسوق العرض",
  "image_url": null
}
```

* The server resolves the palette: responses always include `design.colors` fully filled (for `custom` the admin's three
  colours, else the preset's). Colours are checked for readable contrast (WCAG AA between text and background; the admin
  page warns, the API rejects below 3:1). Clients never invent colours.
* `timer.units` is any subset of d/h/m/s with at least 2; days are hidden when under 24 h and `d` is listed ("auto").
  `urgency_minutes` (0 = off): below that, the digits use the accent colour and pulse once a second (never when the
  person prefers reduced motion).
* `stock_bar` shows "Only {n} left" with a bar when the product has a quantity; `percent_badge` shows "-30%".
* `image_url` is optional (uploaded through the same media route the Banner uses); with an image the widget draws it behind
  the text with a legible overlay.
* Defaults when nothing is set: `classic`, `brand`, `rounded`, units d/h/m/s, `boxes`, urgency 60, stock bar and badge on,
  icon `flame`, headline and CTA words above.

## 5. Public API (`routes/api_public.php`, optional auth so `customer_remaining` is filled for signed-in customers)

### `GET /api/v1/deals`

Query: `home=1` (only deals with `show_on_home`), `limit_products` (1 to 12, default 8, products shown per deal).

```json
{
  "data": {
    "server_time": "2026-11-17T09:12:00+03:00",
    "live": [ Deal ],
    "upcoming": [ Deal ]
  }
}
```

Ordering: `live` by `ends_at` ascending (the one ending soonest first), then `sort_order`; `upcoming` by `starts_at`.
Ended, paused and sold-out deals are not listed (a sold-out deal that is still in its window stays in `live` with
`phase: "sold_out"` for 15 minutes so the person who clicked it sees why, then disappears).

### `GET /api/v1/deals/{id}` (`?page=`, 24 products per page, same cards as the catalog)

Returns one `Deal` with `products.data` (cards, each with its `deal` block) and `products.meta`. 404 `deal_not_found` when
the deal is ended or paused (a sold-out one still answers).

### `Deal`

```json
{
  "id": "uuid", "name": "Weekend Furniture Drop", "tagline": "Big pieces, small prices",
  "phase": "live", "starts_at": "...", "ends_at": "...",
  "seconds_left": 93234,
  "discount": { "type": "percent", "value": "30" },
  "limits": { "per_order": 2, "per_customer": 3 },
  "products_total": 18,
  "products": [ ProductCard ],
  "design": { ...section 4, with colors resolved... },
  "show_on_home": true
}
```

### The `deal` block on product cards, product detail and cart lines

```json
{
  "deal_id": "uuid", "name": "Weekend Furniture Drop", "phase": "live",
  "starts_at": "...", "ends_at": "...",
  "deal_price": {Money}, "regular_price": {Money}, "percent": 30,
  "remaining": 12, "remaining_percent": 24,
  "limit_per_order": 2, "limit_per_customer": 3,
  "customer_remaining": 3,
  "sold_out": false,
  "design": { ... }
}
```

`remaining` and `remaining_percent` are null when unlimited. `customer_remaining` is null for a guest or when there is no
per-customer limit; `0` means this customer already used it (the app shows "You reached your limit for this deal" and the
normal price). `deal` is `null` when the product is in no live or scheduled deal. For a **variant** product the block is on
the product, and `deal_price` is the price for the cheapest variant ("from"); variant rows inside the detail carry their own
`deal_price`.

### Quote and order payloads (additions)

* `totals.deal_savings` (Money, "0.00" when none) and `discounts[]` entries with `source: "deal"`.
* Each quote line gains `deal` (null or): `{ deal_id, name, units_at_deal_price, units_at_regular_price, savings, ends_at, note }`
  where `note` is `null | "limit_per_order" | "limit_per_customer" | "sold_out" | "ended"`.
* Error `409 deal_changed` (context: `deal_id`, `product_id`) on place order, with the fresh quote in `data.quote`.
* Placed order lines expose `deal` (snapshot): `{ deal_id, name, units, unit_savings }`.

Error codes (translated in `lang/{en,ar}/errors.php`): `deal_changed`, `deal_not_found`, `deal_product_overlap`
(admin), `deal_invalid_window` (admin).

## 6. Footer middle button

`app.footer` gains `center`: `"none"` (default) | `"auctions"` | `"deals"`. The theme payload `footer.center` carries it.

* `AppFooterService::ITEMS` gains `deals` (route `/deals`, default icon `flame`; `ICONS` gains `flame`, `bolt`, `timer`,
  `crown`). The label and icon of the middle button are edited like any other item (the item's `label_en`, `label_ar`,
  `icon`), so the dashboard has one place to edit.
* When `center` is not `none`: the chosen key must be among the enabled items; the app draws that item as the **raised
  round middle button** (the existing centre-action bar look, whichever `design` is saved) and puts the other items
  evenly on its two sides (4 others = 2 + 2; 3 others = 2 + 1... the button is always at the exact middle of the bar). A
  deals button shows a small live dot while at least one deal is `live` (the app reads `GET /deals?home=1&limit_products=1`
  once per minute while open; no dot and no request when the button is not Deals).
* The dashboard page (Appearance > App footer) gets a "Middle button" choice: None / Auctions / Deals, with a live preview.
  Choosing one switches the item on, moves it to the middle and explains: "The middle button is raised and round."
  Validation: still 3 to 5 items, Home on; `center` key present and enabled.
* Old payloads without `center` behave as `none`. Old apps ignore the field and show the item in its normal place.

## 7. Dashboard (Marketing > Deals, `admin.deals.index`)

* List: name, phase badge, **live countdown** (ticks), products count, units sold / total with a small bar, orders, savings
  given; row actions: Pause / Resume (instant), End now (confirm), Duplicate, Edit, Delete (only when it has no orders).
  Filters (saved in the URL): Live, Scheduled, Ended, All.
* Editor in a **side window** (docs/guides/SIDE_WINDOWS.md): 1 Basics (names and taglines in both languages, window with
  quick presets: +1 h, +6 h, +24 h, +3 days, end of today, custom date and time in the business time zone), 2 Products (search
  and pick many, each row: deal price when fixed price, quantity, per-order and per-account limit overrides, remove),
  3 Price rule (percent / amount off / fixed price) with a live "customer pays" example from the first product, 4 Limits
  (per order, per customer account, explained in one line each with an example), 5 Look (style, palette, shape, timer
  look, units, urgency, stock bar, badge, icon, headline and button words, optional image) with a **live preview of the
  real widget in English and Arabic, light and dark, that counts down**, 6 Show on home, Active. Save is disabled until dirty.
* Permissions follow Promotions (`marketing.view` to see, `marketing.manage` to change; use the registry's real names),
  every change is audit-logged (`deals.created|updated|paused|resumed|ended|deleted`).
* Overlap error shows the other deal's name and window.

## 8. Not in this change (listed so nobody assumes)

Deal start push notification, deals for staff-made orders, per-branch deals, variant-level deal rows, customer-group deals,
a Deals report in Reports. The notification-rules page (separate task) is where a "deal started" event would be switched on later.

## 9. Proof expected from each builder

Backend: Pest tests for the three price rules and rounding, phases, overlap, the three caps (stock, per order, per account),
the last-unit race (two sequential placements), release idempotence, `deal_changed`, API shapes, footer `center` validation,
admin Livewire flows, permissions and the audit log. Storefront: countdown maths and formatting (days hidden, zero padding,
reaching zero, skew), cart wording, Arabic digits and RTL, 320 px layout. App: parsing, countdown, widget in every style
(English/Arabic, light/dark, 320 dp), centre footer layout (3, 4 and 5 items, RTL), product page and cart integration,
`flutter analyze` clean.
