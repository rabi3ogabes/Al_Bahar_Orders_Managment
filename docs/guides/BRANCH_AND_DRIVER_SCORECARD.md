# Branch and driver scorecard (Insights > Scorecard)

**One question per tab: "How are the branches doing?" and "How are the drivers doing?"** Each branch and each driver, over a period, set beside the period of the
same length just before it. Built on the timestamps the platform already stores (placed, accepted, ready, delivered, failed, cash handed in); nothing new is
asked of staff.

The page is **fair by design**: every number says how many cases it is built from, a number built from too few cases says "not enough data yet" instead of a
percentage, times are a median and a slowest tenth (never only an average), failures a customer caused are shown apart from failures the team caused, arrows
show a direction and never "good" or "bad", and **nothing ranks people** (drivers are listed alphabetically; a column can be sorted by whoever wants to look).

| | |
|---|---|
| Where | Insights > **Scorecard** (`/admin/scorecard`) |
| Who sees it | anyone holding `reports.view`, for the branches that right covers. The owner and admins: every branch. A branch manager: their own branches and the drivers of those branches. Drivers and ordinary staff do not hold the right: no menu entry, the route answers 403. |
| CSV | `reports.export`, same branches, every export written to the audit log (`scorecard.exported`) |
| The one setting | **Minimum cases** (button on the page, needs `settings.edit` company-wide): default 10, between 1 and 200. Saved as `scorecard.min_sample`, so every change is in the audit log (`settings.updated`). |
| Menu / route / permission | `admin.scorecard.index`, `permission:reports.view`, icon `timer`, label `scorecard.nav` |

---

## 1. What the owner and a manager see

**The period** (one row of buttons, in the address so Back and sharing work): *Last 7 days*, *Last 30 days* (the default), *Last 90 days*, *Custom range* (two dates,
**366 days at most**, the last day may be today, never later). A preset is made of **complete days ending yesterday**, so the comparison is never a finished month
against half a day. A custom range that ends today says "Today is counted so far". A range that cannot be used (too long, backwards, in the future, not a date)
falls back to the last 30 days and says why under the period. Every number is set beside the period before: rates and times with an arrow and words ("Up 3.2 pts"), counts and cash with "before: …". Under the period, one line spells out both windows ("12 Nov – 18 Nov 2026 · Every change compares with
5 Nov – 11 Nov 2026, the 7 days before").

**Branches tab.** First screen: the answer for all the branches the viewer sees together (or the one branch): *Orders handled*, *Time to accept (median)*,
*On-time delivery*, *Cash difference, net*, each with its change against the period before. Then one card per branch with four numbers (orders handled, on-time
delivery, median time to accept, median time to prepare), one line of trouble ("2 failed · 1 cancelled", the cash difference) and **All the numbers**, which opens
a side window with every figure and, under each, the one line that says how it is measured:

- *How fast the branch worked*: orders handled; time to accept and to prepare, each as the **median** and the **slowest tenth** (90th percentile), with the number of orders measured.
- *Keeping its promise*: on-time delivery with how many were moved to a new slot and how many had no promised day; pickups collected in time.
- *When it went wrong*: failed deliveries with the **reason breakdown**, each reason tagged *Customer side* / *Team side* / *Not stated*, and the share that was down to the team alone; cancellations with their reasons.
- *After delivery*: returns and claims (with reasons) and replacements offered.
- *Cash*: difference net and ignoring the sign, hand-ins that were not exact, differences nobody has explained, cash held longer than the limit.

**Drivers tab.** First screen: all drivers together (deliveries, on-time, failure rate, cash in hand now), then a table (cards on a phone), **alphabetical by default**;
a click on a column header sorts it (arrow and `aria-sort`, never colour). Columns: deliveries, on time, time on the road (median), failed, jobs given back
(handed back and not answered), cash in hand (with the age of the oldest collection and "over the 24 h limit" in words), cash difference. A click on a driver opens a
**side window** with every number and **the orders behind them**: order number, when, branch code and what happened (delivered on the promised day / N days late /
failed and why / handed the job back and why / did not answer in time / cash in hand / hand-in counted exactly or with a difference). Newest first, 100 rows per
kind at most (it says so when it cuts). **No customer name, phone or address appears anywhere**: the order number is the only identifier.

Also on the page: **Refresh** (forgets the short cache and reads again), **Download CSV**, **Minimum cases** (settings), a "How to read this page" note, "Numbers as of 14:05".
States: loading (the content dims and a spinner shows), empty (no branch visible / no driver), error (plain message and *Try again*; nothing is changed).
English and Arabic, right to left, logical CSS properties, 44 px touch targets, labels on every control.

---

## 2. How every number is measured

All dates are **Qatar days** (`BusinessTime`), the window is the first instant of the first day to the last instant of the last day, stored in UTC. Only **real
orders** count: not waiting for online payment, and not an online order that expired unpaid (`Orders::realSql()`, the one definition the other screens use too).

### Branch numbers

| Number | Definition |
|---|---|
| **Orders handled** | Distinct orders with a part at this branch, placed in the window. A count: always shown. |
| **Time to accept** | For the parts of those orders that were accepted: from when the part **could** be accepted (its creation, or the order's confirmation if that came later, i.e. when an online payment landed) to when it was accepted, in **opening-hours minutes** (`BranchSchedules`, the order clock's own rule: a night the branch was shut is not slowness; a branch with no hours counts wall-clock minutes). Median and 90th percentile (`percentile_cont`: linear interpolation, the median of 10, 20, 30, 40 is 25). |
| **Time to prepare** | Same parts, from accepted to ready (`ready_at`), same clock, same statistics. A part not ready yet is left out. |
| **On-time delivery** | Delivery parts that **reached the customer in the window and had a promised day** (`slot_date`): the share delivered **on that day or earlier, in Qatar time**. Counted by day, not by hour (the same rule as the weekly owner pack). Parts with no promised day are delivered but not counted for or against (shown: "N deliveries had no promised day"). |
| **Rescheduled slots** | The promised day is the **last slot the customer was given** (rescheduling replaces `slot_date`). So a delivery moved to a new slot is measured against the new one, and the card says **how many of the measured deliveries were moved first** (a delivery that was ever in status `rescheduled`), so a branch cannot hide behind reschedules without the owner seeing the count. |
| **Pickups collected in time** | Pickups collected in the window (with a ready time), plus pickups whose pass **ran out inside the window with nothing collected**; in time = collected within `platform.pickup.token_ttl_hours` (72) of becoming ready (the life of the pass; re-issuing a pass does not stretch it). A branch whose customers never come is therefore not shown as perfect. |
| **Cancellations** | Parts cancelled in the window (real orders), as a share of the orders **placed** in the window; the reasons are the ones written in the part's history when it was cancelled (the customer's list and the manager's list; anything else reads "Other"). |
| **Failed deliveries** | Deliveries whose (last) failure fell in the window, as a share of the deliveries that **ended** in the window (delivered + failed). By reason, and by side (below). |
| **Failures down to the team** | Only the failures the team could have prevented (goods **damaged**), as a share of the deliveries that ended. |
| **Returns and claims** | Return and damage claims **opened** in the window (`return_requests.created_at`), as a share of the parts that reached the customer in the window (delivered or collected); reasons listed. |
| **Replacements offered** | Distinct parts for which a replacement was offered (`order_substitution_requests.asked_at` in the window), as a share of the orders placed. |
| **Cash difference** | What cashiers found when counting hand-ins received in the window: **net** (signed, short is negative), **ignoring the sign**, how many hand-ins were not exact, and how many were counted. |
| **Differences nobody has explained** | *Now*, any date: hand-ins whose variance is still open. |
| **Cash held longer than the limit** | *Now*: cash collected, not handed in, older than the Operations clock's **cash limit** (Settings > Operations clock, 24 h by default; the same limit as the exception inbox), with the amount and the age of the oldest. Reaching the limit exactly counts as late, like the order clock. |

"Now" numbers have no "period before" (a stock of cash has no history here), so they show no arrow.

### Who a failed delivery is the fault of

| Side | Reasons | Why |
|---|---|---|
| **Customer side** | customer not home, customer unreachable, wrong address, refused by the customer, no access to the building | nothing the branch or the driver could do differently on the day |
| **Team side** | goods damaged | packing, loading, driving |
| **Not stated** | "Other" (and anything typed by hand) | counted on neither side: an unknown reason is never blamed on anyone |

"Wrong address" is on the customer side because the address is what the customer gave. When a staff member typed it in for the customer the branch could have
caught it; the data cannot tell the two apart, so the owner should read that reason with that in mind.

### Driver numbers (only the work and the cash that belong to the branches the viewer may see)

| Number | Definition |
|---|---|
| **Deliveries made** | Deliveries of this driver that reached the customer in the window. |
| **On-time deliveries** | As for a branch, on the driver's deliveries that had a promised day. |
| **Time on the road** | From *out for delivery* to *delivered*, in **real minutes** (a road is not paused by opening hours); deliveries with no *out for delivery* time are left out. Median and 90th percentile (taken by the database); the plain average is given once, in the driver's window, "for reference" beside them. |
| **Failed deliveries** | The driver's deliveries that failed in the window, as a share of those that ended, by reason and side; and the share down to the team. |
| **Jobs given back / not answered** | `delivery_declines` in the window: jobs the driver **handed back** (too far, wrong vehicle, shift ending, other), jobs he **did not answer in time** (`timeout`), and jobs **taken back when he went off duty** (`off_duty`, shown but not counted against him). The rate is (handed back + not answered) out of the jobs that reached him: delivered + failed + handed back + not answered. |
| **Cash in hand now** | Cash collected by the driver at these branches, not handed in; with the count, the amount and the age of the oldest collection; "over the 24 h limit" when the oldest has reached the cash limit. |
| **Cash difference on his hand-ins** | Hand-ins he made in the window: net, ignoring the sign, how many were not exact. |

**Who is a driver:** an active delivery driver of one of those branches (Branches > Employees), **or** anyone who was given a delivery there in the window. A cashier
who takes cash at the counter is not a driver and does not appear.

### The fairness rules

- **Minimum sample** (setting, default 10): a percentage, a median or a slowest tenth built from fewer cases shows **"Not enough data yet"** with "3 of 10 cases so far"; with no case at all it shows "Nothing to measure in this period". Counts (orders handled, deliveries made, how many failed) are facts and always shown. The minimum applies to **each number's own cases** (on-time delivery counts deliveries with a promised day, not orders).
- **The change against the previous period** is given only when **both** periods had enough cases; otherwise the number says "No comparison" and its tooltip (and a screen-reader note) says why.
- **Medians and the slow end**, never only an average. The page also gives the number of orders each time is built from.
- **No leaderboard.** Default order is alphabetical; no medals, no red or green; arrows have words ("Up 3.2 pts", "No change") and carry no judgement.

---

## 3. Agreement with the daily close and the weekly owner pack

The owner must never see two different numbers for the same thing, so **none of these numbers is defined by the scorecard any more**: the scorecard, the daily close, the weekly owner
pack and the dashboard all ask the same shared definitions (`App\Domain\Reports\Services\Metrics\*` and `SalesBasis`), written down in **`NUMBER_DEFINITIONS.md`** (what each
number means, which screens show it, and the pairs that look alike but are different). `ScorecardBranchNumbers` only asks them about a window and keeps what is the scorecard's own: the
reasons, the pickups collected in time and the replacements offered. The tests compare the screens day by day (`NumbersAgreementTest`) and against the release that ran before the move
(`NumbersGoldenTest`).

| Number | Shared definition | Same on |
|---|---|---|
| Which orders are real | `Orders::realSql` | close, pack, scorecard |
| Orders handled / placed | `Orders::placed` | close ("Orders placed"), scorecard |
| Time to accept / prepare | `Speed::minutes`: the same readings; the close takes their **average**, the scorecard their **median and slowest tenth**; one sample (the newest 20,000 parts of the window) | close, scorecard |
| On-time delivery | `Deliveries::promises` | pack, scorecard (branch and driver) |
| Cancelled parts | `Orders::cancelled / cancelledByReason` | close, scorecard |
| Failed deliveries | `Deliveries::failures` | close, scorecard (branch and driver), pack data |
| Claims opened and claims rate | `Claims::opened`, `Orders::reached` | pack, scorecard |
| Cash difference and number of differences | `Cash::handIns` | close, pack, scorecard (branch and driver) |
| Differences nobody has explained | `Cash::openVariances` | close, pack, scorecard |
| Cash held | `Cash::held`: cash, collected or part-collected, something to hand in, not handed in; the "Needs attention" inbox lists the late ones by the same rule | close, pack, scorecard, drivers, inbox |

"All branches together" counts an order shared by two branches **once** (it used to be the sum of the branches' own counts, which counted it twice): orders handled, and the cancellation and
replacement rates that are shares of it.

---

## 4. Speed

One grouped query per family of figures (placed, cancelled with reasons, timing rows, delivery outcomes, pickups, failures, claims, replacements, hand-ins; then the
cash that stands now), each bounded by the window and by the viewer's branches, run for the window **and** the window before: **28 queries for the Branches tab and 14
for the Drivers tab, however many branches, drivers or orders**. A test checks that eight branches still use fewer than 40 queries. The answer (plain arrays, no customer data)
is kept **120 seconds** under a key made of the tab, the window, the branches, the minimum sample and the cash limit (never the person), and **Refresh** forgets it.
The only rows read one by one are the order timestamps whose opening-hours minutes can only be counted in PHP: at most the newest **20,000** orders of a window
(the card says "Measured on the newest 20,000 orders" when it cuts).

**Migration** `2026_11_22_150000_scorecard_indexes.php` (built `CONCURRENTLY`, `down()` drops them, safe to run twice), each justified by `EXPLAIN (ANALYZE)` on the
seeded scale database (200,000 orders over 400 days, 142,000 deliveries, 580,000 delivery history rows, 61,000 cash collections, 40,000 replacements):

| Index | Question | Without | With |
|---|---|---|---|
| `deliveries (branch_id, delivered_at) WHERE delivered_at IS NOT NULL` | the deliveries a branch or driver finished in a window | 44 ms (sequential scan of all 142,000 deliveries) | 33 ms (bitmap index scan, 30 days of all drivers); the gap grows with the table, because a window reads a fixed number of rows and a scan reads them all |
| `deliveries (order_fulfillment_id)` and `delivery_status_history (delivery_id, to_status)` | "was this delivery moved to another slot?" (both were foreign keys with no index) | 472 ms (hash join over two sequential scans) | 138 ms, one probe on each index per delivery |
| `order_substitution_requests (branch_id, asked_at)` | replacements a branch offered | 10.8 ms (sequential, 40,000 rows) | 4.5 ms |
| `cash_collections (branch_id, collected_at) WHERE handed_in_at IS NULL AND tender = 'cash'` | cash still held (a small slice of a table that only grows) | 11 ms (sequential) | 5 ms |

The other families use indexes that already exist (`orders_placed_at_index`, `order_fulfillments_branch_delivered_at_index`, `…_picked_up_at_index`,
`…_cancelled_at_index`, `deliveries_branch_failed_at_index`, `return_requests_branch_created_at_index`, `cash_handovers_created_at_index`): placed orders 7 days
51 ms, cancellations with reasons 30 days 11 ms, pickups 30 days 16 ms (a bitmap OR of two indexes), timing rows 30 days 79 ms. The decline query reads the whole
`delivery_declines` table (10 ms for 6,000 rows): a table that grows by a handful of rows a day, so it has no index of its own.

**Timings on that seeded scale database, cold (nothing cached), on a machine shared with other work:**

| Window | Branches tab (3 branches, 28 queries) | Drivers tab (12 drivers, 14 queries) |
|---|---|---|
| 7 days | 0.4 s | 0.08 s |
| 30 days | 0.9 s | 0.13 s |
| 90 days | 1.6 s | 0.19 s |
| 366 days | 1.9 s | 0.25 s |

(The seed has about 500 orders a day, far more than a furniture shop; real windows are lighter.) Cached: no database query at all for 120 seconds.

---

## 5. Design note

**Decisions and defaults**

- *Median and 90th percentile, not average.* One order that waited a day must not make a branch look slow all month; the slow end is still shown so slow orders are not hidden.
- *Minimum sample 10, an admin setting.* Safe and reversible; per number, on its own cases; raising it is fairer to a quiet branch.
- *Complete days for presets.* A partial "today" would make every comparison look like a collapse.
- *Rescheduled slots are measured against the last slot, and the count of moved deliveries is shown.* The data cannot tell a reschedule the customer asked for from one the team forced; showing the count lets the owner judge. Measuring against the *original* slot would punish a customer who asked for another day; measuring only against the last would let reschedules hide lateness; showing both numbers (the rate and the count) is the honest middle.
- *On time is by day, not by hour,* because the weekly owner pack already says so and the owner must not see two on-time numbers; the hour-level view is in the driver window (the time of each delivery next to its promised day).
- *Customer-side failures are separated, "Other" is blamed on nobody.* A "team failures" rate (only damaged goods) sits beside the total.
- *No red/green, no ranking, no medals.* Arrows carry words; the drivers are alphabetical; only the viewer's own click sorts.
- *Cash in hand and unexplained differences are "now" numbers* and say so (no arrow).
- *The cash limit is the Operations clock's* (one limit for the exception inbox and the scorecard), not a second setting.
- *Branch scope is computed on the server* from the viewer's roles (`AccessControl::scope('reports.view')`), re-checked on every Livewire call (the route's middleware runs again), and a branch id or driver id sent by the browser can only narrow it. A driver working for two branches shows each manager only the work and the cash of that manager's branch.
- *Staff names appear* (drivers, in the dashboard and the CSV); **no customer data appears anywhere** (the window and the CSV are aggregates, the driver window lists order numbers).

**Trade-offs and limits (said plainly)**

- Speed figures are measured on at most the newest 20,000 orders of a window (a year of a very busy branch); the card says so.
- The failed-delivery count is by delivery (its last failure), as in the daily close, so a delivery that failed twice shows only its last reason.
- A pickup customer who never collects counts against the branch's "in time" share (the pass ran out); that is a customer behaviour as much as a branch one, which is why the number is labelled with the pass life.
- "Wrong address" cannot be split between the customer and a staff typing error.
- The total line of the drivers has no time-on-the-road (percentiles of different drivers cannot be merged).
- Numbers are kept 120 seconds; **Refresh** reads again.
- The search palette (global search) does not yet know the words "scorecard / performance / on time"; it finds the page by its menu name. A one-line alias in `lang/*/search.php` (key `admin_scorecard_index`) would add them.

**Owner decisions that may be wanted:** the minimum sample (10); whether pickups should count customers who never collect; whether the weekly owner pack should show the median beside the average.

**Tests** (`tests/Feature/Reports/Scorecard*Test.php`, 108 tests): the arithmetic by hand (median vs mean, p90, ties, one reading, none), the period and its 366-day limit, the boundary of the on-time rule
(last second of the promised day, first second of the next, 21:30 UTC that is already tomorrow in Qatar), the minimum-sample boundary, the previous-period comparison, moved slots, customer-side vs team-side failures,
pickups, cancellations, claims, replacements, cash, agreement with the daily close and the owner pack, who may see what (manager A / manager B / owner / driver / ordinary staff, a foreign branch id, a foreign driver id),
the CSV (content, audit, permission, formula-safe names), the setting, Arabic, empty and error states, the cache, and the query count.
