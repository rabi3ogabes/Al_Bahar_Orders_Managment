# Order operations: errors, permissions and queue flags

What the staff and driver apps (and the dashboard) can rely on after the integrity fixes. The rules behind them are in [DISPATCH.md](../operations/DISPATCH.md). Errors use the platform envelope: read the machine `code`, show `message` (already in the request's language).

## Error codes

| HTTP | `code` | When | What the app does |
|---|---|---|---|
| 409 | `delivery_cancelled` | a driver step (`accept`, `pickup`, `out-for-delivery`, `deliver`, `fail`) or a (re)assignment on a delivery whose order or part was cancelled | show the message, drop the job from the list |
| 409 | `order_partly_handed_over` | cancelling a whole order (`POST orders/{id}/cancel`) or approving a cancellation request (`POST cancellations/{id}/decide`, `approve: true`) when any part is dispatched, out for delivery, delivered, collected or partly handed over | the request stays `requested`; the manager cancels the parts still at the branch one by one, or declines the request |
| 409 | `cancellation_pending` | dispatch / out for delivery / delivered / pick-up / pickup hand-over while a customer's cancellation request is undecided | wait for the manager's decision |
| 409 | `cod_collection_required` | `POST fulfillments/{id}/transition` with `delivered` on a cash-on-delivery order whose cash is not recorded | use the driver's delivery step (proof and cash) |
| 422 | `collection_amount_mismatch` | a delivery's cash is not the delivery's `cod_amount`; `context.due` says what is due | collect exactly that amount |
| 403 | `branch_forbidden` | the step needs a right the user does not have at the part's branch (below) | |
| 403 | `approval_required` | removing the last item of a part (adjustments API) without `orders.approve` | ask a manager |
| 409 | `invalid_state_transition` | a driver cannot be given a job that is on the road; a `fail` with another reason than the first | |
| 422 | `pickup_details_mismatch` | the fallback hand-over: the order number / last four digits do not match an order waiting at this branch (never says which) | |
| 429 | `pickup_manual_locked` | five wrong tries for that order by that staff member; `context.minutes` | try again later |
| 422 | `pickup_reason_required` | the fallback hand-over without a reason | |

## Which right a step needs (`POST fulfillments/{id}/transition`)

| Step | Right at the part's branch |
|---|---|
| `accepted`, `preparing`, `ready` | `orders.edit` |
| `dispatched`, `out_for_delivery`, `delivered` | `deliveries.edit` (and, for `delivered`, the cash must already be recorded on a cash-on-delivery order) |
| `returned`, `cancelled` | `orders.approve` |

The route lets in anyone who holds one of `orders.edit`, `deliveries.edit`, `orders.approve`; the step's own right is then checked for the part's branch. Cancelling a part also cancels its open delivery (see below), and cancelling the last part cancels the order.

## Staff queue

`GET /api/v1/admin/dispatch/queue?stage=new|preparing|ready|out|failed|all&branch_id=` and `GET /api/v1/admin/dispatch/overview?branch_id=`:

- An online order that is not paid yet is **not listed and not counted**; it appears (and the new-order alert fires) when the payment arrives.
- Every queue row has `"type": "delivery" | "pickup"`.
- `include_pickup=1` (optional; `true`/`1`/`on`/`yes` count as on, anything else is off) also lists the branch's pickup parts and counts them: `ready` then includes `pickup_ready` parts, `new` / `preparing` include pickup parts that are pending / accepted / being prepared. `waiting_for_driver` always counts deliveries only. Without the flag the queue and the overview are exactly what they were before the flag existed.

## Driver channel and push

When a delivery is cancelled with its part, the driver's private channel (`drivers.{id}`, event `dispatch.updated`) carries the usual message with `status` and `delivery_status` `"cancelled"` and `assigned: true`; a driver who loses a job to another driver gets `status: "unassigned"`, `assigned: false` (unchanged). Both are also sent as a push in the language of each of the driver's phones (`data.event = "dispatch.updated"`, `data.job = "cancelled" | "removed"`), when Firebase is set up.

## Cash on delivery of an order from several branches

`cod_amount` of each delivery (`GET dispatch/driver/jobs`, `GET me/deliveries`) is that part's own share of what is still to be collected; a single-part order shows the whole amount as before. `POST deliveries/{id}/deliver` takes `cod: {tender, amount}` and the amount must equal that delivery's `cod_amount`.
