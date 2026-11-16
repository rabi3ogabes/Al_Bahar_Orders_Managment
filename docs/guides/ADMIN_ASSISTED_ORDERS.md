# Admin-assisted orders ("New order" and the order cart panel)

Staff can take an order on behalf of a customer (phone, walk-in, WhatsApp) from the admin dashboard.

## What staff see
- **New order** page (`admin.orders.create`, Sales menu and a button on the Orders list): product browser on the left (live search by name or SKU in English and Arabic, category chips, stock badge for the selected branch, variant picker for multi-variant products, quantity stepper, out-of-stock disabled), the **order cart panel** docked on the right (a bottom sheet on phones).
- **Order cart button** (header and sidebar, live unit count). It opens the same panel as a drawer (bottom sheet on phones) over the current page. Esc and the browser Back button close it. "Open full page" is the New order page.
- **Add to order** icon on every active product row in Products: adds 1 unit with a toast and Undo. With no customer yet the panel opens to ask for one. Multi-variant products open the New order page filtered to that product.
- Panel steps: cart (customer, branch, lines, "you may also need" suggestions, live totals) then checkout (delivery or pickup, address with inline add, delivery/pickup slot, payment method, coupon, customer note, internal note) then success (order number, View order, Print invoice, New order).
- **Held orders**: every staff member can hold several drafts (one per customer), resume or discard them (confirm modal).
- Orders placed this way show "Placed by <staff> on behalf of the customer" on the order page and in the status history, and are written to the audit log (`order.placed` with `placed_by_staff_id`).

## Design decisions
1. **Staff drafts are separate carts.** `carts.status = 'staff_draft'` with `carts.staff_id`. The unique "one active cart per user" index, abandoned-cart journeys, campaigns and expiry all look at `status = 'active'`, so a draft never touches the customer's own cart. A draft may have no customer yet (`user_id` null). Checkout choices that are not cart columns (payment method, slots, notes) live in `carts.staff_options` (jsonb). Max 20 drafts per staff member; drafts hold no stock.
2. **Same engines as customers.** `CartService` gained cart-based methods (`addTo`, `updateIn`, `setOptionsOn`); the customer methods delegate to them. `CheckoutService::placeCart()` is the old `place()` made cart-aware; `place()` is unchanged for customers. Staff use the real `QuoteService` (prices, promotions, coupons, delivery fee and zone, slots, COD limits, loyalty, tax) and `CheckoutService` (advisory lock, quote hash, stock reservation, payment record, idempotency). Totals are identical to the customer quote for the same lines (covered by a test).
3. **Orders** get `source = 'admin'` and `orders.placed_by_staff_id`. Order history and audit actor is the staff member (`on_behalf_of` = customer in metadata). The internal note is saved as an internal order note; the customer note as `customer_notes`.
4. **Branch scope.** The staff member's scope for `orders.create` is derived server-side. A branch-restricted user can only pick their branches, and `QuoteService` receives `restrict_branch_ids` (never from the client) so stock and delivery come only from those branches. Global roles can use "Automatic" branch and splits across branches like customers.
5. **No price override.** Staff cannot set prices; all amounts come from the server. A manual discount permission was not added (see below).
6. **Customers.** Live search by name or phone returns id, name and masked phone only (min 2 characters, rate limited). Quick-create takes name, phone (normalised by `Phone`) and language; an existing account with the same normalised phone is reused (never a duplicate); marketing consent stays off.
7. **Payments.** COD and pay at pickup are confirmed orders. SADAD orders are pending payment (same payment window as customers); the success state shows the signed payment link (copy) and **Send to customer** (in-app and push, plus WhatsApp only when configured) through `NotificationService` event `payment.link`.
8. **Double submit.** The Place button is disabled while the request runs, the panel keeps one idempotency key per checkout attempt, and a repeated key returns the original order (also after the draft was converted).

## Security
- `orders.create` is checked in `StaffOrderService` on every action (route middleware only guards the page). Drafts are visible and editable only by their owner; a super admin can list and discard any draft but not edit it.
- Rate limits per staff member: edits 240/min, drafts 30/min, customer search 90/min, quick-create 10/min, place 10/min, send link 10/min.
- Views escape all output; the panel never receives prices from the browser; the quote hash must match the server quote (409 `quote_changed` otherwise).

## Permissions
`orders.create` already existed. It is now also granted to `order_manager`, `sales_employee` and `customer_support` (branch managers, admin and super admin already had it). **On existing environments run** `php artisan db:seed --class=RolesAndPermissionsSeeder --force` once (it only adds grants, never removes custom ones), and grant `orders.create` to any custom role that should take orders.

## Migration
`2026_01_01_002350_add_staff_order_drafts.php`: `carts.staff_id`, `carts.staff_options`, `orders.placed_by_staff_id`.

## Files
`app/Domain/Checkout/Services/StaffOrderService.php` (all rules), Livewire `app/Livewire/Admin/Sales/{OrderCreate,OrderCart,OrderCartButton}.php` with views `resources/views/livewire/admin/sales/order-*.blade.php`, language `lang/{en,ar}/neworder_admin.php`, tests `tests/Feature/Admin/StaffOrderTest.php`.

## Abandoned orders (Sales › Abandoned orders)

A customer cart (`carts.status = 'active'`, at least one line) idle for 30 minutes or more is an abandoned order. Staff open it, fix it and either remind the customer or place the order for them. Route `admin.sales.abandoned`, Livewire `App\Livewire\Admin\Sales\AbandonedCartsIndex` (the window logic is the trait `AbandonedCartActions`), rules in `App\Domain\Checkout\Services\AbandonedCartService` (the "what is abandoned" query and the menu count live in `App\Domain\Checkout\Support\AbandonedCarts`), tests `tests/Feature/Admin/AbandonedCartsTest.php`, language `lang/{en,ar}/abandoned_admin.php`.

### What staff see
- **List**: KPI strip (abandoned carts now, value waiting at the prices the customers saw, reminded, with a coupon), filters kept in the URL (idle for at least 30 min / 1 h / 24 h / 7 days, search by name or phone, has coupon, branch), newest idle first, 25 per page with keyset paging (a cursor, never OFFSET), table on tablets and desktops, cards on phones. A cart older than the cart lifetime (`platform.checkout.cart_ttl_days`, 30 days) has expired and is not listed. The menu entry shows a small count of carts idle for 30 minutes or more (cached for a minute). There is deliberately no "recovered" figure: a customer's return resets the reminder bookkeeping on the cart, so carts alone cannot say which orders a reminder won.
- **Row actions** (⋯ menu: a popover on tablets and desktops, a bottom sheet on phones): *Open*, *Send reminder*, *Discard cart* (no confirm; a toast with *Undo*).
- **Side window "Cart of <customer>"** (`?cart=<id>` keeps it reloadable and shareable): customer (name, phone, language), idle time, reminders sent, branch and delivery zone (never the street or building); the lines with image, variant, unit price, a quantity stepper (1 to `max_quantity_per_line`), stock and out-of-stock / unavailable flags, remove with *Undo*; **Add item** (search by name or SKU, variants included, price and stock at the cart's branch); the coupon box (real validation, plain-language error under the field); totals and delivery fee from the real `QuoteService` (a free-delivery coupon shows the fee struck through with *Free* and the code); notices from the quote; actions *Place order for customer*, *Send reminder*, *Discard cart*, *Close*.
- If the customer comes back, orders or the cart is discarded while a window is open, the window says so in plain words and nothing is saved.

### Rules
1. **Same rules as the customer app.** Staff edits call the same `CartService` methods (`addTo`, `updateIn`, `setOptionsOn`) with `recordActivity: false`. Per-line limit, variants, price rules and coupon validation are identical; stock is flagged, not enforced, exactly as for the customer. The default (`recordActivity: true`) is unchanged for customers.
2. **A staff edit never resets the idle clock.** `last_activity_at`, `abandoned_notified_at`, `abandoned_notifications` and `expires_at` stay as they were, so reminders and the customer journeys carry on. The customer is not notified of staff edits. Their own next edit resets everything as always.
3. **No lost updates.** Every edit runs in one transaction that locks the cart row (the customer's own add / update also lock it now), re-checks the cart is still open, idle and in the staff member's scope, and compares the **stamp** (a fingerprint of lines, quantities, coupon, branch, address and type) of what the staff member was looking at. A mismatch answers "the cart changed a moment ago, nothing was saved, it was refreshed" (HTTP 409 style); a customer who is shopping again answers "this cart is no longer abandoned".
4. **Permissions** are checked in the service on every call: `orders.view` to see, `orders.create` to change (reminder, discard, edits, place order). Branch-restricted staff see a cart when its preferred branch is in their scope, or, while it has no branch yet, when the delivery zone of its address is served (active zone setting) by a branch in their scope; with neither signal only company-wide roles see it (`App\Domain\Checkout\Support\CartVisibility`). The branch filter can only narrow that scope.
5. **Rate limits** per staff member: edits 240 / minute, reminders 20 / minute.
6. **Audit**: `cart.staff_edited` (before and after: lines by SKU and quantity, coupon, and what changed), `cart.discarded`, `cart.restored` (Undo), `cart.reminder_sent`, `cart.adopted` (place order for the customer).

### Send reminder
Uses the existing `cart.abandoned` message through `NotificationService` (push, the in-app inbox and WhatsApp where the customer opted in, in their language). `CampaignService::reminderStatus()` decides first, with the same limits as the automatic reminders (Settings › Notifications): the customer agreed to marketing messages, fewer than `abandoned_cart_max_messages` reminders for this cart, and nothing within `abandoned_cart_min_interval_hours` of the last message to them, whether it was a reminder, a journey message or a message staff sent by hand. The window and the toast say which rule stopped it. A sent reminder moves the cart's reminder counters (so the automatic reminders do not repeat it) but not its idle time.

### Discard cart and Undo
*Discard cart* sets `status = 'abandoned'`: reminders and journeys stop, the customer gets a fresh empty cart next time. It is reversible, so there is no "are you sure": a toast with **Undo** puts the cart back as it was (idle time and reminder history included). If the customer already started a new cart with items, the cart is not restored (plain message); an empty new cart is replaced.

### Place order for customer
`StaffOrderService::adoptCart($customerCart, $staff)` copies the lines, coupon, loyalty points, branch, address and delivery or pickup choice into a **staff draft** (see "Design decisions" above) and makes it the staff member's current draft; the order cart panel opens at the **checkout** step. The customer's own cart is left untouched until the order is placed. Adopting the same cart again returns the draft that already exists (remembered in `carts.staff_options.source_cart_id`), and the 20-drafts limit applies. When the order is placed, `CheckoutService::placeCart()` marks the draft **and** the customer's own cart `converted` with the order id, so the cart leaves the list, reminders stop and the customer does not find the same items waiting. If the customer already ordered from that cart meanwhile, placing the draft is refused ("the customer already ordered from this cart") so nobody is charged twice.

## Not included
- Manual discount (`orders.discount` permission with reason): not added, to keep prices server-only.
- Express delivery toggle and loyalty point redemption in the staff checkout.
- Automatic clean-up of old empty drafts (they hold no stock; staff can discard them).
- Suggestions only list single-variant products (one tap add).
