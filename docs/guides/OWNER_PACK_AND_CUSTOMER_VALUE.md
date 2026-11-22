# Owner pack, daily close, customer value and offer returns

**One rule: every page answers one question, in plain words, with the number big and the next step one tap away.**
Four pages, one set of definitions. All of them read the dashboard's own definition of a sale (`SalesBasis`), so a number here is the number
on the dashboard, the reports and the branch's own screens. Every number that appears on more than one screen is defined once, in
**`NUMBER_DEFINITIONS.md`**, which says what each number means and which look-alike names are different numbers.

| Page | Menu | Question it answers | Who sees it |
|---|---|---|---|
| **Daily close** | Insights > Daily close | "How did yesterday go?" | anyone with *reports.view*, for the branches that right covers; a branch manager closes the day |
| **Owner pack** | Insights > Owner pack | "What will the owners read on Monday?" | people who see every branch's reports **and** the settings (owner, admin); only *settings.edit* changes it |
| **Customer value** | Sales > Customer value | "Who should we win back?" | anyone with *customers.view*; a branch sees only what customers spent at its own branches; CSV needs *customers.export* |
| **Offer returns** | Marketing > Offer returns | "Did that coupon pay for itself?" | people with *marketing.view* **and** company-wide *reports.view* |

Everything is in English and Arabic (right to left checked), and every page has its loading, empty and error state.

---

## 1. Daily close (Insights > Daily close)

The page opens on **yesterday** ("How did yesterday go?"; *Today* is one tap away, or pick any date up to 31 days back).

**First screen:** the sales of the day, all branches shown, in one big number; under it "3 of 4 branches closed their day" and how many branches have
open points. Then one card per branch: its sales, orders delivered and cancelled, its open points (or "Nothing open"), and **Review and close the day**.

**The branch window** (a side window) shows every figure with a one-line meaning under each section, then the sign-off:

- **Closed / Not closed yet / Day still open.** A day can be closed once the branch is shut: the end of the branch's last opening window that day
  (Branches > Hours) plus a few minutes. A branch with no hours that day uses a fixed time. A day in the past can always be closed, up to 31 days later.
- **The second look.** A day with an open point cannot be closed without a note saying what is being done about it. Open points are: an order nobody accepted,
  cash still with the people who took it, a cash difference nobody has explained. A day with none can be closed with or without a note.
- **A closed day keeps the figures it had when it was closed** (copied into the row), so what the owner reads is what the manager saw, even after a late
  hand-in or refund. The window says so.
- **Who closes:** someone who can see the branch's reports **and** approve its orders, at that branch (the *Branch manager* preset has both). Another
  branch's manager, an accountant or a cashier cannot. Every close is written to the audit log (`daily_close.closed`, with the note and the open points).
- **Open the day again:** needs the company-wide *settings.edit* right and a written reason; audited (`daily_close.reopened`). For a day closed by mistake.
- **Print:** the Print button opens an A4 page per branch (figures, the manager's note, lines for two signatures). It shows exactly the branches the page shows.
- **Closing time** (button, needs *settings.edit*): minutes after the last opening hour (default 30), and the time used for a branch with no hours (default 22:00).

### The numbers (per branch, per Qatar business day)

| Number | Meaning |
|---|---|
| Orders placed | Orders with a part at this branch, placed that day. An online order still waiting for payment, or one that expired unpaid, is not an order. |
| Delivered or collected | Parts of an order that reached the customer that day (delivered or collected at the counter). |
| Cancelled | Parts cancelled that day. |
| Returned by customers | Return or damage claims whose goods came back to the branch that day. |
| Placed this day and still open | Of the orders placed that day, parts not yet delivered, collected, cancelled or returned, **as they stand now**. |
| Never accepted | Of those, parts still waiting to be accepted. |
| Sales | `SalesBasis`: what customers were charged for orders placed that day that are not cancelled and not waiting for online payment, the branch's own share of an order shared with another branch. The dashboard's "Sales today" for that day. |
| Average basket | Sales divided by the orders that count as sales. |
| Payment mix | The same sales split by how the customer chose to pay: cash on delivery, online (SADAD), pay at pickup. |
| Cash collected | Cash (not a card terminal) taken that day by drivers and counters at this branch. |
| Of that, handed in | The part of it a cashier has received. |
| Still with the people who took it | Collected that day and not handed in yet. |
| All cash not yet handed in | The branch's whole open cash balance, any day. |
| Difference found when counting | The signed total of the differences cashiers found on hand-ins received that day (short is negative). |
| Differences nobody has explained | Hand-ins with an open variance (any day). |
| Refunds paid | Refunds processed that day for orders this branch took part in, the branch's share (divided like the sale, so two branches never both claim a whole refund). |
| Failed deliveries | Deliveries at this branch that failed that day. |
| Average time to accept / prepare | In **opening-hours minutes** (the order clock's own rule, so a shut night is not slowness): from when the part could be accepted to when it was; from accepted to ready. The count of orders measured is shown beside it; a day with none shows "Nothing measured". |
| Items out of stock / running low | The dashboard's meaning of out and low (`StockLevels`), **as they stand now**; a closed day keeps the figure it had. |

---

## 2. Weekly owner pack (Insights > Owner pack)

An e-mail once a week, **aggregates only**: figures and product names, never a customer name, phone, address or order number (a test proves it).
Contents: sales, orders, average basket, on-time delivery, claims, stock-outs and cash difference for every branch against the week before (with an arrow and a
signed percent, never colour alone); the five best products by units and by revenue; and **what needs attention next week** (only what exists):
branch days nobody closed, orders nobody accepted, unexplained cash differences, cash not handed in, claims waiting, items out of stock, a branch whose sales fell
by 20% or more, a branch that kept fewer than 80% of its delivery promises.

**First screen:** the state in one look (On/Off, "The pack of week 46 goes out on Monday 16 Nov, 08:00", how many owners, which channels), then the pack **exactly
as it will arrive**, in English or Arabic, for any of the last twelve finished weeks. **Send test to me** sends the shown week to you only (3 tests per 10 minutes).

**Settings** (button, needs *settings.edit*; every change is in the audit log): on/off (**off until switched on**), up to ten e-mail addresses, the weekday and
time (Qatar time, default Monday 08:00), the language (English or Arabic), and a WhatsApp channel.

- **WhatsApp is shown "Not connected yet"** while no WhatsApp provider is connected (today's live state). Its fields are disabled and say so. If it is chosen
  anyway, every week writes a "Not connected" line to the send log; nothing pretends to be sent. When a provider is connected the same channel sends a short text with
  the same figures to each number, one result per number.
- **Once per ISO week and channel.** A scheduled send is one row, unique in the database. Two scheduler runs, a retry or a re-deploy find the row and do nothing. If a
  run failed or died, the next one sends **only to the recipients that did not get it**, every 10 minutes, up to 12 attempts, for 72 hours after the due time; after that
  next week's pack comes on its own. A pack with nobody to send to is logged ("No one is set to receive it") and sent as soon as an address is added.
- **Quiet hours do not apply:** the owner chose the time. The pack of a week is never sent twice.
- **The send log:** when, week, channel, reached / total, result, and why a send failed.
- The command is `php artisan reports:send-owner-pack`, scheduled every five minutes, in **System health** as "Send the weekly owner pack".
- Mail needs a real mailer (`MAIL_MAILER` not `log`): the page warns when this server only writes e-mails to a log, the test button refuses, and a scheduled send is logged as "failed: nothing left this server" (never as sent) and goes out by itself once the mail account is set up, if that is within 72 hours of the due time.

### The numbers

| Number | Meaning |
|---|---|
| Week | Monday to Sunday, Qatar time, ISO week number. |
| Sales, orders, average basket | As in the daily close, summed over the week. The company line adds up the branches' sales, but its **orders are the distinct orders of all the branches together** (an order shared by two branches is one order), and its average basket is the sales divided by them; a rate is recomputed from the sums, never averaged. |
| Delivered on the promised day | Of the deliveries that reached the customer in the week and had a promised day, the share that arrived on that day or earlier. |
| Claims per 100 delivered | Return and damage claims opened in the week, for every 100 orders that reached the customer in the week. "n/a" when none did. |
| Out of stock | Items with nothing available **today** (a stock figure is always as of now). |
| Cash difference | The signed total of the differences found counting cash handed in during the week. |
| Days not closed | Days of the week, already over, whose branch day nobody closed. |
| Top 5 products | By units (then revenue) and by revenue (then units), from the order lines of orders that count as sales: units are quantity less what was cancelled; revenue is the line after discount less what was cancelled. |
| Change | `(this week - last week) / last week`; no change is shown when last week was zero. |

---

## 3. Customer value (Sales > Customer value)

**First screen:** five tiles that are also the tabs: **Gone quiet** (the default), Top customers, Repeat buyers, One-time buyers, New this month. Under them the
question and its answer: "Who should we win back? 12 customers have gone quiet. Together they spent QAR 18,430.00 with us and have ordered nothing for 60 days or
more. The most valuable are first." and **Start with the most valuable**, which opens that customer in the existing customer window (where **Send message** is).
Nothing is sent from this page: it only finds the people, and the existing customer, campaign and message tools do the rest.

The list has search, six sorts (most spent, most orders, biggest basket, ordered most recently, quiet the longest, most late for their usual rhythm), a branch filter,
the customer's lifetime spend and orders, average basket, last order ("140 days ago"), their **usual gap** ("about every 30 days") and, on the quiet tab, **how late**
they are ("4.7 usual gaps"), so the owner sees who is really late rather than who ordered once long ago.

- **Gone quiet** = an active account that has had at least one order delivered or collected and has placed **no** order for N days. N is a setting (button, needs
  *settings.edit*; default **60**, between 14 and 730). A suspended or deactivated account is never listed as someone to win back.
- **Usual gap** = time between the first and the last order divided by the number of gaps. **Late by** = days since the last order divided by the usual gap.
- **Lifetime spend** = what the customer was charged for orders that count as sales, in the dashboard's definition.
- **PII:** names are shown; phone and e-mail are masked unless the viewer may edit customers (the same rule as the customers list). A branch manager sees only
  the customers who ordered from their branches, and only the spend at their branches.
- **Download CSV** (needs *customers.export*, which a branch manager does not have): at most 5,000 rows, the list as filtered, masked the same way, spreadsheet-safe, and every
  download is audited (`customer_value.exported`: the tab, whether a search/branch was used, the row count and whether it was masked; never the rows).
- **Freshness:** the lists are read from `customer_value_rollups`, rebuilt in one pass every 30 minutes (`php artisan customers:refresh-value`, in System health as
  "Work out what each customer is worth") and on demand: the first visit builds it, and **Refresh now** rebuilds it (once every two minutes). The page says "Figures updated 12 minutes ago".
  A page that finds the figures older than six hours rebuilds them itself, so a stopped scheduler cannot leave the lists stale for days.

### Branch-scoped lifetime spend (roadmap 4.6)

A staff member limited to some branches sees **only what customers spent at their branches**, everywhere a lifetime figure appears:

- the customer window / profile (Orders and Lifetime spend tiles; a small "At your branches only" note under the figure);
- the customers list (the Orders and Lifetime spend columns), which used to add up every order of the customer;
- the Customer value lists, the quiet list and the CSV.

Someone with the customers right on every branch sees the full figure. The customer profile and the customers list work the figure out live for the few customers on
screen (`CustomerValue::lifetime`), the lists read the rollup; both apply the same branch rule and the same sales definition. Tests prove a branch manager never reads the
other branch's spend, and a customer who only ordered elsewhere is not visible to them at all.

---

## 4. Offer returns (Marketing > Offer returns)

**First screen:** across every discount of the period (default the last 90 days; 30 days and 12 months are one tap; any dates), "for every 1 QAR given away in discount,
**N** QAR of sales came in", with a verdict. Then a line per coupon, promotion, customer journey and campaign (filter by kind). A line opens a side window that
**spells the sum out** with the meaning of every number, and a link to manage the offer.

| Number | Definition |
|---|---|
| Orders | Orders **placed in the period** that count as sales (not cancelled, not waiting for online payment) and came through the offer: a coupon or promotion applied to them (a reversed use is not counted); a journey message that brought the customer back (the journey's own attribution window); a campaign sent to the customer up to N days before the order (default 7; **the last campaign wins**, so an order is credited to one campaign only). |
| Sales after discount (net revenue) | What those orders were booked at, after every discount, with delivery and tax: the dashboard's sales figure, summed over the orders. |
| Discount given | What the offer itself took off (the coupon usage rows). A shared code is a coupon; a personal code (given to one customer by a journey or a referral) is counted in its promotion and in the journey that sent it. A campaign records no discount (it carries no coupon), so it has no return figure. |
| Sales before discount | Sales after discount plus the discount given. |
| Return on discount | Sales after discount divided by the discount given: how many riyals of sales came in for each riyal given away. Shown as ×13.2. |
| New / returning customers | New: customers whose very first order of all was one of these orders. Returning: the rest. |
| Verdict | **Not enough data yet** below the minimum number of orders (a setting, default 5, button needs *settings.edit*); **Gave nothing away** when no discount was given; otherwise **Paid for itself well** (×5 or more), **Paid for itself** (×2 to ×5), **Barely paid for itself** (under ×2). |

**What it cannot know:** which of those orders would have been placed anyway. An offer aimed at loyal customers shows a high return because they were going to buy. Read it
as the most the offer could have earned, not as proof that it earned that. The page says this under every offer. An order that used several offers is counted once per offer, so
the lines do not add up to the total; the total at the top counts every discounted order once.

---

## 5. For developers

**Tables** (migrations `2026_11_22_10xxxx`): `daily_closes` (unique per branch and day), `owner_pack_sends` (partial unique index per ISO week and channel for scheduled sends) and
`owner_pack_deliveries` (one row per recipient), `customer_value_rollups` (one row per customer per scope: `all` or a branch id), and concurrent partial indexes for the date lookups
(order parts delivered / collected / cancelled by branch, failed deliveries, processed refunds, claims, hand-ins, coupon usage by order and coupon, campaigns by send date).

**Code:** `app/Domain/Reports/Services/` `DailyCloseNumbers` (the figures), `DailyCloseService` (state, close, reopen), `OwnerPackData` / `OwnerPackPresenter` / `OwnerPackSender` / `OwnerPackSettings`,
`CustomerValue`, `OfferReturn`; `app/Console/Commands/SendOwnerPack.php`, `RefreshCustomerValue.php`; mail `app/Domain/Reports/Mail/OwnerPackMail.php` with
`resources/views/emails/owner-pack{,-text}.blade.php`; pages `app/Livewire/Admin/Insights/{DailyClosePage,OwnerPackPage}`, `Sales/CustomerValuePage`, `Marketing/OfferReturnPage`;
print `app/Http/Controllers/Admin/DailyClosePrintController` and `resources/views/documents/daily-close.blade.php`. Words: `lang/{en,ar}/{daily_close,owner_pack,customer_value,offer_return}.php`.
`SalesBasis::orderShares()` gained an optional list of customers (used for one customer or one page).

**Settings keys** (all optional, with the defaults in code): `daily_close.grace_minutes` (30), `daily_close.fallback_time` (22:00), `owner_pack.enabled` (false), `owner_pack.emails`, `owner_pack.email_enabled` (true),
`owner_pack.whatsapp_enabled` (false), `owner_pack.whatsapp_numbers`, `owner_pack.day` (1), `owner_pack.time` (08:00), `owner_pack.language` (en), `customer_value.gone_quiet_days` (60),
`offer_return.min_orders` (5), `offer_return.campaign_days` (7).

**Routes:** `admin.daily-close.index`, `admin.daily-close.print`, `admin.owner-pack.index`, `admin.customer-value.index`, `admin.offer-return.index`. No API endpoint was added or changed.

**Tests:** `tests/Feature/Reports/{DailyCloseNumbersTest,DailyCloseTest,OwnerPackDataTest,OwnerPackSendingTest,OwnerPackPageTest,CustomerValueTest,OfferReturnTest,OwnerPackLangTest}.php`.

---

## 6. Design note: decisions, defaults, trade-offs

1. **One definition of a sale.** Sales everywhere come from `SalesBasis`, and the figures of the daily close, the pack and the lists are summed from it, not re-derived. This is why a split order is
   divided between branches, and why a cancelled part drops out.
2. **A day is closed by a manager, not by time.** The brief asks for a second look, so closing is a deliberate act with a note; the system never closes a day by itself. The price is that an unclosed day
   stays "Not closed yet" until someone does it; the owner sees it on the page and in the pack's "what needs attention". *Safe default:* close time = last opening hour + 30 minutes, 22:00 without hours; both are settings.
3. **Open points demand a note instead of blocking the close.** A manager who cannot fix an open order tonight must still be able to sign the day off honestly. The note is the record.
4. **A closed day is frozen** (its figures are copied) but can be opened again by someone who manages settings, with a reason. Reversible, audited.
5. **Stock is "as it stands".** A stock count cannot be looked up for a past day without a history table; the page says so, and a closed day keeps the number it had.
6. **Opening-hours minutes for speed.** The same clock the dashboard uses, so "slow" means the same thing everywhere. Parts that went straight to "preparing" have no accepted time and are not measured (the count beside the average shows how many were).
7. **Owner pack off by default, once per week by the database.** Switching it on is the owner's act. Idempotency is a unique index, not a lock someone could forget; retries go only to the people who missed it. WhatsApp is a visible "not connected" state, not a hidden failure.
8. **Aggregates only.** The pack is built from counts, totals and product names. A test seeds a customer with a name, phone, e-mail and order number and proves none of it reaches the HTML, the text or the data.
9. **Customer value from a rollup, exact on the page that matters.** Sorting and filtering 100,000 customers by lifetime value cannot scan every order per request, so the lists read a table rebuilt every 30 minutes (and on demand). The customer's own page and the customers list
   compute the number live for the few customers on screen. *Trade-off:* a list can be up to 30 minutes behind; it says when it was last updated. *Branch rule:* a limited viewer reads only their own branches' rows. When a viewer works at several branches, the list adds up
   those branches' rows, so an order shared between two of **their own** branches counts once per branch in the Orders column of the list (the customer's window shows it once).
10. **"Gone quiet" starts at the last order of any kind**, not the last delivery: a customer with an order on its way is not quiet. It needs one delivered order so a first-time buyer whose order failed is not "lost".
11. **Return on discount is a ratio of sales to discount, not a profit.** Cost of goods is not recorded for most products, so a profit number would be invented. The page states the limit (it cannot tell what would have sold anyway) beside every figure and shows "not enough data yet" below five orders.
12. **No new permission.** Daily close uses *reports.view* + *orders.approve* at the branch; the pack uses *settings.view* (and *settings.edit* to change it); customer value uses *customers.view* (CSV: *customers.export*); offer returns use *marketing.view* + company-wide *reports.view*.
    The roles that exist already give a branch manager the first, an owner or admin the rest.
13. **Not done on purpose:** no daily e-mail or push to managers to close their day (the owner sees the state on the page and in the weekly pack); no WhatsApp send until a provider is connected; no customer messaging built here (the existing customer window, campaigns and journeys do that).
