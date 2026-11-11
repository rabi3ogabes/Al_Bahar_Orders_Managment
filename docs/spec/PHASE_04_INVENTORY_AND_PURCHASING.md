# Phase 04 — Independent Branch Inventory, Stock Ledger and Purchasing

## Objective
Implement accurate independent stock per branch, reservation support, stock transfers, stocktaking, suppliers and purchasing.

## Non-negotiable inventory rules
- Inventory key is `(branch_id, product_variant_id)`.
- Each branch owns its own on-hand and reserved quantities.
- Available quantity = on_hand - reserved.
- Never allow available stock below zero.
- Every change creates an immutable stock movement record.
- Do not directly overwrite quantities without a movement and reason.
- All stock operations use database transactions and appropriate row locks.
- Reservations must be idempotent and have expiry/expiry processing.
- Stock is not shared between branches.

## Admin screens
- Inventory overview by branch.
- Product/variant stock detail.
- Low-stock and out-of-stock lists.
- Stock receipt.
- Stock adjustment.
- Stock transfer.
- Stocktaking session.
- Damaged/lost stock.
- Stock movement history.
- Supplier management.
- Purchase orders.
- Goods receiving.
- Inventory valuation report.

## Inventory data
For each branch and variant store:
- on_hand
- reserved
- minimum_stock
- optional maximum_stock
- last_counted_at

Available stock should be calculated or maintained with strict consistency. Do not allow clients to submit available stock as authoritative data.

## Stock movement types
- opening_balance
- purchase_receipt
- sale_deduction
- reservation
- reservation_release
- transfer_out
- transfer_in
- adjustment_in
- adjustment_out
- customer_return
- damaged
- lost
- correction/reversal

Each movement must include branch, variant, quantity delta, movement type, reference type/id, actor, timestamp and reason where relevant.

## Reservations
Implement reservation service:
- Reserve quantities during confirmed checkout.
- Validate all required branch/variant quantities atomically.
- Lock inventory rows to avoid overselling.
- Record reservation expiry.
- Release expired reservations through scheduled jobs.
- Release reservations after eligible cancellation/failure.
- Prevent duplicate reservation for same order item.
- Reservation lifecycle: active, consumed, released, expired.

The exact point when on-hand stock is deducted must be consistent and documented. Recommended: reserve at order confirmation; decrement on-hand and reserved when fulfillment is dispatched/handed over, with compensating movements for returns.

## Stock transfers
Workflow:
1. Source branch creates transfer.
2. Authorized manager approves.
3. Reserve source stock.
4. Dispatch transfer; source on-hand is reduced or moved into an in-transit ledger according to chosen ledger model.
5. Destination receives quantities.
6. Record discrepancies/damage.
7. Complete transfer.
8. Release unshipped or rejected quantities as appropriate.

Support partial dispatch and partial receipt. Use explicit in-transit status and reconciliation.

## Purchasing
- Supplier CRUD.
- Purchase order creation.
- Purchase order lines and costs.
- Approval workflow.
- Goods receipt.
- Partial receipts.
- Supplier reference/invoice.
- Update inventory through purchase receipt movements.
- Purchase cost history.

## Database tables
- branch_inventory
- stock_movements
- stock_reservations
- stock_transfers
- stock_transfer_items
- inventory_adjustments
- inventory_adjustment_items
- stocktaking_sessions
- stocktaking_items
- suppliers
- purchase_orders
- purchase_order_items
- goods_receipts
- goods_receipt_items

## API
- Customer product availability endpoint.
- Admin inventory endpoints.
- Branch inventory endpoints.
- Stock transfer endpoints.
- Supplier/purchasing endpoints.
All branch operations must be authorized server-side.

## Tests
- Concurrent reservations cannot oversell.
- Reservation expiry releases stock once only.
- Cancellation releases stock once only.
- Transfer partial receipt.
- Transfer discrepancy.
- Purchase receipt adds stock correctly.
- Every stock change has a movement.
- Branch A cannot adjust Branch B inventory.

## Acceptance criteria
- Accurate branch/variant stock.
- No negative available stock.
- Full stock ledger.
- Safe reservation and release.
- Transfers and purchasing work end-to-end.
- Low-stock reporting works.
