<?php

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductBranchSetting;
use App\Domain\Cms\Services\HomepageService;
use App\Domain\Deals\Models\Deal;
use App\Domain\Deals\Models\DealProduct;
use App\Domain\Deals\Models\DealRedemption;
use App\Domain\Deals\Services\DealService;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessControl;
use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Services\InventoryLedger;
use App\Domain\Orders\Enums\FulfillmentStatus as FS;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Services\OrderService;
use App\Livewire\Admin\Marketing\DealIndex;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

require_once __DIR__.'/DealFixtures.php';

beforeEach(function () {
    $this->w = commerceWorld();
});

/** Walk a fulfillment through the states in order (as the system, so nobody needs a role or a driver). */
function dealWalk($fulfillment, FS ...$states): void
{
    foreach ($states as $to) {
        app(OrderService::class)->transition($fulfillment->fresh(), $to, null, null, [], true);
    }
}

function dealFails(callable $save, string $field): ValidationException
{
    try {
        $save();
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey($field);

        return $e;
    }
    test()->fail("expected a validation error on $field");
}

// ─── 1. Failed and returned deliveries give the units back ──────────────────────────────────────────────────────────

it('gives the deal units back when a delivery fails for good and the parcel is cancelled', function () {
    makeDeal(['limit_per_order' => 2, 'limit_per_customer' => 2], [$this->w->p1->id => 10]);
    dealAddToCart($this->w->v1->id, 2);
    $order = dealOrderOf(placeDealOrder()->assertCreated());
    $f = $order->fulfillments()->first();

    dealWalk($f, FS::Accepted, FS::Preparing, FS::Ready, FS::Dispatched, FS::Failed);
    expect(DealProduct::first()->quantity_sold)->toBe(2); // out for delivery or failed: the units are still with the order

    dealWalk($f, FS::Cancelled);

    expect(DealProduct::first()->quantity_sold)->toBe(0)->and(DealRedemption::first()->released_at)->not->toBeNull();
    dealAddToCart($this->w->v1->id, 2);
    expect(dealQuote()['lines'][0]['deal']['units_at_deal_price'])->toBe(2); // the customer's own allowance is back too
    // The order still remembers the deal price it got (the invoice), only the deal's count moved.
    expect($order->items()->first()->deal_units)->toBe(2);
});

it('gives the deal units back when a delivered parcel is returned, and keeps them while it is only delivered', function () {
    makeDeal(['limit_per_order' => 2, 'limit_per_customer' => 2], [$this->w->p1->id => 10]);
    dealAddToCart($this->w->v1->id, 2);
    $order = dealOrderOf(placeDealOrder()->assertCreated());
    $f = $order->fulfillments()->first();

    dealWalk($f, FS::Accepted, FS::Preparing, FS::Ready, FS::Dispatched, FS::Delivered);
    expect(DealProduct::first()->quantity_sold)->toBe(2)->and(DealRedemption::first()->released_at)->toBeNull(); // delivered goods stay sold

    dealWalk($f, FS::Returned);

    expect(DealProduct::first()->quantity_sold)->toBe(0)->and(DealRedemption::first()->released_at)->not->toBeNull();
    expect(app(DealService::class)->release($order->id))->toBe(0); // once
});

// ─── 2. Lock order and deadlock retry ───────────────────────────────────────────────────────────────────────────────

it('locks the product rows of a deal before the deal row when an admin saves, the order checkout uses', function () {
    $deal = makeDeal();
    DB::flushQueryLog();
    DB::enableQueryLog();

    makeDeal(['name_en' => 'Renamed'], [$this->w->p1->id => 10], $deal);

    $locks = collect(DB::getQueryLog())->pluck('query')->filter(fn ($q) => str_contains($q, 'for update'))->values();
    $products = $locks->search(fn ($q) => str_contains($q, '"deal_products"'));
    $deals = $locks->search(fn ($q) => str_contains($q, 'from "deals"'));
    DB::disableQueryLog();
    expect($products)->not->toBeFalse()->and($deals)->not->toBeFalse()->and($products)->toBeLessThan($deals);
});

it('tries an order again when the database picks it as a deadlock victim', function () {
    $service = new class(app(AuditLogger::class), app(AccessControl::class)) extends DealService
    {
        public static int $claims = 0;

        public function claim(Order $order, User $user, array $lines): void
        {
            if (self::$claims++ === 0) {
                throw new QueryException('pgsql', 'update deal_products', [], new PDOException('SQLSTATE[40P01]: Deadlock detected: 7 ERROR: deadlock detected'));
            }
            parent::claim($order, $user, $lines);
        }
    };
    app()->instance(DealService::class, $service);
    makeDeal(['limit_per_order' => 5]);
    dealAddToCart($this->w->v1->id, 2);

    $res = placeDealOrder()->assertCreated();

    expect($service::$claims)->toBe(2)->and(Order::count())->toBe(1)->and(DealProduct::first()->quantity_sold)->toBe(2)
        ->and(DealRedemption::count())->toBe(1)->and($res->json('data.order.items.0.deal.units'))->toBe(2);
});

it('gives up after three deadlocks instead of looping', function () {
    $service = new class(app(AuditLogger::class), app(AccessControl::class)) extends DealService
    {
        public static int $claims = 0;

        public function claim(Order $order, User $user, array $lines): void
        {
            self::$claims++;
            throw new QueryException('pgsql', 'update deal_products', [], new PDOException('SQLSTATE[40P01]: Deadlock detected: 7 ERROR: deadlock detected'));
        }
    };
    app()->instance(DealService::class, $service);
    makeDeal(['limit_per_order' => 5]);
    dealAddToCart($this->w->v1->id, 1);

    placeDealOrder()->assertStatus(500);

    expect($service::$claims)->toBe(3)->and(Order::count())->toBe(0)->and(DealProduct::first()->quantity_sold)->toBe(0);
});

// ─── 3. Buy-and-cancel warning ──────────────────────────────────────────────────────────────────────────────────────

it('warns in the editor and the list when a product has a quantity but nobody is limited', function () {
    $deal = makeDeal(['limit_per_order' => null, 'limit_per_customer' => null], [$this->w->p1->id => 10]);

    $c = Livewire::actingAs(dealAdmin())->test(DealIndex::class)->assertSee('No buyer limit')->assertSeeHtml('data-no-buyer-limit');
    $c->call('editDeal', $deal->id)->assertSee('No limit for this product')->assertSee('has a quantity but no limit per order or per customer');
    // A limit anywhere (the deal, or the product) removes the warning.
    $c->set('form.limit_per_customer', '2')->assertDontSee('No limit for this product');
    $c->set('form.limit_per_customer', '')->set('products.0.limit_per_order', '3')->assertDontSee('No limit for this product');
    // Unlimited stock needs no warning: there is no quantity to run out of.
    $c->set('products.0.limit_per_order', '')->set('products.0.quantity_total', '')->assertDontSee('No limit for this product');
    // It is a warning, never a block: the admin can still save.
    Livewire::actingAs(dealAdmin())->test(DealIndex::class)->call('editDeal', $deal->id)->set('form.name_en', 'Saved anyway')->call('save')->assertHasNoErrors();
    expect(Deal::first()->name_en)->toBe('Saved anyway')->and(Deal::first()->lacksBuyerLimit())->toBeTrue();
});

it('shows no warning chip for a deal that has a limit', function () {
    makeDeal(['limit_per_order' => 2]);

    Livewire::actingAs(dealAdmin())->test(DealIndex::class)->assertDontSeeHtml('data-no-buyer-limit');
});

// ─── 4. A price typo cannot sell for almost nothing ─────────────────────────────────────────────────────────────────

it('refuses a deal price below 5 % of the normal price and names the product', function () {
    $sofa = dealProduct('350.00');

    $e = dealFails(fn () => makeDeal(['discount_type' => 'amount_off', 'discount_value' => '400'], [$sofa->id => 5]), 'discount_value');
    expect($e->errors()['discount_value'][0])->toContain($sofa->trans('name'))->toContain('5%')->toContain('QAR 17.50');
    dealFails(fn () => makeDeal(['discount_type' => 'fixed_price', 'discount_value' => '0', 'products' => [['product_id' => $sofa->id, 'deal_price' => '10.00', 'quantity_total' => 5]]], []), 'products.0.deal_price');
    dealFails(fn () => makeDeal(['discount_value' => '30', 'products' => [['product_id' => $sofa->id, 'deal_price' => '1.00', 'quantity_total' => 5]]], []), 'products.0.deal_price');
    expect(Deal::count())->toBe(0);
});

it('accepts a deal price of exactly 5 % and the 95 % cap', function () {
    $sofa = dealProduct('400.00');

    expect(makeDeal(['discount_type' => 'amount_off', 'discount_value' => '380'], [$sofa->id => 5])->exists)->toBeTrue(); // 20.00 = 5 %
    expect(makeDeal(['name_en' => 'B', 'discount_value' => '95', 'is_active' => false], [$sofa->id => 5])->exists)->toBeTrue();
    dealFails(fn () => makeDeal(['name_en' => 'C', 'discount_type' => 'amount_off', 'discount_value' => '380.01', 'is_active' => false], [$sofa->id => 5]), 'discount_value');
});

it('checks every variant, not only the cheapest', function () {
    $p = Product::factory()->withVariants(2)->create(['price' => '1000.00']);
    $p->variants()->orderBy('sort_order')->get()->last()->update(['price' => '60.00']);

    dealFails(fn () => makeDeal(['discount_type' => 'amount_off', 'discount_value' => '900'], [$p->id => 5]), 'discount_value'); // 100.00 for the first variant is fine, 0.01 for the 60.00 one is not
});

// ─── 5. Plain decimals only ─────────────────────────────────────────────────────────────────────────────────────────

it('refuses numbers that pass "numeric" but break the money rules, with a plain message', function (string $type, string $field, string $value) {
    $over = $field === 'discount_value' ? ['discount_type' => $type, 'discount_value' => $value] : ['discount_type' => 'fixed_price', 'discount_value' => '0', 'products' => [['product_id' => $this->w->p1->id, 'deal_price' => $value, 'quantity_total' => 5]]];

    $e = dealFails(fn () => makeDeal($over, $field === 'discount_value' ? null : []), $field === 'discount_value' ? $field : 'products.0.deal_price');

    expect(collect($e->errors())->flatten()->first())->toContain('at most 2 decimals');
})->with([
    'scientific amount off' => ['amount_off', 'discount_value', '1e3'],
    'three decimals amount off' => ['amount_off', 'discount_value', '0.004'],
    'percent with an exponent' => ['percent', 'discount_value', '3e1'],
    'nine digits' => ['amount_off', 'discount_value', '123456789'],
    'scientific deal price' => ['fixed_price', 'deal_price', '1e3'],
    'sub-cent deal price' => ['fixed_price', 'deal_price', '0.004'],
    'three decimals deal price' => ['fixed_price', 'deal_price', '500.123'],
]);

it('still takes ordinary decimals', function () {
    expect((string) makeDeal(['discount_type' => 'amount_off', 'discount_value' => '150.50'], [$this->w->p1->id => 5])->discount_value)->toBe('150.50');
    expect((string) makeDeal(['name_en' => 'P', 'discount_value' => 12.5, 'is_active' => false], [$this->w->p2->id => 5])->discount_value)->toBe('12.50');
});

// ─── 6. Catalog answers are private ─────────────────────────────────────────────────────────────────────────────────

it('marks the catalog list and the product page private and varying by who asks', function () {
    makeDeal(['limit_per_customer' => 3]);

    foreach (['/api/v1/products', '/api/v1/products/search?q=Oslo', '/api/v1/products/'.$this->w->p1->id] as $url) {
        $res = $this->getJson($url)->assertOk();
        expect($res->headers->get('Cache-Control'))->toContain('private')->and($res->headers->get('Vary'))->toContain('Authorization')->toContain('Accept-Language');
    }
});

// ─── 7. A substitute of another product does not keep the deal ──────────────────────────────────────────────────────

it('hands the deal snapshot to a replacement only when it is the same product', function () {
    makeDeal(['limit_per_order' => 2, 'limit_per_customer' => 2], [$this->w->p1->id => 10]);
    dealAddToCart($this->w->v1->id, 2);
    $item = dealOrderOf(placeDealOrder()->assertCreated())->items->first();
    $other = $item->replicate(['id']);
    $other->forceFill(['product_id' => $this->w->p2->id, 'deal_id' => null, 'deal_name_en' => null, 'deal_name_ar' => null, 'deal_units' => 0, 'deal_unit_savings' => null])->save();
    $same = $item->replicate(['id']);
    $same->forceFill(['deal_id' => null, 'deal_name_en' => null, 'deal_name_ar' => null, 'deal_units' => 0, 'deal_unit_savings' => null])->save();

    app(DealService::class)->adjusted($item->fresh(), 2, 0, $other->fresh(), 2);
    expect($other->fresh()->deal_id)->toBeNull()->and($other->fresh()->deal_units)->toBe(0);
    app(DealService::class)->release($item->order_id);
    expect(DealProduct::first()->quantity_sold)->toBe(0); // nothing carries the deal: the units went back to the pool

    $item2 = $item->fresh();
    $item2->forceFill(['deal_units' => 2, 'deal_id' => $item->deal_id])->save();
    app(DealService::class)->adjusted($item2, 2, 0, $same->fresh(), 2);
    expect($same->fresh()->deal_id)->toBe($item->deal_id)->and($same->fresh()->deal_units)->toBe(2);
});

// ─── 8. The home feed does not go stale ─────────────────────────────────────────────────────────────────────────────

it('drops the cached home feed when the last unit goes and when a unit comes back', function () {
    makeDeal(['limit_per_order' => 5], [$this->w->p1->id => 2]);
    dealAddToCart($this->w->v1->id, 2);
    $before = HomepageService::revision();

    $order = dealOrderOf(placeDealOrder()->assertCreated());
    $sold = HomepageService::revision();
    app(OrderService::class)->cancelOrder($order, null, 'customer_request', system: true);

    expect($sold)->not->toBe($before)->and(HomepageService::revision())->not->toBe($sold);
});

it('drops the cached home feed on the minute a deal starts or ends, and only then', function () {
    $this->freezeTime();
    makeDeal(['name_en' => 'Far', 'starts_at' => now()->addDays(2), 'ends_at' => now()->addDays(3)], [$this->w->p2->id => 5]);
    $quiet = HomepageService::revision();
    Artisan::call('deals:refresh-feed');
    expect(HomepageService::revision())->toBe($quiet);

    $running = makeDeal(['name_en' => 'Starting', 'starts_at' => now()->addSeconds(30), 'ends_at' => now()->addHour()], [$this->w->p1->id => 5]);
    $afterSave = HomepageService::revision();
    $this->travel(31)->seconds();
    Artisan::call('deals:refresh-feed');
    expect(HomepageService::revision())->not->toBe($afterSave);

    $again = HomepageService::revision();
    $this->travel(5)->minutes(); // nothing started or ended in the last minute any more
    Artisan::call('deals:refresh-feed');
    expect(HomepageService::revision())->toBe($again)->and($running->fresh()->phase())->toBe('live');
    // The end of a deal counts too.
    $this->travelTo($running->ends_at->addSeconds(10));
    Artisan::call('deals:refresh-feed');
    expect(HomepageService::revision())->not->toBe($again);
});

it('schedules the feed refresh every minute', function () {
    Artisan::call('schedule:list');

    expect(collect(explode("\n", Artisan::output()))->first(fn ($l) => str_contains($l, 'deals:refresh-feed')))->toMatch('/\*\s+\*\s+\*\s+\*\s+\*\s+php artisan deals:refresh-feed/');
});

// ─── 9. The cart and the quote split the units the same way ─────────────────────────────────────────────────────────

it('gives the cart the same deal savings as the quote when two variants share a limit', function () {
    $p = Product::factory()->withVariants(2)->create(['price' => '1000.00']);
    [$cheap, $dear] = [$p->variants()->orderBy('sort_order')->get()->last(), $p->variants()->orderBy('sort_order')->get()->first()];
    $cheap->update(['price' => '600.00']);
    foreach ([$cheap, $dear] as $v) {
        app(InventoryLedger::class)->apply($this->w->a->id, $v->id, MovementType::OpeningBalance, 10);
    }
    makeDeal(['limit_per_order' => 2, 'discount_value' => '30'], [$p->id => 20]);
    dealAddToCart($cheap->id, 2); // first in the cart, but the smaller saving
    dealAddToCart($dear->id, 2);

    $cart = $this->getJson('/api/v1/cart')->assertOk()->json('data');
    $quote = dealQuote();

    expect($quote['totals']['deal_savings']['amount'])->toBe('600.00')->and($cart['deal_savings']['amount'])->toBe($quote['totals']['deal_savings']['amount']);
    $byVariant = collect($cart['items'])->keyBy('variant_id');
    expect($byVariant[$dear->id]['deal']['units_at_deal_price'])->toBe(2)->and($byVariant[$cheap->id]['deal']['units_at_deal_price'])->toBe(0);
});

// ─── 10. Products nobody can buy do not keep a deal alive ───────────────────────────────────────────────────────────

it('calls a deal sold out when what is left is a product that is switched off in the catalog', function () {
    $off = dealProduct('80.00');
    $deal = makeDeal([], [$this->w->p1->id => 2, $off->id => 5]);
    DealProduct::where('product_id', $this->w->p1->id)->update(['quantity_sold' => 2]);
    $off->update(['status' => 'inactive']);

    $listed = $this->getJson('/api/v1/deals')->assertOk()->json('data.live');
    app(DealService::class)->syncSoldOut($deal->fresh());

    expect($listed)->toHaveCount(1)->and($listed[0]['phase'])->toBe('sold_out')->and($listed[0]['products_total'])->toBe(1)
        ->and($deal->fresh()->sold_out_at)->not->toBeNull()->and($deal->fresh()->phase())->toBe('sold_out');
});

it('does not list a deal whose products are all unavailable', function () {
    $deal = makeDeal();
    $this->w->p1->update(['status' => 'inactive']);

    expect($this->getJson('/api/v1/deals')->json('data.live'))->toBe([])->and($this->getJson('/api/v1/deals/'.$deal->id)->assertOk()->json('data.products.data'))->toBe([]);
});

it('judges a deal on the products sold at the shopper\'s branch', function () {
    $atB = dealProduct('80.00');
    makeDeal([], [$this->w->p1->id => 2, $atB->id => 5]);
    DealProduct::where('product_id', $this->w->p1->id)->update(['quantity_sold' => 2]);
    ProductBranchSetting::create(['product_id' => $atB->id, 'branch_id' => $this->w->a->id, 'is_enabled' => false, 'is_visible' => false]);

    $everywhere = $this->getJson('/api/v1/deals')->json('data.live.0');
    $atBranchA = $this->getJson('/api/v1/deals?branch_id='.$this->w->a->id)->json('data.live.0');
    $one = $this->getJson('/api/v1/deals/'.DealProduct::first()->deal_id.'?branch_id='.$this->w->a->id)->json('data');

    expect($everywhere['phase'])->toBe('live')->and($atBranchA['phase'])->toBe('sold_out')->and($atBranchA['products_total'])->toBe(1)->and($one['phase'])->toBe('sold_out');
});

// ─── 11. Two admins at once ─────────────────────────────────────────────────────────────────────────────────────────

it('takes one lock around the overlap check and the write when saving and when switching on', function () {
    $taken = function (callable $do): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $do();
        $n = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'pg_advisory_xact_lock') && in_array('deals:overlap', $q['bindings'], true))->count();
        DB::disableQueryLog();

        return $n;
    };
    $deal = null;
    expect($taken(function () use (&$deal) {
        $deal = makeDeal();
    }))->toBe(1);
    expect($taken(fn () => makeDeal(['name_en' => 'Edit'], [$this->w->p1->id => 10], $deal)))->toBe(1)
        ->and($taken(fn () => app(DealService::class)->setActive(dealAdmin(), $deal->fresh(), false)))->toBe(0)
        ->and($taken(fn () => app(DealService::class)->setActive(dealAdmin(), $deal->fresh(), true)))->toBe(1);
});

// ─── 12. Upper-case ids in a crafted request ────────────────────────────────────────────────────────────────────────

it('keeps the product match when a crafted request writes the id in capitals', function () {
    $deal = makeDeal(['limit_per_order' => 5], [$this->w->p1->id => 5]);
    DealProduct::first()->forceFill(['quantity_sold' => 3])->save();
    $upper = strtoupper($this->w->p1->id);

    dealFails(fn () => app(DealService::class)->save(dealAdmin(), $deal->fresh(), saveData($deal, [['product_id' => $upper, 'quantity_total' => 1]])), 'products.0.quantity_total');
    // And an upper-case id for the product that is already in the deal does not become a second row.
    app(DealService::class)->save(dealAdmin(), $deal->fresh(), saveData($deal, [['product_id' => $upper, 'quantity_total' => 4]]));
    expect(DealProduct::count())->toBe(1)->and(DealProduct::first()->quantity_total)->toBe(4)->and(DealProduct::first()->quantity_sold)->toBe(3)->and(DealProduct::first()->product_id)->toBe($this->w->p1->id);
});

function saveData(Deal $deal, array $products): array
{
    return ['name_en' => $deal->name_en, 'name_ar' => $deal->name_ar, 'is_active' => true, 'ends_at' => $deal->ends_at, 'discount_type' => 'percent', 'discount_value' => '30', 'limit_per_order' => 5, 'products' => $products];
}
