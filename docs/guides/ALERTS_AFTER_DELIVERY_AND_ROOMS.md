# Product alerts, the after-delivery journey and shop by room

Three features that bring customers back (roadmap 3.6, 3.8, 3.9). This page is both the owner's guide (what you see, where the switches are) and the
design note (what was decided, why, and what it costs). The API for the apps and the web store is in [API_CONTRACT.md](../api/API_CONTRACT.md)
(section 6b for the alerts, section 5 and section 10 for rooms).

| Feature | Customer sees | Where you control it | Default |
|---|---|---|---|
| **Back-in-stock and price-drop alerts** | A "Notify me" button on an out-of-stock product; an optional "Tell me if the price drops" on any product; a list of their alerts in the account | **Settings > Notifications** (switches, limits, quiet hours) and **Content > Notification controls** (the message itself) | Back in stock **on**, price drops **off** |
| **After-delivery journey** | A thank-you, a review request and a "goes well with it" message after an order arrives | **Marketing > Customer journeys > After delivery** | **Off**, nothing is sent until you switch it on |
| **Shop by room** | Large room tiles on the home page, a room filter in the shop, a page for each room | **Content > Shop by room** | Five rooms ready, tiles appear by themselves |

Arabic wording of all three was written for this release and has not been reviewed by a native speaker: please have it read before it goes to customers
(the dashboard words are in `backend/lang/ar/alerts_admin.php`, `after_delivery_admin.php` and `rooms_admin.php`; the customer messages are in
`notifications.php` and the journey templates in the journey page itself).

## 1. Back-in-stock and price-drop alerts

### What the customer does
- On a product that cannot be bought at their branch the page shows **Notify me when it is back**. One tap (signing in first if needed, then the tap is done for them).
- When price alerts are on, any product also offers **Tell me if the price drops**.
- **Account > Alerts** (web) and **Account > Alerts** (app) list what they watch, with the price they last saw and the price now, and a button to stop.
- The wishlist rows show the same two buttons.
- When it happens they get one message in the app inbox and a push: "Oslo Sofa is back in stock" or "Oslo Sofa is now QAR 899 (was QAR 999)". Tapping opens the product.

### What the rules are
- **Back in stock**: one message, then the alert is finished. Sent when the variant can be bought at the alert's branch (any branch when none was chosen) and it is still true at the moment the job looks.
- **Price drop**: the alert keeps watching. It is sent when the price the customer would pay now (sale and a running deal included) is lower than the price they last saw by at least *both* the percent and the money minimum, and the product is in stock. After the message the "last seen" price moves to the new one, so the same price is never announced twice.
- **Quiet hours** 22:00 to 08:00 Qatar time: nothing is sent; a message that is due waits and goes out only if it is still true in the morning.
- **Consent**: a back-in-stock message is something the customer asked for, so it does not need the marketing consent. A **price-drop message is marketing** and goes only to customers who agreed to marketing messages.
- **Weekly cap**: at most 3 price-drop messages per customer in any 7 days; the biggest drop goes first.
- **Limits**: 100 active alerts per customer. An alert nobody acted on is forgotten after 90 days; a back-in-stock alert is also removed 30 days after it fired.
- **Idempotent**: each alert is claimed with one atomic update before it is sent, so a repeated job, two servers or a retry never send it twice.

### Where you control it
**Settings > Notifications** (the fields start with "Alerts:"): back in stock on/off, price drops on/off, smallest drop in percent and in money, price messages per customer per week, how long an alert is kept, quiet hours. The wording of the messages and the on/off for the message itself are under **Content > Notification controls** (events *Back in stock* and *Price drop*).

### What branch staff and the owner see
In the product editor and its quick view a small badge says **"N customers are waiting for it to be back"** and **"N customers watch the price"**, so you can see which product to restock first.

### How it runs
- The inventory ledger and the price editor already announce "back in stock" and "price changed"; a listener queues `ProcessStockAlertsJob` for that variant or product.
- `php artisan alerts:process` runs every 5 minutes as a safety net (a missed event never loses an alert). `php artisan alerts:prune` runs daily at 04:30 Qatar time.
- Both are on the **System health** card of the dashboard.

### Design notes
- `stock_alerts` already existed; the migration adds `product_id` (one indexed lookup for "who is waiting for this product") and `expires_at`, plus the partial indexes the queries use (pending back-in-stock per variant, price watchers per product, expiry, the weekly cap on `notifications`).
- The price is compared with the **effective** price, so a deal that starts or ends moves the alert correctly. It is never compared with the regular price.
- Trade-off: a price alert is per variant and per branch choice, not per product, so a customer who wants "any size" subscribes to the sizes they care about.

## 2. After-delivery journey

### What the customer gets (when you switch it on)
| When | Message | What it does |
|---|---|---|
| 2 hours after delivery | **Thank you** | A short thank-you with the order number |
| 4 days after | **Review request** | Names a product they have not reviewed; the link opens the review window on that product (`?review=1`, or `albahar://products/{id}?review=1` in the app) |
| 7 days after (optional) | **Goes well with it** | Up to three products from the "Complete the set" suggestions of what they bought, never something they already bought |

Each message can be switched off, moved (1 minute to 30 days after delivery) and reworded in English and Arabic, with a preview of what the customer reads.

### Who is left out
- For good: an order that was returned or refunded, had a failed delivery, or has a return or damage claim; and a customer who already got this journey for another order in the last 14 days (changeable: *Do not repeat within*).
- A message is skipped (the rest still go) while the customer has an open support ticket, when every product is already reviewed, or when there is nothing to suggest. All of this is read again at the moment the message would go out.
- Never late: a message more than a day overdue (the scheduler was down) is dropped, not sent.

### Rules it follows
Marketing consent, the quiet hours 22:00 to 08:00 Qatar time, and the daily per-customer message cap of the journey engine. All three messages need the customer's marketing consent (the engine checks it at the moment of sending), even the thank-you. One run per delivered order (a unique index on `journey_runs.trigger_order_id`), so the scheduler can look at an order a thousand times and it gets one run.

### Where you control it
**Marketing > Customer journeys > After delivery**: the big switch, each message's switch, timing and words, the cooldown, and the last 30 days of *sent* and *opened* per message (opened = the customer read it in the inbox; a push alone cannot be told apart). The journey is created switched off the first time the page or the scheduler runs.

## 3. Shop by room

### What the customer sees
- **Home page**: a "Shop by room" section of large tiles (Bedroom, Kitchen, Bathroom, Living room, Dining) after the categories.
- **Shop**: a **Room** filter next to the category filter; the choice is in the address (`/shop?room=bedroom`) so back and share work.
- **Room page** for search engines: `/shop?room={slug}` has its own title, description, canonical link and structured data (a collection page with breadcrumbs), and is in the sitemap only while the room has products.
- A room with no product the customer can see at their branch is left out, so an empty room never leads to an empty page.

### What belongs to a room
A product is in a room when it is in one of the room's **categories** (their sub-categories too) **or** carries one of the room's **tags** (compared without regard to upper or lower case). The tags are the ones you already type on a product (*Product > Tags*), so a rug can be in Bedroom and Living room at once.

### Where you control it
**Content > Shop by room**: add, rename (English and Arabic), reorder, switch off, choose categories and tags, upload a picture (stored as 1000 px and 480 px WebP, re-encoded so no camera data travels with it). A room with no picture borrows the picture of its first category. The list says which rooms customers cannot see yet ("No products yet") and how many products each has. The home page builder (*Content > Home page*) can move, retitle or hide the section like any other.

The five default rooms are created by the migration and mapped to the real category slugs (`bedding-linens`, `kitchen-dining`, `bath-personal`, `decor-living` + `home-fragrance`; Dining uses the tag `dining`). The home section itself is **not** created by the migration: `php artisan rooms:ensure-home` (also run hourly by the scheduler and the first time someone who may edit content opens *Content > Shop by room*) adds it once, after the categories. **Run it once after deploy** to see the tiles at once. An owner who removes the section keeps it removed (a setting remembers it was done).

## Deploying
1. `php artisan migrate` (three migrations: `2026_11_21_100000_stock_alerts_expiry_product_and_indexes`, `2026_11_21_130000_after_delivery_journey`, `2026_11_21_140000_create_rooms`). All three have a working `down()`.
2. `php artisan rooms:ensure-home`.
3. Restart the queue worker (the alert job is new).
4. Decide the switches: price-drop alerts and the after-delivery journey are off until you turn them on.

## Decisions for the owner
- **Price-drop alerts on or off?** Shipped off, because the messages are marketing and the thresholds (5 percent and QAR 5) are a guess for your prices. Turn it on, watch a week, then tune.
- **After-delivery journey on or off?** Shipped off. The review request is the one worth switching on first; the "goes well with it" message needs the *Complete the set* suggestions filled in on your products to say anything.
- **Arabic wording** needs a native read (above).
- **Room pictures**: the tiles borrow category artwork until you upload room pictures; real photography will sell better.

## Tests
- Backend: `backend/tests/Feature/Catalog/StockAlertsTest.php`, `tests/Feature/Marketing/AfterDeliveryJourneyTest.php`, `tests/Feature/Catalog/RoomsTest.php`. Run: `cd backend && CACHE_STORE=array vendor/bin/pest tests/Feature/Catalog/StockAlertsTest.php tests/Feature/Marketing/AfterDeliveryJourneyTest.php tests/Feature/Catalog/RoomsTest.php`
- Storefront: `storefront/tests/alerts.test.mjs`, `tests/rooms.test.mjs`, `tests/shop-query.test.mjs`. Run: `cd storefront && npm run typecheck && npm test`
- Customer app: `mobile/test/features/alerts/stock_alerts_test.dart`, `mobile/test/features/rooms/rooms_test.dart`, `mobile/test/features/reviews/review_deep_link_test.dart`. Run: `cd mobile && flutter test test/features/alerts test/features/rooms test/features/reviews`
