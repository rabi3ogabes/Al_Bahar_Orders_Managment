<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Back-in-stock and price-drop alerts (roadmap 3.6).
 *
 *  - `stock_alerts.product_id`: the product behind the variant, so "how many customers are waiting for this product" and the alerts of a product are one
 *    indexed lookup instead of a join through the variants. Filled from the variant for the rows that exist; the application sets it from now on.
 *  - `stock_alerts.expires_at`: an alert nobody acted on is forgotten after the retention period of the settings (Settings > Notifications), counted from
 *    the day it was made. Existing rows get 90 days from their creation.
 *  - `stock_alerts.notified_at` keeps its meaning: when the customer was last told (a back-in-stock alert is done after that; a price alert keeps watching,
 *    its `reference_price` moves to the price the customer was told about, so the same price is never announced twice).
 *  - Indexes, one per real query: the pending back-in-stock alerts of a variant (the stock sweep and the event job), the price alerts of a product (the
 *    price scan and the event job), the waiting counter on the product page of the dashboard, the daily clean-up by expiry, and the foreign key of the
 *    variant (a deleted variant looked at every alert). The partial index on `notifications` serves the weekly cap of price-drop messages per customer.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE stock_alerts ADD COLUMN IF NOT EXISTS product_id uuid NULL REFERENCES products (id) ON DELETE CASCADE');
        DB::statement('ALTER TABLE stock_alerts ADD COLUMN IF NOT EXISTS expires_at timestamptz NULL');
        DB::statement('UPDATE stock_alerts a SET product_id = v.product_id FROM product_variants v WHERE v.id = a.product_variant_id AND a.product_id IS NULL');
        DB::statement("UPDATE stock_alerts SET expires_at = created_at + interval '90 days' WHERE expires_at IS NULL");
        DB::statement('ALTER TABLE stock_alerts ALTER COLUMN product_id SET NOT NULL');
        DB::statement('ALTER TABLE stock_alerts ALTER COLUMN expires_at SET NOT NULL');

        DB::statement('CREATE INDEX IF NOT EXISTS stock_alerts_variant_idx ON stock_alerts (product_variant_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS stock_alerts_product_type_idx ON stock_alerts (product_id, type)');
        DB::statement("CREATE INDEX IF NOT EXISTS stock_alerts_pending_stock_idx ON stock_alerts (product_variant_id) WHERE type = 'back_in_stock' AND notified_at IS NULL");
        DB::statement("CREATE INDEX IF NOT EXISTS stock_alerts_price_watch_idx ON stock_alerts (product_id, product_variant_id) WHERE type = 'price_drop'");
        DB::statement('CREATE INDEX IF NOT EXISTS stock_alerts_expiry_idx ON stock_alerts (expires_at)');
        DB::statement("CREATE INDEX IF NOT EXISTS notifications_price_drop_idx ON notifications (user_id, created_at) WHERE event = 'stock.price_drop'");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS notifications_price_drop_idx');
        DB::statement('DROP INDEX IF EXISTS stock_alerts_expiry_idx');
        DB::statement('DROP INDEX IF EXISTS stock_alerts_price_watch_idx');
        DB::statement('DROP INDEX IF EXISTS stock_alerts_pending_stock_idx');
        DB::statement('DROP INDEX IF EXISTS stock_alerts_product_type_idx');
        DB::statement('DROP INDEX IF EXISTS stock_alerts_variant_idx');
        DB::statement('ALTER TABLE stock_alerts DROP COLUMN IF EXISTS expires_at');
        DB::statement('ALTER TABLE stock_alerts DROP COLUMN IF EXISTS product_id');
    }
};
