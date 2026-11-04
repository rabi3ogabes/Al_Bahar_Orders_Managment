<?php

use App\Domain\Branches\Models\Branch;
use App\Domain\Branches\Models\BranchEmployee;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Models\UserRole;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

pest()->extend(Tests\TestCase::class)->use(RefreshDatabase::class)->in('Feature', 'Unit');

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
    $zone = \App\Domain\Branches\Models\DeliveryZone::factory()->create(['zone_numbers' => '66']);
    $a = \App\Domain\Branches\Models\Branch::factory()->create(['code' => 'A'.random_int(100, 999), 'cod_max_order' => '20000.00']);
    $b = \App\Domain\Branches\Models\Branch::factory()->create(['code' => 'B'.random_int(100, 999)]);
    foreach ([[$a, 10, '15.00'], [$b, 20, '25.00']] as [$br, $prio, $fee]) {
        \App\Domain\Branches\Models\BranchDeliveryZone::create(['branch_id' => $br->id, 'delivery_zone_id' => $zone->id, 'delivery_fee' => $fee,
            'free_delivery_threshold' => '5000.00', 'min_order_amount' => '50.00', 'priority' => $prio]);
        app(\App\Domain\Branches\Services\BranchService::class)->setWeeklyHours($br, app(\App\Domain\Branches\Services\BranchService::class)->defaultHours(), audit: false);
    }
    $p1 = \App\Domain\Catalog\Models\Product::factory()->create(['price' => '1000.00']);
    $p2 = \App\Domain\Catalog\Models\Product::factory()->create(['price' => '250.00']);
    $v1 = $p1->variants()->first();
    $v2 = $p2->variants()->first();
    $ledger = app(\App\Domain\Inventory\Services\InventoryLedger::class);
    foreach ([[$a, $stockA], [$b, $stockB]] as [$br, $stock]) {
        foreach ([$v1, $v2] as $i => $v) {
            if ($stock[$i] > 0) {
                $ledger->apply($br->id, $v->id, \App\Domain\Inventory\Enums\MovementType::OpeningBalance, $stock[$i]);
            }
        }
    }
    $customer = \App\Domain\Identity\Models\User::factory()->create();
    $address = $customer->addresses()->create(['recipient_name' => 'Noor', 'recipient_phone' => '+97455123456', 'zone_number' => '66',
        'street_number' => '850', 'building_number' => '12', 'is_default' => true]);
    \Laravel\Sanctum\Sanctum::actingAs($customer, ['customer']);

    return (object) compact('zone', 'a', 'b', 'p1', 'p2', 'v1', 'v2', 'customer', 'address');
}

function stockOf(\App\Domain\Branches\Models\Branch $b, $variant): array
{
    $r = \App\Domain\Inventory\Models\BranchInventory::where('branch_id', $b->id)->where('product_variant_id', $variant->id)->first();

    return ['on_hand' => $r?->on_hand ?? 0, 'reserved' => $r?->reserved ?? 0];
}

/** Quote with options then place with the returned hash. */
function quoteAndPlace(array $options, ?string $key = null): \Illuminate\Testing\TestResponse
{
    $quote = test()->postJson('/api/v1/checkout/quote', $options)->assertOk()->json('data');

    return test()->postJson('/api/v1/checkout/orders', $options + ['quote_hash' => $quote['quote_hash']], ['Idempotency-Key' => $key ?? (string) \Illuminate\Support\Str::uuid()]);
}

/** An open scheduled auction lot (start 100, increment 10, reserve 300, ends in 10 min) with two registered bidders. */
function auctionWorld(array $lot = [], array $auction = []): object
{
    $admin = staffWithRole('auction_manager');
    $svc = app(\App\Domain\Auctions\Services\AuctionService::class);
    $a = $svc->save($admin, array_merge(['type' => 'scheduled', 'requires_registration' => true, 'translations' => ['en' => ['title' => 'Heritage Majlis'], 'ar' => ['title' => 'مجلس تراثي']]], $auction));
    $l = $svc->addLot($admin, $a, array_merge(['title_en' => 'Carved Cedar Chest', 'title_ar' => 'صندوق أرز منحوت', 'starting_price' => '100.00', 'min_increment' => '10.00',
        'reserve_price' => '300.00', 'starts_at' => now()->subMinute(), 'ends_at' => now()->addMinutes(10)], $lot));
    $svc->publish($admin, $a);
    app(\App\Domain\Auctions\Services\LotService::class)->open($l);
    $u1 = \App\Domain\Identity\Models\User::factory()->create();
    $u2 = \App\Domain\Identity\Models\User::factory()->create();
    $svc->register($u1, $a->fresh());
    $svc->register($u2, $a->fresh());

    return (object) ['auction' => $a->fresh(), 'lot' => $l->fresh(), 'u1' => $u1, 'u2' => $u2, 'admin' => $admin];
}

function bid(object $w, \App\Domain\Identity\Models\User $u, string $amount, ?string $key = null): array
{
    return app(\App\Domain\Auctions\Services\BidEngine::class)->placeBid($u, $w->lot->id, \App\Support\Money::of($amount), $key ?? (string) \Illuminate\Support\Str::uuid());
}
