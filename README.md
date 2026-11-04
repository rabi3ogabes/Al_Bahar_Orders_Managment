# Al Bahar Home & Living — Commerce Platform

Single-company, multi-branch home & furniture commerce for Qatar: a **Laravel 13 REST API** for the Flutter customer
app, a **Blade + Livewire admin dashboard** for staff, real-time **auctions**, **SADAD** online payments, COD and
pay-at-pickup, branch pickup and delivery (internal drivers + courier adapters), marketing, CMS, notifications and
reporting. Arabic & English throughout (real RTL), QAR currency, `Asia/Qatar` business time.

| Part | Path | Stack |
|---|---|---|
| API + admin | `backend/` | PHP 8.3, Laravel 13, Livewire 4, Sanctum, Reverb, PostgreSQL 16, Redis 7, Pest |
| Customer app | `mobile/` | Flutter 3 (Riverpod, go_router, dio), Android & iOS |
| Runtime & CI/CD | `docker/`, `docker-compose.yml`, `ops/`, `.github/workflows/` | Docker, Nginx, GitHub Actions |
| Documentation | `docs/` | spec, architecture, API, database, integrations, operations, guides |

## Quick start (local)

Requirements: PHP 8.3 with `pdo_pgsql`, `redis`, `gd`, `intl`; Composer 2; Node 20+; PostgreSQL 16 (with `pg_trgm`);
Redis 7. Or use Docker (below).

```bash
cd backend
cp .env.example .env          # then set DB_*, REDIS_*, APP_URL
composer install
php artisan key:generate
npm ci && npm run build
php artisan migrate --seed    # roles & permissions + reference data
php artisan db:seed --class=DemoSeeder   # optional: demo branches, staff, catalog, stock (non-production only)
php artisan platform:create-admin        # interactive super-admin creation
php artisan serve
```

In separate terminals: `php artisan queue:work --queue=default,notifications,exports`, `php artisan schedule:work`,
`php artisan reverb:start`.

- Admin: `http://localhost:8000/admin` (staff login, TOTP 2FA)
- API: `http://localhost:8000/api/v1` — health at `/api/v1/health`
- OpenAPI viewer (staff with `viewApiDocs`): `/docs/api`; exported spec: `docs/api/openapi.json`

### Docker

```bash
cp backend/.env.example backend/.env    # set DB_PASSWORD, APP_KEY, REVERB_* …
DB_PASSWORD=change-me docker compose up -d --build
docker compose run --rm app php artisan migrate --seed --force
```

Nginx listens on `:8080`. See [`docs/operations/DEPLOYMENT.md`](docs/operations/DEPLOYMENT.md) for production.

## Tests & quality

```bash
cd backend
vendor/bin/pest --testsuite=Unit,Feature       # transactional suites
vendor/bin/pest --testsuite=Concurrency        # real multi-process races (needs pcntl, non-transactional)
vendor/bin/pint --test                          # code style
```

Tests run against PostgreSQL database `albahar_test` (see `phpunit.xml`); SQLite is not supported because the schema
relies on PostgreSQL constraints, triggers and trigram search. The Flutter app: `cd mobile && flutter analyze && flutter test`.

## Documentation map

| Topic | Document |
|---|---|
| Original specification (phases 01–11) | [`docs/spec/`](docs/spec/README_MASTER.md) |
| Architecture, modules, state machines, security model | [`docs/architecture/ARCHITECTURE.md`](docs/architecture/ARCHITECTURE.md) |
| REST API contract & OpenAPI | [`docs/api/API_CONTRACT.md`](docs/api/API_CONTRACT.md), [`docs/api/openapi.json`](docs/api/openapi.json) |
| Data dictionary & ER diagram | [`docs/database/`](docs/database/DATA_DICTIONARY.md) |
| SADAD | [`docs/integrations/SADAD.md`](docs/integrations/SADAD.md) |
| Couriers & notification channels | [`docs/integrations/DELIVERY_AND_NOTIFICATIONS.md`](docs/integrations/DELIVERY_AND_NOTIFICATIONS.md) |
| Deployment, backup/restore, monitoring, security, launch | [`docs/operations/`](docs/operations/DEPLOYMENT.md) |
| Admin and branch-staff guides (EN/AR) | [`docs/guides/`](docs/guides/ADMIN_GUIDE.md) |
| Completion report & known limitations | [`docs/HANDOVER.md`](docs/HANDOVER.md) |

## Ground rules for contributors

- Business rules live in `backend/app/Domain/<Module>` services; controllers and Livewire components stay thin.
- Never trust client prices, stock, discounts or payment state — the server re-quotes everything.
- Money is `NUMERIC(12,2)` in the database and `App\Support\Money` (integer minor units) in code — never floats.
- Stock is written only through `InventoryLedger`; every mutation is audited.
- Every business rule ships with a test. Secrets live in the environment / secret store only — never in the repository.
