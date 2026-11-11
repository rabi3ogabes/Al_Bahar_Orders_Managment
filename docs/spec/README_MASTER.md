# Home & Living Multi-Branch E-Commerce Platform
## Master AI Development Instructions

You are the senior software architect and implementation agent for a production-grade, single-company, multi-branch home and living e-commerce platform operating in Qatar.

Read this file and all phase files before implementation. Implement phases in order. Do not skip architecture, database integrity, authorization, testing, or documentation.

## 1. Confirmed business decisions

- Business model: one company, multiple branches; this is NOT a marketplace and has no third-party sellers.
- Product catalog: centralized catalog with optional branch-specific product availability and pricing overrides.
- Inventory: independent stock per branch. No shared stock between branches.
- Auctions: scheduled auctions and live auctions with real-time bidding.
- Fulfillment: customer delivery and store pickup.
- Delivery: internal delivery employees and third-party delivery providers.
- Payments: SADAD online payment, cash on delivery (COD), and pay at pickup.
- Platforms: Flutter customer applications for Android and iOS. Build the customer app architecture so Flutter Web can be enabled later if required.
- Admin dashboard: Laravel web application. Do not use React or Next.js for the admin dashboard.
- Languages: Arabic and English, with complete RTL/LTR support.
- Currency: QAR.
- Primary operating timezone: Asia/Qatar.
- Country: Qatar.

## 2. Mandatory technology decisions

| Component | Technology |
|---|---|
| Customer apps | Flutter (Android and iOS) |
| Admin dashboard | Laravel Blade + Livewire, or Laravel Inertia only if approved; default to Blade + Livewire |
| Backend/API | Laravel, version supported by deployment environment |
| Database | PostgreSQL |
| Cache / queue / distributed locks | Redis |
| Real-time auction events | Laravel Reverb / WebSockets |
| API contract | REST JSON, versioned `/api/v1` |
| API documentation | OpenAPI / Swagger |
| File storage | S3-compatible object storage |
| Push notifications | Firebase Cloud Messaging |
| Maps | Google Maps Platform |
| Payments | SADAD merchant integration, subject to merchant API capabilities |
| Automated tests | Pest or PHPUnit; Flutter tests |
| Deployment | Docker, Nginx, CI/CD |

The Laravel application should serve both the admin dashboard and the backend API. Keep the code modular. Start with a modular monolith; do not create microservices unless a later measured scaling requirement justifies them.

## 3. Architecture principles

1. One central PostgreSQL database for the company and all branches.
2. Every branch-scoped operation must be authorized on the server.
3. A product is a central catalog entity; branch settings override availability and/or price.
4. Inventory is stored per branch and product variant.
5. Every stock movement must be traceable and auditable.
6. Orders preserve product, variant, price, discount, branch, and address snapshots.
7. Parent orders may have multiple branch fulfillment records.
8. Order status and payment status are separate state machines.
9. Payment callbacks/webhooks must be authenticated, idempotent, and verified server-side.
10. Auction bids must be validated and committed atomically on the server.
11. All dates are stored in UTC and presented in `Asia/Qatar`.
12. All customer-facing content supports Arabic and English.
13. Use QAR and decimal-safe monetary types (`NUMERIC`, never floating point).
14. Do not store raw card numbers or CVV.
15. Never trust prices, totals, stock, auction end times, permissions, or payment success sent by the client.

## 4. Product scope

### Customer experience
- Registration/login with Qatar mobile numbers and OTP.
- Customer profile and saved addresses.
- Arabic/English language selection.
- Home page with admin-managed sections and banners.
- Categories, subcategories, brands, filters, search, sorting.
- Product details, images, videos, variants, specifications, warranty.
- Branch stock and price visibility.
- Cart, coupons, checkout and order history.
- Delivery or pickup selection.
- SADAD, COD and pay-at-pickup.
- Order tracking and notifications.
- Wishlist, reviews, product questions, returns and support.
- Auctions, bidding, watchlists, winner payment and fulfillment.

### Admin dashboard (Laravel)
- Dashboard analytics.
- Branch and employee management.
- Roles and permissions.
- Product catalog, categories, brands, attributes and variants.
- Branch-specific pricing and availability.
- Inventory, transfers, stocktaking, suppliers and purchasing.
- Orders, fulfillment, pickup and delivery.
- SADAD transactions, COD collections, refunds and reconciliation.
- Scheduled and live auctions.
- Customers, reviews, support and marketing.
- Coupons, promotions, loyalty and referrals.
- CMS, banners, homepage builder and translations.
- Reports, exports, audit logs and system settings.

### Branch and delivery operations
- Branch manager and employee access restricted to assigned branches.
- Independent branch inventory.
- Order preparation and pickup QR verification.
- Internal delivery assignment and proof of delivery.
- Third-party delivery adapter interface for provider APIs and webhooks.

## 5. Mandatory development workflow

For each phase:
1. Read the phase file completely.
2. Inspect existing repository and report its current state.
3. Confirm dependencies and versions.
4. Implement the phase without breaking completed phases.
5. Create/update database migrations, models, policies, services, API resources, UI, and tests as applicable.
6. Run formatting, static analysis, automated tests, and relevant integration tests.
7. Fix failures; do not hide or skip failing tests.
8. Update API and technical documentation.
9. Provide a concise completion report listing files changed, migrations, tests run, known limitations, and next phase readiness.

Do not generate only mockups or static screens. Implement working business logic and persistence. Do not use hard-coded demo data in production paths. Seed demo data only through explicit development seeders.

## 6. Code quality requirements

- Use Laravel Form Requests for validation.
- Use Policies/Gates and middleware for authorization.
- Use Services/Actions for business workflows.
- Use database transactions for checkout, stock reservation, payment state transitions, transfers, refunds, and bids.
- Use events and queued jobs for notifications and long-running tasks.
- Use API Resources for stable JSON contracts.
- Use enums or constrained state values for statuses.
- Use foreign keys, unique constraints, check constraints, and indexes.
- Use soft deletes only where business history permits; never soft-delete financial ledger history.
- Add audit logging for sensitive operations.
- Add idempotency keys for checkout, payment initiation, webhooks, and bid submission.
- Avoid N+1 queries and add pagination to list endpoints.
- Store files in object storage, not PostgreSQL blobs.
- Never put secrets in source control.
- Do not expose stack traces or internal exception details to customers.

## 7. Branch security model

Branch-scoped roles must have explicit branch assignments. Every branch-scoped query and mutation must be scoped using the authenticated user's authorized branch IDs. A client-supplied `branch_id` is not authorization.

Test that a user assigned to Branch A cannot read, update, export, or infer restricted records belonging to Branch B by changing IDs, query parameters, URLs, or API payloads.

Super Admin may access all branches. Other roles receive only the permissions and branch scope explicitly assigned to them.

## 8. Deliverables expected at project completion

- Flutter Android and iOS customer applications.
- Laravel admin dashboard.
- Laravel REST API.
- PostgreSQL schema and migrations.
- Seeders for development/demo data.
- OpenAPI documentation.
- SADAD integration or documented provider capability blockers.
- Internal and third-party delivery integration framework.
- Scheduled and live auction engine.
- Automated backend and Flutter tests.
- Docker and deployment configuration.
- CI/CD workflow.
- Backup and restore documentation.
- Admin and employee user guides.
- Production handover and known-issues report.

## 9. Phase order

1. `PHASE_01_FOUNDATION_AND_ACCESS.md`
2. `PHASE_02_BRANCHES_AND_ORGANIZATION.md`
3. `PHASE_03_CATALOG_AND_PRICING.md`
4. `PHASE_04_INVENTORY_AND_PURCHASING.md`
5. `PHASE_05_FLUTTER_CUSTOMER_APP.md`
6. `PHASE_06_CART_CHECKOUT_ORDERS.md`
7. `PHASE_07_PAYMENTS_AND_REFUNDS.md`
8. `PHASE_08_PICKUP_AND_DELIVERY.md`
9. `PHASE_09_AUCTIONS.md`
10. `PHASE_10_MARKETING_CMS_NOTIFICATIONS.md`
11. `PHASE_11_REPORTING_SECURITY_QA_DEPLOYMENT.md`

Do not start a later phase until the current phase acceptance criteria pass, unless the project owner explicitly authorizes parallel work.
