# Monitoring & alerting

## Signals

| Signal | Source | Alert when |
|---|---|---|
| Health | `GET /api/v1/health` → `{status, checks:{database,cache,redis}}` (503 when degraded) | non-200 for 2 consecutive minutes |
| HTTP errors | Nginx/app logs (every response carries `X-Request-Id`; JSON errors include `request_id`) | 5xx rate > 1% over 5 min |
| Latency | load balancer metrics | p95 > 800 ms on `/api/v1` for 10 min |
| Queues | `php artisan queue:monitor redis:default,redis:notifications,redis:exports --max=500` | backlog > 500 or failed jobs growing (`queue:failed`) |
| Scheduler | `php artisan schedule:list`; heartbeat from `orders:expire-unpaid` logs | no run for 5 minutes |
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

## Dashboards (admin)

The admin dashboard shows today's orders, revenue, pending payments, ready pickups, deliveries in progress, low stock
and live auctions per branch scope. Finance and operations reports (22 in total) are under **Reports**; large exports run
on the `exports` queue and are downloaded from **Exports** (signed, expiring links, audited).

## On-call runbook (short)

1. Check `/api/v1/health` and which check fails.
2. Database fail → managed DB status; Redis fail → app degrades (sessions/queues), restart Redis or fail over.
3. Payments stuck pending → `php artisan payments:reconcile-pending`; verify SADAD status page; never mark paid manually
   without the SADAD reference (finance dual control applies).
4. Auctions frozen → verify the scheduler container is running exactly once; `php artisan auctions:tick` manually.
5. Post-incident: write a summary with request IDs and audit-log references.
