# Handover & completion report

Al Bahar Home & Living platform. Scope: the eleven-phase specification in `docs/spec/`.
This report lists, phase by phase, what was delivered, how it is verified, and what remains open.

## Summary

| Area | Delivered | Verified by |
|---|---|---|
| Backend API (`backend/`) | Laravel 13 modular monolith, 171 PostgreSQL tables, REST `/api/v1` with 212 documented paths (`docs/api/openapi.json`, regenerated with `php artisan scramble:export --path=../docs/api/openapi.json`) | Pest: **3,998** Unit + Feature tests and **21** Concurrency tests (real multi-process row locks, incl. cash hand-in, deposit and driver-step races), all passing except the health test that needs Redis (not installed in the test container); migrations up/down/up |
| Admin dashboard | 50+ Blade + Livewire screens covering phases 1–10, EN/AR with RTL, light/dark, phone-friendly; driver view at `/admin/driver`; camera pickup scanner; live auctioneer console | Feature tests per screen (authorised 200 / unauthorised 403, branch isolation, key workflows); screenshot review EN/AR at 1440 px and 390 px |
| Customer app (`mobile/`) | Flutter Android/iOS, 15 feature modules, EN/AR RTL, push, live auctions | `flutter analyze` (no issues), **2,828** unit/widget/contract tests (10 skipped), headless integration smoke test |
| Operations | Docker image, compose stack, CI, deploy workflow, verified backups, monitoring/security/launch docs | CI pipeline (`.github/workflows/ci.yml`) |

## Per phase

| Phase | Status | Highlights |
|---|---|---|
| 01 Foundation & access | ✅ | OTP (HMAC, TTL, attempts, cooldown, caps), rotating refresh tokens with reuse detection, RBAC + branch scope, staff TOTP 2FA (mandatory for admin sessions), immutable redacted audit log, uniform error envelope, rate limits, security headers |
| 02 Branches & organization | ✅ | Branches, hours, holidays, slots, delivery zones and fees, employees with branch roles, authority-limited grants |
| 03 Catalog & pricing | ✅ | Categories (materialised path), products/variants/media (WebP), price precedence with branch availability, Arabic-normalised trigram search, CSV/XLSX import/export, price history |
| 04 Inventory & purchasing | ✅ | Single-writer ledger with DB invariants, reservations, transfers, adjustments (maker/checker), stocktaking, purchase orders & goods receipts |
| 05 Flutter app | ✅ | All required sections incl. checkout with split plan and `quote_changed`, SADAD in-app browser, pickup QR pass, live auctions with gap-safe replay |
| 06 Cart, checkout, orders | ✅ | Server re-quote + quote hash, idempotent placement, multi-branch allocation, order/fulfillment state machines, cancellations, returns, invoices (AR/EN PDF) |
| 07 Payments & refunds | ✅ (SADAD details to confirm) | Hosted checkout, verified idempotent callbacks, status re-confirmation, reconciliation, expiry, late-capture flag, refunds with dual approval |
| 08 Pickup & delivery | ✅ (courier to select) | HMAC QR pickup passes, partial handover, drivers, COD collection, provider adapter contract + signed webhooks |
| 09 Auctions | ✅ | Row-locked bid engine, proxy bids, anti-sniping, sequenced events broadcast after commit, deposits, unpaid-winner policies |
| 10 Marketing, CMS, notifications | ✅ | Promotion stacking engine, coupons, loyalty FIFO ledger, referrals with fraud controls, reviews/Q&A, campaigns with consent, homepage builder, templates, push/SMS/WhatsApp/email |
| 11 Reports, security, QA, deployment | ✅ | 22 reports + async exports, docs set, Docker, CI/CD, backup & restore drills, launch checklist |

## How to verify

```bash
cd backend && vendor/bin/pest --testsuite=Unit,Feature && vendor/bin/pest --testsuite=Concurrency && vendor/bin/pint --test
cd mobile && flutter analyze && flutter test
```

## Known limitations & open items

**External decisions (block go-live):**
- **SADAD**: field names, checksum algorithm, status API and refund API must be confirmed against the merchant onboarding pack (`docs/integrations/SADAD.md`). Refunds are manual (bank transfer, recorded reference) until the refund API is confirmed.
- **Courier**: no third-party courier chosen; only the mock adapter (refuses to run in production) exists. Internal drivers are fully supported.
- **SMS gateway, FCM/APNs, WhatsApp templates, store accounts**: credentials and approvals needed.

**Not verified in this environment:**
- The Docker image build and Larastan static analysis run only in CI (no Docker daemon here; Larastan installs in CI).
- The mobile app has not run on a physical device or emulator (no Android SDK/Xcode here). Gradle flavours and the iOS project are configured but unbuilt; iOS uses one scheme with dart-define flavours; the Arabic home-screen name needs `InfoPlist.strings` added in Xcode.
- Push notifications end-to-end (no Firebase project files).

**Scope decisions:**
- Wave 2 leftovers and Wave 4 (`product/ROADMAP.md` sections 2 and 4): **built** and live as 1.0.216 — printed picking list and delivery note (`guides/PICKING_LIST_AND_DELIVERY_NOTE.md`), cash shift with Z-report (`guides/CASH_SHIFT.md`), branch and driver scorecard (`guides/BRANCH_AND_DRIVER_SCORECARD.md`); daily close, weekly owner pack, customer value and offer return (`guides/OWNER_PACK_AND_CUSTOMER_VALUE.md`); stock planning and second-person stocktake approval (`guides/STOCK_PLANNING_AND_STOCKTAKE.md`); global search and the live operations board (`guides/SEARCH_AND_LIVE_BOARD.md`). The weekly owner pack ships OFF: it needs a mail account and the owner's recipient addresses (WhatsApp stays "not connected" until a provider exists). Not built on purpose: petty-cash payouts in the cash shift, a PDF download of the Z-report (it prints), a view-only account for the wall display. Arabic wording of the new screens still needs a native speaker's read. Several numbers are defined in more than one guide (scorecard versus daily close); a later pass should unify them.
- Gift cards: not implemented (not required by the phase acceptance criteria).
- Wave 3 (customer growth, `product/ROADMAP.md` section 3): **built** — delivery/pickup date promise, paid assembly, guest cart in the app, WhatsApp share (`guides/DELIVERY_PROMISE_ASSEMBLY_AND_SHARING.md`); return and damage claims with photos and substitution consent (`guides/RETURNS_AND_SUBSTITUTIONS.md`); back-in-stock and price-drop alerts, the after-delivery journey and shop-by-room (`guides/ALERTS_AFTER_DELIVERY_AND_ROOMS.md`). The messages that sell (price-drop, review request, "goes well with it") ship off. Not built on purpose: a dearer replacement paid by the customer, a returns screen in the branch app (claims are handled on the dashboard), "assembly done" in the driver app, an assembly revenue column in the sales reports, card/Apple Pay/instalments (needs a payment provider). Arabic wording of the new screens still needs a native speaker's read.
- Cash drawer: **built** — collector → branch cashier hand-in with counted-vs-expected variance (note required, third-person review), bank deposit with reference, drawer balance, driver-phone balance banner. Custody (receive/bank) needs `payments.edit`.
- Auction lots: **built** — edit (rules depend on lot state), delete untouched lots, manual "Extend time".
- Promotion targeting: **whole basket, categories or specific products** (search or paste SKUs, up to 200; branch restriction combines with any). Not offered: excluding products from a promotion, or targeting by brand/attribute.
- Finance edge cases handled manually: a late-capture payment is resolved with a finance note (it cannot be refunded through the refund flow); a partial branch cancellation releases stock and raises a refund request for exactly that part (or lowers the cash due on delivery) automatically; drivers must attach a photo when proof of delivery is required.
- Product videos and related/upsell products: not implemented in admin or app.
- Delivery time slots: **built** — `GET /branches/{id}/delivery-slots`, a slot is required at checkout whenever the branch publishes delivery slots (single-branch orders), capacity is held at placement and released on cancellation, staff reschedule onto free slots, and the Flutter app has the picker. Slot lists also come as a date span in one request (`?from=&to=[&only_available=1]`, delivery and pickup), which the app uses for its date strip.
- `fulfillment_items` from the database spec are represented by `order_items.order_fulfillment_id`; auction lots link to products through `auction_products`.
- Maps in the app use OpenStreetMap tiles (`MAP_TILE_URL`); switch to a commercial tile provider or Google Maps before launch.
- Brand mark and app icons are typographic placeholders pending final artwork; crash reporting is not wired.
- Optional API additions the app already tolerates: `ProductDetail.fulfillment`, `delivery.tracking_url`, home `see_all`, promotion `link`.

## Where things are

See the documentation map in the root [`README.md`](../README.md).
