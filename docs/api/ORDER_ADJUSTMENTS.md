# Order adjustments (missing items found while preparing)

While an order is being prepared the branch can find that an item is short. The staff member reports it in the staff app (or the dashboard): per item they **reduce the quantity**, **replace the item** with another one the branch has, or **remove the item**. The customer is always told (in-app notification + push + the order page) and the invoice PDF is revised.

This file is the contract between the API, the staff app and the customer app. The service is `App\Domain\Orders\Services\OrderAdjustmentService`; the staff app and the dashboard use the same one, so they can never disagree.

## Rules (decisions, change them here and in code together)
- Allowed only while the branch's fulfillment is `accepted` or `preparing` (not before it is accepted, not once it is ready, picked up or handed over) and the customer has not asked to cancel and is still waiting for a decision. Requires `orders.edit` for the fulfillment's branch.
- One request = one **revision** (any number of item changes + one reason + one notification + one invoice revision). Revisions are numbered 1, 2, … per order and kept for audit (append-only).
- A request describes the state the staff member sees: if any change of it is already in place (a line already removed, a quantity already reached) the whole request is refused with `no_change`, nothing is applied. Item changes are checked in order and the first problem is reported with its position (`context.index`).
- **Reduce** `new_quantity` must be 0 … current − 1 (`0` is the same as `remove`; equal to the current quantity is `no_change`). **Replace** swaps the whole line for a substitute and takes `quantity` 1 … the replaced quantity (never more than was ordered, so the total can never go up). A partly replaced line (keep 2, replace 1) is not supported: reduce, or replace the line.
- A **replacement never costs the customer more** than what it replaces: the replacement's unit price is `min(its current price, the original unit price)` per unit, and it carries the original line's discount per unit scaled by that ratio. So an adjustment never increases the order total.
- Only stock the branch really has can be offered as a replacement (`available > 0`, sellable at that branch, not a variant already in the parcel); the replacement is reserved for the order in the same transaction.
- Reduced / removed quantity: the reservation is released. When the reason is `out_of_stock` or `not_found` the branch's on-hand count is also reduced by the missing quantity (a `correction` stock movement referencing the revision, never below what other orders have reserved) so the shop does not keep selling a phantom unit.
- Totals are recalculated per line (discount share and tax follow the units that stay; a fraction of a dirham is rounded in the customer's favour); delivery and service fees (and the cash-on-delivery fee stored on orders placed while it still existed) are not changed by a shortage (the customer is never penalised for it). **One fee follows the goods: the extra delivery fee of a bulky product** (`fee_breakdown.extra_delivery`, see [CHECKOUT_FEES_V2.md](CHECKOUT_FEES_V2.md) section 3). Units that are reduced, removed or replaced by another product take their fee off the parcel's delivery fee: a `per_unit` product keeps `fee_each` × the units that stay, a `per_line` product keeps its fee while any unit of it stays and loses it when the product is gone (a substitute that is a variant of the same product counts as staying; a substitute of another product adds no fee: a replacement never costs more). A fee never goes up. The order's delivery fee total, the parcel's `delivery_fee`, `fee_breakdown.delivery[].fee/extra` and `fee_breakdown.extra_delivery` all follow, a free-delivery coupon's waiver shrinks with the fee (so the customer still pays nothing for delivery), the total and the refund request use the new numbers, and the revised invoice shows the new sub-line. An order without stored extra fees (placed before the feature) is untouched. Loyalty points **earned** follow the new total (earned when the order completes). Points **redeemed** that the smaller order can no longer absorb are handed back to the customer (never wasted); the total never goes below zero.
- **Paid online orders** (total drops): a refund of the difference (on top of refunds already asked for) is created as a normal refund request (`reason_code` `order_adjustment`) that a finance user approves; the response says `needs_approval`. No money moves until finance approves it. **Cash on delivery / pay at pickup**: the amount due (the payment row and the delivery's `cod_amount`) drops, nothing to refund.
- If every item of the branch's parcel is removed the fulfillment is cancelled (its delivery fee comes off the order, an open delivery is cancelled); if that was the whole order, the order is cancelled with the existing cancellation rules (stock and slots released, everything paid refunded, loyalty and coupon use reversed) and its totals are left as placed (`after` in the response shows zero).
- The customer is notified in the same transaction commit: event `order.items_changed` (in-app always; push per the customer's order-updates preference; both languages). The order timeline gets a customer-visible entry (kind `items_changed`). The invoice (if already issued) gets a new revision under the same number; an invoice downloaded later is built from the current state. The PDF of a revised invoice says "REVISED INVOICE · revision N" and lists what changed.
- Everything is audit-logged (actor, branch, before/after).
- **A retried request is replay-safe** (see below).

## Replay safety (a lost answer, a second tap)
The staff app may tap again when it never saw the answer. `POST …/adjustments` (not `preview`) that is **identical** to the revision just made — same staff member, same fulfillment, same changes (in any order), same `reason_code` and `note` — is answered with **that revision's original result**: HTTP `200`, `applied: true`, `replayed: true`, and nothing is written, refunded, moved in stock or notified again. It holds while **all** of these are true:
- the revision is the order's latest and was made within the last **10 minutes** (`OrderAdjustmentService::REPLAY_WINDOW_MINUTES`);
- the order's money still equals that revision's `after` (or, for a revision that cancelled the order, the order is still cancelled). The parcel's status may have moved on (ready…): the original answer is still true.
A different person, other changes, another reason or note, a later time or an order that moved on is a **new** request and meets the normal rules, so `no_change` still means "this is already in place". Every first answer carries `replayed: false`. The request is identified by a sha256 of its normalised form stored on the revision (`order_revisions.request_hash`); nothing else about it is kept. Previews are never replayed.

## Error shape
Errors use the platform's JSON envelope; read the machine code from the **top-level `code`**, show `message` (already translated into the request's language, `Accept-Language`), and for validation failures read `errors.<field>`:
```json
{ "message": "The new quantity must be between 0 and the quantity now on the order.", "code": "quantity_exceeds",
  "context": { "index": 0, "order_item_id": "uuid" }, "request_id": "…" }
```
| HTTP | `code` | When |
|---|---|---|
| 403 | `branch_forbidden` / `forbidden` | no `orders.edit` on the fulfillment's branch |
| 404 | `not_found` | unknown or malformed fulfillment id |
| 409 | `adjustment_not_allowed` | the parcel is not `accepted`/`preparing` (or a cancellation request is waiting) |
| 422 | `no_change` | the change is already in place (a repeat that is not replayable, a line already removed or at that quantity) |
| 422 | `quantity_exceeds` | reduce: `new_quantity` above the current one or below 0; replace: `quantity` below 1 or above the replaced quantity |
| 422 | `duplicate_item` | the same `order_item_id` twice in `changes` |
| 422 | `substitute_unavailable` | not enough stock / not stocked or sellable at the branch / unknown variant / a variant already in the parcel |
| 422 | `item_not_in_fulfillment` | the id is not a line of this branch's parcel (another branch's, another order's, made up) |
| 422 | `validation_failed` | malformed body: `errors.changes.0.variant_id`, `errors.reason_code`, … (`changes` 1–50 items; `reason_code` one of the five; `note` ≤ 500) |
The item-level codes carry `context.index` (position in `changes`) and `context.order_item_id`. Every one of the six adjustment codes has an English and an Arabic message in `lang/*/errors.php`.

## Staff / admin API (staff token, `orders.edit` on the branch)

### `GET /api/v1/admin/fulfillments/{id}/adjustments/context`
```json
{ "data": {
  "can_adjust": true, "blocked_reason": null,
  "reasons": ["out_of_stock","not_found","damaged","quality","other"],
  "items": [ { "order_item_id": "uuid", "product_id": "uuid", "variant_id": "uuid", "sku": "SKU-1", "name": "Oslo sofa", "variant_name": "Grey",
               "image_url": null, "quantity": 2, "unit_price": {"amount":"1000.00","currency":"QAR"}, "line_total": {"amount":"2000.00","currency":"QAR"},
               "adjustment": null } ],
  "totals": { "grand_total": {"amount":"2275.00","currency":"QAR"}, "payment_method": "cod", "payment_status": "pending" }
} }
```
`blocked_reason`: `null` when it can be adjusted, `"status"` when not (not accepted/preparing). `items` are the lines still in the parcel (quantity > 0); a line already changed carries `adjustment: { kind: "reduced|removed|replaced|replacement", original_quantity, reason_code }`.

### `GET /api/v1/admin/fulfillments/{id}/substitutes?search=&limit=20`
Variants the branch has in stock (excluding the items already in the parcel; `limit` 1–50): `[{ "variant_id","product_id","name","variant_name","sku","image_url","unit_price":Money,"available":int }]`. `unit_price` is the current price; the customer pays `min(that, the original unit price)`.

### `POST /api/v1/admin/fulfillments/{id}/adjustments`
```json
{ "changes": [
    { "order_item_id": "uuid", "action": "reduce",  "new_quantity": 1 },
    { "order_item_id": "uuid", "action": "remove" },
    { "order_item_id": "uuid", "action": "replace", "variant_id": "uuid", "quantity": 2 } ],
  "reason_code": "out_of_stock", "note": "Only one left on the shelf", "preview": false }
```
`preview: true` computes and returns everything below without writing anything (the staff app shows it before the final tap); a preview and the apply that follows give the same `lines`, `before`, `after`, `difference`, `refund` and flags. A preview has `revision: { id: null, number: <next> }`.
```json
{ "data": {
  "applied": true, "preview": false, "replayed": false,
  "revision": { "id": "uuid", "number": 1 },
  "lines": [ { "order_item_id":"uuid","action":"reduce","name":"Oslo sofa","variant_name":"Grey","from_quantity":2,"to_quantity":1,"replacement":null },
             { "order_item_id":"uuid","action":"replace","name":"Oslo lamp","variant_name":null,"from_quantity":1,"to_quantity":0,"replacement":{"variant_id":"uuid","name":"Oslo lamp (large)","variant_name":null,"quantity":1,"unit_price":{"amount":"250.00","currency":"QAR"}} } ],
  "before": { "subtotal": Money, "discount_total": Money, "tax_total": Money, "grand_total": Money },
  "after":  { "subtotal": Money, "discount_total": Money, "tax_total": Money, "grand_total": Money },
  "difference": Money,                       // after − before (zero or negative)
  "refund": { "amount": Money, "needs_approval": true } | null,
  "fulfillment_cancelled": false, "order_cancelled": false, "customer_notified": true,
  "invoice_revision": 2,                     // the invoice revision this issued, null when no invoice existed yet
  "loyalty_points_returned": 0 } }
```
A `reduce` to 0 is reported as `action: "remove"`. `customer_notified` is false only when the customer could not be told (no account, inactive, or the notification failed: the adjustment still stands).

### Staff queue: `GET /api/v1/admin/dispatch/queue`
Each row lists the parcel as it is **now**: `items` has only the lines still to be prepared and delivered (quantity > 0; removed and swapped-out lines are not listed), `items_count` is their unit total. Item shape (no prices):
`{ "id", "order_item_id" (same value as id: the id the adjustments API takes), "name", "variant_name", "sku", "quantity", "adjustment": null | { "kind": "reduced|removed|replaced|replacement", "original_quantity": int } }`.
Row additions: `was_adjusted` (bool), `adjustment_count` (int), `can_adjust` (bool: the fulfillment is `accepted` or `preparing`). Use `GET …/adjustments/context` for prices and the reasons.

## Customer API changes
`GET /api/v1/orders/{id}` and the list payload gain:
- `was_adjusted: bool` (the list needs no extra query per order);
- per item `adjustment`: `null` or `{ "kind": "reduced|removed|replaced|replacement", "original_quantity": 2, "original_name": "…", "reason_code": "out_of_stock", "reason_label": "Out of stock", "replaces_name": null, "replaced_by_name": null }` (`replaced` = this line was swapped out, quantity now 0, `replaced_by_name` names the substitute; `replacement` = this line took the place of `replaces_name`/`original_name`). Removed/replaced lines stay in `items` with `quantity: 0` and `line_total: 0.00` so the app can show them struck through; a replacement is listed right after the line it replaced;
- `revisions: [ { "number": 1, "at": ISO, "reason_code": "out_of_stock", "reason_label": "Out of stock", "note": "…", "branch": "Al Markhiya", "lines": [ { "order_item_id","action","name","variant_name","from_quantity","to_quantity","text":"Oslo sofa: 2 → 1","replacement":{variant_id,name,variant_name,quantity,unit_price:Money}|null } ], "difference": Money, "total_before": Money, "total_after": Money, "order_cancelled": false } ]` (`[]` for an order nobody adjusted; texts and labels in the request's language).
The order timeline (`GET /orders/{id}/timeline`) gains entries of kind `items_changed` (status `items_changed`, label "Items updated"). "Order again" adds only lines with quantity > 0 (a replacement is added, a removed line is not).
Notification event `order.items_changed` (category transactional, channels in-app + push, not a live-card event): title "Your order was updated" / body "Order AB100123 was updated: <changes>. Reason: <reason>. New total: QAR 1,275.00." plus a refund sentence when money is coming back, or the cancellation sentence when nothing was left. `data.deep_link` is `albahar://orders/{id}` (the app resolves it to `/orders/{id}`, like every other order event). The push carries the text in every language, each phone shows its own.
`GET /api/v1/orders/{id}/invoice` returns the latest revision; its file name is `INV-<order number>.pdf`, and `INV-<order number>-rev<N>.pdf` from the second revision.

## Public config
`GET /api/v1/app-config` gains `ops: { "status_style": "buttons" | "slide" }` (Settings → Checkout & fees → "Staff and driver apps: how to change an order status"): how the staff and driver apps ask for a status change. `buttons` = today's buttons; `slide` = slide to confirm. Anything stored that is not one of the two is served as `buttons`; the document is public and cached for a minute.
