<?php

require_once __DIR__.'/../Orders/AdjustmentFixtures.php';

use App\Domain\Auctions\Models\AuctionWinner;
use App\Domain\Auctions\Services\LotService;
use App\Domain\Branches\Models\Branch;
use App\Domain\Branches\Models\BranchDeliveryZone;
use App\Domain\Branches\Models\DeliveryZone;
use App\Domain\Catalog\Models\Product;
use App\Domain\Checkout\Services\CartService;
use App\Domain\Checkout\Services\StaffOrderService;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Services\InventoryLedger;
use App\Domain\Marketing\Models\Coupon;
use App\Domain\Marketing\Models\Promotion;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Services\InvoiceService;
use App\Domain\Settings\Models\SystemSetting;
use App\Domain\Settings\Services\Settings;
use App\Livewire\Admin\Branches\BranchEditor;
use App\Livewire\Admin\Organization\SettingsIndex;
use App\Livewire\Admin\Sales\OrderCart;
use App\Support\Money;
use Database\Seeders\DemoSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;

/*
 * docs/api/CHECKOUT_FEES_V2.md: no cash-on-delivery fee, no minimum order, an extra delivery fee per product.
 * commerceWorld: zone fee 15.00 at branch A (priority 10) and 25.00 at branch B, sofa p1 1000, lamp p2 250.
 */

function fv2Fee(Product $p, string $fee, string $mode = 'per_unit'): void
{
    $p->update(['extra_delivery_fee' => $fee, 'extra_delivery_fee_mode' => $mode]);
}

function fv2Add(object $w, $variant, int $qty): void
{
    test()->postJson('/api/v1/cart/items', ['variant_id' => $variant->id, 'quantity' => $qty])->assertSuccessful();
}

/** @return array<string, mixed> */
function fv2Quote(object $w, array $extra = []): array
{
    return test()->postJson('/api/v1/checkout/quote', $extra + ['address_id' => $w->address->id, 'payment_method' => 'cod'])->assertOk()->json('data');
}

function fv2Place(object $w, array $extra = []): Order
{
    return Order::findOrFail(quoteAndPlace($extra + ['address_id' => $w->address->id, 'payment_method' => 'cod'])->assertCreated()->json('data.order.id'));
}

function fv2ClearCart(object $w): void
{
    app(CartService::class)->current($w->customer)->items()->delete();
}

function fv2Money(string ...$parts): string
{
    return array_reduce($parts, fn (Money $c, string $p) => $c->plus(Money::of($p)), Money::zero())->toDecimal();
}

function fv2FreeDelivery(string $code = 'FREESHIP'): void
{
    $promo = Promotion::create(['name_en' => 'Free delivery', 'name_ar' => 'توصيل مجاني', 'type' => 'free_delivery', 'value' => '0', 'scope' => 'order', 'requires_coupon' => true, 'is_active' => true]);
    Coupon::create(['promotion_id' => $promo->id, 'code' => $code]);
}

/* ───────────────────────── 1. cash on delivery costs nothing extra ───────────────────────── */

it('never adds a cash on delivery fee: the same totals for every payment method, with the key kept at 0.00', function () {
    $w = commerceWorld();
    fv2Add($w, $w->v2, 1);

    foreach (['cod', 'sadad', null] as $method) {
        $q = fv2Quote($w, ['payment_method' => $method]);
        expect($q['totals']['cod_fee']['amount'])->toBe('0.00')->and($q['totals']['grand_total']['amount'])->toBe('265.00')
            ->and($q['totals']['service_fee']['amount'])->toBe('0.00');
    }
    // Pickup, pay at pickup.
    $pickup = fv2Quote($w, ['fulfillment_type' => 'pickup', 'branch_id' => $w->a->id, 'payment_method' => 'pay_at_pickup']);
    expect($pickup['totals']['cod_fee']['amount'])->toBe('0.00')->and($pickup['totals']['grand_total']['amount'])->toBe('250.00');
});

it('places a cash on delivery order with no cod fee anywhere: the service fee, the total and the payment', function () {
    $w = commerceWorld();
    app(Settings::class)->set('checkout.service_fee', '5.00');
    fv2Add($w, $w->v2, 1);

    $order = fv2Place($w);
    expect($order->service_fee_total)->toBe('5.00')->and($order->grand_total)->toBe('270.00')->and($order->fee_breakdown)->not->toHaveKey('cod_fee')
        ->and($order->fee_breakdown['service_fee'])->toBe('5.00')
        ->and(DB::table('payments')->where('order_id', $order->id)->value('amount'))->toBe('270.00');
});

it('ignores a cash on delivery fee still stored in the database from before', function () {
    $w = commerceWorld();
    SystemSetting::create(['group' => 'checkout', 'key' => 'cod_fee', 'value' => ['v' => '25.00'], 'is_secret' => false]);
    config(['platform.checkout.cod_fee' => '25.00']); // even an old config value
    fv2Add($w, $w->v2, 1);

    expect(fv2Quote($w)['totals']['grand_total']['amount'])->toBe('265.00');
});

it('has no cash on delivery fee field in the dashboard Settings, the config, the language files or the staff panel', function () {
    expect(SettingsIndex::schema()['checkout'])->not->toHaveKey('checkout.cod_fee')->and(config('platform.checkout'))->not->toHaveKey('cod_fee');
    foreach (['en', 'ar'] as $locale) {
        foreach (['org_admin', 'neworder_admin'] as $file) {
            expect(file_get_contents(lang_path("{$locale}/{$file}.php")))->not->toContain('cod_fee');
        }
        expect(Lang::has('neworder_admin.cod_fee', $locale))->toBeFalse();
    }

    $this->actingAs(User::factory()->superAdmin()->create());
    Livewire::test(SettingsIndex::class)->set('tab', 'checkout')->assertSee('Service fee')->assertDontSee('Cash on delivery fee')->assertDontSeeHtml('checkout__cod_fee');
});

it('deletes the stored cash on delivery fee with its migration, touches no other setting and is safe to run twice', function () {
    $settings = app(Settings::class);
    $settings->set('checkout.cod_fee', '12.50');
    $settings->set('checkout.service_fee', '3.00');
    expect($settings->get('checkout.cod_fee'))->toBe('12.50');

    $migration = require base_path('database/migrations/2026_11_17_100000_remove_cod_fee_setting.php');
    $migration->up();
    $migration->up();

    expect(DB::table('system_settings')->where('group', 'checkout')->where('key', 'cod_fee')->count())->toBe(0)
        ->and($settings->get('checkout.cod_fee'))->toBeNull()
        ->and($settings->get('checkout.service_fee'))->toBe('3.00');
    $migration->down(); // nothing to restore, and it must not fail
    expect(DB::table('system_settings')->where('group', 'checkout')->where('key', 'cod_fee')->count())->toBe(0);
});

it('keeps an order placed with the old cash on delivery fee adding up when its items change', function () {
    $a = adjustmentWorld(); // 2 sofas + 1 lamp + 15 delivery + 10 old cod fee = 2275 (see the fixture)
    expect($a->order->grand_total)->toBe('2275.00')->and($a->order->service_fee_total)->toBe('10.00')->and($a->order->fee_breakdown['cod_fee'])->toBe('10.00');

    postAdjust($a, [['order_item_id' => $a->l1->id, 'action' => 'reduce', 'new_quantity' => 1]])->assertOk();

    $o = $a->order->fresh();
    expect($o->subtotal)->toBe('1250.00')->and($o->grand_total)->toBe('1275.00') // 1250 + 15 + 10: the fee stays
        ->and($o->service_fee_total)->toBe('10.00')->and($o->fee_breakdown['cod_fee'])->toBe('10.00');
});

/* ───────────────────────── 2. no minimum order ───────────────────────── */

it('has no minimum order: a tiny order is quoted and placed, whatever a zone still has stored', function () {
    $w = commerceWorld();
    BranchDeliveryZone::query()->update(['min_order_amount' => '5000.00']); // a stale number written by hand: nothing reads it
    fv2Add($w, $w->v2, 1);

    $q = fv2Quote($w);
    expect($q['errors'])->toBe([])->and($q['can_place_order'])->toBeTrue()->and(json_encode($q))->not->toContain('below_min_order')->not->toContain('shortfall');
    fv2Place($w);
});

it('still refuses an order above the zone maximum, and says so as before', function () {
    $w = commerceWorld([20, 20], [20, 20]);
    BranchDeliveryZone::query()->update(['max_order_amount' => '2000.00']);
    fv2Add($w, $w->v1, 3);

    expect(collect(fv2Quote($w)['errors'])->pluck('code')->all())->toBe(['above_max_order']);
});

it('shows and stores no minimum order in the zone editor', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $branch = Branch::factory()->create();
    $zone = DeliveryZone::factory()->create();
    $editor = Livewire::actingAs(User::factory()->superAdmin()->create())->test(BranchEditor::class, ['branch' => $branch])->set('tab', 'zones')
        ->assertDontSee('Minimum order')->assertDontSeeHtml('zone.min_order_amount')->assertSee('Maximum order');
    expect($editor->get('zone'))->not->toHaveKey('min_order_amount');

    // A request that still sends the field stores nothing for it, and the maximum needs no minimum any more.
    $editor->set('zone.delivery_zone_id', $zone->id)->set('zone.delivery_fee', '20.00')->set('zone.min_order_amount', '500')->set('zone.max_order_amount', '90')
        ->call('saveZone')->assertHasNoErrors();
    $row = DB::table('branch_delivery_zones')->where('branch_id', $branch->id)->first();
    expect($row->min_order_amount)->toBeNull()->and($row->max_order_amount)->toBe('90.00');
});

it('does not accept, store or send a minimum order in the zone API', function () {
    $branch = Branch::factory()->create();
    $zone = DeliveryZone::factory()->create();
    actingAsStaff(staffWithRole('admin'));

    $this->postJson("/api/v1/admin/branches/{$branch->id}/zones", ['delivery_zone_id' => $zone->id, 'delivery_fee' => 20, 'min_order_amount' => 100, 'max_order_amount' => 50])
        ->assertCreated()->assertJsonMissingPath('data.min_order_amount');
    expect(DB::table('branch_delivery_zones')->where('branch_id', $branch->id)->value('min_order_amount'))->toBeNull();
    // The old rule "the maximum must not be below the minimum" is gone with the minimum.
    $this->getJson("/api/v1/admin/branches/{$branch->id}/zones")->assertOk()->assertJsonCount(1, 'data')->assertJsonMissingPath('data.0.min_order_amount')
        ->assertJsonPath('data.0.max_order_amount', '50.00');
    $this->postJson("/api/v1/admin/branches/{$branch->id}/zones", ['delivery_zone_id' => $zone->id, 'delivery_fee' => 20, 'max_order_amount' => -5])
        ->assertStatus(422)->assertJsonValidationErrors('max_order_amount');
});

it('clears every stored minimum order with its migration, keeps the rest and is safe to run twice', function () {
    [$a, $b] = Branch::factory()->count(2)->create();
    [$z1, $z2] = DeliveryZone::factory()->count(2)->create();
    foreach ([[$a, $z1, '100.00', '900.00'], [$a, $z2, null, null], [$b, $z1, '0.00', '1500.00']] as [$br, $zn, $min, $max]) {
        BranchDeliveryZone::create(['branch_id' => $br->id, 'delivery_zone_id' => $zn->id, 'delivery_fee' => '15.00', 'min_order_amount' => $min, 'max_order_amount' => $max]);
    }
    expect(DB::table('branch_delivery_zones')->whereNotNull('min_order_amount')->count())->toBe(2);

    $migration = require base_path('database/migrations/2026_11_17_100100_clear_min_order_amounts.php');
    $migration->up();
    $migration->up();

    expect(DB::table('branch_delivery_zones')->whereNotNull('min_order_amount')->count())->toBe(0)
        ->and(DB::table('branch_delivery_zones')->count())->toBe(3)
        ->and(DB::table('branch_delivery_zones')->where('delivery_fee', '15.00')->count())->toBe(3)
        ->and(DB::table('branch_delivery_zones')->orderBy('max_order_amount')->pluck('max_order_amount')->all())->toBe(['900.00', '1500.00', null]);
    $migration->down(); // nothing to restore, and it must not fail
    expect(DB::table('branch_delivery_zones')->whereNotNull('min_order_amount')->count())->toBe(0);
});

it('seeds no minimum order', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(DemoSeeder::class);
    expect(DB::table('branch_delivery_zones')->count())->toBeGreaterThan(0)->and(DB::table('branch_delivery_zones')->whereNotNull('min_order_amount')->count())->toBe(0);
});

/* ───────────────────────── 3. the extra delivery fee: the migration ───────────────────────── */

it('adds the extra delivery fee columns with safe defaults and checks, and can be rolled back and run again', function () {
    $existing = Product::factory()->create();
    $migration = require base_path('database/migrations/2026_11_17_100200_add_extra_delivery_fee_to_products.php');

    $migration->up(); // safe to run again: only adds what is missing
    expect(Schema::hasColumns('products', ['extra_delivery_fee', 'extra_delivery_fee_mode']))->toBeTrue()
        ->and((array) DB::table('products')->where('id', $existing->id)->first(['extra_delivery_fee', 'extra_delivery_fee_mode']))->toBe(['extra_delivery_fee' => '0.00', 'extra_delivery_fee_mode' => 'per_unit']);
    // The database refuses a negative fee and an unknown mode, whoever writes them (each attempt in its own savepoint, so the test transaction survives).
    expect(fn () => DB::transaction(fn () => DB::table('products')->where('id', $existing->id)->update(['extra_delivery_fee' => '-1.00'])))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => DB::table('products')->where('id', $existing->id)->update(['extra_delivery_fee_mode' => 'weekly'])))->toThrow(QueryException::class);

    $migration->down();
    expect(Schema::hasColumn('products', 'extra_delivery_fee'))->toBeFalse()->and(Schema::hasColumn('products', 'extra_delivery_fee_mode'))->toBeFalse();
    $migration->down(); // and again: nothing left to remove
    $migration->up();
    expect(DB::table('products')->where('id', $existing->id)->value('extra_delivery_fee'))->toBe('0.00');
});

/* ───────────────────────── 3. the extra delivery fee: the quote ───────────────────────── */

it('adds the fee of a per-unit product for every unit', function (int $qty) {
    $w = commerceWorld([20, 20], [20, 20]);
    fv2Fee($w->p1, '50.00', 'per_unit');
    fv2Add($w, $w->v1, $qty);

    $q = fv2Quote($w);
    $extra = Money::of('50.00')->times($qty)->toDecimal();
    expect($q['totals']['extra_delivery_fee']['amount'])->toBe($extra)
        ->and($q['totals']['delivery_fee']['amount'])->toBe(fv2Money('15.00', $extra))
        ->and($q['fulfillments'][0]['delivery_fee']['amount'])->toBe(fv2Money('15.00', $extra))
        ->and($q['fulfillments'][0]['extra_delivery_fee']['amount'])->toBe($extra)
        ->and($q['totals']['grand_total']['amount'])->toBe(fv2Money(Money::of('1000.00')->times($qty)->toDecimal(), '15.00', $extra))
        ->and($q['delivery_extras'])->toHaveCount(1)
        ->and($q['delivery_extras'][0])->toMatchArray(['product_id' => $w->p1->id, 'name' => $w->p1->trans('name', 'en'), 'mode' => 'per_unit', 'quantity' => $qty, 'branch_id' => $w->a->id])
        ->and($q['delivery_extras'][0]['fee_each']['amount'])->toBe('50.00')->and($q['delivery_extras'][0]['amount']['amount'])->toBe($extra)
        ->and($q['fulfillments'][0]['delivery_extras'])->toBe($q['delivery_extras']);
})->with([1, 2, 5]);

it('adds the fee of a per-line product once, whatever the quantity', function (int $qty) {
    $w = commerceWorld([20, 20], [20, 20]);
    fv2Fee($w->p1, '50.00', 'per_line');
    fv2Add($w, $w->v1, $qty);

    $q = fv2Quote($w);
    expect($q['totals']['extra_delivery_fee']['amount'])->toBe('50.00')->and($q['totals']['delivery_fee']['amount'])->toBe('65.00')
        ->and($q['delivery_extras'][0])->toMatchArray(['mode' => 'per_line', 'quantity' => $qty])->and($q['delivery_extras'][0]['amount']['amount'])->toBe('50.00');
})->with([1, 2, 5]);

it('charges nothing extra for a product without a fee and shows an empty list', function () {
    $w = commerceWorld();
    fv2Add($w, $w->v1, 2);

    $q = fv2Quote($w);
    expect($q['totals']['extra_delivery_fee']['amount'])->toBe('0.00')->and($q['delivery_extras'])->toBe([])->and($q['fulfillments'][0]['delivery_extras'])->toBe([])
        ->and($q['fulfillments'][0]['extra_delivery_fee']['amount'])->toBe('0.00')->and($q['totals']['delivery_fee']['amount'])->toBe('15.00');
});

it('counts the variants of one product together: summed units per unit, once per line', function (string $mode, string $expected) {
    $w = commerceWorld();
    $p = Product::factory()->withVariants(2)->create(['price' => '400.00']);
    [$va, $vb] = $p->variants()->get()->all();
    foreach ([$va, $vb] as $v) {
        app(InventoryLedger::class)->apply($w->a->id, $v->id, MovementType::OpeningBalance, 10);
    }
    fv2Fee($p, '30.00', $mode);
    fv2Add($w, $va, 2);
    fv2Add($w, $vb, 3);

    $q = fv2Quote($w);
    expect($q['delivery_extras'])->toHaveCount(1)->and($q['delivery_extras'][0]['quantity'])->toBe(5)->and($q['totals']['extra_delivery_fee']['amount'])->toBe($expected)
        ->and($q['totals']['delivery_fee']['amount'])->toBe(fv2Money('15.00', $expected));
})->with([
    'per unit' => ['per_unit', '150.00'],
    'per line' => ['per_line', '30.00'],
]);

it('adds the fees of two products with their own modes and lists each one', function () {
    $w = commerceWorld([20, 20], [20, 20]);
    fv2Fee($w->p1, '50.00', 'per_unit');
    fv2Fee($w->p2, '12.50', 'per_line');
    fv2Add($w, $w->v1, 2);
    fv2Add($w, $w->v2, 3);

    $q = fv2Quote($w);
    expect($q['totals']['extra_delivery_fee']['amount'])->toBe('112.50')->and($q['totals']['delivery_fee']['amount'])->toBe('127.50')
        ->and(collect($q['delivery_extras'])->pluck('amount.amount', 'product_id')->all())->toEqual([$w->p1->id => '100.00', $w->p2->id => '12.50']);
});

it('charges each branch part of a split order its own fees', function () {
    $w = commerceWorld([10, 0], [0, 10]); // sofas only at A (fee 15), lamps only at B (fee 25)
    fv2Fee($w->p1, '50.00', 'per_line');
    fv2Fee($w->p2, '20.00', 'per_unit');
    fv2Add($w, $w->v1, 3);
    fv2Add($w, $w->v2, 3);

    $q = fv2Quote($w);
    $byBranch = collect($q['fulfillments'])->keyBy('branch.id');
    expect($q['is_split'])->toBeTrue()
        ->and($byBranch[$w->a->id]['delivery_fee']['amount'])->toBe('65.00')->and($byBranch[$w->a->id]['extra_delivery_fee']['amount'])->toBe('50.00')
        ->and($byBranch[$w->b->id]['delivery_fee']['amount'])->toBe('85.00')->and($byBranch[$w->b->id]['extra_delivery_fee']['amount'])->toBe('60.00')
        ->and($q['totals']['delivery_fee']['amount'])->toBe('150.00')->and($q['totals']['extra_delivery_fee']['amount'])->toBe('110.00')
        ->and($q['delivery_extras'])->toHaveCount(2);
});

it('charges the same product split over two branches per unit in total, and once per branch part per line', function (string $mode, string $expected) {
    $w = commerceWorld([5, 5], [5, 5]); // 8 sofas: 5 at A + 3 at B
    fv2Fee($w->p1, '50.00', $mode);
    fv2Add($w, $w->v1, 8);

    $q = fv2Quote($w);
    expect($q['is_split'])->toBeTrue()->and($q['totals']['extra_delivery_fee']['amount'])->toBe($expected)
        ->and(collect($q['delivery_extras'])->sum(fn ($e) => $e['quantity']))->toBe(8);
})->with([
    'per unit' => ['per_unit', '400.00'],
    'per line' => ['per_line', '100.00'], // once in each branch part
]);

it('never charges the extra fee for pickup', function () {
    $w = commerceWorld();
    fv2Fee($w->p1, '50.00', 'per_unit');
    fv2Add($w, $w->v1, 2);

    $q = fv2Quote($w, ['fulfillment_type' => 'pickup', 'branch_id' => $w->a->id, 'payment_method' => 'pay_at_pickup']);
    expect($q['totals']['delivery_fee']['amount'])->toBe('0.00')->and($q['totals']['extra_delivery_fee']['amount'])->toBe('0.00')->and($q['delivery_extras'])->toBe([])
        ->and($q['totals']['grand_total']['amount'])->toBe('2000.00');

    $order = Order::findOrFail(quoteAndPlace(['fulfillment_type' => 'pickup', 'branch_id' => $w->a->id, 'payment_method' => 'pay_at_pickup'])->assertCreated()->json('data.order.id'));
    expect($order->delivery_fee_total)->toBe('0.00')->and($order->extraDeliveryFee()->isZero())->toBeTrue()->and($order->deliveryExtras())->toBe([]);
});

it('waives the whole delivery fee, extras included, with a free-delivery coupon only', function () {
    $w = commerceWorld();
    fv2Fee($w->p1, '50.00', 'per_unit');
    fv2FreeDelivery();
    fv2Add($w, $w->v1, 2);

    $plain = fv2Quote($w);
    expect($plain['totals']['delivery_fee']['amount'])->toBe('115.00')->and($plain['totals']['discount']['amount'])->toBe('0.00')->and($plain['totals']['grand_total']['amount'])->toBe('2115.00');

    $q = fv2Quote($w, ['coupon_code' => 'freeship']);
    expect($q['totals']['delivery_fee']['amount'])->toBe('115.00') // the gross fee stays, so the app can strike it through
        ->and($q['totals']['extra_delivery_fee']['amount'])->toBe('100.00')
        ->and($q['totals']['discount']['amount'])->toBe('115.00')->and($q['totals']['grand_total']['amount'])->toBe('2000.00')
        ->and($q['discounts'][0]['free_delivery'])->toBeTrue()->and($q['discounts'][0]['amount']['amount'])->toBe('115.00');

    $order = fv2Place($w, ['coupon_code' => 'FREESHIP']);
    expect($order->delivery_fee_total)->toBe('115.00')->and($order->discount_total)->toBe('115.00')->and($order->grand_total)->toBe('2000.00')
        ->and($order->fee_breakdown['delivery_discount'])->toBe('115.00')->and($order->fee_breakdown['extra_delivery']['amount'])->toBe('100.00');
});

it('stacks with the oversized surcharge and the express fee', function () {
    $w = commerceWorld();
    BranchDeliveryZone::query()->update(['oversized_surcharge' => '40.00', 'express_enabled' => true, 'express_fee' => '35.00']);
    $w->p1->update(['is_oversized' => true]);
    fv2Fee($w->p1, '50.00', 'per_unit');
    fv2Add($w, $w->v1, 2);

    $plain = fv2Quote($w);
    expect($plain['totals']['delivery_fee']['amount'])->toBe(fv2Money('15.00', '40.00', '100.00')); // zone + oversized + extras
    $express = fv2Quote($w, ['express' => true]);
    expect($express['totals']['delivery_fee']['amount'])->toBe(fv2Money('15.00', '40.00', '100.00', '35.00'))->and($express['totals']['extra_delivery_fee']['amount'])->toBe('100.00');
});

it('keeps the money exact with cents and with the largest fee on the largest line', function () {
    $w = commerceWorld([60, 60], [60, 60]);
    fv2Fee($w->p2, '33.33', 'per_unit');
    fv2Add($w, $w->v2, 3);
    expect(fv2Quote($w)['totals']['extra_delivery_fee']['amount'])->toBe('99.99');

    fv2ClearCart($w);
    fv2Fee($w->p2, '100000.00', 'per_unit');
    fv2Add($w, $w->v2, 50);
    $q = fv2Quote($w);
    expect($q['totals']['extra_delivery_fee']['amount'])->toBe('5000000.00')->and($q['totals']['delivery_fee']['amount'])->toBe('5000015.00');
});

it('refuses a zero quantity and so never charges a product that is not in the cart', function () {
    $w = commerceWorld();
    fv2Fee($w->p1, '50.00');
    $this->postJson('/api/v1/cart/items', ['variant_id' => $w->v1->id, 'quantity' => 0])->assertStatus(422);
    fv2Add($w, $w->v2, 1);

    expect(fv2Quote($w)['totals']['extra_delivery_fee']['amount'])->toBe('0.00');
});

it('binds the fee to the quote: a changed fee after quoting is a 409 with the new quote', function () {
    $w = commerceWorld();
    fv2Fee($w->p1, '50.00');
    fv2Add($w, $w->v1, 1);
    $opts = ['address_id' => $w->address->id, 'payment_method' => 'cod'];
    $hash = $this->postJson('/api/v1/checkout/quote', $opts)->json('data.quote_hash');

    fv2Fee($w->p1, '80.00');
    $res = $this->postJson('/api/v1/checkout/orders', $opts + ['quote_hash' => $hash], ['Idempotency-Key' => (string) Str::uuid()])
        ->assertStatus(409)->assertJsonPath('code', 'quote_changed');
    expect($res->json('context.quote.totals.extra_delivery_fee.amount'))->toBe('80.00')->and(Order::count())->toBe(0);
});

/* ───────────────────────── 3. the extra delivery fee: the order, the invoice, the staff panel, auctions ───────────────────────── */

it('stores the fee on the order, shows it to the customer in both languages and keeps it when the product changes', function () {
    $w = commerceWorld();
    fv2Fee($w->p1, '50.00', 'per_unit');
    fv2Add($w, $w->v1, 2);

    $order = fv2Place($w);
    $extra = $order->fee_breakdown['extra_delivery'];
    expect($order->delivery_fee_total)->toBe('115.00')->and($order->grand_total)->toBe('2115.00')->and($order->fulfillments->first()->delivery_fee)->toBe('115.00')
        ->and($order->fee_breakdown['delivery'][0])->toMatchArray(['fee' => '115.00', 'extra' => '100.00'])
        ->and($extra['amount'])->toBe('100.00')->and($extra['lines'])->toHaveCount(1)
        ->and($extra['lines'][0])->toMatchArray(['branch_id' => $w->a->id, 'product_id' => $w->p1->id, 'mode' => 'per_unit', 'quantity' => 2, 'fee_each' => '50.00', 'amount' => '100.00',
            'name_en' => $w->p1->trans('name', 'en'), 'name_ar' => $w->p1->trans('name', 'ar')]);

    // Renamed and repriced later: the order keeps what it was placed with.
    $w->p1->translations()->where('locale', 'en')->update(['name' => 'Renamed']);
    fv2Fee($w->p1, '999.00', 'per_line');

    $en = $this->getJson("/api/v1/orders/{$order->id}")->assertOk();
    expect($en->json('data.totals.extra_delivery_fee.amount'))->toBe('100.00')->and($en->json('data.totals.delivery_fee.amount'))->toBe('115.00')
        ->and($en->json('data.delivery_extras.0'))->toMatchArray(['product_id' => $w->p1->id, 'mode' => 'per_unit', 'quantity' => 2])
        ->and($en->json('data.delivery_extras.0.name'))->toBe($order->fee_breakdown['extra_delivery']['lines'][0]['name_en'])
        ->and($en->json('data.delivery_extras.0.fee_each.amount'))->toBe('50.00')->and($en->json('data.delivery_extras.0.amount.amount'))->toBe('100.00');
    $ar = $this->withHeaders(['Accept-Language' => 'ar'])->getJson("/api/v1/orders/{$order->id}")->assertOk();
    expect($ar->json('data.delivery_extras.0.name'))->toBe($order->fee_breakdown['extra_delivery']['lines'][0]['name_ar']);
});

it('shows an order without extra fees an empty list and 0.00', function () {
    $w = commerceWorld();
    fv2Add($w, $w->v2, 1);
    $order = fv2Place($w);

    $res = $this->getJson("/api/v1/orders/{$order->id}")->assertOk();
    expect($res->json('data.totals.extra_delivery_fee.amount'))->toBe('0.00')->and($res->json('data.delivery_extras'))->toBe([]);
});

it('prints the extra fee as a sub-line of the delivery on the invoice, and nothing when there is none', function () {
    $w = commerceWorld();
    fv2Fee($w->p1, '50.00', 'per_unit');
    fv2Add($w, $w->v1, 2);
    $order = fv2Place($w);
    $invoices = app(InvoiceService::class);

    $invoice = $invoices->issue($order);
    $html = view('documents.invoice', ['invoice' => $invoice, 's' => $invoice->snapshot, 'locale' => 'en'])->render();
    expect($invoice->snapshot['extra_delivery_fee'])->toBe('100.00')->and($html)->toContain('extra delivery fee for bulky items')->toContain('100.00')
        ->and($invoices->pdf($invoice, $order)->output())->toStartWith('%PDF');
    $arabic = view('documents.invoice', ['invoice' => $invoice, 's' => $invoice->snapshot, 'locale' => 'ar'])->render();
    expect($arabic)->toContain('extra delivery fee for bulky items');

    // No fee: no sub-line. An invoice issued before the feature (no key in its snapshot) renders as before.
    fv2Fee($w->p1, '0.00');
    fv2ClearCart($w);
    fv2Add($w, $w->v2, 1);
    $plain = $invoices->issue(fv2Place($w));
    $snapshot = $plain->snapshot;
    unset($snapshot['extra_delivery_fee']);
    expect(view('documents.invoice', ['invoice' => $plain, 's' => $plain->snapshot, 'locale' => 'en'])->render())->not->toContain('extra delivery fee for bulky items')
        ->and(view('documents.invoice', ['invoice' => $plain, 's' => $snapshot, 'locale' => 'en'])->render())->not->toContain('extra delivery fee for bulky items');
});

it('shows the bulky-item fee as a sub-line of the delivery fee on the dashboard order page, and nothing without one', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $w = commerceWorld();
    fv2Fee($w->p1, '50.00', 'per_unit');
    fv2Add($w, $w->v1, 2);
    $withFee = fv2Place($w);
    fv2ClearCart($w);
    fv2Add($w, $w->v2, 1);
    $plain = fv2Place($w);

    $this->actingAs(User::factory()->superAdmin()->create());
    $this->get(route('admin.orders.show', $withFee))->assertOk()->assertSee('of which extra fee for bulky items');
    $this->get(route('admin.orders.show', $plain))->assertOk()->assertDontSee('of which extra fee for bulky items');
});

it('shows the extra fee line in the totals of a staff draft and places the order with it', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $w = commerceWorld();
    fv2Fee($w->p1, '50.00', 'per_unit');
    $svc = app(StaffOrderService::class);
    $staff = staffWithRole('branch_manager', $w->a);
    $draft = $svc->create($staff, null, $w->customer->id);
    $svc->add($staff, $draft, $w->v1->id, 2);
    $svc->setOptions($staff, $draft, ['address_id' => $w->address->id, 'payment_method' => 'cod']);

    $quote = $svc->quote($staff, $draft->fresh());
    expect($quote->extraDeliveryFeeTotal()->toDecimal())->toBe('100.00')->and($quote->deliveryFeeTotal()->toDecimal())->toBe('115.00')->and($quote->grandTotal()->toDecimal())->toBe('2115.00');

    $this->actingAs($staff);
    Livewire::test(OrderCart::class, ['mode' => 'docked', 'step' => 'checkout'])->assertSee(__('neworder_admin.extra_delivery'))->assertDontSee('Cash on delivery fee');
    $placed = $svc->place($staff, $draft->fresh(), $quote->hash, 'staff-key-'.str_repeat('f', 12));
    expect($placed['order']->fresh()->extraDeliveryFee()->toDecimal())->toBe('100.00')->and($placed['order']->fresh()->delivery_fee_total)->toBe('115.00');
});

it('adds the product fee, for one unit, to the delivery fee of an auction win', function () {
    $w = commerceWorld();
    fv2Fee($w->p1, '50.00', 'per_unit');
    $auction = auctionWorld(['product_variant_id' => $w->v1->id, 'branch_id' => $w->a->id], ['cod_allowed' => true]);
    bid($auction, $auction->u1, '400.00');
    $this->travel(11)->minutes();
    app(LotService::class)->tick();
    $winner = AuctionWinner::firstOrFail();

    Sanctum::actingAs($auction->u1, ['customer']);
    $address = $auction->u1->addresses()->create(['recipient_name' => 'Noor', 'recipient_phone' => '+97455123456', 'zone_number' => '66', 'street_number' => '850', 'building_number' => '12', 'is_default' => true]);
    $res = $this->postJson("/api/v1/me/auctions/wins/{$winner->id}/checkout", ['fulfillment_type' => 'delivery', 'address_id' => $address->id, 'payment_method' => 'cod'])->assertCreated();
    $order = Order::findOrFail($res->json('data.order.id'));
    expect($order->delivery_fee_total)->toBe('65.00')->and($order->grand_total)->toBe('465.00')->and($order->fulfillments->first()->delivery_fee)->toBe('65.00')
        ->and($order->extraDeliveryFee()->toDecimal())->toBe('50.00')->and($order->deliveryExtras())->toHaveCount(1)
        ->and($res->json('data.order.totals.extra_delivery_fee.amount'))->toBe('50.00');
});

it('gives an auction win of a per-line product the same fee, and none without a fee', function () {
    $w = commerceWorld();
    fv2Fee($w->p1, '50.00', 'per_line');
    $auction = auctionWorld(['product_variant_id' => $w->v1->id, 'branch_id' => $w->a->id], ['cod_allowed' => true]);
    bid($auction, $auction->u1, '400.00');
    $this->travel(11)->minutes();
    app(LotService::class)->tick();
    $winner = AuctionWinner::firstOrFail();

    Sanctum::actingAs($auction->u1, ['customer']);
    $pickup = $this->postJson("/api/v1/me/auctions/wins/{$winner->id}/checkout", ['fulfillment_type' => 'pickup', 'branch_id' => $w->a->id, 'payment_method' => 'pay_at_pickup'])->assertCreated();
    expect($pickup->json('data.order.totals.delivery_fee.amount'))->toBe('0.00')->and($pickup->json('data.order.totals.extra_delivery_fee.amount'))->toBe('0.00'); // pickup: none
});

/* ───────────────────────── 4. the alternative phone number follows the dashboard switch ───────────────────────── */

const FV2_ALT = '+97455987654';

it('never stores a number sent with an address, whatever the switch says, and does not send one back', function (bool $on) {
    $w = commerceWorld();
    app(Settings::class)->set('customer_app.alternate_phone_enabled', $on);

    $created = $this->postJson('/api/v1/me/addresses', ['recipient_name' => 'Noor', 'recipient_phone' => '+97455123456', 'zone_number' => '66', 'street_number' => '850',
        'building_number' => '13', 'alternate_phone' => FV2_ALT])->assertCreated();
    $id = $created->json('data.id');
    $this->patchJson("/api/v1/me/addresses/{$id}", ['building_number' => '14', 'alternate_phone' => FV2_ALT])->assertOk();

    expect(json_encode($created->json()))->not->toContain('987654')->and(json_encode($this->getJson("/api/v1/me/addresses/{$id}")->json()))->not->toContain('987654')
        ->and(DB::table('user_addresses')->where('id', $id)->count())->toBe(1)->and(Schema::hasColumn('user_addresses', 'alternate_phone'))->toBeFalse()
        ->and($w->customer->fresh()->alternate_phone)->toBeNull();
})->with([true, false]);

it('ignores a number at checkout while the switch is off: the order never carries it and nothing is saved on the profile', function () {
    $w = commerceWorld();
    app(Settings::class)->set('customer_app.alternate_phone_enabled', false);
    fv2Add($w, $w->v2, 1);

    $order = fv2Place($w, ['alternate_phone' => FV2_ALT, 'no_alternate_phone' => false]);
    $detail = $this->getJson("/api/v1/orders/{$order->id}")->assertOk();
    $invoice = app(InvoiceService::class)->issue($order);
    expect($order->alternate_phone)->toBeNull()->and($w->customer->fresh()->alternate_phone)->toBeNull()
        ->and(json_encode($detail->json()))->not->toContain('987654')->and(json_encode($invoice->snapshot))->not->toContain('987654');
});

it('uses the number only while the switch is on, and never puts it on the customer order page or the invoice', function () {
    $w = commerceWorld();
    fv2Add($w, $w->v2, 1);

    $order = fv2Place($w, ['alternate_phone' => FV2_ALT]);
    $detail = $this->getJson("/api/v1/orders/{$order->id}")->assertOk();
    expect($order->alternate_phone)->toBe(FV2_ALT)->and(json_encode($detail->json()))->not->toContain('987654')
        ->and(json_encode(app(InvoiceService::class)->issue($order)->snapshot))->not->toContain('987654');
});

it('does not let staff placing an order store a number while the switch is off, even when the customer has one saved', function (bool $on, ?string $expected) {
    $this->seed(RolesAndPermissionsSeeder::class);
    $w = commerceWorld();
    $w->customer->forceFill(['alternate_phone' => FV2_ALT])->save();
    app(Settings::class)->set('customer_app.alternate_phone_enabled', $on);
    $svc = app(StaffOrderService::class);
    $staff = staffWithRole('branch_manager', $w->a);
    $draft = $svc->create($staff, null, $w->customer->id);
    $svc->add($staff, $draft, $w->v2->id, 1);
    $svc->setOptions($staff, $draft, ['address_id' => $w->address->id, 'payment_method' => 'cod']);
    $quote = $svc->quote($staff, $draft->fresh());

    $order = $svc->place($staff, $draft->fresh(), $quote->hash, 'staff-key-'.str_repeat('s', 12))['order']->fresh();
    expect($order->alternate_phone)->toBe($expected)->and($w->customer->fresh()->alternate_phone)->toBe(FV2_ALT); // the profile is never changed by staff
})->with([
    'switch on: the saved number is carried for the driver' => [true, FV2_ALT],
    'switch off: nothing is stored' => [false, null],
]);
