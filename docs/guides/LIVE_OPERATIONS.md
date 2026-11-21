# Live Operations Control

**One rule: nothing waits silently.** Every new order, delivery, pickup and cash balance that needs a person is shown, says how long it has
been waiting, and is one tap away from the action, for the right person only.

See also **[SEARCH_AND_LIVE_BOARD.md](SEARCH_AND_LIVE_BOARD.md)**: the *Live board* (every open order in lanes, with a wall display for a screen in the branch) and the global search (Ctrl/Cmd+K) build on the clock and the feed described here.

Four things work together:

| What | Where | For whom |
|---|---|---|
| **The order clock**: a coloured chip with the age of every wait | Orders list, Needs attention, dashboard card | everyone who sees orders |
| **Needs attention**: one list of everything that waits for a person | Menu > Overview > *Needs attention* (`/admin/operations/attention`) | anyone with orders, deliveries, payments or pickup access |
| **Alerts**: a push and an inbox message for every new order, a reminder when one waits too long, a toast on every page | phones, the app inbox, every dashboard page | the branch team |
| **Proof of delivery**: the driver's photo, who received it, where, and the cash collected | order page (side window) | staff who see the delivery's branch |

---

## 1. The order clock

Every wait has an age and one of three states. The state is never colour alone: each has its own shape and a word for screen readers.

| State | Looks like | Means |
|---|---|---|
| **On time** | green dot | waiting, within the time allowed |
| **Getting late** | amber clock | past the first limit: deal with it next |
| **Late** | red warning sign | past the second limit: deal with it first |

### What is measured, and the limits

The limits are settings (**Settings > Operations clock**). These are the starting values.

| What waits | Gets "getting late" after | Gets "late" after | Counted in |
|---|---|---|---|
| A **new order** nobody has accepted | 5 min | 15 min | opening hours |
| An order **being prepared** (accepted, not ready). Furniture needs time, so this is long | 45 min | 90 min | opening hours |
| A **delivery that is ready**, no driver named yet | 30 min | 60 min | opening hours |
| A driver **named but has not accepted** | 5 min | 10 min | opening hours |
| A **pickup** nobody collected | 2 days | 4 days | real days |
| **Cash** a driver or cashier still holds (also: a refund waiting for approval) | 24 hours | 48 hours | real hours |

Three more lines borrow a limit so they need no settings of their own: a **cancellation request** waiting for a manager uses the new-order
limits, a **failed delivery** uses the "ready, no driver" limits, and a **refund** waiting for approval uses the cash limits.

### Opening hours are not counted

The branch steps count **only the minutes the branch was open** (its weekly hours, breaks, overnight hours, holidays and temporary closures,
from *Branches > Hours*). An order placed at 23:00 is **not** red at 08:00 just because the shop was shut: its clock starts again when the
shop opens, and its tooltip says "The clock is paused while the branch is closed". The tooltip also shows the real time that passed.

- A branch with **no opening hours saved** (or every day marked closed) is measured in plain minutes, so a missing schedule never makes the dashboard go quiet.
- Time zone is Qatar (Asia/Qatar, no daylight saving).
- Only the last 14 days are walked; an older wait is simply late.
- Online (SADAD) orders start their clock **when they are paid**, not when they were placed. An unpaid online order is never on the clock.

### How to tune it

1. **Settings > Operations clock** (needs *settings.edit*). Each field has a one-line help text and shows the default.
2. Rules: whole numbers from 1 up; the **"getting late" time must be lower than the "late" time** (the page tells you if not). Largest values: 1440 minutes, 720 hours, 90 days.
3. Saving applies at once and is written to the audit log. Only fields you change are stored; the rest follow the defaults.

Rule of thumb: set "late" to the time after which you would phone the branch yourself, and "getting late" to about a third of it.

---

## 2. Needs attention

**Menu > Overview > Needs attention.** The number beside it is the count of things waiting (red when something is late, brass otherwise).
The list is grouped by urgency (**Late now**, **Getting late**, **On time**), the longest wait first, and every line shows:
*what* it is, *where / who* (branch code, customer, driver), the **age chip**, and **one button**.

| Line | Shown when | Needs | The one button |
|---|---|---|---|
| Waiting to be accepted | always (from the first minute) | orders.view | **Open order** (opens the order in a side window; accept it there) |
| Being prepared, taking long | amber or red | orders.view | **Open order** |
| Ready, no driver yet | always | deliveries.view | **Assign driver** (Deliveries, filtered to this order) |
| Driver has not accepted | amber or red | deliveries.view | **Reassign driver** + a call button for the driver |
| Delivery failed, needs a new plan | always | deliveries.view | **Reschedule** + a call button for the customer, and the reason the driver gave |
| Waiting to be collected | amber or red | pickup.view | **Re-issue pass** (a fresh pickup pass, in place; needs pickup.edit) |
| Cancellation request | always | orders.view | **Review request** (approve or reject in the side window; needs orders.approve) |
| Refund to approve | always | refunds.view | **Review refund** |
| Cash not handed in | amber or red | payments.view | **Receive cash** (Finance > Cash, *To hand in*) |
| Failed background jobs | at least one | settings.edit (company-wide) | **Open dashboard** |
| Campaign stuck "sending" for over 30 min | always | settings.edit (company-wide) | **Open campaigns** |

- **Who sees what:** every kind of line is checked against its own permission **and the branches that permission covers**, the same rule as the
  page that handles it. A manager of another branch sees nothing of yours. A person with only *pickup.view* sees only pickups.
- **Filters:** *All* / *Mine* (only what is assigned to you: your preparer parts, your deliveries, the cash you hold, your requests) / one chip per
  branch for people who work in several. They are kept in the address, so Back and sharing work.
- **It refreshes by itself:** every 20 seconds, and **at once** when a new order arrives. No need to reload.
- **Calm when empty:** "All clear. Nothing needs you right now."
- Only the 100 longest-waiting lines of each kind are shown (the page says when more exist).
- **On the dashboard home** a small card shows the worst five, the total, and "See all".

---

## 3. Alerts

### A new order for your branch

*Notification controls name: **New order for the branch** (`staff.order_new`).*

- Sent the moment an order is placed with **cash on delivery or pay at pickup**, delivery **and** pickup. An **online (SADAD) order alerts only after it is paid**; an unpaid online order never alerts anybody.
- **Who gets it:** the **active employees of that branch who can accept orders there** (*orders.edit*). Not other branches, not drivers without order rights, not suspended accounts. A split order alerts each branch for its own part.
- **How:** a push to their phones (in the language of each phone) **and** an entry in their in-app inbox. The text is only the order number and the number of items, e.g. *New order AB100123 · 3 items to prepare at your branch.* **No customer name, phone or address.**
- **Once only:** a replayed event or a retried job never alerts twice.

### A reminder when an order waits

*Notification controls name: **Order still waiting** (`staff.order_reminder`).*

- The command `orders:remind-unaccepted` runs every minute. When a new order turns **amber** it sends **one** reminder, and when it turns **red** it sends **one** more. At most two per order part.
- **Who gets it:** the branch managers (*orders.approve*) **and** the staff who can accept (*orders.edit*) at that branch.
- **Quiet while the branch is closed.** Nobody is woken at night; the clock only runs in opening hours anyway.
- If the order is accepted, cancelled, or still unpaid, no reminder is sent.

### Switching them off or changing the channels

**Content > Notification controls > Staff and driver alerts.** Each alert has a master switch and a switch for **Push** and **In-app**, on by default.
Switching an alert off sends **and stores** nothing. A reminder crossing that was switched off is not sent late when you switch it back on.

### Live toasts and the chime (every dashboard page)

- A toast slides in on **any** dashboard page for each new order of **your branches** (never other branches). A burst is collapsed: at most **one toast per 3 seconds**; orders that arrive inside that window show together as **"N new orders"**.
- Each order is announced **once**, and online orders only when they are paid.
- **The chime is off until you turn it on.** The speaker icon in the top bar switches it for you, on this device only (it is remembered in the browser). The fleet map shows its own toasts, so nothing is doubled there.
- If live updates are not available on your server, the list and the number in the menu still refresh every 20-30 seconds.

---

## 4. Proof of delivery

Open the order (or the delivery) and use **View proof**: the photo(s) the driver took, **who received the order and when**, the **driver**, **where the driver was** (with **Open in map**), and the **cash collected** (or still to collect) for cash-on-delivery orders.

- Photos are **private**. They are served only to staff with *deliveries.view* at **that delivery's branch**; anybody else gets "not found". There is no public link and no folder listing.
- A courier's own proof is shown as a link to the courier, never embedded.
- If the driver's position was not recorded, the card says so.

---

## 5. For the apps and the developers

Nothing here changes an existing API. The pieces the mobile apps can use:

### Events and payloads

| Name | Channel | Payload |
|---|---|---|
| `staff.order_new` (push + inbox) | FCM to the staff devices; inbox row; socket `notification.created` on `private-users.{userId}` | FCM `data`: `event` = `staff.order_new`, `order_id`, `number`, `fulfillment_id`, `branch_id`, `notification_id` (the inbox row), `deep_link` (a dashboard path; the app should open its own order screen from `order_id`), `category` = `transactional`. FCM `notification`: `title` "New order {number}", `body` "{n} item(s) to prepare at your branch...". |
| `staff.order_reminder` | same | the same data plus `level` = `amber` or `red`; title "Order {number} is waiting" or "...is late". |
| Inbox row | table `notifications` (the existing in-app mechanism) | `event`, `category` = `transactional`, `title`, `body`, `data` = `{deep_link, reference = order id}`, `read_at`. |
| `.order.live` | `private-live-orders` (staff who see every branch) and `private-live-orders.{branchId}` | the existing frame: `id`, `type` (`placed` or `status`), `order_id`, `number`, `status`, `previous`, `branch`, `branch_ar`, `customer` (first name), `total`, `currency`, `payment_method`, `fulfilment`, `at`. |

**What counts as a new order in a `.order.live` frame** (the dashboard and the apps should follow the same rule): `type = placed` and `status != pending_payment`; **or** a `status` frame with `previous = pending_payment` and `status = confirmed` (an online order that was just paid). Announce each `order_id` once.

### Settings keys (Settings service, group `ops_clock`)

`ops_clock.{pending|preparing|ready|driver}_{amber|red}_minutes`, `ops_clock.pickup_{amber|red}_days`, `ops_clock.cash_{amber|red}_hours`. Defaults are in `App\Domain\Operations\Services\OpsClock::DEFAULTS`.

### Code map

| Piece | File |
|---|---|
| The clock | `app/Domain/Operations/Services/OpsClock.php`, `BranchSchedules.php` (business minutes), `Support/OpsAge.php` |
| The inbox | `app/Domain/Operations/Services/AttentionFeed.php`; page `app/Livewire/Admin/Operations/NeedsAttention.php`; card `AttentionCard`; menu badge `AttentionBadge` |
| Chip | `<x-admin.ops.age-chip :state :label />` (`resources/views/components/admin/ops/age-chip.blade.php`) |
| Alerts | `app/Listeners/AnnounceNewOrder.php` (OrderPlaced and the paid status change), `Services/OrderAlerts.php`, `Services/StaffAlerts.php`, `app/Console/Commands/RemindUnacceptedOrders.php`; table `ops_reminders` (unique per part and level) |
| Live toasts | `resources/js/live-toasts.js` (`startOpsLive`), `live-toasts-core.js` (`asNewOrderRaw`, `createBurstGate`); mounted in `resources/views/layouts/admin.blade.php` |
| Proof | `app/Domain/Operations/Services/DeliveryProofs.php`, `Http/DeliveryProofController.php` (`GET /admin/deliveries/{delivery}/proofs/{proof}`), `<x-admin.sales.delivery-proof :delivery>` and the window `<livewire:admin.operations.delivery-proof-window />` |
| Tests | `tests/Feature/Operations/*`, `tests/js/live-toasts-core.test.mjs` (`vendor/bin/pest tests/Feature/Operations`, `npm test`) |

### Scheduler

```php
Schedule::command('orders:remind-unaccepted')->everyMinute()->withoutOverlapping(10);
```

---

## بالعربية باختصار

- **ساعة الطلبات:** كل انتظار له عمر ولون وشكل وكلمة: في الوقت، يقترب من التأخر، متأخر. خطوات الفرع تُحسب بساعات العمل فقط، فلا يبدأ الصباح متأخراً لأن المعرض كان مغلقاً ليلاً. تُضبط الحدود من **الإعدادات > ساعة العمليات**، ويجب أن تكون مدة «يقترب من التأخر» أقل من «متأخر».
- **يحتاج انتباهك:** قائمة واحدة بكل ما ينتظر شخصاً، الأسوأ أولاً، وأمام كل بند زر واحد. تظهر لكل شخص بحسب صلاحياته وفروعه فقط، وتتحدّث تلقائياً.
- **التنبيهات:** إشعار ورسالة داخلية لفريق الفرع عند كل طلب جديد (والطلب الإلكتروني بعد دفعه فقط)، وتذكير واحد عند بدء التأخر وآخر عند التأخر الشديد لمدير الفرع وفريق الطلبات، بلا إزعاج خارج ساعات العمل. تُدار من **المحتوى > التحكم في الإشعارات**.
- **النغمة:** متوقفة افتراضياً، وتُشغَّل من أيقونة السماعة في الشريط العلوي، وعلى هذا الجهاز فقط.
- **إثبات التسليم:** صورة السائق واسم المستلم والوقت والموقع والمبلغ المحصّل، لموظفي فرع التوصيلة فقط.
