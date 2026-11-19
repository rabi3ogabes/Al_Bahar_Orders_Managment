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

## The driver's side: job context, arrival, hand back, watchdog and "My day"

What the driver app (the `ops` flavor) relies on. The guide for drivers is [DRIVER_APP.md](../guides/DRIVER_APP.md). Code: `DriverJobPresenter`, `DispatchService` (`declineJob`, `watchdog`, `setDuty`, `heartbeat`), `DeliveryService` (`arrived`, `decline`, `releaseUnaccepted`, `deliver`, `fail`), `DeliveryCustomerAlerts`, `DriverDispatchAlerts`, `DriverDayController`, command `dispatch:watchdog`. Tests: `tests/Feature/Fulfillment/Driver*Test.php`.

### Settings (Settings service, group `dispatch`, all optional)

| Key | Default | Meaning |
|---|---|---|
| `dispatch.two_person_weight_kg` | 25 | a parcel heavier than this (summed over the quantities with a known weight), or any oversized line, `needs_two_people` |
| `dispatch.accept_timeout_min` | 5 | minutes a driver may take to accept a job before `dispatch:watchdog` takes it back (`0` switches the watchdog's take-back off) |
| `dispatch.stale_duty_min` | 20 | minutes without a sign of life (`driver_profiles.last_seen_at`) after which a driver on duty is set off duty |
| `dispatch.max_auto_attempts` | 3 | drivers the system tries on its own per delivery; after that the delivery waits for a person |
| `dispatch.nearby_eta_min` | 10 | the customer is told "close" at this many minutes or fewer |
| `dispatch.nearby_distance_m` | 1500 | the same by straight-line distance when no arrival time can be worked out |

A value that is not a positive whole number falls back to its default (`DispatchSettings`).

### `GET dispatch/driver/jobs`, `GET dispatch/driver/jobs/{id}`, `GET dispatch/driver/history`

The driver's OWN jobs only. An open job carries everything below; a finished job (history) carries the short form (the first block) and, once it is more than 24 hours old, loses the phones and the street (`redacted: true`: `customer_phone` and `alternate_phone` become `null`, and the address keeps only its zone and area).

```json
{
  "id": "01a1…", "tracking_number": "ABD261119K3Q8ZT", "status": "assigned", "fulfillment_id": "01a1…", "order_id": "01a1…", "seq": 4,
  "order_number": "AB100123", "customer_name": "Hamad Al Kuwari", "customer_phone": "+97455000001", "alternate_phone": null,
  "address": { "zone_number": "66", "street_number": "850", "building_number": "12", "unit_number": "4", "landmark": "Behind the blue pharmacy",
               "instructions": "Gate code 4471", "recipient_name": "Noor", "recipient_phone": "+97455123456", "latitude": 25.3, "longitude": 51.5 },
  "payment_method": "cod", "cod_amount": "303.00", "requires_proof": true,
  "branch": { "id": "01a1…", "name": "Al Markhiya" },
  "scheduled_for": "2026-11-24T14:00:00+03:00", "assigned_at": "2026-11-24T11:02:10+03:00",
  "items": [ { "id": "01a1…", "order_item_id": "01a1…", "name": "Oslo sofa", "variant_name": "Grey", "sku": "S1", "quantity": 2, "adjustment": null,
               "weight_kg": 32.5, "dimensions_cm": { "length": 210, "width": 90, "height": 85 }, "is_oversized": true, "requires_assembly": false } ],
  "arrived_at": null, "can_decline": true, "redacted": false,

  "recipient": { "name": "Noor", "phone": "+97455123456", "is_account_holder": false },
  "instructions": "Gate code 4471", "customer_notes": "Please ring twice", "landmark": "Behind the blue pharmacy",
  "slot": { "start": "2026-11-24T14:00:00+03:00", "end": "2026-11-24T16:00:00+03:00" },
  "total_weight_kg": 65, "needs_two_people": true, "is_oversized": true, "requires_assembly": false,
  "cod": { "due": true, "amount": "303.00", "currency": "QAR" },
  "payment_note": "Collect QAR 303.00 in cash at the door."
}
```

- The second block (from `recipient` down) exists on open jobs only. Strings that are empty or blank are `null`. `slot` is `null` for a job that was not booked into a delivery slot. `weight_kg` / `dimensions_cm` are per unit, in kilograms and centimetres, `null` when the catalogue does not know them.
- `recipient.is_account_holder` is `false` when the order's recipient has another phone number (or, with no phone, another name) than the account holder's. The app shows **Call recipient** and **Call account holder**. `alternate_phone` follows the dashboard switch (`AlternatePhone::visible`).
- `needs_two_people`, `is_oversized` and `requires_assembly` are only `true` when the data says so; the app draws a chip for a `true` and nothing for a `false`.
- `payment_note` is written in the request's language (`Accept-Language`).
- `GET dispatch/driver/jobs/{id}` answers `404` when the job is not the caller's (any more).

### `GET dispatch/driver/day`

Driver-scoped: only his own sign-in, never `payments.view`. "Today" is the Qatar day.

```json
{ "data": {
  "date": "2026-11-19", "on_duty": true, "on_duty_since": "2026-11-19T07:45:00+03:00",
  "jobs": { "done": 7, "failed": 1, "declined": 2, "active": 3 },
  "cash": { "currency": "QAR", "collected_today": "1200.00", "held": "450.00", "held_count": 3, "oldest_at": "2026-11-18T11:00:00+03:00", "state": "amber", "age_minutes": 1560 },
  "list": [ { "id": "01a1…", "order_number": "AB100001", "status": "delivered", "customer_name": "Hamad", "address": { "zone_number": "24" }, "cod_amount": "50.00", "at": "2026-11-19T11:00:00+03:00" } ],
  "at": "2026-11-19T15:00:00+03:00" } }
```

`failed` counts jobs whose attempt failed today and are still failed, rescheduled, returning or returned (a failed attempt that was delivered later counts as done). `declined` counts the jobs he handed back himself (the system's own take-backs are not listed). `held` is `CashDrawerService::balancesFor` for him (collected, not yet handed in); `state` is the order clock's cash state (`ok` / `amber` / `red`, limits in *Settings > Operations clock*). A decline appears in `list` with `status: "declined"`.

### `POST deliveries/{id}/arrived`

Needs `deliveries.edit`; only the delivery's driver (`403 delivery_not_yours` for anyone else, dispatcher included), only while `out_for_delivery` (`409 invalid_state_transition` otherwise, `409 delivery_cancelled` after a cancellation). Body, all optional: `latitude`, `longitude`, `accuracy` (metres). Idempotent: a repeat finds `arrived_at` set and changes nothing (no second message, history row or audit row). Writes a history row (note `arrived at the customer`, with the position) and the audit row `delivery.arrived`; the customer's message goes out after the commit. Going out for delivery again after a failed attempt clears `arrived_at` and `nearby_at`: every trip to the door is announced on its own.

```json
{ "data": { "id": "01a1…", "status": "out_for_delivery", "arrived_at": "2026-11-24T14:12:03+03:00" } }
```

### The two customer messages

| Event (Notification controls > Orders) | When | Text (title / body) |
|---|---|---|
| `delivery.nearby` ("Driver is close") | once per trip, when a position report puts the driver at `dispatch.nearby_eta_min` minutes or fewer from the address (the cached arrival time, `EtaService`; never more than one look per delivery per 15 s; the routing server is not asked for a driver more than 15 km away; without an arrival time: within `dispatch.nearby_distance_m`) | *Your driver is close* / *Ahmed is a few minutes away with order AB100123. Please be ready to receive it.* |
| `delivery.arrived` ("Driver has arrived") | once, when the driver taps "I have arrived" | *Your driver has arrived* / *Ahmed is at your door with order AB100123.* |

Both are `transactional`, on **push** and **in-app** by default, switchable like every catalogue event, in the customer's language, with the order number and the driver's FIRST name only (no phone). They are never sent for a pickup order (it has no delivery), for a courier delivery (no own driver), for an order without a signed-in customer, or after the delivery left the road. `nearby` is claimed in the database (`deliveries.nearby_at`), so two reports at the same moment send one message; it is not sent once `arrived_at` is set.

### Delivery proof with a position, and the failed-delivery kit

`POST deliveries/{id}/deliver` accepts the optional `latitude` (-90..90), `longitude` (-180..180) and `accuracy` (0..32767 metres); a value out of range is `422`, the "no fix" pair `0,0` is ignored. They are stored on the proof row (`delivery_proofs.latitude`, `longitude`, `accuracy_m`) and on the "delivered" history row; a delivery that needs no photo still keeps the position as a proof row of type `location`. The dashboard's *View proof* shows the position saved with the proof first, then the driver's trail, then *Not recorded*.

`POST deliveries/{id}/fail` (JSON or multipart):

| Field | Rule |
|---|---|
| `reason_code` | `customer_unreachable`, `customer_absent`, `wrong_address`, `refused`, `damaged`, `access_denied`, `other` |
| `notes` | **required** for `damaged` and `other`, otherwise optional; at most 500 characters (`422` with `errors.notes`) |
| `photo` | optional image (jpeg, png, webp, 8 MB); kept as a proof row of type `failed` in the same private storage, with the position; the dashboard viewer shows it labelled *Failed attempt* |
| `latitude`, `longitude`, `accuracy` | optional, as above |

A repeat with the same reason changes nothing (no second attempt, photo or history row). A failed-attempt photo is never counted as proof that the order was handed over (a later delivery still needs its own photo). The customer's live order card says *We could not deliver your order. We will call you to arrange a new time.* (the existing `failed` step; there is no separate push). The notes rule is part of the driver API only: the dashboard's own failure forms are unchanged.

### `POST dispatch/jobs/{id}/decline`

Body: `reason` = `too_far` | `vehicle` | `shift_ending` | `other`, optional `note` (300). Only the driver the job is given to, only before he accepted (`409 invalid_state_transition` for an accepted job, `403 delivery_not_yours` for another driver's). In one transaction under the parcel's and the delivery's locks: a `delivery_declines` row (unique per delivery and driver), the job back to `pending` with `driver_id = null`, a history row (`declined: too_far`) and the audit row `delivery.declined`. After the commit: in a branch on **automatic** dispatch the next on-duty driver gets it at once, never one who declined it or did not answer for it; the branch managers and dispatchers (`deliveries.assign` or `orders.approve` at the branch) get a push and an inbox message through the existing staff alert `staff.order_reminder` (data `kind: "driver_declined"`, `order_id`, `number`, `delivery_id`; no customer data). A repeat (a second tap) changes nothing and tells nobody twice. The driver's own channel hears `status: "unassigned"`, `assigned: false` for it (the app does not show that as news).

```json
{ "data": { "id": "01a1…", "status": "pending", "declined": true } }
```

### The watchdog: `dispatch:watchdog`

Scheduled in `routes/console.php`:

```php
Schedule::command('dispatch:watchdog')->everyMinute()->withoutOverlapping(10)->onOneServer();
```

Every step is idempotent and takes row locks (a driver who accepts at the last second keeps the job).

1. **A job given and not accepted** for longer than `dispatch.accept_timeout_min` is taken back: a `delivery_declines` row with reason `timeout`, history `taken back: timeout`, audit `delivery.released`, the driver is told it is no longer his (live message and push). A branch on automatic dispatch offers it to the next driver (never one who already failed to answer for it). After `dispatch.max_auto_attempts` timeouts the delivery **stays pending**, the managers get *No driver took order …*, and it shows in **Needs attention** as **Driver has not accepted** (*No driver accepted it. The system tried 3 drivers…*) with **Assign driver**. In a branch where a person chooses the drivers the job is taken back and the managers are told instead of a driver being chosen.
2. **A driver on duty without a sign of life** (heartbeat, duty switch) for `dispatch.stale_duty_min` minutes is set off duty (audit `driver.auto_off_duty`) and the jobs he had **not accepted** are taken back as above. What he accepted or carries (`accepted`, `picked_up`, `out_for_delivery`, `failed`) is never touched; the managers are told *… went offline* with how many jobs he still carries.

### Going off duty

`PUT dispatch/driver/duty {"on_duty": false}` while the driver has jobs in `accepted`, `picked_up`, `out_for_delivery` or `failed` answers `409`:

```json
{ "message": "You still have 2 jobs. Finish them or ask the branch to hand them back.", "code": "jobs_in_progress", "context": { "count": 2 } }
```

He stays on duty. Switching off with nothing in hand works (a repeat is harmless) and gives the jobs he had not accepted to another driver. `driver_profiles.on_duty_since` records when he went on (a repeated "on" does not move it). The decision is taken under the profile's row lock.

### Errors the driver app maps to its own sentences

| HTTP | `code` | Where |
|---|---|---|
| 409 | `jobs_in_progress` | duty off (`context.count`) |
| 409 | `delivery_cancelled`, `cancellation_pending`, `invalid_state_transition` | every driver step (see [ORDER_OPERATIONS_INTEGRITY.md](../api/ORDER_OPERATIONS_INTEGRITY.md)) |
| 403 | `delivery_not_yours` | arrived, decline, any step on another driver's job |
| 422 | `cod_collection_required`, `collection_amount_mismatch` (`context.due`), `delivery_proof_required`, `media_invalid` | deliver |
| 422 | validation, `errors.notes` | fail without its note |
| 404 | (not found) | `GET dispatch/driver/jobs/{id}` for a job that is not his |

### Tables and columns added

`deliveries.arrived_at`, `deliveries.nearby_at`; `delivery_proofs.latitude`, `longitude`, `accuracy_m` (types `photo`, `signature`, `provider`, plus `failed` and `location`); `delivery_declines` (`delivery_id`, `driver_id`, `reason` = `too_far` / `vehicle` / `shift_ending` / `other` / `timeout` / `off_duty`, `note`, `created_at`, unique `(delivery_id, driver_id)`); `driver_profiles.on_duty_since`. Migration `2026_11_19_400000_driver_day_arrival_declines_and_proof_gps.php` (reversible).
