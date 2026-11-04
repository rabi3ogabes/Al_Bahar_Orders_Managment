<?php

use App\Domain\Branches\Models\Branch;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductBranchSetting;
use App\Domain\Catalog\Models\ProductPriceHistory;
use App\Domain\Catalog\Models\ProductVariantBranchSetting;
use App\Domain\Pricing\Services\PriceResolver;
use App\Domain\Pricing\Services\PricingService;
use App\Support\DomainException;
use Illuminate\Database\QueryException;

beforeEach(function () {
    $this->branch = Branch::factory()->create();
    $this->product = Product::factory()->create(['price' => '1000.00']);
    $this->variant = $this->product->variants()->first();
    $this->resolve = fn () => (new PriceResolver)->resolve($this->variant->fresh('product'), $this->branch);
});

it('applies the documented four-level price precedence', function () {
    expect(($this->resolve)()->final()->toDecimal())->toBe('1000.00')->and(($this->resolve)()->source)->toBe('product');

    $this->variant->update(['price' => '950.00']);
    expect(($this->resolve)()->final()->toDecimal())->toBe('950.00')->and(($this->resolve)()->source)->toBe('variant');

    ProductBranchSetting::create(['product_id' => $this->product->id, 'branch_id' => $this->branch->id, 'price' => '900.00']);
    expect(($this->resolve)()->final()->toDecimal())->toBe('900.00')->and(($this->resolve)()->source)->toBe('product_branch');

    ProductVariantBranchSetting::create(['product_variant_id' => $this->variant->id, 'branch_id' => $this->branch->id, 'price' => '850.00']);
    expect(($this->resolve)()->final()->toDecimal())->toBe('850.00')->and(($this->resolve)()->source)->toBe('variant_branch');

    // Other branches are unaffected by branch-specific overrides.
    $other = Branch::factory()->create();
    expect((new PriceResolver)->resolve($this->variant->fresh('product'), $other)->final()->toDecimal())->toBe('950.00');
});

it('uses the sale price of the winning level only within its window', function () {
    $this->product->update(['sale_price' => '800.00', 'sale_starts_at' => now()->subDay(), 'sale_ends_at' => now()->addDay()]);
    expect(($this->resolve)()->final()->toDecimal())->toBe('800.00')->and(($this->resolve)()->discountPercent())->toBe(20);

    $this->product->update(['sale_ends_at' => now()->subMinute()]);
    expect(($this->resolve)()->sale)->toBeNull();

    // A higher-precedence level without a sale overrides a lower level's sale.
    $this->product->update(['sale_ends_at' => now()->addDay()]);
    ProductBranchSetting::create(['product_id' => $this->product->id, 'branch_id' => $this->branch->id, 'price' => '990.00']);
    expect(($this->resolve)()->final()->toDecimal())->toBe('990.00');
});

it('never prices a product that is disabled in the branch', function () {
    ProductBranchSetting::create(['product_id' => $this->product->id, 'branch_id' => $this->branch->id, 'is_enabled' => false, 'price' => '1.00']);
    expect(($this->resolve)())->toBeNull();

    $selective = Product::factory()->selectedBranchesOnly()->create();
    $v = $selective->variants()->first();
    expect((new PriceResolver)->resolve($v, $this->branch))->toBeNull();
    ProductBranchSetting::create(['product_id' => $selective->id, 'branch_id' => $this->branch->id, 'is_enabled' => true]);
    expect((new PriceResolver)->resolve($v->fresh('product'), $this->branch)?->final()->toDecimal())->toBe('1000.00');

    ProductVariantBranchSetting::create(['product_variant_id' => $this->variant->id, 'branch_id' => $this->branch->id, 'is_enabled' => false]);
    ProductBranchSetting::where('product_id', $this->product->id)->update(['is_enabled' => true]);
    expect(($this->resolve)())->toBeNull();

    $this->product->update(['status' => 'inactive']);
    expect((new PriceResolver)->availability($this->variant->fresh('product'), null)->reason)->toBe('product_inactive');
});

it('validates sale prices and records price history with the actor', function () {
    $admin = staffWithRole('admin');
    $this->actingAs($admin);
    $svc = app(PricingService::class);

    expect(fn () => $svc->setProductPrice($this->product, '100.00', '150.00'))->toThrow(DomainException::class);
    expect(fn () => $svc->setProductBranchPrice($this->product, $this->branch->id, null, '50.00'))->toThrow(DomainException::class);
    expect(fn () => $svc->setProductPrice($this->product, '100.00', '90.00', now(), now()->subDay()))->toThrow(DomainException::class);

    $svc->setProductBranchPrice($this->product, $this->branch->id, '920.00', '880.00', null, null, 'Ramadan promo');
    $h = ProductPriceHistory::firstOrFail();
    expect($h->branch_id)->toBe($this->branch->id)->and($h->new_price)->toBe('920.00')->and($h->changed_by)->toBe($admin->id)->and($h->reason)->toBe('Ramadan promo');

    expect(fn () => $h->delete())->toThrow(QueryException::class); // append-only
});

it('exposes branch-aware effective prices in the storefront API', function () {
    ProductBranchSetting::create(['product_id' => $this->product->id, 'branch_id' => $this->branch->id, 'price' => '899.00']);
    $this->getJson("/api/v1/products/{$this->product->id}?branch_id={$this->branch->id}")
        ->assertOk()->assertJsonPath('data.price.final.amount', '899.00')->assertJsonPath('data.price.source', 'product_branch')
        ->assertJsonPath('data.price.final.currency', 'QAR');
    $this->getJson("/api/v1/products/{$this->product->id}")->assertJsonPath('data.price.final.amount', '1000.00');
});

it('filters and sorts by effective branch price in SQL', function () {
    $cheap = Product::factory()->create(['price' => '200.00']);
    $mid = Product::factory()->create(['price' => '500.00', 'sale_price' => '150.00']);
    ProductBranchSetting::create(['product_id' => $this->product->id, 'branch_id' => $this->branch->id, 'price' => '100.00']);

    $ids = $this->getJson("/api/v1/products?branch_id={$this->branch->id}&sort=price")->assertOk()->json('data.*.id');
    expect($ids)->toBe([$this->product->id, $mid->id, $cheap->id]);

    $ids = $this->getJson("/api/v1/products?branch_id={$this->branch->id}&price_min=120&price_max=250")->json('data.*.id');
    expect($ids)->toEqualCanonicalizing([$mid->id, $cheap->id]);

    expect($this->getJson('/api/v1/products?on_sale=1')->json('data.*.id'))->toBe([$mid->id]);
});
