<?php

use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Marketing\Models\Coupon;
use App\Domain\Marketing\Models\Promotion;
use App\Domain\Marketing\Support\PromotionSummary;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

// Promotion exclusions (docs/guides/PROMOTION_EXCLUSIONS.md): "the whole basket EXCEPT these". The server is the source of truth, so every rule is checked on the quote.
// The basket: a sofa worth 1000.00 (p1, one unit) and a rug worth 250.00 (p2, two units = 500.00).

function exPromo(array $a = []): Promotion
{
    return Promotion::create(array_merge(['name_en' => 'Ex promo', 'name_ar' => 'عرض', 'type' => 'percentage', 'value' => '10', 'scope' => 'order', 'requires_coupon' => false, 'is_active' => true], $a));
}

beforeEach(function () {
    $this->w = commerceWorld();
    $this->postJson('/api/v1/cart/items', ['variant_id' => $this->w->v1->id, 'quantity' => 1]);   // 1000.00
    $this->postJson('/api/v1/cart/items', ['variant_id' => $this->w->v2->id, 'quantity' => 2]);   // 500.00
    $this->opts = ['address_id' => $this->w->address->id, 'payment_method' => 'cod'];
    $this->quote = fn (array $extra = []) => $this->postJson('/api/v1/checkout/quote', $this->opts + $extra)->json('data');
    $this->discount = fn (array $extra = []) => ($this->quote)($extra)['totals']['discount']['amount'];
});

describe('the engine never discounts an excluded line', function () {
    it('leaves out a product: the rest of the basket is discounted, the product pays full price', function () {
        $promo = exPromo();
        expect(($this->discount)())->toBe('150.00');                                              // 10% of 1500.00

        $promo->excludedProducts()->attach($this->w->p1->id);

        expect(($this->discount)())->toBe('50.00');                                               // 10% of the 500.00 rug only
        $lines = collect(($this->quote)()['lines']);
        expect($lines->firstWhere('variant_id', $this->w->v1->id)['discount']['amount'])->toBe('0.00')->and($lines->firstWhere('variant_id', $this->w->v2->id)['discount']['amount'])->toBe('50.00');
    });

    it('leaves out a category and all its sub-categories, and only those', function () {
        $parent = Category::factory()->create();
        $child = Category::factory()->create(['parent_id' => $parent->id, 'path' => '/'.$parent->id.'/', 'depth' => 1]);
        $other = Category::factory()->create();
        $this->w->p1->update(['category_id' => $child->id]);                                       // the sofa sits in a sub-category of the excluded one
        $this->w->p2->update(['category_id' => $other->id]);
        exPromo()->excludedCategories()->attach($parent->id);

        expect(($this->discount)())->toBe('50.00');
    });

    it('leaves out a brand', function () {
        $brand = Brand::factory()->create();
        $this->w->p2->update(['brand_id' => $brand->id]);
        exPromo()->excludedBrands()->attach($brand->id);

        expect(($this->discount)())->toBe('100.00');                                              // only the sofa is discounted
    });

    it('lets an exclusion win over an inclusion of the same product', function () {
        $promo = exPromo(['scope' => 'products']);
        $promo->products()->attach([$this->w->p1->id, $this->w->p2->id]);
        expect(($this->discount)())->toBe('150.00');

        $promo->excludedProducts()->attach($this->w->p1->id);

        expect(($this->discount)())->toBe('50.00');
    });

    it('lets a brand exclusion win over an inclusion by product, and a category exclusion over an inclusion by category', function () {
        $brand = Brand::factory()->create();
        $this->w->p1->update(['brand_id' => $brand->id]);
        $byProduct = exPromo(['scope' => 'products']);
        $byProduct->products()->attach([$this->w->p1->id, $this->w->p2->id]);
        $byProduct->excludedBrands()->attach($brand->id);
        expect(($this->discount)())->toBe('50.00');                                               // the sofa is in the SKU list but its brand is left out

        $byProduct->delete();
        $cat = Category::factory()->create();
        $this->w->p1->update(['category_id' => $cat->id]);
        $byCategory = exPromo(['scope' => 'categories']);
        $byCategory->categories()->attach($cat->id);
        expect(($this->discount)())->toBe('100.00');                                              // the sofa's brand is no longer excluded, only discounted via its category
        $byCategory->excludedProducts()->attach($this->w->p1->id);
        expect(($this->discount)())->toBe('0.00');                                                // included by category, excluded by product: excluded
    });

    it('gives no discount at all when everything in the basket is left out, and says so on a coupon', function () {
        $promo = exPromo(['requires_coupon' => true]);
        Coupon::create(['promotion_id' => $promo->id, 'code' => 'ALLOUT']);
        $promo->excludedProducts()->attach([$this->w->p1->id, $this->w->p2->id]);

        $q = ($this->quote)(['coupon_code' => 'ALLOUT']);

        expect($q['totals']['discount']['amount'])->toBe('0.00')
            ->and(collect($q['warnings'])->pluck('code')->all())->toContain('coupon_excluded')
            ->and(collect($q['warnings'])->firstWhere('code', 'coupon_excluded')['message'])->toBe('The items in your cart are not part of this offer.');
    });

    it('does not call it "excluded" when the basket was never covered by the offer', function () {
        $promo = exPromo(['requires_coupon' => true, 'scope' => 'products']);
        Coupon::create(['promotion_id' => $promo->id, 'code' => 'ELSEWHERE']);
        $promo->products()->attach(Product::factory()->create()->id);
        $promo->excludedProducts()->attach($this->w->p1->id);

        expect(collect(($this->quote)(['coupon_code' => 'ELSEWHERE'])['warnings'])->pluck('code')->all())->toContain('coupon_not_eligible')->not->toContain('coupon_excluded');
    });

    it('says it in Arabic when the customer shops in Arabic', function () {
        $promo = exPromo(['requires_coupon' => true]);
        Coupon::create(['promotion_id' => $promo->id, 'code' => 'ALLOUT']);
        $promo->excludedProducts()->attach([$this->w->p1->id, $this->w->p2->id]);

        $warning = collect($this->withHeaders(['X-Locale' => 'ar'])->postJson('/api/v1/checkout/quote', $this->opts + ['coupon_code' => 'ALLOUT'])->json('data.warnings'))->firstWhere('code', 'coupon_excluded');

        expect($warning['message'])->toBe('المنتجات في سلتك لا يشملها هذا العرض.');
    });
});

describe('what the exclusion changes around it', function () {
    it('counts only the eligible lines towards the minimum spend', function () {
        $promo = exPromo(['min_basket_amount' => '600.00']);
        expect(($this->discount)())->toBe('150.00');                                              // 1500.00 reaches 600.00

        $promo->excludedProducts()->attach($this->w->p1->id);                                       // 500.00 of eligible lines no longer does

        expect(($this->discount)())->toBe('0.00');
        $promo->update(['min_basket_amount' => '500.00']);
        expect(($this->discount)())->toBe('50.00');                                               // exactly the minimum is enough
    });

    it('reports the minimum-spend problem on a coupon, counted on the eligible lines', function () {
        $promo = exPromo(['requires_coupon' => true, 'min_basket_amount' => '600.00']);
        Coupon::create(['promotion_id' => $promo->id, 'code' => 'BIGSPEND']);
        $promo->excludedProducts()->attach($this->w->p1->id);

        expect(collect(($this->quote)(['coupon_code' => 'BIGSPEND'])['warnings'])->pluck('code')->all())->toContain('coupon_min_basket');
    });

    it('is inherited by every coupon of the promotion', function () {
        $promo = exPromo(['requires_coupon' => true]);
        Coupon::create(['promotion_id' => $promo->id, 'code' => 'ONE']);
        Coupon::create(['promotion_id' => $promo->id, 'code' => 'TWO']);
        $promo->excludedProducts()->attach($this->w->p1->id);

        expect(($this->discount)(['coupon_code' => 'ONE']))->toBe('50.00')->and(($this->discount)(['coupon_code' => 'TWO']))->toBe('50.00')
            ->and(($this->quote)(['coupon_code' => 'ONE'])['discounts'][0]['code'])->toBe('ONE');
    });

    it('caps a fixed amount at what the eligible lines are worth, not the whole basket', function () {
        $promo = exPromo(['type' => 'fixed', 'value' => '700.00']);
        expect(($this->discount)())->toBe('700.00');

        $promo->excludedProducts()->attach($this->w->p1->id);

        expect(($this->discount)())->toBe('500.00');
    });

    it('does not count an excluded unit in a buy-one-get-one', function () {
        $promo = exPromo(['type' => 'bogo', 'buy_quantity' => 1, 'get_quantity' => 1, 'get_discount_percent' => '100']);
        expect(($this->discount)())->toBe('250.00');                                              // 3 units: one group of two, the cheapest free

        $promo->excludedProducts()->attach($this->w->p2->id);                                       // leaves only the single sofa: no complete group

        expect(($this->discount)())->toBe('0.00');
    });

    it('does not count an excluded line towards a bundle', function () {
        $promo = exPromo(['type' => 'bundle', 'value' => '20', 'buy_quantity' => 3]);
        expect(($this->discount)())->toBe('300.00');                                              // 3 units, 20% of 1500.00

        $promo->excludedProducts()->attach($this->w->p1->id);

        expect(($this->discount)())->toBe('0.00');                                                // only 2 eligible units
    });

    it('keeps a promotion without exclusions exactly as it was', function () {
        exPromo();

        expect(($this->discount)())->toBe('150.00')->and(PromotionSummary::of(Promotion::firstOrFail()))->toBe('Whole basket');
    });

    it('works with the branch restriction and a stackable partner at the same time', function () {
        $first = exPromo(['is_stackable' => true, 'priority' => 1, 'value' => '10']);
        exPromo(['is_stackable' => true, 'priority' => 2, 'value' => '10']);
        $first->excludedProducts()->attach($this->w->p1->id);
        $first->branches()->attach($this->w->a->id);

        // the second promotion discounts everything: 10% of 1500.00 = 150.00; the first (branch A only, sofa left out) adds 10% of what is left of the rug
        expect((float) ($this->discount)())->toBeGreaterThan(150.0)->and((float) ($this->discount)())->toBeLessThanOrEqual(200.0);
    });
});

describe('boundaries', function () {
    it('works with 200 products left out, and still discounts the one that is not', function () {
        $promo = exPromo();
        $ids = Product::factory()->count(199)->create()->pluck('id')->push($this->w->p1->id)->all();
        $promo->excludedProducts()->attach($ids);

        expect($promo->excludedProducts()->count())->toBe(200)->and(($this->discount)())->toBe('50.00');
    });

    it('does not care about a product that is gone or not on sale in the list', function () {
        $promo = exPromo();
        $gone = Product::factory()->create();
        $draft = Product::factory()->create(['status' => 'draft']);
        $promo->excludedProducts()->attach([$gone->id, $draft->id, $this->w->p1->id]);
        $gone->forceDelete();                                                                       // the row in the list goes with the product

        expect($promo->excludedProducts()->count())->toBe(2)->and(($this->discount)())->toBe('50.00');
    });

    it('goes when the promotion goes, and a deleted brand or category leaves no row behind', function () {
        $promo = exPromo();
        $brand = Brand::factory()->create();
        $cat = Category::factory()->create();
        $promo->excludedBrands()->attach($brand->id);
        $promo->excludedCategories()->attach($cat->id);
        $brand->delete();
        $cat->delete();

        expect(DB::table('promotion_excluded_brands')->count())->toBe(0)->and(DB::table('promotion_excluded_categories')->count())->toBe(0);
        $promo->excludedProducts()->attach($this->w->p1->id);
        $promo->delete();
        expect(DB::table('promotion_excluded_products')->count())->toBe(0);
    });

    it('cannot put the same product twice in the list', function () {
        $promo = exPromo();
        $promo->excludedProducts()->attach($this->w->p1->id);

        expect(fn () => $promo->excludedProducts()->attach($this->w->p1->id))->toThrow(UniqueConstraintViolationException::class);
    });
});

describe('the plain line in the list', function () {
    it('says what is covered and what is left out', function (array $scope, array $left, string $expected) {
        $promo = exPromo(['scope' => $scope[0]]);
        if ($scope[0] === 'products') {
            $promo->products()->attach(Product::factory()->count($scope[1])->create()->pluck('id'));
        }
        if ($scope[0] === 'categories') {
            $promo->categories()->attach(Category::factory()->count($scope[1])->create()->pluck('id'));
        }
        $promo->excludedProducts()->attach(Product::factory()->count($left[0])->create()->pluck('id'));
        $promo->excludedCategories()->attach(Category::factory()->count($left[1])->create()->pluck('id'));
        $promo->excludedBrands()->attach(Brand::factory()->count($left[2])->create()->pluck('id'));

        expect(PromotionSummary::of($promo->fresh()))->toBe($expected);
    })->with([
        'whole basket, nothing left out' => [['order'], [0, 0, 0], 'Whole basket'],
        'whole basket except 3 products, 1 brand' => [['order'], [3, 0, 1], 'Whole basket except 3 products, 1 brand'],
        'whole basket except one of each' => [['order'], [1, 1, 1], 'Whole basket except 1 product, 1 category, 1 brand'],
        '2 categories except 1 product' => [['categories', 2], [1, 0, 0], '2 categories except 1 product'],
        '1 product except 2 brands' => [['products', 1], [0, 0, 2], '1 product except 2 brands'],
    ]);

    it('says it in Arabic with the right plural words', function () {
        $promo = exPromo();
        $promo->excludedProducts()->attach(Product::factory()->count(3)->create()->pluck('id'));
        $promo->excludedBrands()->attach(Brand::factory()->count(1)->create()->pluck('id'));

        expect(PromotionSummary::of($promo->fresh(), 'ar'))->toBe('كامل السلة عدا 3 منتجات، علامة تجارية واحدة');
    });
});
