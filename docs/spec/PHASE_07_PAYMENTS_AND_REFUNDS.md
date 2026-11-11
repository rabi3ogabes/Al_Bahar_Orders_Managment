# Phase 07 — SADAD, COD, Pay at Pickup, Transactions and Refunds

## Objective
Implement a payment abstraction layer supporting SADAD, cash on delivery and pay at pickup. Confirm actual SADAD capabilities from the merchant integration documentation before coding provider-specific assumptions.

## Payment architecture
Create a provider interface with operations supported by each provider:
- initiate payment
- retrieve payment status
- verify callback/webhook
- cancel/void where supported
- refund where supported
- reconcile transactions

Do not assume every gateway supports every operation. Record provider capability and limitations.

## SADAD
- Configure merchant credentials securely.
- Use sandbox/test environment first.
- Create payment request server-side.
- Store internal payment ID and provider reference.
- Use supported embedded/native flow only if SADAD explicitly provides and approves it.
- Provide hosted checkout fallback if required.
- Verify payment status server-to-server.
- Validate callback signatures/authentication according to provider documentation.
- Handle delayed, duplicate and out-of-order callbacks.
- Handle pending, failed, expired and successful states.
- Support retry without duplicate charge.
- Implement refunds only if supported by merchant agreement/API.
- Redact secrets and sensitive personal/payment data from logs.

Never mark payment successful based only on Flutter return URL, browser redirect, or client message.

## COD
- Enable/disable by branch and delivery zone.
- Configure minimum/maximum order value.
- Configure COD service fee.
- Record amount due.
- Assign collecting employee.
- Record amount collected, timestamp and collection method.
- Support partial collection only if business policy allows.
- Handle failed collection and delivery failure.
- Include cash in reconciliation.

## Pay at pickup
- Enable/disable per branch.
- Configure accepted tender types (cash/card terminal).
- Verify pickup reservation before accepting payment.
- Record employee, amount, method, receipt reference and time.
- Update payment state only after authorized confirmation.

## Payment state machine
- pending
- processing
- authorized (only if provider supports)
- paid
- failed
- cancelled
- expired
- partially_refunded
- refunded

Order status is separate from payment status.

## Refunds
- Full and partial refund subject to remaining refundable amount.
- Refund reason.
- Requested/approved/processed/failed statuses.
- Approval permissions.
- Provider reference.
- Refund audit trail.
- Prevent cumulative refunds exceeding captured amount.
- Reconcile refund state with provider.
- Support manual refunds only with controlled permission and audit.

## Webhooks and idempotency
- Verify provider authenticity.
- Persist provider event ID and payload hash.
- Unique constraint on provider event ID.
- Process in transaction.
- Queue non-critical follow-up tasks.
- Reconcile ambiguous states through provider status API.
- Idempotency keys for payment initiation and refund initiation.

## Admin screens
- Payment list and detail.
- SADAD transactions.
- COD collections.
- Pay-at-pickup transactions.
- Failed/pending transactions.
- Refund queue.
- Reconciliation.
- Gateway configuration (secrets masked).
- Provider event logs (redacted).

## Tests
- Success, failure, pending, expiry.
- Duplicate and out-of-order webhook.
- Invalid signature.
- Customer closes app during payment.
- Payment retry.
- Partial and full refund.
- Refund limit.
- COD collection and reconciliation.
- Pay-at-pickup.
- Payment/order status separation.

## Acceptance criteria
- Payment state cannot be changed by customer client.
- SADAD is verified server-side.
- Duplicate callbacks do not duplicate payment.
- Refund limits are enforced.
- COD and pickup collection are auditable.
- Provider limitations are documented.
