# Global search and the live operations board

Two things for the owner, the branch manager and the order desk, both on every dashboard screen size and in English and Arabic:

| What | Where | Who |
|---|---|---|
| **Global search** (a "command palette") | the **Search** button in the top bar, or press **Ctrl/Cmd + K**, or **/** | every signed-in staff member; each group of results only for the people who may open that page |
| **Live board** | Menu > Operations > *Live board* (`/admin/operations/board`); *Wall display* button on the page (`?wall=1`) | anyone with **orders.view** (their own branches only) |

Neither has settings of its own. The board's late / getting-late colours are the existing **Settings > Operations clock** limits.

---

## 1. Global search

### Opening it, and what to type

- **Search** button in the top bar (a magnifier on phones), **Ctrl/Cmd + K** (also toggles it closed), or **/** when you are not typing in a field. On Arabic keyboards the K key works too.
- One box. Type, wait a moment, read. Nothing to choose first.

| You type | It finds |
|---|---|
| `AB100123`, `ab-100123`, `#100123`, `0012`, `AB100123-1` | **Orders** whose number contains that (the "AB" is not counted, so `AB1` does not match the whole shop; at least 3 characters after it). The number of a branch part (`AB100123-1`) finds its order. The exact number comes first, then the newest. |
| `+974 5512 3456`, `974 55123456`, `55123456`, `٥٥١٢٣٤٥٦`, `5512-3456`, or just `5512` | **Orders** by the customer's phone, and **Customers**. Any way of writing a Qatar number works, Arabic digits too. Four digits are enough (a piece of the number). With `+974` typed, the number must *start* like that. |
| `Noor`, `نور`, `fk@example` | **Customers** by name (any part, Latin or Arabic) or e-mail |
| `velvet sofa`, `كنبة`, `SOFA-0001`, `6290001112` | **Products and variants** by name in English or Arabic (Arabic spellings are folded the way the shop search folds them), by SKU or by barcode (starts with), of the product *and* of each of its variants |
| `cash`, `stocktake`, `returns`, `جرد`, `نقد` | **Go to**: the pages of the dashboard menu that **you** may open, by their name in English or Arabic (whatever language the dashboard is in) or by words people use for them (`till`, `count`, `claims`...; the list is in `lang/*/search.php`) |

- **1 character** asks nothing. **2 characters** show pages only. **3 or more** look in orders, customers and products as well. The query is cut at **64 characters**.
- A page whose name starts with what you typed is listed first (`cash`); a typed number never lists a page first.
- At most **5 results per group**. When there are more, the last row of the group is **"See all matching orders / customers / products"** (opens that list with the search filled in).

### Reading and opening a result

- **Orders**: number, customer name and phone, status, total (what the Orders list shows). **Customers**: name, phone and e-mail **masked exactly as the Customers list masks them** unless you may edit customers; a suspended customer carries a "Suspended" tag. **Products**: name in your language, SKU (the variant's SKU and name when a variant matched), "Draft" / "Inactive" tag when it is not on sale. **Go to**: page name and its menu group.
- A result opens with the pattern the dashboard already uses, so nothing is built twice: an **order** opens in the **side window** of the Orders list (`/admin/orders?view=<id>`), a **customer** in the side window of the Customers list, a **product** in the quick view of the Products list (with "Open full editor"), a **page** goes to the page. Ctrl/Cmd/Shift-click or the middle button opens it in a new tab (every result is a real link). While a side window is open you can still search; the window closes first, as for any other navigation.
- **Recent searches** (up to 6) are shown when the box is empty, are kept **in this browser only, per person**, and can be removed one by one or cleared. A query that looks like a phone number (8 digits or more) is **never** kept.

### Keys and accessibility

Up/Down move (wrapping), **Enter** opens the highlighted result (the first one is highlighted when results arrive), **Esc** or the phone's Back button closes, the Close button is always there. Focus stays in the box; the highlighted result is announced (`aria-activedescendant`); the number of results is announced politely (`role=status`); focus is **trapped** while open and **returned** to the Search button or whatever had it. Rows and buttons are at least **44 px**. Layout uses start/end (never left/right), so Arabic mirrors itself; phone numbers and e-mails inside a line are kept left to right. Reduced motion switches the transitions and the spinner off. Dark and light follow the dashboard.

States: **empty** (what to type, with examples, and recent searches), **one letter** ("keep typing"), **loading** (rows in the final layout after 160 ms, so a quick answer never flashes a skeleton), **no result** ("Nothing found for ... check the spelling, or type just the number or one word"), **error** ("Search is not available right now" with *Try again*), **too many searches** (429, with *Try again*).

### Who can find what (server side, every time)

| Group | Needs | Scope |
|---|---|---|
| Orders | `orders.view` | only orders with a part in a branch where you have it (same rule as the Orders list); company-wide staff see all |
| Customers | `customers.view` | company-wide: everyone; branch staff: customers who ordered from their branches (`CustomerScope`, the Customers list's rule). Phone and e-mail masked unless `customers.edit` |
| Products and variants | `catalog.view` | the catalogue is the same for every branch (as in the Products list) |
| Go to | none | only pages in the menu **you** see (`AdminNavigation`), so a page you may not open is never offered |

A group you may not see is simply absent. Tested: another branch's orders and customers are never found by number, phone or name; a driver (deliveries only) finds only pages; masked e-mail and phone stay masked.

### Safety

- The query is a bound parameter only; `%`, `_` and `\` are escaped, so they are plain characters. Emoji, SQL, HTML, quotes, invalid UTF-8, a 5,000-character paste and `?q[]=` are all handled (tests). Control characters are dropped, spaces collapsed.
- **Rate limit** `admin-search`: 90 searches a minute **per staff member** (the screen asks once per pause in typing). Over it: `429` and "You are searching very fast".
- The answer is `Cache-Control: no-store, private`. **The query is never stored or logged** (a test asserts no log line is written), so full phone numbers never reach a log.
- Staff only: a visitor gets 401, a customer account 403.

### How fast (measured)

Data: **300,000 orders, 375,000 branch parts, 750,000 lines, 250,000 customers, 30,000 products (60,000 variants)** on PostgreSQL 16, seeded with `generate_series`. Median of 7 runs of `GlobalSearch::run` (database, PHP and icon drawing together):

| Typed | All branches | One branch | Group | Plan |
|---|---|---|---|---|
| `AB250123` (whole number) | 7.9 ms | 7.3 ms | orders | Bitmap Index Scan `orders_number_trgm_idx` |
| `AB2501` (a piece) | 8.2 ms | 6.5 ms | orders | same; top-N sort of the ~160 matches |
| `5512` (a piece of a phone) | 12.0 ms | 12.2 ms | orders + customers + products | `BitmapOr` of `orders_number_trgm_idx` and `orders_customer_phone_trgm_idx`; `users_*_trgm_idx` |
| `Khalid Al-Mohannadi` | 22.1 ms | 25.9 ms | customers | `BitmapOr` of `users_name_trgm_idx`, `users_email_trgm_idx` |
| `mariam` (a name 6% of customers have: 15,625 rows) | 31.7 ms | 30.7 ms | customers | same; the worst case measured |
| `فاطمة` (Arabic, 15,625 rows) | 28.8 ms | 27.5 ms | customers | same (trigram works on Arabic in this locale) |
| `SOFA-0001` / `6290000012` | 6.2 / 8.9 ms | 5.2 / 7.9 ms | products | five lookups in one statement: `Index Scan using products_sku_prefix_idx`, `product_variants_sku_prefix_idx`, `..._barcode_prefix_idx` |
| `velvet sofa 12` / `أريكة مخمل` | 7.9 / 8.7 ms | 7.4 / 9.5 ms | products | `product_translations_search_trgm` (for a common word the planner stops after 30 rows: `Limit` over a sequential scan, 4 ms) |
| `cash` | 9.2 ms | 6.9 ms | go to | in memory |

Every group is **one statement** with a `LIMIT` (orders and customers: 6 rows; products: five lookups of at most 30 rows each, merged and cut at 6). A branch-limited person adds a semi-join on `order_fulfillments.order_id` that is only probed for the rows the trigram index already found. There is no sequential scan of orders, customers or products in any plan on this data. Indexes are in the migration `2026_11_22_120000_add_global_search_indexes` (trigram GIN on `orders.number`, `orders.customer_phone`, `users.name`, `users.email`, `users.phone`; pattern-ops B-trees on `lower(sku)` and `lower(barcode)` of products and variants, so "starts with" works whatever collation the database was created with). `pg_trgm` is already required by the catalogue search; the migration creates it only if missing, and `down()` drops every index.

### The JSON (for the developers)

`GET /admin/search?q=<text>` (session login, staff only, `throttle:admin-search`) answers

```json
{ "q": "AB1003", "state": "ready", "groups": [
  { "key": "orders", "label": "Orders",
    "items": [ { "id": "...", "title": "AB391003", "subtitle": "Name · +974...", "badge": "Completed", "meta": "QAR 403.00",
                 "url": "/admin/orders?view=...", "icon": "bag", "ltr": true, "svg": "<svg ...>" } ],
    "more": { "label": "See all matching orders", "url": "/admin/orders?search=AB1003" } } ] }
```

`state` is `short` (under 2 characters), `pages` (2 characters, menu only) or `ready`. Order of groups: orders, customers, products, go to (go to first when a page's name starts with the query). `svg` is the dashboard's own icon drawing (never user text). This is a dashboard (session) endpoint, not part of the public API, so `docs/api/API_CONTRACT.md` is unchanged.

---

## 2. Live operations board

**Menu > Operations > Live board.** One look answers "what is happening right now, and what is stuck?". It updates by itself.

### The lanes (left to right; right to left in Arabic)

Each open **branch part** of an order is **one card in exactly one lane** (an order from two branches is two cards, labelled "Part 1 of 2"). A part is "open" until it is delivered, picked up, cancelled or returned. An unpaid online order is not on the board (it is not the branch's work yet; same rule as "Needs attention").

| Lane | A part lands here when | The card says |
|---|---|---|
| **New** | part `pending` (not accepted) and the clock is not red | *Waiting to be accepted* |
| **Preparing** | part `accepted` or `preparing`, clock not red | *Accepted, not started* / *Being prepared* |
| **Ready** | part `ready`: a delivery for a driver, or for the customer; clock not red | *Ready, no driver yet* (no delivery) / *Driver named, not accepted yet* (delivery `assigned`) / *Driver is coming to collect* (driver accepted; no clock, the driver is moving) / *Ready for the customer* |
| **Out for delivery** | part `dispatched` or `out_for_delivery` | *On the road*, with the time since it left (a plain time in a neutral chip: nobody at the branch is racing it) |
| **Pickup waiting** | part `pickup_ready`, clock not red | *Waiting to be collected* (days, the pickup limits: amber 2 days, red 4) |
| **Needs attention** | anything below, in **any** step | the step it is in, plus the reason and "Step: ..." |

**Needs attention** takes a part when (first reason that applies):

1. **Cancellation request**: the customer asked to cancel and a manager has not decided (order `cancellation_requested`). Clock: the "new order" limits from the request.
2. **Failed**: the delivery failed (part `failed`); shows the driver's reason. Clock: the "ready, no driver" limits from the failure.
3. **Late**: the step's order clock is **red** (a new order over 15 minutes, a part being prepared over 90, a ready delivery over 60, a named driver over 10, a pickup over 4 days; **Settings > Operations clock** changes them; branch steps count opening hours only).
4. **Customer has not answered**: a replacement question to the customer has used half of its time or more (amber from half, red when time is up; the same measure as "Needs attention").

Below that line a card with a question waiting for the customer **stays in its own lane** with a **Waiting for the customer** badge (the part cannot leave until they answer).

Lane headers show the count and, when there are any, a red and an amber badge. On a phone a strip of lane buttons (with the red count) jumps to a lane; the lanes slide sideways one at a time.

### A card

Order number (and "Part n of m"), the **age chip** (shape + word + colour, never colour alone; the tooltip says when the clock is paused because the branch is closed), what it is waiting for, **items**, **slot** ("Today 14:00-16:00", "Tomorrow", "Sat 28 Nov"; or the planned time), **Express**, **payment**: *Cash on delivery QAR 1,250.00* / *Pay at pickup QAR 450.00* (the amount still to collect for this part; **bold** when there is cash to collect) / *Paid online*, the **driver's name** when named, the failure reason, and **no customer name, phone or address**.

Actions (the same permissions as everywhere): **Open** opens the order in the side window of the page (`?view=`, with all the order's own actions); **Accept** on a new order (needs `orders.edit` at that branch; it calls the same `OrderService::transition` as the order window, so stock, audit, alerts and the customer's message are identical; a second tap says it is no longer waiting); **Assign driver** / **Change driver** / **Reschedule** go to the Deliveries page filtered to the order (needs `deliveries.view`). A person who may only look has no buttons.

### Keeping it fresh

- **Live** (green): the socket is connected. The board redraws about a second after a new order, an accept, a driver assignment, a delivery moving (`order.live` through the layout's feed, and `dispatch.updated` on the `branches.{id}` channels of the branches you work at); a safety timer redraws every **30 s** so the age clocks move.
- **Reconnecting...** (amber): the socket is trying; the board redraws every **10 s** meanwhile. It returns to Live by itself (tested by stopping and restarting the socket server).
- **Updating every 10 s** (grey): no socket at all (not set up, blocked, refused); the same 10 s timer.
- **Not updating** (red): the board has not been redrawn for three polls (at least a minute): look at the connection. The time of the last redraw is shown next to the indicator.
- No full page loads: Livewire redraws the lanes in place. A hidden tab does not poll and catches up when it is shown again.
- **New orders**: a card that arrives in *New*, or a card that has just *moved into Needs attention*, rings itself in brass for 9 seconds and slides in (no motion if reduced motion is on). The **sound and the toast** are the dashboard's existing new-order alert (Content > Notification controls, and the speaker in the top bar); the board never makes a second one, so nothing is announced twice. Online orders count when paid.

### Branches

Default **All my branches**; with several, cards are **grouped per branch** inside each lane (branch code and name, count). A chip per branch (a single box on phones) narrows the board; it can only narrow, never widen: a branch you may not see is ignored. A one-branch person sees one branch and no selector.

### Wall display

**Wall display** (or `?wall=1`, `&branch=` to fix one branch) shows the board **alone**: no menu, top bar or footer, large type, six lanes filling the screen (fits 1920 x 1080 and 1440 wide), the shop clock (Qatar time), the live indicator, a **sound** switch (the same choice as the speaker in the top bar), **Full screen** and **Exit**. It asks the screen not to sleep. It is a display: **no buttons**, no side window, and the server refuses Accept in this mode. Dark and light follow the device. The new-order toast and sound of the dashboard keep working on it. For a screen in a branch, use a **view-only staff account** at that branch (role with `orders.view` only) so the screen cannot be used to change anything.

### How big, how fast

Per refresh, **8 statements** whatever the number of parts: **one** for the open parts (a short lookup per part state on `order_fulfillments (branch_id, status, created_at)`, each cut at **150**, oldest first, joined with `UNION ALL`, so a flood of one state never hides the others and the longest-stuck are the ones kept), the eager loads by id (orders, deliveries with their drivers, slots, pickup passes), **one** grouped query for the units, and one each for waiting replacements, cancellation requests and the number of parts of an order. A test asserts the count does not grow from 40 to 200 parts. Lanes, counts and colours are worked out in memory; a lane shows **10 cards** and "Show N more" opens it to **60**; only the shown cards get their slot text and cash amount (plus a cash-split lookup for the few orders that come from several branches).

Measured on the seeded data (604 open parts over 6 branches, PHP CLI on the dev container): **8 statements, 25 ms in the database**, about **0.28 s** for the whole board (most of it the order clock walking each branch's opening hours for each part). The main statement:

```
Append
  -> Limit -> Sort (created_at, id) -> Nested Loop
       -> Seq Scan on branches (6 rows)
       -> Index Scan using order_fulfillments_branch_id_status_created_at_index
            Index Cond: branch_id = branches.id AND status = 'pending'
  -> (the same, one leg for each of: accepted, preparing, ready, dispatched, out_for_delivery, pickup_ready, failed)
Execution Time: 1 to 4 ms
```

**This needed one index.** `order_items` had an index on the order and on the branch but **none on the part** (PostgreSQL does not index a foreign key), so a first version of the board (units summed per part) read the whole 750,000-line table **once per part: 63.8 seconds** for 600 parts. With `order_items_order_fulfillment_id_idx` (in the same migration) the same board took **0.23 s**, and the units are now one grouped lookup on it. The index also makes the "Needs attention" inbox, which sums units the same way, fast.

---

## 3. Commands, tests and where things are

```
cd backend
CACHE_STORE=array DB_DATABASE=<your test db> vendor/bin/pest tests/Feature/Search tests/Feature/Operations
npm test                    # includes tests/js/command-palette-core.test.mjs and tests/js/ops-board-core.test.mjs
```

| Piece | File |
|---|---|
| Search: query reading | `app/Domain/Search/Support/SearchQuery.php` (limits, phone and order-number reading, LIKE escaping), `SearchGroup.php` |
| Search: groups | `app/Domain/Search/Services/{OrderResults,CustomerResults,ProductResults,CommandResults,GlobalSearch}.php` |
| Search: endpoint, limiter | `app/Http/Controllers/Admin/SearchController.php`, route `admin.search`, limiter `admin-search` (`AppServiceProvider`) |
| Search: screen | `resources/views/components/admin/command-palette.blade.php`, the button in `resources/views/layouts/admin.blade.php`, `resources/js/command-palette.js` (Alpine) and `command-palette-core.js` (pure, tested) |
| Search: words | `lang/{en,ar}/search.php` (screen texts and the page `aliases`) |
| Board: lanes and cards | `app/Domain/Operations/Services/LiveBoard.php`, `Support/Board.php`, `Support/BoardCard.php` (reuses `OpsClock`, `SubstitutionClock::wait()`, `OrderService::transition`) |
| Board: page | `app/Livewire/Admin/Operations/LiveBoardPage.php`, `resources/views/livewire/admin/operations/live-board.blade.php` (+ `partials/`), `resources/views/components/admin/ops/board-card.blade.php`, route `admin.operations.board` |
| Board: browser | `resources/js/ops-board.js` (Alpine) and `ops-board-core.js` (pure, tested) |
| Board: words | `lang/{en,ar}/ops_board.php` |
| Wall display | `$wall` in `resources/views/layouts/admin.blade.php` leaves out the menu, top bar and footer for `?wall=1` on the board only |
| Migration | `database/migrations/2026_11_22_120000_add_global_search_indexes.php` (up, down, up tested) |
| Tests | `tests/Unit/Search/SearchQueryTest.php` (how a query is read), `tests/Feature/Search/{GlobalSearchTest,CommandPaletteTest}.php`, `tests/Feature/Operations/LiveBoardTest.php`, `tests/js/command-palette-core.test.mjs`, `tests/js/ops-board-core.test.mjs` |

---

## 4. Design note

**Decisions and defaults** (all reversible; none needed the owner)

- **Search is a JSON endpoint, not a Livewire component.** A Livewire call on every pause would carry the page's snapshot and cannot be cancelled; `fetch` with `AbortController` can (a slower answer for `ab1` can never overwrite the answer for `ab1001`: tested) and the work per request is only the lookups.
- **Three characters for tables, two for pages.** A trigram index needs three; fewer would scan. A fragment of an order number must be 3 characters *after* "AB".
- **Orders are found by number and phone only** (as asked); customer names are found in the Customers group, so the Orders group never shows a surprising match.
- **Results open the existing side windows by link** (`?view=`) instead of new windows: no duplicate UI, every result is a real link, and the permission check is the page's own.
- **Nothing about a query is stored on the server.** Recent searches live in the browser, per person, and never keep a phone number.
- **One card per branch part; one lane per card.** The lane is a *state*, not a filter, so counts add up; "late" is a colour on the card and a red lane **only when something is red or broken** (a red new order moves to *Needs attention* so the red lane is the one place to look). The reasons are listed in the card.
- **The board does not alert.** The dashboard already toasts and chimes once per new order on every page; a second sound on the board would double it. The wall display relies on the same alert (the toast shows on the screen; the sound switch is on the wall toolbar).
- **Wall display is a mode of the same page** (`?wall=1`), not a second page, so it shares the lanes, the live feed and the permissions; it has no buttons on purpose.
- **Quick actions reuse, never re-implement:** Accept = `OrderService::transition`; Assign driver = the Deliveries page; Open = the order window.
- **Cut per part state (150 each), oldest first**, not 600 overall: a first version cut the newest, which hid *new* orders exactly when the backlog was large (found while testing the live path). A board with more than 150 of one state says so.

**What every number means**

- *Lane count*: open branch parts in that lane (up to 150 per state read). *Red / amber badge on a lane*: cards in it whose order clock is red / amber. In **Needs attention** a card without a clock limit (a cancellation request is measured from the request; a failed delivery from the failure) uses the limits listed above.
- *Top chips*: **late** = all red cards, **getting late** = all amber cards, **open** = all open parts on the board (unpaid online orders excluded).
- *Age chip*: minutes **counted in opening hours** for branch steps (new, preparing, ready, driver), whole days/hours for pickup, cash and refunds (as in `docs/guides/LIVE_OPERATIONS.md`); on the road: real minutes since it left. For a question to the customer: opening-hours minutes since it was asked; amber from half of its response time, red at the end.
- *Items*: sum of line quantities of that part. *Cash to collect*: the part's own delivery amount (`cod_amount`, what the driver is asked for) when there is a delivery; otherwise what the order still owes (total less paid), shared between the order's open parts by the same rule as the drivers' cash (`ParcelShares`) when it comes from several branches; nothing when paid or paid online.
- *Updated hh:mm:ss*: when the server last drew the board; *Live / Reconnecting / Updating every 10 s / Not updating*: see "Keeping it fresh".

**Trade-offs and limits (stated plainly)**

- Staff with a **company-wide role who are not employees of any branch** (and not super admins) are not allowed on the `branches.{id}` socket channels (the existing channel rule), so their board redraws on the order feed (new orders and order status changes) and on the 10/30 s timer: a part moving from *Preparing* to *Ready* shows within 30 s for them, within about a second for branch staff and super admins.
- The customer's name is not on the card (privacy on a shared screen). To find a customer's order use the global search.
- Search counts and "N more" are limited by the index-friendly design: a query with a very common word (`mariam`) costs about 30 ms at 250,000 customers; that is the worst measured.
- No change to any API, to the mobile apps or to the storefront. No new permission, setting, scheduler task or message.

---

## بالعربية باختصار

- **البحث الشامل:** زر «بحث» في الشريط العلوي أو **Ctrl/Cmd + K** أو **/**. اكتب رقم طلب (أو جزءاً منه) أو رقم هاتف بأي صيغة (+974 أو 974 أو ثمانية أرقام أو أرقاماً عربية) أو اسم عميل أو رمز منتج أو اسم صفحة (نقد، جرد، مرتجعات...). تظهر النتائج في مجموعات: الطلبات والعملاء والمنتجات والخيارات وانتقال إلى. حرف واحد لا يبحث، حرفان يجدان صفحة، ثلاثة أحرف تبحث في الجداول. أقصى 5 نتائج في كل مجموعة. كل مجموعة تُفحص بصلاحيتك وفروعك على الخادم، وتبقى بيانات العميل مخفية جزئياً كما في قائمة العملاء. لا يُحفظ نص البحث على الخادم ولا يُسجَّل، وهناك حد 90 عملية بحث في الدقيقة لكل موظف. تُحفظ عمليات البحث الأخيرة في متصفحك فقط ولا تُحفظ أرقام الهواتف. نتيجة الطلب تفتح نافذته الجانبية، والعميل نافذته، والمنتج عرضه السريع، والصفحة تنتقل إليها.
- **اللوحة المباشرة:** العمليات > اللوحة المباشرة. ستة مسارات: جديد، قيد التجهيز، جاهز، في الطريق، بانتظار الاستلام، يحتاج انتباهك. كل جزء من طلب بطاقة واحدة في مسار واحد. يدخل «يحتاج انتباهك» كل ما تأخر (الساعة حمراء) أو فشل توصيله أو طلب العميل إلغاءه أو لم يجب على سؤال الاستبدال. البطاقة تُظهر الرقم وعمر الانتظار وعدد الأصناف والموعد وطريقة الدفع والمبلغ المطلوب تحصيله واسم السائق، بلا اسم العميل ولا هاتفه. «فتح» يفتح الطلب في نافذة جانبية، و«قبول» يقبل الطلب بالصلاحية نفسها، و«تعيين سائق» ينقل إلى صفحة التوصيل.
- **التحديث التلقائي:** مباشر عبر قناة الأحداث، وإن انقطعت فيعمل مؤقت كل 10 ثوانٍ مع مؤشر «جارٍ إعادة الاتصال» ثم «لا يتحدّث» بالأحمر إن توقف تماماً. لا تنبيه صوتي مزدوج: التنبيه هو تنبيه لوحة التحكم الحالي.
- **شاشة الجدار:** زر «شاشة الجدار» (أو `?wall=1`) تعرض اللوحة وحدها بخط كبير بلا قائمة ولا أزرار، وتمنع الشاشة من النوم. استخدم لها حساب موظف للعرض فقط.
