# Al Bahar: what to build next (audit of 19 Nov 2026)

Source: a code review of the customer app and web store, the driver app, the branch staff app and the admin dashboard, plus checks on the live server.
Every finding below was read in the code with a file reference; the ones marked **verified** were re-read a second time before any work started.
The live store is young (about 15 orders), so this list is about *what will hurt first as orders grow*, not about today's volume.

Legend: value (H/M/L), effort (S = under a day, M = a few days, L = a week or more).

---

## 0. Needs the owner (nothing the code can fix alone)

| # | What | Why it matters | What to do |
|---|------|----------------|------------|
| O1 | **OTP test mode is on in production** (verified live: `OTP_TEST_CODE` is set and `SMS_DRIVER=null`) | While it is on, the sign-in code is returned in the API response, so anyone can sign in as any customer by typing their phone number and see their addresses and orders. The repo's own audit lists it as "temporary until an SMS provider is connected". | Connect a Qatar SMS (or WhatsApp) provider, then empty `OTP_TEST_CODE` and set `SMS_DRIVER=http`. Do not switch it off before that, or no customer can sign in. This is the single most important item on this page. |
| O2 | Card / Apple Pay / instalments (Tabby, Tamara) | Only COD, pay at pickup and SADAD (currently off) exist. For QAR 3k to 15k baskets this is probably the biggest conversion unlock. | Choose a Qatar payment provider and send me the account details; the gateway slot already exists (`PaymentGateway`). |
| O3 | Partner share link uses one shared password and shows customer names and exact drop locations | Anyone with the password sees personal data, outside staff sign-in. | Tell me who the partners are; I will hide names and switch to a per-partner link that can be revoked. |
| O4 | A super admin can approve their own refund request; any user with `settings.edit` can switch off mandatory 2FA | Dual control is bypassed. | Decide the policy (second approver, or owner-only). |
| O5 | WhatsApp as a support / order-chat channel | Furniture is a family decision; customers will want to ask and share. | Someone must staff the number. |

---

## 1. Wave 1: bugs that cost money, stock or trust (in progress)

Verified defects. All are being fixed with tests; none needs new screens.

**Orders, delivery and cash**
- Cancelling an order never cancels its delivery: the driver keeps a live job, drives to the customer, and "Delivered" fails (**verified**).
- Approving a cancellation after goods left the branch refunds or voids the whole payment (also after a partial pickup) (**verified** in code path).
- Goods can leave the branch while a cancellation request is waiting for the manager.
- An order-desk user can mark a COD order "delivered" with no driver, proof or cash; "Mark returned" restocks without approval (**verified**).
- Assigning / re-assigning a driver is not atomic and the web "Reassign" button fails for accepted jobs; deliver and fail are not safe to repeat (the app retries after a timeout) (**verified**); any driver can cancel or return any delivery in the branch.
- Unpaid online orders show as "New" in the staff app and alert staff, but accepting them fails.
- Split (multi-branch) COD orders ask the first driver to collect the whole order (being investigated).
- Pickup pass expiry has no way out in the dashboard.

**Owner numbers**
- "Revenue today" only counts paid orders, so COD shops see ~0; split orders count in full for every branch; "orders today" includes cancelled and unpaid; tile, chart and report disagree.
- Reports undercount when two orders have the same total (`sum(distinct)`); lifetime value, refunds and net sales are affected.
- The scheduler lock can stall payment reconciliation for up to 24 hours after a deploy; no heartbeat; no view of failed jobs.
- Campaign sending can message every customer twice and can hang forever.
- Provider secrets are cached decrypted in Redis for 5 minutes.
- Missing indexes on the order, payment and refund tables.

**Customer experience**
- Payment dead end: when the basket is over the COD limit and SADAD is off the customer sees a generic "Not available" (app) or a raw key (web).
- Web: two open tabs sign the customer out of everything after an hour; a temporary server error wipes the session; the invoice download fails after an hour.
- App: "add to cart" is forgotten after sign-in; returns give no feedback and no status.
- Web is missing colour / material / size filters the app already has; the home page trust strip reads like grocery copy.

---

## 2. Wave 2: "Live Operations Control" (the flagship feature)

One connected feature for the branch, the driver and the owner: *nothing waits silently, and everyone sees what needs them right now.*

| # | Capability | Who | Value | Effort | Builds on |
|---|-----------|-----|-------|--------|-----------|
| 2.1 | **New-order alerts that cannot be missed**: push to branch staff for every new order including pickups; live toast on every dashboard page, not only the fleet map | Branch | H | S | `LiveOrderEvent`, `PushNotifier::branch` |
| 2.2 | **Order clock (SLA)**: every order shows its age; amber after X minutes, red after Y; manager is pushed when an order is not accepted; thresholds editable in settings | Branch, owner | H | S | `placed_at`, `accepted_at`, `ready_at` already stored |
| 2.3 | **Exception inbox** (dashboard page + home card): unaccepted orders, ready but not dispatched, pickups waiting more than N days, cash held by a driver too long, failed deliveries, refunds waiting, stuck campaigns, failed jobs | Owner, manager | H | S-M | existing lanes and filters |
| 2.4 | **Driver job card for heavy furniture**: gate code / customer notes, the recipient's name and phone, floor, "needs two people", assembly requested, weight and dimensions | Driver | H | S-M | data already stored but not sent to the app |
| 2.5 | **Proof of delivery viewer**: photo, time, recipient, GPS on the order page, and (privately) to the customer | Branch, owner, customer | H | S | `delivery_proofs` is write-only today |
| 2.6 | **Driver day-end**: cash in hand, jobs done, one-tap "off duty"; decline a job, accept timeout with automatic re-assign, auto off-duty when the phone goes silent | Driver, manager | H | M | `CashDrawerService::balancesFor`, `last_seen_at` |
| 2.7 | **Failed-delivery kit**: reason + note + photo, damaged option, propose a new slot, customer is messaged to rebook | Driver, customer | M | M | `rescheduleToSlot`, delivery slots |
| 2.8 | **Pickup in the staff app**: pickup tab, QR scanner, pass re-issue, hand over by order number | Branch | M | M | pickup API and `mobile_scanner` exist |
| 2.9 | **"Arrived" + "10 minutes away"** message to the customer; fewer absent-customer failures | Driver, customer | M | M | ETA service, live card |
| 2.10 | **Printed picking list and delivery note** (barcode, bin location, COD amount, slot, signature line) | Branch | M | S-M | invoice PDF, `Code128` |
| 2.11 | **Cash shift**: open / close, float, end-of-day count, Z-report; hand-in by selected collections | Branch, owner | M | M | `CashHandover` |
| 2.12 | **Branch and driver scorecard**: time to accept / prepare, on-time rate, failure reasons, cash variance | Owner | M | S | timestamps already stored |

## 3. Wave 3: more orders from the same customers

| # | Capability | Value | Effort |
|---|-----------|-------|--------|
| 3.1 | **Delivery or pickup date on the product page and cart** (furniture buyers need the date before they pay) | H | S-M |
| 3.2 | Guest cart in the app (the web already has one) | M | S |
| 3.3 | **Return and damage claims with photos**, status and push notifications | H | S-M |
| 3.4 | **Substitution consent**: customer accepts the replacement or cancels that line for a refund | M | M |
| 3.5 | Paid assembly and installation add-on (the flag exists but cannot be bought) | M-H | M |
| 3.6 | Back-in-stock and price-drop alerts: subscribed state, list, cancel, wishlist price-drop push | M | S |
| 3.7 | Share a basket or product on WhatsApp | M | S |
| 3.8 | Post-delivery journey: thank you, review request, "need anything to go with it" | M | S |
| 3.9 | Room-based browsing and style entry points on the web home | M | S |
| 3.10 | Card / Apple Pay / instalments (needs O2) | H | L |

## 4. Wave 4: owner intelligence

| # | Capability | Value | Effort |
|---|-----------|-------|--------|
| 4.1 | Daily close per branch and weekly owner pack by email / WhatsApp (aggregates only, no personal data) | M | M |
| 4.2 | Reorder points and stock-out report (days of cover, demand from alerts and wishlists) | M | M |
| 4.3 | Global search / command palette (orders by number or phone, customers, SKUs) | M | S-M |
| 4.4 | Live operations board with auto-refreshing lanes per branch | M | M |
| 4.5 | Customer value and "gone quiet" lists; coupon / campaign return on investment | M | M |
| 4.6 | Stocktake approval by a second person; branch-scoped read access on issue log, customer lifetime spend and split orders | M | S |

---

## 5. What was checked and found fine

Branch scoping on writes (pricing, stock, cash, refunds, returns, pickup, support, deliveries) is re-checked per branch on the server. Checkout uses quote hashes, idempotency keys and slot auto-pick. Right-to-left layouts in the app and web showed no left/right mistakes. Order adjustments (missing items) are complete, replay-safe and in the app.

## 6. Not reviewed in depth

Auction engine internals, loyalty screens, CMS screens, push delivery on a real device, which branches really run auto-dispatch. The driver and staff apps' offline behaviour is a known gap (no cache, no queue); it is a Wave 2 follow-up after the delivery-state fixes.
