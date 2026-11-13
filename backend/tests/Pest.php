<?php

use App\Domain\Auctions\Services\AuctionService;
use App\Domain\Auctions\Services\BidEngine;
use App\Domain\Auctions\Services\LotService;
use App\Domain\Branches\Models\Branch;
use App\Domain\Branches\Models\BranchDeliveryZone;
use App\Domain\Branches\Models\BranchEmployee;
use App\Domain\Branches\Models\DeliveryZone;
use App\Domain\Branches\Services\BranchService;
use App\Domain\Fulfillment\Models\Delivery;
use App\Domain\Catalog\Models\Product;
use App\Domain\Fulfillment\Models\DriverProfile;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Models\UserRole;
use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Models\BranchInventory;
use App\Domain\Inventory\Services\InventoryLedger;
use App\Domain\Orders\Models\Order;
use App\Support\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature', 'Unit');

/** Create an active staff member holding $roleKey, scoped to $branch (or company-wide when null). */
function staffWithRole(string $roleKey, ?Branch $branch = null, array $attributes = []): User
{
    if (! Role::where('key', $roleKey)->exists()) {
        test()->seed(RolesAndPermissionsSeeder::class);
    }
    $user = User::factory()->staff()->create($attributes);
    $role = Role::where('key', $roleKey)->firstOrFail();
    if ($branch) {
        BranchEmployee::create(['branch_id' => $branch->id, 'user_id' => $user->id, 'is_active' => true]);
    }
    UserRole::create(['user_id' => $user->id, 'role_id' => $role->id, 'branch_id' => $branch?->id]);

    return $user;
}

function actingAsStaff(User $user): User
{
    Sanctum::actingAs($user, ['staff']);

    return $user;
}

function actingAsCustomer(?User $user = null): User
{
    $user ??= User::factory()->create();
    Sanctum::actingAs($user, ['customer']);

    return $user;
}

/**
 * Commerce fixture: zone Z served by branches A (priority 10, fee 15) and B (priority 20, fee 25),
 * two products with stock, and a customer with an address in Z.
 */
function commerceWorld(array $stockA = [5, 5], array $stockB = [5, 5]): object
{
    $zone = DeliveryZone::factory()->create(['zone_numbers' => '66']);
    $a = Branch::factory()->create(['code' => 'A'.random_int(100, 999), 'cod_max_order' => '20000.00']);
    $b = Branch::factory()->create(['code' => 'B'.random_int(100, 999)]);
    foreach ([[$a, 10, '15.00'], [$b, 20, '25.00']] as [$br, $prio, $fee]) {
        BranchDeliveryZone::create(['branch_id' => $br->id, 'delivery_zone_id' => $zone->id, 'delivery_fee' => $fee,
            'free_delivery_threshold' => '5000.00', 'min_order_amount' => '50.00', 'priority' => $prio]);
        app(BranchService::class)->setWeeklyHours($br, app(BranchService::class)->defaultHours(), audit: false);
    }
    $p1 = Product::factory()->create(['price' => '1000.00']);
    $p2 = Product::factory()->create(['price' => '250.00']);
    $v1 = $p1->variants()->first();
    $v2 = $p2->variants()->first();
    $ledger = app(InventoryLedger::class);
    foreach ([[$a, $stockA], [$b, $stockB]] as [$br, $stock]) {
        foreach ([$v1, $v2] as $i => $v) {
            if ($stock[$i] > 0) {
                $ledger->apply($br->id, $v->id, MovementType::OpeningBalance, $stock[$i]);
            }
        }
    }
    $customer = User::factory()->create();
    $address = $customer->addresses()->create(['recipient_name' => 'Noor', 'recipient_phone' => '+97455123456', 'zone_number' => '66',
        'street_number' => '850', 'building_number' => '12', 'is_default' => true]);
    Sanctum::actingAs($customer, ['customer']);

    return (object) compact('zone', 'a', 'b', 'p1', 'p2', 'v1', 'v2', 'customer', 'address');
}

function stockOf(Branch $b, $variant): array
{
    $r = BranchInventory::where('branch_id', $b->id)->where('product_variant_id', $variant->id)->first();

    return ['on_hand' => $r?->on_hand ?? 0, 'reserved' => $r?->reserved ?? 0];
}

/** Quote with options then place with the returned hash. */
function quoteAndPlace(array $options, ?string $key = null): TestResponse
{
    $quote = test()->postJson('/api/v1/checkout/quote', $options)->assertOk()->json('data');

    return test()->postJson('/api/v1/checkout/orders', $options + ['quote_hash' => $quote['quote_hash']], ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
}

/** An open scheduled auction lot (start 100, increment 10, reserve 300, ends in 10 min) with two registered bidders. */
function auctionWorld(array $lot = [], array $auction = []): object
{
    $admin = staffWithRole('auction_manager');
    $svc = app(AuctionService::class);
    $a = $svc->save($admin, array_merge(['type' => 'scheduled', 'requires_registration' => true, 'translations' => ['en' => ['title' => 'Heritage Majlis'], 'ar' => ['title' => 'مجلس تراثي']]], $auction));
    $l = $svc->addLot($admin, $a, array_merge(['title_en' => 'Carved Cedar Chest', 'title_ar' => 'صندوق أرز منحوت', 'starting_price' => '100.00', 'min_increment' => '10.00',
        'reserve_price' => '300.00', 'starts_at' => now()->subMinute(), 'ends_at' => now()->addMinutes(10)], $lot));
    $svc->publish($admin, $a);
    app(LotService::class)->open($l);
    $u1 = User::factory()->create();
    $u2 = User::factory()->create();
    $svc->register($u1, $a->fresh());
    $svc->register($u2, $a->fresh());

    return (object) ['auction' => $a->fresh(), 'lot' => $l->fresh(), 'u1' => $u1, 'u2' => $u2, 'admin' => $admin];
}

function bid(object $w, User $u, string $amount, ?string $key = null): array
{
    return app(BidEngine::class)->placeBid($u, $w->lot->id, Money::of($amount), $key ?? (string) Str::uuid());
}

/** A delivery driver of the given branch(es), on or off duty. */
function driverFor($branches, bool $onDuty = true)
{
    $driver = staffWithRole('delivery_employee', null);
    foreach (Arr::wrap($branches) as $b) {
        BranchEmployee::create(['branch_id' => $b->id, 'user_id' => $driver->id, 'is_active' => true, 'is_delivery_driver' => true]);
        UserRole::create(['user_id' => $driver->id, 'role_id' => Role::where('key', 'delivery_employee')->value('id'), 'branch_id' => $b->id]);
    }
    DriverProfile::create(['user_id' => $driver->id, 'on_duty' => $onDuty]);

    return $driver;
}

/** Places a COD order and walks its fulfillment to ready as a branch manager; returns [fulfillment, manager]. */
function readyOrder($w): array
{
    test()->postJson('/api/v1/cart/items', ['variant_id' => $w->v1->id, 'quantity' => 1]);
    $orderId = quoteAndPlace(['address_id' => $w->address->id, 'payment_method' => 'cod'])->json('data.order.id');
    $f = Order::with('fulfillments')->find($orderId)->fulfillments->first();
    $mgr = actingAsStaff(staffWithRole('order_manager', $w->a));
    foreach (['accepted', 'preparing', 'ready'] as $s) {
        test()->postJson("/api/v1/admin/fulfillments/{$f->id}/transition", ['status' => $s])->assertOk();
    }

    return [$f->fresh(), $mgr];
}

/**
 * A delivery of branch A walked to $status (assigned | accepted | picked_up | out_for_delivery), with the order's destination
 * set to $pin. Returns the world, the driver, the branch manager, the delivery and the order id; the session is left as the driver.
 */
function deliveryAt(string $status = 'out_for_delivery', array $pin = ['latitude' => 25.30, 'longitude' => 51.50]): object
{
    $w = commerceWorld();
    $driver = driverFor($w->a);
    [$f, $mgr] = readyOrder($w);
    test()->postJson("/api/v1/admin/dispatch/fulfillments/{$f->id}/assign", ['driver_id' => $driver->id])->assertOk();
    $w->address->update($pin);
    Order::whereKey($f->order_id)->update(['address_snapshot' => json_encode($w->address->fresh()->snapshot())]);
    $delivery = Delivery::where('order_fulfillment_id', $f->id)->firstOrFail();
    actingAsStaff($driver);
    foreach (['assigned' => [], 'accepted' => ['accept'], 'picked_up' => ['accept', 'pickup'], 'out_for_delivery' => ['accept', 'pickup', 'out-for-delivery']][$status] as $step) {
        test()->postJson("/api/v1/admin/deliveries/{$delivery->id}/{$step}")->assertOk();
    }

    return (object) ['w' => $w, 'driver' => $driver, 'mgr' => $mgr, 'delivery' => $delivery->fresh(), 'orderId' => $f->order_id, 'customer' => $w->customer];
}
