# Deployment

## Topology (production)

| Component | Recommendation |
|---|---|
| App image `albahar/app:<tag>` | ≥ 2 replicas behind a TLS load balancer; same image runs `app` (PHP-FPM), `queue`, `scheduler`, `reverb` |
| Web | Nginx (`docker/nginx/default.conf`) in front of PHP-FPM; `/app/` proxied to Reverb as WebSocket (WSS at the load balancer) |
| PostgreSQL 16 | Managed service with PITR (WAL archiving), daily snapshots, `pg_trgm` extension enabled |
| Redis 7 | Managed, AOF on; used for cache, queues, locks, sessions |
| Object storage | S3-compatible bucket for media and exports (`FILESYSTEM_DISK=s3`) |
| Scheduler | **Exactly one** `scheduler` container (tasks also use `onOneServer()` as a second guard) |
| Queue workers | ≥ 2 `queue` containers: queues `default,notifications,exports` |

## Environment & secrets

Start from `backend/.env.example`. Production values come from the platform's secret store — never from a file in the
repository. Mandatory in production:

- `APP_ENV=production`, `APP_DEBUG=false`, `APP_KEY`, `APP_URL` (https)
- `DB_*`, `REDIS_*`, `CACHE_STORE=redis`, `QUEUE_CONNECTION=redis`, `SESSION_DRIVER=redis`, `SESSION_SECURE_COOKIE=true`
- `REVERB_APP_ID/KEY/SECRET`, `REVERB_HOST`, `REVERB_SCHEME=https`
- `OTP_DEV_ECHO=false` (the app refuses to boot in production if it is true), `SMS_DRIVER=http` + `SMS_HTTP_*`
- `SADAD_*` (see `docs/integrations/SADAD.md`), `FCM_*`, `WHATSAPP_*`, `MAIL_*`

`AppServiceProvider` refuses to boot with `APP_DEBUG=true` in production or `OTP_DEV_ECHO=true` outside local/testing;
the mock courier adapter refuses to run in production.

## Pipeline

1. **CI** (`.github/workflows/ci.yml`, every push/PR): Pint, Larastan, `composer audit`, `npm audit`, migrations
   up → down → up on a fresh PostgreSQL, Pest `Unit`, `Feature`, `Concurrency`; Flutter analyze + test; Docker build.
2. **Release** (`.github/workflows/deploy.yml`): every push to `main` deploys to **staging**; **production** is a manual
   dispatch gated by required reviewers on the `production` GitHub environment. The job builds and pushes the image
   tagged with the commit SHA, then runs `ops/deploy.sh <sha>` on the target host over SSH.
3. **`ops/deploy.sh <tag>`**: pull image → verified pre-deploy backup (`ops/backup.sh`) → `migrate --force` once →
   `up -d` → `optimize` → `queue:restart` → health check (`/api/v1/health` must return `"status":"ok"`) → records
   `.last-good-release`.

## Migrations policy

- Migrations must be backward compatible with the previous release (expand → deploy → contract in a later release).
- Destructive changes (drop/rename) ship only after the code that stops using the column has been live one release.
- Every migration has a working `down()`; CI proves up/down/up.

## Rollback

```bash
./ops/deploy.sh "$(cat .last-good-release)"
```

If the failed release ran a non-reversible migration, restore the pre-deploy backup first
(`docs/operations/BACKUP_RESTORE.md`). Payments and webhooks are idempotent, so SADAD/courier callbacks received during the
rollback window are safely re-applied by `payments:reconcile-pending`.

## Scheduled tasks (run by the `scheduler` container)

| Command | Frequency | Purpose |
|---|---|---|
| `orders:expire-unpaid` | every minute | expire SADAD payments past the 30-min window, cancel order, release stock |
| `inventory:expire-reservations` | every minute | release expired stock reservations exactly once |
| `payments:reconcile-pending` | every 5 min | re-check pending payments via status API, flag missed callbacks |
| `auctions:tick` | every 5 s | open/close lots on server time, settle winners, apply unpaid-winner policy |
| `marketing:send-scheduled` | every minute | dispatch scheduled campaigns |
| `marketing:abandoned-carts` | every 15 min | consented abandoned-cart reminders |
| `loyalty:expire-points` | daily 02:15 Qatar | expire loyalty points FIFO |

## First production install

```bash
docker compose run --rm app php artisan migrate --force
docker compose run --rm app php artisan db:seed --class=RolesAndPermissionsSeeder --force
docker compose run --rm app php artisan db:seed --class=ReferenceDataSeeder --force
docker compose run --rm app php artisan platform:create-admin
```

Never run `DemoSeeder` in production (it refuses to). Then create branches, hours, zones and staff from the admin.
