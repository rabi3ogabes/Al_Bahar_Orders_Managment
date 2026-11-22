# Delivery follow-ups: new times after a failed delivery, the proof of delivery, "assembly done"

**One rule: the customer never waits for a phone call to learn what happens next, and the driver never marks a job delivered by accident.** Three small things
that close the loop of a delivery (roadmap 5.3, 5.4, 5.2). This page is both the guide (what the driver, the customer and the branch see, where the switches
are) and the design note (what was decided, why, and what every state means). The API is in [API_CONTRACT.md](../api/API_CONTRACT.md) section 8.3.

| Feature | The customer sees | The branch and the driver see | Switch (Settings > Checkout & fees) | Default |
|---|---|---|---|---|
| **New times after a failed delivery** | A notification and, on the order, "Choose a new delivery time" with up to three free times; one tap to confirm | "Waiting for the customer (open until ...)", "Customer chose Fri 20 Nov, 16:00-18:00" or "did not choose in time" on the order, in Needs attention, on the live board and in the Deliveries list; a button **Offer new times** | *Offer new times after a failed delivery*, *How many times to offer* (1 to 5), *How long an offer stays open* (hours) | **On**, 3 times, 48 hours |
| **Proof of delivery** | On a delivered order: "Delivered on 19 Nov, 12:00 · received by Mariam" and the photo | The same proof as before (Proof of delivery window) | *Show the proof of delivery to customers* | **On** |
| **"Assembly done"** | "Assembled on ..." (or "not done: reason, the branch will contact you") | Driver: one tap on the job card. Branch: the answer on the order and, when not done, a line in Needs attention | *Driver must answer for paid assembly* | **On** |

Arabic and English everywhere, right-to-left checked. The Arabic wording was written for this release and has not been read by a native speaker: please have it
read before it reaches customers (`backend/lang/ar/delivery_followup.php`, `notifications.php`, `push.php`; `mobile/lib/l10n/app_ar.arb`;
`mobile/lib/ops/driver/driver_strings.dart`; `storefront/src/locales/ar.ts`).

## 1. New times after a failed delivery (5.3)

### What happens, in order
1. The driver (or a dispatcher in the dashboard) records **Could not deliver** with a reason. Nothing changes for the driver.
2. If the reason is one a new time can fix (**Customer not there**, **Customer unreachable**, **Wrong address**, **No access**) and the order has a customer account, the
   system looks at the branch's own delivery slots and offers up to **three** free ones, the earliest of each day first, starting at least **three hours** ahead (the
   goods have to come back to the branch first). **Refused**, **Damaged** and **Other** are never offered automatically: a person decides those.
3. The customer gets a push and an inbox message (*New delivery times to choose* under Notification controls > Orders) that opens the order. In the app and on the web
   order page the card **Choose a new delivery time** lists the times; the customer picks one and taps **Confirm this time**.
4. Confirming moves the booking exactly as a dashboard reschedule does (the capacity of the new slot is checked and taken, the old place is given back), the delivery
   becomes **Rescheduled** (back in the branch's and the driver's list with the new time), the branch team that sends drivers and the driver of the job get a push and an
   inbox message ("Customer chose Fri 20 Nov, 16:00-18:00"), and the customer's live order card says "Your new delivery time is booked". Cash due is not touched.
5. If the customer does not choose within the offer's time, it closes (**did not choose in time**) and the old path applies: the order is in Needs attention as
   *Delivery failed, needs a new plan* and the branch phones the customer.

### What the customer is told, and what they cannot do
- Picking twice, picking a time that is no longer in the offer, picking a time that filled up since, picking after the offer ran out, and picking on somebody else's order are all
  refused with a plain sentence (see the API for the codes). A time that filled up leaves the offer open so they can pick another.
- Sending the same pick again (a lost answer, a double tap) is the same answer: one booking, one alert to the branch, one audit row (the app sends an `Idempotency-Key`).
- When every offered time was taken in the meantime, the card says so calmly and the branch calls, as before.

### What the branch can do
- **Offer new times** (on the order, in the failed delivery's block; needs *deliveries.assign* at the branch, the same right as rescheduling): the window shows the free
  delivery times of the next ten days with their places left, the times the system would have offered already ticked, up to five. Sending replaces an open offer and
  tells the customer. The branch can offer times for any failed delivery, whatever the reason.
- The existing **Reschedule** still works and wins: it closes an open offer (the customer's pick then answers "the branch has already arranged your delivery").
- Anything that moves the delivery on closes the offer too: the driver trying again, the goods coming back to the branch.

### Night
Nobody is messaged between 22:00 and 08:00 (Qatar time). An offer made at night is kept, the customer is told at 08:00, and its lifetime starts then. The staff alert for a
customer's choice goes to the inbox only at night (no phone is woken).

## 2. The customer sees the proof of delivery (5.4)

On a **delivered** order the customer's order page (app and web) says **Delivered on <date, time> · received by <name>** and shows the photo the driver took.

- **Private.** Only the order's customer can read the order (another customer's order does not exist for them). The photo comes through a **signed link that works for ten
  minutes**, made fresh every time the order is opened; it is never a public file, never listed, and is sent with `Cache-Control: private, no-store`.
- **Never** the driver's position, the driver's name or phone, the photo of a failed attempt, the photo of an assembly, or a courier's own link.
- **Switch off** (*Show the proof of delivery to customers*): the photo disappears (and the old links stop working) but the time and the name of who received it stay.
- **Older deliveries** (made before this change) with no photo show only the time and the name (the name is read from the delivery's history when no proof row kept it).

## 3. "Assembly done" in the driver app (5.2)

When the customer paid for **assembly and installation** (see [DELIVERY_PROMISE_ASSEMBLY_AND_SHARING.md](DELIVERY_PROMISE_ASSEMBLY_AND_SHARING.md)) the job card shows a strip once the
goods are with the driver:

- **Assembly done**: one tap (or one slide, when the dashboard's *Staff and driver apps: how to change an order status* setting asks for the slide). Next to it **Add a note or photo** (an optional note, an optional photo of
  the finished assembly) and **Not done**.
- **Not done** asks why: *The customer was not ready*, *Parts or tools were missing*, *It could not be done here*, *There was no time*, *Another reason* (this one needs a few words).
- The strip then says what he answered, with **Change**. The answer is stored per branch part with who and when, and audit-logged (`delivery.assembly_answered`).
- **A delivery with paid assembly cannot be confirmed before he answered**: "Mark delivered" opens the answer window first; the server refuses too (`assembly_answer_required`).
  The server holds only this new driver app to the rule (it says so with `answers_assembly` when it confirms): a driver on an older app, and the dashboard's own
  driver view, can still confirm such a parcel (the order then shows no assembly answer), so nobody is stranded at a door until the phone is updated.
  *Driver must answer for paid assembly* (Settings > Checkout & fees) switches the rule off altogether.
- **Not done** tells the branch team that sends drivers (push and inbox, *Assembly not done* under Notification controls > Staff and driver alerts) and raises a line in
  **Needs attention** ("Assembly not done", the reason, the driver's note, a call button, the order window). The branch refunds the fee through the usual refund approval
  (Finance > Refunds, dual control stays) or books it again, then presses **Mark as handled** on the order: the line goes away and the action is audit-logged
  (`delivery.assembly_handled`).
- The customer sees **Assembled on <date>**, or "The assembly was not done: <reason>. The branch will contact you." (never the driver's note).
- The sales report's assembly revenue is another feature (5.5); it can read `order_fulfillments.assembly_status` (`done` / `not_done` / null) and `assembly_done_at`.

---

## For developers

**Migrations** (each with a working `down()`): `2026_11_23_100000_create_delivery_slot_offers`, `2026_11_23_100100_add_assembly_answer_to_fulfillments`.

| Piece | File |
|---|---|
| Rules, offers, choice, expiry, staff lines | `app/Domain/Fulfillment/Services/SlotOffers.php` (model `DeliverySlotOffer`, table `delivery_slot_offers`) |
| The customer's page data (offer, proof, assembly) | `app/Domain/Fulfillment/Services/DeliveryFollowUps.php`, added to `OrderPresenter::detail()` per part |
| The move onto a slot (staff and customer) | `DeliveryService::moveToSlot`, `rescheduleToSlot`, `rescheduleForCustomer`; an offer closes in `DeliveryService::transition` |
| Assembly | `DeliveryService::answerAssembly` / `handleAssembly`, `AssemblyFacts`, `AssemblyAlerts`; the gate is in `DeliveryService::deliver` |
| Settings | `DeliveryFollowUpSettings` (keys `delivery.slot_offers_enabled`, `delivery.slot_offer_count`, `delivery.slot_offer_hours`, `delivery.proof_for_customer`, `delivery.assembly_answer_required`) |
| Customer endpoints | `POST /orders/{id}/slot-offers/{offerId}/choose`, signed `GET /orders/{id}/delivery-proof/{proofId}/photo` |
| Staff endpoint | `POST /admin/deliveries/{id}/assembly` |
| Dashboard | Livewire `DeliveryFollowUp` (inside the order page and window), notes in `AttentionFeed`, `LiveBoard`, `DeliveryIndex`; the settings in `SettingsIndex::deliveryFollowUpSchema` |
| Scheduler | `deliveries:process-slot-offers` every five minutes (told-at-08:00 messages, closing offers whose time ran out); in `SchedulerHeartbeat::TASKS` |
| Notification events | `delivery.slots_offered` (customer: push + inbox), `staff.slot_chosen`, `staff.assembly_not_done` (staff: push + inbox) |
| Audit actions | `delivery.slots_offered`, `delivery.slots_none`, `delivery.slot_chosen`, `delivery.slots_expired`, `delivery.slots_closed`, `delivery.assembly_answered`, `delivery.assembly_handled` |
| Apps | customer: `mobile/lib/features/orders/presentation/widgets/follow_up_cards.dart`; driver: `mobile/lib/ops/driver/driver_assembly.dart` (network call `OpsRepository.assembly`); web: `storefront/src/components/FollowUps.tsx`, `src/lib/followUps.ts` |
| Tests | `backend/tests/Feature/Fulfillment/{SlotOffersTest,SlotOffersDashboardTest,CustomerProofTest,AssemblyAnswerTest}.php`, `mobile/test/features/orders/follow_up_test.dart`, `mobile/test/ops/driver_assembly_test.dart`, `storefront/tests/follow-ups.test.mjs` |

Permissions: the offer, the dashboard buttons and "Mark as handled" need `deliveries.assign` at the part's branch; the driver's answer needs `deliveries.edit` and the job to be his (a
dispatcher with `deliveries.assign` can answer for him). A branch-limited member sees only their branches' offers, lines and cards (the order window already narrows parcels; the
component answers 403 for another branch's part).

---

## Design note

### Decisions and defaults (change any of them)
| Choice | Default | Why |
|---|---|---|
| Offers are **on** | on | It only replaces a phone call; the branch can still call. One switch turns it off. |
| Reasons that get an automatic offer | customer absent, unreachable, wrong address, no access | A new time fixes these. Refused and damaged need a person; "other" is unknown. There is no "not ready" reason in the driver app, so none is listed. |
| How many times | 3 (1 to 5) | Enough to choose, not a menu. The earliest free time of each day first, so the choice is real. |
| Earliest time offered | 3 hours from now | The goods must come back to the branch. A slot's own booking cut-off still applies. |
| Looks ahead | 14 days | Past that a phone call is better than a distant date. |
| Offer lifetime | 48 hours (1 to 336) | Two days to answer; counted in real hours, from the moment the customer is told. |
| Automatic offers stop | after the third failed attempt | A customer who is never home needs a person, not a loop. The branch can still offer by hand. |
| A branch without delivery slots | no offer | Slots are the only list of times we can promise. The phone call stays. |
| An order without a customer account | no offer | Nobody can choose in an app; the branch is told "no account, call". |
| Quiet hours | 22:00 to 08:00 | Same as every automatic message. |
| After a pick the delivery is | **Rescheduled** | The branch's own word for "failed, new time set". It keeps its driver; the driver app shows "Try delivery again" with the new time. The order's part stays *failed* until the driver goes out again (this is how the dashboard's reschedule has always worked). |
| Proof photo link | signed, 10 minutes | An `<img>` on the web cannot send a sign-in header; a short signed link is private enough and works everywhere. The photo is never public. |
| Assembly answer is required | on | The owner asked for it. Only the new driver app is held to it (`answers_assembly`); older apps and the dashboard driver view deliver as before. |
| Assembly photo | stored as a proof row of type `assembly` | Same private storage, same viewer (labelled), and it is never counted as the proof of the handover. |
| "Not done" waits in Needs attention until handled | yes | A customer paid for it; nothing waits silently. Refund stays the existing dual-control flow: this only surfaces it. |

### What every number and state means
- **Offer `open`**: the customer can choose until `expires_at`. **`expired`**: nobody chose in time (the job writes it within five minutes; the app already shows it the moment the time passes).
  **`chosen`**: a time is booked. **`cancelled`**: the branch arranged something else (`rescheduled_by_branch`, `tried_again`, `delivery_moved_on`, `replaced` by a new offer) or there was no free
  time to offer (`no_slots`, kept only so the branch can see why nobody was asked).
- **Places left** on a time: the slot's capacity minus what is booked, zero when the booking cut-off passed or the branch is closed that day (holidays and closures included): the checkout's
  own figure.
- **"Waiting for the customer (open until ...)"**: an open offer; **"Customer chose ..."**: a chosen one; **"did not choose in time"**: an expired one; **"No free times to offer"**: the branch had none.
  A line is only shown for the current failure (an older offer says nothing about a delivery that failed again).
- **Assembly `pending` / `done` / `not_done`**: no answer yet / the driver said it was done (`assembly_done_at` is set only then) / he said it was not (with a reason code).
  **Handled**: the branch dealt with a `not_done`.
- **Proof**: *delivered at* is the delivery's own delivered time, *received by* the name the driver typed.

### Trade-offs and what is not done
- The customer's pick is **not** a new delivery: it moves the existing one, so history, cash due and the driver stay together.
- A branch that offers times by hand can offer any free slot, including one sooner than three hours (a manager knows better).
- The storefront has no component test runner, so the web order page is covered by tests of its logic (`follow-ups.test.mjs`) and the type checker, not by a rendered test.
- The driver-app offline layer (roadmap 5.1) will wrap the new call `OpsRepository.assembly`, like the other job steps.

---

## بالعربية باختصار

- **مواعيد جديدة بعد تعذّر التوصيل:** إذا لم يكن العميل موجوداً أو تعذّر الوصول إليه أو كان العنوان خاطئاً، يعرض النظام عليه حتى ثلاثة مواعيد توصيل متاحة (من أقرب موعد في كل يوم، وبعد ثلاث ساعات على الأقل). يختار العميل موعداً بلمسة واحدة في التطبيق أو في صفحة الطلب، فيعود التوصيل إلى قائمة الفرع والسائق بالموعد الجديد ويصلهما إشعار. إن لم يختر خلال 48 ساعة (قابلة للتعديل) يتصل به الفرع كما كان. يستطيع الفرع أيضاً عرض مواعيد من الطلب بزر «عرض مواعيد جديدة». لا تُرسل رسائل بين 22:00 و08:00.
- **إثبات التسليم للعميل:** في الطلب المُسلَّم يرى العميل وقت التسليم واسم المستلم وصورة التسليم (رابط خاص يعمل عشر دقائق). لا يرى موقع السائق ولا رقمه. يمكن إيقاف الصورة من الإعدادات فيبقى الوقت والاسم.
- **«تم التركيب» في تطبيق السائق:** للطلب الذي دفع العميل تركيبه تظهر بطاقة بلمسة واحدة «تم التركيب» (مع ملاحظة وصورة اختياريتين) أو «لم يتم» مع سبب. لا يمكن تأكيد التسليم قبل الإجابة. إن لم يتم التركيب يظهر بند في «يحتاج انتباهك» ويصل إشعار للفرع ليُعيد الرسوم (بالموافقة المعتادة) أو يحجز التركيب من جديد، ثم يضغط «تمت المعالجة».
