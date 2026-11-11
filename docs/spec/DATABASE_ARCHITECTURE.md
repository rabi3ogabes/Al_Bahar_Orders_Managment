# Database Architecture — PostgreSQL

## 1. Overview
Single-company, multi-branch database. Do not create a database per branch. Use shared company data with branch-scoped operational records and strict server-side authorization.

## 2. Core entity groups

### Identity
- users
- user_addresses
- user_sessions / personal_access_tokens
- otp_verifications
- user_devices
- roles
- permissions
- role_permissions
- user_roles
- branch_employees

### Organization
- branches
- branch_hours
- branch_holidays
- branch_settings
- delivery_zones
- branch_delivery_zones
- delivery_slots

### Catalog
- products
- product_translations
- categories
- category_translations
- brands
- product_variants
- product_attributes
- product_attribute_values
- product_images
- product_videos
- product_branch_settings
- product_variant_branch_settings
- product_warranties
- product_related
- product_price_history

### Inventory and purchasing
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

### Commerce
- carts
- cart_items
- orders
- order_items
- order_fulfillments
- fulfillment_items
- order_status_history
- order_notes
- order_cancellations
- order_invoices
- order_coupons

### Payments
- payments
- payment_transactions
- payment_webhook_events
- refunds
- cash_collections
- payment_reconciliations
- payment_reconciliation_items

### Fulfillment
- delivery_providers
- deliveries
- delivery_status_history
- delivery_attempts
- delivery_proofs
- pickup_reservations
- pickup_verifications

### Auctions
- auctions
- auction_translations
- auction_lots
- auction_products
- auction_branches
- auction_bidders
- auction_bids
- auction_auto_bids
- auction_extensions
- auction_winners
- auction_deposits
- auction_lot_events

### Marketing and engagement
- customer_groups
- customer_group_members
- promotions
- promotion_products
- promotion_categories
- promotion_branches
- coupons
- coupon_usage
- wishlists
- wishlist_items
- reviews
- review_media
- loyalty_accounts
- loyalty_transactions
- referrals
- gift_cards

### CMS and operations
- banners
- homepage_sections
- homepage_section_items
- cms_pages
- cms_page_translations
- notifications
- notification_templates
- notification_logs
- notification_preferences
- support_tickets
- support_messages
- audit_logs
- system_settings

## 3. Important relationships

- One product has many variants.
- One variant has one inventory row per branch.
- One branch has many inventory rows.
- Product branch settings are unique by product + branch.
- Variant branch settings are unique by variant + branch.
- One customer has many addresses and orders.
- One parent order has many order items and fulfillment records.
- One fulfillment belongs to one branch.
- One order may have multiple payment attempts/transactions.
- One payment may have multiple refunds, but total refunds cannot exceed captured amount.
- One auction has many lots.
- One lot has many accepted/rejected bid records.
- Each lot has at most one final winner record.
- A pickup reservation belongs to a fulfillment.
- A delivery belongs to a fulfillment (or a delivery group explicitly modeled for consolidation).

## 4. Key constraints

Recommended:
- `UNIQUE(products.sku)`
- `UNIQUE(product_variants.sku)`
- `UNIQUE(branch_inventory.branch_id, branch_inventory.variant_id)`
- `UNIQUE(product_branch_settings.product_id, branch_id)`
- `UNIQUE(product_variant_branch_settings.variant_id, branch_id)`
- `UNIQUE(product_translations.product_id, language)`
- `UNIQUE(category_translations.category_id, language)`
- `UNIQUE(payment_transactions.gateway, transaction_reference)` where reference exists
- `UNIQUE(payment_webhook_events.provider, provider_event_id)`
- `UNIQUE(auction_bids.auction_lot_id, bid_number)`
- `CHECK(on_hand >= 0)`
- `CHECK(reserved >= 0)`
- `CHECK(reserved <= on_hand)` if the chosen stock ledger model maintains this invariant
- Positive quantity checks where applicable.
- Valid money and currency checks.

Use partial unique indexes where nullable provider references or active records require them.

## 5. Money and time
- Store monetary values as `NUMERIC(12,2)` or a suitable higher precision.
- Store currency code on transactions/orders.
- Never use float/double for money.
- Store timestamps in UTC (`timestamptz`).
- Present business times in `Asia/Qatar`.
- Preserve price/tax/discount snapshots on order lines.

## 6. Inventory ledger
`branch_inventory` is the current balance projection. `stock_movements` is the audit ledger.
Every change to on-hand or reserved quantities must be made by a domain service in a database transaction and create the corresponding movement/reservation record.
Use row-level locks when reserving or consuming stock.
Do not allow direct inventory edits from generic CRUD endpoints.

## 7. Order snapshots
Order lines must preserve product name in both languages, SKU, variant attributes, image reference, price, discount, quantity, branch, tax snapshot and fulfillment details. Historical invoices must not change when catalog records are edited.

## 8. Auction integrity
PostgreSQL is the authoritative bid ledger. Redis/WebSockets distribute updates but are not the source of truth.
Lock the auction lot row during bid acceptance, validate current state and minimum bid, allocate a sequence number, insert the bid, update current state and commit before broadcasting.
Use idempotency keys and unique constraints to prevent duplicate bid acceptance.

## 9. Branch isolation
Branch scope must be derived from the authenticated principal and role assignments. Use Laravel policies/scopes and consider PostgreSQL row-level security as defense in depth if operationally suitable. Do not rely on RLS alone; application authorization remains mandatory.

## 10. Indexing
Add indexes for:
- users(phone), users(email)
- products(sku), products(barcode), products(category_id)
- translations(language, normalized/searchable name)
- branch_inventory(branch_id, variant_id)
- orders(order_number), orders(user_id, status, created_at)
- order_items(branch_id)
- payments(status, created_at)
- payment transaction references
- deliveries(tracking_number, status)
- auctions(status, start_at, end_at)
- auction bids(lot_id, accepted_at, bid_number)
- notifications(user_id, read_at, created_at)

Use `EXPLAIN ANALYZE` against realistic datasets before adding speculative indexes.

## 11. Required database documentation
Generate and maintain:
- ER diagram.
- Table/column dictionary.
- Foreign key and index list.
- Status/state machine definitions.
- Migration history.
- Data retention policy.
- Backup and restore procedure.
