# Security

## Controls implemented

| Area | Control |
|---|---|
| Customer auth | Phone OTP: HMAC-SHA256-hashed codes, 5-min TTL, 5 attempts, 60 s resend cooldown, hourly cap per phone + per-IP throttle; codes never logged. Optional password login. Sanctum access tokens (60 min) + rotating refresh tokens stored as SHA-256; **reuse of a rotated refresh token revokes the whole family**. Device/session list with remote sign-out. Account deletion request flow. |
| Staff auth | Separate login channel; strong password policy; **TOTP 2FA** (enforceable per role, required for super admin & finance) with single-use recovery codes and replay protection; session regeneration; throttled login. |
| Authorization | RBAC `module.action` permissions + branch scope. Branch roles require an active branch assignment; client-supplied `branch_id` can only *narrow* scope; every mutation re-checks the record's branch. No self-elevation; staff can grant only permissions they hold, within their branches. |
| Input & data | Form Request validation, server-side re-quote of every price/discount/stock/fee (quote hash, 409 `quote_changed`), decimal-safe money, DB `CHECK`/FK/unique constraints, append-only triggers on ledgers & audit. |
| Payments | No card data touches our servers (SADAD hosted page). Checksum verification on callbacks, optional server-to-server status confirmation, idempotent event store, amount-mismatch hold, late-capture flag, refund dual approval capped at captured amount. |
| Webhooks | SADAD checksum; courier HMAC signature + timestamp; unique `(provider, event_id)`; dedicated throttle; CSRF excluded only for `/webhooks/*`. |
| Uploads | Image MIME/size validation, re-encoded to WebP (strips metadata), random names, private disk for exports. |
| Exports | Permission per report, rate limited, CSV formula-injection neutralised, audited, signed expiring download links. |
| Transport & headers | HSTS (TLS), `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Referrer-Policy`, `Permissions-Policy`; self-hosted fonts & assets (CSP-friendly); secure, http-only, same-site cookies. |
| Secrets | Environment/secret store only; secret settings encrypted at rest and masked in the admin; `Redactor` on logs and stored provider payloads; `.env` git-ignored. |
| Audit | `AuditLogger` for auth, staff/roles, prices, stock, orders, payments, refunds, auctions, settings, exports — immutable (DB trigger), redacted, with actor, IP, user agent and request ID. |
| Mobile | Tokens in Keychain/Keystore (`flutter_secure_storage`), TLS only, no secrets in the app bundle, SADAD in a secure in-app browser. |

## Penetration-test checklist (run before go-live and yearly)

- [ ] Auth: OTP brute force / enumeration (responses identical for unknown phones), refresh-token replay, token expiry, logout revocation.
- [ ] Staff: 2FA bypass, recovery-code reuse, session fixation, password reset token reuse.
- [ ] IDOR: orders, addresses, payments, invoices, notifications, support tickets of another customer (expect 404).
- [ ] Branch isolation: staff of branch A reading/mutating branch B orders, stock, transfers, reports, exports (expect 403).
- [ ] Privilege escalation: assigning roles/permissions beyond own authority; editing own roles.
- [ ] Price tampering: client-sent prices, discounts, fees, quantities, stale quote, coupon replay & usage-limit races.
- [ ] Payment: forged/replayed SADAD callbacks, amount changes, callback for another payment, return-page state changes.
- [ ] Auctions: bid below increment, bid after close (server time), race conditions, proxy-max disclosure.
- [ ] Webhooks: missing/invalid signature, replay, oversized payloads.
- [ ] Injection: SQL (search, filters, sort params), XSS in CMS/reviews/product content (Blade escaping), CSV injection in exports.
- [ ] Uploads: polyglot images, SVG, path traversal, oversized files.
- [ ] Rate limits: OTP, login, checkout, bids, exports, webhooks.
- [ ] Headers/TLS: HSTS, cookies, CORS (only the app origins), no stack traces (`APP_DEBUG=false`).
- [ ] Dependency audit: `composer audit`, `npm audit`, `flutter pub outdated` — no high/critical.

## Admin 2FA policy

1. Every staff account enrols TOTP at first login when its role requires it (super admin, admin, finance, branch manager).
2. Recovery codes are shown once; regenerating invalidates the previous set (audited).
3. Lost device: another super admin resets 2FA after identity verification — the reset is audited and forces re-enrolment.

## Incident response

Contain (revoke tokens: staff → **Staff › Sessions**; customers → family revocation), rotate affected secrets in the
secret store, preserve audit logs and request IDs, notify stakeholders and, where personal data is affected, follow Qatar
PDPPL obligations. Record the post-mortem.
