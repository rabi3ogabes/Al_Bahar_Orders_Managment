# Stock planning, stock counts with a second person, and branch-limited reading

Roadmap 4.2 and the 4.6 parts that belong to stock and branches. This page is the owner's and manager's guide (what you see, where the switches are) and the
design note (what was decided, why, and the definition of every number).

| Feature | Who sees it | Where | Default |
|---|---|---|---|
| **Stock planning**: what to order today, days of stock, reorder points, who is waiting | Anyone who can see stock (`inventory.view`), inside their own branches | **Inventory > Stock planning** | On |
| **A second person approves a stock count** | Everyone who counts or approves counts | **Inventory > Stocktaking**, the count screen, the dashboard | **On**: whoever counted a count cannot approve it |
| **Branch-limited reading of the issue log and of split orders** | Branch staff | **Insights > Issue log**, **Sales > Orders** and the staff API | On, nothing to switch |

The numbers behind planning and the second-person rule are in **Settings > Stock**. The Arabic wording was written for this release and has not been read by a native speaker: please have `backend/lang/ar/stock_planning.php` read before staff rely on it.

---

## 1. Stock planning: "What do I have to order today?"

### The first screen
Open **Inventory > Stock planning**. The answer is the list called **Order today**: the items that are out of stock, running out, or below their reorder point, with something to order, most urgent first. Above it four tiles count the lists (tap one to open it), and under the list a note explains how every number is worked out, with your own settings filled in.

| List | What it holds |
|---|---|
| **Order today** | Out of stock, running low or below the reorder point, where an order is still needed (what is already on order is taken off) |
| **Out of stock now** | Nothing is available at the branch (on hand minus reserved is zero). The row says for how long ("Out for 12 days") and "14 customers are waiting" |
| **Below reorder point** | Available plus already ordered is at or under the item's reorder point |
| **Running low** | Fewer than 14 days of stock left (changeable) |
| **Slow / overstocked** | More than 120 days of stock, or nothing sold in a whole demand window (changeable) |
| **Everything** | Every item the branch holds or has held |

An item can be in several lists (a sold-out item is in "Out of stock now" and in "Order today"); each tile counts items once in its own list. The badge on the row and its place in the list use the most urgent list: out, then running low, then below reorder point, then slow.

### A row
Product and branch; **Available** (with on hand and reserved under it); **Selling** per day and how many sold in the window; **Days of stock**; **Reorder at** (and where that number comes from); **Already ordered** (units on purchase orders that were sent or approved, with the date they are due); **Order** (the suggestion) with an **Order** button; the sliders button (this item's own rules) and the ledger button.
Under the name: how many customers asked to be told when it is back ("Notify me"), and on how many wish lists it sits. On a phone the three numbers that matter sit under the name.

Two small badges keep the numbers honest:
- **New item**: less than a week of history. The daily number then uses at least seven days, so one busy day does not look like a trend.
- **One big order**: one order is more than half of what sold. It is counted like an ordinary order (the real total stays visible), so one customer does not make the shelf look empty.

### One tap to a purchase order
- **Order** on a row, or **Order everything suggested** on the toolbar (one branch at a time, up to 100 lines), opens a window with the suggested quantities, the cost of each (the last purchase order's cost, else the item's cost), the supplier that delivered most of them last time, and the expected date. **Create draft order** makes a normal **draft purchase order** with the existing purchasing code: numbering, audit log and totals are the purchasing module's own. Nothing reaches the supplier until the order is submitted and approved there, and the window opens on the new order.
- You need to be allowed to create purchase orders at that branch. Pressing the button twice makes one order.

### This item's own rules
The sliders button sets, for one item at one branch: **days to deliver**, **safety days**, a fixed **reorder point** and a fixed **order quantity**. An empty box means "work it out". Saved with an audit entry; stock quantities are never touched.

### CSV
**Download CSV** (needs the stock export right, `inventory.export`) gives up to 20,000 rows of the current list and branch (a message says so when more match): branch, SKU, product, status, on hand, reserved, available, sold, per day, days of stock, reorder point, on order, customers waiting, out since, suggested order. No customer data. Every download is in the audit log. Text starting with `=`, `+`, `-` or `@` is neutralised so a spreadsheet never runs it.

### Settings > Stock
| Setting | Default | Meaning |
|---|---|---|
| Days of sales to look at | 30 | How far back the sales are looked at |
| Days a supplier takes to deliver | 14 | Lead time when an item has none of its own |
| Safety days of stock | 7 | Extra stock so a late delivery does not empty the shelf |
| Days of sales each order should cover | 30 | How big one order should be |
| "Running low" means fewer than | 14 days | |
| "Slow / overstocked" means more than | 120 days | |

(The same tab holds the two stock-count settings of section 2.) The dashboard's own low-stock tile keeps its separate level (`inventory.low_stock_default`).

---

## 2. Stock counts: the person who counted does not approve

### How a count goes now
1. A team member starts a count and scans or types quantities, as before. Several people can record lines.
2. **Finish counting**:
   - nothing differs from what was expected, or the differences are worth no more than the "post without approval" limit: the count is **posted at once** (the stock is corrected and the count says "posted when the count was finished");
   - otherwise it becomes **Waiting for approval**. The stock does not change.
3. Someone **who did not count it** and may approve counts at that branch opens it and either **Approve and post** (the differences become stock movements) or **Send back for recount** with a reason. A sent-back count returns to *counting* with a banner showing who sent it back and why; the people who counted are told.
4. The count screen, the list and the audit log record who counted, who finished it, who approved and how (second person, the same person with the rule off, or automatically).

The people who "counted" are everyone who recorded a quantity on any line plus the person who finished the count. **Owners and super admins follow the same rule.** The same person cannot send it back either.

### Where you see what is waiting
- **Dashboard > Needs attention**: "Stock counts waiting for approval" with the count (inside the viewer's branches).
- **Inventory > Stocktaking**: a yellow banner ("1 stock count is waiting for approval (QAR 120.00 at cost)"), a **Differences** column (number of items and their value) and "Waiting for approval" under the status.
- The count screen: how many items differ, the units, the value at cost, who counted, and (for the people who may not approve it) why.
- **Alerts**: the people who may approve at that branch, the owner included and the people who counted excluded, get an in-app notification and a push when a count is waiting; the people who counted get one when it is sent back. Between 22:00 and 08:00 Qatar time the alert goes to the inbox without a push. Both alerts can be switched off or changed under **Content > Notification controls** (*Stock count waiting for approval*, *Stock count sent back*).

### Settings > Stock
| Setting | Default | Meaning |
|---|---|---|
| A second person must approve stock counts | **On** | Switch it off only if one person does everything (the shop has a single member of staff). It is a normal audited setting: who changed it and when is in the audit log, and a count approved by the person who counted records "approved by the same person (the second-person rule is switched off)". |
| Post small differences without approval, up to | **QAR 0** | A count whose differences are worth this much or less **at cost** is posted when it is finished. 0 means every difference needs a second person. A count with an item that has no cost is never posted without approval. |

### No bypass
The rule lives in the stocktaking service, so the dashboard and the staff API (`POST /api/v1/admin/inventory/stocktakes/{id}/post`) refuse the same way (`403 stocktake_second_person`). The refusal is written to the audit log (`stocktaking.self_approval_refused`).

---

## 3. Branch-limited reading

A branch-limited member (a role assigned to one or more branches, not company-wide) now reads only their branches' data in two places. A member with every branch sees everything, as before.

### Issue log
They see issues about an order, or about a payment of an order, that has a part at one of their branches; and the issues they reported themselves. Issues with no order (a bug in an app) are company-wide and stay with company-wide staff. It applies to the list, the counters, the search (title, reference, order number), the side window and its `?view=` link, and changing a status: another branch's issue never opens, is never found by search, and answers "not found" to a changed request. When they report an issue, an order number or payment reference of another branch is refused as "not found" (it never confirms that the order exists).

### Split orders
An order with parts at several branches is listed for, and opens for, each of those branches. Inside it, a branch-limited member now reads only **their branches' parcels**: the parcels with their lines, deliveries and pickups, the item changes, replacement questions, claims, notes and timeline entries of those parcels. Another branch's parcel, its number, branch name and lines are not sent to the browser. The order itself stays whole (customer, delivery address, totals, payments, notes about the whole order). This applies to the order page, the order side window and its `?view=` link, the order list rows, and the staff API (`GET /admin/orders` and `/admin/orders/{id}`). Writing was already per branch and is unchanged.

---

## 4. Design note

### Decisions and defaults
- **One query makes every number.** The page, its counters, the CSV and the purchase-order window read the same SQL (`StockPlanning`), so they cannot disagree, and the cost does not grow with the number of rows PHP handles.
- **"Out of stock" is the dashboard's definition** (nothing available and the item was held at that branch before), so the tile there and the list here agree. A branch that never held the item never appears as out of stock.
- **"Out since" is kept by the stock ledger** (`branch_inventory.out_since`): set when the available units reach zero, cleared when they are back. Reading it is a plain column, never a scan of the movement history. The migration fills it once for items that are out today.
- **A fixed number on an item always wins** (reorder point, order quantity, lead time, safety days); an empty box means "work it out". This is stored per item per branch on `branch_inventory`.
- **The second-person rule is on by default and server-side**; owners are not exempt (unlike purchase orders and adjustments, which let a super admin approve their own). The limit for small differences defaults to zero. The count's value uses the cost the shop already keeps: the branch's average cost, else the item's cost, else the product's.
- **A count with no difference posts when it is finished** (there is nothing to approve), so a clean count is never stuck.
- **Alerts reuse the staff-alert layer** (`StaffAlerts`): in-app inbox plus push, with the notification-controls switch, and the quiet hours of 22:00–08:00 Qatar (inbox only).
- **Issues and parcels are filtered where they are read** (a model scope and `ParcelAccess`), not in the views.
- **Supplier lead time**: the shop has no per-supplier lead time, and the product's own "lead time" is the promise made to the customer (made-to-order days), not how long a supplier takes. Planning therefore uses one shop-wide number with a per-item override (both editable).

### What every number means
| Number | Definition |
|---|---|
| **Available** | On hand minus reserved for open orders, at that branch |
| **Sold in the window** | Units on orders placed in the last *window* days, in the branch that fulfils them, not cancelled and not waiting for their online payment (the same definition as the dashboard's sales), less units cancelled or returned on the line and in a cancelled part. Orders in the window of a part that was cancelled are not counted |
| **Selling per day** | Units sold (with one big order counted as an ordinary one, see below) divided by **selling days** |
| **Selling days** | The days in the window the item could be sold: for a new item from the day it first appeared at the branch; the days it has been out of stock are left out; never fewer than 7 |
| **One big order** | One order is more than half of the window's units, the window sold at least 6 units, and there are at least two orders. That order is counted as the average of the others |
| **Days of stock** (cover) | Available divided by selling per day. Empty when nothing sold |
| **Reorder point** | Selling per day × (days to deliver + safety days), rounded up, never below the item's own minimum stock; a fixed number on the item replaces it |
| **Already ordered** | Units still to arrive on purchase orders that are pending approval, approved or partly received (drafts, received, closed and cancelled orders do not count) |
| **Suggested order** | Selling per day × (days to deliver + safety days + days an order should cover), rounded up, but never less than twice the reorder point and never less than the customers waiting; less available and already ordered. A fixed order quantity replaces it. Zero is shown as nothing to order |
| **Customers waiting** | Different customers with an active "Notify me when it is back" for the item, for this branch or for any branch, not yet told and not expired |
| **On wish lists** | Wish lists that hold the item, or its whole product |
| **Running low** | Available plus already ordered, divided by selling per day, is under the low-stock days (and something is available) |
| **Below reorder point** | Available plus already ordered is at or under the reorder point (and something is available) |
| **Slow / overstocked** | Days of stock above the slow limit, or nothing sold and the item has been at the branch for at least a whole window |
| **Value of a count's differences** | Σ \|counted − expected\| × cost, in QAR at cost. Unknown (shown as such) when any item that differs has no cost |

### Trade-offs
- Demand looks at orders placed, not at deliveries, so an order that is later cancelled is correctly left out but one that is still pending delivery already counts. Lines a customer returns are taken off.
- Days out of stock are known only for the **current** stock-out. An item that was out earlier in the window and has been restocked is divided by the whole window (a little low). The seven-day floor limits the damage.
- Seasonality and promotions are not modelled: a sale that doubled demand last month doubles the plan. The window setting (7–180 days) is the lever.
- The suggestion is per branch. It does not move stock between branches; transfers stay in **Inventory > Transfers**.
- The staff **invoice** of a split order stays whole: it is the customer's document.
- The count alert uses the in-app inbox and push only. WhatsApp and SMS are not connected on the live server and are not used here.
- The live **Needs attention** inbox (Operations) does not list waiting stock counts; the dashboard tile and the Stocktaking banner do.

### Performance
One statement works out every number for the page, its counters and the CSV: a grouped read of the orders in the window (index on `orders.placed_at`, lines by `order_items.order_id`), one of the open purchase orders, one of the waiting alerts, and the stock rows of the viewer's branches, with the lane tests done in SQL so filtering, ordering and paging happen in the database. Only the 25 rows of a page are then loaded with their products, branches and wish-list counts (never a query per row: a test seeds more rows and checks the number of queries does not change). Two things keep it fast: PostgreSQL's JIT compiler is switched off for this read (it cost 450 ms of 1,260 ms), and the intermediate steps are materialised so a row's expensive numbers are worked out once, not once per place they are used (this took the same read from about 1 s to about 0.4 s). It needs PostgreSQL 12 or newer (CI and live run 16).

Measured on a scratch database with 20,000 items × 2 branches (40,000 stock rows), on a busy shared machine (load average 4–5), median of runs:

| Orders in the 30-day window | Page of "Order today" (counters + 25 rows) | One branch | Search | 5,000 rows for the CSV |
|---|---|---|---|---|
| 10,000 (a very busy month) | about 0.4 s | about 0.35 s | about 0.07 s | about 0.45 s |
| 128,000 (more than ten times that) | 0.6 to 1.0 s | 0.4 to 0.6 s | 0.3 s | 1.0 to 1.2 s |

If order volume ever grows far beyond that, the next step is a nightly per-day rollup of units sold per branch and item (the grouped read is the only part that grows with orders); nothing else in the page needs to change.

### Owner decisions
1. Should a count with a small difference post without a second person? Set **Settings > Stock > Post small differences without approval, up to** (default 0).
2. Should the live **Needs attention** inbox list waiting counts too?
3. Should a branch-limited member also see company-wide issues (bugs in the apps)? Today they do not.
4. Should branch staff print the invoice of a split order with every branch's lines (today yes, it is the customer's document)?
