<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Indexes for the dashboard's global search (Ctrl/Cmd+K) and the live operations board.
 *
 * Global search: one bounded, index-backed query per result group, on every keystroke.
 *
 *  - orders.number, orders.customer_phone, users.name / email / phone: trigram (GIN) indexes, so "part of an order number" or "part of a
 *    phone number" is an index lookup and never a scan of the whole table. The older indexes (unique order number, unique phone) only help
 *    an exact match.
 *  - lower(sku) / lower(barcode) of products and variants: pattern-ops B-trees, so "starts with" works whatever collation the database was
 *    created with (the plain lower() indexes of 2026_11_14_300000 only serve exact scans).
 *
 * pg_trgm is already required by the catalogue search (2026_01_01_000100); creating it again is a no-op.
 *
 * Live operations board: the units on each card are summed from the lines of the branch parts on screen. order_items had an index on the
 * order and on the branch but none on the part (a foreign key is not indexed by PostgreSQL), so every refresh read the whole table.
 */
return new class extends Migration
{
    /** @var array<string, array{0: string, 1: string, 2: ?string}> index => [table, definition, partial-index predicate] */
    private const INDEXES = [
        'orders_number_trgm_idx' => ['orders', 'USING gin (number gin_trgm_ops)', null],
        'orders_customer_phone_trgm_idx' => ['orders', 'USING gin (customer_phone gin_trgm_ops)', null],
        'users_name_trgm_idx' => ['users', 'USING gin (name gin_trgm_ops)', null],
        'users_email_trgm_idx' => ['users', 'USING gin (email gin_trgm_ops)', null],
        'users_phone_trgm_idx' => ['users', 'USING gin (phone gin_trgm_ops)', null],
        'products_sku_prefix_idx' => ['products', '(lower(sku) text_pattern_ops)', null],
        'products_barcode_prefix_idx' => ['products', '(lower(barcode) text_pattern_ops)', 'barcode IS NOT NULL'],
        'product_variants_sku_prefix_idx' => ['product_variants', '(lower(sku) text_pattern_ops)', null],
        'product_variants_barcode_prefix_idx' => ['product_variants', '(lower(barcode) text_pattern_ops)', 'barcode IS NOT NULL'],
        'order_items_order_fulfillment_id_idx' => ['order_items', '(order_fulfillment_id)', null],
    ];

    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        foreach (self::INDEXES as $name => [$table, $definition, $where]) {
            DB::statement("CREATE INDEX IF NOT EXISTS {$name} ON {$table} {$definition}".($where ? " WHERE {$where}" : ''));
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::INDEXES) as $name) {
            DB::statement("DROP INDEX IF EXISTS {$name}");
        }
    }
};
