<?php

namespace Database\Seeders;

use App\Domain\Branches\Models\Branch;
use App\Domain\Branches\Models\BranchDeliveryZone;
use App\Domain\Branches\Models\BranchEmployee;
use App\Domain\Branches\Models\DeliverySlot;
use App\Domain\Branches\Models\DeliveryZone;
use App\Domain\Branches\Services\BranchService;
use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\ProductAttribute;
use App\Domain\Catalog\Models\ProductBranchSetting;
use App\Domain\Catalog\Services\CategoryService;
use App\Domain\Catalog\Services\ProductService;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Models\UserRole;
use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Services\InventoryLedger;
use App\Support\Money;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Development/demo data only — never runs in production (guarded in DatabaseSeeder and here).
 * Demo staff password: "AlBahar#Demo2026" (local only).
 */
class DemoSeeder extends Seeder
{
    private const PASSWORD = 'AlBahar#Demo2026';

    public function run(): void
    {
        if (app()->isProduction()) {
            return;
        }
        if (Branch::where('code', 'WB')->exists()) {
            return; // idempotent
        }

        DB::transaction(function () {
            $branches = $this->branches();
            $this->zones($branches);
            $this->staff($branches);
            $this->catalog($branches);
        });
    }

    private function branches(): array
    {
        $svc = app(BranchService::class);
        $defs = [
            ['WB', 'West Bay Flagship', 'معرض الخليج الغربي الرئيسي', 'West Bay', '61', 25.3221, 51.5291, true],
            ['PRL', 'The Pearl Showroom', 'معرض اللؤلؤة', 'The Pearl-Qatar', '66', 25.3691, 51.5508, false],
            ['LUS', 'Lusail Boulevard', 'بوليفارد لوسيل', 'Lusail', '69', 25.4206, 51.4905, false],
            ['WAK', 'Al Wakrah Warehouse Store', 'متجر مستودع الوكرة', 'Al Wakrah', '90', 25.1715, 51.6034, true],
        ];
        $out = [];
        foreach ($defs as $i => [$code, $en, $ar, $area, $zone, $lat, $lng, $hub]) {
            $out[$code] = $svc->create([
                'code' => $code, 'name_en' => $en, 'name_ar' => $ar, 'area' => $area, 'zone_number' => $zone,
                'latitude' => $lat, 'longitude' => $lng, 'phone' => '+97444'.str_pad((string) (100000 + $i * 1111), 6, '0'),
                'email' => strtolower($code).'@albahar.qa', 'address_en' => "{$area}, Doha, Qatar", 'address_ar' => "{$ar}، الدوحة، قطر",
                'is_fulfillment_hub' => $hub, 'sort_order' => $i, 'pay_at_pickup_tenders' => ['cash', 'card_terminal'],
                'cod_max_order' => '15000.00',
            ]);
            foreach ([0, 1, 2, 3, 4, 5, 6] as $dow) {
                foreach ([['10:00', '13:00'], ['16:00', '19:00'], ['19:00', '22:00']] as [$s, $e]) {
                    DeliverySlot::create(['branch_id' => $out[$code]->id, 'fulfillment_type' => 'pickup', 'day_of_week' => $dow, 'starts_at' => $s, 'ends_at' => $e, 'capacity' => 12]);
                }
                DeliverySlot::create(['branch_id' => $out[$code]->id, 'fulfillment_type' => 'delivery', 'day_of_week' => $dow, 'starts_at' => '09:00', 'ends_at' => '13:00', 'capacity' => 20]);
                DeliverySlot::create(['branch_id' => $out[$code]->id, 'fulfillment_type' => 'delivery', 'day_of_week' => $dow, 'starts_at' => '15:00', 'ends_at' => '21:00', 'capacity' => 20]);
            }
        }

        return $out;
    }

    private function zones(array $b): void
    {
        $zones = [
            ['DOHA-C', 'Central Doha', 'وسط الدوحة', '1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,19,20,21,22,23,24,25,26,27'],
            ['WESTBAY', 'West Bay & Dafna', 'الخليج الغربي والدفنة', '60,61,62,63'],
            ['PEARL', 'The Pearl & Legtaifiya', 'اللؤلؤة والقطيفية', '64,65,66'],
            ['LUSAIL', 'Lusail', 'لوسيل', '67,68,69'],
            ['RAYYAN', 'Al Rayyan', 'الريان', '51,52,53,54,55,56,57'],
            ['WAKRAH', 'Al Wakrah & Al Wukair', 'الوكرة والوكير', '90,91,92'],
            ['KHOR', 'Al Khor', 'الخور', '74,75', true],
        ];
        $map = [
            'DOHA-C' => ['WB' => [15, 10], 'WAK' => [25, 40]], 'WESTBAY' => ['WB' => [0, 5], 'PRL' => [15, 20]],
            'PEARL' => ['PRL' => [0, 5], 'WB' => [15, 20]], 'LUSAIL' => ['LUS' => [0, 5], 'PRL' => [20, 25]],
            'RAYYAN' => ['WB' => [25, 30], 'LUS' => [30, 40]], 'WAKRAH' => ['WAK' => [0, 5], 'WB' => [35, 50]], 'KHOR' => ['LUS' => [60, 60]],
        ];
        foreach ($zones as $z) {
            $zone = DeliveryZone::create(['code' => $z[0], 'name_en' => $z[1], 'name_ar' => $z[2], 'zone_numbers' => $z[3], 'is_remote' => $z[4] ?? false]);
            foreach ($map[$z[0]] as $code => [$fee, $priority]) {
                BranchDeliveryZone::create(['branch_id' => $b[$code]->id, 'delivery_zone_id' => $zone->id, 'delivery_fee' => number_format($fee, 2, '.', ''),
                    'free_delivery_threshold' => '1500.00', 'min_order_amount' => '100.00', 'oversized_surcharge' => '50.00', 'cod_enabled' => $code !== 'LUS' || $z[0] !== 'KHOR',
                    'estimated_minutes' => $fee === 0 ? 120 : 240, 'priority' => $priority, 'express_enabled' => $fee <= 15, 'express_fee' => $fee <= 15 ? '35.00' : null]);
            }
        }
    }

    private function staff(array $b): void
    {
        $people = [
            ['Aisha Al-Kuwari', 'manager.westbay@albahar.qa', 'branch_manager', ['WB'], 'Branch Manager'],
            ['Omar Haddad', 'manager.pearl@albahar.qa', 'branch_manager', ['PRL'], 'Branch Manager'],
            ['Fatima Nasser', 'inventory@albahar.qa', 'inventory_manager', ['WB', 'WAK'], 'Inventory Controller'],
            ['Yousef Ali', 'orders@albahar.qa', 'order_manager', ['WB', 'PRL', 'LUS'], 'Order Operations'],
            ['Mariam Saleh', 'pickup.westbay@albahar.qa', 'pickup_employee', ['WB'], 'Pickup Desk'],
            ['Ravi Kumar', 'driver1@albahar.qa', 'delivery_employee', ['WB'], 'Driver', true],
            ['Joseph Mathew', 'driver2@albahar.qa', 'delivery_employee', ['WAK'], 'Driver', true],
            ['Khalid Al-Marri', 'auctions@albahar.qa', 'auction_manager', [], 'Auction Manager'],
            ['Layla Hassan', 'marketing@albahar.qa', 'marketing_manager', [], 'Marketing Manager'],
            ['Samir Farouk', 'finance@albahar.qa', 'accountant', [], 'Accountant'],
            ['Noura Al-Sulaiti', 'care@albahar.qa', 'customer_support', [], 'Customer Care'],
            ['Hamad Al-Thani', 'audit@albahar.qa', 'auditor', [], 'Internal Audit'],
        ];
        foreach ($people as $p) {
            $user = User::create(['type' => 'staff', 'name' => $p[0], 'email' => $p[1], 'password' => self::PASSWORD, 'job_title' => $p[4], 'locale' => 'en']);
            $role = Role::where('key', $p[2])->firstOrFail();
            if ($p[3] === []) {
                UserRole::create(['user_id' => $user->id, 'role_id' => $role->id, 'branch_id' => null]);
            }
            foreach ($p[3] as $code) {
                BranchEmployee::create(['branch_id' => $b[$code]->id, 'user_id' => $user->id, 'job_title' => $p[4], 'is_active' => true, 'is_delivery_driver' => $p[5] ?? false]);
                UserRole::create(['user_id' => $user->id, 'role_id' => $role->id, 'branch_id' => $b[$code]->id]);
            }
        }
        User::create(['type' => 'customer', 'name' => 'Noor Al-Mansoori', 'phone' => '+97455123456', 'email' => 'noor@example.com',
            'password' => self::PASSWORD, 'phone_verified_at' => now(), 'locale' => 'ar', 'referral_code' => 'ABNOOR01', 'marketing_consent_at' => now()]);
    }

    private function catalog(array $b): void
    {
        $cats = app(CategoryService::class);
        $tree = [
            'living' => ['Living Room', 'غرفة المعيشة', ['sofas' => ['Sofas', 'الكنب'], 'coffee-tables' => ['Coffee Tables', 'طاولات القهوة'], 'armchairs' => ['Armchairs', 'الكراسي المنفردة']]],
            'dining' => ['Dining', 'غرفة الطعام', ['dining-tables' => ['Dining Tables', 'طاولات الطعام'], 'dining-chairs' => ['Dining Chairs', 'كراسي الطعام']]],
            'bedroom' => ['Bedroom', 'غرفة النوم', ['beds' => ['Beds', 'الأسرّة'], 'wardrobes' => ['Wardrobes', 'الخزائن']]],
            'decor' => ['Décor & Lighting', 'الديكور والإضاءة', ['lighting' => ['Lighting', 'الإضاءة'], 'rugs' => ['Rugs', 'السجاد']]],
            'outdoor' => ['Outdoor', 'الأثاث الخارجي', []],
        ];
        $catIds = [];
        foreach ($tree as $slug => [$en, $ar, $children]) {
            $parent = $cats->create(['slug' => $slug, 'translations' => ['en' => ['name' => $en], 'ar' => ['name' => $ar]]]);
            $catIds[$slug] = $parent->id;
            foreach ($children as $cs => [$cen, $car]) {
                $catIds[$cs] = $cats->create(['slug' => $cs, 'parent_id' => $parent->id, 'translations' => ['en' => ['name' => $cen], 'ar' => ['name' => $car]]])->id;
            }
        }

        $brands = [];
        foreach ([['nordic-living', 'Nordic Living', 'نورديك ليفينج'], ['casa-doha', 'Casa Doha', 'كازا الدوحة'], ['maison-noor', 'Maison Noor', 'ميزون نور'], ['atelier-gulf', 'Atelier Gulf', 'أتيليه الخليج']] as $i => [$s, $en, $ar]) {
            $brands[$s] = Brand::create(['slug' => $s, 'name_en' => $en, 'name_ar' => $ar, 'status' => 'active', 'sort_order' => $i])->id;
        }

        $color = ProductAttribute::create(['code' => 'color', 'name_en' => 'Colour', 'name_ar' => 'اللون', 'type' => 'color', 'sort_order' => 1]);
        $colors = [];
        foreach ([['ivory', 'Ivory', 'عاجي', '#f3ede1'], ['sand', 'Sand', 'رملي', '#d8c7a6'], ['charcoal', 'Charcoal', 'فحمي', '#3b3d43'], ['olive', 'Olive', 'زيتوني', '#6b7350'], ['walnut', 'Walnut', 'جوزي', '#6b4a33']] as $i => [$c, $en, $ar, $hex]) {
            $colors[$c] = $color->values()->create(['code' => $c, 'value_en' => $en, 'value_ar' => $ar, 'color_hex' => $hex, 'sort_order' => $i])->id;
        }
        $size = ProductAttribute::create(['code' => 'size', 'name_en' => 'Size', 'name_ar' => 'المقاس', 'type' => 'select', 'sort_order' => 2]);
        $sizes = [];
        foreach ([['2-seater', '2 seater', 'مقعدان'], ['3-seater', '3 seater', '3 مقاعد'], ['queen', 'Queen 160×200', 'كوين 160×200'], ['king', 'King 180×200', 'كينج 180×200']] as $i => [$c, $en, $ar]) {
            $sizes[$c] = $size->values()->create(['code' => $c, 'value_en' => $en, 'value_ar' => $ar, 'sort_order' => $i])->id;
        }
        $material = ProductAttribute::create(['code' => 'material', 'name_en' => 'Material', 'name_ar' => 'الخامة', 'type' => 'select', 'is_variant_axis' => false, 'sort_order' => 3]);
        foreach ([['linen', 'Linen', 'كتان'], ['velvet', 'Velvet', 'مخمل'], ['oak', 'Solid oak', 'خشب البلوط'], ['marble', 'Marble', 'رخام']] as $i => [$c, $en, $ar]) {
            $material->values()->create(['code' => $c, 'value_en' => $en, 'value_ar' => $ar, 'sort_order' => $i]);
        }

        $products = [
            ['SOF-OSLO', 'sofas', 'nordic-living', 'Oslo Linen Sofa', 'كنبة أوسلو الكتان', '4890.00', '4290.00', [['2-seater', 'sand', '3990.00'], ['3-seater', 'sand', null], ['3-seater', 'charcoal', null]], true, true],
            ['SOF-MILANO', 'sofas', 'maison-noor', 'Milano Velvet Sofa', 'كنبة ميلانو المخملية', '6450.00', null, [['3-seater', 'olive', null], ['3-seater', 'ivory', null]], true, false],
            ['SOF-CORNICHE', 'sofas', 'casa-doha', 'Corniche Modular Sectional', 'كنبة كورنيش المعيارية', '9800.00', null, [], false, true],
            ['CTB-ATLAS', 'coffee-tables', 'atelier-gulf', 'Atlas Marble Coffee Table', 'طاولة قهوة أطلس الرخامية', '2150.00', '1890.00', [], true, false],
            ['CTB-DUNE', 'coffee-tables', 'nordic-living', 'Dune Oak Coffee Table', 'طاولة قهوة ديون من البلوط', '1450.00', null, [], false, true],
            ['ARM-SAHARA', 'armchairs', 'maison-noor', 'Sahara Bouclé Armchair', 'كرسي صحارى بوكليه', '1890.00', null, [['', 'ivory', null], ['', 'sand', null]], false, false],
            ['DTB-ZAFRAN', 'dining-tables', 'casa-doha', 'Zafran Extending Dining Table', 'طاولة طعام زعفران القابلة للتمديد', '5200.00', null, [], true, false],
            ['DCH-NAJMA', 'dining-chairs', 'casa-doha', 'Najma Upholstered Dining Chair', 'كرسي طعام نجمة المنجد', '690.00', '590.00', [['', 'sand', null], ['', 'charcoal', null]], false, true],
            ['BED-LAYAL', 'beds', 'maison-noor', 'Layal Upholstered Bed', 'سرير ليال المنجد', '5650.00', null, [['queen', 'ivory', null], ['king', 'ivory', '6250.00']], true, false],
            ['WRD-MANAR', 'wardrobes', 'atelier-gulf', 'Manar 4-Door Wardrobe', 'خزانة منار بأربعة أبواب', '7300.00', null, [], false, false],
            ['LMP-FANOOS', 'lighting', 'atelier-gulf', 'Fanoos Brass Pendant', 'ثريا فانوس النحاسية', '980.00', null, [], false, true],
            ['LMP-QAMAR', 'lighting', 'nordic-living', 'Qamar Floor Lamp', 'مصباح قمر الأرضي', '760.00', '640.00', [], false, false],
            ['RUG-SADU', 'rugs', 'casa-doha', 'Sadu Hand-Woven Rug 200×300', 'سجادة السدو المنسوجة يدوياً 200×300', '3400.00', null, [], true, true],
            ['OUT-MAJLIS', 'outdoor', 'casa-doha', 'Majlis Outdoor Lounge Set', 'طقم مجلس خارجي', '8900.00', null, [], false, false],
        ];
        $svc = app(ProductService::class);
        $ledger = app(InventoryLedger::class);
        foreach ($products as $i => [$sku, $cat, $brand, $en, $ar, $price, $sale, $variants, $featured, $new]) {
            $variantRows = [];
            foreach ($variants as $j => [$sz, $col, $vPrice]) {
                $variantRows[] = ['sku' => $sku.'-'.strtoupper(substr($sz ?: 'std', 0, 3)).'-'.strtoupper(substr($col, 0, 3)).$j, 'price' => $vPrice,
                    'attribute_value_ids' => array_values(array_filter([$sz ? $sizes[$sz] : null, $colors[$col]]))];
            }
            $product = $svc->create([
                'sku' => $sku, 'price' => $price, 'sale_price' => $sale, 'status' => 'active', 'category_id' => $catIds[$cat], 'brand_id' => $brands[$brand],
                'is_featured' => $featured, 'is_new_arrival' => $new, 'is_bestseller' => $i % 4 === 0, 'requires_assembly' => in_array($cat, ['beds', 'wardrobes', 'dining-tables'], true),
                'assembly_service_available' => true, 'is_oversized' => in_array($cat, ['sofas', 'wardrobes', 'beds', 'dining-tables'], true) || $cat === 'outdoor',
                'weight' => (string) (10 + $i * 3), 'length' => (string) (80 + $i * 10), 'width' => '90', 'height' => '85',
                'specifications' => [['label_en' => 'Origin', 'label_ar' => 'بلد المنشأ', 'value_en' => $i % 2 ? 'Italy' : 'Portugal', 'value_ar' => $i % 2 ? 'إيطاليا' : 'البرتغال'],
                    ['label_en' => 'Care', 'label_ar' => 'العناية', 'value_en' => 'Professional cleaning recommended', 'value_ar' => 'يُنصح بالتنظيف الاحترافي']],
                'warranty' => ['duration_months' => in_array($cat, ['sofas', 'beds'], true) ? 60 : 24, 'provider' => 'Al Bahar', 'terms_en' => 'Covers manufacturing defects in frame and mechanisms.', 'terms_ar' => 'يغطي عيوب التصنيع في الهيكل والآليات.'],
                'translations' => [
                    'en' => ['name' => $en, 'short_description' => 'Crafted for calm, considered living.', 'description' => '<p>'.$en.' brings quiet luxury to your home with premium materials and meticulous finishing.</p>', 'search_keywords' => $cat],
                    'ar' => ['name' => $ar, 'short_description' => 'مصمم لحياة هادئة وراقية.', 'description' => '<p>يمنح '.$ar.' منزلك فخامة هادئة بخامات فاخرة وتشطيب دقيق.</p>'],
                ],
                'variants' => $variantRows,
            ]);
            foreach ($product->variants as $k => $variant) {
                foreach ($b as $code => $branch) {
                    $qty = ($i + $k + strlen($code)) % 7 === 0 ? 0 : (($i * 3 + $k * 5 + strlen($code)) % 9) + 1;
                    if ($qty > 0) {
                        $ledger->apply($branch->id, $variant->id, MovementType::OpeningBalance, $qty, 0, null, null, 'Demo opening balance', null, self::cost($price));
                    }
                }
            }
            if ($sku === 'SOF-OSLO') {
                ProductBranchSetting::create(['product_id' => $product->id, 'branch_id' => $b['PRL']->id, 'price' => '4990.00', 'sale_price' => '4490.00']);
            }
            if ($sku === 'WRD-MANAR') {
                ProductBranchSetting::create(['product_id' => $product->id, 'branch_id' => $b['PRL']->id, 'is_enabled' => false]);
            }
        }
        DB::table('branch_inventory')->update(['minimum_stock' => 2]);
    }

    /** Demo cost price ≈ 55% of retail. */
    private static function cost(string $price): string
    {
        return Money::of($price)->percent('55')->toDecimal();
    }
}
