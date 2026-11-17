<?php

use App\Domain\Deals\Models\Deal;
use App\Domain\Deals\Models\DealProduct;
use App\Domain\Deals\Services\DealService;
use App\Domain\Deals\Support\DealDesign;
use App\Domain\Deals\Support\DealPricing;
use App\Support\Contrast;
use App\Support\DomainException;
use App\Support\Money;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/DealFixtures.php';

function rule(string $type, string $value, ?string $rowPrice = null): array
{
    return [new Deal(['discount_type' => $type, 'discount_value' => $value]), new DealProduct(['deal_price' => $rowPrice])];
}

it('prices a percent deal half-up to two decimals and never above the normal price', function (string $percent, string $normal, string $expected) {
    [$deal, $row] = rule('percent', $percent);

    expect(DealPricing::unitPrice($deal, $row, Money::of($normal))->toDecimal())->toBe($expected);
})->with([
    'plain 30 %' => ['30', '1000.00', '700.00'],
    'rounds 66.6633 down' => ['33.33', '99.99', '66.66'],
    'rounds 8.75875 up' => ['12.5', '10.01', '8.76'],
    'half exactly goes up' => ['50', '0.03', '0.02'],
    '95 % off' => ['95', '200.00', '10.00'],
    '1 % off a small price' => ['1', '10.00', '9.90'],
]);

it('takes an amount off, never below 0.01, and gives no deal when nothing is saved', function () {
    [$deal, $row] = rule('amount_off', '150');
    expect(DealPricing::unitPrice($deal, $row, Money::of('1000.00'))->toDecimal())->toBe('850.00');

    [$deal, $row] = rule('amount_off', '5');
    expect(DealPricing::unitPrice($deal, $row, Money::of('3.00'))->toDecimal())->toBe('0.01');
    expect(DealPricing::unitPrice($deal, $row, Money::of('0.01')))->toBeNull(); // already the floor: nothing to give
});

it('uses the product price for a fixed-price deal, only for variants that cost more', function () {
    [$deal, $row] = rule('fixed_price', '0', '640.00');

    expect(DealPricing::unitPrice($deal, $row, Money::of('900.00'))->toDecimal())->toBe('640.00')
        ->and(DealPricing::unitPrice($deal, $row, Money::of('640.00')))->toBeNull()
        ->and(DealPricing::unitPrice($deal, $row, Money::of('600.00')))->toBeNull();
    [$deal, $missing] = rule('fixed_price', '0');
    expect(DealPricing::unitPrice($deal, $missing, Money::of('900.00')))->toBeNull(); // incomplete: it never discounts
});

it('lets a product price override the rule', function () {
    [$deal, $row] = rule('percent', '10', '500.00');

    expect(DealPricing::unitPrice($deal, $row, Money::of('1000.00'))->toDecimal())->toBe('500.00');
});

it('shows the saving as a whole percent, at least 1 once anything is saved', function () {
    expect(DealPricing::percentOff(Money::of('1000.00'), Money::of('700.00')))->toBe(30)
        ->and(DealPricing::percentOff(Money::of('1000.00'), Money::of('999.50')))->toBe(1)
        ->and(DealPricing::percentOff(Money::of('1000.00'), Money::of('1000.00')))->toBe(0)
        ->and(DealPricing::plain('30.00'))->toBe('30')->and(DealPricing::plain('12.50'))->toBe('12.5')->and(DealPricing::plain('0.00'))->toBe('0');
});

// ─── The look ───────────────────────────────────────────────────────────────────────────────────────────────────────

it('fills a missing look with the defaults and drops anything it does not know', function () {
    $clean = DealDesign::validate(['style' => 'luxe', 'evil' => '<script>', 'timer' => ['look' => 'digits', 'rogue' => 1]]);

    expect($clean['style'])->toBe('luxe')->and($clean['timer'])->toBe(['units' => ['d', 'h', 'm', 's'], 'look' => 'digits', 'urgency_minutes' => 60])
        ->and($clean)->not->toHaveKey('evil')->and($clean['icon'])->toBe('flame')->and($clean['headline_en'])->toBe('Ends in')->and($clean['cta_ar'])->toBe('تسوق العرض')
        ->and(DealDesign::validate(null))->toBe(DealDesign::validate([]));
});

it('rejects anything outside the allow-lists', function (array $input, string $key) {
    try {
        DealDesign::validate($input);
        $this->fail('should have been refused');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey($key);
    }
})->with([
    'style' => [['style' => 'neon'], 'design.style'],
    'palette' => [['palette' => 'rainbow'], 'design.palette'],
    'shape' => [['shape' => 'star'], 'design.shape'],
    'icon' => [['icon' => 'skull'], 'design.icon'],
    'timer look' => [['timer' => ['look' => 'flip']], 'design.timer.look'],
    'one unit only' => [['timer' => ['units' => ['s']]], 'design.timer.units'],
    'unknown unit' => [['timer' => ['units' => ['h', 'w']]], 'design.timer.units.1'],
    'duplicate unit' => [['timer' => ['units' => ['h', 'h']]], 'design.timer.units.0'],
    'urgency too long' => [['timer' => ['urgency_minutes' => 999999]], 'design.timer.urgency_minutes'],
    'urgency negative' => [['timer' => ['urgency_minutes' => -1]], 'design.timer.urgency_minutes'],
    'bad colour' => [['palette' => 'custom', 'colors' => ['background' => 'red']], 'design.colors.background'],
    'long headline' => [['headline_en' => str_repeat('x', 41)], 'design.headline_en'],
    'path walks out' => [['image_path' => 'deals/../../secret.php'], 'design.image_path'],
    'url as image' => [['image_path' => 'https://evil.example/x.png'], 'design.image_path'],
    'text on background too faint' => [['palette' => 'custom', 'colors' => ['background' => '#FFFFFF', 'text' => '#F5F5F5', 'accent' => '#000000']], 'design.colors.text'],
    'accent on background too faint' => [['palette' => 'custom', 'colors' => ['background' => '#FFFFFF', 'text' => '#000000', 'accent' => '#F0F0F0']], 'design.colors.accent'],
]);

it('keeps the units in clock order and the words clean', function () {
    $clean = DealDesign::validate(['timer' => ['units' => ['s', 'h', 'm']], 'headline_en' => '  <b>Hurry</b>  ', 'cta_en' => '', 'image_path' => 'deals/a1b2.webp']);

    expect($clean['timer']['units'])->toBe(['h', 'm', 's'])->and($clean['headline_en'])->toBe('Hurry')->and($clean['cta_en'])->toBe('Shop the deal')->and($clean['image_path'])->toBe('deals/a1b2.webp');
});

it('resolves every palette to three readable colours and fills the custom ones as given', function (string $palette) {
    $resolved = DealDesign::resolve(['palette' => $palette, 'colors' => ['background' => '#112233', 'text' => '#FFFFFF', 'accent' => '#FFCC00']], '#8A1538');
    $c = $resolved['colors'];

    expect(array_keys($c))->toBe(['background', 'text', 'accent'])->and(Contrast::ratio($c['text'], $c['background']))->toBeGreaterThanOrEqual(4.5)
        ->and(Contrast::ratio($c['accent'], $c['background']))->toBeGreaterThanOrEqual(3.0)->and($resolved)->not->toHaveKey('image_path')->and($resolved['image_url'])->toBeNull();
    if ($palette === 'custom') {
        expect($c)->toBe(['background' => '#112233', 'text' => '#FFFFFF', 'accent' => '#FFCC00']);
    }
})->with(DealDesign::PALETTES);

it('follows the brand colour for the brand palette, whatever it is', function (string $brand) {
    $c = DealDesign::resolve(['palette' => 'brand'], $brand)['colors'];

    expect($c['background'])->toBe($brand)->and(Contrast::ratio($c['text'], $c['background']))->toBeGreaterThanOrEqual(4.5)->and(Contrast::ratio($c['accent'], $c['background']))->toBeGreaterThanOrEqual(3.0);
})->with(['#8A1538', '#FFD700', '#101010', '#E0E0E0', '#7F7F7F']);

it('falls back to the default look when a stored design was damaged', function () {
    expect(DealDesign::resolve(['style' => 'nope', 'palette' => 'custom', 'colors' => ['background' => 'x']])['style'])->toBe('classic');
});

// ─── Saving: windows, values, overlap ───────────────────────────────────────────────────────────────────────────────

beforeEach(function () {
    $this->w = commerceWorld();
});

function saveFails(array $over, string $field): void
{
    try {
        makeDeal($over);
        test()->fail('should have been refused');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey($field);
    }
}

it('checks the price rule values', function () {
    saveFails(['discount_value' => '0'], 'discount_value');
    saveFails(['discount_value' => '96'], 'discount_value');
    saveFails(['discount_type' => 'amount_off', 'discount_value' => '0'], 'discount_value');
    saveFails(['discount_type' => 'nonsense'], 'discount_type');
    saveFails(['limit_per_order' => 0], 'limit_per_order');
    saveFails(['limit_per_customer' => -2], 'limit_per_customer');
    saveFails(['name_en' => ''], 'name_en');
    saveFails(['products' => []], 'products');
    expect(makeDeal(['discount_value' => '95'])->discount_value)->toBe('95.00');
});

it('checks each product row', function () {
    $row = fn (array $x) => ['products' => [['product_id' => $this->w->p1->id] + $x]];
    saveFails($row(['quantity_total' => 0]), 'products.0.quantity_total');
    saveFails($row(['deal_price' => '0']), 'products.0.deal_price');
    saveFails($row(['limit_per_order' => 0]), 'products.0.limit_per_order');
    saveFails(['products' => [['product_id' => $this->w->p1->id], ['product_id' => $this->w->p1->id]]], 'products.0.product_id');
    saveFails(['products' => [['product_id' => (string) Str::uuid()]]], 'products.0.product_id');
    saveFails(['discount_type' => 'fixed_price', 'discount_value' => '0'], 'products.0.deal_price');
});

it('refuses a window that ends before it starts, and a switched-on deal that ends in the past', function () {
    expect(fn () => makeDeal(['starts_at' => now()->addDay(), 'ends_at' => now()->addHour()]))->toThrow(DomainException::class);
    try {
        makeDeal(['ends_at' => now()->subMinute()]);
    } catch (DomainException $e) {
        expect($e->errorCode)->toBe('deal_invalid_window')->and($e->status)->toBe(422);
    }
    expect(makeDeal(['is_active' => false, 'ends_at' => now()->subMinute()])->is_active)->toBeFalse(); // a draft may keep an old date
});

it('keeps two deals of one product apart: overlapping refused with the other deal named, back-to-back allowed', function () {
    $a = makeDeal(['name_en' => 'First', 'starts_at' => now()->addHour(), 'ends_at' => now()->addHours(3)]);

    try {
        makeDeal(['name_en' => 'Second', 'starts_at' => $a->ends_at->subSecond(), 'ends_at' => now()->addHours(6)]);
        $this->fail('overlap not noticed');
    } catch (DomainException $e) {
        expect($e->errorCode)->toBe('deal_product_overlap')->and($e->getMessage())->toContain('First')->toContain('Sofa')->and($e->context)->toHaveKeys(['product', 'deal', 'from', 'to']);
    }
    // Starting the moment the other ends is fine; so is a deal of another product.
    $b = makeDeal(['name_en' => 'Second', 'starts_at' => $a->ends_at, 'ends_at' => now()->addHours(6)]);
    expect($b->exists)->toBeTrue();
    makeDeal(['name_en' => 'Other product', 'starts_at' => now()->addHour(), 'ends_at' => now()->addHours(3)], [$this->w->p2->id => 4]);
    // A deal already running (no start) blocks one that starts while it runs.
    $run = makeDeal(['name_en' => 'Running', 'ends_at' => now()->addDays(2)], [dealProduct('50.00')->id => 3]);
    expect(fn () => makeDeal(['starts_at' => now()->addDay(), 'ends_at' => now()->addDays(3)], [DealProduct::where('deal_id', $run->id)->value('product_id') => 2]))->toThrow(DomainException::class);
});

it('does not let a paused, ended or deleted deal block a product, and re-checks when it is switched on again', function () {
    $a = makeDeal(['name_en' => 'First']);
    app(DealService::class)->setActive(dealAdmin(), $a, false);
    $b = makeDeal(['name_en' => 'Second']); // allowed: the first one is paused

    expect(fn () => app(DealService::class)->setActive(dealAdmin(), $a->fresh(), true))->toThrow(DomainException::class); // would clash with the second
    app(DealService::class)->end(dealAdmin(), $b);
    expect(app(DealService::class)->setActive(dealAdmin(), $a->fresh(), true)->is_active)->toBeTrue();
});

it('lets a deal be edited without clashing with itself', function () {
    $deal = makeDeal();

    $again = makeDeal(['name_en' => 'Renamed', 'discount_value' => '40'], [$this->w->p1->id => 12], $deal);

    expect($again->id)->toBe($deal->id)->and($again->name_en)->toBe('Renamed')->and((string) $again->discount_value)->toBe('40.00')->and($again->products()->first()->quantity_total)->toBe(12)
        ->and(Deal::count())->toBe(1);
});

it('never lets the quantity drop below what is sold, nor a product with orders leave the deal', function () {
    $deal = makeDeal();
    addToCart($this->w->v1->id, 2);
    placeDealOrder()->assertCreated();

    try {
        makeDeal([], [$this->w->p1->id => 1], $deal);
        $this->fail('quantity below sold accepted');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('products.0.quantity_total')->and($e->errors()['products.0.quantity_total'][0])->toContain('2 units');
    }
    try {
        makeDeal([], [$this->w->p2->id => 5], $deal);
        $this->fail('product with orders removed');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('products');
    }
    expect(makeDeal([], [$this->w->p1->id => 2], $deal)->products()->first()->quantity_total)->toBe(2); // exactly what is sold is fine
    expect($deal->fresh()->phase())->toBe('sold_out');
});

it('keeps an ended deal read-only', function () {
    $deal = makeDeal();
    app(DealService::class)->end(dealAdmin(), $deal);

    expect(fn () => makeDeal(['name_en' => 'Changed'], null, $deal->fresh()))->toThrow(DomainException::class);
    expect($deal->fresh()->name_en)->toBe('Weekend Furniture Drop');
});

it('clears sold_out_at when units are added back', function () {
    $deal = makeDeal([], [$this->w->p1->id => 1]);
    DealProduct::first()->forceFill(['quantity_sold' => 1])->save();
    app(DealService::class)->syncSoldOut($deal);
    expect($deal->fresh()->sold_out_at)->not->toBeNull();

    makeDeal([], [$this->w->p1->id => 5], $deal);
    expect($deal->fresh()->sold_out_at)->toBeNull()->and($deal->fresh()->phase())->toBe('live');
});

it('keeps Arabic words for every key of the new language files', function (string $file) {
    $en = require base_path("lang/en/{$file}.php");
    $ar = require base_path("lang/ar/{$file}.php");
    $flat = fn (array $a, string $p = '') => collect($a)->flatMap(fn ($v, $k) => is_array($v) ? [] : [$p.$k => $v])->all();

    expect(array_keys($flat($ar)))->toEqualCanonicalizing(array_keys($flat($en)));
    foreach ($flat($ar) as $key => $text) {
        expect($text)->not->toBe('')->and(preg_match('/[\x{0600}-\x{06FF}]/u', (string) $text) === 1 || preg_match('/^[\s\d:%.+\-\/()[\]a-z]*$/i', (string) $text) === 1)->toBeTrue("{$file}.{$key} has no Arabic: {$text}");
    }
})->with(['deals', 'deals_admin']);

it('has Arabic for the error codes, the menu and the footer words the deals added', function () {
    foreach (['deal_changed', 'deal_not_found', 'deal_product_overlap', 'deal_invalid_window', 'deal_has_orders'] as $key) {
        expect(__('errors.'.$key, [], 'ar'))->not->toBe('errors.'.$key)->and(__('errors.'.$key, [], 'en'))->not->toBe('errors.'.$key);
    }
    expect(__('nav.deals', [], 'ar'))->toBe('العروض');
    foreach (['item_deals', 'icon_flame', 'icon_bolt', 'icon_timer', 'icon_crown', 'short.deals', 'err_center', 'center', 'center_hint', 'center_none', 'center_none_hint', 'center_auctions', 'center_auctions_hint',
        'center_deals', 'center_deals_hint', 'center_note', 'center_reset', 'center_full'] as $key) {
        expect(__('footer_admin.'.$key, ['item' => 'x', 'max' => 5], 'ar'))->not->toBe('footer_admin.'.$key)->and(__('footer_admin.'.$key, ['item' => 'x', 'max' => 5], 'en'))->not->toBe('footer_admin.'.$key);
    }
});
