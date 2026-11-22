# What does this number mean? (one definition per number)

**One rule: a number that appears on more than one screen is worked out in ONE place, and every screen asks that place.** The owner never sees two different
"on-time delivery", "orders delivered", "cash difference", "time to accept", "failed deliveries" or "sales" for the same branch and the same day on two screens.
Where two numbers are really different things, they carry **two different names** on screen, and this page says which is which.

This page is the answer to "what does this number mean?" for the **Daily close**, the **Weekly owner pack**, the **Scorecard** (branches and drivers), the
**dashboard home**, the **Live board**, **Customer value**, **Offer returns** and the **Sales report**. For how each page works, read its own guide
(`OWNER_PACK_AND_CUSTOMER_VALUE.md`, `BRANCH_AND_DRIVER_SCORECARD.md`, `SEARCH_AND_LIVE_BOARD.md`, `CASH_SHIFT.md`).

| | |
|---|---|
| Where the definitions live (code) | `App\Domain\Reports\Services\SalesBasis` (what a sale is) and `App\Domain\Reports\Services\Metrics\{Orders, Deliveries, Cash, Claims, Speed}`; stock is `StockLevels` |
| Who may read a number | the screen decides (its own permission and branch scope, listed below); the definition never changes with the viewer, only the branches it is asked about |
| The tests that keep it true | `tests/Feature/Reports/NumbersGoldenTest.php` (figures unchanged against release 1.0.216), `NumbersAgreementTest.php` (the same named number on every screen), `NumbersMetricsTest.php` (each definition at its edges); the one fixture is `NumbersSupport.php` |
| Settings | none. (The scorecard's *minimum cases* and the daily close's *closing time* change what is SHOWN or WHEN a day can be closed, never what a number means.) |

---

## 1. Rules that hold for every number

- **A day is a Qatar business day** (`Asia/Qatar`, `BusinessTime`), from 00:00:00 to 23:59:59 local time, stored in UTC. An order placed at 00:30 on the 11th is the 11th
  on every screen, though it is 21:30 on the 10th in UTC. A delivery at 00:05 on the 11th for an order promised for the 10th is **late** on every screen.
- **A week is Monday to Sunday** (ISO week) on the owner pack; the scorecard takes any whole days (up to 366). The same dates give the same figure on both.
- **A figure belongs to the day it HAPPENED, not the day of its order**, except sales and the speed of an order, which belong to the day the order was placed:
  a refund paid on Monday is Monday's refund; goods that come back on Thursday are Thursday's "returned"; a delivery made on Sunday is Sunday's delivery.
- **A driver's step happened when he pressed it**, also when his phone had no signal and sent it later (`acted_at`, see
  [OFFLINE_DRIVER_AND_STAFF_APPS.md](OFFLINE_DRIVER_AND_STAFF_APPS.md)): picked up, out for delivery, delivered, failed, the cash he took and the assembly answer carry
  that time on every screen. A delivery made at 23:50 and received at 00:10 is the earlier day's delivery (and on time for that day); a day that was already
  closed keeps the figures it had.
- **Now-numbers say so**: open work, cash held, unexplained differences and stock are "as they stand now". A past day shows today's value of them (a closed day keeps
  the value it had when it was closed). They have no "before".
- **Money is in QAR, in cents, never floats.** A rate is worked out from the sums (never an average of averages), as a percent with one decimal.
- **An order shared by two branches is one order for the company and one order of each branch.** Its amount is divided between the branches in proportion to what each
  branch's part is worth (items after discount, with tax, assembly, and the branch's own delivery fee), in cents, so the parts always add up to the order. On "all
  branches together" it is counted ONCE (see section 4).
- **Only real orders count.** An online order still waiting for payment, and one that expired unpaid, never happened. A cancelled order counts as an order that was
  placed (and cancelled), but is no sale.

---

## 2. The numbers

Columns: what the owner reads, in plain words; where it is worked out; which dates it uses; where it is shown.

### Sales and orders

| Number (as named on screen) | Plain words | Worked out in | Dates | Shown on (who sees it) |
|---|---|---|---|---|
| **Sales** (also "Sales today"; "Booked sales" on the sales report) | What customers were charged (items, delivery, tax, assembly, less discounts) for orders that are **not cancelled and not waiting for online payment**, the branch's own share of a shared order. "Booked": it says nothing about whether the money has arrived. | `SalesBasis::totals / byBranch / perDay` | the day the order was **placed** | Daily close (reports.view at the branch), Owner pack (settings.view + every branch), Dashboard (orders.view or reports.view), Sales report (reports.view). One figure. |
| **Orders** (the card "N orders" on the close, "Orders" on the pack, "Orders today", "Orders" on the sales report) | How many of those sales: orders that count as a sale. For the company: distinct orders. | `SalesBasis` (the same query) | placed | same screens as Sales |
| **Average basket** | Sales divided by orders, to the nearest cent. Not an average of daily averages. | `SalesBasis::average` | placed | Daily close, Owner pack |
| **Orders placed** (Daily close) = **Orders handled** (Scorecard) | Every real order with a part at the branch, **including cancelled ones**. Two names for one number: the close says "placed", the scorecard "handled". | `Orders::placed` | placed | Daily close, Scorecard |
| **Cancelled** (close) = **Cancellations** (scorecard) | Parts of orders cancelled that day (a part, not an order), real orders only. The scorecard also shows the reasons and the share of orders placed. | `Orders::cancelled / cancelledByReason` | the day it was **cancelled** | Daily close, Scorecard |
| **Payment mix** | The same sales split by how the customer chose to pay (cash on delivery, online, pay at pickup). | `SalesBasis::orderShares` | placed | Daily close |
| **Assembly revenue, gross** (sales report: "Assembly revenue (gross)", Assembly sales: "Gross") | The assembly fee of the units still on the order (fee per unit x units not cancelled), for orders that count as a sale, never more than the line still owes. A flat service charge: no discount or tax touches it. Not part of "Net sales" (items only), but part of "Sales" (the whole charge). | `AssemblySales` (over the same orders as `SalesBasis`) | the day the order was **placed** | Sales report (reports.view at the branch) |
| **Assembly refunded** (sales report, Assembly sales) | The assembly fee of the units that came back (fee x `quantity_returned`, never more than the gross). Attributed to the order's day, so a past period changes when a later return arrives. | `AssemblySales` | placed | Sales report |
| **Assembly revenue, net** | Gross minus refunded. | `AssemblySales` | placed | Sales report |
| **Assembly jobs / units** | Jobs: branch parts of orders that still have assembly left after returns (one visit each). Units: the units with assembly still on the order. | `AssemblySales` | placed | Sales report. **Not on the daily close or the owner pack yet.** |

### Delivery and failure

| Number | Plain words | Worked out in | Dates | Shown on |
|---|---|---|---|---|
| **Delivered or collected** (close) | Parts that reached the customer: delivered, or collected at the counter. A part counts once. This is the figure behind the pack's "Claims per 100 delivered" and the scorecard's claims rate. | `Orders::reached` | the day it was delivered / collected | Daily close; (denominator on Owner pack and Scorecard) |
| **Deliveries** (scorecard) | The delivery parts (not pickups) that reached the customer in the period. A pickup is counted in "Pickups collected in time". Delivered + collected = Delivered or collected. | `Deliveries::promises` | delivered | Scorecard |
| **On-time delivery** (scorecard) = **Delivered on the promised day / On time** (pack) | Of the deliveries that reached the customer in the period **and had a promised day**, the share that arrived on that day or earlier. By day, not by hour, in Qatar time. A delivery with no promised day is delivered but never late. A delivery moved to a new slot is measured against its **last** slot; the scorecard says how many were moved. | `Deliveries::promises`, `Deliveries::onTimeSql` | delivered | Owner pack, Scorecard (branch and driver, the driver's own deliveries) |
| **Failed deliveries** | Deliveries whose (last) failure fell in the period, counted once however many attempts. The scorecard adds the reason and who it was down to (customer side / team side / not stated) and the share of the deliveries that ended. | `Deliveries::failures` | the day it **failed** | Daily close, Scorecard (branch and driver); the pack carries it in its data |
| **Returned by customers** (close) | Return or damage claims whose **goods came back** to the branch that day. Not the same as a claim being opened (below). | `Claims::received` | received | Daily close |
| **Claims per 100 delivered** (pack) = **Returns and claims** (scorecard) | Return or damage claims **opened** in the period, for every 100 parts that reached the customer in the period. "n/a" when nothing reached a customer. | `Claims::opened`, `Orders::reached` | the day the claim was **opened** | Owner pack, Scorecard |
| **Refunds paid** (close) | Refunds paid out (processed) that day for orders the branch took part in, the branch's share, each refund once. | `SalesBasis::refunds` | processed | Daily close |
| **Refunds** (sales report) | The same refunds **less** those whose money the order lines have already dropped (a cancellation, an item change), so net sales does not take the same money off twice. Always equal to or less than "Refunds paid". | `SalesBasis::refunds` with the two reasons left out | processed | Sales report |

### Cash

| Number | Plain words | Worked out in | Dates | Shown on |
|---|---|---|---|---|
| **Cash difference** (close: "Difference found when counting"; pack: "Cash diff."; scorecard: "Cash difference, net"; driver: "Cash difference on his hand-ins") | What the cashiers found when they counted the cash handed in: the **signed** total (short is negative), over the hand-ins counted in the period. The scorecard also gives it ignoring the sign, and how many hand-ins were not exact. | `Cash::handIns` | the day the hand-in was **counted** | Daily close, Owner pack, Scorecard (branch and driver) |
| **Differences nobody has explained** | Hand-ins with a difference that is still open, of **any date**. | `Cash::openVariances` | now | Daily close, Owner pack ("what needs attention"), Scorecard |
| **Cash held** = close: "All cash not yet handed in (any day)" = pack: "cash has not been handed in yet" = scorecard: "Cash in hand now" / driver "Cash in hand" | Cash (a card terminal is not cash) that was taken, is worth something and has not been handed in, of **any day**. The scorecard adds how much is older than the cash limit (Settings > Operations clock, 24 h) and the age of the oldest; the "Needs attention" inbox lists the late ones by this same rule. | `Cash::held / heldQuery` | now | Daily close, Owner pack, Scorecard, Needs attention |
| **Cash collected**, **Of that, handed in**, **Still with the people who took it** (close) | Cash taken **that day**, how much of it a cashier has received, and the rest. "Still with the people" is that day's cash only; "All cash not yet handed in" is every day's. | `Cash::takenQuery` (the close) | collected | Daily close |
| **Collected today** (dashboard), "Online ... Cash and card ..." | Money that **actually came in** today: online payments captured plus every cash **and card-terminal** collection staff recorded. Its "Cash and card" part is therefore the close's cash **plus** the card terminal. | `SalesBasis::collected` | paid / collected | Dashboard (payments.view or reports.view) |
| **Petty cash paid out** (cash page "Petty cash paid out", Z-report block) | Money paid out of the open drawer for small expenses, **not counting payouts that were reversed**, with their number. A payout needs a reason; above QAR 100 (setting) also a named approver. | `CashPayoutService` / `CashShiftService::figuresFor` (frozen in the Z-report summary when the shift closes) | the shift it was paid in | Cash page (payments.view at the branch), Z-report print and PDF. Aggregates and staff names only. |
| **Expected cash** (shift: "Cash that should be in the drawer now", Z-report) | Opening float + hand-ins received - hand-ins banked - cash refunds paid - petty cash paid out. The count at closing is compared with it (the variance). A payout cannot exceed it. | `CashShiftService::figuresFor` | the shift | Cash page, Z-report |

### Speed

| Number | Plain words | Worked out in | Dates | Shown on |
|---|---|---|---|---|
| **Time to accept** and **Time to prepare** | From when the part **could** be accepted (its creation, or the order's confirmation if that came later, i.e. when the online payment landed) to when it was accepted; from accepted to ready. In **opening-hours minutes** (a night when the branch was shut is not slowness; a branch with no hours counts wall-clock minutes). The same readings feed both screens. | `Speed::minutes` | parts of the orders **placed** in the period | see the next two rows |
| ... as an **average** (Daily close: "Average time to accept / prepare") | The plain average of the day's readings in whole minutes, with the number of orders it was measured on; "Nothing measured" when there were none. | `Speed::average` | placed | Daily close |
| ... as a **median** and **slowest tenth** (Scorecard: "Time to accept / prepare") | The middle reading, and the time that 9 in 10 beat. Never only an average; one reading is its own median. | `ScorecardStats::median / p90` over the same readings | placed | Scorecard |
| **Time on the road** (scorecard, drivers) | From "out for delivery" to "delivered", real minutes (a road is not paused by opening hours), median and slowest tenth; the plain average once, "for reference". | the driver query in `ScorecardDriverNumbers` | delivered | Scorecard (Drivers) |

### Open work, stock, the live board

| Number | Plain words | Worked out in | Dates | Shown on |
|---|---|---|---|---|
| **Placed this day and still open** / **Never accepted** (close) | Of the orders placed that day, the parts not yet delivered, collected, cancelled or returned, and the ones nobody has accepted. As they stand now. "Open" is the **same list of states as the live board's**. | `Orders::standing`, `Orders::OPEN_STATUSES` (= `LiveBoard::OPEN`) | placed; the value is now | Daily close; the pack's "orders still waiting to be accepted" is the sum over its week |
| **To prepare** (dashboard) | Parts waiting to be accepted, accepted or being prepared, not waiting for online payment. | `Orders::TO_PREPARE` | now | Dashboard (orders.view) |
| **Out for delivery / Awaiting pickup** (dashboard) | Parts on the road (dispatched or out for delivery); parts ready for the customer to collect. | `Orders::ON_THE_ROAD`, status `pickup_ready` | now | Dashboard |
| **Live board lanes** | Every open part is **one card in one lane**: New, Preparing, Ready, Out for delivery, Pickup waiting, Needs attention. A late or broken card moves to Needs attention, so a lane count is **not** the dashboard's tile: *To prepare* = the cards that started in New or Preparing, wherever they sit now; *Awaiting pickup* = the cards that started in Pickup waiting. | `LiveBoard` | now | Live board (orders.view, the viewer's branches) |
| **Items out of stock / running low** | Out: nothing available and the branch has held it before. Low: something left, but no more than the item's own minimum, or the shop-wide default (3). | `StockLevels` | now | Daily close, Owner pack ("Out of stock"), Dashboard, Stock report |

### Customers and offers

| Number | Plain words | Worked out in | Shown on |
|---|---|---|---|
| **Lifetime spend, Orders** (customer) | What the customer was charged for orders that count as a sale: the **sales definition above**, summed over the customer. A branch-limited viewer sees only what was spent at their branches; the branches' parts add up to the owner's figure. | `CustomerValue` (live or the 30-minute rollup) over `SalesBasis` | Customer value, customer window, customers list |
| **Sales after discount** (offer return) | The same booked sales, summed over the orders that came through the offer (a cancelled order is in neither). | `OfferReturn` over `SalesBasis` | Offer returns |

### Two different numbers with a similar name (never the same word)

| If you see ... | ... it is not ... | Why they differ |
|---|---|---|
| **Sales** / **Booked sales** | **Net sales** (sales report) | Booked sales is the whole charge with delivery and tax. Net sales counts items only: item value less discounts, less items cancelled afterwards, less refunds the lines do not already explain. The report shows both side by side. |
| **Average basket** (close, pack) | **Average order value** (sales report) | Basket = booked sales / orders. Order value = **net** sales / orders. |
| **Orders** / **Orders today** | **Orders placed** / **Orders handled** | Orders = the ones that count as a sale (not cancelled). Placed / handled = every real order, including the cancelled ones. |
| **Cash collected** (close) | **Collected today** (dashboard) | The close's is cash only; the dashboard's "Cash and card" adds the card terminal, and it adds online payments. |
| **Cash in hand now** (scorecard) | **Cash collected** (close) | Cash in hand is every day's, not yet handed in; collected is one day's. |
| **Not handed in** (Reports > Finance collections) | **Cash held** / **All cash not yet handed in** | The report lists the collections **made in the period** (by person, kind and tender) and how much of that period's cash is not handed in yet; cash held is every day's, cash only. |
| **Refunds paid** (close) | **Refunds** (sales report), **Pending refunds** (dashboard) | All refunds paid / those the lines do not explain / refunds still waiting for a decision. |
| **Returned by customers** (close) | **Returns and claims** (scorecard), **Claims per 100 delivered** (pack) | Goods that came back that day / claims opened in the period. |
| **Delivered / Failed** in Reports > Deliveries | **Deliveries / Failed deliveries** (scorecard) | The report is about deliveries **created** in the period and their status now, by driver; the scorecard counts what happened in the period. Likewise its "Avg. minutes on road" starts at the branch pick-up and is an average, the scorecard's "Time on the road" starts at "out for delivery" and is a median. |
| **Out for delivery** (dashboard tile) | **Out for delivery** (board lane) | The tile counts every part on the road; the lane leaves out the ones that moved to Needs attention. |

---

## 3. What changed in this release (for the owner)

Nothing visible was added. Everything was checked against the release that was running (1.0.216) on one rich set of data: **every figure is the same, except the
ones below, which were wrong.** The comparison is a test (`NumbersGoldenTest`), so a figure that moves without being listed fails the build.

| What | Before | Now | Why |
|---|---|---|---|
| **Owner pack, "All branches": Orders** and so **Average basket** | the orders of the branches were added up, so an order shared by two branches was counted **twice** (20 orders, average 189.50 in the test week) | counted once (19 orders, average 199.47), the same as the dashboard and the sales report | an order is one order for the company |
| **Scorecard, "All branches together": Orders handled**, **Cancellations rate**, **Replacements rate**, and their change against the period before | same double count (23 became 22 in the test period) | counted once | same |
| **Daily close: average time to accept / prepare** | measured on the first 2,000 parts of the day | the newest 20,000 (the scorecard's sample) | one sample for both screens; no branch has 2,000 parts in a day, so no real figure moves |
| **Scorecard: claims rate** | divided by delivered + collected pickups that had a "ready" time | divided by the parts that reached the customer, as the pack does | one denominator; a pickup always has a ready time in real data, so no real figure moves |
| **Sales report: Refunds** (and so Net sales) | worked out the same way, but by scanning every order of the company (75 ms on 150,000 orders) | the same figure, from the refunded orders only (2 ms) | speed only |

The daily close's own "orders placed" for a day when a branch is already **closed** adds the branches' frozen counts: a shared order counts once in each closed
branch. That is a property of freezing the close and is unchanged (see the daily close guide).

---

## 4. For developers: how to add or change a number

1. **Look here first.** If the measure exists (an order placed, a delivery on time, cash held, a difference found, a refund, a reading of speed), call it. Never write the SQL a second time.
2. **A new shared number goes in `Services/Metrics/*`** (a small static class, one grouped query, bounded by the window, started from an indexed date) or, if it is about
   what a sale is, in `SalesBasis`. It takes the list of branches and a window and returns one row per branch. A screen that needs one branch and one day asks for one branch and
   one day; the weekly pack asks for all branches and a week.
3. **"All branches together" is never the sum of the branches** for anything counted by order: ask for the distinct count (`Orders::placed(...)['together']`,
   `SalesBasis::byBranch(...)['together']`). Amounts and counts of parts do add up.
4. **Name it once.** Two screens may show the same number under the same name only if it is the same definition. Where two definitions are both legitimate, give them two names
   and add the pair to the table above.
5. **Add the number to `NumbersSupport.php`'s snapshot** (so the golden test sees it) and to an agreement test (so every screen that shows it is compared with the others).
6. Money is `Money`, minutes are opening-hours minutes unless the measure is a road, every time is a Qatar day through `BusinessTime::dateRange`.

Other workers' areas read these definitions too: the cash shift / Z-report reads cash through `Cash::*`, and the sales report's assembly rows through `SalesBasis` and `AssemblySales`.

---

## 5. Design note: decisions, defaults, trade-offs

**Decisions**

- **`SalesBasis` is the base.** It was already the single definition of a sale and is untouched, except for three additive helpers (`byBranch`, `average`, `refunds`).
- **Small static classes, no framework.** `Metrics\Orders`, `Deliveries`, `Cash`, `Claims` are static (pure queries); `Speed` is an object because it needs the branches' opening hours.
  Each returns plain arrays keyed by branch, so a screen shapes them (a rate, a minimum sample, a language) without changing a definition.
- **The stricter, already-tested definition won** wherever two screens differed: the exception inbox's "cash held" rule (cash tender, collected or part-collected, something to hand in)
  for the scorecard, the close and the pack; the scorecard's on-time rule (delivery parts delivered in the window) for the pack; the scorecard's 20,000-part speed sample for the close.
- **Two names for two numbers, and the same name only for the same number**, as in section 2. Labels that were already two names for one number ("Orders placed" / "Orders handled",
  "Sales" / "Booked sales") were **not renamed**: they are the words each page already teaches and a rename would touch the translations of three pages. They are listed in the table.
- **The scorecard's *minimum cases* is a display rule, not a definition.** The same 7 of 9 deliveries is "77.8%" on the pack and "not enough data yet, 9 of 10 cases" on the scorecard; both screens count 9 and 7.
- **No migration.** Every query uses an index that already exists (checked below).

**Defaults:** none added. (The cash limit, the minimum cases and the closing time are the existing settings.)

**Trade-offs and limits (said plainly)**

- The scorecard is cached for 120 seconds (cache key `scorecard:v2`, changed so nothing built before this release is shown), the pack and the close are worked out when asked.
- "Orders placed" on the daily close of a **closed** day is the number it was closed with; a shared order may count once in each closed branch (above).
- The weekly "never accepted" line of the pack is the sum of the days' "never accepted", every branch, as they stand now.
- Reports > Deliveries is a different, cohort-style report (see the table). Aligning its words with the scorecard's is an owner decision.

**Owner decisions that may be wanted**

1. Whether to **unify the words** for the same number: "Orders placed" (close) / "Orders handled" (scorecard); "Sales" / "Booked sales"; and to rename "Average order value" (sales report) to "Average net order value".
2. Whether Reports > Deliveries should say "created in the period ... so far" on its Delivered / Failed columns, or follow the scorecard's windows.
3. Whether the pack should print the order count of each branch as "orders with a part here" (they add up to one more than the company when an order is shared) or leave it as is.

**Performance (nothing regressed; checked on a scale copy, 150,000 orders, 163,000 parts, 120,000 deliveries, 38,600 cash collections, 12,000 hand-ins, 2,000 claims, best of three, same machine, old = release 1.0.216)**

| Work | Old | New | Queries (old / new) |
|---|---|---|---|
| Weekly owner pack, 4 branches | 193 to 208 ms | 157 to 210 ms | 22 / 24 |
| Daily close, one day x 4 branches | 91 to 112 ms | 115 ms | 60 / 60 |
| Scorecard Branches, 30 days and the 30 before, cold | 495 to 550 ms | 510 to 576 ms | 20 / 22 |
| Scorecard Drivers, same | 69 to 77 ms | 63 to 74 ms | 11 / 11 |
| Dashboard tiles and chart | 6 ms | 5 ms | 2 / 2 |
| Sales report: refunds, 30 days, all branches | 65 to 80 ms | 2 ms | 1 / 1 |

The two extra scorecard queries are the "reached the customer" count (one per period, 4 ms, a bitmap OR of two existing indexes); the company's order count rides on the
orders query as a second grouping, so it costs no extra scan. The weekly company line rides on the sales query the same way. `EXPLAIN (ANALYZE)` of the new shared queries:

| Query | Time | Plan |
|---|---|---|
| `Orders::reached` (30 days, 4 branches) | 4.1 ms | bitmap OR of `order_fulfillments (branch_id, delivered_at)` and `(branch_id, picked_up_at)` |
| `Deliveries::failures` (30 days) | 2.4 ms | `deliveries (branch_id, failed_at)` |
| `Deliveries::promises` (30 days, with the moved count) | 6.8 ms | `order_fulfillments (branch_id, delivered_at)`; the moved check probes `deliveries (order_fulfillment_id)` and `delivery_status_history (delivery_id, to_status)` per promised delivery |
| `Claims::opened` / `Claims::received` | 0.2 ms | `return_requests (branch_id, created_at)` / `(branch_id, received_at)` |
| `Cash::handIns` | 0.8 ms | `cash_handovers (branch_id, created_at)` |
| `Cash::held` | 0.8 ms | the partial index of cash not handed in |
| `Cash::openVariances` | 1.2 ms | sequential scan of `cash_handovers`: a table that grows by a few hand-ins a day (12,000 rows here); an index on it would cost more than it saves |
| `Orders::placed` (30 days, with the all-branches count) | 35 ms | scan of the window's parts joined to the orders: the same plan and time as the query it replaces (a 30-day window at four branches is a seventh of the table, so the planner scans) |
| `SalesBasis::byBranch` (a week) | 35 ms | the existing sales pipeline of the pack (unchanged), now also giving the company line |
| `SalesBasis::refunds` | 0.2 ms | only the refunded orders are read |

**Mutation checks (each definition was broken on purpose, the three number tests were run, the code was put back and checked identical)**

| Broken on purpose | Tests that failed (of 48) |
|---|---|
| The on-time rule made strict (`<=` became `<` in `Deliveries::onTimeSql`) | 9: the golden comparison, the on-time and driver agreement, the minimum-sample case, the one-order world, the two-branch manager's line, and three edge tests of the definition itself |
| The owner pack's "All branches" adds the branches' orders up again (the shared order counted twice) | 2: the golden comparison and the "counted once on the pack, the sales report and the dashboard" agreement |
| The scorecard's "All branches together" adds the branches' orders up again | 3: the golden comparison, the scorecard's company line, and the two-branch manager's line |
