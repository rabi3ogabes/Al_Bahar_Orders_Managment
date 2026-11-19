<?php

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/../Operations/OpsSupport.php';

/*
| The staff queue pages (page / per_page / meta.next) without changing what an older app gets when it sends neither, can list the branch's
| pickups on their own (type=pickup), and the overview tells the app the branches of the person, the pickup counts and what they may do here.
*/

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->travelTo(qatar('2026-11-19 12:00'));
    $this->branch = opsBranch(attributes: ['code' => 'AAA']);
    $this->mgr = opsStaff('branch_manager', $this->branch);
    actingAsStaff($this->mgr);
});

/** @return array<int, string> the order numbers, oldest first */
function staffPendingOrders(int $n): array
{
    $numbers = [];
    for ($i = 0; $i < $n; $i++) {
        $numbers[] = opsOrder(test()->branch, [], ['status' => 'pending'], opsAgo(60 - $i))['number'];
    }

    return $numbers;
}

it('pages the queue and says where the next page is', function () {
    $numbers = staffPendingOrders(5);
    $url = '/api/v1/admin/dispatch/queue?stage=new&branch_id='.$this->branch->id;

    $first = $this->getJson($url.'&per_page=2&page=1')->assertOk();
    $second = $this->getJson($url.'&per_page=2&page=2')->assertOk();
    $last = $this->getJson($url.'&per_page=2&page=3')->assertOk();

    expect(array_column($first->json('data'), 'number'))->toBe([$numbers[0], $numbers[1]])
        ->and($first->json('meta'))->toBe(['page' => 1, 'per_page' => 2, 'has_more' => true, 'next' => 2])
        ->and(array_column($second->json('data'), 'number'))->toBe([$numbers[2], $numbers[3]])
        ->and($second->json('meta.next'))->toBe(3)
        ->and(array_column($last->json('data'), 'number'))->toBe([$numbers[4]])
        ->and($last->json('meta'))->toBe(['page' => 3, 'per_page' => 2, 'has_more' => false, 'next' => null]);
});

it('never repeats or skips a row when a page is exactly full', function () {
    staffPendingOrders(4);
    $url = '/api/v1/admin/dispatch/queue?stage=new&branch_id='.$this->branch->id.'&per_page=2';

    $second = $this->getJson($url.'&page=2')->assertOk();

    expect($second->json('data'))->toHaveCount(2)->and($second->json('meta.has_more'))->toBeFalse()->and($second->json('meta.next'))->toBeNull();
});

it('gives an older app that sends no paging the first 100 rows, as it always got', function () {
    foreach (range(1, 105) as $i) {
        opsOrder($this->branch, [], ['status' => 'pending'], opsAgo(200 - $i));
    }

    $legacy = $this->getJson('/api/v1/admin/dispatch/queue?stage=new&branch_id='.$this->branch->id)->assertOk();
    $default = $this->getJson('/api/v1/admin/dispatch/queue?stage=new&branch_id='.$this->branch->id.'&page=1')->assertOk();

    expect($legacy->json('data'))->toHaveCount(100)->and($legacy->json('meta.has_more'))->toBeTrue()
        ->and($default->json('data'))->toHaveCount(50)->and($default->json('meta.per_page'))->toBe(50)->and($default->json('meta.next'))->toBe(2);
});

it('refuses a page size above 100 or a page below 1', function () {
    $url = '/api/v1/admin/dispatch/queue?stage=new&branch_id='.$this->branch->id;

    $this->getJson($url.'&per_page=101')->assertStatus(422);
    $this->getJson($url.'&per_page=0')->assertStatus(422);
    $this->getJson($url.'&page=0')->assertStatus(422);
    $this->getJson($url.'&type=courier')->assertStatus(422);
});

it('lists pickups on their own with type=pickup and leaves them out otherwise', function () {
    $delivery = opsOrder($this->branch, [], ['status' => 'pending'], opsAgo(5));
    $pickup = opsOrder($this->branch, ['fulfillment_type' => 'pickup', 'payment_method' => 'pay_at_pickup'], ['status' => 'pending', 'type' => 'pickup'], opsAgo(9));
    $ready = opsOrder($this->branch, ['fulfillment_type' => 'pickup'], ['status' => 'pickup_ready', 'type' => 'pickup', 'ready_at' => opsUtc(opsAgo(3 * 1440))], opsAgo(4 * 1440));
    opsPickup($ready['part'], $this->branch, 'ready', opsAgo(60));
    $base = '/api/v1/admin/dispatch/queue?branch_id='.$this->branch->id;

    $plain = array_column($this->getJson($base)->assertOk()->json('data'), 'number');
    $only = collect($this->getJson($base.'&type=pickup')->assertOk()->json('data'))->keyBy('number');
    $mixed = array_column($this->getJson($base.'&include_pickup=1')->assertOk()->json('data'), 'number');
    $deliveries = array_column($this->getJson($base.'&type=delivery&include_pickup=1')->assertOk()->json('data'), 'number');

    expect($plain)->toBe([$delivery['number']])
        ->and($only->keys()->all())->toEqualCanonicalizing([$pickup['number'], $ready['number']])
        ->and($only[$pickup['number']]['type'])->toBe('pickup')->and($only[$pickup['number']]['payment_method'])->toBe('pay_at_pickup')
        ->and($only[$ready['number']]['pickup'])->toMatchArray(['status' => 'ready', 'expired' => true])
        ->and($only[$ready['number']]['clock'])->toMatchArray(['kind' => 'pickup', 'state' => 'amber'])
        ->and($mixed)->toHaveCount(3)->and($deliveries)->toBe([$delivery['number']]);
});

it('tells the app the pickup counts, the branches of the person and what they may do at this branch', function () {
    opsOrder($this->branch, ['fulfillment_type' => 'pickup'], ['status' => 'pending', 'type' => 'pickup'], opsAgo(2));
    opsOrder($this->branch, ['fulfillment_type' => 'pickup'], ['status' => 'preparing', 'type' => 'pickup'], opsAgo(2));
    opsOrder($this->branch, ['fulfillment_type' => 'pickup'], ['status' => 'pickup_ready', 'type' => 'pickup'], opsAgo(2));
    opsOrder($this->branch, [], ['status' => 'pending'], opsAgo(2));

    $data = $this->getJson('/api/v1/admin/dispatch/overview?branch_id='.$this->branch->id)->assertOk()->json('data');

    expect($data['new'])->toBe(1)->and($data['pickup'])->toBe(['new' => 1, 'preparing' => 1, 'ready' => 1])
        ->and($data['branches'])->toHaveCount(1)->and($data['branches'][0]['id'])->toBe($this->branch->id)
        ->and($data['can'])->toMatchArray(['orders.edit' => true, 'orders.approve' => true, 'pickup.approve' => true, 'pickup.edit' => true]);
});

it('lists every branch a manager of two works in, serves the one asked for, and refuses one they do not have', function () {
    $b = opsBranch(attributes: ['code' => 'BBB']);
    $other = opsBranch(attributes: ['code' => 'CCC']);
    $both = opsStaff('branch_manager', $this->branch);
    DB::table('branch_employees')->insert(['id' => (string) Str::uuid(), 'branch_id' => $b->id, 'user_id' => $both->id, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('user_roles')->insert(['id' => (string) Str::uuid(), 'user_id' => $both->id, 'role_id' => DB::table('roles')->where('key', 'branch_manager')->value('id'), 'branch_id' => $b->id, 'created_at' => now(), 'updated_at' => now()]);
    opsOrder($b, [], ['status' => 'pending'], opsAgo(4));
    actingAsStaff($both);

    $first = $this->getJson('/api/v1/admin/dispatch/overview')->assertOk()->json('data');
    $second = $this->getJson('/api/v1/admin/dispatch/overview?branch_id='.$b->id)->assertOk()->json('data');

    expect(array_column($first['branches'], 'id'))->toEqualCanonicalizing([$this->branch->id, $b->id])
        ->and($first['branch']['id'])->not->toBe($b->id)->and($first['new'])->toBe(0)
        ->and($second['branch']['id'])->toBe($b->id)->and($second['new'])->toBe(1);
    $this->getJson('/api/v1/admin/dispatch/overview?branch_id='.$other->id)->assertForbidden();
    $this->getJson('/api/v1/admin/dispatch/queue?branch_id='.$other->id)->assertForbidden();
});
