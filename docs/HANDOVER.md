# Handover & completion report

Al Bahar Home & Living platform. Scope: the eleven-phase specification in `docs/spec/`.
This report lists, phase by phase, what was delivered, how it is verified, and what remains open.

## Summary

| Area | Delivered | Verified by |
|---|---|---|
| Backend API (`backend/`) | Laravel 13 modular monolith, 132 PostgreSQL tables, REST `/api/v1` with 143 documented paths (`docs/api/openapi.json`) | Pest: **298** Unit + Feature tests (1,461 assertions) and the Concurrency suite (real multi-process row locks), all passing; migrations up/down/up |
| Admin dashboard | 50+ Blade + Livewire screens covering phases 1–10, EN/AR with RTL, light/dark, phone-friendly; driver view at `/admin/driver`; camera pickup scanner; live auctioneer console | Feature tests per screen (authorised 200 / unauthorised 403, branch isolation, key workflows); screenshot review EN/AR at 1440 px and 390 px |
| Customer app (`mobile/`) | Flutter Android/iOS, 15 feature modules, EN/AR RTL, push, live auctions | `flutter analyze` (no issues), 116 unit/widget/contract tests, headless integration smoke test |
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
- Gift cards: not implemented (not required by the phase acceptance criteria).
- Admin gaps without a domain service yet: cash drawer hand-in/settlement (the Cash screen shows totals not handed in), manual lot extension (anti-sniping extends automatically), editing/deleting a lot after it is added, product-level promotion targeting in the editor (order and category scope are supported).
- Finance edge cases handled manually: a late-capture payment is resolved with a finance note (it cannot be refunded through the refund flow); a partial branch cancellation releases stock but the refund is raised separately; drivers must attach a photo when proof of delivery is required.
- Product videos and related/upsell products: not implemented in admin or app.
- Delivery time slots: pickup slots exist; delivery uses date selection only (no delivery-slot endpoint).
- `fulfillment_items` from the database spec are represented by `order_items.order_fulfillment_id`; auction lots link to products through `auction_products`.
- Maps in the app use OpenStreetMap tiles (`MAP_TILE_URL`); switch to a commercial tile provider or Google Maps before launch.
- Brand mark and app icons are typographic placeholders pending final artwork; crash reporting is not wired.
- Optional API additions the app already tolerates: `ProductDetail.fulfillment`, `delivery.tracking_url`, home `see_all`, promotion `link`.

## Where things are

See the documentation map in the root [`README.md`](../README.md).
