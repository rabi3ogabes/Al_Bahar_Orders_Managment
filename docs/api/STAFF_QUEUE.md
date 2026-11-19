# Branch staff queue: the order clock, pickups, cancellation requests, paging, needs attention

What the **Al Bahar Branch** (staff) app reads and does at the counter, after the Live Operations work (see [LIVE_OPERATIONS.md](../guides/LIVE_OPERATIONS.md)). All paths are under `/api/v1/admin`, need a staff token, and answer the usual envelope (`{ "data": ... }`, errors `{ message, code, errors, context }`; `message` is already in the request's language).

**Nothing that an older app build reads was removed or renamed.** Every field below is new and optional for a client; a request that sends none of the new parameters gets what it always got.

| Endpoint | Needs | What it is |
|---|---|---|
| `GET dispatch/queue` | `orders.view` | The branch's orders by stage; every row now carries its clock (changed, below) |
| `GET dispatch/overview` | `orders.view` | The counts and the person's branches and rights (changed, below) |
| `GET dispatch/attention` | `orders.view` | **New.** The top of the "Needs attention" inbox |
| `POST fulfillments/{id}/pickup-reissue` | `pickup.edit` | **New.** A new pickup pass |
| `POST pickup/manual-handover` | `pickup.approve` | **New.** A manager hands over without the pass |
| `POST fulfillments/{id}/pickup-ready`, `POST pickup/verify`, `POST pickup/{reservation}/handover`, `POST cancellations/{id}/decide`, `POST fulfillments/{id}/transition` | as before | Unchanged; the app uses them for the pickup counter and the cancellation decision |

---

## `GET dispatch/queue`

Query: `stage` (`new|preparing|ready|out|failed|all`, default `all`), `branch_id`, `include_pickup` (as before), and new:

| Parameter | Meaning |
|---|---|
| `type` | `delivery` or `pickup`. `pickup` lists only the branch's pickup parts (it implies `include_pickup`; with `stage=all` that is new + preparing + ready for pickup). Anything else is a 422. |
| `page`, `per_page` | Paging. `per_page` 1 to 100, default **50**; `page` from 1. |

An app that sends **neither** `page` nor `per_page` gets the first 100 rows, exactly as before (so an older build never loses rows 51 to 100); `meta` is still sent.

### Row

The old keys are unchanged (`fulfillment_id`, `order_id`, `type`, `seq`, `status`, `number`, `placed_at`, `customer_name`, `customer_phone`, `alternate_phone`, `address`, `items_count`, `items`, `was_adjusted`, `adjustment_count`, `can_adjust`, `payment_method`, `grand_total`, `scheduled_for`, `delivery`). New:

```json
{
  "clock": {
    "state": "amber",            // ok | amber | red
    "age_minutes": 22,           // the minutes that count (opening hours only for the branch's own steps)
    "label": "22 min",           // the same in words, in the request's language
    "kind": "pending",           // what it waits for: pending, preparing, ready, driver, pickup, cancellation; none = nobody has to act; 
    "wall_minutes": 22,          // real minutes that passed
    "paused": false,             // true while the branch is closed
    "amber_at": 5,               // the limits in the same minutes (null when nobody has to act):
    "red_at": 15                 //   the app moves the age on by itself between two reads
  },
  "cancellation_request": {      // null when none is open
    "id": "…", "status": "requested", "reason": "Changed my mind", "requested_at": "2026-11-19T10:12:00+03:00"
  },
  "payment_status": "pending",
  "amount_due": "120.00",        // what is still to collect at the counter (grand total less what was paid), "0.00" when nothing
  "pickup": {                    // only on a pickup row
    "status": "ready",           // the pass: ready | partially_collected | …
    "token_expires_at": "2026-11-22T10:12:00+03:00",
    "expired": false
  }
}
```

- `clock` is computed with the same `OpsClock` and limits as the dashboard (Settings > Operations clock). A part nobody has to act on (a driver has it, it is out) still has one: `kind` `none`, `state` `ok`, the minutes since it last moved. A part waiting on the customer's cancellation request is measured from the request (`kind` `cancellation`).
- `cancellation_request` is only ever an **open** request (`status` `requested`). While it is open, dispatch, driver pick-up and pickup hand-over answer `409 cancellation_pending`; preparing and ready stay allowed.
- **Order:** the longest wait first inside a stage (a new part from when it was placed, a part being prepared from when it was accepted, a ready one from when it became ready), then by creation, then by id, so paging never repeats or skips a row.

### `meta`

```json
{ "page": 1, "per_page": 50, "has_more": true, "next": 2 }
```

`next` is the next page number, `null` on the last page.

---

## `GET dispatch/overview`

Unchanged keys (`branch`, `new`, `preparing`, `ready`, `out_for_delivery`, `failed`, `waiting_for_driver`, `drivers_on_duty`, `drivers_total`). New:

```json
{
  "pickup": { "new": 2, "preparing": 1, "ready": 3 },
  "branches": [ { "id": "…", "name": "Markhiya" }, { "id": "…", "name": "Wakra" } ],
  "can": { "orders.edit": true, "orders.approve": true, "deliveries.assign": true, "deliveries.edit": true,
           "pickup.view": true, "pickup.edit": true, "pickup.approve": true, "payments.create": true }
}
```

- `pickup` counts the branch's pickup parts (an unpaid online order is left out here too). The delivery counts above are deliveries only, as before.
- `branches` is every branch the person may see orders of, by sort order. The app shows its branch picker only when there are **two or more**. It then sends `branch_id` on the queue, the overview, the attention list, the drivers and the fleet.
- `can` is what the person may do at **this** branch (`branch_id` or the first one): a manager of one branch is not a manager of another. A branch the person does not have is a `403`.

---

## `GET dispatch/attention`

Query: `branch_id` (optional; a branch the person has nothing to do with answers an empty list, never everything).

The top of the dashboard's **Needs attention** inbox for the signed-in person: it is the same service (`AttentionFeed`), so the same scoping and permissions. At most **20** lines, worst first; `counts` covers all of the inbox.

```json
{ "data": {
  "items": [ {
    "id": "unaccepted:3f1c…",     "kind": "unaccepted",
    "target": "open_order",       // open_order | assign_driver | reissue_pass | dashboard
    "in_app": true,
    "subject": "AB100123",        "subject_is_number": true,
    "label": "Waiting to be accepted", "detail": "Noor · 3 items", "note": null,
    "state": "red", "age_minutes": 20, "age_label": "20 min",
    "branch_id": "…", "branch_code": "AAA",
    "order_id": null,             "fulfillment_id": "3f1c…",   // what the app opens (order_id on a cancellation line)
    "mine": false,
    "call": { "label": "Call customer", "phone": "+974…" },
    "hint": null                  // "This is handled on the dashboard." when target is dashboard
  } ],
  "counts": { "total": 31, "red": 4, "amber": 9, "ok": 18, "by_kind": { "unaccepted": 12, "stuck": 6, "pickup_waiting": 3, "refund": 2 } },
  "truncated": false,
  "branch_id": null
} }
```

| `kind` | `target` |
|---|---|
| `unaccepted`, `stuck`, `delivery_failed`, `cancellation` | `open_order` |
| `undispatched`, `driver_silent` | `assign_driver` |
| `pickup_waiting` | `reissue_pass` (when the pass can be re-issued and the person may), else `open_order` |
| `refund`, `cash`, `queue`, `campaign` | `dashboard` (no screen in the app) |

---

## Pickup counter

| Call | Body | Answer |
|---|---|---|
| `POST fulfillments/{id}/pickup-ready` | none | `{ status, token_expires_at }` (the pass is issued) |
| `POST fulfillments/{id}/pickup-reissue` | `reason` (optional, 200) | `{ status, token_expires_at, message }`. The old pass stops working at once; the customer is told; audit `pickup.pass_reissued`. `409 pickup_not_ready` when the part is not waiting for the customer. |
| `POST pickup/verify` | `code`, `branch_id` | `200` `{ result: "success", reservation_id, order: { number, customer_name, payment_method, payment_status, amount_due: { amount, currency, formatted } }, items: [ { id, name, variant, sku, quantity, handed_over } ] }`. A refused pass is **`422`** with the same shape and `result` one of `invalid_token`, `expired`, `already_used`, `wrong_branch`, `not_ready` (no `reservation_id`, no order). |
| `POST pickup/{reservation}/handover` | `items` (order item id to quantity now; empty = everything left), `collector_name`, `collection: { tender: cash\|card_terminal, amount, receipt_reference }` | `{ status: collected\|partially_collected }`. A pay-at-pickup order that is not settled needs `collection` (else `409 pickup_payment_required`); the amount is what is due. |
| `POST pickup/manual-handover` | `branch_id`, `order_number`, `phone_last4` (exactly 4 digits), `reason`, optional `items`, `collector_name`, `collection` | `{ status, message }`. Needs `pickup.approve`. A wrong detail is `422 pickup_details_mismatch` (it never says which); five wrong tries by the same person for the same order lock it: `429 pickup_manual_locked` (`context.minutes`). Without a reason: `422 pickup_reason_required`. Audit `pickup.manual_handover`. |

While a cancellation request is open every hand-over answers `409 cancellation_pending`.

## Cancellation decision

`POST cancellations/{cancellation_request.id}/decide` with `{ approve: bool, note?: string(500) }`, needs `orders.approve` at **every** branch of the order. Approving cancels the order (`409 order_partly_handed_over` when goods already left: the request stays open). A request somebody else already decided is `409 invalid_state_transition`.

## Error codes the app puts into plain words

`order_awaiting_payment`, `cancellation_pending`, `invalid_state_transition`, `branch_forbidden`, `approval_required`, `order_partly_handed_over`, `delivery_cancelled`, `pickup_not_ready`, `pickup_payment_required`, `pickup_details_mismatch`, `pickup_manual_locked`, `pickup_reason_required`, `quantity_invalid`, `stock_would_go_negative`, `collection_amount_mismatch`, `payment_already_settled`, and a plain 404. Any other answer keeps the server's own `message`. The full table is in [ORDER_OPERATIONS_INTEGRITY.md](ORDER_OPERATIONS_INTEGRITY.md).

## Alerts

The pushes `staff.order_new` and `staff.order_reminder` (payload in [LIVE_OPERATIONS.md](../guides/LIVE_OPERATIONS.md)) open the app on the order: the app maps them to `/staff/orders/{order_id}?fulfillment={fulfillment_id}&branch={branch_id}`, switches to that branch when it is another of the person's, and opens the order's window (or says plainly that it is no longer in the person's list). An online order that is not paid yet is never announced.

## Backward compatibility

- No key was removed or renamed; `clock`, `cancellation_request`, `payment_status`, `amount_due`, `pickup`, `meta`, `pickup` / `branches` / `can` (overview) are additions.
- No `page` / `per_page`: the first 100 rows, as before. No `type`: deliveries only (or both with `include_pickup=1`), as before.
- The order of the rows inside a stage is now "longest wait first"; the set of rows is the same.

Code: `app/Http/Controllers/Api/V1/Admin/DispatchController.php` (`queue`, `overview`), `StaffAttentionController.php`, `StaffPickupController.php`, `app/Support/StaffQueue.php`. Tests: `backend/tests/Feature/Fulfillment/StaffQueue*Test.php`; the app's: `mobile/test/ops/staff_*_test.dart`.
