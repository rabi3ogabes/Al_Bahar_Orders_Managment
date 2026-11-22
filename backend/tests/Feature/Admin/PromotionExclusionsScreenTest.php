<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Marketing\Models\Promotion;
use App\Livewire\Admin\Marketing\PromotionIndex;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;
use Livewire\Livewire;

// The "Leave out" part of the promotion window and the plain line in the list (docs/guides/PROMOTION_EXCLUSIONS.md).

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->marketing = staffWithRole('marketing_manager');
    $this->actingAs($this->marketing);
    $this->sofa = Product::factory()->create(['sku' => 'SOF-OSLO']);
    $this->lamp = Product::factory()->create(['sku' => 'LMP-QAMAR']);
    $this->rug = Product::factory()->create(['sku' => 'RUG-SADU']);
    $this->archived = Product::factory()->create(['sku' => 'OLD-ONE', 'status' => 'archived']);
    $this->form = fn () => Livewire::test(PromotionIndex::class)->call('createPromotion')->set('p.name_en', 'Spring edit')->set('p.name_ar', 'عرض الربيع')
        ->set('p.type', 'percentage')->set('p.value', '15')->set('p.requires_coupon', false);
});

it('saves what a promotion leaves out and loads it back when editing', function () {
    $brand = Brand::factory()->create();
    $cat = Category::factory()->create();

    $c = ($this->form)()->call('addExcludedProduct', $this->sofa->id)->set('excludedCategoryIds', [$cat->id])->set('excludedBrandIds', [$brand->id])
        ->assertSee('Leave out')->assertSee('3 things are left out', false)->call('savePromotion')->assertHasNoErrors();

    $promo = Promotion::firstOrFail();
    expect($promo->scope)->toBe('order')->and($promo->excludedProducts()->pluck('products.id')->all())->toBe([$this->sofa->id])
        ->and($promo->excludedCategories()->pluck('categories.id')->all())->toBe([$cat->id])->and($promo->excludedBrands()->pluck('brands.id')->all())->toBe([$brand->id]);
    $log = AuditLog::where('action', 'marketing.promotion_created')->firstOrFail();
    expect($log->metadata['excluded_products'])->toBe([$this->sofa->id])->and($log->metadata['excluded_categories'])->toBe([$cat->id])->and($log->metadata['excluded_brands'])->toBe([$brand->id]);

    Livewire::test(PromotionIndex::class)->call('editPromotion', $promo->id)->assertSet('excludedProductIds', [$this->sofa->id])->assertSet('excludedCategoryIds', [$cat->id])
        ->assertSet('excludedBrandIds', [$brand->id])->assertViewHas('pickedExcluded', fn ($p) => $p->pluck('id')->all() === [$this->sofa->id])
        ->call('removeExcludedProduct', $this->sofa->id)->set('excludedBrandIds', [])->call('savePromotion')->assertHasNoErrors();
    expect($promo->excludedProducts()->count())->toBe(0)->and($promo->excludedBrands()->count())->toBe(0)->and($promo->excludedCategories()->count())->toBe(1);
});

it('searches and pastes SKUs for the products to leave out, the way the covered products are chosen', function () {
    $variantSku = $this->rug->variants()->first()->sku;
    $c = ($this->form)();

    $c->set('excludeSearch', 'SOF-OSL')->assertViewHas('excludeMatches', fn ($m) => $m->pluck('id')->all() === [$this->sofa->id])
        ->call('addExcludedProduct', $this->sofa->id)->assertSet('excludeSearch', '')
        ->set('excludeSearch', 'SOF-OSL')->assertViewHas('excludeMatches', fn ($m) => $m->isEmpty())                  // already in the list
        ->set('excludeSearch', 'OLD-ON')->assertViewHas('excludeMatches', fn ($m) => $m->isEmpty())                   // archived: never offered
        ->call('addExcludedProduct', $this->archived->id)->assertSet('excludedProductIds', [$this->sofa->id]);

    $c->set('excludeSkuPaste', "lmp-qamar, {$variantSku}\nSOF-OSLO\nNOPE-1")->call('addExcludedBySkus')
        ->assertDispatched('toast', type: 'warning')->assertSet('excludedProductIds', [$this->sofa->id, $this->lamp->id, $this->rug->id])->assertSet('excludeSkuPaste', '');
    // the covered-products list is a different list and was not touched
    $c->assertSet('productIds', []);
});

it('keeps the order the SKUs were pasted in', function () {
    ($this->form)()->set('excludeSkuPaste', "RUG-SADU\nLMP-QAMAR\nSOF-OSLO")->call('addExcludedBySkus')->assertSet('excludedProductIds', [$this->rug->id, $this->lamp->id, $this->sofa->id])
        ->assertViewHas('pickedExcluded', fn ($p) => $p->pluck('id')->all() === [$this->rug->id, $this->lamp->id, $this->sofa->id]);
});

it('stops at 200 products to leave out, and ignores garbage ids', function () {
    $many = fn (int $n) => collect(range(1, $n))->map(fn () => (string) Str::uuid())->all();
    $c = ($this->form)()->set('excludedProductIds', $many(PromotionIndex::MAX_PRODUCTS));

    $c->call('addExcludedProduct', "x' OR 1=1 --")->assertOk();
    $c->call('addExcludedProduct', $this->sofa->id)->assertDispatched('toast', type: 'warning');
    expect(count($c->get('excludedProductIds')))->toBe(PromotionIndex::MAX_PRODUCTS);

    $paste = ($this->form)()->set('excludedProductIds', $many(PromotionIndex::MAX_PRODUCTS - 1))->set('excludeSkuPaste', 'SOF-OSLO LMP-QAMAR')->call('addExcludedBySkus');
    expect(count($paste->get('excludedProductIds')))->toBe(PromotionIndex::MAX_PRODUCTS);
});

it('refuses ids that were forged past the screen', function () {
    ($this->form)()->set('excludedProductIds', ['not-a-uuid'])->call('savePromotion')->assertHasErrors('excludedProductIds.0');
    ($this->form)()->set('excludedBrandIds', [(string) Str::uuid()])->call('savePromotion')->assertHasErrors('excludedBrandIds.0');
    ($this->form)()->set('excludedCategoryIds', [(string) Str::uuid()])->call('savePromotion')->assertHasErrors('excludedCategoryIds.0');
    ($this->form)()->set('excludedProductIds', array_fill(0, PromotionIndex::MAX_PRODUCTS + 1, (string) Str::uuid()))->call('savePromotion')->assertHasErrors('excludedProductIds');
    expect(Promotion::count())->toBe(0);
});

it('lets a product be both covered and left out, and the leave-out wins when the engine runs', function () {
    $c = ($this->form)()->set('p.scope', 'products')->call('addProduct', $this->sofa->id)->call('addProduct', $this->lamp->id)->call('addExcludedProduct', $this->sofa->id)->call('savePromotion')->assertHasNoErrors();

    $promo = Promotion::firstOrFail();
    expect($promo->products()->count())->toBe(2)->and($promo->excludedProducts()->pluck('products.id')->all())->toBe([$this->sofa->id]);
});

it('is one promotion when "Save" is tapped twice, and the leave-out list is not doubled', function () {
    $c = ($this->form)()->call('addExcludedProduct', $this->sofa->id)->call('savePromotion')->call('savePromotion')->assertHasNoErrors();

    expect(Promotion::count())->toBe(1)->and(Promotion::firstOrFail()->excludedProducts()->count())->toBe(1);
});

it('clears the list with one tap', function () {
    ($this->form)()->call('addExcludedProduct', $this->sofa->id)->call('addExcludedProduct', $this->lamp->id)->set('excludedProductIds', [])->assertViewHas('pickedExcluded', fn ($p) => $p->isEmpty());
});

it('shows a plain line in the list, with the covered scope and what is left out', function () {
    $brand = Brand::factory()->create();
    $c = ($this->form)()->call('addExcludedProduct', $this->sofa->id)->call('addExcludedProduct', $this->lamp->id)->call('addExcludedProduct', $this->rug->id)->set('excludedBrandIds', [$brand->id])->call('savePromotion');

    Livewire::test(PromotionIndex::class)->assertSeeHtml('data-promotion-covers')->assertSee('Whole basket except 3 products, 1 brand')
        ->assertViewHas('rows', fn ($r) => $r->first()->excluded_products_count === 3 && $r->first()->excluded_brands_count === 1);
});

it('shows the line in Arabic', function () {
    $brand = Brand::factory()->create();
    ($this->form)()->call('addExcludedProduct', $this->sofa->id)->set('excludedBrandIds', [$brand->id])->call('savePromotion');
    app()->setLocale('ar');

    Livewire::test(PromotionIndex::class)->assertSee('كامل السلة عدا منتج واحد، علامة تجارية واحدة')->call('editPromotion', Promotion::firstOrFail()->id)
        ->assertSee('استثناء')->assertSee('منتجات مستثناة')->assertSee('أقسام مستثناة')->assertSee('علامات تجارية مستثناة');
});

it('reads the list with one extra query, not one per promotion', function () {
    foreach (range(1, 6) as $i) {
        $p = Promotion::create(['name_en' => "P{$i}", 'name_ar' => 'ع', 'type' => 'percentage', 'value' => '5', 'scope' => 'order', 'requires_coupon' => false, 'is_active' => true]);
        $p->excludedProducts()->attach($this->sofa->id);
    }
    DB::enableQueryLog();
    Livewire::test(PromotionIndex::class)->assertSee('Whole basket except 1 product');
    $counting = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'promotion_excluded_products'))->count();
    DB::disableQueryLog();

    expect($counting)->toBeLessThanOrEqual(2);
});

it('is closed to someone who may not edit promotions', function () {
    $this->actingAs(staffWithRole('auditor'));

    Livewire::test(PromotionIndex::class)->call('addExcludedProduct', $this->sofa->id)->assertForbidden();
    Livewire::test(PromotionIndex::class)->set('excludeSkuPaste', 'SOF-OSLO')->call('addExcludedBySkus')->assertForbidden();
});
