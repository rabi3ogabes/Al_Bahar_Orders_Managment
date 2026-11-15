# Order adjustments (missing items found while preparing)

While an order is being prepared the branch can find that an item is short. The staff member reports it in the staff app (or the dashboard): per item they **reduce the quantity**, **replace the item** with another one the branch has, or **remove the item**. The customer is always told (in-app notification + push + the order page) and the invoice PDF is revised.

This file is the contract between the API, the staff app and the customer app. The service is `App\Domain\Orders\Services\OrderAdjustmentService`.

## Rules (decisions, change them here and in code together)
- Allowed only while the branch's fulfillment is `accepted` or `preparing` (not before it is accepted, not once it is ready, picked up or handed over). Requires `orders.edit` for the fulfillment's branch.
- One request = one **revision** (any number of item changes + one reason + one notification + one invoice revision). Revisions are numbered 1, 2, … per order and kept for audit.
- A **replacement never costs the customer more** than what it replaces: the replacement's unit price is `min(its current price, the original unit price)` per unit. So an adjustment never increases the order total.
- Only stock the branch really has can be offered as a replacement (`available > 0`); the replacement is reserved for the order in the same transaction.
- Reduced / removed quantity: the reservation is released. When the reason is `out_of_stock` or `not_found` the branch's on-hand count is also reduced by the missing quantity (a stock-count adjustment with the revision as reference) so the shop does not keep selling a phantom unit.
- Totals are recalculated per line (discount and tax follow the line's remaining share); delivery and service fees are not changed by a shortage (the customer is never penalised for it). Loyalty points earned follow the new total.
- **Paid online orders** (total drops): a refund of the difference is created as a normal refund request that a finance user approves; the response says `needs_approval`. **Cash on delivery / pay at pickup**: the amount due drops, nothing to refund.
- If every item of the branch's parcel is removed the fulfillment is cancelled; if that was the whole order, the order is cancelled (existing cancellation rules incl. stock release and refund).
- The customer is notified in the same transaction commit: event `order.items_changed` (in-app always; push per the customer's order-updates preference; both languages). The order timeline gets a customer-visible entry. The invoice (if already issued) gets a new revision; an invoice downloaded later is built from the current state. The PDF of a revised invoice says "REVISED INVOICE · revision N" and lists what changed.
- Everything is audit-logged (actor, branch, before/after).

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
`blocked_reason`: `status` (not accepted/preparing) | `none`.

### `GET /api/v1/admin/fulfillments/{id}/substitutes?search=&limit=20`
Variants the branch has in stock (excluding the items already in the parcel): `[{ "variant_id","product_id","name","variant_name","sku","image_url","unit_price":Money,"available":int }]`.

### `POST /api/v1/admin/fulfillments/{id}/adjustments`
```json
{ "changes": [
    { "order_item_id": "uuid", "action": "reduce",  "new_quantity": 1 },
    { "order_item_id": "uuid", "action": "remove" },
    { "order_item_id": "uuid", "action": "replace", "variant_id": "uuid", "quantity": 2 } ],
  "reason_code": "out_of_stock", "note": "Only one left on the shelf", "preview": false }
```
`preview: true` computes and returns everything below without writing anything (the staff app shows it before the final tap).
```json
{ "data": {
  "applied": true, "preview": false,
  "revision": { "id": "uuid", "number": 1 },
  "lines": [ { "order_item_id":"uuid","action":"reduce","name":"Oslo sofa","variant_name":"Grey","from_quantity":2,"to_quantity":1,"replacement":null },
             { "order_item_id":"uuid","action":"replace","name":"Oslo lamp","from_quantity":1,"to_quantity":0,"replacement":{"variant_id":"uuid","name":"Oslo lamp (large)","variant_name":null,"quantity":1,"unit_price":{"amount":"250.00","currency":"QAR"}} } ],
  "before": { "subtotal": Money, "discount_total": Money, "tax_total": Money, "grand_total": Money },
  "after":  { "subtotal": Money, "discount_total": Money, "tax_total": Money, "grand_total": Money },
  "difference": Money,                       // after − before (zero or negative)
  "refund": { "amount": Money, "needs_approval": true } | null,
  "fulfillment_cancelled": false, "order_cancelled": false, "customer_notified": true } }
```
Errors (JSON envelope with `code`): `403`; `409 adjustment_not_allowed` (status); `422 validation` with codes `no_change`, `quantity_exceeds` (new_quantity above the current one / below 0 for reduce), `duplicate_item`, `substitute_unavailable` (not enough stock / not stocked at the branch), `item_not_in_fulfillment`.

## Customer API changes
`GET /api/v1/orders/{id}` and the list payload gain:
- `was_adjusted: bool`;
- per item `adjustment`: `null` or `{ "kind": "reduced|removed|replaced|replacement", "original_quantity": 2, "original_name": "…", "reason_code": "out_of_stock" }` (`replaced` = this line was swapped out, quantity now 0; `replacement` = this line replaced another, with `replaces_name`). Removed/replaced lines stay in `items` with `quantity: 0` so the app can show them struck through;
- `revisions: [ { "number": 1, "at": ISO, "reason_code": "out_of_stock", "note": "…", "branch": "Al Markhiya", "lines": [ same as above, text-ready ], "difference": Money } ]`.
Notification event `order.items_changed`: title "Your order was updated" / body naming the changes and the new total, `deep_link` `/orders/{id}`. The order timeline entry kind is `items_changed`.
`GET /api/v1/orders/{id}/invoice` returns the latest revision.

## Public config
`GET /api/v1/app-config` gains `ops: { "status_style": "buttons" | "slide" }` (Settings → Checkout & fees → "Staff and driver apps: how to change an order status"): how the staff and driver apps ask for a status change. `buttons` = today's buttons; `slide` = slide to confirm.
