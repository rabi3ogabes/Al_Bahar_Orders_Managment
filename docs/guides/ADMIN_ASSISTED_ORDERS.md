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

## Not included
- Manual discount (`orders.discount` permission with reason): not added, to keep prices server-only.
- Express delivery toggle and loyalty point redemption in the staff checkout.
- Automatic clean-up of old empty drafts (they hold no stock; staff can discard them).
- Suggestions only list single-variant products (one tap add).
