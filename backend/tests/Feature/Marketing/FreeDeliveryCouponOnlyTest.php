<?php

require_once __DIR__.'/../Orders/AdjustmentFixtures.php';

use App\Domain\Branches\Models\Branch;
use App\Domain\Branches\Models\BranchDeliveryZone;
use App\Domain\Branches\Models\DeliveryZone;
use App\Domain\Identity\Models\User;
use App\Domain\Marketing\Models\Coupon;
use App\Domain\Marketing\Models\OrderCoupon;
use App\Domain\Marketing\Models\Promotion;
use App\Domain\Orders\Models\Order;
use App\Livewire\Admin\Branches\BranchEditor;
use App\Livewire\Admin\Marketing\PromotionIndex;
use Database\Seeders\DemoSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * Delivery fee rule: every delivery order pays its zone fee. There is no "free above X QAR"; free delivery comes only from a coupon code that
 * belongs to a free-delivery promotion. (commerceWorld: zone fee 15.00 at branch A, minimum order 50, cash on delivery fee 10.)
 */
function freeDeliveryPromotion(array $attrs = []): Promotion
{
    return Promotion::create(array_merge(['name_en' => 'Free delivery', 'name_ar' => 'توصيل مجاني', 'type' => 'free_delivery', 'value' => '0', 'scope' => 'order',
        'requires_coupon' => true, 'is_active' => true], $attrs));
}

function quoteOf(object $w, array $extra = []): array
{
    return test()->postJson('/api/v1/checkout/quote', ['address_id' => $w->address->id, 'payment_method' => 'cod'] + $extra)->assertOk()->json('data');
}

/* ───────────────────────── the quote ───────────────────────── */

it('charges the zone fee on every delivery order, below, at and far above any old threshold, and never hints at free delivery', function (string $variant, int $qty, string $subtotal, string $grand) {
    $w = commerceWorld([20, 20], [20, 20]);
    // A number left over from before the rule changed (the migration clears these, but the code must not look at them either).
    BranchDeliveryZone::query()->update(['free_delivery_threshold' => '1000.00']);
    $this->postJson('/api/v1/cart/items', ['variant_id' => $w->{$variant}->id, 'quantity' => $qty])->assertSuccessful();

    $q = quoteOf($w);

    expect($q['totals']['subtotal']['amount'])->toBe($subtotal)
        ->and($q['totals']['delivery_fee']['amount'])->toBe('15.00')
        ->and($q['fulfillments'][0]['delivery_fee']['amount'])->toBe('15.00')
        ->and($q['totals']['discount']['amount'])->toBe('0.00')
        ->and($q['totals']['grand_total']['amount'])->toBe($grand)
        ->and($q['discounts'])->toBe([])
        ->and($q['hints'])->toBe([])
        ->and($q['can_place_order'])->toBeTrue()
        ->and(json_encode($q))->not->toContain('free_delivery_progress');
})->with([
    'below the old threshold' => ['v2', 1, '250.00', '275.00'],
    'exactly at it' => ['v1', 1, '1000.00', '1025.00'],
    'far above it' => ['v1', 6, '6000.00', '6025.00'],
]);

it('keeps the minimum and maximum order amounts of a zone', function () {
    $w = commerceWorld([20, 20], [20, 20]);
    BranchDeliveryZone::query()->update(['min_order_amount' => '300.00', 'max_order_amount' => '2000.00']);
    $this->postJson('/api/v1/cart/items', ['variant_id' => $w->v2->id, 'quantity' => 1])->assertSuccessful();
    expect(collect(quoteOf($w)['errors'])->pluck('code'))->toContain('below_min_order');

    $this->postJson('/api/v1/cart/items', ['variant_id' => $w->v1->id, 'quantity' => 3])->assertSuccessful();
    expect(collect(quoteOf($w)['errors'])->pluck('code'))->toContain('above_max_order');
});

it('waives the delivery fee only with a free-delivery coupon, and shows it in the quote and on the order', function () {
    $w = commerceWorld([20, 20], [20, 20]);
    Coupon::create(['promotion_id' => freeDeliveryPromotion()->id, 'code' => 'FREESHIP']);
    $this->postJson('/api/v1/cart/items', ['variant_id' => $w->v2->id, 'quantity' => 1])->assertSuccessful(); // 250
    $opts = ['address_id' => $w->address->id, 'payment_method' => 'cod'];

    $plain = quoteOf($w);
    expect($plain['totals']['delivery_fee']['amount'])->toBe('15.00')->and($plain['totals']['discount']['amount'])->toBe('0.00')
        ->and($plain['totals']['grand_total']['amount'])->toBe('275.00')->and($plain['discounts'])->toBe([]);

    $q = quoteOf($w, ['coupon_code' => 'freeship']);
    expect($q['totals']['delivery_fee']['amount'])->toBe('15.00') // the fee line stays, so the app can strike it through
        ->and($q['totals']['discount']['amount'])->toBe('15.00')
        ->and($q['totals']['grand_total']['amount'])->toBe('260.00') // 250 + 15 - 15 + 10 cash on delivery
        ->and($q['discounts'])->toHaveCount(1)
        ->and($q['discounts'][0]['free_delivery'])->toBeTrue()->and($q['discounts'][0]['code'])->toBe('FREESHIP')->and($q['discounts'][0]['amount']['amount'])->toBe('15.00')
        ->and($q['hints'])->toBe([]);

    $order = Order::findOrFail(quoteAndPlace($opts + ['coupon_code' => 'FREESHIP'])->assertCreated()->json('data.order.id'));
    expect($order->delivery_fee_total)->toBe('15.00')->and($order->discount_total)->toBe('15.00')->and($order->grand_total)->toBe('260.00')
        ->and($order->fee_breakdown['delivery_discount'])->toBe('15.00')
        ->and(OrderCoupon::where('order_id', $order->id)->where('free_delivery', true)->count())->toBe(1);
});

it('waives the delivery fee of every branch part of a split order with the code', function () {
    $w = commerceWorld([10, 0], [0, 10]); // sofas only at A, lamps only at B: two parcels, 15 + 25
    $this->postJson('/api/v1/cart/items', ['variant_id' => $w->v1->id, 'quantity' => 1])->assertSuccessful();
    $this->postJson('/api/v1/cart/items', ['variant_id' => $w->v2->id, 'quantity' => 1])->assertSuccessful();
    Coupon::create(['promotion_id' => freeDeliveryPromotion()->id, 'code' => 'FREESHIP']);

    $plain = quoteOf($w);
    expect($plain['totals']['delivery_fee']['amount'])->toBe('40.00')->and($plain['totals']['discount']['amount'])->toBe('0.00');
    $q = quoteOf($w, ['coupon_code' => 'FREESHIP']);
    expect($q['totals']['delivery_fee']['amount'])->toBe('40.00')->and($q['totals']['discount']['amount'])->toBe('40.00')->and($q['discounts'][0]['free_delivery'])->toBeTrue();
});

it('says why a free-delivery code does not work instead of waiving the fee silently', function (array $couponChanges, string $warning) {
    $w = commerceWorld([20, 20], [20, 20]);
    Coupon::create(['promotion_id' => freeDeliveryPromotion()->id, 'code' => 'FREESHIP'] + $couponChanges);
    $this->postJson('/api/v1/cart/items', ['variant_id' => $w->v2->id, 'quantity' => 1])->assertSuccessful();

    $q = quoteOf($w, ['coupon_code' => 'FREESHIP']);
    expect(collect($q['warnings'])->pluck('code'))->toContain($warning)->and($q['totals']['delivery_fee']['amount'])->toBe('15.00')->and($q['totals']['discount']['amount'])->toBe('0.00');
})->with([
    'switched off' => [['is_active' => false], 'coupon_invalid'],
    'ended' => fn () => [['ends_at' => now()->subDay()], 'coupon_expired'],
]);

it('ignores an automatic free-delivery promotion: with no code, or even with a code pointing at it', function () {
    $w = commerceWorld([20, 20], [20, 20]);
    $automatic = freeDeliveryPromotion(['requires_coupon' => false]);
    $this->postJson('/api/v1/cart/items', ['variant_id' => $w->v1->id, 'quantity' => 6])->assertSuccessful(); // 6000, far above any threshold

    $q = quoteOf($w);
    expect($q['totals']['delivery_fee']['amount'])->toBe('15.00')->and($q['totals']['discount']['amount'])->toBe('0.00')->and($q['discounts'])->toBe([])
        ->and($q['totals']['grand_total']['amount'])->toBe('6025.00');

    // An old coupon row that points at such a promotion does not turn it back on.
    Coupon::create(['promotion_id' => $automatic->id, 'code' => 'OLDSHIP']);
    $q = quoteOf($w, ['coupon_code' => 'OLDSHIP']);
    expect($q['totals']['delivery_fee']['amount'])->toBe('15.00')->and($q['totals']['discount']['amount'])->toBe('0.00')->and($q['discounts'])->toBe([])
        ->and(collect($q['warnings'])->pluck('code'))->toContain('coupon_invalid');
});

it('lets item promotions that are automatic keep working next to a coupon-only free delivery', function () {
    $w = commerceWorld([20, 20], [20, 20]);
    Promotion::create(['name_en' => 'Ten off', 'name_ar' => 'خصم', 'type' => 'percentage', 'value' => '10', 'scope' => 'order', 'requires_coupon' => false, 'is_active' => true]);
    freeDeliveryPromotion(['requires_coupon' => false]); // ignored
    $this->postJson('/api/v1/cart/items', ['variant_id' => $w->v1->id, 'quantity' => 1])->assertSuccessful();

    $q = quoteOf($w);
    expect($q['totals']['discount']['amount'])->toBe('100.00')->and($q['totals']['delivery_fee']['amount'])->toBe('15.00')->and($q['totals']['grand_total']['amount'])->toBe('925.00');
});

/* ───────────────────────── an order that already exists ───────────────────────── */

it('keeps the delivery fee of an order as charged when its items change, whatever the basket becomes worth', function () {
    $a = adjustmentWorld(['qty' => [6, 0]]); // six sofas = 6000, delivery 15, cash on delivery fee 10
    expect($a->order->delivery_fee_total)->toBe('15.00')->and($a->order->grand_total)->toBe('6025.00');
    // Even a stale threshold in the database must not change what an existing order pays.
    BranchDeliveryZone::query()->update(['free_delivery_threshold' => '100.00']);

    postAdjust($a, [['order_item_id' => $a->l1->id, 'action' => 'reduce', 'new_quantity' => 1]])->assertOk();

    $o = $a->order->fresh();
    expect($o->subtotal)->toBe('1000.00')->and($o->delivery_fee_total)->toBe('15.00')->and($o->grand_total)->toBe('1025.00')
        ->and($o->fee_breakdown['delivery_discount'] ?? '0.00')->toBe('0.00')->and($a->f->fresh()->delivery_fee)->toBe('15.00');
});

it('keeps the delivery waiver of a free-delivery coupon order as it was granted when its items change', function () {
    $a = adjustmentWorld(['coupon' => ['free_delivery', '0']]); // 2 sofas + 1 lamp = 2250, delivery 15 waived, cod 10 = 2260
    expect($a->order->delivery_fee_total)->toBe('15.00')->and($a->order->discount_total)->toBe('15.00')->and($a->order->grand_total)->toBe('2260.00');

    postAdjust($a, [['order_item_id' => $a->l1->id, 'action' => 'reduce', 'new_quantity' => 1]])->assertOk();

    $o = $a->order->fresh();
    expect($o->subtotal)->toBe('1250.00')->and($o->delivery_fee_total)->toBe('15.00')->and($o->fee_breakdown['delivery_discount'])->toBe('15.00')
        ->and($o->discount_total)->toBe('15.00')->and($o->grand_total)->toBe('1260.00')
        ->and(OrderCoupon::where('order_id', $o->id)->where('free_delivery', true)->value('discount_amount'))->toBe('15.00');
});

/* ───────────────────────── the dashboard ───────────────────────── */

it('forces a coupon code on a free-delivery promotion in the Promotions form and refuses to save it without one', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $form = Livewire::actingAs(User::factory()->superAdmin()->create())->test(PromotionIndex::class)->call('createPromotion')
        ->assertDontSee(__('marketing_admin.requires_coupon_locked'))
        ->set('p.requires_coupon', false)->set('p.type', 'free_delivery')
        ->assertSet('p.requires_coupon', true)->assertSee(__('marketing_admin.requires_coupon_locked'));

    // A tampered request that switches the code requirement off again is refused with a plain message, and nothing is saved.
    $form->set('p.name_en', 'Free shipping')->set('p.name_ar', 'توصيل مجاني')->set('p.requires_coupon', false)->call('savePromotion')
        ->assertHasErrors('p.requires_coupon')->assertSee(__('marketing_admin.free_delivery_needs_code'));
    expect(Promotion::count())->toBe(0);

    $form->set('p.requires_coupon', true)->call('savePromotion')->assertHasNoErrors();
    $saved = Promotion::firstOrFail();
    expect($saved->type)->toBe('free_delivery')->and($saved->requires_coupon)->toBeTrue()->and($saved->value)->toBe('0.00');
});

it('still lets other promotion types be automatic', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Livewire::actingAs(User::factory()->superAdmin()->create())->test(PromotionIndex::class)->call('createPromotion')->set('p.name_en', 'Spring')->set('p.name_ar', 'ربيع')
        ->set('p.type', 'percentage')->set('p.value', '10')->set('p.requires_coupon', false)->assertDontSee(__('marketing_admin.requires_coupon_locked'))
        ->call('savePromotion')->assertHasNoErrors();
    expect(Promotion::firstOrFail()->requires_coupon)->toBeFalse();
});

it('shows an older automatic free-delivery promotion as coupon-only when it is opened, and saves it that way', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $legacy = freeDeliveryPromotion(['requires_coupon' => false]);
    Livewire::actingAs(User::factory()->superAdmin()->create())->test(PromotionIndex::class)->call('editPromotion', $legacy->id)
        ->assertSet('p.requires_coupon', true)->call('savePromotion')->assertHasNoErrors();
    expect($legacy->fresh()->requires_coupon)->toBeTrue();
});

it('no longer shows or stores a free-delivery amount in the branch zone editor', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $branch = Branch::factory()->create();
    $zone = DeliveryZone::factory()->create();
    $editor = Livewire::actingAs(User::factory()->superAdmin()->create())->test(BranchEditor::class, ['branch' => $branch])->set('tab', 'zones')
        ->assertDontSee('Free above')->assertDontSeeHtml('free_delivery_threshold');
    expect($editor->get('zone'))->not->toHaveKey('free_delivery_threshold');

    // Even a request that still sends the old field stores nothing for it.
    $editor->set('zone.delivery_zone_id', $zone->id)->set('zone.delivery_fee', '20.00')->set('zone.free_delivery_threshold', '500')->call('saveZone')->assertHasNoErrors()
        ->assertSee($zone->name_en)->assertDontSee('Free above');
    $row = DB::table('branch_delivery_zones')->where('branch_id', $branch->id)->first();
    expect($row->delivery_fee)->toBe('20.00')->and($row->free_delivery_threshold)->toBeNull();
});

it('does not send the old column in the zone API, and ignores it when sent', function () {
    $branch = Branch::factory()->create();
    $zone = DeliveryZone::factory()->create();
    actingAsStaff(staffWithRole('admin'));

    $this->postJson("/api/v1/admin/branches/{$branch->id}/zones", ['delivery_zone_id' => $zone->id, 'delivery_fee' => 20, 'free_delivery_threshold' => 500])
        ->assertCreated()->assertJsonMissingPath('data.free_delivery_threshold');
    expect(DB::table('branch_delivery_zones')->where('branch_id', $branch->id)->value('free_delivery_threshold'))->toBeNull();
    $this->getJson("/api/v1/admin/branches/{$branch->id}/zones")->assertOk()->assertJsonCount(1, 'data')->assertJsonMissingPath('data.0.free_delivery_threshold');
});

/* ───────────────────────── the data ───────────────────────── */

it('clears every stored free-delivery threshold and touches nothing else', function () {
    [$a, $b] = Branch::factory()->count(2)->create();
    [$z1, $z2] = DeliveryZone::factory()->count(2)->create();
    foreach ([[$a, $z1, '1500.00'], [$a, $z2, null], [$b, $z1, '0.00']] as [$br, $zn, $threshold]) {
        BranchDeliveryZone::create(['branch_id' => $br->id, 'delivery_zone_id' => $zn->id, 'delivery_fee' => '15.00', 'min_order_amount' => '100.00',
            'free_delivery_threshold' => $threshold]);
    }
    expect(DB::table('branch_delivery_zones')->whereNotNull('free_delivery_threshold')->count())->toBe(2);

    $migration = require base_path('database/migrations/2026_11_16_200000_clear_free_delivery_thresholds.php');
    $migration->up();
    $migration->up(); // safe to run again

    expect(DB::table('branch_delivery_zones')->whereNotNull('free_delivery_threshold')->count())->toBe(0)
        ->and(DB::table('branch_delivery_zones')->count())->toBe(3)
        ->and(DB::table('branch_delivery_zones')->where('delivery_fee', '15.00')->where('min_order_amount', '100.00')->count())->toBe(3);
    $migration->down(); // nothing to restore, and it must not fail
    expect(DB::table('branch_delivery_zones')->whereNotNull('free_delivery_threshold')->count())->toBe(0);
});

it('seeds no free-delivery threshold', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(DemoSeeder::class);
    expect(DB::table('branch_delivery_zones')->count())->toBeGreaterThan(0)->and(DB::table('branch_delivery_zones')->whereNotNull('free_delivery_threshold')->count())->toBe(0);
});
