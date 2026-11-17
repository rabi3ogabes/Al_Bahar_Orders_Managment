<?php

use App\Domain\Catalog\Models\Product;
use App\Domain\Deals\Models\Deal;
use App\Domain\Deals\Models\DealProduct;
use App\Domain\Deals\Services\DealService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/DealFixtures.php';

beforeEach(function () {
    $this->w = commerceWorld();
});

function dealGuest(): void
{
    app('auth')->forgetGuards();
}

it('lists live and upcoming deals with the server clock, each with its products and look', function () {
    $live = makeDeal(['name_en' => 'Weekend Furniture Drop', 'tagline_en' => 'Big pieces, small prices', 'limit_per_customer' => 3, 'ends_at' => now()->addHours(26)]);
    $later = makeDeal(['name_en' => 'Lamp week', 'name_ar' => 'أسبوع المصابيح', 'starts_at' => now()->addDays(2), 'ends_at' => now()->addDays(4)], [$this->w->p2->id => 20]);

    $json = $this->getJson('/api/v1/deals')->assertOk()->json('data');

    expect($json['server_time'])->toBe(now('Asia/Qatar')->toIso8601String())
        ->and($json['live'])->toHaveCount(1)->and($json['upcoming'])->toHaveCount(1);
    $d = $json['live'][0];
    expect($d)->toMatchArray(['id' => $live->id, 'name' => 'Weekend Furniture Drop', 'tagline' => 'Big pieces, small prices', 'phase' => 'live', 'products_total' => 1, 'show_on_home' => true,
        'discount' => ['type' => 'percent', 'value' => '30'], 'limits' => ['per_order' => 2, 'per_customer' => 3], 'seconds_to_start' => null])
        ->and($d['seconds_left'])->toBe(26 * 3600)->and($d['ends_at'])->toBe($live->ends_at->setTimezone('Asia/Qatar')->toIso8601String())
        ->and($d['products'])->toHaveCount(1)->and($d['products'][0]['id'])->toBe($this->w->p1->id)
        ->and($d['products'][0]['deal'])->toMatchArray(['deal_id' => $live->id, 'phase' => 'live', 'percent' => 30, 'remaining' => 10, 'remaining_percent' => 100, 'limit_per_order' => 2,
            'limit_per_customer' => 3, 'customer_remaining' => 3, 'sold_out' => false])
        ->and($d['products'][0]['deal']['deal_price']['amount'])->toBe('700.00')->and($d['products'][0]['deal']['regular_price']['amount'])->toBe('1000.00')
        ->and($d['products'][0]['price']['final']['amount'])->toBe('1000.00') // price.final keeps its normal meaning
        ->and($d['design'])->toMatchArray(['style' => 'classic', 'palette' => 'brand', 'shape' => 'rounded', 'stock_bar' => true, 'percent_badge' => true, 'icon' => 'flame',
            'headline_en' => 'Ends in', 'cta_en' => 'Shop the deal', 'image_url' => null])
        ->and($d['design']['colors'])->toHaveKeys(['background', 'text', 'accent'])->and($d['design']['colors']['background'])->toMatch('/^#[0-9A-F]{6}$/')
        ->and($d['design']['timer'])->toBe(['units' => ['d', 'h', 'm', 's'], 'look' => 'boxes', 'urgency_minutes' => 60]);
    $u = $json['upcoming'][0];
    expect($u['id'])->toBe($later->id)->and($u['phase'])->toBe('scheduled')->and($u['seconds_to_start'])->toBe(2 * 86400)->and($u['name'])->toBe('Lamp week');
});

it('answers in Arabic with the Arabic names and words', function () {
    makeDeal(['name_ar' => 'عرض الأثاث', 'tagline_ar' => 'قطع كبيرة وأسعار صغيرة']);

    $d = $this->getJson('/api/v1/deals', ['Accept-Language' => 'ar'])->assertOk()->json('data.live.0');

    expect($d['name'])->toBe('عرض الأثاث')->and($d['tagline'])->toBe('قطع كبيرة وأسعار صغيرة')->and($d['products'][0]['deal']['name'])->toBe('عرض الأثاث')
        ->and($d['products'][0]['deal']['deal_price']['formatted'])->toContain('ر.ق');
});

it('orders live deals by the one ending first, then by their sort order', function () {
    $p3 = dealProduct('80.00');
    $p4 = dealProduct('90.00');
    $b = makeDeal(['name_en' => 'Ends in 2 days', 'ends_at' => now()->addDays(2)], [$this->w->p1->id => 5]);
    $a = makeDeal(['name_en' => 'Ends in 3 hours', 'ends_at' => now()->addHours(3)], [$this->w->p2->id => 5]);
    $c = makeDeal(['name_en' => 'Same end, later in the list', 'ends_at' => now()->addDays(2), 'sort_order' => 5], [$p3->id => 5]);
    $d = makeDeal(['name_en' => 'Same end, first in the list', 'ends_at' => now()->addDays(2), 'sort_order' => 1], [$p4->id => 5]);
    DB::table('deals')->whereIn('id', [$b->id, $c->id, $d->id])->update(['ends_at' => $b->fresh()->ends_at]);

    $names = collect($this->getJson('/api/v1/deals')->json('data.live'))->pluck('name')->all();

    expect($names)->toBe(['Ends in 3 hours', 'Ends in 2 days', 'Same end, first in the list', 'Same end, later in the list']);
});

it('never lists a paused, ended or empty deal, and "home=1" keeps only the ones shown on home', function () {
    $p3 = dealProduct('80.00');
    $p4 = dealProduct('90.00');
    $paused = makeDeal(['name_en' => 'Paused', 'is_active' => false], [$this->w->p1->id => 5]);
    $ended = makeDeal(['name_en' => 'Ended'], [$this->w->p2->id => 5]);
    app(DealService::class)->end(dealAdmin(), $ended);
    makeDeal(['name_en' => 'Hidden from home', 'show_on_home' => false], [$p3->id => 5]);
    makeDeal(['name_en' => 'On home'], [$p4->id => 5]);

    expect(collect($this->getJson('/api/v1/deals')->json('data.live'))->pluck('name')->sort()->values()->all())->toBe(['Hidden from home', 'On home'])
        ->and(collect($this->getJson('/api/v1/deals?home=1')->json('data.live'))->pluck('name')->all())->toBe(['On home']);
    // A deal whose only product was switched off in the catalog has nothing to show.
    $p4->update(['status' => 'inactive']);
    expect($this->getJson('/api/v1/deals?home=1')->json('data.live'))->toBe([]);
});

it('keeps a sold-out deal on the list for 15 minutes, then drops it', function () {
    $deal = makeDeal([], [$this->w->p1->id => 2]);
    DealProduct::first()->forceFill(['quantity_sold' => 2])->save();
    app(DealService::class)->syncSoldOut($deal);

    $d = $this->getJson('/api/v1/deals')->json('data.live.0');
    expect($d['phase'])->toBe('sold_out')->and($d['products'][0]['deal'])->toMatchArray(['sold_out' => true, 'remaining' => 0, 'remaining_percent' => 0, 'phase' => 'sold_out']);

    $this->travel(14)->minutes();
    expect($this->getJson('/api/v1/deals')->json('data.live'))->toHaveCount(1);
    $this->travel(2)->minutes();
    expect($this->getJson('/api/v1/deals')->json('data.live'))->toBe([]);
    $this->getJson('/api/v1/deals/'.$deal->id)->assertOk()->assertJsonPath('data.phase', 'sold_out'); // a sold-out deal still answers
});

it('moves a deal through its phases exactly at the boundaries', function () {
    $this->travelTo(now()->startOfMinute());
    $deal = makeDeal(['starts_at' => now()->addHour(), 'ends_at' => now()->addHours(2)]);

    expect($deal->fresh()->phase())->toBe('scheduled');
    $this->travelTo($deal->starts_at->subSecond());
    expect($deal->fresh()->phase())->toBe('scheduled');
    $this->travelTo($deal->starts_at);
    expect($deal->fresh()->phase())->toBe('live');
    $this->travelTo($deal->ends_at->subSecond());
    expect($deal->fresh()->phase())->toBe('live');
    $this->travelTo($deal->ends_at);
    expect($deal->fresh()->phase())->toBe('ended');
    $this->getJson('/api/v1/deals/'.$deal->id)->assertNotFound()->assertJsonPath('code', 'deal_not_found');
});

it('shows one deal with a page of 24 cards and its meta, and 404s an ended or paused one', function () {
    $products = collect(range(1, 26))->map(fn () => dealProduct('50.00', 5));
    $deal = makeDeal([], $products->mapWithKeys(fn ($p) => [$p->id => 3])->all());

    $first = $this->getJson('/api/v1/deals/'.$deal->id)->assertOk()->json('data');
    expect($first['products_total'])->toBe(26)->and($first['products']['data'])->toHaveCount(24)->and($first['products']['meta'])->toBe(['current_page' => 1, 'per_page' => 24, 'total' => 26, 'last_page' => 2])
        ->and($first['server_time'])->toBeString()->and($first['products']['data'][0]['deal']['deal_id'])->toBe($deal->id);
    $second = $this->getJson('/api/v1/deals/'.$deal->id.'?page=2')->json('data');
    expect($second['products']['data'])->toHaveCount(2)->and($second['products']['meta']['current_page'])->toBe(2);

    app(DealService::class)->setActive(dealAdmin(), $deal, false);
    $this->getJson('/api/v1/deals/'.$deal->id)->assertNotFound()->assertJsonPath('code', 'deal_not_found');
    $this->getJson('/api/v1/deals/not-an-id')->assertNotFound();
    $this->getJson('/api/v1/deals/'.(string) Str::uuid())->assertNotFound()->assertJsonPath('code', 'deal_not_found');
});

it('puts the deal block on catalog cards and on the product page, with the cheapest variant as "from"', function () {
    $deal = makeDeal(['limit_per_customer' => 3]);

    $card = $this->getJson('/api/v1/products')->assertOk()->json('data');
    $sofa = collect($card)->firstWhere('id', $this->w->p1->id);
    $lamp = collect($card)->firstWhere('id', $this->w->p2->id);
    expect($sofa['deal']['deal_id'])->toBe($deal->id)->and($sofa['deal']['customer_remaining'])->toBe(3)->and($lamp['deal'])->toBeNull();

    $detail = $this->getJson('/api/v1/products/'.$this->w->p1->id)->assertOk()->json('data');
    expect($detail['deal']['deal_price']['amount'])->toBe('700.00')->and($detail['variants'][0]['deal_price']['amount'])->toBe('700.00')
        ->and($this->getJson('/api/v1/products/'.$this->w->p2->id)->json('data.variants.0.deal_price'))->toBeNull();
});

it('fills customer_remaining only for a signed-in customer with a per-account limit', function () {
    makeDeal(['limit_per_customer' => 3]);
    dealAddToCart($this->w->v1->id, 2);
    placeDealOrder()->assertCreated();

    expect($this->getJson('/api/v1/products/'.$this->w->p1->id)->json('data.deal.customer_remaining'))->toBe(1);
    dealGuest();
    expect($this->getJson('/api/v1/products/'.$this->w->p1->id)->json('data.deal.customer_remaining'))->toBeNull();
    // 0 means this customer used it all.
    actingAsCustomer($this->w->customer);
    dealAddToCart($this->w->v1->id, 1);
    placeDealOrder()->assertCreated();
    expect($this->getJson('/api/v1/products/'.$this->w->p1->id)->json('data.deal.customer_remaining'))->toBe(0);
});

it('has no per-customer figure when the deal has no per-account limit', function () {
    makeDeal(['limit_per_customer' => null]);

    expect($this->getJson('/api/v1/products/'.$this->w->p1->id)->json('data.deal.customer_remaining'))->toBeNull();
});

it('says how many units of each cart line get the deal price', function () {
    $deal = makeDeal();
    dealAddToCart($this->w->v1->id, 3);
    dealAddToCart($this->w->v2->id, 1);

    $cart = $this->getJson('/api/v1/cart')->assertOk()->json('data');
    $sofa = collect($cart['items'])->firstWhere('variant_id', $this->w->v1->id);
    $lamp = collect($cart['items'])->firstWhere('variant_id', $this->w->v2->id);

    expect($sofa['deal'])->toMatchArray(['deal_id' => $deal->id, 'units_at_deal_price' => 2, 'units_at_regular_price' => 1, 'note' => 'limit_per_order'])
        ->and($sofa['product']['deal']['deal_id'])->toBe($deal->id)->and($lamp['deal'])->toBeNull()
        ->and($cart['deal_savings']['amount'])->toBe('600.00')->and($cart['subtotal']['amount'])->toBe('3250.00');
});

it('counts the same number of queries for one deal or many (no N+1 on the list)', function () {
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/v1/deals?limit_products=3')->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };
    makeDeal(['name_en' => 'One'], [dealProduct('60.00')->id => 5, dealProduct('70.00')->id => 5, dealProduct('75.00')->id => 5]);
    $count(); // warm up the theme cache and the like
    $one = $count();

    foreach (range(1, 4) as $i) {
        makeDeal(['name_en' => "Deal $i", 'ends_at' => now()->addHours($i + 1)], [dealProduct('60.00')->id => 5, dealProduct('70.00')->id => 5, dealProduct('75.00')->id => 5]);
    }
    $many = $count();

    expect($many)->toBe($one)->and($one)->toBeLessThan(20);
});

it('keeps the cards of a deal identical to the catalog cards (one price source)', function () {
    makeDeal();
    $fromDeals = $this->getJson('/api/v1/deals')->json('data.live.0.products.0');
    $fromCatalog = collect($this->getJson('/api/v1/products')->json('data'))->firstWhere('id', $this->w->p1->id);

    expect($fromDeals)->toBe($fromCatalog);
});

it('stays correct after an admin change: no stale card or list', function () {
    $deal = makeDeal();
    expect($this->getJson('/api/v1/products/'.$this->w->p1->id)->json('data.deal.deal_price.amount'))->toBe('700.00');

    makeDeal(['discount_value' => '50'], [$this->w->p1->id => 10], $deal);

    expect($this->getJson('/api/v1/products/'.$this->w->p1->id)->json('data.deal.deal_price.amount'))->toBe('500.00')
        ->and($this->getJson('/api/v1/deals')->json('data.live.0.discount.value'))->toBe('50');
    app(DealService::class)->end(dealAdmin(), $deal->fresh());
    expect($this->getJson('/api/v1/products/'.$this->w->p1->id)->json('data.deal'))->toBeNull()->and($this->getJson('/api/v1/deals')->json('data.live'))->toBe([]);
});
