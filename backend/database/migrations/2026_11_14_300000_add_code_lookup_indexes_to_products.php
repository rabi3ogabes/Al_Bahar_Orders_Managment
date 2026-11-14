<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // "Scan to add" resolves a scanned string case-insensitively (lower(sku) = lower(?)). The existing UNIQUE indexes are on the raw
    // columns, so PostgreSQL could not use them for that comparison: these expression indexes make every scan an index lookup.
    // Barcodes are nullable; the partial index keeps products without one out of it.
    private const INDEXES = [
        'products_sku_lower_idx' => ['products', 'lower(sku)', null],
        'products_barcode_lower_idx' => ['products', 'lower(barcode)', 'barcode IS NOT NULL'],
        'product_variants_sku_lower_idx' => ['product_variants', 'lower(sku)', null],
        'product_variants_barcode_lower_idx' => ['product_variants', 'lower(barcode)', 'barcode IS NOT NULL'],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $name => [$table, $expression, $where]) {
            DB::statement("CREATE INDEX IF NOT EXISTS {$name} ON {$table} ({$expression})".($where ? " WHERE {$where}" : ''));
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::INDEXES) as $name) {
            DB::statement("DROP INDEX IF EXISTS {$name}");
        }
    }
};
