# Return and damage claims, and substitution consent

Two features of the order after-sale experience (roadmap 3.3 and 3.4). Both are built end to end: database, services, API, dashboard, web store and the customer app (and the branch app for the waiting clock).

- Contract: [API_CONTRACT.md](../api/API_CONTRACT.md) (sections 8.1 and 8.2), the missing-item flow it builds on: [ORDER_ADJUSTMENTS.md](../api/ORDER_ADJUSTMENTS.md).
- Code: `App\Domain\Orders\Services\{ReturnClaims, ReturnService, ReturnClaimPresenter, ClaimPhotoStore, SubstitutionService, SubstitutionClock}`.

---

## 1. Return and damage claims (3.3)

### What the customer does
In the order window (app and web) a delivered order has **Report a problem or return**. The customer chooses what happened (**damaged on arrival, wrong item, something missing from the box, quality problem, I changed my mind**), the pieces (per line, with a quantity stepper), photos and a short note (500 characters, Arabic and emoji safe) and sends it.

- A **damaged** or **wrong item** claim **needs at least one photo**; the other kinds do not. At most 5 photos, JPEG / PNG / WebP, 8 MB each. The apps scale pictures before sending.
- The window says **"You can report a problem until <date>"** (the last day for the pieces that can still be reported).
- A claim is a **timeline** inside the order window (no new page): *Claim received → Under review → Approved / Not approved (with the team's reply) → Pickup arranged / Bring it to the branch → Item received → Refunded (amount and how)*. A claim that ends early ends the line there (not approved, cancelled by the customer).
- While the team has not decided (**received / under review**) the customer can **add photos or a note**, and **cancel** the claim. After the decision both are closed (`409 claim_not_editable`).
- Sending is **idempotent**: the apps send an `Idempotency-Key`; the same key (or an identical claim within two minutes) answers with the claim already made, never a second one.
- Several claims on one order are fine, but **a piece can be in only one claim at a time**; a claim cannot mix branches (the branch that handles it must be one).

### What the branch / back office does
Dashboard → **Sales → Returns**. A row opens the claim in the side window:

- the **photos** (tiles; a tap opens a viewer you can zoom and step through, with "open full size"), the customer's note and anything they added later, the order lines (claimed of delivered, price), and the claim's own log (who did what, when);
- decisions in the footer: **Start review** (the customer sees "under review"), **Approve** (choose how the goods come back: *we collect it / the customer brings it to the branch / nothing comes back*, plus a pickup window or instruction the customer reads) or **Reject** (a reply is required), **Mark received**, **Raise refund**, **Close**.
- **Stock when the goods arrive**: the manager chooses **put back in stock** or **write off**. A claim about the condition of the goods (damaged, wrong, missing, quality) is **never restocked by itself**: the window offers *write off* first and the manager must choose *restock*. A plain return (changed my mind) offers *restock*.
- **Refund** goes through the **existing refund flow** (`ReturnService::refund` → `RefundService::request`), with its own dual control and finance approval. Nothing here pays money. A claim whose goods never come back (an item missing from the box, a piece the customer keeps: method *nothing comes back*) goes straight from approved to the refund.
- Permissions are the same as before and **branch-scoped**: opening needs `orders.view` at the claim's branch, starting a review and receiving need `orders.edit`, approving / rejecting / closing need `orders.approve`, the refund needs `refunds.create`. Every step is **audit-logged** (`return.requested`, `.evidence_added`, `.review_started`, `.approved`, `.rejected`, `.received`, `.refund_requested`, `.cancelled`, `.closed`).
- **Needs attention**: a claim about the goods (damaged, wrong, missing, quality) that nobody has decided shows in the *Needs attention* inbox (dashboard and branch app list, "Claim waiting for a decision") once it has waited longer than the new **Operations clock → Return or damage claim** limits (default **4 h** getting late, **24 h** late, opening hours only). It opens the claim in the side window. A plain "changed my mind" return is not urgent and stays on the Returns page.

### What the customer is told
Each customer-visible step sends **push + in-app** (new catalogue events, English and Arabic, ON by default, switchable per event under **Content → Notification controls → Returns and damage claims**): `return.received` (inbox only: it is the customer's own action), `return.under_review`, `return.approved` (says how the item comes back and carries the team's reply), `return.rejected` (carries the reply), `return.item_received`. The refund step uses the existing `refund.processed` message when the money is sent.

### Photos are private
Files are decoded and **written again as plain JPEG** (every trace of location, camera and embedded data is gone; the phone's rotation is applied; the long side is scaled to 1600 px), stored on the **private disk** under `return-claims/<claim>/<random>.jpg`, and reachable only through authorised routes: the customer's own claim (`GET /api/v1/returns/{id}/photos/{photo}`, bearer token) and the dashboard (`/admin/returns/{claim}/photos/{photo}`, `orders.view` at the claim's branch, otherwise a plain 404). The same picture sent twice is kept once.

### Where the switches are
Settings → **Checkout & fees**: *Let customers return orders* (existing) and **Days to report a problem or return** (`returns.window_days`, default 14, 1 to 90). Settings → **Operations clock**: the two claim limits. Content → **Notification controls**: the five claim messages.

---

## 2. Substitution consent (3.4)

### What happens
When the branch **replaces** a line (missing item flow) with **another product**, or with one **whose own price is above what the customer ordered**, the customer is asked: *"We do not have X. Replace it with Y?"* with **Accept** and **No, remove it and refund me**, the time left, and what happens if nobody answers. The replacement is **already in the order** (reserved for it) and **never costs more** than the line it replaces (the adjustment rule is unchanged), so the customer can never be charged more than they agreed to. Reducing a quantity or removing a line never asks; neither does another size or colour of the same product at the same or a lower price.

- **Accept**: the replacement stays. Recorded in the order history and on the revised invoice ("Replacement accepted by the customer").
- **No**: the replacement is taken out again through the normal adjustment (revision reason *You did not accept the replacement*): its stock is released, anything paid for it is refunded as a normal refund request that finance approves, the revised invoice is issued, the customer is told.
- **No answer by the deadline**: the shop's **fallback** decides. Default **remove and refund** (same path as "No"). Alternative **keep the replacement** at no extra cost (never dearer than the original; the customer is told). The fallback in force is fixed when the question is asked and shown to the customer.
- The answer is **idempotent**; only the order's owner can give it (any other order or question answers 404); a different answer once settled is `409 substitution_already_answered`; an answer after the deadline settles the question by the fallback and answers `409 substitution_expired`.
- **Deadline**: Settings → Checkout & fees → *Time the customer has to answer* (default **30 minutes**, 5 to 1440), counted in the **branch's opening hours** (the order-clock rule), so a question asked at 21:50 does not run out while the shop is shut. The moment is worked out when the question is asked and stored. `substitutions:expire` runs every minute and settles the questions that ran out.

### What the branch sees
**Waiting for the customer** with a live clock: on the dashboard **order list** (chip in the status column), in the **order window** (banner listing the replacements and the hint), in the **branch app** (the order card and the order window, from `waiting_for_customer` on `GET /admin/dispatch/queue`). The part **cannot be marked ready** while a question is open (`409 substitution_pending`); the rest of the order, and the other parts, are prepared as usual. When half of the time is used (amber) or all of it (red, waiting for the minute job) the question appears in **Needs attention** ("Waiting for the customer's answer", with a *Call customer* button).

### Settings and messages
Settings → Checkout & fees: **Ask the customer before replacing an item** (default on), **Time the customer has to answer**, **If the customer does not answer** (*remove and refund* default / *keep the replacement*). Notification controls: `order.substitution_offer` (push + in-app; it **replaces** the plain "order updated" for that revision; switched off, the plain message is sent) and `order.substitution_kept`. Declined and timed-out removals send the normal "order items changed" message.

---

## 3. Design note: decisions taken, defaults and trade-offs

1. **Claim = the existing `return_requests` row, extended** (new statuses `under_review`, `cancelled`; new tables `return_request_photos`, `return_request_events`). One lifecycle, one admin page, one refund path. Claim *type* is the existing `reason_code` (new `missing_item`, `quality`; `not_as_described` from older apps reads as quality).
2. **Older apps**: an old build that sends a *damaged* claim without photos now gets a clear 422 on `photos` (it shows its generic message). The other kinds still work. Notes over 500 characters are refused (old limit 1000).
3. **One branch per claim.** A multi-branch order needs one claim per branch (clear 422 on `items`). Auto-splitting would duplicate photos and complicate idempotency; multi-branch orders are rare.
4. **More than one claim per order** is allowed (the old rule blocked a new request while one was open). The no-double-claim guarantee is per piece (`holding` claims: open ones, plus refunded-without-return ones). The existing test of the old rule was changed on purpose.
5. **Restock rule**: restock by default only for claims that are not about the condition of the goods; defect claims default to write-off and need an explicit manager choice. The decision is stored on the claim (`stock_decision`). A write-off adds no stock movement (the unit was already out of the branch's count).
6. **Claim window** is one setting for every claim type, applied to the delivery / collection time per parcel; changing it applies to already delivered orders too.
7. **Substitution is applied first, then confirmed** (instead of holding it back until answered): stock is reserved while the customer decides, the order total can only go down, and "No" / timeout reuse the audited, replay-safe adjustment with its refund rules. The cost is that a declined replacement leaves one extra revision in the history, which is what the customer asked for.
8. **A higher price is never charged.** The brief mentions "+QAR n"; the existing adjustment rule (a replacement never costs more) is kept, so the question shows *same price* or *QAR n less*. Charging the difference would need an extra payment flow for paid orders. **Owner decision**: say if you want upsell-with-consent; it needs that payment step.
9. **Deadline in opening hours**, fixed at asking time (a later change of hours does not move it). If the branch has no hours it counts wall-clock minutes. At most 14 days of wall clock per question.
10. **Consent rule** (default on): another product, or a replacement whose own price is above the original's. Same product with the same or lower price (a size or colour) does not ask. Switch the whole thing off with the setting.
11. **System removal** uses the adjustment with no staff actor (the customer for "No", nobody for the clock): the revision's actor is the customer or empty, and two reasons (`substitute_declined`, `substitute_expired`) that staff can never pick. The `order_revisions` reason check was extended (the `down()` maps them to `other`).
12. **One message per revision**: when a revision opens questions, `order.substitution_offer` carries the whole change and the plain `order.items_changed` is not sent; if the offer message is switched off, the plain one is.
13. **Staff app list for claims**: dashboard-only (the app's Needs attention list shows the line and says it is handled on the dashboard); the app does not have a returns screen.

14. **Declining the only line of a parcel**: when the replacement is the last live line, the customer's "no" (or the clock) cancels the parcel and, if it was the last one, the order, with the normal refund. A removal like this does not need a manager's approval, because it is the customer's own answer: the approval rule applies only to the five staff reasons (`OrderRevision::REASONS`), and only `SubstitutionService` can use the two system reasons. Found by the independent review of the release and covered by a test.

### Known limits (independent review, accepted for now)
- If the branch reduces the quantity of a replacement that is still waiting for an answer, the question is closed (the line stays, never dearer). It would be better to keep asking; no money is at risk.
- A claim's evidence log has no cap on the number of notes a customer can add (each note is 500 characters, 20 requests a minute); staff see the long log. A cap per claim is the next step if it is ever abused.
- Photos are decoded up to 50 million pixels per file, inside the claim's database lock. The photo count is checked after storing on purpose (a retried upload that already worked must not trip the limit), so a rejected upload still pays the decode once.

## 4. Tests
`backend/tests/Feature/Orders/ReturnClaimsTest.php` (31), `SubstitutionConsentTest.php` (22); updated: `OrderReturnStatusTest`, `AppConfigReturnsInvoiceTest`, `ApiAuditTest`, `SalesScreensTest`, `OpsClockSettingsTest`. Web: `storefront/tests/return-claims.test.mjs`, `substitutions.test.mjs`. App: `mobile/test/features/orders/claims_test.dart`, `substitution_test.dart`, `mobile/test/ops/staff_waiting_test.dart`; updated `returns_reviews_calls_test.dart`.
