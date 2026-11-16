# Delivery fee rule and the abandoned orders manager

## 1. Delivery fee: no free-delivery minimum

**Rule.** Every delivery order pays the delivery fee of its zone (plus oversized and express charges). Delivery is free **only** when
the customer applies a coupon code that belongs to a `free_delivery` promotion. There is no "free above X QAR" any more.

* Removed: the per-zone `free_delivery_threshold` (read, write, validation, admin field, API fields, seeders, docs) and the quote hint
  `free_delivery_progress`. Old app versions that look for the hint simply show nothing.
* Kept: `min_order_amount` and `max_order_amount` (a different rule: the smallest/largest order a zone accepts).
* Database: the column `branch_delivery_zones.free_delivery_threshold` stays (nullable, unused). A migration sets it to NULL on every row
  so no stale numbers survive. A later release may drop the column.
* Promotions: a `free_delivery` promotion must require a coupon (`requires_coupon = true`). The Promotions form forces it and says why;
  the promotion engine ignores any automatic (`requires_coupon = false`) free-delivery promotion. (Live has no such promotions.)
* Clients: no "spend X more for free delivery" strip, bar or text anywhere (cart, checkout, drawer, product, home). The delivery fee line
  is always shown. When a free-delivery coupon is applied the fee line shows the fee struck through with "Free" and the code.

## 2. Abandoned orders manager (dashboard)

An abandoned order is a customer cart (`carts.status = 'active'`, at least one line) idle for at least 30 minutes
(`last_activity_at`). Staff can open it, fix it and either remind the customer or place the order for them.

* Page `admin.sales.abandoned` ("Abandoned orders", Sales menu). `orders.view` to see, `orders.create` to change anything.
* List: customer, phone, items, value, last activity ("2 h ago"), reminders sent, coupon chip. Filters kept in the URL: idle time
  (30 min / 1 h / 24 h / 7 days), search by name or phone, has coupon, branch. Newest idle first, keyset pagination, 25 per page.
* Side window per cart (bottom sheet on phones): lines with image, name, variant, unit price, quantity stepper, remove (with Undo),
  "Add item" search-and-add, coupon box (apply / remove, plain error text), delivery fee and totals from the **real quote engine**
  (same numbers the customer sees), warnings (out of stock, price changed, zone minimum), and these actions:
  Send reminder (consent aware, uses the existing abandoned-cart message), Place order for customer (copies the cart into the staff
  order panel at the checkout step; the customer's cart is converted when the order is placed), Discard cart (confirm; sets
  `status = 'abandoned'` so reminders stop).
* Edits go through the same `CartService` rules as the customer app (stock, per-line limits, variants). A staff edit does **not**
  reset the customer's idle clock or the reminder counters. Every change is audited (`cart.staff_edited`, with before and after).
* The customer sees the changed cart the next time the app or the store loads the cart.
* Never trusts the browser for prices; rate limited; double submits blocked; EN and AR; accessible; works on phones.
