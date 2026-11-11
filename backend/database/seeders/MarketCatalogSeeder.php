<?php

namespace Database\Seeders;

use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Services\CategoryService;
use App\Domain\Catalog\Services\ProductService;
use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Services\InventoryLedger;
use Illuminate\Support\Facades\DB;

/**
 * Household & grocery catalogue loaded from database/data/market_catalog.json (bilingual, rewritten copy).
 * Creates showrooms, delivery zones, categories, brands, products, prices and stock — no accounts —
 * and may run in production:
 *
 *   php artisan db:seed --class=MarketCatalogSeeder --force
 */
class MarketCatalogSeeder extends DemoSeeder
{
    protected bool $withAccounts = false;

    protected bool $allowProduction = true;

    protected function catalog(array $b): void
    {
        $data = json_decode((string) file_get_contents(database_path('data/market_catalog.json')), true, flags: JSON_THROW_ON_ERROR);
        $cats = app(CategoryService::class);

        $catIds = [];
        foreach ($data['categories'] as $i => $c) {
            $catIds[$c['slug']] = $cats->create(['slug' => $c['slug'], 'sort_order' => $i, 'translations' => ['en' => ['name' => $c['name_en'], 'description' => $c['description_en'] ?? null], 'ar' => ['name' => $c['name_ar'], 'description' => $c['description_ar'] ?? null]]])->id;
        }
        $brandIds = [];
        foreach ($data['brands'] as $i => $br) {
            $brandIds[$br['slug']] = Brand::create(['slug' => $br['slug'], 'name_en' => $br['name_en'], 'name_ar' => $br['name_ar'], 'status' => 'active', 'sort_order' => $i])->id;
        }

        $svc = app(ProductService::class);
        $ledger = app(InventoryLedger::class);
        $branches = array_values($b);
        foreach ($data['products'] as $i => $p) {
            $product = $svc->create([
                'sku' => $p['sku'], 'price' => number_format((float) $p['price'], 2, '.', ''),
                'sale_price' => $p['sale_price'] !== null ? number_format((float) $p['sale_price'], 2, '.', '') : null,
                'status' => 'active', 'category_id' => $catIds[$p['category']], 'brand_id' => $p['brand'] ? $brandIds[$p['brand']] : null,
                'is_featured' => (bool) ($p['featured'] ?? false), 'is_new_arrival' => (bool) ($p['new'] ?? false), 'is_bestseller' => (bool) ($p['bestseller'] ?? false),
                'requires_assembly' => false, 'assembly_service_available' => false, 'is_oversized' => false,
                'specifications' => [['label_en' => 'Pack', 'label_ar' => 'العبوة', 'value_en' => $p['pack_en'], 'value_ar' => $p['pack_ar']]],
                'translations' => [
                    'en' => ['name' => $p['name_en'], 'short_description' => $p['short_en'], 'description' => '<p>'.e($p['desc_en']).'</p>', 'search_keywords' => $p['keywords'] ?? ''],
                    'ar' => ['name' => $p['name_ar'], 'short_description' => $p['short_ar'], 'description' => '<p>'.e($p['desc_ar']).'</p>'],
                ],
                'variants' => [],
            ]);
            $stock = max(0, (int) ($p['stock'] ?? 0));
            foreach ($product->variants as $variant) {
                // Spread the stock over the showrooms so every one can sell it.
                foreach ($branches as $k => $branch) {
                    $qty = intdiv($stock, count($branches)) + ($k < $stock % count($branches) ? 1 : 0);
                    if ($qty > 0) {
                        $ledger->apply($branch->id, $variant->id, MovementType::OpeningBalance, $qty, 0, null, null, 'Opening balance (catalogue import)', null, self::cost((string) $p['price']));
                    }
                }
            }
        }
        DB::table('branch_inventory')->update(['minimum_stock' => 5]);
    }
}
