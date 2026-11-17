# Checkout fees v2: no cash-on-delivery fee, no minimum order, an extra delivery fee per product

Owner request (verbatim): "remove cash on delivery fee on checkout page; no minimum order on checkout page; each product can have extra
delivery fee (the admin can add extra fee for each big box added on the cart or it can be add for any quantity); the admin can enable/disable
the alternative number on the checkout page."

## 1. Cash on delivery fee: removed
* The quote never adds a COD fee. `Quote::$codFee` stays in the JSON (`totals.cod_fee`) **always `"0.00"`** so older app builds that read it keep working.
  New clients never show a COD fee row.
* Removed: the `checkout.cod_fee` setting (dashboard Settings > Checkout & fees field, `config('platform.checkout.cod_fee')`, its language keys, the staff
  New order panel label), a migration deletes the stored `checkout.cod_fee` row. Orders already placed keep their stored `cod_fee` (history, invoices, adjustments).
* Kept: the per-branch / per-zone **Cash on delivery on/off** switch (`cod_enabled`): that decides whether COD is offered, it is not a fee.

## 2. Minimum order: removed
* `branch_delivery_zones.min_order_amount` is no longer read: no `below_min_order` error anywhere (quote, cart, checkout, staff panel). The field is removed from the
  zone editor, the admin API validation and resources, seeders and docs. A migration sets every `min_order_amount` to NULL (live has it on all 15 zones); the column stays (unused).
* Kept: `max_order_amount` (a different rule) and the referral programme's own `min_order_amount` (minimum first order for a reward: unrelated).
* Clients: remove every "add X more to reach the minimum" message, bar and blocked button; keep tolerating an old server that still sends `below_min_order` (show its message as a normal error).

## 3. Extra delivery fee per product
Per product (not per variant), two settings in the product editor (Delivery section) and the API:

| Field | Type | Meaning |
|---|---|---|
| `extra_delivery_fee` | decimal(12,2) >= 0, default 0 | 0 = none |
| `extra_delivery_fee_mode` | `per_unit` \| `per_line`, default `per_unit` | `per_unit`: charged for **every unit** of the product in the order (each big box). `per_line`: charged **once** for the product, whatever the quantity |

Rules (QuoteService, one place):
* Only for **delivery** fulfilment, never pickup. Computed **per fulfilment plan** (a split order charges each branch's own lines).
* Several variants of one product in the same plan count as one product: `per_unit` uses the summed quantity, `per_line` charges once.
* Added to the delivery fee together with the zone fee, the existing zone oversized surcharge (kept as is: no live product uses it) and the express fee:
  `delivery_fee = zone fee + oversized surcharge + extra delivery fees + express fee`. It is **not** waived by anything except a free-delivery coupon, which waives the
  whole `delivery_fee` exactly as today (the quote shows the full fee and a `free_delivery: true` discount of the same amount).
* Quote JSON: `totals.delivery_fee` stays the gross total; new `totals.extra_delivery_fee` (the part that comes from products, `"0.00"` when none) and
  `delivery_extras`: `[{product_id, name, mode, quantity, fee_each, amount}]` (empty list when none) so every client can show the detail line
  "Extra delivery fee for bulky items: QAR X" under the delivery line. Placed orders store it in `fee_breakdown.extra_delivery` ({amount, lines}) and
  `orders.delivery_fee_total` includes it; the invoice shows it as a sub-line when > 0.
* Auction wins delivered by the shop: the product's extra fee is added for quantity 1 (both modes give the same amount).
* Public product API (`/api/v1/products...` detail and list items) exposes `extra_delivery_fee` (string money) and `extra_delivery_fee_mode`; clients show a small note on the
  product page when the fee is > 0: "Extra delivery fee QAR 50.00 per item" (per_unit) or "Extra delivery fee QAR 50.00 for this item" (per_line), AR + EN.
* Catalogue CSV import/export gains the two columns (optional on import). Existing products: 0 / `per_unit`.
* Admin product editor: money field + two radio choices ("For every unit" / "Once, whatever the quantity"), validated server side (decimal >= 0, max 100000, mode allow-list), audited like other product fields. The Oversized flag keeps its own hint.

## 4. Alternative phone number on checkout
The existing dashboard switch (Settings > Customer app > "Alternative phone number", `customer_app.alternate_phone_enabled`, published as `alternate_phone_enabled` in `GET /api/v1/app-config`)
must hide the field on the checkout page and the add-address form in the web store and in the customer app, and the server must ignore a submitted value while it is off. Verify
every place (web checkout, address drawer, app checkout, app address form, staff New order panel) and fix any gap.

## 5. Customer app product page
Flutter: `CustomScrollView` + `SliverAppBar` (`expandedHeight`, `FlexibleSpaceBar` with the image gallery as background, `pinned: true`, `stretch: true`, `stretchModes`),
`BouncingScrollPhysics` on every platform (Android included) so the customer scrolls the page up over the image and the image stays visible while the bar collapses.
