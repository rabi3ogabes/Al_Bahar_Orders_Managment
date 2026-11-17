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

### Cart payload (`GET /cart`, and every cart response)

Each `items[]` line keeps its `product` card (which carries the product's `deal` block) and adds a line-level `deal`, with the same fields as the block plus the
words of the cart ("Deal price on 2 of 3 · limit 2 per order"):

```json
{ "id": "uuid", "variant_id": "uuid", "quantity": 3,
  "deal": { "deal_id": "uuid", "name": "...", "phase": "live", "deal_price": {Money}, "regular_price": {Money}, "percent": 30, "ends_at": "...",
            "remaining": 12, "limit_per_order": 2, "limit_per_customer": 3, "customer_remaining": 3, "sold_out": false, "design": { ... },
            "units_at_deal_price": 2, "units_at_regular_price": 1, "note": "limit_per_order" } }
```

* `items[].deal` is `null` when the line's variant gets no deal; otherwise `units_at_deal_price + units_at_regular_price = quantity`, and `note` is `null` when
  every unit gets the deal price, else `"limit_per_order" | "limit_per_customer" | "sold_out"` (the reason the rest cost the normal price). Units are shared per
  **product** (two variants of one product share its per-order limit), lines are filled in the order the cart lists them. A staff draft cart has no deals.
* **`deal_savings`** (Money, `"0.00"` when none) at the top level of the cart: what the deal prices save in all. `subtotal` and `items[].line_total` stay at the
  normal price; the quote (below) is the final word on what the customer pays.

### Quote and order payloads (additions)

* `totals.deal_savings` (Money, "0.00" when none) and `discounts[]` entries with `source: "deal"`.
* Each quote line gains `deal` (null or): `{ deal_id, name, units_at_deal_price, units_at_regular_price, savings, ends_at, note }`
  where `note` is `null | "limit_per_order" | "limit_per_customer" | "sold_out" | "ended"`.
* Error `409 deal_changed` on place order: `context` is `{deal_id, product_id, quote}`, the fresh quote sitting in `context.quote` exactly like `409 quote_changed` (see Decisions).
* Placed order lines expose `deal` (snapshot): `{ deal_id, name, units, unit_savings }`.

### Never cached, and the clock

Both deal endpoints answer `Cache-Control: no-store, private, max-age=0` and `Vary: Authorization, Accept-Language`. `server_time` (and `seconds_left`,
`seconds_to_start`, the units left) are read from the server clock at the moment of each request, never remembered, so a CDN or a browser holding an old copy
can never make a countdown drift. Clients correct their clock from `server_time` of every response and count down to `ends_at`; they do not cache these
responses either.

### Search engines (web store)

The server writes the storefront page head for `/deals` and `/deals/{id}` (`StorefrontSeo`, like products and pages): the title and description carry **both
languages** (`Deals | العروض`, a deal's two names, its two taglines, or a description generated from its price rule, product count and end time), `canonical`,
Open Graph tags and a `CollectionPage` with `@language`-tagged names and a breadcrumb. A **live, sold-out or scheduled** deal is indexable; an **ended or
paused** deal still answers 200 (the page explains it) with `noindex,follow`; an unknown id answers 404. Deal pages are never remembered by the SEO cache, so
`noindex` appears the moment a deal ends. `/sitemap.xml` lists `/deals` while at least one deal is live, and every live deal (`/deals/{id}`); it is refreshed
at once when a deal changes.

Error codes (translated in `lang/{en,ar}/errors.php`): `deal_changed`, `deal_not_found`, `deal_product_overlap`
(admin), `deal_invalid_window` (admin), `deal_has_orders` (admin, a deal with orders cannot be deleted).

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
  Filters (saved in the URL): Live, Scheduled, Paused, Ended, All.
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

## 10. Decisions (backend builder; the owner can change any of them)

Where the contract was silent or the code showed a better shape. Field names that differ from sections 1 to 9 are listed first so the app and the store follow them.

**Differences to read first**

1. **`deal_changed` carries the quote in `context.quote`**, not `data.quote`: the error envelope has no `data`; `quote_changed` already uses `context.quote`, so clients reuse one code path. `context` is `{deal_id, product_id, quote}`.
2. `Deal.seconds_to_start` (extra, additive): seconds until `starts_at` while the deal is `scheduled`, else `null`. `seconds_left` is always seconds until `ends_at`, rounded up, never negative.
3. `GET /deals/{id}` returns `products: {data: [ProductCard], meta: {current_page, per_page, total, last_page}}` and `server_time` (as the list does).
4. Cart lines (`GET /cart` → `items[].deal`) carry the product's `deal` block **plus** `units_at_deal_price`, `units_at_regular_price`, `note` (the same words as the quote); the cart also has `deal_savings` (Money). The quote is still the final word.
5. `discounts[]` entries (quote and placed order) all have `source` (`"deal"` | `"promotion"`); a deal entry has `deal_id` and `promotion_id: null`. A placed order's `totals.deal_savings` is the sum of its deal discounts.
6. Quote line `deal.note` `"ended"` is sent for 30 minutes after a deal ended (with `units_at_deal_price: 0`), so a customer who was in checkout is told why the price changed. `"sold_out"` is also used when the deal still has fewer units than the customer wants (the remaining units get the deal price, the rest the normal one).
7. Extra error code `deal_has_orders` (admin). Extra list filter `paused` in the dashboard.

**Rules**

* **Phases** are computed (never stored): `ended` wins over `paused`, `paused` over `scheduled`, `scheduled` over `sold_out`/`live`. `starts_at` is inclusive and `ends_at` exclusive (a deal is live at exactly `starts_at` and ended at exactly `ends_at`). `sold_out` needs every product to have a quantity and every quantity sold; one unlimited product keeps the deal live. The 15 minutes of visibility count from `sold_out_at` (the moment the last unit went; cleared when a unit comes back).
* **Overlap** is checked only when the deal being saved is switched on (a paused draft may overlap; switching it on checks again) against other deals that are switched on and not ended. Windows are half-open: a deal may start exactly when another ends. A deal with no start counts as started.
* A deal that was **ended with "End now" is read-only** (Duplicate it). A deal that merely passed its end time can still be edited (a later end time brings it back).
* A **per-product deal price** always overrides the computed price for that product (also under percent and amount off). A computed deal price is rounded half-up, never below 0.01 and never above the normal price; a variant the rule would not make cheaper gets no deal.
* **Which units get the deal price** when a product sits on several cart lines (variants, branches): the lines that save the most per unit first, ties by line key, so the answer is the same on every run.
* **Custom colours:** text on background and highlight on background must each reach 3:1 (the API refuses below that); the dashboard warns below 4.5:1. The `brand` palette follows the live brand colour of the current theme and picks white or black text.
* The **stored design** keeps the picture as `image_path` (a media-disk path); responses always expose `image_url` and never the path.

**Money and stock**

* Deal units are **claimed under row locks**, taken before the quote is rebuilt inside the order transaction (cart, then deal rows by id, then stock rows; every other path that touches both takes them in this order). The claim is also an atomic guarded update, and a CHECK keeps `quantity_sold <= quantity_total`.
* **`deal_changed` vs `quote_changed`:** the quote remembers (30 minutes, in the cache) a short fingerprint of its deal units under its hash. If the confirmed hash no longer matches and the stored fingerprint differs from the fresh one, the answer is `deal_changed`; otherwise `quote_changed`. A claim that fails at the last step (it cannot happen while the locks hold) is also `deal_changed`, with a quote built after the rollback.
* **Release** gives back what an order no longer holds, once: a cancelled fulfillment (customer, staff, an unpaid online order that expired, an adjustment that emptied it; the units of a parcel that already left stay sold), a fully refunded order (everything), and an item adjustment (a removed unit; a replaced line keeps the share its substitute carries). A line that shrinks keeps `ceil(deal units x new quantity / old quantity)` deal units, the same rule AdjustmentMath uses for the discount.
* **A failed payment attempt does not release**: the order stays payable (retry) until its payment window ends, and the stock stays reserved the same way. When the window ends the unpaid order is cancelled and the units come back. The same "abandoned online order" path covers an abandoned checkout; a plain abandoned cart claims nothing.
* The deal appears on the order as one `order_coupons` row with `deal_id` (no promotion, no coupon), so the order page, the invoice and the revised invoice need no change; an order line keeps `deal_id`, `deal_name_en/ar`, `deal_units`, `deal_unit_savings`.
* Free-delivery coupons are checked against the whole basket, so a basket of nothing but deal units can still use one.
* **Staff-made orders and auctions get no deal prices:** the staff quote options carry `apply_deals: false` (set by the server, never read from a client).

**Public API and caching**

* Nothing about deals is cached between requests (units left change with every order; an admin change must show at once). The home feed keeps its one-minute cache and is dropped whenever a deal changes; cards in it carry no `customer_remaining`.
* Deals on the list are built from one product query and one set of cards for all deals, so the number of queries does not grow with the number of deals (a test counts them).
* Anyone can call both endpoints; a bearer token only adds `customer_remaining`. A product of a deal that is not active in the catalog, or not sold at the chosen `branch_id`, is left out of that deal's cards (and of `products_total` on the list).

**Footer**

* `center` must name an item that is in the menu and switched on, in a menu of 3 to 5 items with Home on; the dashboard puts it back to `none` by itself when its item is switched off or removed. The app (not the server) puts the middle item in the exact middle.
