# Phase 08 — Store Pickup, Internal Delivery and Third-Party Delivery

## Objective
Implement two fulfillment methods and two delivery execution models.

## Pickup
- Customer selects an eligible branch.
- Show branch opening hours and pickup slots.
- Enforce slot capacity.
- Reserve inventory through order workflow.
- Branch receives pickup task.
- Staff prepares order and marks ready.
- Generate secure random pickup token/QR.
- QR must be single-use and expire according to policy.
- Staff scans QR and verifies order.
- Support authorized alternate collector if enabled.
- Record staff ID, timestamp and items handed over.
- Support partial pickup for split fulfillment.
- Notify customer.
- Complete fulfillment only after handover.

Do not put sequential order IDs or personal data in QR codes. Use opaque, unguessable tokens and server-side verification.

## Internal delivery
- Delivery employee profiles and branch assignment.
- Delivery task assignment.
- Assigned task list in employee mobile experience or restricted Flutter employee app.
- Customer address and instructions.
- Call customer action.
- Map navigation.
- Status updates.
- Out-for-delivery.
- Delivered confirmation.
- Proof photo/signature if configured.
- COD collection.
- Failed delivery reason.
- Reschedule.
- Return to branch.
- Daily cash and delivery reports.

## Third-party delivery providers
Build an adapter interface, not provider-specific logic inside order controllers.
Each adapter may support:
- quote/rate
- create booking
- cancel booking
- tracking lookup
- webhook processing
- proof of delivery

Provider capability must be configured and documented. Do not assume every provider supports all operations.

Store:
- provider
- provider reference
- booking request/response safely redacted
- tracking number
- quoted fee
- actual fee
- status
- timestamps
- error details safe for admin

## Delivery zones and fees
- Branch-to-zone service mapping.
- Zone-specific fee.
- Free delivery threshold.
- Minimum/maximum order.
- Oversized item surcharge.
- Remote/unsupported area handling.
- Delivery slot capacity.
- Optional same-day/express service.
All fees must be quoted before order confirmation.

## Multi-branch delivery
- Each fulfillment can have its own delivery.
- Optional consolidation at a configured hub/branch.
- If consolidated, track transfer and handover.
- Customer must see delivery plan and fees.
- One parent order may show multiple delivery statuses.

## Tests
- Pickup slot capacity.
- QR expiry and duplicate scan.
- Partial pickup.
- Internal assignment and proof.
- COD collection.
- Failed delivery.
- Provider API timeout.
- Duplicate provider webhook.
- Delivery cancellation.
- Branch permission enforcement.

## Acceptance criteria
- Pickup end-to-end works.
- Internal delivery end-to-end works.
- Provider adapter can be tested with a mock provider.
- Delivery statuses and tracking are visible to customer/admin.
- COD collection is auditable.
