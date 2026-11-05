# Data dictionary

Generated from the live PostgreSQL schema (`docs/database/generate.py`). Regenerate after every migration.

**133 tables**, 250 foreign keys, 68 CHECK constraints, 314 indexes.

## Append-only (immutable) tables

Protected by the `prevent_ledger_mutation()` trigger: audit_logs, order_status_history, payment_transactions, product_price_history, stock_movements

## `account_deletion_requests`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | uuid | no |  |
| `user_id` | uuid | no |  |
| `status` | character varying(16) | no | 'pending'::character varying |
| `reason` | text | yes |  |
| `scheduled_for` | timestamp with time zone | yes |  |
| `processed_at` | timestamp with time zone | yes |  |
| `processed_by` | uuid | yes |  |
| `created_at` | timestamp with time zone | yes |  |
| `updated_at` | timestamp with time zone | yes |  |

Foreign keys: `processed_by` → `users` (on delete set null); `user_id` → `users` (on delete cascade)

## `auction_auto_bids`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | uuid | no |  |
| `auction_lot_id` | uuid | no |  |
| `user_id` | uuid | no |  |
| `max_amount` | numeric | no |  |
| `status` | character varying(16) | no | 'active'::character varying |
| `set_at` | timestamp with time zone | no |  |
| `created_at` | timestamp with time zone | yes |  |
| `updated_at` | timestamp with time zone | yes |  |

Foreign keys: `auction_lot_id` → `auction_lots` (on delete cascade); `user_id` → `users` (on delete cascade)

Indexes:

- `UNIQUE INDEX auction_auto_bids_one_active ON public.auction_auto_bids USING btree (auction_lot_id, user_id) WHERE ((status)::text = 'active'::text)`

## `auction_bidders`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | uuid | no |  |
| `auction_id` | uuid | no |  |
| `user_id` | uuid | no |  |
| `status` | character varying(16) | no | 'approved'::character varying |
| `paddle_number` | character varying(16) | no |  |
| `terms_accepted_at` | timestamp with time zone | no |  |
| `blocked_reason` | character varying(500) | yes |  |
| `blocked_by` | uuid | yes |  |
| `created_at` | timestamp with time zone | yes |  |
| `updated_at` | timestamp with time zone | yes |  |

Foreign keys: `auction_id` → `auctions` (on delete cascade); `blocked_by` → `users` (on delete set null); `user_id` → `users` (on delete cascade)

Indexes:

- `UNIQUE INDEX auction_bidders_auction_id_paddle_number_unique ON public.auction_bidders USING btree (auction_id, paddle_number)`
- `UNIQUE INDEX auction_bidders_auction_id_user_id_unique ON public.auction_bidders USING btree (auction_id, user_id)`

## `auction_bids`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | uuid | no |  |
| `auction_lot_id` | uuid | no |  |
| `user_id` | uuid | no |  |
| `bid_number` | bigint | yes |  |
| `amount` | numeric | no |  |
| `type` | character varying(16) | no |  |
| `status` | character varying(16) | no |  |
| `rejection_reason` | character varying(64) | yes |  |
| `idempotency_key` | character varying(128) | yes |  |
| `ip` | character varying(45) | yes |  |
| `placed_at` | timestamp with time zone | no |  |
| `created_at` | timestamp with time zone | yes |  |
| `updated_at` | timestamp with time zone | yes |  |

Foreign keys: `auction_lot_id` → `auction_lots` (on delete restrict); `user_id` → `users` (on delete restrict)

Checks: `CHECK (((((status)::text = 'accepted'::text) = (bid_number IS NOT NULL)) OR ((status)::text = 'retracted'::text)))`

Indexes:

- `UNIQUE INDEX auction_bids_auction_lot_id_bid_number_unique ON public.auction_bids USING btree (auction_lot_id, bid_number)`
- `INDEX auction_bids_auction_lot_id_placed_at_bid_number_index ON public.auction_bids USING btree (auction_lot_id, placed_at, bid_number)`
- `UNIQUE INDEX auction_bids_user_id_idempotency_key_unique ON public.auction_bids USING btree (user_id, idempotency_key)`

## `auction_branches`

| Column | Type | Null | Default |
|---|---|---|---|
| `auction_id` | uuid | no |  |
| `branch_id` | uuid | no |  |

Foreign keys: `auction_id` → `auctions` (on delete cascade); `branch_id` → `branches` (on delete cascade)

## `auction_deposits`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | uuid | no |  |
| `auction_id` | uuid | no |  |
| `user_id` | uuid | no |  |
| `amount` | numeric | no |  |
| `status` | character varying(16) | no | 'pending'::character varying |
| `payment_id` | uuid | yes |  |
| `paid_at` | timestamp with time zone | yes |  |
| `resolved_at` | timestamp with time zone | yes |  |
| `resolution_note` | character varying(500) | yes |  |
| `created_at` | timestamp with time zone | yes |  |
| `updated_at` | timestamp with time zone | yes |  |

Foreign keys: `auction_id` → `auctions` (on delete restrict); `payment_id` → `payments` (on delete set null); `user_id` → `users` (on delete restrict)

Indexes:

- `UNIQUE INDEX auction_deposits_auction_id_user_id_unique ON public.auction_deposits USING btree (auction_id, user_id)`

## `auction_extensions`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | uuid | no |  |
| `auction_lot_id` | uuid | no |  |
| `auction_bid_id` | uuid | yes |  |
| `previous_ends_at` | timestamp with time zone | no |  |
| `new_ends_at` | timestamp with time zone | no |  |
| `created_at` | timestamp with time zone | no | CURRENT_TIMESTAMP |

Foreign keys: `auction_bid_id` → `auction_bids` (on delete set null); `auction_lot_id` → `auction_lots` (on delete cascade)

## `auction_lot_events`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | bigint | no | nextval('auction_lot_events_id_seq'::regclass) |
| `auction_lot_id` | uuid | no |  |
| `sequence` | bigint | no |  |
| `type` | character varying(32) | no |  |
| `payload` | jsonb | no |  |
| `actor_id` | uuid | yes |  |
| `created_at` | timestamp with time zone | no | CURRENT_TIMESTAMP |

Foreign keys: `auction_lot_id` → `auction_lots` (on delete cascade)

Indexes:

- `UNIQUE INDEX auction_lot_events_auction_lot_id_sequence_unique ON public.auction_lot_events USING btree (auction_lot_id, sequence)`

## `auction_lots`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | uuid | no |  |
| `auction_id` | uuid | no |  |
| `lot_number` | integer | no |  |
| `title_en` | character varying(255) | no |  |
| `title_ar` | character varying(255) | no |  |
| `description_en` | text | yes |  |
| `description_ar` | text | yes |  |
| `image_path` | character varying(255) | yes |  |
| `quantity` | integer | no | 1 |
| `starting_price` | numeric | no |  |
| `reserve_price` | numeric | yes |  |
| `min_increment` | numeric | no |  |
| `buy_now_price` | numeric | yes |  |
| `current_price` | numeric | yes |  |
| `current_bid_id` | uuid | yes |  |
| `current_leader_id` | uuid | yes |  |
| `bid_count` | integer | no | 0 |
| `last_bid_number` | bigint | no | '0'::bigint |
| `last_event_sequence` | bigint | no | '0'::bigint |
| `status` | character varying(16) | no | 'pending'::character varying |
| `starts_at` | timestamp with time zone | yes |  |
| `ends_at` | timestamp with time zone | yes |  |
| `original_ends_at` | timestamp with time zone | yes |  |
| `extension_count` | smallint | no | '0'::smallint |
| `paused_at` | timestamp with time zone | yes |  |
| `paused_remaining_seconds` | integer | yes |  |
| `closed_at` | timestamp with time zone | yes |  |
| `sort_order` | integer | no | 0 |
| `created_at` | timestamp with time zone | yes |  |
| `updated_at` | timestamp with time zone | yes |  |

Foreign keys: `auction_id` → `auctions` (on delete cascade); `current_bid_id` → `auction_bids` (on delete set null); `current_leader_id` → `users` (on delete set null)

Checks: `CHECK (((starting_price >= (0)::numeric) AND (min_increment > (0)::numeric) AND ((reserve_price IS NULL) OR (reserve_price >= (0)::numeric)) AND ((buy_now_price IS NULL) OR (buy_now_price > starting_price)) AND (quantity > 0)))`; `CHECK (((status)::text = ANY ((ARRAY['pending'::character varying, 'open'::character varying, 'paused'::character varying, 'closed'::character varying, 'sold'::character varying, 'unsold'::character varying, 'cancelled'::character varying])::text[])))`

Indexes:

- `UNIQUE INDEX auction_lots_auction_id_lot_number_unique ON public.auction_lots USING btree (auction_id, lot_number)`
- `INDEX auction_lots_status_ends_at_index ON public.auction_lots USING btree (status, ends_at)`

## `auction_products`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | uuid | no |  |
| `auction_lot_id` | uuid | no |  |
| `product_id` | uuid | no |  |
| `product_variant_id` | uuid | no |  |
| `branch_id` | uuid | no |  |
| `quantity` | integer | no | 1 |
| `created_at` | timestamp with time zone | yes |  |
| `updated_at` | timestamp with time zone | yes |  |

Foreign keys: `auction_lot_id` → `auction_lots` (on delete cascade); `branch_id` → `branches` (on delete restrict); `product_id` → `products` (on delete restrict); `product_variant_id` → `product_variants` (on delete restrict)

Indexes:

- `UNIQUE INDEX auction_products_auction_lot_id_product_variant_id_unique ON public.auction_products USING btree (auction_lot_id, product_variant_id)`

## `auction_translations`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | uuid | no |  |
| `auction_id` | uuid | no |  |
| `locale` | character varying(5) | no |  |
| `title` | character varying(255) | no |  |
| `description` | text | yes |  |
| `terms` | text | yes |  |
| `created_at` | timestamp with time zone | yes |  |
| `updated_at` | timestamp with time zone | yes |  |

Foreign keys: `auction_id` → `auctions` (on delete cascade)

Indexes:

- `UNIQUE INDEX auction_translations_auction_id_locale_unique ON public.auction_translations USING btree (auction_id, locale)`

## `auction_watchlists`

| Column | Type | Null | Default |
|---|---|---|---|
| `user_id` | uuid | no |  |
| `auction_lot_id` | uuid | no |  |
| `created_at` | timestamp with time zone | no | CURRENT_TIMESTAMP |

Foreign keys: `auction_lot_id` → `auction_lots` (on delete cascade); `user_id` → `users` (on delete cascade)

## `auction_winners`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | uuid | no |  |
| `auction_lot_id` | uuid | no |  |
| `user_id` | uuid | no |  |
| `auction_bid_id` | uuid | no |  |
| `amount` | numeric | no |  |
| `rank` | smallint | no | '1'::smallint |
| `status` | character varying(24) | no | 'pending_confirmation'::character varying |
| `payment_due_at` | timestamp with time zone | no |  |
| `order_id` | uuid | yes |  |
| `confirmed_at` | timestamp with time zone | yes |  |
| `paid_at` | timestamp with time zone | yes |  |
| `defaulted_at` | timestamp with time zone | yes |  |
| `created_at` | timestamp with time zone | yes |  |
| `updated_at` | timestamp with time zone | yes |  |

Foreign keys: `auction_bid_id` → `auction_bids` (on delete restrict); `auction_lot_id` → `auction_lots` (on delete restrict); `order_id` → `orders` (on delete set null); `user_id` → `users` (on delete restrict)

Indexes:

- `UNIQUE INDEX auction_winners_one_final ON public.auction_winners USING btree (auction_lot_id) WHERE ((status)::text <> ALL ((ARRAY['defaulted'::character varying, 'declined'::character varying, 'cancelled'::character varying])::text[]))`

## `auctions`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | uuid | no |  |
| `reference` | character varying(24) | no |  |
| `type` | character varying(16) | no |  |
| `status` | character varying(16) | no | 'draft'::character varying |
| `cover_image_path` | character varying(255) | yes |  |
| `video_url` | character varying(255) | yes |  |
| `starts_at` | timestamp with time zone | yes |  |
| `ends_at` | timestamp with time zone | yes |  |
| `requires_registration` | boolean | no | true |
| `requires_verified_phone` | boolean | no | true |
| `deposit_amount` | numeric | no | '0'::numeric |
| `winner_payment_hours` | smallint | no | '48'::smallint |
| `cod_allowed` | boolean | no | false |
| `pickup_allowed` | boolean | no | true |
| `delivery_allowed` | boolean | no | true |
| `extension_window_seconds` | integer | no | 120 |
| `extension_seconds` | integer | no | 120 |
| `max_extensions` | smallint | yes |  |
| `unpaid_winner_policy` | character varying(24) | no | 'offer_next_bidder'::character varying |
| `show_bidder_names` | boolean | no | false |
| `created_by` | uuid | yes |  |
| `published_at` | timestamp with time zone | yes |  |
| `cancelled_at` | timestamp with time zone | yes |  |
| `cancellation_reason` | character varying(500) | yes |  |
| `created_at` | timestamp with time zone | yes |  |
| `updated_at` | timestamp with time zone | yes |  |

Foreign keys: `created_by` → `users` (on delete set null)

Checks: `CHECK (((status)::text = ANY ((ARRAY['draft'::character varying, 'scheduled'::character varying, 'live'::character varying, 'paused'::character varying, 'closed'::character varying, 'cancelled'::character varying])::text[])))`; `CHECK (((type)::text = ANY ((ARRAY['scheduled'::character varying, 'live'::character varying])::text[])))`

Indexes:

- `UNIQUE INDEX auctions_reference_unique ON public.auctions USING btree (reference)`
- `INDEX auctions_status_starts_at_ends_at_index ON public.auctions USING btree (status, starts_at, ends_at)`

## `audit_logs`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | bigint | no | nextval('audit_logs_id_seq'::regclass) |
| `actor_id` | uuid | yes |  |
| `actor_type` | character varying(16) | yes |  |
| `action` | character varying(96) | no |  |
| `auditable_type` | character varying(96) | yes |  |
| `auditable_id` | character varying(64) | yes |  |
| `branch_id` | uuid | yes |  |
| `old_values` | jsonb | yes |  |
| `new_values` | jsonb | yes |  |
| `metadata` | jsonb | yes |  |
| `ip` | character varying(45) | yes |  |
| `user_agent` | character varying(512) | yes |  |
| `request_id` | character varying(64) | yes |  |
| `created_at` | timestamp with time zone | no | CURRENT_TIMESTAMP |

Indexes:

- `INDEX audit_logs_action_created_at_index ON public.audit_logs USING btree (action, created_at)`
- `INDEX audit_logs_actor_id_index ON public.audit_logs USING btree (actor_id)`
- `INDEX audit_logs_auditable_type_auditable_id_index ON public.audit_logs USING btree (auditable_type, auditable_id)`
- `INDEX audit_logs_branch_id_index ON public.audit_logs USING btree (branch_id)`
- `INDEX audit_logs_created_at_index ON public.audit_logs USING btree (created_at)`

## `banners`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | uuid | no |  |
| `placement` | character varying(32) | no | 'home_hero'::character varying |
| `platform` | character varying(16) | no | 'all'::character varying |
| `title_en` | character varying(255) | yes |  |
| `title_ar` | character varying(255) | yes |  |
| `subtitle_en` | character varying(255) | yes |  |
| `subtitle_ar` | character varying(255) | yes |  |
| `image_en_path` | character varying(255) | no |  |
| `image_ar_path` | character varying(255) | yes |  |
| `link_type` | character varying(16) | yes |  |
| `link_value` | character varying(255) | yes |  |
| `sort_order` | integer | no | 0 |
| `status` | character varying(16) | no | 'draft'::character varying |
| `starts_at` | timestamp with time zone | yes |  |
| `ends_at` | timestamp with time zone | yes |  |
| `created_at` | timestamp with time zone | yes |  |
| `updated_at` | timestamp with time zone | yes |  |

Indexes:

- `INDEX banners_placement_status_sort_order_index ON public.banners USING btree (placement, status, sort_order)`

## `branch_delivery_zones`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | uuid | no |  |
| `branch_id` | uuid | no |  |
| `delivery_zone_id` | uuid | no |  |
| `delivery_fee` | numeric | no | '0'::numeric |
| `free_delivery_threshold` | numeric | yes |  |
| `min_order_amount` | numeric | yes |  |
| `max_order_amount` | numeric | yes |  |
| `oversized_surcharge` | numeric | no | '0'::numeric |
| `cod_enabled` | boolean | no | true |
| `express_enabled` | boolean | no | false |
| `express_fee` | numeric | yes |  |
| `estimated_minutes` | integer | yes |  |
| `priority` | smallint | no | '100'::smallint |
| `is_active` | boolean | no | true |
| `created_at` | timestamp with time zone | yes |  |
| `updated_at` | timestamp with time zone | yes |  |

Foreign keys: `branch_id` → `branches` (on delete cascade); `delivery_zone_id` → `delivery_zones` (on delete cascade)

Checks: `CHECK (((delivery_fee >= (0)::numeric) AND (oversized_surcharge >= (0)::numeric) AND ((min_order_amount IS NULL) OR (max_order_amount IS NULL) OR (max_order_amount >= min_order_amount))))`

Indexes:

- `UNIQUE INDEX branch_delivery_zones_branch_id_delivery_zone_id_unique ON public.branch_delivery_zones USING btree (branch_id, delivery_zone_id)`

## `branch_employees`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | uuid | no |  |
| `branch_id` | uuid | no |  |
| `user_id` | uuid | no |  |
| `job_title` | character varying(255) | yes |  |
| `is_active` | boolean | no | true |
| `is_delivery_driver` | boolean | no | false |
| `assigned_by` | uuid | yes |  |
| `created_at` | timestamp with time zone | yes |  |
| `updated_at` | timestamp with time zone | yes |  |

Foreign keys: `assigned_by` → `users` (on delete set null); `branch_id` → `branches` (on delete cascade); `user_id` → `users` (on delete cascade)

Indexes:

- `UNIQUE INDEX branch_employees_branch_id_user_id_unique ON public.branch_employees USING btree (branch_id, user_id)`
- `INDEX branch_employees_user_id_is_active_index ON public.branch_employees USING btree (user_id, is_active)`

## `branch_holidays`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | uuid | no |  |
| `branch_id` | uuid | no |  |
| `type` | character varying(24) | no | 'holiday'::character varying |
| `name_en` | character varying(255) | no |  |
| `name_ar` | character varying(255) | no |  |
| `starts_at` | timestamp with time zone | no |  |
| `ends_at` | timestamp with time zone | no |  |
| `reason` | text | yes |  |
| `created_by` | uuid | yes |  |
| `created_at` | timestamp with time zone | yes |  |
| `updated_at` | timestamp with time zone | yes |  |

Foreign keys: `branch_id` → `branches` (on delete cascade); `created_by` → `users` (on delete set null)

Checks: `CHECK ((ends_at > starts_at))`

Indexes:

- `INDEX branch_holidays_branch_id_starts_at_ends_at_index ON public.branch_holidays USING btree (branch_id, starts_at, ends_at)`

## `branch_hours`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | uuid | no |  |
| `branch_id` | uuid | no |  |
| `day_of_week` | smallint | no |  |
| `is_closed` | boolean | no | false |
| `opens_at` | time without time zone | yes |  |
| `closes_at` | time without time zone | yes |  |
| `break_starts_at` | time without time zone | yes |  |
| `break_ends_at` | time without time zone | yes |  |
| `created_at` | timestamp with time zone | yes |  |
| `updated_at` | timestamp with time zone | yes |  |

Foreign keys: `branch_id` → `branches` (on delete cascade)

Checks: `CHECK (((day_of_week >= 0) AND (day_of_week <= 6)))`; `CHECK ((is_closed OR ((opens_at IS NOT NULL) AND (closes_at IS NOT NULL))))`

Indexes:

- `UNIQUE INDEX branch_hours_branch_id_day_of_week_unique ON public.branch_hours USING btree (branch_id, day_of_week)`

## `branch_inventory`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | uuid | no |  |
| `branch_id` | uuid | no |  |
| `product_variant_id` | uuid | no |  |
| `on_hand` | integer | no | 0 |
| `reserved` | integer | no | 0 |
| `minimum_stock` | integer | no | 0 |
| `maximum_stock` | integer | yes |  |
| `average_cost` | numeric | yes |  |
| `bin_location` | character varying(64) | yes |  |
| `last_counted_at` | timestamp with time zone | yes |  |
| `last_movement_at` | timestamp with time zone | yes |  |
| `created_at` | timestamp with time zone | yes |  |
| `updated_at` | timestamp with time zone | yes |  |

Foreign keys: `branch_id` → `branches` (on delete restrict); `product_variant_id` → `product_variants` (on delete restrict)

Checks: `CHECK ((reserved <= on_hand))`; `CHECK (((minimum_stock >= 0) AND ((maximum_stock IS NULL) OR (maximum_stock >= minimum_stock))))`; `CHECK ((on_hand >= 0))`; `CHECK ((reserved >= 0))`

Indexes:

- `UNIQUE INDEX branch_inventory_branch_id_product_variant_id_unique ON public.branch_inventory USING btree (branch_id, product_variant_id)`
- `INDEX branch_inventory_product_variant_id_index ON public.branch_inventory USING btree (product_variant_id)`

## `branch_settings`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | bigint | no | nextval('branch_settings_id_seq'::regclass) |
| `branch_id` | uuid | no |  |
| `key` | character varying(96) | no |  |
| `value` | jsonb | yes |  |
| `created_at` | timestamp with time zone | yes |  |
| `updated_at` | timestamp with time zone | yes |  |

Foreign keys: `branch_id` → `branches` (on delete cascade)

Indexes:

- `UNIQUE INDEX branch_settings_branch_id_key_unique ON public.branch_settings USING btree (branch_id, key)`

## `branches`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | uuid | no |  |
| `code` | character varying(24) | no |  |
| `name_en` | character varying(255) | no |  |
| `name_ar` | character varying(255) | no |  |
| `description_en` | text | yes |  |
| `description_ar` | text | yes |  |
| `phone` | character varying(20) | yes |  |
| `email` | character varying(255) | yes |  |
| `address_en` | character varying(255) | yes |  |
| `address_ar` | character varying(255) | yes |  |
| `area` | character varying(255) | yes |  |
| `zone_number` | character varying(16) | yes |  |
| `latitude` | numeric | yes |  |
| `longitude` | numeric | yes |  |
| `timezone` | character varying(48) | no | 'Asia/Qatar'::character varying |
| `opening_status` | character varying(24) | no | 'open'::character varying |
| `pickup_enabled` | boolean | no | true |
| `delivery_enabled` | boolean | no | true |
| `cod_enabled` | boolean | no | true |
| `pay_at_pickup_enabled` | boolean | no | true |
| `pay_at_pickup_tenders` | jsonb | yes |  |
| `cod_min_order` | numeric | yes |  |
| `cod_max_order` | numeric | yes |  |
| `pickup_preparation_minutes` | smallint | no | '60'::smallint |
| `is_fulfillment_hub` | boolean | no | false |
| `status` | character varying(16) | no | 'active'::character varying |
| `sort_order` | smallint | no | '0'::smallint |
| `created_at` | timestamp with time zone | yes |  |
| `updated_at` | timestamp with time zone | yes |  |

Checks: `CHECK (((latitude IS NULL) OR ((latitude >= ('-90'::integer)::numeric) AND (latitude <= (90)::numeric))))`; `CHECK (((longitude IS NULL) OR ((longitude >= ('-180'::integer)::numeric) AND (longitude <= (180)::numeric))))`; `CHECK (((opening_status)::text = ANY ((ARRAY['open'::character varying, 'temporarily_closed'::character varying])::text[])))`; `CHECK (((status)::text = ANY ((ARRAY['active'::character varying, 'inactive'::character varying])::text[])))`

Indexes:

- `UNIQUE INDEX branches_code_unique ON public.branches USING btree (code)`
- `INDEX branches_status_sort_order_index ON public.branches USING btree (status, sort_order)`

## `brands`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | uuid | no |  |
| `slug` | character varying(160) | no |  |
| `name_en` | character varying(255) | no |  |
| `name_ar` | character varying(255) | no |  |
| `description_en` | text | yes |  |
| `description_ar` | text | yes |  |
| `logo_path` | character varying(255) | yes |  |
| `status` | character varying(16) | no | 'active'::character varying |
| `sort_order` | integer | no | 0 |
| `created_at` | timestamp with time zone | yes |  |
| `updated_at` | timestamp with time zone | yes |  |

Indexes:

- `UNIQUE INDEX brands_slug_unique ON public.brands USING btree (slug)`

## `cart_items`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | uuid | no |  |
| `cart_id` | uuid | no |  |
| `product_variant_id` | uuid | no |  |
| `quantity` | integer | no |  |
| `price_at_add` | numeric | yes |  |
| `created_at` | timestamp with time zone | yes |  |
| `updated_at` | timestamp with time zone | yes |  |

Foreign keys: `cart_id` → `carts` (on delete cascade); `product_variant_id` → `product_variants` (on delete cascade)

Checks: `CHECK ((quantity > 0))`

Indexes:

- `UNIQUE INDEX cart_items_cart_id_product_variant_id_unique ON public.cart_items USING btree (cart_id, product_variant_id)`

## `carts`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | uuid | no |  |
| `user_id` | uuid | yes |  |
| `guest_token_hash` | character varying(64) | yes |  |
| `branch_id` | uuid | yes |  |
| `fulfillment_type` | character varying(16) | no | 'delivery'::character varying |
| `address_id` | uuid | yes |  |
| `coupon_code` | character varying(48) | yes |  |
| `loyalty_points_to_redeem` | integer | no | 0 |
| `status` | character varying(16) | no | 'active'::character varying |
| `converted_order_id` | uuid | yes |  |
| `last_activity_at` | timestamp with time zone | yes |  |
| `abandoned_notified_at` | timestamp with time zone | yes |  |
| `abandoned_notifications` | smallint | no | '0'::smallint |
| `expires_at` | timestamp with time zone | yes |  |
| `created_at` | timestamp with time zone | yes |  |
| `updated_at` | timestamp with time zone | yes |  |

Foreign keys: `address_id` → `user_addresses` (on delete set null); `branch_id` → `branches` (on delete set null); `user_id` → `users` (on delete cascade)

Indexes:

- `UNIQUE INDEX carts_guest_token_hash_unique ON public.carts USING btree (guest_token_hash)`
- `UNIQUE INDEX carts_one_active_per_user ON public.carts USING btree (user_id) WHERE (((status)::text = 'active'::text) AND (user_id IS NOT NULL))`
- `INDEX carts_status_last_activity_at_index ON public.carts USING btree (status, last_activity_at)`

## `cash_collections`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | uuid | no |  |
| `payment_id` | uuid | no |  |
| `order_fulfillment_id` | uuid | yes |  |
| `branch_id` | uuid | no |  |
| `collected_by` | uuid | no |  |
| `context` | character varying(16) | no |  |
| `tender` | character varying(16) | no |  |
| `amount_due` | numeric | no |  |
| `amount_collected` | numeric | no |  |
| `status` | character varying(16) | no |  |
| `receipt_reference` | character varying(128) | yes |  |
| `failure_reason` | character varying(500) | yes |  |
| `collected_at` | timestamp with time zone | no |  |
| `payment_reconciliation_id` | uuid | yes |  |
| `handed_in_at` | timestamp with time zone | yes |  |
| `handed_in_to` | uuid | yes |  |
| `created_at` | timestamp with time zone | yes |  |
| `updated_at` | timestamp with time zone | yes |  |
| `cash_handover_id` | uuid | yes |  |

Foreign keys: `branch_id` → `branches` (on delete restrict); `cash_handover_id` → `cash_handovers` (on delete restrict); `collected_by` → `users` (on delete restrict); `handed_in_to` → `users` (on delete set null); `order_fulfillment_id` → `order_fulfillments` (on delete restrict); `payment_id` → `payments` (on delete restrict); `payment_reconciliation_id` → `payment_reconciliations` (on delete set null)

Checks: `CHECK (((amount_due >= (0)::numeric) AND (amount_collected >= (0)::numeric)))`

Indexes:

- `INDEX cash_collections_branch_id_collected_at_index ON public.cash_collections USING btree (branch_id, collected_at)`
- `INDEX cash_collections_cash_handover_id_index ON public.cash_collections USING btree (cash_handover_id)`
- `INDEX cash_collections_collected_by_collected_at_index ON public.cash_collections USING btree (collected_by, collected_at)`

## `cash_handovers`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | uuid | no |  |
| `number` | character varying(32) | no |  |
| `branch_id` | uuid | no |  |
| `collector_id` | uuid | no |  |
| `received_by` | uuid | no |  |
| `collections_count` | integer | no |  |
| `expected_amount` | numeric | no |  |
| `counted_amount` | numeric | no |  |
| `variance` | numeric | no |  |
| `status` | character varying(16) | no |  |
| `note` | character varying(500) | yes |  |
| `variance_status` | character varying(16) | yes |  |
| `variance_resolved_by` | uuid | yes |  |
| `variance_resolved_at` | timestamp with time zone | yes |  |
| `variance_resolution` | character varying(500) | yes |  |
| `deposited_at` | timestamp with time zone | yes |  |
| `deposit_reference` | character varying(128) | yes |  |
| `deposited_by` | uuid | yes |  |
| `created_at` | timestamp with time zone | yes |  |
| `updated_at` | timestamp with time zone | yes |  |

Foreign keys: `branch_id` → `branches` (on delete restrict); `collector_id` → `users` (on delete restrict); `deposited_by` → `users` (on delete set null); `received_by` → `users` (on delete restrict); `variance_resolved_by` → `users` (on delete set null)

Checks: `CHECK (((expected_amount >= (0)::numeric) AND (counted_amount >= (0)::numeric) AND (collections_count > 0) AND (variance = (counted_amount - expected_amount))))`; `CHECK (((deposited_at IS NULL) = (deposit_reference IS NULL)))`; `CHECK ((collector_id <> received_by))`; `CHECK (((status)::text = ANY ((ARRAY['balanced'::character varying, 'short'::character varying, 'over'::character varying])::text[])))`; `CHECK ((((variance = (0)::numeric) AND (variance_status IS NULL)) OR ((variance <> (0)::numeric) AND ((variance_status)::text = ANY ((ARRAY['open'::character varying, 'resolved'::character varying])::text[])))))`

Indexes:

- `INDEX cash_handovers_branch_id_created_at_index ON public.cash_handovers USING btree (branch_id, created_at)`
- `INDEX cash_handovers_branch_id_deposited_at_index ON public.cash_handovers USING btree (branch_id, deposited_at)`
- `INDEX cash_handovers_collector_id_created_at_index ON public.cash_handovers USING btree (collector_id, created_at)`
- `UNIQUE INDEX cash_handovers_number_unique ON public.cash_handovers USING btree (number)`

## `catalog_imports`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | uuid | no |  |
| `user_id` | uuid | yes |  |
| `type` | character varying(24) | no | 'products'::character varying |
| `disk` | character varying(32) | no |  |
| `path` | character varying(255) | no |  |
| `original_name` | character varying(255) | no |  |
| `status` | character varying(16) | no | 'pending'::character varying |
| `total_rows` | integer | no | 0 |
| `created_rows` | integer | no | 0 |
| `updated_rows` | integer | no | 0 |
| `failed_rows` | integer | no | 0 |
| `errors` | jsonb | yes |  |
| `completed_at` | timestamp with time zone | yes |  |
| `created_at` | timestamp with time zone | yes |  |
| `updated_at` | timestamp with time zone | yes |  |

Foreign keys: `user_id` → `users` (on delete set null)

## `categories`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | uuid | no |  |
| `parent_id` | uuid | yes |  |
| `slug` | character varying(160) | no |  |
| `image_path` | character varying(255) | yes |  |
| `icon_path` | character varying(255) | yes |  |
| `banner_path` | character varying(255) | yes |  |
| `sort_order` | integer | no | 0 |
| `status` | character varying(16) | no | 'active'::character varying |
| `filter_attribute_ids` | jsonb | yes |  |
| `depth` | smallint | no | '0'::smallint |
| `path` | character varying(2000) | no | ''::character varying |
| `created_at` | timestamp with time zone | yes |  |
| `updated_at` | timestamp with time zone | yes |  |

Foreign keys: `parent_id` → `categories` (on delete restrict)

Checks: `CHECK (((parent_id IS NULL) OR (parent_id <> id)))`

Indexes:

- `INDEX categories_parent_id_index ON public.categories USING btree (parent_id)`
- `UNIQUE INDEX categories_slug_unique ON public.categories USING btree (slug)`

## `category_translations`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | uuid | no |  |
| `category_id` | uuid | no |  |
| `locale` | character varying(5) | no |  |
| `name` | character varying(255) | no |  |
| `description` | text | yes |  |
| `seo_title` | character varying(255) | yes |  |
| `seo_description` | character varying(500) | yes |  |
| `created_at` | timestamp with time zone | yes |  |
| `updated_at` | timestamp with time zone | yes |  |

Foreign keys: `category_id` → `categories` (on delete cascade)

Indexes:

- `UNIQUE INDEX category_translations_category_id_locale_unique ON public.category_translations USING btree (category_id, locale)`

## `cms_page_translations`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | uuid | no |  |
| `cms_page_id` | uuid | no |  |
| `locale` | character varying(5) | no |  |
| `title` | character varying(255) | no |  |
| `body` | text | no |  |
| `draft_body` | text | yes |  |
| `seo_title` | character varying(255) | yes |  |
| `seo_description` | character varying(500) | yes |  |
| `created_at` | timestamp with time zone | yes |  |
| `updated_at` | timestamp with time zone | yes |  |

Foreign keys: `cms_page_id` → `cms_pages` (on delete cascade)

Indexes:

- `UNIQUE INDEX cms_page_translations_cms_page_id_locale_unique ON public.cms_page_translations USING btree (cms_page_id, locale)`

## `cms_pages`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | uuid | no |  |
| `slug` | character varying(96) | no |  |
| `status` | character varying(16) | no | 'draft'::character varying |
| `published_at` | timestamp with time zone | yes |  |
| `updated_by` | uuid | yes |  |
| `created_at` | timestamp with time zone | yes |  |
| `updated_at` | timestamp with time zone | yes |  |

Foreign keys: `updated_by` → `users` (on delete set null)

Indexes:

- `UNIQUE INDEX cms_pages_slug_unique ON public.cms_pages USING btree (slug)`

## `coupon_usage`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | uuid | no |  |
| `coupon_id` | uuid | yes |  |
| `promotion_id` | uuid | no |  |
| `user_id` | uuid | yes |  |
| `order_id` | uuid | no |  |
| `discount_amount` | numeric | no |  |
| `status` | character varying(16) | no | 'applied'::character varying |
| `reversed_at` | timestamp with time zone | yes |  |
| `created_at` | timestamp with time zone | yes |  |
| `updated_at` | timestamp with time zone | yes |  |

Foreign keys: `coupon_id` → `coupons` (on delete set null); `order_id` → `orders` (on delete cascade); `promotion_id` → `promotions` (on delete restrict); `user_id` → `users` (on delete set null)

Indexes:

- `UNIQUE INDEX coupon_usage_promotion_id_order_id_unique ON public.coupon_usage USING btree (promotion_id, order_id)`
- `INDEX coupon_usage_user_id_promotion_id_status_index ON public.coupon_usage USING btree (user_id, promotion_id, status)`

## `coupons`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | uuid | no |  |
| `promotion_id` | uuid | no |  |
| `code` | character varying(48) | no |  |
| `usage_limit` | integer | yes |  |
| `per_customer_limit` | integer | yes |  |
| `times_used` | integer | no | 0 |
| `assigned_user_id` | uuid | yes |  |
| `is_active` | boolean | no | true |
| `starts_at` | timestamp with time zone | yes |  |
| `ends_at` | timestamp with time zone | yes |  |
| `created_at` | timestamp with time zone | yes |  |
| `updated_at` | timestamp with time zone | yes |  |

Foreign keys: `assigned_user_id` → `users` (on delete set null); `promotion_id` → `promotions` (on delete cascade)

Indexes:

- `UNIQUE INDEX coupons_code_unique ON public.coupons USING btree (code)`

## `customer_group_members`

| Column | Type | Null | Default |
|---|---|---|---|
| `customer_group_id` | uuid | no |  |
| `user_id` | uuid | no |  |
| `created_at` | timestamp with time zone | no | CURRENT_TIMESTAMP |

Foreign keys: `customer_group_id` → `customer_groups` (on delete cascade); `user_id` → `users` (on delete cascade)

## `customer_groups`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | uuid | no |  |
| `code` | character varying(32) | no |  |
| `name_en` | character varying(255) | no |  |
| `name_ar` | character varying(255) | no |  |
| `description` | text | yes |  |
| `created_at` | timestamp with time zone | yes |  |
| `updated_at` | timestamp with time zone | yes |  |

Indexes:

- `UNIQUE INDEX customer_groups_code_unique ON public.customer_groups USING btree (code)`

## `data_exports`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | uuid | no |  |
| `user_id` | uuid | no |  |
| `type` | character varying(48) | no |  |
| `filters` | jsonb | yes |  |
| `status` | character varying(16) | no | 'queued'::character varying |
| `disk` | character varying(32) | yes |  |
| `path` | character varying(255) | yes |  |
| `row_count` | integer | no | 0 |
| `error` | character varying(500) | yes |  |
| `completed_at` | timestamp with time zone | yes |  |
| `expires_at` | timestamp with time zone | yes |  |
| `created_at` | timestamp with time zone | yes |  |
| `updated_at` | timestamp with time zone | yes |  |

Foreign keys: `user_id` → `users` (on delete cascade)

## `deliveries`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | uuid | no |  |
| `order_fulfillment_id` | uuid | no |  |
| `branch_id` | uuid | no |  |
| `mode` | character varying(16) | no |  |
| `delivery_provider_id` | uuid | yes |  |
| `driver_id` | uuid | yes |  |
| `status` | character varying(24) | no | 'pending'::character varying |
| `tracking_number` | character varying(64) | no |  |
| `provider_reference` | character varying(128) | yes |  |
| `quoted_fee` | numeric | yes |  |
| `actual_fee` | numeric | yes |  |
| `cod_amount` | numeric | no | '0'::numeric |
| `requires_proof` | boolean | no | true |
| `attempt_count` | smallint | no | '0'::smallint |
| `scheduled_for` | timestamp with time zone | yes |  |
| `assigned_at` | timestamp with time zone | yes |  |
| `picked_up_at` | timestamp with time zone | yes |  |
| `out_for_delivery_at` | timestamp with time zone | yes |  |
| `delivered_at` | timestamp with time zone | yes |  |
| `failed_at` | timestamp with time zone | yes |  |
| `returned_at` | timestamp with time zone | yes |  |
| `failure_reason` | character varying(500) | yes |  |
| `booking_request` | jsonb | yes |  |
| `booking_response` | jsonb | yes |  |
| `last_error` | character varying(500) | yes |  |
| `created_at` | timestamp with time zone | yes |  |
| `updated_at` | timestamp with time zone | yes |  |

Foreign keys: `branch_id` → `branches` (on delete restrict); `delivery_provider_id` → `delivery_providers` (on delete restrict); `driver_id` → `users` (on delete set null); `order_fulfillment_id` → `order_fulfillments` (on delete cascade)

Checks: `CHECK (((status)::text = ANY ((ARRAY['pending'::character varying, 'assigned'::character varying, 'accepted'::character varying, 'picked_up'::character varying, 'out_for_delivery'::character varying, 'delivered'::character varying, 'failed'::character varying, 'rescheduled'::character varying, 'returning'::character varying, 'returned'::character varying, 'cancelled'::character varying])::text[])))`

Indexes:

- `INDEX deliveries_branch_id_status_index ON public.deliveries USING btree (branch_id, status)`
- `INDEX deliveries_driver_id_status_index ON public.deliveries USING btree (driver_id, status)`
- `UNIQUE INDEX deliveries_one_active_per_fulfillment ON public.deliveries USING btree (order_fulfillment_id) WHERE ((status)::text <> ALL ((ARRAY['cancelled'::character varying, 'failed'::character varying, 'returned'::character varying])::text[]))`
- `UNIQUE INDEX deliveries_provider_reference_unique ON public.deliveries USING btree (delivery_provider_id, provider_reference) WHERE (provider_reference IS NOT NULL)`
- `INDEX deliveries_tracking_number_status_index ON public.deliveries USING btree (tracking_number, status)`
- `UNIQUE INDEX deliveries_tracking_number_unique ON public.deliveries USING btree (tracking_number)`

## `delivery_attempts`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | uuid | no |  |
| `delivery_id` | uuid | no |  |
| `attempt_number` | smallint | no |  |
| `result` | character varying(16) | no |  |
| `failure_reason_code` | character varying(48) | yes |  |
| `notes` | character varying(500) | yes |  |
| `attempted_by` | uuid | yes |  |
| `attempted_at` | timestamp with time zone | no |  |
| `created_at` | timestamp with time zone | yes |  |
| `updated_at` | timestamp with time zone | yes |  |

Foreign keys: `attempted_by` → `users` (on delete set null); `delivery_id` → `deliveries` (on delete cascade)

Indexes:

- `UNIQUE INDEX delivery_attempts_delivery_id_attempt_number_unique ON public.delivery_attempts USING btree (delivery_id, attempt_number)`

## `delivery_proofs`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | uuid | no |  |
| `delivery_id` | uuid | no |  |
| `type` | character varying(16) | no |  |
| `disk` | character varying(32) | yes |  |
| `path` | character varying(255) | yes |  |
| `recipient_name` | character varying(255) | yes |  |
| `captured_by` | uuid | yes |  |
| `captured_at` | timestamp with time zone | no |  |
| `created_at` | timestamp with time zone | yes |  |
| `updated_at` | timestamp with time zone | yes |  |

Foreign keys: `captured_by` → `users` (on delete set null); `delivery_id` → `deliveries` (on delete cascade)

## `delivery_provider_events`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | uuid | no |  |
| `delivery_provider_id` | uuid | no |  |
| `provider_event_id` | character varying(191) | no |  |
| `payload_hash` | character varying(64) | no |  |
| `payload` | jsonb | no |  |
| `signature_valid` | boolean | no |  |
| `status` | character varying(16) | no | 'received'::character varying |
| `error` | character varying(500) | yes |  |
| `processed_at` | timestamp with time zone | yes |  |
| `created_at` | timestamp with time zone | yes |  |
| `updated_at` | timestamp with time zone | yes |  |

Foreign keys: `delivery_provider_id` → `delivery_providers` (on delete cascade)

Indexes:

- `UNIQUE INDEX delivery_provider_events_delivery_provider_id_provider_event_id ON public.delivery_provider_events USING btree (delivery_provider_id, provider_event_id)`

## `delivery_providers`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | uuid | no |  |
| `code` | character varying(32) | no |  |
| `name` | character varying(255) | no |  |
| `driver` | character varying(32) | no |  |
| `is_active` | boolean | no | false |
| `credentials` | text | yes |  |
| `webhook_secret` | text | yes |  |
| `settings` | jsonb | yes |  |
| `capabilities` | jsonb | yes |  |
| `created_at` | timestamp with time zone | yes |  |
| `updated_at` | timestamp with time zone | yes |  |

Indexes:

- `UNIQUE INDEX delivery_providers_code_unique ON public.delivery_providers USING btree (code)`

## `delivery_slot_bookings`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | uuid | no |  |
| `delivery_slot_id` | uuid | no |  |
| `slot_date` | date | no |  |
| `booked` | smallint | no | '0'::smallint |
| `created_at` | timestamp with time zone | yes |  |
| `updated_at` | timestamp with time zone | yes |  |

Foreign keys: `delivery_slot_id` → `delivery_slots` (on delete cascade)

Checks: `CHECK ((booked >= 0))`

Indexes:

- `UNIQUE INDEX delivery_slot_bookings_delivery_slot_id_slot_date_unique ON public.delivery_slot_bookings USING btree (delivery_slot_id, slot_date)`

## `delivery_slots`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | uuid | no |  |
| `branch_id` | uuid | no |  |
| `fulfillment_type` | character varying(16) | no |  |
| `day_of_week` | smallint | no |  |
| `starts_at` | time without time zone | no |  |
| `ends_at` | time without time zone | no |  |
| `capacity` | smallint | no |  |
| `cutoff_minutes` | smallint | no | '60'::smallint |
| `is_active` | boolean | no | true |
| `created_at` | timestamp with time zone | yes |  |
| `updated_at` | timestamp with time zone | yes |  |

Foreign keys: `branch_id` → `branches` (on delete cascade)

Checks: `CHECK (((ends_at > starts_at) AND (capacity > 0)))`; `CHECK (((fulfillment_type)::text = ANY ((ARRAY['delivery'::character varying, 'pickup'::character varying])::text[])))`

Indexes:

- `INDEX delivery_slots_branch_id_fulfillment_type_day_of_week_index ON public.delivery_slots USING btree (branch_id, fulfillment_type, day_of_week)`

## `delivery_status_history`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | bigint | no | nextval('delivery_status_history_id_seq'::regclass) |
| `delivery_id` | uuid | no |  |
| `from_status` | character varying(24) | yes |  |
| `to_status` | character varying(24) | no |  |
| `actor_id` | uuid | yes |  |
| `source` | character varying(16) | no | 'staff'::character varying |
| `note` | character varying(500) | yes |  |
| `latitude` | numeric | yes |  |
| `longitude` | numeric | yes |  |
| `created_at` | timestamp with time zone | no | CURRENT_TIMESTAMP |

Foreign keys: `delivery_id` → `deliveries` (on delete cascade)

## `delivery_zones`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | uuid | no |  |
| `code` | character varying(32) | no |  |
| `name_en` | character varying(255) | no |  |
| `name_ar` | character varying(255) | no |  |
| `zone_numbers` | character varying(255) | yes |  |
| `polygon` | jsonb | yes |  |
| `is_remote` | boolean | no | false |
| `is_active` | boolean | no | true |
| `created_at` | timestamp with time zone | yes |  |
| `updated_at` | timestamp with time zone | yes |  |

Indexes:

- `UNIQUE INDEX delivery_zones_code_unique ON public.delivery_zones USING btree (code)`

## `goods_receipt_items`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | uuid | no |  |
| `goods_receipt_id` | uuid | no |  |
| `purchase_order_item_id` | uuid | no |  |
| `product_variant_id` | uuid | no |  |
| `quantity_received` | integer | no |  |
| `quantity_rejected` | integer | no | 0 |
| `unit_cost` | numeric | no |  |
| `rejection_reason` | character varying(255) | yes |  |
| `created_at` | timestamp with time zone | yes |  |
| `updated_at` | timestamp with time zone | yes |  |

Foreign keys: `goods_receipt_id` → `goods_receipts` (on delete cascade); `product_variant_id` → `product_variants` (on delete restrict); `purchase_order_item_id` → `purchase_order_items` (on delete restrict)

Checks: `CHECK (((quantity_received >= 0) AND (quantity_rejected >= 0) AND ((quantity_received + quantity_rejected) > 0)))`

## `goods_receipts`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | uuid | no |  |
| `number` | character varying(32) | no |  |
| `purchase_order_id` | uuid | no |  |
| `branch_id` | uuid | no |  |
| `supplier_invoice_number` | character varying(255) | yes |  |
| `notes` | text | yes |  |
| `received_by` | uuid | yes |  |
| `received_at` | timestamp with time zone | no |  |
| `created_at` | timestamp with time zone | yes |  |
| `updated_at` | timestamp with time zone | yes |  |

Foreign keys: `branch_id` → `branches` (on delete restrict); `purchase_order_id` → `purchase_orders` (on delete restrict); `received_by` → `users` (on delete set null)

Indexes:

- `UNIQUE INDEX goods_receipts_number_unique ON public.goods_receipts USING btree (number)`

## `homepage_section_items`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | uuid | no |  |
| `homepage_section_id` | uuid | no |  |
| `item_type` | character varying(16) | no |  |
| `item_id` | uuid | no |  |
| `sort_order` | integer | no | 0 |
| `created_at` | timestamp with time zone | yes |  |
| `updated_at` | timestamp with time zone | yes |  |

Foreign keys: `homepage_section_id` → `homepage_sections` (on delete cascade)

## `homepage_sections`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | uuid | no |  |
| `type` | character varying(32) | no |  |
| `title_en` | character varying(255) | yes |  |
| `title_ar` | character varying(255) | yes |  |
| `subtitle_en` | character varying(255) | yes |  |
| `subtitle_ar` | character varying(255) | yes |  |
| `sort_order` | integer | no | 0 |
| `status` | character varying(16) | no | 'draft'::character varying |
| `layout` | character varying(24) | no | 'carousel'::character varying |
| `item_limit` | smallint | no | '12'::smallint |
| `settings` | jsonb | yes |  |
| `starts_at` | timestamp with time zone | yes |  |
| `ends_at` | timestamp with time zone | yes |  |
| `created_at` | timestamp with time zone | yes |  |
| `updated_at` | timestamp with time zone | yes |  |

## `idempotency_keys`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | uuid | no |  |
| `user_id` | uuid | yes |  |
| `scope` | character varying(48) | no |  |
| `key` | character varying(128) | no |  |
| `request_hash` | character varying(64) | no |  |
| `response_status` | smallint | yes |  |
| `response_body` | jsonb | yes |  |
| `locked_until` | timestamp with time zone | yes |  |
| `completed_at` | timestamp with time zone | yes |  |
| `created_at` | timestamp with time zone | yes |  |
| `updated_at` | timestamp with time zone | yes |  |

Indexes:

- `UNIQUE INDEX idempotency_keys_scope_user_id_key_unique ON public.idempotency_keys USING btree (scope, user_id, key)`

## `inventory_adjustment_items`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | uuid | no |  |
| `inventory_adjustment_id` | uuid | no |  |
| `product_variant_id` | uuid | no |  |
| `quantity` | integer | no |  |
| `unit_cost` | numeric | yes |  |
| `note` | character varying(500) | yes |  |
| `created_at` | timestamp with time zone | yes |  |
| `updated_at` | timestamp with time zone | yes |  |

Foreign keys: `inventory_adjustment_id` → `inventory_adjustments` (on delete cascade); `product_variant_id` → `product_variants` (on delete restrict)

Checks: `CHECK ((quantity <> 0))`

## `inventory_adjustments`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | uuid | no |  |
| `number` | character varying(32) | no |  |
| `branch_id` | uuid | no |  |
| `type` | character varying(24) | no |  |
| `status` | character varying(16) | no | 'draft'::character varying |
| `reason` | character varying(500) | no |  |
| `created_by` | uuid | yes |  |
| `approved_by` | uuid | yes |  |
| `posted_at` | timestamp with time zone | yes |  |
| `created_at` | timestamp with time zone | yes |  |
| `updated_at` | timestamp with time zone | yes |  |

Foreign keys: `approved_by` → `users` (on delete set null); `branch_id` → `branches` (on delete restrict); `created_by` → `users` (on delete set null)

Indexes:

- `INDEX inventory_adjustments_branch_id_status_index ON public.inventory_adjustments USING btree (branch_id, status)`
- `UNIQUE INDEX inventory_adjustments_number_unique ON public.inventory_adjustments USING btree (number)`

## `loyalty_accounts`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | uuid | no |  |
| `user_id` | uuid | no |  |
| `balance` | integer | no | 0 |
| `lifetime_earned` | bigint | no | '0'::bigint |
| `tier` | character varying(16) | no | 'classic'::character varying |
| `created_at` | timestamp with time zone | yes |  |
| `updated_at` | timestamp with time zone | yes |  |

Foreign keys: `user_id` → `users` (on delete cascade)

Checks: `CHECK ((balance >= 0))`

Indexes:

- `UNIQUE INDEX loyalty_accounts_user_id_unique ON public.loyalty_accounts USING btree (user_id)`

## `loyalty_transactions`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | bigint | no | nextval('loyalty_transactions_id_seq'::regclass) |
| `loyalty_account_id` | uuid | no |  |
| `type` | character varying(16) | no |  |
| `points` | integer | no |  |
| `balance_after` | integer | no |  |
| `remaining` | integer | yes |  |
| `order_id` | uuid | yes |  |
| `reference_type` | character varying(48) | yes |  |
| `reference_id` | character varying(64) | yes |  |
| `expires_at` | timestamp with time zone | yes |  |
| `reason` | character varying(500) | yes |  |
| `actor_id` | uuid | yes |  |
| `created_at` | timestamp with time zone | no | CURRENT_TIMESTAMP |

Foreign keys: `loyalty_account_id` → `loyalty_accounts` (on delete restrict)

Indexes:

- `INDEX loyalty_transactions_loyalty_account_id_created_at_index ON public.loyalty_transactions USING btree (loyalty_account_id, created_at)`
- `INDEX loyalty_transactions_type_expires_at_index ON public.loyalty_transactions USING btree (type, expires_at)`
- `UNIQUE INDEX loyalty_tx_unique_order_type ON public.loyalty_transactions USING btree (order_id, type) WHERE ((order_id IS NOT NULL) AND ((type)::text = ANY ((ARRAY['earn'::character varying, 'redeem'::character varying])::text[])))`

## `marketing_campaigns`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | uuid | no |  |
| `name` | character varying(255) | no |  |
| `channels` | jsonb | no |  |
| `title_en` | character varying(255) | no |  |
| `title_ar` | character varying(255) | no |  |
| `body_en` | text | no |  |
| `body_ar` | text | no |  |
| `deep_link` | character varying(255) | yes |  |
| `segment` | jsonb | no |  |
| `status` | character varying(16) | no | 'draft'::character varying |
| `scheduled_at` | timestamp with time zone | yes |  |
| `sent_at` | timestamp with time zone | yes |  |
| `recipients_count` | integer | no | 0 |
| `created_by` | uuid | yes |  |
| `created_at` | timestamp with time zone | yes |  |
| `updated_at` | timestamp with time zone | yes |  |

Foreign keys: `created_by` → `users` (on delete set null)

## `notification_logs`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | uuid | no |  |
| `notification_id` | uuid | yes |  |
| `user_id` | uuid | yes |  |
| `event` | character varying(64) | no |  |
| `channel` | character varying(16) | no |  |
| `provider` | character varying(32) | yes |  |
| `recipient` | character varying(191) | yes |  |
| `status` | character varying(16) | no | 'queued'::character varying |
| `attempts` | smallint | no | '0'::smallint |
| `provider_message_id` | character varying(191) | yes |  |
| `error` | character varying(500) | yes |  |
| `response` | jsonb | yes |  |
| `campaign_id` | character varying(64) | yes |  |
| `sent_at` | timestamp with time zone | yes |  |
| `created_at` | timestamp with time zone | yes |  |
| `updated_at` | timestamp with time zone | yes |  |

Foreign keys: `notification_id` → `notifications` (on delete set null); `user_id` → `users` (on delete set null)

Indexes:

- `INDEX notification_logs_status_created_at_index ON public.notification_logs USING btree (status, created_at)`
- `INDEX notification_logs_user_id_event_created_at_index ON public.notification_logs USING btree (user_id, event, created_at)`

## `notification_preferences`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | uuid | no |  |
| `user_id` | uuid | no |  |
| `channel` | character varying(16) | no |  |
| `category` | character varying(16) | no |  |
| `is_enabled` | boolean | no | true |
| `created_at` | timestamp with time zone | yes |  |
| `updated_at` | timestamp with time zone | yes |  |

Foreign keys: `user_id` → `users` (on delete cascade)

Indexes:

- `UNIQUE INDEX notification_preferences_user_id_channel_category_unique ON public.notification_preferences USING btree (user_id, channel, category)`

## `notification_templates`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | uuid | no |  |
| `event` | character varying(64) | no |  |
| `channel` | character varying(16) | no |  |
| `locale` | character varying(5) | no |  |
| `subject` | character varying(255) | yes |  |
| `body` | text | no |  |
| `is_active` | boolean | no | true |
| `created_at` | timestamp with time zone | yes |  |
| `updated_at` | timestamp with time zone | yes |  |

Indexes:

- `UNIQUE INDEX notification_templates_event_channel_locale_unique ON public.notification_templates USING btree (event, channel, locale)`

## `notifications`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | uuid | no |  |
| `user_id` | uuid | no |  |
| `event` | character varying(64) | no |  |
| `category` | character varying(16) | no | 'transactional'::character varying |
| `title` | character varying(255) | no |  |
| `body` | text | no |  |
| `data` | jsonb | yes |  |
| `read_at` | timestamp with time zone | yes |  |
| `created_at` | timestamp with time zone | yes |  |
| `updated_at` | timestamp with time zone | yes |  |

Foreign keys: `user_id` → `users` (on delete cascade)

Indexes:

- `INDEX notifications_user_id_read_at_created_at_index ON public.notifications USING btree (user_id, read_at, created_at)`

## `order_cancellations`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | uuid | no |  |
| `order_id` | uuid | no |  |
| `order_fulfillment_id` | uuid | yes |  |
| `requested_by` | uuid | yes |  |
| `requested_by_type` | character varying(16) | no |  |
| `reason_code` | character varying(48) | no |  |
| `reason` | text | yes |  |
| `status` | character varying(16) | no | 'requested'::character varying |
| `decided_by` | uuid | yes |  |
| `decided_at` | timestamp with time zone | yes |  |
| `decision_note` | text | yes |  |
| `created_at` | timestamp with time zone | yes |  |
| `updated_at` | timestamp with time zone | yes |  |

Foreign keys: `decided_by` → `users` (on delete set null); `order_fulfillment_id` → `order_fulfillments` (on delete cascade); `order_id` → `orders` (on delete cascade); `requested_by` → `users` (on delete set null)

## `order_coupons`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | uuid | no |  |
| `order_id` | uuid | no |  |
| `coupon_id` | uuid | yes |  |
| `promotion_id` | uuid | yes |  |
| `code` | character varying(48) | yes |  |
| `label_en` | character varying(255) | no |  |
| `label_ar` | character varying(255) | no |  |
| `discount_amount` | numeric | no |  |
| `free_delivery` | boolean | no | false |
| `created_at` | timestamp with time zone | yes |  |
| `updated_at` | timestamp with time zone | yes |  |

Foreign keys: `coupon_id` → `coupons` (on delete set null); `order_id` → `orders` (on delete cascade); `promotion_id` → `promotions` (on delete set null)

## `order_fulfillments`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | uuid | no |  |
| `order_id` | uuid | no |  |
| `branch_id` | uuid | no |  |
| `number` | character varying(32) | no |  |
| `type` | character varying(16) | no |  |
| `status` | character varying(24) | no | 'pending'::character varying |
| `delivery_zone_id` | uuid | yes |  |
| `subtotal` | numeric | no | '0'::numeric |
| `delivery_fee` | numeric | no | '0'::numeric |
| `delivery_slot_id` | uuid | yes |  |
| `slot_date` | date | yes |  |
| `is_express` | boolean | no | false |
| `assigned_to` | uuid | yes |  |
| `accepted_at` | timestamp with time zone | yes |  |
| `ready_at` | timestamp with time zone | yes |  |
| `dispatched_at` | timestamp with time zone | yes |  |
| `delivered_at` | timestamp with time zone | yes |  |
| `picked_up_at` | timestamp with time zone | yes |  |
| `cancelled_at` | timestamp with time zone | yes |  |
| `allocation_reason` | character varying(64) | yes |  |
| `created_at` | timestamp with time zone | yes |  |
| `updated_at` | timestamp with time zone | yes |  |

Foreign keys: `assigned_to` → `users` (on delete set null); `branch_id` → `branches` (on delete restrict); `delivery_slot_id` → `delivery_slots` (on delete set null); `delivery_zone_id` → `delivery_zones` (on delete set null); `order_id` → `orders` (on delete cascade)

Checks: `CHECK (((status)::text = ANY ((ARRAY['pending'::character varying, 'accepted'::character varying, 'preparing'::character varying, 'ready'::character varying, 'dispatched'::character varying, 'out_for_delivery'::character varying, 'delivered'::character varying, 'pickup_ready'::character varying, 'picked_up'::character varying, 'failed'::character varying, 'cancelled'::character varying, 'returned'::character varying])::text[])))`

Indexes:

- `INDEX order_fulfillments_branch_id_status_created_at_index ON public.order_fulfillments USING btree (branch_id, status, created_at)`
- `UNIQUE INDEX order_fulfillments_number_unique ON public.order_fulfillments USING btree (number)`

## `order_invoices`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | uuid | no |  |
| `order_id` | uuid | no |  |
| `number` | character varying(32) | no |  |
| `snapshot` | jsonb | no |  |
| `issued_at` | timestamp with time zone | no |  |
| `created_at` | timestamp with time zone | yes |  |
| `updated_at` | timestamp with time zone | yes |  |

Foreign keys: `order_id` → `orders` (on delete cascade)

Indexes:

- `UNIQUE INDEX order_invoices_number_unique ON public.order_invoices USING btree (number)`
- `UNIQUE INDEX order_invoices_order_id_unique ON public.order_invoices USING btree (order_id)`

## `order_items`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | uuid | no |  |
| `order_id` | uuid | no |  |
| `order_fulfillment_id` | uuid | no |  |
| `branch_id` | uuid | no |  |
| `product_id` | uuid | yes |  |
| `product_variant_id` | uuid | yes |  |
| `sku` | character varying(64) | no |  |
| `name_en` | character varying(255) | no |  |
| `name_ar` | character varying(255) | no |  |
| `variant_name_en` | character varying(255) | yes |  |
| `variant_name_ar` | character varying(255) | yes |  |
| `variant_attributes` | jsonb | yes |  |
| `image_path` | character varying(255) | yes |  |
| `unit_price` | numeric | no |  |
| `sale_price` | numeric | yes |  |
| `unit_final_price` | numeric | no |  |
| `quantity` | integer | no |  |
| `line_subtotal` | numeric | no |  |
| `discount_amount` | numeric | no | '0'::numeric |
| `tax_rate` | numeric | no | '0'::numeric |
| `tax_amount` | numeric | no | '0'::numeric |
| `line_total` | numeric | no |  |
| `quantity_cancelled` | integer | no | 0 |
| `quantity_returned` | integer | no | 0 |
| `quantity_handed_over` | integer | no | 0 |
| `price_source` | character varying(32) | yes |  |
| `created_at` | timestamp with time zone | yes |  |
| `updated_at` | timestamp with time zone | yes |  |

Foreign keys: `branch_id` → `branches` (on delete restrict); `order_fulfillment_id` → `order_fulfillments` (on delete cascade); `order_id` → `orders` (on delete cascade); `product_id` → `products` (on delete set null); `product_variant_id` → `product_variants` (on delete set null)

Checks: `CHECK (((quantity > 0) AND (quantity_cancelled >= 0) AND (quantity_returned >= 0) AND (quantity_handed_over >= 0) AND ((quantity_cancelled + quantity_handed_over) <= quantity) AND (quantity_returned <= quantity_handed_over)))`

Indexes:

- `INDEX order_items_branch_id_index ON public.order_items USING btree (branch_id)`

## `order_notes`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | uuid | no |  |
| `order_id` | uuid | no |  |
| `order_fulfillment_id` | uuid | yes |  |
| `author_id` | uuid | yes |  |
| `body` | text | no |  |
| `is_internal` | boolean | no | true |
| `created_at` | timestamp with time zone | yes |  |
| `updated_at` | timestamp with time zone | yes |  |

Foreign keys: `author_id` → `users` (on delete set null); `order_fulfillment_id` → `order_fulfillments` (on delete cascade); `order_id` → `orders` (on delete cascade)

## `order_status_history`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | bigint | no | nextval('order_status_history_id_seq'::regclass) |
| `order_id` | uuid | no |  |
| `order_fulfillment_id` | uuid | yes |  |
| `kind` | character varying(16) | no |  |
| `from_status` | character varying(32) | yes |  |
| `to_status` | character varying(32) | no |  |
| `actor_id` | uuid | yes |  |
| `actor_type` | character varying(16) | yes |  |
| `reason` | character varying(500) | yes |  |
| `metadata` | jsonb | yes |  |
| `is_customer_visible` | boolean | no | true |
| `created_at` | timestamp with time zone | no | CURRENT_TIMESTAMP |

Foreign keys: `order_fulfillment_id` → `order_fulfillments` (on delete cascade); `order_id` → `orders` (on delete cascade)

Indexes:

- `INDEX order_status_history_order_id_created_at_index ON public.order_status_history USING btree (order_id, created_at)`

## `orders`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | uuid | no |  |
| `number` | character varying(24) | no |  |
| `user_id` | uuid | yes |  |
| `source` | character varying(16) | no | 'app'::character varying |
| `status` | character varying(32) | no | 'pending_payment'::character varying |
| `payment_status` | character varying(24) | no | 'pending'::character varying |
| `payment_method` | character varying(24) | no |  |
| `fulfillment_type` | character varying(16) | no |  |
| `currency` | character(3) | no | 'QAR'::bpchar |
| `subtotal` | numeric | no |  |
| `discount_total` | numeric | no | '0'::numeric |
| `delivery_fee_total` | numeric | no | '0'::numeric |
| `service_fee_total` | numeric | no | '0'::numeric |
| `tax_total` | numeric | no | '0'::numeric |
| `loyalty_discount` | numeric | no | '0'::numeric |
| `loyalty_points_redeemed` | integer | no | 0 |
| `grand_total` | numeric | no |  |
| `paid_total` | numeric | no | '0'::numeric |
| `refunded_total` | numeric | no | '0'::numeric |
| `customer_name` | character varying(255) | no |  |
| `customer_phone` | character varying(20) | yes |  |
| `customer_email` | character varying(255) | yes |  |
| `address_snapshot` | jsonb | yes |  |
| `fee_breakdown` | jsonb | yes |  |
| `tax_snapshot` | jsonb | yes |  |
| `locale` | character varying(5) | no | 'en'::character varying |
| `customer_notes` | text | yes |  |
| `idempotency_key` | character varying(128) | yes |  |
| `quote_hash` | character varying(64) | yes |  |
| `auction_winner_id` | uuid | yes |  |
| `placed_at` | timestamp with time zone | no |  |
| `confirmed_at` | timestamp with time zone | yes |  |
| `completed_at` | timestamp with time zone | yes |  |
| `cancelled_at` | timestamp with time zone | yes |  |
| `payment_due_at` | timestamp with time zone | yes |  |
| `created_at` | timestamp with time zone | yes |  |
| `updated_at` | timestamp with time zone | yes |  |

Foreign keys: `auction_winner_id` → `auction_winners` (on delete set null); `user_id` → `users` (on delete set null)

Checks: `CHECK (((subtotal >= (0)::numeric) AND (discount_total >= (0)::numeric) AND (delivery_fee_total >= (0)::numeric) AND (grand_total >= (0)::numeric) AND (paid_total >= (0)::numeric) AND (refunded_total >= (0)::numeric) AND (refunded_total <= paid_total)))`; `CHECK (((payment_method)::text = ANY ((ARRAY['sadad'::character varying, 'cod'::character varying, 'pay_at_pickup'::character varying])::text[])))`; `CHECK (((payment_status)::text = ANY ((ARRAY['pending'::character varying, 'processing'::character varying, 'authorized'::character varying, 'paid'::character varying, 'failed'::character varying, 'cancelled'::character varying, 'expired'::character varying, 'partially_refunded'::character varying, 'refunded'::character varying])::text[])))`; `CHECK (((status)::text = ANY ((ARRAY['pending_payment'::character varying, 'confirmed'::character varying, 'processing'::character varying, 'partially_fulfilled'::character varying, 'ready_for_pickup'::character varying, 'out_for_delivery'::character varying, 'completed'::character varying, 'cancellation_requested'::character varying, 'cancelled'::character varying, 'partially_cancelled'::character varying, 'returned'::character varying])::text[])))`

Indexes:

- `UNIQUE INDEX orders_number_unique ON public.orders USING btree (number)`
- `INDEX orders_payment_status_created_at_index ON public.orders USING btree (payment_status, created_at)`
- `INDEX orders_status_created_at_index ON public.orders USING btree (status, created_at)`
- `UNIQUE INDEX orders_user_id_idempotency_key_unique ON public.orders USING btree (user_id, idempotency_key)`
- `INDEX orders_user_id_status_created_at_index ON public.orders USING btree (user_id, status, created_at)`

## `otp_verifications`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | uuid | no |  |
| `phone` | character varying(20) | no |  |
| `purpose` | character varying(24) | no |  |
| `code_hash` | character varying(128) | no |  |
| `attempts` | smallint | no | '0'::smallint |
| `max_attempts` | smallint | no |  |
| `expires_at` | timestamp with time zone | no |  |
| `consumed_at` | timestamp with time zone | yes |  |
| `invalidated_at` | timestamp with time zone | yes |  |
| `ip` | character varying(45) | yes |  |
| `user_agent` | character varying(512) | yes |  |
| `created_at` | timestamp with time zone | yes |  |
| `updated_at` | timestamp with time zone | yes |  |

Indexes:

- `INDEX otp_verifications_phone_purpose_created_at_index ON public.otp_verifications USING btree (phone, purpose, created_at)`

## `password_reset_tokens`

| Column | Type | Null | Default |
|---|---|---|---|
| `email` | character varying(255) | no |  |
| `token` | character varying(255) | no |  |
| `created_at` | timestamp with time zone | yes |  |

## `payment_reconciliation_items`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | uuid | no |  |
| `payment_reconciliation_id` | uuid | no |  |
| `payment_id` | uuid | yes |  |
| `cash_collection_id` | uuid | yes |  |
| `expected_amount` | numeric | no |  |
| `actual_amount` | numeric | yes |  |
| `status` | character varying(16) | no |  |
| `note` | character varying(500) | yes |  |
| `created_at` | timestamp with time zone | yes |  |
| `updated_at` | timestamp with time zone | yes |  |

Foreign keys: `cash_collection_id` → `cash_collections` (on delete restrict); `payment_id` → `payments` (on delete restrict); `payment_reconciliation_id` → `payment_reconciliations` (on delete cascade)

## `payment_reconciliations`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | uuid | no |  |
| `number` | character varying(32) | no |  |
| `branch_id` | uuid | yes |  |
| `method` | character varying(24) | no |  |
| `period_start` | date | no |  |
| `period_end` | date | no |  |
| `status` | character varying(16) | no | 'draft'::character varying |
| `expected_total` | numeric | no | '0'::numeric |
| `actual_total` | numeric | no | '0'::numeric |
| `difference` | numeric | no | '0'::numeric |
| `notes` | text | yes |  |
| `prepared_by` | uuid | yes |  |
| `finalized_by` | uuid | yes |  |
| `finalized_at` | timestamp with time zone | yes |  |
| `created_at` | timestamp with time zone | yes |  |
| `updated_at` | timestamp with time zone | yes |  |

Foreign keys: `branch_id` → `branches` (on delete restrict); `finalized_by` → `users` (on delete set null); `prepared_by` → `users` (on delete set null)

Indexes:

- `UNIQUE INDEX payment_reconciliations_number_unique ON public.payment_reconciliations USING btree (number)`

## `payment_transactions`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | uuid | no |  |
| `payment_id` | uuid | no |  |
| `type` | character varying(24) | no |  |
| `gateway` | character varying(24) | no |  |
| `transaction_reference` | character varying(128) | yes |  |
| `status` | character varying(24) | no |  |
| `amount` | numeric | yes |  |
| `request` | jsonb | yes |  |
| `response` | jsonb | yes |  |
| `error` | character varying(500) | yes |  |
| `created_at` | timestamp with time zone | no | CURRENT_TIMESTAMP |

Foreign keys: `payment_id` → `payments` (on delete restrict)

Indexes:

- `INDEX payment_transactions_payment_id_created_at_index ON public.payment_transactions USING btree (payment_id, created_at)`
- `UNIQUE INDEX payment_transactions_reference_unique ON public.payment_transactions USING btree (gateway, transaction_reference, type) WHERE (transaction_reference IS NOT NULL)`

## `payment_webhook_events`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | uuid | no |  |
| `provider` | character varying(24) | no |  |
| `provider_event_id` | character varying(191) | no |  |
| `payload_hash` | character varying(64) | no |  |
| `payload` | jsonb | no |  |
| `signature_valid` | boolean | no |  |
| `status` | character varying(16) | no | 'received'::character varying |
| `payment_id` | uuid | yes |  |
| `error` | character varying(500) | yes |  |
| `ip` | character varying(45) | yes |  |
| `processed_at` | timestamp with time zone | yes |  |
| `created_at` | timestamp with time zone | yes |  |
| `updated_at` | timestamp with time zone | yes |  |

Indexes:

- `UNIQUE INDEX payment_webhook_events_provider_provider_event_id_unique ON public.payment_webhook_events USING btree (provider, provider_event_id)`

## `payments`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | uuid | no |  |
| `payable_type` | character varying(48) | no |  |
| `payable_id` | uuid | no |  |
| `user_id` | uuid | yes |  |
| `order_id` | uuid | yes |  |
| `method` | character varying(24) | no |  |
| `provider` | character varying(24) | no |  |
| `status` | character varying(24) | no | 'pending'::character varying |
| `currency` | character(3) | no | 'QAR'::bpchar |
| `amount` | numeric | no |  |
| `captured_amount` | numeric | no | '0'::numeric |
| `refunded_amount` | numeric | no | '0'::numeric |
| `provider_reference` | character varying(128) | yes |  |
| `idempotency_key` | character varying(128) | yes |  |
| `attempt` | smallint | no | '1'::smallint |
| `expires_at` | timestamp with time zone | yes |  |
| `paid_at` | timestamp with time zone | yes |  |
| `failed_at` | timestamp with time zone | yes |  |
| `failure_reason` | character varying(500) | yes |  |
| `metadata` | jsonb | yes |  |
| `lock_version` | integer | no | 0 |
| `created_at` | timestamp with time zone | yes |  |
| `updated_at` | timestamp with time zone | yes |  |

Foreign keys: `order_id` → `orders` (on delete restrict); `user_id` → `users` (on delete set null)

Checks: `CHECK (((amount >= (0)::numeric) AND (captured_amount >= (0)::numeric) AND (refunded_amount >= (0)::numeric) AND (refunded_amount <= captured_amount) AND (captured_amount <= amount)))`; `CHECK (((status)::text = ANY ((ARRAY['pending'::character varying, 'processing'::character varying, 'authorized'::character varying, 'paid'::character varying, 'failed'::character varying, 'cancelled'::character varying, 'expired'::character varying, 'partially_refunded'::character varying, 'refunded'::character varying])::text[])))`

Indexes:

- `UNIQUE INDEX payments_idempotency_key_unique ON public.payments USING btree (idempotency_key)`
- `INDEX payments_method_status_index ON public.payments USING btree (method, status)`
- `INDEX payments_payable_type_payable_id_index ON public.payments USING btree (payable_type, payable_id)`
- `UNIQUE INDEX payments_provider_reference_unique ON public.payments USING btree (provider, provider_reference) WHERE (provider_reference IS NOT NULL)`
- `INDEX payments_status_created_at_index ON public.payments USING btree (status, created_at)`

## `permissions`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | bigint | no | nextval('permissions_id_seq'::regclass) |
| `key` | character varying(96) | no |  |
| `module` | character varying(48) | no |  |
| `action` | character varying(24) | no |  |
| `name_en` | character varying(255) | no |  |
| `name_ar` | character varying(255) | no |  |
| `created_at` | timestamp with time zone | yes |  |
| `updated_at` | timestamp with time zone | yes |  |

Indexes:

- `UNIQUE INDEX permissions_key_unique ON public.permissions USING btree (key)`
- `INDEX permissions_module_index ON public.permissions USING btree (module)`

## `personal_access_tokens`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | bigint | no | nextval('personal_access_tokens_id_seq'::regclass) |
| `tokenable_type` | character varying(255) | no |  |
| `tokenable_id` | uuid | no |  |
| `name` | text | no |  |
| `token` | character varying(64) | no |  |
| `abilities` | text | yes |  |
| `last_used_at` | timestamp without time zone | yes |  |
| `expires_at` | timestamp without time zone | yes |  |
| `created_at` | timestamp without time zone | yes |  |
| `updated_at` | timestamp without time zone | yes |  |

Indexes:

- `INDEX personal_access_tokens_expires_at_index ON public.personal_access_tokens USING btree (expires_at)`
- `UNIQUE INDEX personal_access_tokens_token_unique ON public.personal_access_tokens USING btree (token)`
- `INDEX personal_access_tokens_tokenable_type_tokenable_id_index ON public.personal_access_tokens USING btree (tokenable_type, tokenable_id)`

## `pickup_reservations`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | uuid | no |  |
| `order_fulfillment_id` | uuid | no |  |
| `branch_id` | uuid | no |  |
| `delivery_slot_id` | uuid | yes |  |
| `slot_date` | date | yes |  |
| `status` | character varying(24) | no | 'pending'::character varying |
| `token_hash` | character varying(64) | yes |  |
| `token_expires_at` | timestamp with time zone | yes |  |
| `token_used_at` | timestamp with time zone | yes |  |
| `alternate_collector_name` | character varying(255) | yes |  |
| `alternate_collector_phone` | character varying(20) | yes |  |
| `ready_at` | timestamp with time zone | yes |  |
| `collected_at` | timestamp with time zone | yes |  |
| `handed_over_by` | uuid | yes |  |
| `created_at` | timestamp with time zone | yes |  |
| `updated_at` | timestamp with time zone | yes |  |

Foreign keys: `branch_id` → `branches` (on delete restrict); `delivery_slot_id` → `delivery_slots` (on delete set null); `handed_over_by` → `users` (on delete set null); `order_fulfillment_id` → `order_fulfillments` (on delete cascade)

Indexes:

- `INDEX pickup_reservations_branch_id_status_index ON public.pickup_reservations USING btree (branch_id, status)`
- `UNIQUE INDEX pickup_reservations_order_fulfillment_id_unique ON public.pickup_reservations USING btree (order_fulfillment_id)`
- `UNIQUE INDEX pickup_reservations_token_hash_unique ON public.pickup_reservations USING btree (token_hash)`

## `pickup_verifications`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | uuid | no |  |
| `pickup_reservation_id` | uuid | yes |  |
| `verified_by` | uuid | no |  |
| `branch_id` | uuid | no |  |
| `result` | character varying(24) | no |  |
| `collector_name` | character varying(255) | yes |  |
| `is_alternate_collector` | boolean | no | false |
| `items_handed_over` | jsonb | yes |  |
| `ip` | character varying(45) | yes |  |
| `created_at` | timestamp with time zone | no | CURRENT_TIMESTAMP |

Foreign keys: `branch_id` → `branches` (on delete restrict); `pickup_reservation_id` → `pickup_reservations` (on delete cascade); `verified_by` → `users` (on delete restrict)

## `product_attribute_values`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | bigint | no | nextval('product_attribute_values_id_seq'::regclass) |
| `product_attribute_id` | bigint | no |  |
| `code` | character varying(64) | no |  |
| `value_en` | character varying(255) | no |  |
| `value_ar` | character varying(255) | no |  |
| `color_hex` | character varying(9) | yes |  |
| `sort_order` | integer | no | 0 |
| `created_at` | timestamp with time zone | yes |  |
| `updated_at` | timestamp with time zone | yes |  |

Foreign keys: `product_attribute_id` → `product_attributes` (on delete cascade)

Indexes:

- `UNIQUE INDEX product_attribute_values_product_attribute_id_code_unique ON public.product_attribute_values USING btree (product_attribute_id, code)`

## `product_attributes`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | bigint | no | nextval('product_attributes_id_seq'::regclass) |
| `code` | character varying(48) | no |  |
| `name_en` | character varying(255) | no |  |
| `name_ar` | character varying(255) | no |  |
| `type` | character varying(16) | no | 'select'::character varying |
| `is_filterable` | boolean | no | true |
| `is_variant_axis` | boolean | no | true |
| `sort_order` | integer | no | 0 |
| `created_at` | timestamp with time zone | yes |  |
| `updated_at` | timestamp with time zone | yes |  |

Indexes:

- `UNIQUE INDEX product_attributes_code_unique ON public.product_attributes USING btree (code)`

## `product_branch_settings`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | uuid | no |  |
| `product_id` | uuid | no |  |
| `branch_id` | uuid | no |  |
| `is_enabled` | boolean | no | true |
| `is_visible` | boolean | no | true |
| `price` | numeric | yes |  |
| `sale_price` | numeric | yes |  |
| `sale_starts_at` | timestamp with time zone | yes |  |
| `sale_ends_at` | timestamp with time zone | yes |  |
| `updated_by` | uuid | yes |  |
| `created_at` | timestamp with time zone | yes |  |
| `updated_at` | timestamp with time zone | yes |  |

Foreign keys: `branch_id` → `branches` (on delete cascade); `product_id` → `products` (on delete cascade); `updated_by` → `users` (on delete set null)

Checks: `CHECK ((((price IS NULL) OR (price >= (0)::numeric)) AND ((sale_price IS NULL) OR (sale_price >= (0)::numeric))))`

Indexes:

- `INDEX product_branch_settings_branch_id_index ON public.product_branch_settings USING btree (branch_id)`
- `UNIQUE INDEX product_branch_settings_product_id_branch_id_unique ON public.product_branch_settings USING btree (product_id, branch_id)`

## `product_images`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | uuid | no |  |
| `product_id` | uuid | no |  |
| `product_variant_id` | uuid | yes |  |
| `disk` | character varying(32) | no |  |
| `path` | character varying(255) | no |  |
| `variants` | jsonb | yes |  |
| `alt_en` | character varying(255) | yes |  |
| `alt_ar` | character varying(255) | yes |  |
| `width` | integer | yes |  |
| `height` | integer | yes |  |
| `sort_order` | integer | no | 0 |
| `created_at` | timestamp with time zone | yes |  |
| `updated_at` | timestamp with time zone | yes |  |

Foreign keys: `product_id` → `products` (on delete cascade); `product_variant_id` → `product_variants` (on delete set null)

Indexes:

- `INDEX product_images_product_id_sort_order_index ON public.product_images USING btree (product_id, sort_order)`

## `product_price_history`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | bigint | no | nextval('product_price_history_id_seq'::regclass) |
| `product_id` | uuid | no |  |
| `product_variant_id` | uuid | yes |  |
| `branch_id` | uuid | yes |  |
| `old_price` | numeric | yes |  |
| `new_price` | numeric | yes |  |
| `old_sale_price` | numeric | yes |  |
| `new_sale_price` | numeric | yes |  |
| `sale_starts_at` | timestamp with time zone | yes |  |
| `sale_ends_at` | timestamp with time zone | yes |  |
| `reason` | character varying(255) | yes |  |
| `changed_by` | uuid | yes |  |
| `created_at` | timestamp with time zone | no | CURRENT_TIMESTAMP |

Foreign keys: `branch_id` → `branches` (on delete cascade); `changed_by` → `users` (on delete set null); `product_id` → `products` (on delete cascade); `product_variant_id` → `product_variants` (on delete cascade)

Indexes:

- `INDEX product_price_history_product_id_created_at_index ON public.product_price_history USING btree (product_id, created_at)`

## `product_questions`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | uuid | no |  |
| `product_id` | uuid | no |  |
| `user_id` | uuid | no |  |
| `question` | text | no |  |
| `answer` | text | yes |  |
| `answered_by` | uuid | yes |  |
| `answered_at` | timestamp with time zone | yes |  |
| `status` | character varying(16) | no | 'pending'::character varying |
| `created_at` | timestamp with time zone | yes |  |
| `updated_at` | timestamp with time zone | yes |  |

Foreign keys: `answered_by` → `users` (on delete set null); `product_id` → `products` (on delete cascade); `user_id` → `users` (on delete cascade)

## `product_related`

| Column | Type | Null | Default |
|---|---|---|---|
| `product_id` | uuid | no |  |
| `related_product_id` | uuid | no |  |
| `type` | character varying(16) | no | 'related'::character varying |
| `sort_order` | integer | no | 0 |

Foreign keys: `product_id` → `products` (on delete cascade); `related_product_id` → `products` (on delete cascade)

Checks: `CHECK ((product_id <> related_product_id))`

## `product_translations`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | uuid | no |  |
| `product_id` | uuid | no |  |
| `locale` | character varying(5) | no |  |
| `name` | character varying(255) | no |  |
| `slug` | character varying(200) | no |  |
| `short_description` | character varying(1000) | yes |  |
| `description` | text | yes |  |
| `material` | character varying(255) | yes |  |
| `seo_title` | character varying(255) | yes |  |
| `seo_description` | character varying(500) | yes |  |
| `search_keywords` | character varying(1000) | yes |  |
| `search_text` | text | no | ''::text |
| `created_at` | timestamp with time zone | yes |  |
| `updated_at` | timestamp with time zone | yes |  |

Foreign keys: `product_id` → `products` (on delete cascade)

Indexes:

- `UNIQUE INDEX product_translations_locale_slug_unique ON public.product_translations USING btree (locale, slug)`
- `UNIQUE INDEX product_translations_product_id_locale_unique ON public.product_translations USING btree (product_id, locale)`
- `INDEX product_translations_search_trgm ON public.product_translations USING gin (search_text gin_trgm_ops)`

## `product_variant_branch_settings`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | uuid | no |  |
| `product_variant_id` | uuid | no |  |
| `branch_id` | uuid | no |  |
| `is_enabled` | boolean | no | true |
| `price` | numeric | yes |  |
| `sale_price` | numeric | yes |  |
| `sale_starts_at` | timestamp with time zone | yes |  |
| `sale_ends_at` | timestamp with time zone | yes |  |
| `updated_by` | uuid | yes |  |
| `created_at` | timestamp with time zone | yes |  |
| `updated_at` | timestamp with time zone | yes |  |

Foreign keys: `branch_id` → `branches` (on delete cascade); `product_variant_id` → `product_variants` (on delete cascade); `updated_by` → `users` (on delete set null)

Checks: `CHECK ((((price IS NULL) OR (price >= (0)::numeric)) AND ((sale_price IS NULL) OR (sale_price >= (0)::numeric))))`

Indexes:

- `INDEX product_variant_branch_settings_branch_id_index ON public.product_variant_branch_settings USING btree (branch_id)`
- `UNIQUE INDEX product_variant_branch_settings_product_variant_id_branch_id_un ON public.product_variant_branch_settings USING btree (product_variant_id, branch_id)`

## `product_variant_values`

| Column | Type | Null | Default |
|---|---|---|---|
| `product_variant_id` | uuid | no |  |
| `product_attribute_value_id` | bigint | no |  |

Foreign keys: `product_attribute_value_id` → `product_attribute_values` (on delete restrict); `product_variant_id` → `product_variants` (on delete cascade)

## `product_variants`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | uuid | no |  |
| `product_id` | uuid | no |  |
| `sku` | character varying(64) | no |  |
| `barcode` | character varying(64) | yes |  |
| `name_en` | character varying(255) | yes |  |
| `name_ar` | character varying(255) | yes |  |
| `price` | numeric | yes |  |
| `sale_price` | numeric | yes |  |
| `sale_starts_at` | timestamp with time zone | yes |  |
| `sale_ends_at` | timestamp with time zone | yes |  |
| `cost_price` | numeric | yes |  |
| `weight` | numeric | yes |  |
| `length` | numeric | yes |  |
| `width` | numeric | yes |  |
| `height` | numeric | yes |  |
| `status` | character varying(16) | no | 'active'::character varying |
| `is_default` | boolean | no | false |
| `sort_order` | integer | no | 0 |
| `created_at` | timestamp with time zone | yes |  |
| `updated_at` | timestamp with time zone | yes |  |

Foreign keys: `product_id` → `products` (on delete cascade)

Checks: `CHECK ((((price IS NULL) OR (price >= (0)::numeric)) AND ((sale_price IS NULL) OR (sale_price >= (0)::numeric))))`

Indexes:

- `UNIQUE INDEX product_variants_barcode_unique ON public.product_variants USING btree (barcode)`
- `UNIQUE INDEX product_variants_one_default ON public.product_variants USING btree (product_id) WHERE is_default`
- `INDEX product_variants_product_id_status_index ON public.product_variants USING btree (product_id, status)`
- `UNIQUE INDEX product_variants_sku_unique ON public.product_variants USING btree (sku)`

## `product_videos`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | uuid | no |  |
| `product_id` | uuid | no |  |
| `source` | character varying(16) | no |  |
| `disk` | character varying(32) | yes |  |
| `path` | character varying(255) | yes |  |
| `url` | character varying(255) | yes |  |
| `title_en` | character varying(255) | yes |  |
| `title_ar` | character varying(255) | yes |  |
| `sort_order` | integer | no | 0 |
| `created_at` | timestamp with time zone | yes |  |
| `updated_at` | timestamp with time zone | yes |  |

Foreign keys: `product_id` → `products` (on delete cascade)

## `product_warranties`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | uuid | no |  |
| `product_id` | uuid | no |  |
| `duration_months` | smallint | no |  |
| `provider` | character varying(255) | yes |  |
| `terms_en` | text | yes |  |
| `terms_ar` | text | yes |  |
| `created_at` | timestamp with time zone | yes |  |
| `updated_at` | timestamp with time zone | yes |  |

Foreign keys: `product_id` → `products` (on delete cascade)

Indexes:

- `UNIQUE INDEX product_warranties_product_id_unique ON public.product_warranties USING btree (product_id)`

## `products`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | uuid | no |  |
| `sku` | character varying(64) | no |  |
| `barcode` | character varying(64) | yes |  |
| `category_id` | uuid | yes |  |
| `brand_id` | uuid | yes |  |
| `status` | character varying(16) | no | 'draft'::character varying |
| `has_variants` | boolean | no | false |
| `currency` | character(3) | no | 'QAR'::bpchar |
| `price` | numeric | no |  |
| `sale_price` | numeric | yes |  |
| `sale_starts_at` | timestamp with time zone | yes |  |
| `sale_ends_at` | timestamp with time zone | yes |  |
| `allow_sale_above_price` | boolean | no | false |
| `cost_price` | numeric | yes |  |
| `weight` | numeric | yes |  |
| `weight_unit` | character varying(4) | no | 'kg'::character varying |
| `length` | numeric | yes |  |
| `width` | numeric | yes |  |
| `height` | numeric | yes |  |
| `dimension_unit` | character varying(4) | no | 'cm'::character varying |
| `is_featured` | boolean | no | false |
| `is_bestseller` | boolean | no | false |
| `is_new_arrival` | boolean | no | false |
| `requires_assembly` | boolean | no | false |
| `assembly_service_available` | boolean | no | false |
| `is_oversized` | boolean | no | false |
| `available_in_all_branches` | boolean | no | true |
| `tags` | jsonb | yes |  |
| `specifications` | jsonb | yes |  |
| `rating_average` | numeric | no | '0'::numeric |
| `rating_count` | integer | no | 0 |
| `sales_count` | bigint | no | '0'::bigint |
| `published_at` | timestamp with time zone | yes |  |
| `created_by` | uuid | yes |  |
| `updated_by` | uuid | yes |  |
| `created_at` | timestamp with time zone | yes |  |
| `updated_at` | timestamp with time zone | yes |  |

Foreign keys: `brand_id` → `brands` (on delete set null); `category_id` → `categories` (on delete set null); `created_by` → `users` (on delete set null); `updated_by` → `users` (on delete set null)

Checks: `CHECK (((price >= (0)::numeric) AND ((sale_price IS NULL) OR (sale_price >= (0)::numeric))))`; `CHECK (((sale_price IS NULL) OR allow_sale_above_price OR (sale_price <= price)))`; `CHECK (((sale_starts_at IS NULL) OR (sale_ends_at IS NULL) OR (sale_ends_at > sale_starts_at)))`; `CHECK (((status)::text = ANY ((ARRAY['draft'::character varying, 'active'::character varying, 'inactive'::character varying, 'archived'::character varying])::text[])))`

Indexes:

- `UNIQUE INDEX products_barcode_unique ON public.products USING btree (barcode)`
- `INDEX products_is_featured_status_index ON public.products USING btree (is_featured, status)`
- `UNIQUE INDEX products_sku_unique ON public.products USING btree (sku)`
- `INDEX products_status_brand_id_index ON public.products USING btree (status, brand_id)`
- `INDEX products_status_category_id_index ON public.products USING btree (status, category_id)`

## `promotion_branches`

| Column | Type | Null | Default |
|---|---|---|---|
| `promotion_id` | uuid | no |  |
| `branch_id` | uuid | no |  |

Foreign keys: `branch_id` → `branches` (on delete cascade); `promotion_id` → `promotions` (on delete cascade)

## `promotion_categories`

| Column | Type | Null | Default |
|---|---|---|---|
| `promotion_id` | uuid | no |  |
| `category_id` | uuid | no |  |

Foreign keys: `category_id` → `categories` (on delete cascade); `promotion_id` → `promotions` (on delete cascade)

## `promotion_products`

| Column | Type | Null | Default |
|---|---|---|---|
| `promotion_id` | uuid | no |  |
| `product_id` | uuid | no |  |

Foreign keys: `product_id` → `products` (on delete cascade); `promotion_id` → `promotions` (on delete cascade)

## `promotions`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | uuid | no |  |
| `name_en` | character varying(255) | no |  |
| `name_ar` | character varying(255) | no |  |
| `description_en` | text | yes |  |
| `description_ar` | text | yes |  |
| `type` | character varying(24) | no |  |
| `value` | numeric | no | '0'::numeric |
| `scope` | character varying(16) | no | 'order'::character varying |
| `min_basket_amount` | numeric | yes |  |
| `max_discount_amount` | numeric | yes |  |
| `buy_quantity` | smallint | yes |  |
| `get_quantity` | smallint | yes |  |
| `get_discount_percent` | numeric | yes |  |
| `first_order_only` | boolean | no | false |
| `customer_group_id` | uuid | yes |  |
| `requires_coupon` | boolean | no | true |
| `is_stackable` | boolean | no | false |
| `priority` | smallint | no | '100'::smallint |
| `usage_limit` | integer | yes |  |
| `per_customer_limit` | integer | yes |  |
| `times_used` | integer | no | 0 |
| `starts_at` | timestamp with time zone | yes |  |
| `ends_at` | timestamp with time zone | yes |  |
| `is_active` | boolean | no | true |
| `applies_to_auctions` | boolean | no | false |
| `created_by` | uuid | yes |  |
| `created_at` | timestamp with time zone | yes |  |
| `updated_at` | timestamp with time zone | yes |  |

Foreign keys: `created_by` → `users` (on delete set null); `customer_group_id` → `customer_groups` (on delete set null)

Checks: `CHECK (((type)::text = ANY ((ARRAY['percentage'::character varying, 'fixed'::character varying, 'free_delivery'::character varying, 'bogo'::character varying, 'bundle'::character varying, 'flash_sale'::character varying])::text[])))`; `CHECK (((value >= (0)::numeric) AND (((type)::text <> ALL ((ARRAY['percentage'::character varying, 'flash_sale'::character varying])::text[])) OR (value <= (100)::numeric))))`

Indexes:

- `INDEX promotions_is_active_requires_coupon_starts_at_ends_at_index ON public.promotions USING btree (is_active, requires_coupon, starts_at, ends_at)`

## `purchase_order_items`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | uuid | no |  |
| `purchase_order_id` | uuid | no |  |
| `product_variant_id` | uuid | no |  |
| `quantity_ordered` | integer | no |  |
| `quantity_received` | integer | no | 0 |
| `unit_cost` | numeric | no |  |
| `line_total` | numeric | no |  |
| `created_at` | timestamp with time zone | yes |  |
| `updated_at` | timestamp with time zone | yes |  |

Foreign keys: `product_variant_id` → `product_variants` (on delete restrict); `purchase_order_id` → `purchase_orders` (on delete cascade)

Checks: `CHECK (((quantity_ordered > 0) AND (quantity_received >= 0) AND (unit_cost >= (0)::numeric)))`

Indexes:

- `UNIQUE INDEX purchase_order_items_purchase_order_id_product_variant_id_uniqu ON public.purchase_order_items USING btree (purchase_order_id, product_variant_id)`

## `purchase_orders`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | uuid | no |  |
| `number` | character varying(32) | no |  |
| `supplier_id` | uuid | no |  |
| `branch_id` | uuid | no |  |
| `status` | character varying(24) | no | 'draft'::character varying |
| `currency` | character(3) | no | 'QAR'::bpchar |
| `subtotal` | numeric | no | '0'::numeric |
| `supplier_reference` | character varying(255) | yes |  |
| `expected_at` | date | yes |  |
| `notes` | text | yes |  |
| `created_by` | uuid | yes |  |
| `approved_by` | uuid | yes |  |
| `submitted_at` | timestamp with time zone | yes |  |
| `approved_at` | timestamp with time zone | yes |  |
| `closed_at` | timestamp with time zone | yes |  |
| `created_at` | timestamp with time zone | yes |  |
| `updated_at` | timestamp with time zone | yes |  |

Foreign keys: `approved_by` → `users` (on delete set null); `branch_id` → `branches` (on delete restrict); `created_by` → `users` (on delete set null); `supplier_id` → `suppliers` (on delete restrict)

Checks: `CHECK (((status)::text = ANY ((ARRAY['draft'::character varying, 'pending_approval'::character varying, 'approved'::character varying, 'partially_received'::character varying, 'received'::character varying, 'closed'::character varying, 'cancelled'::character varying, 'rejected'::character varying])::text[])))`

Indexes:

- `INDEX purchase_orders_branch_id_status_index ON public.purchase_orders USING btree (branch_id, status)`
- `UNIQUE INDEX purchase_orders_number_unique ON public.purchase_orders USING btree (number)`

## `referrals`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | uuid | no |  |
| `referrer_id` | uuid | no |  |
| `referee_id` | uuid | no |  |
| `code` | character varying(16) | no |  |
| `status` | character varying(16) | no | 'pending'::character varying |
| `qualifying_order_id` | uuid | yes |  |
| `rejection_reason` | character varying(255) | yes |  |
| `rewarded_at` | timestamp with time zone | yes |  |
| `created_at` | timestamp with time zone | yes |  |
| `updated_at` | timestamp with time zone | yes |  |

Foreign keys: `referee_id` → `users` (on delete cascade); `referrer_id` → `users` (on delete cascade)

Checks: `CHECK ((referrer_id <> referee_id))`

Indexes:

- `UNIQUE INDEX referrals_referee_id_unique ON public.referrals USING btree (referee_id)`

## `refunds`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | uuid | no |  |
| `number` | character varying(32) | no |  |
| `payment_id` | uuid | no |  |
| `order_id` | uuid | yes |  |
| `amount` | numeric | no |  |
| `currency` | character(3) | no | 'QAR'::bpchar |
| `reason_code` | character varying(48) | no |  |
| `reason` | text | yes |  |
| `method` | character varying(24) | no |  |
| `status` | character varying(16) | no | 'requested'::character varying |
| `provider_reference` | character varying(128) | yes |  |
| `idempotency_key` | character varying(128) | yes |  |
| `requested_by` | uuid | yes |  |
| `approved_by` | uuid | yes |  |
| `processed_by` | uuid | yes |  |
| `approved_at` | timestamp with time zone | yes |  |
| `processed_at` | timestamp with time zone | yes |  |
| `failure_reason` | character varying(500) | yes |  |
| `created_at` | timestamp with time zone | yes |  |
| `updated_at` | timestamp with time zone | yes |  |

Foreign keys: `approved_by` → `users` (on delete set null); `order_id` → `orders` (on delete restrict); `payment_id` → `payments` (on delete restrict); `processed_by` → `users` (on delete set null); `requested_by` → `users` (on delete set null)

Checks: `CHECK ((amount > (0)::numeric))`

Indexes:

- `UNIQUE INDEX refunds_idempotency_key_unique ON public.refunds USING btree (idempotency_key)`
- `UNIQUE INDEX refunds_number_unique ON public.refunds USING btree (number)`
- `INDEX refunds_status_created_at_index ON public.refunds USING btree (status, created_at)`

## `return_requests`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | uuid | no |  |
| `number` | character varying(24) | no |  |
| `order_id` | uuid | no |  |
| `user_id` | uuid | no |  |
| `branch_id` | uuid | no |  |
| `status` | character varying(16) | no | 'requested'::character varying |
| `reason_code` | character varying(48) | no |  |
| `reason` | text | yes |  |
| `items` | jsonb | no |  |
| `decided_by` | uuid | yes |  |
| `decided_at` | timestamp with time zone | yes |  |
| `received_at` | timestamp with time zone | yes |  |
| `refund_id` | uuid | yes |  |
| `created_at` | timestamp with time zone | yes |  |
| `updated_at` | timestamp with time zone | yes |  |

Foreign keys: `branch_id` → `branches` (on delete restrict); `decided_by` → `users` (on delete set null); `order_id` → `orders` (on delete cascade); `refund_id` → `refunds` (on delete set null); `user_id` → `users` (on delete cascade)

Indexes:

- `UNIQUE INDEX return_requests_number_unique ON public.return_requests USING btree (number)`

## `review_media`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | uuid | no |  |
| `review_id` | uuid | no |  |
| `disk` | character varying(32) | no |  |
| `path` | character varying(255) | no |  |
| `created_at` | timestamp with time zone | yes |  |
| `updated_at` | timestamp with time zone | yes |  |

Foreign keys: `review_id` → `reviews` (on delete cascade)

## `review_reports`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | uuid | no |  |
| `review_id` | uuid | no |  |
| `user_id` | uuid | no |  |
| `reason` | character varying(48) | no |  |
| `created_at` | timestamp with time zone | yes |  |
| `updated_at` | timestamp with time zone | yes |  |

Foreign keys: `review_id` → `reviews` (on delete cascade); `user_id` → `users` (on delete cascade)

Indexes:

- `UNIQUE INDEX review_reports_review_id_user_id_unique ON public.review_reports USING btree (review_id, user_id)`

## `reviews`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | uuid | no |  |
| `product_id` | uuid | no |  |
| `user_id` | uuid | no |  |
| `order_item_id` | uuid | yes |  |
| `rating` | smallint | no |  |
| `title` | character varying(255) | yes |  |
| `body` | text | yes |  |
| `locale` | character varying(5) | no | 'en'::character varying |
| `is_verified_purchase` | boolean | no | false |
| `status` | character varying(16) | no | 'pending'::character varying |
| `report_count` | integer | no | 0 |
| `moderated_by` | uuid | yes |  |
| `moderated_at` | timestamp with time zone | yes |  |
| `moderation_note` | character varying(500) | yes |  |
| `created_at` | timestamp with time zone | yes |  |
| `updated_at` | timestamp with time zone | yes |  |

Foreign keys: `moderated_by` → `users` (on delete set null); `order_item_id` → `order_items` (on delete set null); `product_id` → `products` (on delete cascade); `user_id` → `users` (on delete cascade)

Checks: `CHECK (((rating >= 1) AND (rating <= 5)))`

Indexes:

- `INDEX reviews_product_id_status_index ON public.reviews USING btree (product_id, status)`
- `UNIQUE INDEX reviews_product_id_user_id_unique ON public.reviews USING btree (product_id, user_id)`

## `role_permissions`

| Column | Type | Null | Default |
|---|---|---|---|
| `role_id` | bigint | no |  |
| `permission_id` | bigint | no |  |

Foreign keys: `permission_id` → `permissions` (on delete cascade); `role_id` → `roles` (on delete cascade)

## `roles`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | bigint | no | nextval('roles_id_seq'::regclass) |
| `key` | character varying(64) | no |  |
| `name_en` | character varying(255) | no |  |
| `name_ar` | character varying(255) | no |  |
| `description` | text | yes |  |
| `scope` | character varying(16) | no | 'branch'::character varying |
| `is_system` | boolean | no | false |
| `created_at` | timestamp with time zone | yes |  |
| `updated_at` | timestamp with time zone | yes |  |

Checks: `CHECK (((scope)::text = ANY ((ARRAY['global'::character varying, 'branch'::character varying])::text[])))`

Indexes:

- `UNIQUE INDEX roles_key_unique ON public.roles USING btree (key)`

## `sessions`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | character varying(255) | no |  |
| `user_id` | uuid | yes |  |
| `ip_address` | character varying(45) | yes |  |
| `user_agent` | text | yes |  |
| `payload` | text | no |  |
| `last_activity` | integer | no |  |

Indexes:

- `INDEX sessions_last_activity_index ON public.sessions USING btree (last_activity)`
- `INDEX sessions_user_id_index ON public.sessions USING btree (user_id)`

## `stock_alerts`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | uuid | no |  |
| `user_id` | uuid | no |  |
| `product_variant_id` | uuid | no |  |
| `branch_id` | uuid | yes |  |
| `type` | character varying(16) | no |  |
| `reference_price` | numeric | yes |  |
| `notified_at` | timestamp with time zone | yes |  |
| `created_at` | timestamp with time zone | yes |  |
| `updated_at` | timestamp with time zone | yes |  |

Foreign keys: `branch_id` → `branches` (on delete cascade); `product_variant_id` → `product_variants` (on delete cascade); `user_id` → `users` (on delete cascade)

Indexes:

- `UNIQUE INDEX stock_alerts_user_id_product_variant_id_type_unique ON public.stock_alerts USING btree (user_id, product_variant_id, type)`

## `stock_movements`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | bigint | no | nextval('stock_movements_id_seq'::regclass) |
| `branch_id` | uuid | no |  |
| `product_variant_id` | uuid | no |  |
| `type` | character varying(32) | no |  |
| `quantity` | integer | no |  |
| `reserved_delta` | integer | no | 0 |
| `on_hand_after` | integer | no |  |
| `reserved_after` | integer | no |  |
| `unit_cost` | numeric | yes |  |
| `reference_type` | character varying(48) | yes |  |
| `reference_id` | character varying(64) | yes |  |
| `actor_id` | uuid | yes |  |
| `reason` | character varying(500) | yes |  |
| `reverses_movement_id` | bigint | yes |  |
| `created_at` | timestamp with time zone | no | CURRENT_TIMESTAMP |

Foreign keys: `branch_id` → `branches` (on delete restrict); `product_variant_id` → `product_variants` (on delete restrict)

Checks: `CHECK (((quantity <> 0) OR (reserved_delta <> 0)))`; `CHECK (((type)::text = ANY ((ARRAY['opening_balance'::character varying, 'purchase_receipt'::character varying, 'sale_deduction'::character varying, 'reservation'::character varying, 'reservation_release'::character varying, 'transfer_out'::character varying, 'transfer_in'::character varying, 'adjustment_in'::character varying, 'adjustment_out'::character varying, 'customer_return'::character varying, 'damaged'::character varying, 'lost'::character varying, 'correction'::character varying, 'reversal'::character varying, 'stocktake_correction'::character varying])::text[])))`

Indexes:

- `INDEX stock_movements_branch_id_product_variant_id_created_at_index ON public.stock_movements USING btree (branch_id, product_variant_id, created_at)`
- `INDEX stock_movements_reference_type_reference_id_index ON public.stock_movements USING btree (reference_type, reference_id)`
- `INDEX stock_movements_type_created_at_index ON public.stock_movements USING btree (type, created_at)`

## `stock_reservations`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | uuid | no |  |
| `branch_id` | uuid | no |  |
| `product_variant_id` | uuid | no |  |
| `quantity` | integer | no |  |
| `status` | character varying(16) | no | 'active'::character varying |
| `reference_type` | character varying(48) | no |  |
| `reference_id` | character varying(64) | no |  |
| `expires_at` | timestamp with time zone | yes |  |
| `consumed_at` | timestamp with time zone | yes |  |
| `released_at` | timestamp with time zone | yes |  |
| `release_reason` | character varying(64) | yes |  |
| `created_by` | uuid | yes |  |
| `created_at` | timestamp with time zone | yes |  |
| `updated_at` | timestamp with time zone | yes |  |

Foreign keys: `branch_id` → `branches` (on delete restrict); `product_variant_id` → `product_variants` (on delete restrict)

Checks: `CHECK ((quantity > 0))`; `CHECK (((status)::text = ANY ((ARRAY['active'::character varying, 'consumed'::character varying, 'released'::character varying, 'expired'::character varying])::text[])))`

Indexes:

- `INDEX stock_reservations_branch_id_product_variant_id_status_index ON public.stock_reservations USING btree (branch_id, product_variant_id, status)`
- `UNIQUE INDEX stock_reservations_reference_type_reference_id_unique ON public.stock_reservations USING btree (reference_type, reference_id)`
- `INDEX stock_reservations_status_expires_at_index ON public.stock_reservations USING btree (status, expires_at)`

## `stock_transfer_items`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | uuid | no |  |
| `stock_transfer_id` | uuid | no |  |
| `product_variant_id` | uuid | no |  |
| `quantity_requested` | integer | no |  |
| `quantity_approved` | integer | no | 0 |
| `quantity_dispatched` | integer | no | 0 |
| `quantity_received` | integer | no | 0 |
| `quantity_damaged` | integer | no | 0 |
| `discrepancy_note` | text | yes |  |
| `created_at` | timestamp with time zone | yes |  |
| `updated_at` | timestamp with time zone | yes |  |

Foreign keys: `product_variant_id` → `product_variants` (on delete restrict); `stock_transfer_id` → `stock_transfers` (on delete cascade)

Checks: `CHECK (((quantity_requested > 0) AND (quantity_approved >= 0) AND (quantity_approved <= quantity_requested) AND (quantity_dispatched >= 0) AND (quantity_dispatched <= quantity_approved) AND (quantity_received >= 0) AND (quantity_damaged >= 0) AND ((quantity_received + quantity_damaged) <= quantity_dispatched)))`

Indexes:

- `UNIQUE INDEX stock_transfer_items_stock_transfer_id_product_variant_id_uniqu ON public.stock_transfer_items USING btree (stock_transfer_id, product_variant_id)`

## `stock_transfers`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | uuid | no |  |
| `number` | character varying(32) | no |  |
| `source_branch_id` | uuid | no |  |
| `destination_branch_id` | uuid | no |  |
| `status` | character varying(24) | no | 'draft'::character varying |
| `notes` | text | yes |  |
| `requested_by` | uuid | yes |  |
| `approved_by` | uuid | yes |  |
| `dispatched_by` | uuid | yes |  |
| `received_by` | uuid | yes |  |
| `submitted_at` | timestamp with time zone | yes |  |
| `approved_at` | timestamp with time zone | yes |  |
| `dispatched_at` | timestamp with time zone | yes |  |
| `received_at` | timestamp with time zone | yes |  |
| `completed_at` | timestamp with time zone | yes |  |
| `cancelled_at` | timestamp with time zone | yes |  |
| `cancellation_reason` | character varying(255) | yes |  |
| `created_at` | timestamp with time zone | yes |  |
| `updated_at` | timestamp with time zone | yes |  |

Foreign keys: `approved_by` → `users` (on delete set null); `destination_branch_id` → `branches` (on delete restrict); `dispatched_by` → `users` (on delete set null); `received_by` → `users` (on delete set null); `requested_by` → `users` (on delete set null); `source_branch_id` → `branches` (on delete restrict)

Checks: `CHECK ((source_branch_id <> destination_branch_id))`; `CHECK (((status)::text = ANY ((ARRAY['draft'::character varying, 'pending_approval'::character varying, 'approved'::character varying, 'in_transit'::character varying, 'partially_received'::character varying, 'received'::character varying, 'completed'::character varying, 'rejected'::character varying, 'cancelled'::character varying])::text[])))`

Indexes:

- `INDEX stock_transfers_destination_branch_id_status_index ON public.stock_transfers USING btree (destination_branch_id, status)`
- `UNIQUE INDEX stock_transfers_number_unique ON public.stock_transfers USING btree (number)`
- `INDEX stock_transfers_source_branch_id_status_index ON public.stock_transfers USING btree (source_branch_id, status)`

## `stocktaking_items`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | uuid | no |  |
| `stocktaking_session_id` | uuid | no |  |
| `product_variant_id` | uuid | no |  |
| `expected_quantity` | integer | no |  |
| `counted_quantity` | integer | yes |  |
| `counted_by` | uuid | yes |  |
| `counted_at` | timestamp with time zone | yes |  |
| `created_at` | timestamp with time zone | yes |  |
| `updated_at` | timestamp with time zone | yes |  |

Foreign keys: `counted_by` → `users` (on delete set null); `product_variant_id` → `product_variants` (on delete restrict); `stocktaking_session_id` → `stocktaking_sessions` (on delete cascade)

Checks: `CHECK (((expected_quantity >= 0) AND ((counted_quantity IS NULL) OR (counted_quantity >= 0))))`

Indexes:

- `UNIQUE INDEX stocktaking_items_stocktaking_session_id_product_variant_id_uni ON public.stocktaking_items USING btree (stocktaking_session_id, product_variant_id)`

## `stocktaking_sessions`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | uuid | no |  |
| `number` | character varying(32) | no |  |
| `branch_id` | uuid | no |  |
| `status` | character varying(16) | no | 'counting'::character varying |
| `category_id` | uuid | yes |  |
| `notes` | text | yes |  |
| `started_by` | uuid | yes |  |
| `approved_by` | uuid | yes |  |
| `submitted_at` | timestamp with time zone | yes |  |
| `posted_at` | timestamp with time zone | yes |  |
| `created_at` | timestamp with time zone | yes |  |
| `updated_at` | timestamp with time zone | yes |  |

Foreign keys: `approved_by` → `users` (on delete set null); `branch_id` → `branches` (on delete restrict); `category_id` → `categories` (on delete set null); `started_by` → `users` (on delete set null)

Indexes:

- `UNIQUE INDEX stocktaking_one_open_per_branch ON public.stocktaking_sessions USING btree (branch_id) WHERE ((status)::text = ANY ((ARRAY['counting'::character varying, 'submitted'::character varying])::text[]))`
- `UNIQUE INDEX stocktaking_sessions_number_unique ON public.stocktaking_sessions USING btree (number)`

## `suppliers`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | uuid | no |  |
| `code` | character varying(32) | no |  |
| `name` | character varying(255) | no |  |
| `contact_name` | character varying(255) | yes |  |
| `phone` | character varying(32) | yes |  |
| `email` | character varying(255) | yes |  |
| `address` | character varying(255) | yes |  |
| `country` | character varying(2) | yes |  |
| `tax_number` | character varying(64) | yes |  |
| `payment_terms` | character varying(255) | yes |  |
| `currency` | character(3) | no | 'QAR'::bpchar |
| `status` | character varying(16) | no | 'active'::character varying |
| `notes` | text | yes |  |
| `created_at` | timestamp with time zone | yes |  |
| `updated_at` | timestamp with time zone | yes |  |

Indexes:

- `UNIQUE INDEX suppliers_code_unique ON public.suppliers USING btree (code)`

## `support_messages`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | uuid | no |  |
| `support_ticket_id` | uuid | no |  |
| `author_id` | uuid | yes |  |
| `author_type` | character varying(16) | no |  |
| `body` | text | no |  |
| `is_internal` | boolean | no | false |
| `attachments` | jsonb | yes |  |
| `created_at` | timestamp with time zone | yes |  |
| `updated_at` | timestamp with time zone | yes |  |

Foreign keys: `author_id` → `users` (on delete set null); `support_ticket_id` → `support_tickets` (on delete cascade)

## `support_tickets`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | uuid | no |  |
| `number` | character varying(24) | no |  |
| `user_id` | uuid | no |  |
| `order_id` | uuid | yes |  |
| `branch_id` | uuid | yes |  |
| `category` | character varying(32) | no |  |
| `subject` | character varying(255) | no |  |
| `priority` | character varying(16) | no | 'normal'::character varying |
| `status` | character varying(24) | no | 'open'::character varying |
| `assigned_to` | uuid | yes |  |
| `first_response_at` | timestamp with time zone | yes |  |
| `resolved_at` | timestamp with time zone | yes |  |
| `closed_at` | timestamp with time zone | yes |  |
| `created_at` | timestamp with time zone | yes |  |
| `updated_at` | timestamp with time zone | yes |  |

Foreign keys: `assigned_to` → `users` (on delete set null); `branch_id` → `branches` (on delete set null); `order_id` → `orders` (on delete set null); `user_id` → `users` (on delete cascade)

Indexes:

- `UNIQUE INDEX support_tickets_number_unique ON public.support_tickets USING btree (number)`
- `INDEX support_tickets_status_priority_created_at_index ON public.support_tickets USING btree (status, priority, created_at)`
- `INDEX support_tickets_user_id_created_at_index ON public.support_tickets USING btree (user_id, created_at)`

## `system_settings`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | bigint | no | nextval('system_settings_id_seq'::regclass) |
| `group` | character varying(48) | no |  |
| `key` | character varying(96) | no |  |
| `value` | jsonb | yes |  |
| `is_secret` | boolean | no | false |
| `updated_by` | uuid | yes |  |
| `created_at` | timestamp with time zone | yes |  |
| `updated_at` | timestamp with time zone | yes |  |

Foreign keys: `updated_by` → `users` (on delete set null)

Indexes:

- `UNIQUE INDEX system_settings_group_key_unique ON public.system_settings USING btree ("group", key)`

## `user_addresses`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | uuid | no |  |
| `user_id` | uuid | no |  |
| `label` | character varying(48) | yes |  |
| `recipient_name` | character varying(255) | no |  |
| `recipient_phone` | character varying(20) | no |  |
| `delivery_zone_id` | uuid | yes |  |
| `zone_number` | character varying(8) | yes |  |
| `street_number` | character varying(16) | yes |  |
| `building_number` | character varying(16) | yes |  |
| `unit_number` | character varying(16) | yes |  |
| `area` | character varying(255) | yes |  |
| `city` | character varying(255) | no | 'Doha'::character varying |
| `landmark` | character varying(255) | yes |  |
| `instructions` | text | yes |  |
| `latitude` | numeric | yes |  |
| `longitude` | numeric | yes |  |
| `is_default` | boolean | no | false |
| `created_at` | timestamp with time zone | yes |  |
| `updated_at` | timestamp with time zone | yes |  |
| `deleted_at` | timestamp with time zone | yes |  |

Foreign keys: `delivery_zone_id` → `delivery_zones` (on delete set null); `user_id` → `users` (on delete cascade)

Indexes:

- `UNIQUE INDEX user_addresses_one_default ON public.user_addresses USING btree (user_id) WHERE (is_default AND (deleted_at IS NULL))`

## `user_devices`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | uuid | no |  |
| `user_id` | uuid | no |  |
| `platform` | character varying(16) | no |  |
| `push_token` | character varying(512) | no |  |
| `device_id` | character varying(191) | yes |  |
| `app_version` | character varying(32) | yes |  |
| `locale` | character varying(5) | yes |  |
| `last_seen_at` | timestamp with time zone | yes |  |
| `disabled_at` | timestamp with time zone | yes |  |
| `created_at` | timestamp with time zone | yes |  |
| `updated_at` | timestamp with time zone | yes |  |

Foreign keys: `user_id` → `users` (on delete cascade)

Checks: `CHECK (((platform)::text = ANY ((ARRAY['android'::character varying, 'ios'::character varying, 'web'::character varying])::text[])))`

Indexes:

- `UNIQUE INDEX user_devices_push_token_unique ON public.user_devices USING btree (push_token)`
- `INDEX user_devices_user_id_disabled_at_index ON public.user_devices USING btree (user_id, disabled_at)`

## `user_roles`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | uuid | no |  |
| `user_id` | uuid | no |  |
| `role_id` | bigint | no |  |
| `branch_id` | uuid | yes |  |
| `assigned_by` | uuid | yes |  |
| `created_at` | timestamp with time zone | yes |  |
| `updated_at` | timestamp with time zone | yes |  |

Foreign keys: `assigned_by` → `users` (on delete set null); `branch_id` → `branches` (on delete cascade); `role_id` → `roles` (on delete cascade); `user_id` → `users` (on delete cascade)

Indexes:

- `UNIQUE INDEX user_roles_unique_branch ON public.user_roles USING btree (user_id, role_id, branch_id) WHERE (branch_id IS NOT NULL)`
- `UNIQUE INDEX user_roles_unique_global ON public.user_roles USING btree (user_id, role_id) WHERE (branch_id IS NULL)`
- `INDEX user_roles_user_id_branch_id_index ON public.user_roles USING btree (user_id, branch_id)`

## `user_sessions`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | uuid | no |  |
| `user_id` | uuid | no |  |
| `family_id` | uuid | no |  |
| `refresh_token_hash` | character varying(128) | no |  |
| `access_token_id` | bigint | yes |  |
| `device_name` | character varying(120) | yes |  |
| `platform` | character varying(16) | yes |  |
| `ip` | character varying(45) | yes |  |
| `user_agent` | character varying(512) | yes |  |
| `expires_at` | timestamp with time zone | no |  |
| `last_used_at` | timestamp with time zone | yes |  |
| `rotated_at` | timestamp with time zone | yes |  |
| `revoked_at` | timestamp with time zone | yes |  |
| `revoked_reason` | character varying(64) | yes |  |
| `created_at` | timestamp with time zone | yes |  |
| `updated_at` | timestamp with time zone | yes |  |

Foreign keys: `user_id` → `users` (on delete cascade)

Indexes:

- `INDEX user_sessions_family_id_index ON public.user_sessions USING btree (family_id)`
- `UNIQUE INDEX user_sessions_refresh_token_hash_unique ON public.user_sessions USING btree (refresh_token_hash)`
- `INDEX user_sessions_user_id_revoked_at_index ON public.user_sessions USING btree (user_id, revoked_at)`

## `users`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | uuid | no |  |
| `type` | character varying(16) | no | 'customer'::character varying |
| `name` | character varying(255) | no |  |
| `phone` | character varying(20) | yes |  |
| `email` | character varying(255) | yes |  |
| `phone_verified_at` | timestamp with time zone | yes |  |
| `email_verified_at` | timestamp with time zone | yes |  |
| `password` | character varying(255) | yes |  |
| `locale` | character varying(5) | no | 'en'::character varying |
| `status` | character varying(16) | no | 'active'::character varying |
| `is_super_admin` | boolean | no | false |
| `job_title` | character varying(255) | yes |  |
| `avatar_path` | character varying(255) | yes |  |
| `date_of_birth` | date | yes |  |
| `gender` | character varying(16) | yes |  |
| `referral_code` | character varying(16) | yes |  |
| `marketing_consent_at` | timestamp with time zone | yes |  |
| `two_factor_secret` | text | yes |  |
| `two_factor_recovery_codes` | text | yes |  |
| `two_factor_confirmed_at` | timestamp with time zone | yes |  |
| `last_login_at` | timestamp with time zone | yes |  |
| `last_login_ip` | character varying(45) | yes |  |
| `remember_token` | character varying(100) | yes |  |
| `created_at` | timestamp with time zone | yes |  |
| `updated_at` | timestamp with time zone | yes |  |
| `deleted_at` | timestamp with time zone | yes |  |

Checks: `CHECK (((phone IS NOT NULL) OR (email IS NOT NULL)))`; `CHECK (((status)::text = ANY ((ARRAY['active'::character varying, 'suspended'::character varying, 'deactivated'::character varying, 'deleted'::character varying])::text[])))`; `CHECK (((type)::text = ANY ((ARRAY['customer'::character varying, 'staff'::character varying])::text[])))`

Indexes:

- `UNIQUE INDEX users_email_unique ON public.users USING btree (email)`
- `UNIQUE INDEX users_phone_unique ON public.users USING btree (phone)`
- `UNIQUE INDEX users_referral_code_unique ON public.users USING btree (referral_code)`
- `INDEX users_type_status_index ON public.users USING btree (type, status)`

## `wishlist_items`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | uuid | no |  |
| `wishlist_id` | uuid | no |  |
| `product_id` | uuid | no |  |
| `product_variant_id` | uuid | yes |  |
| `created_at` | timestamp with time zone | yes |  |
| `updated_at` | timestamp with time zone | yes |  |

Foreign keys: `product_id` → `products` (on delete cascade); `product_variant_id` → `product_variants` (on delete cascade); `wishlist_id` → `wishlists` (on delete cascade)

Indexes:

- `UNIQUE INDEX wishlist_items_unique ON public.wishlist_items USING btree (wishlist_id, product_id, COALESCE(product_variant_id, '00000000-0000-0000-0000-000000000000'::uuid))`

## `wishlists`

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | uuid | no |  |
| `user_id` | uuid | no |  |
| `name` | character varying(255) | no | 'Default'::character varying |
| `created_at` | timestamp with time zone | yes |  |
| `updated_at` | timestamp with time zone | yes |  |

Foreign keys: `user_id` → `users` (on delete cascade)

Indexes:

- `UNIQUE INDEX wishlists_user_id_name_unique ON public.wishlists USING btree (user_id, name)`
