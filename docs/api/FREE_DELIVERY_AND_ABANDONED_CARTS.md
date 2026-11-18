# Delivery fee rule and the abandoned orders manager

## 1. Delivery fee: no free-delivery minimum

**Rule.** Every delivery order pays the delivery fee of its zone (plus oversized and express charges). Delivery is free **only** when
the customer applies a coupon code that belongs to a `free_delivery` promotion. There is no "free above X QAR" any more.

* Removed: the per-zone `free_delivery_threshold` (read, write, validation, admin field, API fields, seeders, docs) and the quote hint
  `free_delivery_progress`. Old app versions that look for the hint simply show nothing.
* Kept: `max_order_amount` (a different rule: the largest order a zone accepts). The minimum (`min_order_amount`) was removed later: see [CHECKOUT_FEES_V2.md](CHECKOUT_FEES_V2.md) section 2.
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
  (same numbers the customer sees), warnings (out of stock, price changed, zone maximum), and these actions:
  Send reminder (consent aware, uses the existing abandoned-cart message; it counts in the same per-cart limit as the automatic reminders), Place order for customer (copies the cart into the staff
  order panel at the checkout step; the customer's cart is converted when the order is placed), Discard cart (sets
  `status = 'abandoned'` so reminders stop; reversible, so an Undo toast instead of a confirm).
* Edits go through the same `CartService` rules as the customer app (stock, per-line limits, variants). A staff edit does **not**
  reset the customer's idle clock or the reminder counters. Every change is audited (`cart.staff_edited`, with before and after).
* The customer sees the changed cart the next time the app or the store loads the cart.
* Never trusts the browser for prices; rate limited; double submits blocked; EN and AR; accessible; works on phones.

### Automatic reminders (since 2026-11-18)

The card at the top of the page switches **automatic reminders** on and off and opens the **Reminder settings** window; the full guide is
[ABANDONED_CART_REMINDERS.md](../guides/ABANDONED_CART_REMINDERS.md). What changed for the API, the data and the code:

* **One engine.** The automatic reminders are the `abandoned_cart` customer journey (`JourneyService`, every minute by `journeys:tick`). The old
  `marketing:abandoned-carts` command (every 15 minutes, `CampaignService::remindAbandonedCarts`) and its settings `notifications.abandoned_cart_after_minutes`
  and `abandoned_cart_max_messages` are **gone**; the remaining `abandoned_cart_min_interval_hours` is the gap before a reminder sent *by staff*.
  The old command had no switch and sent reminders to every consenting customer; now **nothing is sent until the owner switches the card on** (it ships **off**, `journeys.is_enabled = false`).
* **`AbandonedCartReminders`** (the service behind the window) reads and saves the settings as the journey: rules in `journeys.settings`
  (`quiet_start`, `quiet_end`, `max_per_cart`, `max_per_day`, `attribution_days`, `defaults_version`), reminders 1 to 3 as the first three `journey_steps`
  (delay in minutes, channels, on/off) and their words as `journey_templates` (English and Arabic title and message, an optional `promotion_id` for the offer).
* **Counters.** Every reminder that goes out, automatic or by staff, does `carts.abandoned_notifications = abandoned_notifications + 1` and sets
  `abandoned_notified_at`; `CartService::touch` resets both when the customer changes the cart. `AbandonedCartService::reminderSummary` and the list read only these
  (the journey is no longer added on top). Migration `2026_11_18_100000_automatic_cart_reminders` copies reminders the journey had already sent onto the open carts, once.
* **Gates in the engine.** `AbandonedCartReminders::allowed()` (`NotificationRules::enabled('cart.abandoned')` and `enabled('campaign')`) before any run starts or message is
  sent, `channelAllowed()` per channel, consent and the customer's channel choices (`skipReason`), quiet hours (held until they end, dropped when more than 12 hours late),
  the cart read fresh at send time (still open, not emptied, not touched since the run started, not discarded, something purchasable), the per-cart limit, a 3 hour gap and the
  daily limit of messages.
* **Scale.** `journey_runs.next_at` (partial index on active runs) is the moment a run needs attention again; a pass reads only due runs (100 at most), leases each
  with one UPDATE, and finds runs that must end with one indexed query. Deliveries are unique per run, step and channel; one left `queued` by a dead pass is closed as `failed` (`interrupted`).
* **Audit.** `cart.reminder_sent` is written for staff reminders (`source = staff`) and automatic ones (`source = automatic`); switch and settings changes are
  `marketing.journey_enabled`, `marketing.journey_disabled` and `marketing.cart_reminders_saved`.
* **The cart window** shows the reminder history of the cart (`AbandonedCartService::present()['history']`): each reminder with its ways and the plain reason for any that did not go out, and the next automatic one.
* **Permissions.** Seeing the card and the results: `orders.view` (results narrowed to the viewer's branches). Switching and settings: `marketing.edit` granted company-wide.

