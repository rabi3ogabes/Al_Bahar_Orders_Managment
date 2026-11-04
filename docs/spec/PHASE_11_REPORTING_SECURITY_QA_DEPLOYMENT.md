# Phase 11 — Reports, Security Hardening, QA, Performance and Production Deployment

## Objective
Complete the platform for production with reports, export controls, security review, performance testing, backup/recovery and operational documentation.

## Reporting
Provide date range and authorized branch filters.

### Sales
- Gross sales.
- Net sales.
- Discounts.
- Refunds.
- Sales by branch.
- Sales by product/category/brand.
- Sales by payment method.
- Sales by delivery/pickup.
- Average order value.
- Order count and status breakdown.

### Inventory
- On-hand/reserved/available.
- Inventory valuation.
- Low stock/out of stock.
- Stock movement.
- Transfers.
- Damaged/lost stock.
- Fast/slow moving products.

### Customers
- New and returning customers.
- Repeat purchase.
- Average order value.
- Customer lifetime value where data supports it.
- Customer groups.
- Customer engagement.

### Delivery and pickup
- Deliveries by status.
- Failed attempts.
- Provider performance.
- Internal employee performance.
- COD collection.
- Pickup completion and wait time.

### Auctions
- Auction count.
- Bids per auction.
- Winning bid.
- Reserve met/not met.
- Unpaid winners.
- Deposit/refund status.
- Auction conversion.

### Finance
- SADAD reconciliation.
- COD collections.
- Pay-at-pickup collections.
- Refunds.
- Outstanding amounts.
Reports must respect user permissions and branch scope. Exports must be audited.

## Security hardening
- Review authentication and session management.
- Review authorization on every API route.
- Verify branch isolation.
- Validate file uploads.
- Rate-limit sensitive endpoints.
- Review CORS and CSRF configuration.
- Verify webhook signatures.
- Review secret storage.
- Check dependencies for known vulnerabilities.
- Prevent sensitive information in logs.
- Add admin 2FA.
- Conduct penetration testing before launch.

## Performance
- Query profiling and indexes.
- Avoid N+1 queries.
- Pagination on all large lists.
- Cache safe read-heavy content.
- Queue notifications, imports, exports and reports.
- Load-test checkout and stock reservations.
- Load-test live bidding separately.
- Configure database connection pooling.
- Monitor queue depth and failed jobs.

## Backup and recovery
- Automated database backups.
- Point-in-time recovery where supported.
- Offsite backup storage.
- Encryption at rest and in transit.
- Backup retention policy.
- Regular restore drills.
- Define and document RPO and RTO.
- Back up object storage or enable versioning.
- Never consider a backup successful until restoration has been tested.

## Monitoring
- Application errors.
- API latency.
- Database health.
- Queue failures and backlog.
- WebSocket health.
- Payment callback failures.
- Failed delivery provider requests.
- Auction event lag.
- Disk and memory.
- SSL expiry.
- Backup job status.

## CI/CD
- Run backend tests.
- Run Flutter tests and builds.
- Run static analysis and formatting.
- Run dependency checks.
- Deploy to staging.
- Require approval for production.
- Run migrations safely.
- Provide rollback plan.
- Keep secrets in CI secret storage.

## Environments
- Development.
- Staging.
- Production.
Use separate databases, credentials, payment credentials and notification credentials.

## Final QA matrix
Test:
- Customer registration and OTP.
- Arabic/English and RTL/LTR.
- Branch discovery and isolation.
- Product and branch price precedence.
- Variant inventory.
- Concurrent checkout and reservation.
- Split fulfillment.
- SADAD success/failure/pending/duplicate callbacks.
- COD and cash reconciliation.
- Pickup QR expiry and replay.
- Internal delivery.
- Third-party delivery adapter.
- Scheduled and live auctions.
- Concurrent bids and anti-sniping.
- Refunds and returns.
- Coupons and loyalty.
- Notifications and opt-out.
- Reports and export permissions.
- Account deletion and privacy workflows.

## Launch readiness checklist
- All critical acceptance tests pass.
- No known critical/high security vulnerabilities.
- Payment provider sandbox tests completed.
- Merchant production credentials configured securely.
- Delivery provider credentials/configuration approved.
- App store assets and privacy disclosures ready.
- Terms, privacy, returns and auction rules reviewed.
- Backups and restore drill completed.
- Monitoring and alerts active.
- Admin and employee training completed.
- Support escalation process documented.
- Production rollback plan approved.

## Final handover
Provide:
- Source repositories.
- Database schema and migrations.
- API OpenAPI specification.
- Environment setup guide.
- Deployment guide.
- Backup/restore guide.
- Admin manual.
- Branch/employee manual.
- Flutter build/release guide.
- Integration credentials handover process (never include secrets in documentation).
- Test results.
- Known limitations.
- Production launch approval checklist.

## Acceptance criteria
The system is production-ready only when functional, security, performance, backup restoration and operational acceptance criteria have been demonstrated. A successful build alone is not sufficient.
