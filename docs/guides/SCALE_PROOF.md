# Proof at scale (roadmap 6.6)

**What this is.** Evidence that the shop's busiest screens and API calls stay fast when the database is big: a throw-away database with 500,000 orders is filled, every request-path query is replayed with `EXPLAIN (ANALYZE, BUFFERS)`, what was slow is fixed, and the app is load-tested locally. Nothing here touches the live site or its data. The tools never run in the normal test suite.

**What the owner gets.** The dashboard's order list, payments, stock movements and customers open in about 10 to 35 ms of database time at 500,000 orders (they read 480,000 rows before the fix). The lists show "10,000+" instead of an exact count past 10,000 rows. The pages that add up a whole month or year of sales (reports, scorecard, owner pack, dashboard home) are still the slowest part: see "What is still slow".

## Method

1. `php artisan scale:seed --orders=500000 --products=5000 --fresh` on a database whose name ends in `_scale` (it refuses every other database and `APP_ENV=production`). Bulk SQL (`generate_series`, one `INSERT ... SELECT` per table), deterministic (ids are `md5('order:N')`, every "random" choice is a hash of the row number), 4.1 minutes on the build machine. Orders go in chunks of 100,000 through one temporary table holding each order's shape, so an order, its parts, lines, payment, delivery and history always agree. 3 years of history, recent months busier (about 900 orders a day now), 87% completed, a few thousand in flight.
2. `php artisan scale:explain` signs in as real people of that database (a customer, a branch manager, a driver, the owner), sends the requests through the real application (`app()->handle`), catches every SQL statement with `DB::listen`, and proves each SELECT: it runs it twice (the second run is "warm") under `EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON)`. Each scenario runs inside a transaction that is rolled back, so nothing is changed. A statement **fails** on:
   - a sequential scan that reads a fifth or more of a table of 100,000+ rows;
   - more than 200 rows back with no `LIMIT` (a lookup by a list of 20 or more keys counts as bounded by that list);
   - a `LIMIT` that skips more than 1,000 rows (a deep `OFFSET`);
   - more than 50 ms warm (100 ms for search and for the reports);
   - the same statement shape repeated more than 12 times in one request (an N+1);
   - or a 5xx answer.
   `--failing` prints the SQL and plan of each failing statement, `--only=text` runs the scenarios whose name contains the text, `--markdown=file` writes the result table.
3. Fix, run again, keep the before and after numbers below.
4. A local load test of the production-mode app against the same database.

### Data volumes (the `albahar_scale` database, 3.3 GB)

| Table | Rows | | Table | Rows |
|---|---|---|---|---|
| orders | 500,000 | | stock_movements | 998,544 |
| order_fulfillments (parts) | 599,907 | | order_status_history | 999,546 |
| order_items | 999,330 | | notifications | 732,379 |
| payments | 500,000 | | notification_logs | 250,000 |
| refunds | 26,333 | | audit_logs | 750,044 |
| return_requests | 21,561 | | users (customers) | 100,038 |
| deliveries | 446,522 | | user_addresses | 133,333 |
| delivery_proofs | 446,369 | | products / variants | 5,014 / 8,984 |
| cash_collections | 108,569 | | branch_inventory | 35,928 |
| customer_value_rollups | 404,122 | | carts / support tickets | 17,038 / 8,333 |

4 branches, 24 drivers. **Why not 1,000,000 orders:** the build machine had 8 GB of free disk shared with three other workers; 1,000,000 orders need about 6.5 GB. The seed takes `--orders=1000000` unchanged on a bigger disk (a chunk of 100,000 orders takes 45 to 50 seconds, the database grows 6.6 KB per order). Customers are one fifth of the orders.

Machine: 4 cores (shared with other running work), 16 GB RAM, PostgreSQL 16 with the Ubuntu defaults (`shared_buffers` 128 MB, `work_mem` 4 MB). The scale database has `random_page_cost = 1.1` (see the design note).

## The requests and their result (final run, 85 scenarios: 70 pass, 15 fail)

"DB ms" is the total database time of the request (all its statements); "Worst" is the slowest single statement, warm.

| Request | Queries | DB ms | Worst ms | Result |
|---|---|---|---|---|
| api home | 31 | 22.0 | 3.5 | pass |
| api categories | 2 | 1.2 | 0.0 | pass |
| api catalogue list (page 1 / page 40) | 9 / 9 | 16.6 / 21.1 | 6.3 / 12.8 | pass |
| api catalogue by category, sorted by price | 11 | 10.9 | 3.0 | pass |
| api catalogue, in stock and on sale | 12 | 137.1 | 70.4 | FAIL (65 and 70 ms) |
| api search, English / Arabic / suggestions | 9 / 9 / 11 | 73.8 / 71.6 / 46.1 | 36.8 / 39.5 / 41.6 | pass |
| api product page, availability, delivery promise | 16 / 18 / 23 | 9.5 / 6.7 / 11.2 | 0.8 / 0.0 / 0.0 | pass |
| api branch products, reviews, deals, branches | 13 / 1 / 7 / 4 | 17.5 / 0.4 / 1.8 / 1.8 | 6.7 / 0.0 / 0.0 / 0.0 | pass |
| api my orders (and filtered), active progress | 3 / 3 / 1 | 1.6 / 1.6 / 0.5 | 0.0 | pass |
| api order detail, timeline, tracking | 18 / 2 / 4 | 7.4 / 0.8 / 2.2 | 0.0 | pass |
| api notifications, addresses, loyalty, buy again | 3 / 2 / 3 / 10 | 1.3 / 1.0 / 1.6 / 10.4 | 0.0 / 0.0 / 0.0 / 0.2 | pass |
| api cart, add to cart, checkout quote | 3 / 20 / 3 | 1.3 / 7.5 / 1.2 | 0.0 | pass |
| staff driver jobs, day, history, my deliveries | 9 / 11 / 9 / 5 | 21.9 / 30.1 / 23.9 / 15.0 | 11.5 / 9.3 / 17.9 / 11.1 | pass |
| staff dispatch overview, queue, drivers, attention | 16 / 10 / 5 / 17 | 9.5 / 14.6 / 2.0 / 14.2 | 0.3 / 1.1 / 0.1 / 1.1 | pass |
| staff order list (and filtered), order detail | 4 / 4 / 23 | 7.8 / 4.3 / 9.2 | 8.9 / 0.6 / 1.7 | pass |
| staff stock list, stock movements, cash outstanding | 6 / 3 / 2 | 10.5 / 14.7 / 1.3 | 4.6 / 17.1 / 0.1 | pass |
| dashboard orders (page 1, page 3, filtered, search by number) | 17 each | 33.7 / 33.6 / 33.4 / 36.1 | 7.2 / 7.3 / 7.3 / 8.2 | pass |
| dashboard order detail | 58 | 33.3 | 4.9 | pass |
| dashboard live board, needs attention | 21 / 25 | 36.2 / 61.3 | 5.4 / 16.0 | pass |
| dashboard daily close | 69 | 220.0 | 7.6 | pass (69 small queries, none slow) |
| dashboard customers, deliveries, returns, payments, refunds | 7 / 38 / 9 / 10 / 7 | 16.2 / 84.1 / 35.6 / 17.3 / 10.5 | 4.8 / 18.9 / 21.6 / 5.6 / 7.7 | pass |
| dashboard inventory, stock movements, products, audit log | 13 / 11 / 13 / 7 | 66.8 / 13.5 / 16.4 / 10.2 | 48.5 / 5.3 / 5.5 / 5.8 | pass |
| dashboard global search: number, name, phone, product | 5 each | 4.5 / 6.8 / 10.3 / 4.8 | 0.6 / 1.7 / 3.6 / 0.7 | pass |
| dashboard home | 38 | 184.4 | 110.8 | FAIL (sales per day, 14 days) |
| dashboard scorecard (30 days) | 27 | 417.7 | 92.0 | FAIL (4 statements of 64 to 92 ms) |
| dashboard owner pack | 29 | 307.6 | 66.6 | FAIL (two statements of 64 and 67 ms) |
| dashboard customer value | 9 | 139.2 | 94.5 | FAIL (joins 76,000 rollup rows to users) |
| dashboard reports page | 8 | 497.4 | 316.0 | FAIL (the month's sales on open) |
| report sales_summary / by_branch / by_product / by_category / by_payment_method / by_fulfillment, 30 days | 4 / 1 | 476.8 / 145.3 / 145.7 / 137.3 / 121.3 / 123.9 | 312.6 / 154.1 / 163.9 / 152.2 / 147.0 / 164.7 | FAIL (about 27,000 orders in the month) |
| report order_status, deliveries, finance_collections, finance_refunds, finance_outstanding, inventory_levels, fast_slow_movers, 30 days | 1 to 3 | 2.0 to 84.9 | 1.4 to 83.4 | pass |
| report customers, 30 days | 3 | 67.7 | 39.3 | pass after rerun (see below) |
| report sales_summary, 365 days | 4 | 6,449 | 6,063 | FAIL (about 300,000 orders) |
| report sales_by_product, 365 days | 1 | 772.5 | 821.0 | FAIL |

(The "report customers" row of the table file shows a seq scan of `users`: that was the last fix, an index on `users (type, created_at)`, made after the table was written; a rerun of that scenario reads 29 ms and passes.)

## What was found and fixed (before to after, same 500,000-order data)

| Finding | Fix | Before | After |
|---|---|---|---|
| Dashboard **orders list**: `id IN (select order_id from order_fulfillments)` for every viewer, 8 exact `count(*)` per page view (3 chips, 4 tabs, the total), the "needs action" tab an `OR` over the whole table | the viewer of every branch reads `orders` directly; each count is taken **up to 10,000** (`BoundedPage`); "needs action" is two small id sets joined by `UNION`; new index `order_fulfillments (branch_id, order_id)` and a partial `order_fulfillments (status, order_id)` for the open parts | 871 ms DB, worst 304 ms, 500,000-row seq scans | 33.7 ms DB, worst 7.2 ms |
| Staff API **order list** | same scope shortcut and capped total | 93.7 ms (118 ms seq scan of parts) | 7.8 ms |
| Dashboard **payments** (exact count, newest-first without an index, the "late capture" flag counted by reading all payments) | `payments (created_at)`, partial index on the late-capture flag, capped total | 119.9 ms (three seq scans) | 17.3 ms |
| **Stock movements** (dashboard and staff API) | `stock_movements (created_at)`, `(branch_id, created_at)`, capped total | 63 to 74 ms, 1,000,000-row seq scan | 13.5 ms and 14.7 ms |
| **Customers** list | `users (created_at) WHERE type='customer'`, capped total | 81.6 ms | 16.2 ms |
| **Order detail** (API and dashboard): proofs and cash looked up by unindexed foreign keys | `delivery_proofs (delivery_id, captured_at)`, `cash_collections (payment_id)`, `(order_fulfillment_id, context)` | 115 ms (446,000- and 108,000-row seq scans) | 33.3 ms dashboard, 7.4 ms API |
| **My addresses** (customer API): only the one-default index existed | `user_addresses (user_id, created_at) WHERE deleted_at IS NULL` | 14.2 ms, 133,000-row seq scan | 1.0 ms |
| **Sales share** (`SalesBasis::orderShares`, behind dashboard home, daily close, scorecard, owner pack, reports, customer value): every part of every order went through item sums, three window sorts and the largest-remainder rule | an order with ONE part (nearly all) takes its grand total directly; only a split order is divided. The cents are identical (the 353 number tests, including the golden comparison, pass, and a new test compares both paths on odd cents) | 489 ms home, 637 ms `sales_summary` 30 days | 184 ms home, 477 ms (the remaining time is the month's rows) |
| **Customers report** (lifetime value of every customer) added up every order the company ever took | reads the customer rollup the dashboard already keeps (`CustomerValue::rollup`, rebuilt first when missing or 6 hours old, refreshed every 30 minutes by the scheduler) | 6,414 ms (7.7 s worst) | 67.7 ms |
| **Month-range sales reports** read 1,000,000 `order_items` by seq scan | covering index `order_items (order_id) INCLUDE (...)`: the lines of the orders in range are read without visiting the table | 175 to 200 ms, seq scan | 121 to 164 ms (index-only) |
| **Finance outstanding** report returned every open order unbounded | the list stops at 1,000 rows; the total is added up in SQL over all of them | 17.9 ms, 295 rows, no LIMIT | 31.9 ms for both statements, list bounded |
| Orders **search by name** (`ilike '%x%'`) walked the date index | trigram index on `orders.customer_name` next to the existing number and phone ones | 562 ms | 8.2 ms |
| **Global search by phone** | (test used an unselective 7-digit prefix; a real number is selective) | 126 ms | 3.6 ms |

Each fix has a test that fails without it (see "Tests"). All indexes are in `2026_11_24_120000_scale_proof_indexes.php`: built `CONCURRENTLY` (no write lock on a live system), `IF NOT EXISTS`, a half-built index from an interrupted run is dropped and rebuilt, and `down()` drops them all (CI runs up, down, up).

## What is still slow (honest list)

All remaining failures are **period totals that must add up every order of a window**; none is a missing index. The size of the window is what costs. At the seeded rate of about 900 orders a day, 30 days are 27,000 orders: 120 to 480 ms; 365 days are 300,000 orders: 0.8 to 6 s. Al Bahar's real daily volume is far lower, so a month will be well inside budget, but a year of "sales summary" will not be if the shop ever approaches this volume.

- Month reports `sales_summary` 312 ms, `sales_by_*` 147 to 165 ms, the scorecard (92 ms the slowest of 4), owner pack (67 ms), dashboard home (111 ms for the 14-day chart) and the reports page (316 ms: it runs the month's sales on open).
- A year of `sales_summary` (6.1 s) and `sales_by_product` (0.8 s).
- Customer value (95 ms): joins 76,000 rollup rows to `users`.
- The catalogue's "in stock + on sale" filter (65 to 70 ms for 5,000 products): the price filter works out the cheapest price of every product on every request, so its cost grows with the catalogue; Al Bahar has hundreds of products, where it is a few milliseconds.

Recommended next step if the shop grows: a nightly **daily sales rollup** (per branch and day: orders, share, units; the same `SalesBasis` definition) that these screens read instead of the orders; the customer-value rollup is the pattern. Not built: it changes the numbers' source and needs the owner's decision on freshness (the screens are live today).

## Load test (production mode, this machine)

The app ran as `APP_ENV=production APP_DEBUG=false`, PHP 8.3 with OPcache (no timestamp checks), PHP's built-in server with 4 worker processes (`PHP_CLI_SERVER_WORKERS=4`), Redis (cache), against the 500,000-order database, on the same 4 shared cores as PostgreSQL and the load generator (so these are lower bounds for a real server). A small Node closed-loop generator (`scratchpad` script, keep-alive connections, exact percentiles) sent requests as fast as answers came back; guests carried a random `X-Forwarded-For` (the limiter is 120 a minute per address), signed-in requests used the tokens of 400 different customers or 24 different drivers. Every request answered 200; **0 errors in 17,649 requests**.

| Request | 4 connections (about one per worker) | | | 16 connections (saturated) | | | |
|---|---|---|---|---|---|---|---|
| | req/s | p50 | p95 / p99 ms | req/s | p50 | p95 / p99 ms | errors |
| home (cached) | 195 | 19 | 28 / 39 | 213 | 73 | 95 / 107 | 0 |
| catalogue list (random page 1 to 20) | 54 | 70 | 93 / 154 | 56 | 279 | 341 / 378 | 0 |
| product page | 66 | 56 | 83 / 125 | 71 | 224 | 272 / 306 | 0 |
| checkout quote (signed in) | 79 | 45 | 93 / 110 | 83 | 188 | 259 / 302 | 0 |
| my orders (signed in) | 83 | 45 | 64 / 95 | 88 | 180 | 218 / 243 | 0 |
| driver jobs | 48 | 77 | 115 / 179 | 51 | 307 | 374 / 410 | 0 |

Reading it: the database is not the limit (a request spends 1 to 25 ms in PostgreSQL); PHP's own work is (about 12 to 20 ms of boot per request plus the response building). The 16-connection column is the machine saturated: throughput stops growing and the extra connections only queue, so p95 is the wait in line. With one connection per worker the p95 is under 120 ms everywhere, under the 200 ms target. A real deployment has more cores, a tuned FPM pool and Redis on the same host; scale the worker count with the traffic.

## How to rerun the scale proof

```bash
# 1. A throw-away database (name must end in _scale). About 3.3 GB for 500,000 orders; check the free disk first.
createdb albahar_scale        # or: psql -c "create database albahar_scale"
cd backend
export DB_DATABASE=albahar_scale CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync BROADCAST_CONNECTION=null
# 2. Fill it: migrates from scratch (--fresh), then 4 minutes for 500,000 orders. Estimate: 45 to 50 s per 100,000 orders.
nice -n 10 php artisan scale:seed --orders=500000 --products=5000 --fresh
php artisan customers:refresh-value        # the customer-value rollup (25 s), what the live scheduler does every 30 minutes
psql -d albahar_scale -c "alter database albahar_scale set random_page_cost = 1.1"   # SSD setting; see the design note
# 3. The plan test: 85 scenarios, about 80 seconds; exit code 1 when one fails
nice -n 10 php artisan scale:explain --failing --markdown=/tmp/plan.md
php artisan scale:explain --only="dashboard orders"      # one scenario
# 4. Drop it when done
dropdb albahar_scale
```
Never run these against the live database: both commands stop at once unless the database name ends in `_scale` and `APP_ENV` is not `production`. The load test used `APP_ENV=production OTP_DEV_ECHO=false`, Redis on another port and the built-in server (`php -S 127.0.0.1:8099 -t public public/index.php` with `PHP_CLI_SERVER_WORKERS=4`); limit the generator to 16 connections on a 4-core machine.

## Tests

`backend/tests/Feature/Scale/ScaleProofTest.php` (7 tests, 33 assertions): the commands refuse a database that is not `_scale`; `BoundedPage` counts to the cap, writes `10,000+`, keeps short and empty lists exact; the one-part shortcut gives the same cents as the old division for odd-cent split orders and drops a cancelled single part; every index of the migration exists and is valid; the "needs action" tab counts the same orders for the owner and for a branch and never another branch's; the customers report reads the rollup and a branch sees only its own customers. Mutation checks: adding 1 to the one-part share fails two tests (the share test and the customers report); removing the `pending_payment` filter of the needs-action set fails the tab test. Changed existing tests (their intent is kept): `OwnerReportsTest` (the customers report now builds the rollup once and reads it, instead of one aggregate over every order), `OwnerOperationsTest` (the foreign-key index check accepts the covering index of `order_items`, which starts with the same column).

Verified: `pest tests/Feature/Reports tests/Feature/Scale` and the Admin, Orders, Inventory, Commerce, Identity and Operations files that name the changed screens: 850 tests, all pass after the three fixes above.

## Design note

- **Scale database and seed.** A separate database named `*_scale`, guarded twice (name suffix, not production). Deterministic data, so numbers compare between runs. Passwords of generated customers share one hash (hashing 100,000 passwords would take an hour); nobody logs in as them. 500,000 orders instead of 1,000,000 because of the disk (above).
- **What "slow" means.** 50 ms for a single statement; 100 ms for search and reports; a seq scan only counts when it reads a fifth or more of a table of 100,000+ rows (a count that stops at its 10,000-row cap may read a few tens of thousands of rows and takes under 10 ms: that is bounded work, not a missing index). A lookup by 20+ keys is bounded by the list. Aggregates over a period are held to the same 50/100 ms: that is why they are listed as failures rather than excused.
- **Counts up to 10,000 (`App\Support\BoundedPage`).** Safest reversible choice: lists look and page the same, the number reads "10,000+" past the cap; narrowing with a filter shows the exact number. Changes: dashboard orders, payments, stock movements, customers and the staff `GET /admin/orders` (documented in `API_CONTRACT.md` section 15). Chips and tabs of the orders list use the same cap (`BoundedPage::count`, shown with `BoundedPage::show`).
- **Orders list for a viewer of every branch** no longer restricts to "orders that have a part": every order has at least one part (checkout creates them in the same transaction), so the condition only cost time. A viewer of some branches still gets the restriction, served by the new `order_fulfillments (branch_id, order_id)` index.
- **One-part shortcut in `SalesBasis`.** The share of the only part of an order is the order's grand total (the division of one part by itself gives the same cents); a split order still goes through the division. A cancelled single part is not a sale, as before.
- **Customers report from the rollup.** The figure can be up to the rollup's age behind (the scheduler refreshes it every 30 minutes; `ensureFresh` rebuilds it when it is missing or 6 hours old). The customer-value screen already reads the same rollup, so the two now agree.
- **Duplicate-looking index.** `order_items_order_report_index (order_id) INCLUDE (...)` starts with the same column as the old `order_items_order_id_index`. The old one is kept: it is 25 MB against 165 MB, cheaper for the many plain lookups by order. Drop it only after measuring write cost on the live database.
- **`random_page_cost`.** The scale database runs `random_page_cost = 1.1` (PostgreSQL's default 4.0 assumes spinning disks and made it choose sequential scans for month ranges). It was set per database with `ALTER DATABASE`, not in the app. The live server's setting is the owner's/hosting decision; on an SSD host 1.1 is the usual value. Do not change it without asking.
- **Not done:** a daily sales rollup (above); `OFFSET` pagination on lists other than the five capped ones (their deep pages were not slow in the plan test, none skips more than 1,000 rows on a default page); the 1,000,000-order run; `inventory` movement API list (not slow; left exact).
