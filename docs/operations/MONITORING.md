# Monitoring & alerting

## Signals

| Signal | Source | Alert when |
|---|---|---|
| Health | `GET /api/v1/health` → `{status, checks:{database,cache,redis}}` (503 when degraded) | non-200 for 2 consecutive minutes |
| HTTP errors | Nginx/app logs (every response carries `X-Request-Id`; JSON errors include `request_id`) | 5xx rate > 1% over 5 min |
| Latency | load balancer metrics | p95 > 800 ms on `/api/v1` for 10 min |
| Queues | **System health** card (jobs waiting per queue, failed jobs); `php artisan queue:monitor redis:default,redis:notifications,redis:exports --max=500` | backlog > 500 or failed jobs growing (`queue:failed`) |
| Scheduler | **System health** card on the admin dashboard (heartbeat per scheduled task, see below); `php artisan schedule:list` | any task **Overdue**, **Last run failed** or never seen; no run for 5 minutes |
| Reverb | process up + connection count | down, or auction pages report fallback polling |
| Payments | report **SADAD reconciliation** (missing callbacks, amount mismatches, late captures) | any `late_capture` or mismatch; pending > 30 min |
| Stock | **Low stock** report; DB constraint violations in logs | invariant violation (should be impossible — page on-call) |
| Notifications | `notification_logs` failure rate by channel | > 5% failures for a channel in 1 h |
| Security | audit log: failed staff logins, 2FA failures, refresh-token reuse (family revoked), permission denials | spikes vs. baseline |
| Database | managed DB metrics: connections, replication lag, storage, slow queries (> 500 ms) | storage > 80%, lag > 30 s |

## Logs

- Production: `LOG_CHANNEL=stderr` (JSON) collected by the platform; retention 30 days hot / 1 year cold.
- `App\Support\Redactor` removes secrets, tokens, OTPs, card-like numbers and checksums from logged context and stored
  provider payloads. Recipients in `notification_logs` are masked.
- Never raise `LOG_LEVEL` to `debug` in production for more than an incident window.

## Scheduler heartbeat and the System health card

Every scheduled task writes a heartbeat into `scheduler_heartbeats` (`task`, `last_started_at`, `last_finished_at`, `last_status`
= `running` | `ok` | `failed`, `last_duration_ms`, `every_minutes`, `run_count`). It is attached once, at the end of `routes/console.php`
(`SchedulerHeartbeat::attach`), to every scheduled artisan command, so a task added later is watched without extra code. The
write never fails a task. `auctions:tick` (every 5 seconds) writes at most every 30 seconds. Only times and an ok/failed flag are
stored, never output or error text (the cause is in the application log).

The **System health** card is on the admin dashboard for super admins and company-wide `settings.edit` holders (checked again on
every refresh, and read-only). It lists each task with its last run and a state:

| State | Meaning |
|---|---|
| On time | finished within its limit: 5 minutes for a per-minute task, 15 for a 5-minute task, 1.5 x the interval for daily tasks |
| Running now | started, not finished yet (and less than 10 minutes ago) |
| Overdue | no finished run inside the limit, or started and never finished (the process was killed) |
| Last run failed | the command exited with an error |
| Not seen yet | no heartbeat recorded: the scheduler is not running, or the task is new |

Below the tasks it shows the queue: jobs waiting in `default`, `notifications` and `exports` for the configured connection
(redis in production, database or inline elsewhere) and the `failed_jobs` count. **Backups are not on the card**: they run outside the
application (`ops/backup.sh`), so the app cannot know their age without guessing; monitor them where they run.

**Locks.** `withoutOverlapping()` keeps its lock for 24 hours by default, and a container killed during a deploy never releases it, so a
task would silently stop for a day. Every guarded task now names a short expiry (10 minutes, `journeys:tick` and
`messaging:dispatch-due` 5, `auctions:tick` 1), longer than the task ever runs. If a task shows Overdue after a deploy, wait that long
before suspecting anything else.

**Campaigns.** A campaign being sent shows its progress in **Marketing > Campaigns**. One that has had no progress for 30 minutes (worker
killed, job lost) is picked up again by `marketing:send-scheduled` and continues where it stopped; customers already messaged are never
messaged again.

## Dashboards (admin)

The admin dashboard shows, for the viewer's branches and only the sections their role may see:

- **Sales today**: orders placed today (Qatar time), not cancelled and not waiting for their online payment, at their booked value.
- **Collected today**: money that actually came in today: online payments captured plus cash and card collections recorded by staff.
- **Orders today**, to prepare, ready pickups, deliveries in progress, refunds to review, support tickets, live auctions.
- **Low stock** (an item with no minimum of its own counts as low at 3 or fewer left; setting `inventory.low_stock_default`) and
  out of stock (only items the branch has held before).
- the 14-day sales chart, built from the same definition as the Sales today tile, and the **Sales summary** report's *Booked sales*.

A split order is divided between its branches to the cent (the same weights and rounding as the cash split), so branch figures add
up to the order. Finance and operations reports (22 in total) are under **Reports**; large exports run on the `exports` queue and are
downloaded from **Exports** (signed, expiring links, audited). The audit log shows the last 30 days unless dates are picked and
counts matching entries only up to 1,000 (shown as "1,000+").

## On-call runbook (short)

1. Check `/api/v1/health` and which check fails.
2. Database fail → managed DB status; Redis fail → app degrades (sessions/queues), restart Redis or fail over.
3. Payments stuck pending → `php artisan payments:reconcile-pending`; verify SADAD status page; never mark paid manually
   without the SADAD reference (finance dual control applies).
4. Auctions frozen → verify the scheduler container is running exactly once; `php artisan auctions:tick` manually. The System health
   card shows which tasks have stopped and since when.
5. Post-incident: write a summary with request IDs and audit-log references.
