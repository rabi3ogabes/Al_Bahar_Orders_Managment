<?php

use App\Domain\Branches\Models\Branch;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductBranchSetting;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Models\ProductVariantBranchSetting;
use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Services\InventoryLedger;
use Illuminate\Support\Facades\DB;

/** "Scan to add": GET /api/v1/products/by-code/{code}. */
function scanUrl(string $code): string
{
    return '/api/v1/products/by-code/'.rawurlencode($code);
}

function stocked(Product|ProductVariant $item, int $units, ?Branch $branch = null): Branch
{
    $variant = $item instanceof Product ? $item->variants()->first() : $item;
    $branch ??= Branch::factory()->create();
    app(InventoryLedger::class)->apply($branch->id, $variant->id, MovementType::OpeningBalance, $units);

    return $branch;
}

function slugOf(Product $p, string $locale = 'en'): string
{
    return $p->translations()->where('locale', $locale)->value('slug');
}

it('finds a product by its variant barcode and says exactly which variant to add', function () {
    $p = Product::factory()->create(['sku' => 'SOFA-1', 'barcode' => '6291000000011', 'price' => '1000.00']);
    $v = $p->variants()->first();
    $v->update(['barcode' => '6291000000028', 'price' => '900.00']);
    stocked($p, 7);

    $r = $this->getJson(scanUrl('6291000000028'))->assertOk();
    $r->assertJsonPath('data.id', $p->id)->assertJsonPath('data.variant_id', $v->id)->assertJsonPath('data.matched_by', 'variant_barcode')
        ->assertJsonPath('data.needs_choice', false)->assertJsonPath('data.in_stock', true)->assertJsonPath('data.purchasable', true)
        ->assertJsonPath('data.max_quantity', 7)->assertJsonPath('data.variant_sku', 'SOFA-1');
    // The price is the scanned variant's, not the "from" price of the product.
    expect(json_encode($r->json('data.price.final')))->toContain('900')->and(json_encode($r->json('data.price.final')))->not->toContain('1000');
    // It is the usual product card as well.
    expect($r->json('data'))->toHaveKeys(['id', 'slug', 'sku', 'name', 'image', 'price', 'rating', 'badges', 'in_stock', 'purchasable', 'has_variants']);
});

it('finds a product by its product barcode when it has a single variant', function () {
    $p = Product::factory()->create(['barcode' => '5012345678900']);
    stocked($p, 3);

    $this->getJson(scanUrl('5012345678900'))->assertOk()->assertJsonPath('data.matched_by', 'product_barcode')
        ->assertJsonPath('data.variant_id', $p->variants()->first()->id)->assertJsonPath('data.needs_choice', false);
});

it('finds a product by SKU, ignoring case, spaces and invisible characters', function () {
    $p = Product::factory()->create(['sku' => 'Chair-Oak-01']);
    $v = $p->variants()->first();
    $v->update(['sku' => 'Chair-Oak-01-A']);
    stocked($p, 2);

    foreach (['Chair-Oak-01-A', 'chair-oak-01-a', 'CHAIR-OAK-01-A', "  chair-oak-01-a \n", "\u{200B}CHAIR-OAK-01-A\u{200B}", "\tChair-Oak-01-A\r"] as $input) {
        $this->getJson(scanUrl($input))->assertOk()->assertJsonPath('data.matched_by', 'variant_sku')->assertJsonPath('data.variant_id', $v->id);
    }
    $this->getJson(scanUrl('chair-oak-01'))->assertOk()->assertJsonPath('data.matched_by', 'product_sku')->assertJsonPath('data.id', $p->id);
});

it('tries variant barcode, product barcode, variant SKU and product SKU in that order', function () {
    $bySku = Product::factory()->create(['sku' => 'CODE-1']);
    $bySku->variants()->first()->update(['sku' => 'CODE-1-X']);
    $byVariantSku = Product::factory()->create();
    $byVariantSku->variants()->first()->update(['sku' => 'Code-1']);
    $byProductBarcode = Product::factory()->create(['barcode' => 'code-1']);
    $byVariantBarcode = Product::factory()->create();
    $byVariantBarcode->variants()->first()->update(['barcode' => 'CODE-1']);

    $this->getJson(scanUrl('code-1'))->assertOk()->assertJsonPath('data.id', $byVariantBarcode->id)->assertJsonPath('data.matched_by', 'variant_barcode');
    $byVariantBarcode->variants()->update(['barcode' => null]);
    $this->getJson(scanUrl('code-1'))->assertOk()->assertJsonPath('data.id', $byProductBarcode->id)->assertJsonPath('data.matched_by', 'product_barcode');
    $byProductBarcode->update(['barcode' => null]);
    $this->getJson(scanUrl('code-1'))->assertOk()->assertJsonPath('data.id', $byVariantSku->id)->assertJsonPath('data.matched_by', 'variant_sku');
    $byVariantSku->variants()->update(['sku' => 'Code-1-moved']);
    $this->getJson(scanUrl('code-1'))->assertOk()->assertJsonPath('data.id', $bySku->id)->assertJsonPath('data.matched_by', 'product_sku');
});

it('reads UPC-A, EAN-13 and GTIN-14 as the same barcode with or without the leading zero', function () {
    $upc = Product::factory()->create();
    $upc->variants()->first()->update(['barcode' => '036000291452']);        // stored as UPC-A (12)
    $ean = Product::factory()->create();
    $ean->variants()->first()->update(['barcode' => '4006381333931']);       // stored as EAN-13
    $short = Product::factory()->create(['barcode' => '96385074']);          // EAN-8

    foreach (['036000291452', '0036000291452', '00036000291452'] as $scanned) {
        $this->getJson(scanUrl($scanned))->assertOk()->assertJsonPath('data.id', $upc->id);
    }
    foreach (['4006381333931', '04006381333931'] as $scanned) {
        $this->getJson(scanUrl($scanned))->assertOk()->assertJsonPath('data.id', $ean->id);
    }
    $this->getJson(scanUrl('96385074'))->assertOk()->assertJsonPath('data.id', $short->id);
    $this->getJson(scanUrl('0000096385074'))->assertOk()->assertJsonPath('data.id', $short->id);
    // A different number is not "the same barcode".
    $this->getJson(scanUrl('4006381333932'))->assertStatus(404)->assertJsonPath('code', 'product_not_found');
    $this->getJson(scanUrl('1036000291452'))->assertStatus(404);
});

it('accepts Arabic-Indic digits typed by hand', function () {
    $p = Product::factory()->create(['barcode' => '6291000000011']);

    $this->getJson(scanUrl('٦٢٩١٠٠٠٠٠٠٠١١'))->assertOk()->assertJsonPath('data.id', $p->id);
});

it('a number-looking SKU is matched as written before any zero-padding guess', function () {
    $sku = Product::factory()->create(['sku' => '0042']);
    $barcode = Product::factory()->create(['barcode' => '000000000042']);

    $this->getJson(scanUrl('0042'))->assertOk()->assertJsonPath('data.id', $sku->id);
    $this->getJson(scanUrl('000000000042'))->assertOk()->assertJsonPath('data.id', $barcode->id);
});

it('accepts store links, the app deep link, an id and a slug', function () {
    $p = Product::factory()->create();
    $slug = slugOf($p);
    $arabic = slugOf($p, 'ar');
    $id = $p->id;
    $forms = [
        "https://albahar.qa/products/{$slug}" => 'url',
        "https://albahar.qa/products/{$slug}/" => 'url',
        "https://www.albahar.qa/products/{$slug}?utm_source=qr&variant=1#reviews" => 'url',
        "http://localhost:8000/products/{$slug}" => 'url',
        "HTTPS://ALBAHAR.QA/products/{$slug}" => 'url',
        "https://albahar.qa/ar/products/{$slug}" => 'url',
        "https://albahar.qa/products/{$arabic}" => 'url',
        'https://albahar.qa/products/'.rawurlencode($arabic) => 'url',
        "https://albahar.qa/app/products/{$id}" => 'url',
        "albahar://products/{$id}" => 'deep_link',
        "ALBAHAR://products/{$id}?ref=qr" => 'deep_link',
        "albahar://products/{$slug}" => 'deep_link',
        $slug => 'slug',
        strtoupper($slug) => 'slug',
        $arabic => 'slug',
        $id => 'id',
    ];
    foreach ($forms as $input => $via) {
        $this->getJson(scanUrl($input))->assertOk()->assertJsonPath('data.id', $id)->assertJsonPath('data.matched_by', $via);
    }
});

it('accepts a store link that was not percent-encoded by the caller', function () {
    $p = Product::factory()->create();

    // "/" inside the code, sent as is: the route still hands the whole string over.
    $this->getJson('/api/v1/products/by-code/https://albahar.qa/products/'.slugOf($p))->assertOk()->assertJsonPath('data.id', $p->id);
});

it('does not take a link to something else for a product', function () {
    $p = Product::factory()->create();
    foreach (['https://albahar.qa/categories/'.slugOf($p), 'https://albahar.qa/', 'albahar://orders/'.$p->id, 'albahar://products/', 'https://evil.example/x?products='.$p->id] as $input) {
        $this->getJson(scanUrl($input))->assertStatus(404)->assertJsonPath('code', 'product_not_found');
    }
});

it('answers 404 product_not_found with a plain message for an unknown code, in both languages', function () {
    $this->getJson(scanUrl('0000000000000'))->assertStatus(404)
        ->assertJsonPath('code', 'product_not_found')->assertJsonPath('message', "We couldn't find this product. Try again or search by name.")
        ->assertJsonStructure(['message', 'code', 'request_id']);
    $this->getJson(scanUrl('nothing-here'), ['Accept-Language' => 'ar'])->assertStatus(404)->assertJsonPath('code', 'product_not_found')
        ->assertJsonPath('message', 'لم نعثر على هذا المنتج. حاول مرة أخرى أو ابحث بالاسم.');
});

it('never finds a draft, inactive or archived product', function (string $status) {
    $p = Product::factory()->create(['status' => $status, 'sku' => 'HIDDEN-1', 'barcode' => '6291000000099']);
    $p->variants()->first()->update(['barcode' => '6291000000103', 'sku' => 'HIDDEN-1-A']);

    foreach (['6291000000103', '6291000000099', 'HIDDEN-1-A', 'HIDDEN-1', slugOf($p), $p->id, 'https://albahar.qa/products/'.slugOf($p), "albahar://products/{$p->id}"] as $code) {
        $this->getJson(scanUrl($code))->assertStatus(404)->assertJsonPath('code', 'product_not_found');
    }
})->with(['draft', 'inactive', 'archived']);

it('never finds an inactive variant, and a product left without an active variant is not found at all', function () {
    $p = Product::factory()->withVariants(2)->create(['barcode' => '6291000000200']);
    [$a, $b] = $p->variants()->get()->all();
    $a->update(['barcode' => '6291000000217', 'status' => 'inactive']);
    $b->update(['barcode' => '6291000000224']);

    $this->getJson(scanUrl('6291000000217'))->assertStatus(404);
    // The product-level code now names a product with one sellable variant: that one is added.
    $this->getJson(scanUrl('6291000000200'))->assertOk()->assertJsonPath('data.variant_id', $b->id)->assertJsonPath('data.needs_choice', false);
    $b->update(['status' => 'inactive']);
    $this->getJson(scanUrl('6291000000200'))->assertStatus(404);
    $this->getJson(scanUrl('6291000000224'))->assertStatus(404);
});

it('reports a product that is out of stock instead of hiding it', function () {
    $p = Product::factory()->create(['barcode' => '6291000000300']);
    $p->variants()->first()->update(['barcode' => '6291000000317']);

    $this->getJson(scanUrl('6291000000317'))->assertOk()->assertJsonPath('data.in_stock', false)->assertJsonPath('data.purchasable', true)
        ->assertJsonPath('data.max_quantity', 0)->assertJsonPath('data.variant_id', $p->variants()->first()->id);
});

it('limits max_quantity to what is in stock and to the per-line cart limit', function () {
    $p = Product::factory()->create(['barcode' => '6291000000400']);
    $branch = stocked($p, 3);
    $this->getJson(scanUrl('6291000000400'))->assertOk()->assertJsonPath('data.max_quantity', 3);

    stocked($p, 500, $branch);
    $this->getJson(scanUrl('6291000000400'))->assertOk()->assertJsonPath('data.max_quantity', (int) config('platform.checkout.max_quantity_per_line'));

    // Stock of a closed branch does not count.
    $closed = Branch::factory()->inactive()->create();
    $other = Product::factory()->create(['barcode' => '6291000000417']);
    stocked($other, 9, $closed);
    $this->getJson(scanUrl('6291000000417'))->assertOk()->assertJsonPath('data.in_stock', false)->assertJsonPath('data.max_quantity', 0);
});

it('asks the shopper to choose when a product-level code belongs to a product with several variants', function () {
    $p = Product::factory()->withVariants(3)->create(['sku' => 'WARDROBE', 'barcode' => '6291000000500']);
    $variants = $p->variants()->get();
    $variants[1]->update(['barcode' => '6291000000517']);
    stocked($variants[1], 4);

    foreach (['6291000000500', 'WARDROBE', slugOf($p), "albahar://products/{$p->id}", 'https://albahar.qa/products/'.slugOf($p)] as $code) {
        $this->getJson(scanUrl($code))->assertOk()->assertJsonPath('data.id', $p->id)->assertJsonPath('data.needs_choice', true)
            ->assertJsonPath('data.variant_id', null)->assertJsonPath('data.max_quantity', null)->assertJsonPath('data.has_variants', true);
    }
    // The code of one variant is not ambiguous.
    $this->getJson(scanUrl('6291000000517'))->assertOk()->assertJsonPath('data.needs_choice', false)->assertJsonPath('data.variant_id', $variants[1]->id)
        ->assertJsonPath('data.max_quantity', 4)->assertJsonPath('data.matched_by', 'variant_barcode');
});

it('follows the branch the shopper chose: hidden products are not found there and prices are the branch prices', function () {
    $branch = Branch::factory()->create();
    $visible = Product::factory()->create(['barcode' => '6291000000600', 'price' => '100.00']);
    $hidden = Product::factory()->selectedBranchesOnly()->create(['barcode' => '6291000000617']);
    $variantOff = Product::factory()->create(['barcode' => '6291000000624']);
    ProductBranchSetting::create(['product_id' => $visible->id, 'branch_id' => $branch->id, 'is_enabled' => true, 'is_visible' => true, 'price' => '80.00']);
    ProductVariantBranchSetting::create(['product_variant_id' => $variantOff->variants()->first()->id, 'branch_id' => $branch->id, 'is_enabled' => false]);
    foreach ([$visible, $hidden, $variantOff] as $p) {
        stocked($p, 5, $branch);
    }

    $this->getJson(scanUrl('6291000000617'))->assertOk(); // no branch chosen: the shop's whole catalogue
    $this->getJson(scanUrl('6291000000617').'?branch_id='.$branch->id)->assertStatus(404)->assertJsonPath('code', 'product_not_found');
    $this->getJson(scanUrl('6291000000624').'?branch_id='.$branch->id)->assertOk()->assertJsonPath('data.purchasable', false)->assertJsonPath('data.max_quantity', 0)
        ->assertJsonPath('data.price', null);
    $r = $this->getJson(scanUrl('6291000000600').'?branch_id='.$branch->id)->assertOk()->assertJsonPath('data.max_quantity', 5);
    expect(json_encode($r->json('data.price')))->toContain('80')->not->toContain('100.00');

    $this->getJson(scanUrl('6291000000600').'?branch_id=not-a-uuid')->assertStatus(422);
});

it('is public: works for guests and signed-in customers alike', function () {
    $p = Product::factory()->create(['barcode' => '6291000000700']);
    $this->getJson(scanUrl('6291000000700'))->assertOk();
    actingAsCustomer();
    $this->getJson(scanUrl('6291000000700'))->assertOk()->assertJsonPath('data.id', $p->id);
});

it('scanning then adding puts exactly one unit in the cart', function () {
    $p = Product::factory()->create(['barcode' => '6291000000800']);
    stocked($p, 5);
    actingAsCustomer();

    $variantId = $this->getJson(scanUrl('6291000000800'))->assertOk()->json('data.variant_id');
    $this->postJson('/api/v1/cart/items', ['variant_id' => $variantId, 'quantity' => 1])->assertCreated();
    $this->postJson('/api/v1/cart/items', ['variant_id' => $variantId, 'quantity' => 1])->assertCreated(); // a deliberate second scan
    $cart = $this->getJson('/api/v1/cart')->assertOk()->json('data');
    expect($cart['items'])->toHaveCount(1)->and($cart['items'][0]['quantity'])->toBe(2);
});

it('survives hostile and odd input without a server error', function (string $code) {
    Product::factory()->create(['sku' => 'SAFE-1', 'barcode' => '6291000000900']);

    $r = $this->getJson('/api/v1/products/by-code/'.$code);
    expect($r->getStatusCode())->toBe(404)->and($r->json('code'))->toBe('product_not_found');
    expect(DB::table('products')->count())->toBe(1);
})->with([
    'sql quote' => [rawurlencode("' OR '1'='1")],
    'sql comment' => [rawurlencode("1'; DROP TABLE products; --")],
    'union' => [rawurlencode("x' UNION SELECT id FROM users --")],
    'percent wildcard' => ['%25'],
    'like wildcard' => [rawurlencode('SAFE-_')],
    'sku prefix with wildcard' => [rawurlencode('SAFE-%')],
    'backslash' => ['%5C'],
    'double quotes' => ['%22%22'],
    'path traversal' => [rawurlencode('../../.env')],
    'script tag' => [rawurlencode('<script>alert(1)</script>')],
    'null byte' => ['SAFE%001'],
    'only a null byte' => ['%00'],
    'emoji' => [rawurlencode('🛋️🛋️')],
    'right-to-left marks' => [rawurlencode("\u{202E}SAFE-1x")],
    'zero width only' => [rawurlencode("\u{200B}\u{200B}")],
    'spaces only' => ['%20%20%20'],
    'dot' => ['.'],
    'plus' => ['%2B'],
    'whitespace inside' => [rawurlencode('SAFE 1')],
    'looks like a url with a null' => [rawurlencode("https://albahar.qa/products/x\0y")],
    'garbage uuid' => ['00000000-0000-0000-0000-000000000000'],
]);

it('answers a client error, never a server error, for malformed percent-encoding', function (string $code) {
    $status = $this->getJson('/api/v1/products/by-code/'.$code)->getStatusCode();

    expect($status)->toBeGreaterThanOrEqual(400)->toBeLessThan(500);
})->with(['invalid utf-8' => ['%FF%FE%FD'], 'half a character' => ['%E2%82'], 'link with bad bytes' => [rawurlencode('https://albahar.qa/products/').'%FF']]);

it('treats very long input as not found, quickly', function () {
    foreach ([str_repeat('9', 600), str_repeat('SAFE-1', 200), str_repeat('ب', 3000), 'https://albahar.qa/products/'.str_repeat('a', 5000)] as $long) {
        $started = microtime(true);
        $this->getJson(scanUrl($long))->assertStatus(404)->assertJsonPath('code', 'product_not_found');
        expect(microtime(true) - $started)->toBeLessThan(2.0);
    }
});

it('does not mistake unicode lookalikes for a code', function () {
    Product::factory()->create(['sku' => 'ABC-1']);

    $this->getJson(scanUrl('ＡＢＣ-1'))->assertStatus(404);   // full-width letters
    $this->getJson(scanUrl('АВС-1'))->assertStatus(404);     // Cyrillic А В С
});

it('handles a scan every second without cost growing with the catalogue: a handful of queries', function () {
    $p = Product::factory()->withVariants(4)->create(['barcode' => '6291001000000']);
    foreach ($p->variants()->get() as $i => $v) {
        $v->update(['barcode' => '62910010000'.($i + 1).'0']);
    }
    DB::enableQueryLog();
    $this->getJson(scanUrl('6291001000000'))->assertOk();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($count)->toBeLessThanOrEqual(16);
});

it('is rate limited per guest address and counts a signed-in shopper separately', function () {
    for ($i = 0; $i < 60; $i++) {
        $this->getJson(scanUrl('missing-'.$i))->assertStatus(404);
    }
    $this->getJson(scanUrl('one-too-many'))->assertStatus(429)->assertJsonPath('code', 'too_many_requests');

    $customer = actingAsCustomer();
    $this->getJson(scanUrl('missing-as-customer'))->assertStatus(404);
    expect($customer->id)->not->toBeNull();
});

it('looks every code up through an index (lower(sku) and lower(barcode))', function () {
    DB::statement('SET LOCAL enable_seqscan = off');
    foreach ([['products', 'sku'], ['products', 'barcode'], ['product_variants', 'sku'], ['product_variants', 'barcode']] as [$table, $column]) {
        $plan = collect(DB::select("EXPLAIN SELECT id FROM {$table} WHERE lower({$column}) in (lower('x'), lower('y'))"))->pluck('QUERY PLAN')->implode("\n");
        expect($plan)->toContain("{$table}_{$column}_lower_idx");
    }
});
