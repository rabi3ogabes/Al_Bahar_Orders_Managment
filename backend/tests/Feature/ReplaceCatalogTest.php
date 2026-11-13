<?php

use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Inventory\Models\BranchInventory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/Commerce/LiveOrderHelpers.php';

/** The one-time swap from the imported catalogue to Al Bahar's own (catalog:replace). */
function replaceSpec(array $skus = ['ABH-9001', 'ABH-9002']): string
{
    $spec = ['categories' => [
        ['slug' => 'test-kitchen', 'sort' => 1, 'en' => ['name' => 'Kitchen', 'description' => 'Pots'], 'ar' => ['name' => 'المطبخ', 'description' => 'قدور']],
    ], 'products' => array_map(fn (string $sku) => [
        'sku' => $sku, 'category' => 'test-kitchen', 'price' => '49.00', 'weight' => 1.2, 'featured' => true,
        'en' => ['name' => "Pot {$sku}", 'short_description' => 'A pot', 'description' => '<p>Good pot</p><ul><li>Steel</li></ul>', 'search_keywords' => 'pot'],
        'ar' => ['name' => "قدر {$sku}", 'short_description' => 'قدر', 'description' => '<p>قدر جيد</p>', 'search_keywords' => 'قدر'],
    ], $skus)];
    $path = tempnam(sys_get_temp_dir(), 'spec').'.json';
    file_put_contents($path, json_encode($spec, JSON_UNESCAPED_UNICODE));

    return $path;
}

it('replaces the catalogue: old products, categories and non-Al-Bahar brands go, new ones arrive with opening stock', function () {
    $w = commerceWorld();
    Brand::create(['slug' => 'al-bahar', 'name_en' => 'Al Bahar', 'name_ar' => 'البحار', 'status' => 'active']);
    Brand::create(['slug' => 'ariel', 'name_en' => 'Ariel', 'name_ar' => 'أريال', 'status' => 'active']);
    $oldIds = [$w->p1->id, $w->p2->id];
    expect(BranchInventory::count())->toBeGreaterThan(0);

    $this->artisan('catalog:replace', ['spec' => replaceSpec(), '--force' => true, '--stock' => 7])->assertSuccessful();

    expect(Product::whereIn('id', $oldIds)->count())->toBe(0)
        ->and(Product::pluck('sku')->sort()->values()->all())->toBe(['ABH-9001', 'ABH-9002'])
        ->and(Brand::pluck('slug')->all())->toBe(['al-bahar'])
        ->and(Category::pluck('slug')->all())->toBe(['test-kitchen'])
        ->and(Product::first()->status)->toBe('active')
        ->and(Product::first()->brand->slug)->toBe('al-bahar');
    // Opening stock only for the new variants: 7 in each of the (two) branches.
    $inv = BranchInventory::all();
    expect($inv)->toHaveCount(4)->and($inv->pluck('on_hand')->unique()->all())->toBe([7]);
    expect(DB::table('product_translations')->where('locale', 'ar')->where('name', 'like', 'قدر%')->count())->toBe(2);
});

it('shows what it would do and changes nothing on a dry run', function () {
    $w = commerceWorld();

    $this->artisan('catalog:replace', ['spec' => replaceSpec(), '--dry-run' => true])->expectsOutputToContain('Dry run')->assertSuccessful();

    expect(Product::whereIn('id', [$w->p1->id, $w->p2->id])->count())->toBe(2)->and(Product::where('sku', 'like', 'ABH-%')->count())->toBe(0);
});

it('refuses a spec with a SKU that is not Al Bahar\'s, an unknown category or a missing price', function () {
    $path = tempnam(sys_get_temp_dir(), 'spec').'.json';
    file_put_contents($path, json_encode(['categories' => [['slug' => 'a', 'en' => ['name' => 'A'], 'ar' => ['name' => 'أ']]], 'products' => [
        ['sku' => 'AM-1', 'category' => 'a', 'price' => '5', 'en' => ['name' => 'X'], 'ar' => ['name' => 'س']],
        ['sku' => 'ABH-1', 'category' => 'nope', 'price' => '5', 'en' => ['name' => 'X'], 'ar' => ['name' => 'س']],
        ['sku' => 'ABH-2', 'category' => 'a', 'en' => ['name' => 'X'], 'ar' => ['name' => 'س']],
    ]], JSON_UNESCAPED_UNICODE));
    $w = commerceWorld();

    $this->artisan('catalog:replace', ['spec' => $path, '--force' => true])->assertFailed();

    expect(Product::whereIn('id', [$w->p1->id, $w->p2->id])->count())->toBe(2);
});

it('is safe to run twice and attaches pictures from a manifest to products that have none', function () {
    Storage::fake(config('platform.media.disk'));
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    Http::fake(['https://img.example.com/*' => Http::response($png, 200, ['Content-Type' => 'image/png'])]);
    $manifest = tempnam(sys_get_temp_dir(), 'img').'.json';
    file_put_contents($manifest, json_encode(['ABH-9001' => ['https://img.example.com/a.png'], 'category:test-kitchen' => 'https://img.example.com/c.png']));
    $args = ['spec' => replaceSpec(['ABH-9001']), '--force' => true, '--keep-old' => true, '--stock' => 0, '--images' => $manifest];

    $this->artisan('catalog:replace', $args)->assertSuccessful();
    $this->artisan('catalog:replace', $args)->assertSuccessful();

    $product = Product::where('sku', 'ABH-9001')->firstOrFail();
    expect(Product::where('sku', 'ABH-9001')->count())->toBe(1)
        ->and($product->images()->count())->toBe(1)
        ->and(Category::where('slug', 'test-kitchen')->value('image_path'))->toBe('categories/test-kitchen.webp');
    Storage::disk(config('platform.media.disk'))->assertExists('categories/test-kitchen.webp');
});

it('keeps the text of past orders but not the picture of a product that no longer exists', function () {
    $w = commerceWorld();
    $order = lopPlace($w);
    DB::table('order_items')->update(['image_path' => 'products/old/photo.webp']);
    $line = DB::table('order_items')->first();
    expect($line->product_id)->not->toBeNull();

    $this->artisan('catalog:replace', ['spec' => replaceSpec(), '--force' => true])->assertSuccessful();

    $after = DB::table('order_items')->where('id', $line->id)->first();
    expect($after->product_id)->toBeNull()->and($after->image_path)->toBeNull()
        ->and($after->sku)->toBe($line->sku)->and($after->name_en)->toBe($line->name_en)
        ->and(DB::table('orders')->where('id', $order->id)->exists())->toBeTrue();
});
