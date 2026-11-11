# Phase 01 — Foundation, Laravel Architecture, Database and Access Control

## Objective
Create the technical foundation for the platform. Establish the Laravel application, PostgreSQL database, Redis, API conventions, authentication, authorization, localization foundations, audit logging, and automated testing.

## Technology
- Laravel backend and admin application.
- Laravel Blade + Livewire for the admin dashboard.
- PostgreSQL.
- Redis for cache, queues and locks.
- REST API at `/api/v1`.
- Flutter app repository may be initialized, but customer features are implemented in Phase 05.
- Docker for local development.

## Tasks

### 1. Repository and environment
- Create a maintainable repository structure.
- Configure local development with Docker Compose: Laravel/PHP, Nginx, PostgreSQL, Redis, queue worker and scheduler.
- Add `.env.example`; never commit secrets.
- Create separate development, staging and production configuration.
- Configure health-check endpoint.
- Add CI pipeline for formatting, static analysis and tests.
- Pin compatible dependency versions and document them.

### 2. Laravel modular structure
Create clear modules/domains:
- Identity and Access
- Branches
- Catalog
- Pricing
- Inventory
- Cart and Checkout
- Orders
- Payments
- Fulfillment
- Auctions
- Marketing
- Notifications
- CMS
- Reports
- Support
- Audit and Settings

Start as a modular monolith. Keep controllers thin. Put business rules in application services/actions and domain services.

### 3. Database conventions
- PostgreSQL.
- UUID primary keys for major entities.
- `NUMERIC(12,2)` for money.
- Explicit currency code (`QAR`).
- UTC timestamps.
- Foreign keys and appropriate unique/check constraints.
- `created_at`, `updated_at`; soft deletes only where appropriate.
- Add indexes based on actual query patterns.
- Use migrations for every schema change.
- Use seeders for development roles and permissions.
- Do not use floating-point columns for money.

### 4. Authentication
Implement:
- Customer registration and login.
- Qatar mobile number validation (+974), while allowing configurable international formats if the company later needs them.
- OTP issuance, verification, expiration and attempt limits.
- Password login.
- Password reset.
- Logout and session/token revocation.
- Refresh-token/session lifecycle.
- Device registration for push notifications.
- Admin/employee authentication separated by guard or clearly separated access policy.
- Secure password hashing.
- Rate limits for OTP, login and password reset.

OTP codes must be hashed at rest, short-lived, single-use, and protected against brute force. Do not log OTP values.

### 5. Authorization and roles
Implement RBAC:
- Super Admin
- Admin
- Branch Manager
- Inventory Manager
- Order Manager
- Sales Employee
- Pickup Employee
- Delivery Employee
- Auction Manager
- Marketing Manager
- Accountant
- Customer Support
- Auditor / read-only

Permissions must be action-based:
- view
- create
- edit
- delete
- approve
- export
- assign
- refund
- override

Support user assignment to one or multiple branches. Super Admin can access all branches. Other staff are restricted to assigned branches and permissions.

### 6. Localization foundations
- Admin interface Arabic and English.
- RTL/LTR switching.
- Locale middleware for APIs.
- Store language preference.
- Use translation files and translation tables where content is database-managed.
- Store dates UTC; render Qatar time.
- Currency formatting for QAR.

### 7. Audit logging
Record security-relevant actions:
- Login and failed login.
- Employee and permission changes.
- Product/price changes.
- Stock changes.
- Order status changes.
- Payment/refund actions.
- Auction administration and bid intervention.
- Settings and integration changes.
Include actor, action, entity type/id, timestamp, IP/device context where appropriate, and safe before/after metadata. Never store secrets or sensitive payment credentials in audit logs.

### 8. Initial Laravel admin shell
Build a working Laravel dashboard shell:
- Authentication.
- Sidebar/navigation.
- Dashboard layout.
- User profile.
- Language switcher.
- Role-aware menu visibility.
- Responsive layout.
- Arabic RTL and English LTR.
- Unauthorized/forbidden screens.
This is the Laravel dashboard, not a React/Next.js dashboard.

### 9. API standards
- Prefix `/api/v1`.
- JSON request/response format.
- Consistent validation errors.
- Pagination, filtering and sorting conventions.
- API Resources.
- Request correlation IDs.
- Rate limiting.
- OpenAPI documentation starter.
- No stack traces in production responses.

## Initial database tables
- users
- user_sessions or token tables
- otp_verifications
- user_devices
- roles
- permissions
- role_permissions
- user_roles
- audit_logs
- system_settings

## Required tests
- OTP success, expiry, reuse and attempt limits.
- Login and password reset.
- Role/permission checks.
- Branch scope authorization foundation.
- Arabic/English locale selection.
- API validation and error format.
- Audit log creation for protected actions.

## Acceptance criteria
- Laravel application runs in Docker.
- PostgreSQL and Redis connections work.
- Laravel admin dashboard login works.
- API health check works.
- Roles and permissions are seeded and editable.
- Unauthorized users cannot access admin routes.
- OTP lifecycle is secure and tested.
- Arabic and English admin shell works with RTL/LTR.
- CI runs tests successfully.
- README explains setup and commands.

## Do not
- Do not implement product/order business workflows in this phase.
- Do not hard-code user roles in frontend-only logic.
- Do not trust branch IDs supplied by a client.
- Do not store plaintext OTPs or passwords.
