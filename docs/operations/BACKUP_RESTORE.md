# Backup & restore

## Objectives

| | Target | How |
|---|---|---|
| **RPO** (max data loss) | ≤ 5 minutes | Managed PostgreSQL PITR with continuous WAL archiving |
| **RTO** (max downtime) | ≤ 1 hour | Restore to new instance from PITR, repoint `DB_HOST`, `ops/deploy.sh <last-good-tag>` |
| Logical backups | daily + before every deploy, retained 35 days (monthly kept 12 months) | `ops/backup.sh` → GPG-encrypted → off-site bucket |
| Media | versioned object-storage bucket with 30-day noncurrent retention | bucket versioning + cross-region replication |

Redis holds no system-of-record data (cache, queues, locks, sessions). Losing it logs users out of the admin and drops
queued notifications that have not yet been sent; orders, payments, stock and bids are unaffected.

## Logical backup (`ops/backup.sh`)

1. `pg_dump -Fc` of the application database.
2. **Restore verification**: the dump is restored into a scratch `restore_check` database and the table count is checked
   (> 100). A dump that does not restore fails the job (and blocks the deploy).
3. If `BACKUP_BUCKET` is set: encrypted with `BACKUP_GPG_RECIPIENT`, uploaded to `s3://…/postgres/`, local copies shredded.

Schedule it daily (e.g. host cron `15 1 * * * /srv/albahar/ops/backup.sh daily`). Alert on non-zero exit.

## Restore procedures

**Point-in-time (preferred, corruption or bad migration):**
1. Put the app in maintenance: `docker compose exec app php artisan down --secret=<token>`.
2. In the managed DB console, restore to a new instance at the timestamp just before the incident.
3. Update `DB_HOST` in the secret store and redeploy the last good tag: `./ops/deploy.sh <tag>`.
4. `php artisan up`, then run `php artisan payments:reconcile-pending` to resolve SADAD callbacks received meanwhile.

**From a logical dump:**
```bash
aws s3 cp s3://<bucket>/postgres/<file>.dump.gpg .
gpg --decrypt <file>.dump.gpg > restore.dump
docker compose exec -T postgres sh -c 'createdb -U "$POSTGRES_USER" albahar_restored'
docker compose exec -T postgres sh -c 'pg_restore -U "$POSTGRES_USER" -d albahar_restored --no-owner' < restore.dump
```
Validate (counts of orders, payments, stock movements vs. the last reports), then switch `DB_DATABASE`.

## Post-restore checklist

- Reconcile payments: run the **SADAD reconciliation** report against the SADAD settlement report for the gap window.
- Re-run `inventory:expire-reservations` and `orders:expire-unpaid`.
- Check auctions: lots that should have closed during the outage are closed by the next `auctions:tick` using server time;
  review the auction audit trail before announcing winners.
- Record the incident and the restore in the audit log / incident register.

## Drills

Quarterly: restore last night's dump into staging, run the Feature test suite smoke subset and the reports, record the
measured RTO in the operations log. Every backup is already restore-tested automatically by `ops/backup.sh`.
