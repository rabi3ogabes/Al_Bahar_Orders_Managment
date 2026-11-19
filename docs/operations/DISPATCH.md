# Dispatch, drivers and branch staff

Three Android apps share one codebase: **customer**, **driver** and **branch staff** (own package id, name and icon each; see `mobile/README.md`).

## Who assigns a delivery

Set per branch in the admin panel: **Branches > (branch) > Services > Who assigns deliveries**.

| Mode | What happens when an order is marked *Ready* |
|---|---|
| **Automatic** | The system creates the delivery and gives it to the on-duty driver of that branch with the fewest open jobs (the one idle longest wins a tie). If nobody is on duty the delivery waits as *Waiting for a driver* and goes to the first driver who goes on duty. |
| **Manual** (default) | A branch employee picks the driver in the staff app (*To do* tab, *Ready* section > *Assign driver*). The server checks the driver belongs to that branch. |

Branch employees can also change the driver in automatic mode.

## People

- A branch can have **several employees** (roles such as *Order Manager* or *Branch Manager*) and **several drivers**.
- A driver can work for **several branches**: add the person to each branch in **Branches > Employees** with *delivery driver* ticked (role *Delivery Employee*).
- Drivers switch **On duty / Off duty** in the app; only on-duty drivers receive automatic assignments. While on duty the app reports its position every few seconds; nothing is stored once the driver is off duty with no order on the road.
- Sign-in for both apps is the staff **email + password** (`POST /api/v1/admin/auth/login`).

## API (prefix `/api/v1/admin`, staff token)

| Who | Endpoint |
|---|---|
| Driver | `GET dispatch/driver`, `PUT dispatch/driver/duty`, `POST dispatch/driver/location`, `GET dispatch/driver/jobs?scope=active\|history` |
| Driver steps | `POST deliveries/{id}/accept\|pickup\|out-for-delivery\|deliver\|fail` (deliver takes a proof photo and the cash collected) |
| Staff | `GET dispatch/overview`, `GET dispatch/queue?stage=new\|preparing\|ready\|out\|failed`, `GET dispatch/drivers`, `GET dispatch/fleet`, `GET deliveries/{id}/route`, `POST dispatch/fulfillments/{id}/assign` |
| Staff order steps | `POST fulfillments/{id}/transition` (`accepted`, `preparing`, `ready`; the delivery steps and `returned` / `cancelled` need the rights listed below) |

`GET dispatch/queue` and `GET dispatch/overview` leave out an online (SADAD) order that is not paid yet (it cannot be accepted; the dashboard hides it the same way), and the branch is not told about it until the payment arrives. Every queue row carries its `type` (`delivery`). `include_pickup=1` adds the branch's pickup parts (type `pickup`; `ready` then also means `pickup_ready`); without the flag the answer is exactly what it was. Details: [ORDER_OPERATIONS_INTEGRITY.md](../api/ORDER_OPERATIONS_INTEGRITY.md).

Positions, the fleet map, customer tracking, route history and the map provider settings are specified in [MAPS.md](../guides/MAPS.md).

Code: `app/Domain/Fulfillment/Services/DispatchService.php`, `app/Http/Controllers/Api/V1/Admin/DispatchController.php`, tests in `tests/Feature/Commerce/DispatchTest.php`.

## Rules that keep goods, cash and statuses in step

The code is in `OrderService`, `DeliveryService`, `PickupService`, `PaymentService` and `CodSplit`; the tests are `tests/Feature/Orders/Ops*Test.php` and `tests/Feature/Commerce/Ops*Test.php`. The error codes and answers are in [ORDER_OPERATIONS_INTEGRITY.md](../api/ORDER_OPERATIONS_INTEGRITY.md).

- **A cancelled part cancels its delivery.** When a part of an order is cancelled (staff, an approved customer request, an item adjustment that emptied it) its delivery that has not left the branch (waiting, assigned or accepted) is cancelled in the same transaction, with a history row and an audit row. The driver who had it is told at once on his live channel (`delivery_status: "cancelled"`) and by push; a courier's booking is released after the commit (a courier that cannot be reached never blocks the cancellation: the delivery keeps a note for staff). A driver step on a cancelled job answers `409 delivery_cancelled`.
- **A whole order cannot be cancelled once goods left the branch.** Staff cancelling the order, or a manager approving the customer's request, is refused with `409 order_partly_handed_over` when any part is dispatched, out for delivery, delivered, collected, or a pickup was handed over in part. The request stays open (the manager can decline it). The remaining parts are cancelled one by one, and what was handed over goes through a return.
- **Cancelling one part of a split order takes only that part's share.** The share is the exact division of the money by `ParcelShares` (a part's weight is its lines after discount and tax plus its own delivery fee; rounding goes to the largest remainders, so the shares add up to the whole). Cash on delivery: the amount due drops by the part's share (what was already collected is untouched; when everything left to pay was collected the payment closes). Already paid: a refund request for exactly that share is raised for finance to approve (idempotent per part; never more than what is left to refund). The last part cancelled ends like a whole-order cancellation.
- **No hand-over while a cancellation waits.** While a customer's cancellation request is `requested`, dispatch, out for delivery, delivery, driver pick-up and the pickup hand-over answer `409 cancellation_pending`. Preparing and ready stay allowed. A courier's webhook for such an order is recorded as `rejected` instead of failing.
- **Who may take which step** (`OrderService::permissionFor`, checked for the part's branch): accepted / preparing / ready need `orders.edit`; dispatched / out for delivery / delivered / failed need `deliveries.edit`; returned and cancelled need `orders.approve`. A staff member cannot mark a cash-on-delivery order delivered before the cash is recorded (`409 cod_collection_required`): the driver's delivery step (proof and cash) is the way. Taking away the last item of a part (which cancels it, and the order when it was the last part) needs `orders.approve`.
- **Giving a delivery to a driver.** Only while the goods are at the branch: waiting, assigned, accepted by the previous driver, or rescheduled. One transaction under the part's and the delivery's locks. The same driver again changes nothing. A change writes a delivery history row (an accepted job goes back to the pool first: accepted > pending > assigned) and an audit row, and tells both drivers (the old one: `unassigned` live, and a push "no longer yours"). Never once the driver picked it up.
- **A repeated driver tap is harmless.** `accept`, `pickup`, `out-for-delivery`, `deliver`, `fail`, `return` and `cancel` repeated on a delivery that is already there answer with it unchanged: no second attempt, proof, cash collection or audit row. `fail` with another reason than the first is refused (`409`).
- **Who may cancel or return a delivery.** The driver who has the job, or branch staff with `deliveries.assign`; cancelling is audit-logged with its reason.
- **Cash on delivery for an order from several branches.** Each branch's delivery collects its own part: `cod_amount` is the part's share of what is still due (the payment's amount less what was collected), divided among the parts still to be delivered, so the last driver collects exactly what is left and the payment closes to the dirham. `deliver` requires that amount (`422 collection_amount_mismatch` otherwise); the payment stays pending with `captured_amount` growing until the last part. An order with one part is unchanged. The amounts are recomputed whenever a delivery is created, a part is delivered or cancelled, or an item adjustment changes the total. The dashboard driver console must pre-fill the amount with the delivery's `cod_amount` (not the order's total).
- **An expired or lost pickup pass.** `Re-issue pass` on the order page (`pickup.edit`): the old pass stops working at once, the new one lasts a full pass lifetime, the customer is told (the "ready for pickup" message), the audit log says who and why; a part handed over in pieces keeps its status and quantities. `Hand over without the pass` (`pickup.approve`, a manager): the order's number and the last four digits of the phone number on the order stand in for the QR, the manager writes why, and everything is recorded (audit `pickup.manual_handover`, pickup log result `manual`). Five wrong tries per staff member and order lock it for fifteen minutes; the error never says which detail was wrong.
