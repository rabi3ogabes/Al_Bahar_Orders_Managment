# Launch checklist

## Blockers that need a decision or a third party

- [ ] **SADAD**: merchant onboarding pack received; checkout/status URLs, field names, checksum and refund support
      confirmed (`docs/integrations/SADAD.md` §"must be confirmed"); sandbox end-to-end payment + failure + refund drill.
- [ ] **Courier**: choose provider(s), implement the adapter (`DeliveryProviderAdapter`), map statuses, configure webhook.
      Until then delivery runs with internal drivers only.
- [ ] **SMS gateway** for OTP (Qatar sender ID registration) — `SMS_DRIVER=http`.
- [ ] **FCM** project + service account; **APNs** key uploaded to Firebase; WhatsApp Business templates approved.
- [ ] App Store / Play Console accounts, privacy policy & terms pages published in CMS (AR/EN), data-safety forms.
- [ ] Legal review of auction terms (deposit, unpaid-winner policy) and returns policy.

## Infrastructure

- [ ] Production secrets in the secret store; `APP_DEBUG=false`, `OTP_DEV_ECHO=false`, `SESSION_SECURE_COOKIE=true`.
- [ ] TLS certificates, HSTS, DNS for `api.`, `admin.`, `ws.` hosts; CORS limited to app origins.
- [ ] Managed PostgreSQL with PITR; `pg_trgm` enabled; daily `ops/backup.sh` scheduled and alerting; restore drill done.
- [ ] Exactly one scheduler; ≥ 2 queue workers; Reverb reachable over WSS.
- [ ] Monitoring & alerts from `MONITORING.md` wired; on-call rota defined.

## Data & configuration

- [ ] Roles & permissions seeded; super admins created with 2FA; staff accounts per branch with least privilege.
- [ ] Branches: addresses, coordinates, hours, holidays, pickup slots, **delivery slots** (required at checkout once published), delivery zones & fees, COD limits.
- [ ] Cash handling: branch managers/accountants hold `payments.edit`; drivers and counters only `payments.create`; bank-reference format agreed with finance.
- [ ] Catalog imported (CSV import with dry-run), images uploaded, prices per branch reviewed, stock counts posted via stocktake.
- [ ] Settings: tax/VAT position, COD fee, payment window, loyalty rules, referral rewards, auction defaults.
- [ ] CMS: homepage sections, banners, pages (about, privacy, terms, returns, auction rules) in AR & EN.
- [ ] Notification templates reviewed in AR & EN for every event × channel.

## Quality gates

- [ ] CI green: style, static analysis, audits, migrations up/down/up, Unit + Feature + Concurrency suites, Flutter tests.
- [ ] UAT sign-off per role: customer, cashier, branch manager, warehouse, driver, finance, marketing, auctioneer, super admin.
- [ ] Penetration-test checklist (`SECURITY.md`) completed, findings fixed.
- [ ] Load test: browse/search 200 rps, checkout 20 rps, 500 concurrent auction watchers, p95 < 800 ms.
- [ ] Accessibility & RTL review of the app and admin in Arabic.

## Go-live day

- [ ] Freeze; tag release; `ops/deploy.sh <tag>`; verify health, a real low-value SADAD payment and refund, a COD order,
      a pickup handover with QR, a driver delivery, push notification receipt.
- [ ] Monitor dashboards for 24 h; finance reconciles first settlement report.
