# Phase 06 — Cart, Checkout, Orders and Multi-Branch Fulfillment Allocation

## Objective
Implement secure checkout and order lifecycle, including single-branch and split-branch fulfillment.

## Cart
- Support authenticated customer carts.
- Optional guest cart with safe merge after login.
- Cart items reference product variant.
- Quantity validation.
- Product/variant/branch availability validation.
- Effective pricing recalculation.
- Coupon validation hook.
- Delivery/pickup selection.
- Cart expiry policy configurable.
- Prevent duplicate cart lines for same variant and fulfillment context where appropriate.

## Checkout quote
Create `POST /api/v1/checkout/quote`.
It must:
- Re-read current product and variant status.
- Validate branch availability.
- Resolve effective branch price.
- Validate quantity and available stock.
- Apply eligible promotions/coupons.
- Determine fulfillment eligibility.
- Calculate delivery fees.
- Calculate service fees.
- Calculate taxes only according to configured legal/accounting rules.
- Return line-level and total breakdown.
- Return warnings for changed prices or unavailable products.
A quote is informational and must not guarantee stock until reservation succeeds.

## Fulfillment allocation
Support:
1. Single-branch fulfillment: all items assigned to one branch.
2. Split fulfillment: items assigned to different branches according to stock, customer choice and company allocation policy.

Create a configurable allocation strategy:
- Prefer selected branch.
- Prefer fewest fulfillment branches.
- Prefer nearest eligible branch if location is available and consented.
- Prefer branches with sufficient stock.
- Admin override with audit record.

Do not split an order silently if extra delivery fees, separate delivery windows or customer experience changes. Display the complete cost and fulfillment plan before confirmation.

## Order creation
Use a database transaction:
1. Validate idempotency key.
2. Revalidate quote and prices.
3. Allocate branch fulfillment.
4. Lock inventory rows.
5. Reserve stock for all items.
6. Create parent order.
7. Create order item snapshots.
8. Create fulfillment records and fulfillment items.
9. Create payment intent/record where applicable.
10. Commit.
If any critical step fails, roll back all database changes.

## Order snapshots
Preserve:
- product name Arabic/English
- SKU
- variant name/attributes
- product image reference
- unit price
- sale/discount values
- quantity
- line total
- branch
- address snapshot
- delivery/pickup selection
- fee breakdown
- currency
- applicable tax details

## Order statuses
Use a documented state machine. Suggested parent statuses:
- pending_payment
- confirmed
- processing
- partially_fulfilled
- ready_for_pickup
- out_for_delivery
- completed
- cancellation_requested
- cancelled
- partially_cancelled
- returned

Fulfillment statuses are independent:
- pending
- accepted
- preparing
- ready
- dispatched
- out_for_delivery
- delivered
- pickup_ready
- picked_up
- failed
- cancelled
- returned

Payment status must remain separate.

## Customer APIs
- list orders
- order detail
- order timeline
- cancellation request
- reorder where products remain available
- invoice download
- fulfillment tracking

## Admin/branch dashboard
- Search/filter orders.
- View parent order and branch fulfillments.
- Accept/reject where policy allows.
- Prepare items.
- Record packing.
- Mark ready.
- Assign delivery/pickup.
- Add internal notes.
- Record cancellation reason.
- Print invoice and packing slip.
- Export authorized records.

## Cancellation
- Define cancellation eligibility by order/fulfillment state.
- Release reservations exactly once.
- Trigger payment void/refund workflow as appropriate.
- Maintain status history.
- Require approval for restricted states.
- Do not delete order history.

## Tests
- Price changed after cart.
- Stock depleted before checkout.
- Concurrent checkout.
- Split fulfillment.
- Idempotent checkout retry.
- Checkout rollback.
- Order snapshots remain unchanged after product edit.
- Cancellation releases reservations once.
- Branch employee cannot manage unrelated fulfillment.

## Acceptance criteria
- Checkout is server-authoritative.
- Parent order and branch fulfillment records are created correctly.
- Split fulfillment works.
- No overselling.
- Idempotent checkout.
- Complete order timeline and audit history.
