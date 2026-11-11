<?php

namespace Database\Seeders;

use App\Domain\Branches\Models\BranchDeliveryZone;
use App\Domain\Branches\Models\DeliverySlot;
use App\Domain\Branches\Models\DeliveryZone;
use App\Domain\Branches\Services\BranchService;
use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Services\CategoryService;
use App\Domain\Catalog\Services\ProductService;
use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Services\InventoryLedger;
use Illuminate\Support\Facades\DB;

/**
 * Household & grocery catalogue loaded from database/data/market_catalog.json (bilingual, rewritten copy).
 * Creates the single Al Markhiya showroom, delivery zones, categories, brands, products, prices and stock — no accounts —
 * and may run in production:
 *
 *   php artisan db:seed --class=MarketCatalogSeeder --force
 */
class MarketCatalogSeeder extends DemoSeeder
{
    protected bool $withAccounts = false;

    protected bool $allowProduction = true;

    /** One showroom: Al Markhiya Main Branch (address and contact details are completed in the admin). */
    protected function branches(): array
    {
        $branch = app(BranchService::class)->create([
            'code' => 'MRK', 'name_en' => 'Al Markhiya Main Branch', 'name_ar' => 'فرع المرخية الرئيسي', 'area' => 'Al Markhiya',
            'address_en' => 'Al Markhiya, Doha, Qatar', 'address_ar' => 'المرخية، الدوحة، قطر', 'is_fulfillment_hub' => true, 'sort_order' => 0,
            'pay_at_pickup_tenders' => ['cash', 'card_terminal'], 'cod_max_order' => '15000.00',
        ]);
        foreach (range(0, 6) as $dow) {
            foreach ([['10:00', '13:00'], ['16:00', '19:00'], ['19:00', '22:00']] as [$from, $to]) {
                DeliverySlot::create(['branch_id' => $branch->id, 'fulfillment_type' => 'pickup', 'day_of_week' => $dow, 'starts_at' => $from, 'ends_at' => $to, 'capacity' => 12]);
            }
            foreach ([['09:00', '13:00'], ['15:00', '21:00']] as [$from, $to]) {
                DeliverySlot::create(['branch_id' => $branch->id, 'fulfillment_type' => 'delivery', 'day_of_week' => $dow, 'starts_at' => $from, 'ends_at' => $to, 'capacity' => 20]);
            }
        }

        return ['MRK' => $branch];
    }

    /** Every delivery zone is served from the single branch (fees are placeholders to review in the admin). */
    protected function zones(array $b): void
    {
        $zones = [
            ['DOHA-C', 'Central Doha', 'وسط الدوحة', '1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,19,20,21,22,23,24,25,26,27', 15, false],
            ['WESTBAY', 'West Bay & Dafna', 'الخليج الغربي والدفنة', '60,61,62,63', 15, false],
            ['PEARL', 'The Pearl & Legtaifiya', 'اللؤلؤة والقطيفية', '64,65,66', 20, false],
            ['LUSAIL', 'Lusail', 'لوسيل', '67,68,69', 25, false],
            ['RAYYAN', 'Al Rayyan', 'الريان', '51,52,53,54,55,56,57', 25, false],
            ['WAKRAH', 'Al Wakrah & Al Wukair', 'الوكرة والوكير', '90,91,92', 35, false],
            ['KHOR', 'Al Khor', 'الخور', '74,75', 60, true],
        ];
        foreach ($zones as [$code, $en, $ar, $numbers, $fee, $remote]) {
            $zone = DeliveryZone::create(['code' => $code, 'name_en' => $en, 'name_ar' => $ar, 'zone_numbers' => $numbers, 'is_remote' => $remote]);
            BranchDeliveryZone::create(['branch_id' => $b['MRK']->id, 'delivery_zone_id' => $zone->id, 'delivery_fee' => number_format($fee, 2, '.', ''),
                'free_delivery_threshold' => '1500.00', 'min_order_amount' => '100.00', 'oversized_surcharge' => '50.00', 'cod_enabled' => true,
                'estimated_minutes' => 240, 'priority' => 10]);
        }
    }

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
