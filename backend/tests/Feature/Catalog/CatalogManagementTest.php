<?php

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductAttribute;
use App\Domain\Catalog\Services\CategoryService;
use App\Support\DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

function productPayload(array $over = []): array
{
    return array_replace_recursive([
        'sku' => 'SOF-100', 'price' => '2499.00', 'status' => 'active',
        'translations' => ['en' => ['name' => 'Milano Velvet Sofa', 'short_description' => 'Deep-seated velvet sofa'],
            'ar' => ['name' => 'كنبة ميلانو المخملية', 'short_description' => 'كنبة مخملية عميقة']],
        'specifications' => [['label_en' => 'Seats', 'label_ar' => 'المقاعد', 'value_en' => '3', 'value_ar' => '٣']],
        'warranty' => ['duration_months' => 24, 'provider' => 'store'],
    ], $over);
}

it('creates a bilingual product with a default variant and enforces unique SKU/barcode', function () {
    actingAsStaff(staffWithRole('admin'));
    $id = $this->postJson('/api/v1/admin/catalog/products', productPayload(['barcode' => '6291234567890']))->assertCreated()->json('data.id');
    $p = Product::with('variants', 'translations')->find($id);
    expect($p->variants)->toHaveCount(1)->and($p->variants[0]->sku)->toBe('SOF-100')
        ->and($p->trans('name', 'ar'))->toBe('كنبة ميلانو المخملية')->and($p->warranty->duration_months)->toBe(24);

    $this->postJson('/api/v1/admin/catalog/products', productPayload())->assertStatus(422)->assertJsonPath('code', 'sku_taken');
    $this->postJson('/api/v1/admin/catalog/products', productPayload(['sku' => 'SOF-200', 'barcode' => '6291234567890']))->assertStatus(422)->assertJsonPath('code', 'barcode_taken');
});

it('manages variants with attributes and unique variant SKUs', function () {
    actingAsStaff(staffWithRole('admin'));
    $color = ProductAttribute::create(['code' => 'color', 'name_en' => 'Colour', 'name_ar' => 'اللون', 'type' => 'color']);
    $grey = $color->values()->create(['code' => 'grey', 'value_en' => 'Grey', 'value_ar' => 'رمادي', 'color_hex' => '#9a9a9a']);
    $sand = $color->values()->create(['code' => 'sand', 'value_en' => 'Sand', 'value_ar' => 'رملي', 'color_hex' => '#d8c7a6']);

    $id = $this->postJson('/api/v1/admin/catalog/products', productPayload(['variants' => [
        ['sku' => 'SOF-100-GRY', 'attribute_value_ids' => [$grey->id]],
        ['sku' => 'SOF-100-SND', 'price' => '2699.00', 'attribute_value_ids' => [$sand->id]],
    ]]))->assertCreated()->json('data.id');
    $this->postJson("/api/v1/admin/catalog/products/{$id}/variants", ['sku' => 'SOF-100-GRY'])->assertStatus(422)->assertJsonPath('code', 'sku_taken');

    $res = $this->getJson("/api/v1/products/{$id}", ['Accept-Language' => 'ar'])->assertOk();
    expect($res->json('data.variants'))->toHaveCount(2)
        ->and($res->json('data.attributes.0.values.*.name'))->toBe(['رمادي', 'رملي'])
        ->and($res->json('data.variants.1.price.final.amount'))->toBe('2699.00')
        ->and($res->json('data.price.final.amount'))->toBe('2499.00') // "from" price
        ->and($res->json('data.has_variants'))->toBeTrue();
});

it('enforces one translation per locale', function () {
    $p = Product::factory()->create();
    expect(fn () => $p->translations()->create(['locale' => 'en', 'name' => 'Dup', 'slug' => 'dup']))->toThrow(\Illuminate\Database\QueryException::class);
});

it('prevents circular category nesting and supports deep trees', function () {
    $svc = app(CategoryService::class);
    $living = $svc->create(['translations' => ['en' => ['name' => 'Living'], 'ar' => ['name' => 'المعيشة']]]);
    $sofas = $svc->create(['parent_id' => $living->id, 'translations' => ['en' => ['name' => 'Sofas'], 'ar' => ['name' => 'الكنب']]]);
    $sectional = $svc->create(['parent_id' => $sofas->id, 'translations' => ['en' => ['name' => 'Sectionals'], 'ar' => ['name' => 'كنب زاوية']]]);

    expect(fn () => $svc->move($living->fresh(), $sectional->id))->toThrow(DomainException::class);
    expect(fn () => $svc->move($living->fresh(), $living->id))->toThrow(DomainException::class);
    expect($living->fresh()->descendantAndSelfIds())->toEqualCanonicalizing([$living->id, $sofas->id, $sectional->id]);

    // Moving a subtree re-roots descendants.
    $bedroom = $svc->create(['translations' => ['en' => ['name' => 'Bedroom'], 'ar' => ['name' => 'غرف النوم']]]);
    $svc->move($sofas->fresh(), $bedroom->id);
    expect($sectional->fresh()->depth)->toBe(2)->and($bedroom->fresh()->descendantAndSelfIds())->toContain($sectional->id);

    $tree = $this->getJson('/api/v1/categories', ['Accept-Language' => 'ar'])->assertOk()->json('data');
    $bedroomNode = collect($tree)->firstWhere('id', $bedroom->id);
    expect($bedroomNode['name'])->toBe('غرف النوم')->and($bedroomNode['children'][0]['children'][0]['name'])->toBe('كنب زاوية');
});

it('searches Arabic and English names, partial words, SKU and barcode', function () {
    $sofa = Product::factory()->create(['sku' => 'SOF-777', 'barcode' => '6290000000777']);
    $sofa->translations()->update([]); // keep factory names
    $sofa->translations()->where('locale', 'en')->first()->update(['name' => 'Oslo Linen Sofa']);
    $sofa->translations()->where('locale', 'ar')->first()->update(['name' => 'كَنَبة أوسلو الكتان']);
    $lamp = Product::factory()->create();
    $lamp->translations()->where('locale', 'en')->first()->update(['name' => 'Brass Floor Lamp', 'short_description' => 'Warm reading light']);
    $lamp->translations()->where('locale', 'ar')->first()->update(['name' => 'مصباح أرضي نحاسي', 'short_description' => 'إضاءة دافئة للقراءة']);

    expect($this->getJson('/api/v1/products/search?q=linen')->json('data.*.id'))->toBe([$sofa->id])
        ->and($this->getJson('/api/v1/products/search?q='.urlencode('كنبه اوسلو'))->json('data.*.id'))->toBe([$sofa->id]) // folded alef/taa marbuta, no tashkeel
        ->and($this->getJson('/api/v1/products/search?q=sof-77')->json('data.*.id'))->toBe([$sofa->id])
        ->and($this->getJson('/api/v1/products/search?q=6290000000777')->json('data.*.id'))->toBe([$sofa->id])
        ->and($this->getJson('/api/v1/products/search?q='.urlencode('نحاس'))->json('data.*.id'))->toBe([$lamp->id])
        ->and($this->getJson('/api/v1/products/search?q=lamb')->json('data.*.id'))->toContain($lamp->id); // typo tolerant
});

it('hides draft products and products not visible in the branch', function () {
    $branch = \App\Domain\Branches\Models\Branch::factory()->create();
    $visible = Product::factory()->create();
    Product::factory()->draft()->create();
    $hidden = Product::factory()->create();
    \App\Domain\Catalog\Models\ProductBranchSetting::create(['product_id' => $hidden->id, 'branch_id' => $branch->id, 'is_visible' => false]);

    expect($this->getJson("/api/v1/products?branch_id={$branch->id}")->json('data.*.id'))->toBe([$visible->id]);
});

it('imports products from CSV with a per-row error report', function () {
    actingAsStaff(staffWithRole('admin'));
    Category::factory()->create(['slug' => 'sofas']);
    $csv = "sku,name_en,name_ar,price,sale_price,status,category_slug\n"
        ."IMP-1,Lounge Chair,كرسي استرخاء,799.00,,active,sofas\n"
        ."IMP-2,Bad Price,سعر خاطئ,abc,,active,sofas\n"
        ."IMP-3,Sale Above,تخفيض أعلى,100,150,active,\n"
        ."IMP-4,Unknown Cat,فئة مجهولة,100,,active,nope\n";
    $file = UploadedFile::fake()->createWithContent('catalog.csv', $csv);

    $res = $this->postJson('/api/v1/admin/catalog/products/import', ['file' => $file])->assertCreated();
    expect($res->json('data.created_rows'))->toBe(1)->and($res->json('data.failed_rows'))->toBe(3)
        ->and(collect($res->json('data.errors'))->pluck('field')->all())->toContain('price', 'sale_price', 'category_slug');
    expect(Product::where('sku', 'IMP-1')->first()->trans('name', 'ar'))->toBe('كرسي استرخاء');

    // Re-import updates instead of duplicating.
    $this->postJson('/api/v1/admin/catalog/products/import', ['file' => UploadedFile::fake()->createWithContent('c.csv', "sku,name_en,name_ar,price\nIMP-1,Lounge Chair II,كرسي,850\n")])
        ->assertJsonPath('data.updated_rows', 1);
    expect(Product::where('sku', 'IMP-1')->value('price'))->toBe('850.00');
});

it('blocks catalog edits without permission and branch overrides outside the user branch', function () {
    $a = \App\Domain\Branches\Models\Branch::factory()->create();
    $b = \App\Domain\Branches\Models\Branch::factory()->create();
    $p = Product::factory()->create();

    actingAsStaff(staffWithRole('sales_employee', $a));
    $this->postJson('/api/v1/admin/catalog/products', productPayload())->assertForbidden();
    $this->patchJson("/api/v1/admin/catalog/products/{$p->id}", ['price' => 1])->assertForbidden();

    actingAsStaff(staffWithRole('branch_manager', $a));
    $this->postJson("/api/v1/admin/catalog/products/{$p->id}/branch-settings", ['branch_id' => $a->id, 'price' => '900'])->assertOk();
    $this->postJson("/api/v1/admin/catalog/products/{$p->id}/branch-settings", ['branch_id' => $b->id, 'price' => '1'])->assertForbidden();
});

it('stores optimized images on object storage with reference-checked deletion', function () {
    Storage::fake('public');
    actingAsStaff(staffWithRole('admin'));
    $p = Product::factory()->create();
    $res = $this->post("/api/v1/admin/catalog/products/{$p->id}/images", ['image' => UploadedFile::fake()->image('sofa.jpg', 1600, 1000)], ['Accept' => 'application/json'])->assertCreated();
    $img = \App\Domain\Catalog\Models\ProductImage::find($res->json('data.id'));
    Storage::disk('public')->assertExists($img->path);
    expect(array_map('intval', array_keys($img->variants)))->toBe([320, 640, 1280]);

    $this->post("/api/v1/admin/catalog/products/{$p->id}/images", ['image' => UploadedFile::fake()->create('evil.php', 10, 'application/x-php')], ['Accept' => 'application/json'])->assertStatus(422);

    $copy = app(\App\Domain\Catalog\Services\ProductService::class)->duplicate($p);
    $this->deleteJson("/api/v1/admin/catalog/images/{$img->id}")->assertOk();
    Storage::disk('public')->assertExists($img->path); // still referenced by the duplicate
});
