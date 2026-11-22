# The driver app: a driver's day

**One rule: the driver never wonders what to do next, and the app never lets him do the wrong thing.** One big action per state, every dead end has a way out,
and nothing is asked twice. This guide is the plain-English version for drivers, branch managers and anyone who supports them. The developer notes
(endpoints, settings, events) are at the end.

Arabic and English, right-to-left, light and dark. The words below are the English ones; the Arabic app says the same things in its own way.

---

## 1. Starting the day

1. Sign in with the account your branch manager created. Allow **location** when asked (it is how customers and the branch see you are on the way).
2. Switch **On duty**. Only drivers on duty are given jobs automatically. The app shares your position every few seconds while you are on duty and
   stops when you go off duty.
3. **My day** (the calendar icon at the top) shows today's numbers and the cash you hold. It is not a tab: it opens on top of the list and Back brings you home.

**No signal?** A strip across the top says *No connection. Your steps are saved and sent when the signal is back.* with **Try again**. The lists keep
their last good rows, also after the app was closed (*Last updated 2:05 PM*). Accept, Picked up, On my way, Mark delivered (photo, cash, recipient),
Could not deliver and the assembly answer still work: the job moves at once and says **Waiting to send**, a strip at the top counts them (*2 updates
waiting to send*), and they go by themselves when the signal is back, in the order you pressed them, recorded at the time you pressed. "I have arrived"
and "I can't take this" need a signal (the customer and the branch must hear them now). Full detail: [OFFLINE_DRIVER_AND_STAFF_APPS.md](OFFLINE_DRIVER_AND_STAFF_APPS.md).

---

## 2. A new job

A job arrives as a card in your list, with a short vibration and a notification on your phone. It has the order number, the customer, the address, the **delivery window** the customer chose
(for example *Today · 2:00 PM – 4:00 PM*) and the facts that matter before you go (below).

| You see | You can |
|---|---|
| **Accept** (the one big button) | take the job |
| **I can't take this** (a small button under it) | hand it back: *Too far from me*, *My vehicle cannot carry it*, *My shift is ending*, *Something else* (and a short note if you like). The branch is told at once and the job goes to another driver. You will not be offered the same job again. |

You can only hand a job back **before you accept it**. After that, ask the branch to take it back.

**If you do not answer** a job within **5 minutes**, the system takes it back and offers it to the next driver (it tries up to three drivers on its own, then it waits for
the branch). You are told that it is no longer yours. Accepting quickly is always safe.

---

## 3. The job card

Open a job to see everything in the order you need it.

1. **What, where, when.** The customer (or the person at the door if the order names someone else), the address in large type, the landmark, the window.
   Three big buttons right under it: **Call**, **Navigate**, **Copy address**.
2. **Before you go.** Chips that appear **only when they are true** for this parcel. A plain household order shows none of them.
   - *Needs two people*: a line is oversized, or the whole parcel is heavier than 25 kg (the limit is a setting).
   - *Assembly requested*, *Oversized*.
   - *Cash on delivery QAR 303.00*.
3. **Notes** in a highlighted box: the customer's note and the **instructions for the door** (gate code, "call from downstairs").
4. **Who to call.** When the person receiving the order is not the account holder you see **Call recipient** and **Call account holder**, each with its own number.
   When the customer gave a second number (and the dashboard shows it) there is a **Call alternative number** button too.
5. **What is in the parcel.** Each line with its weight and size when the catalogue knows them, a total weight, and *Oversized* / *Assembly* on the lines that need it.
6. **Payment.** What to collect in cash at the door, or "nothing to collect".

---

## 4. On the road

| Step | What the button says | What happens |
|---|---|---|
| Collect it at the branch | **Picked up from branch** | stock leaves the branch |
| Set off | **On my way** | the customer sees you on the map |
| Close to the customer | (nothing to do) | when you are about **10 minutes away** the customer gets one message: *Your driver is close* |
| At the door | **I have arrived** | the customer gets one message: *Your driver has arrived* (your first name and the order number, never your phone) |
| Hand it over | **Mark delivered** | opens the delivery form |

If the arrival cannot be sent (no signal at the door) a small **Mark delivered** button stays under it, so you are never stuck.

### The delivery form

- **Received by**: who took it.
- **Cash collected** (cash on delivery only): the amount is checked. Collect **exactly** what is shown; the amount is the part of the order in your hands.
- **Take proof photo**: required for most deliveries.
- **Confirm delivery**. Your position goes with the proof when the phone knows it; **a delivery never waits for GPS and never fails without it.**

The signal dropped? The delivery is kept on the phone with the photo, the name and the cash, the job says **Waiting to send**, and it goes by itself when
the signal is back: always the same delivery, never a second one. If the server refuses something you typed (the cash is not what is due, say) while you
still have signal, the form stays open with the reason, as before.

### Could not deliver

One window, no jargon:

1. Tap what happened: *Customer not there*, *Customer unreachable*, *Wrong address*, *Refused the order*, *No access*, *Damaged*, *Other*.
2. A **note** is required for *Damaged* and *Other* (the branch must be able to tell what happened) and optional for the rest.
3. **Add photo** of the door or the damage (optional).
4. Confirm. The customer is told: *We could not deliver your order. We will call you to arrange a new time.* The branch gets it in their queue.

The job stays with you: try again later (**Try delivery again**), or when it is back at the branch tap **Return to branch**.

---

## 5. When a job changes under you

- **The order was cancelled.** The job leaves your list at once and one calm message says *This order was cancelled. No need to go.* If you were looking at the job, its window closes by itself.
- **A manager gave it to another driver.** The same, with *This order was given to another driver. It is no longer yours.*
- A job you handed back yourself never shows that message.

---

## 6. My day and the cash you hold

**My day** shows four numbers (*Delivered*, *Not delivered*, *Handed back*, *Still to do*), when you went on duty, today's jobs with their state, and the cash:

> **Cash with you: QAR 450.00.** Hand it in at the branch.

The cash card turns **amber** (*It has been with you a while. Please hand it in soon.*) after 24 hours and **red** (*It is late. Please hand it in at the branch now.*) after 48 hours (the same limits as the dashboard's *Settings > Operations clock > Cash*).
Hand the cash in to a cashier or a branch manager; they count it with you. You cannot receive your own cash.

---

## 7. Going off duty

- **Off duty with nothing in your hands:** the switch turns off and any job you had not accepted goes to another driver.
- **Off duty while you still carry jobs:** the app says *You still have 2 jobs. Finish them or ask the branch to hand them back.* The switch stays on. Nothing is lost.
- **Signing out** switches you off duty first when you have nothing in progress. With jobs in hand it tells you they stay yours.
- **Phone off or no signal for 20 minutes:** you are set off duty so nobody waits for a driver who is not there. Jobs you had **not accepted** go to another driver.
  Jobs you accepted or are carrying are **never** taken away; the branch managers are told you went quiet carrying them.

---

## 8. Buttons or slide

The dashboard (*Settings > Staff and driver apps*) decides whether a step is a plain **button** or a **slide to confirm** (to avoid a stray tap on a motorbike). Both apply to *Accept*, *Picked up*, *On my way*, *I have arrived*, *I can't take this* and *Could not deliver*. The delivery form's last step (*Confirm delivery*) follows the same choice.

---

## For branch managers

- A job a driver hands back, a job nobody accepted after three drivers, and a driver who went quiet while carrying jobs reach **you** as a push and an inbox message (*Notification controls > Staff and driver alerts > Order still waiting*).
- A delivery the system gave up on shows in **Needs attention** as **Driver has not accepted** with the button **Assign driver**.
- **Proof of delivery** (order page) shows where the driver stood when he took the photo (*Not recorded* when the phone had no GPS), and the photo of a failed attempt, labelled.
- Customers get two new messages, switchable under *Notification controls > Orders*: **Driver is close** and **Driver has arrived**. Never for pickup orders.

---

## For developers

All routes are under `/api/v1/admin` with a staff token. Errors use the platform envelope (`code`, `message`, `context`); the app maps the `code` to its own sentence.

| Endpoint | What it does |
|---|---|
| `GET dispatch/driver/jobs` | the driver's open jobs, each with the context below |
| `GET dispatch/driver/jobs/{id}` | one of the driver's own jobs (404 once it is not his) |
| `GET dispatch/driver/history` (or `jobs?scope=history`) | the last 30 finished jobs, short form |
| `PUT dispatch/driver/duty` | on / off; `409 jobs_in_progress` (`context.count`) while he carries jobs |
| `GET dispatch/driver/day` | "My day" |
| `POST deliveries/{id}/arrived` | "I have arrived" (`latitude`, `longitude`, `accuracy` optional) |
| `POST deliveries/{id}/deliver` | adds optional `latitude`, `longitude`, `accuracy` |
| `POST deliveries/{id}/fail` | `reason_code`, `notes` (required for `damaged` and `other`), optional `photo` (multipart), `latitude`, `longitude`, `accuracy` |
| `POST dispatch/jobs/{id}/decline` | `reason` = `too_far` / `vehicle` / `shift_ending` / `other`, optional `note` |

Full request and response examples, the settings, the events and the scheduler line are in [DISPATCH.md](../operations/DISPATCH.md#the-drivers-side-job-context-arrival-hand-back-watchdog-and-my-day).

---

## بالعربية باختصار

- **الطلب الجديد:** بطاقة فيها العنوان وفترة التوصيل وما يهمّك قبل الانطلاق (يحتاج شخصين، مطلوب تركيب، حجم كبير، الدفع عند الاستلام) تظهر **فقط عندما تنطبق**. ملاحظات العميل ورمز البوابة في صندوق واضح، وأزرار كبيرة: **اتصال** و**الاتجاه** و**نسخ العنوان**.
- **لا تستطيع التوصيل؟** اضغط **لا أستطيع توصيله** قبل القبول واختر السبب؛ يُسند الفرع الطلب لسائق آخر ولا يعود إليك. وإن لم تردّ خلال ٥ دقائق يُعرض على السائق التالي.
- **في الطريق:** اضغط **وصلت** عند الباب فيصل العميل إشعار واحد، وقبلها بنحو ١٠ دقائق يصله «السائق قريب».
- **تعذّر التسليم:** اختر السبب (الملاحظة مطلوبة عند «تالف» و«أخرى»)، وأضف صورة إن شئت. يتصل الفرع بالعميل لترتيب موعد جديد.
- **إلغاء الطلب:** يختفي من قائمتك فوراً وتظهر رسالة واحدة: «تم إلغاء هذا الطلب. لا داعي للذهاب».
- **يومي:** أرقام اليوم والنقد الذي معك. يتحول إلى البرتقالي بعد ٢٤ ساعة وإلى الأحمر بعد ٤٨ ساعة؛ سلّمه في الفرع.
- **إنهاء الدوام:** لا يمكن مع وجود طلبات قبلتها ولم تُنهِها. وإن انقطع اتصال هاتفك ٢٠ دقيقة يُنهى دوامك تلقائياً، دون أن تُسحب منك الطلبات التي قبلتها.
