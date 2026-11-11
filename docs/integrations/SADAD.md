# SADAD integration

## Implemented flow (hosted Web Checkout)
1. Checkout creates a `payments` row (`pending`, expires with the 30-minute payment window) and returns a
   **signed, short-lived redirect URL** (`/payments/sadad/{payment}/start`).
2. The app opens it in a secure in-app browser; the page auto-posts a server-built form (merchant id, order id = payment
   UUID, amount, callback URL, language, product detail, `checksumhash`) to the configured `SADAD_CHECKOUT_URL`.
3. SADAD posts the result server-to-server to `POST /webhooks/sadad`. We verify the checksum, store the event once
   (`payment_webhook_events.provider + provider_event_id` unique), lock the payment row and apply the state machine.
   If `SADAD_STATUS_URL` is configured, a success is **re-confirmed server-to-server** before marking paid.
4. The browser return page never changes state; the app polls `GET /api/v1/payments/{id}`.
5. `payments:reconcile-pending` (every 5 min) and `orders:expire-unpaid` (every minute) resolve missed callbacks and
   release stock for abandoned payments.

Covered by tests: success, failure, pending, duplicate & out-of-order callbacks, forged checksum, amount mismatch
(held as `processing` for finance), late capture after expiry (flagged, never resurrects), retry without duplicate
charge, expiry with stock release.

## Capability matrix (configure in `config/platform.php → payments.sadad.capabilities`)

| Capability | Default | Behaviour when off |
|---|---|---|
| hosted_checkout | on | — |
| status_api | on only if `SADAD_STATUS_URL` set | rely on verified callbacks + reconciliation |
| refund_api | **off** | refunds are processed manually (bank transfer) with dual approval and recorded reference |
| void | off | unpaid attempts are cancelled locally |
| authorize_capture | off | not used |

## ⚠ Items that MUST be confirmed with SADAD before go-live (documented blockers)
The public merchant kit was used as the reference; we did **not** invent undocumented capabilities. Confirm with the
merchant onboarding pack and update `SadadGateway` / `SadadChecksum` if any differ:
1. Exact sandbox and production checkout URLs and field names (`ORDER_ID`, `TXN_AMOUNT`, `CALLBACK_URL`, `productdetail`…).
2. Checksum algorithm and key derivation (implemented: AES-128-CBC over `sha256(payload|salt)+salt`, key = urlencoded secret + merchant id).
3. Callback field names and success code (`RESPCODE = 1`), and whether callbacks are retried.
4. Availability, URL and authentication of a transaction **status API**.
5. Refund API availability under your merchant agreement (keep `SADAD_REFUND_SUPPORTED=false` until confirmed).
6. Whether an embedded/native SDK flow is approved for the app (current design uses the hosted page, which is always allowed).
7. Settlement report format for automated reconciliation (current report `sadad_reconciliation` flags missing callbacks,
   amount mismatches and late captures).

## Configuration
`SADAD_ENABLED`, `SADAD_MODE`, `SADAD_MERCHANT_ID`, `SADAD_SECRET_KEY` (secret store only), `SADAD_WEBSITE`,
`SADAD_CHECKOUT_URL`, `SADAD_STATUS_URL`, `SADAD_REFUND_SUPPORTED`. Never log or commit these values.
