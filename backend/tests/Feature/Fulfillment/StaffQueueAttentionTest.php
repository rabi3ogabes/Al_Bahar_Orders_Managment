<?php

use Database\Seeders\RolesAndPermissionsSeeder;

require_once __DIR__.'/../Operations/OpsSupport.php';

/*
| GET /api/v1/admin/dispatch/attention: the top of the dashboard's "Needs attention" inbox for the signed-in person, same scoping and
| permissions (it is the same service), with where the app should take them for each line.
*/

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->travelTo(qatar('2026-11-19 12:00'));
    $this->a = opsBranch(attributes: ['code' => 'AAA']);
    $this->b = opsBranch(attributes: ['code' => 'BBB']);
    $this->mgr = opsStaff('branch_manager', $this->a);
    actingAsStaff($this->mgr);
});

function staffAttention(string $query = ''): array
{
    return test()->getJson('/api/v1/admin/dispatch/attention'.$query)->assertOk()->json('data');
}

it('lists what waits for the person, worst first, with counts per kind and where each line leads', function () {
    $new = opsOrder($this->a, [], ['status' => 'pending'], opsAgo(20));
    $ready = opsOrder($this->a, [], ['status' => 'ready', 'ready_at' => opsUtc(opsAgo(40))], opsAgo(90));
    $cancel = opsOrder($this->a, [], ['status' => 'preparing', 'accepted_at' => opsUtc(opsAgo(5))], opsAgo(60));
    opsCancellation($cancel['order'], opsAgo(8));
    $pass = opsOrder($this->a, ['fulfillment_type' => 'pickup'], ['status' => 'pickup_ready', 'type' => 'pickup', 'ready_at' => opsUtc(opsAgo(3 * 1440))], opsAgo(4 * 1440));
    opsPickup($pass['part'], $this->a, 'ready', opsAgo(60));
    $refund = opsOrder($this->a, [], ['status' => 'delivered'], opsAgo(3000));
    opsRefund($refund['order'], opsAgo(25 * 60));

    $data = staffAttention();
    $byKind = collect($data['items'])->keyBy('kind');

    expect($data['counts']['total'])->toBe(5)->and($data['counts']['by_kind'])->toMatchArray(['unaccepted' => 1, 'undispatched' => 1, 'cancellation' => 1, 'pickup_waiting' => 1, 'refund' => 1])
        ->and($data['counts']['red'])->toBeGreaterThanOrEqual(1)->and($data['items'][0]['state'])->toBe('red')
        ->and($byKind['unaccepted'])->toMatchArray(['subject' => $new['number'], 'target' => 'open_order', 'in_app' => true, 'fulfillment_id' => $new['part'], 'state' => 'red', 'age_minutes' => 20])
        ->and($byKind['undispatched'])->toMatchArray(['target' => 'assign_driver', 'fulfillment_id' => $ready['part'], 'branch_id' => $this->a->id])
        ->and($byKind['cancellation'])->toMatchArray(['target' => 'open_order', 'order_id' => $cancel['order'], 'fulfillment_id' => null])
        ->and($byKind['pickup_waiting'])->toMatchArray(['target' => 'reissue_pass', 'fulfillment_id' => $pass['part']])
        ->and($byKind['refund'])->toMatchArray(['target' => 'dashboard', 'in_app' => false])->and($byKind['refund']['hint'])->not->toBeNull()
        ->and($data['truncated'])->toBeFalse();
});

it('tells a manager exactly where to receive cash a driver never handed in', function () {
    $driver = opsStaff('delivery_employee', $this->a);
    $sale = opsOrder($this->a, [], ['status' => 'delivered'], opsAgo(3000));
    opsCash($this->a, $driver, $sale['order'], opsAgo(30 * 60));

    $cash = collect(staffAttention()['items'])->firstWhere('kind', 'cash');

    expect($cash)->toMatchArray(['target' => 'dashboard', 'in_app' => false, 'subject' => $driver->name])
        ->and($cash['hint'])->toBe(__('staff_app_api.attention.cash_on_dashboard'))
        ->and($cash['hint'])->not->toBe(__('staff_app_api.attention.dashboard_only'));
});

it('sends at most 20 lines but counts them all', function () {
    foreach (range(1, 25) as $i) {
        opsOrder($this->a, [], ['status' => 'pending'], opsAgo(2 + $i));
    }

    $data = staffAttention();

    expect($data['items'])->toHaveCount(20)->and($data['counts']['total'])->toBe(25)->and($data['counts']['by_kind']['unaccepted'])->toBe(25);
});

it('shows a manager of one branch nothing of another branch', function () {
    opsOrder($this->b, [], ['status' => 'pending'], opsAgo(30));
    $mine = opsOrder($this->a, [], ['status' => 'pending'], opsAgo(30));

    $data = staffAttention();

    expect(array_column($data['items'], 'subject'))->toBe([$mine['number']]);
    // asking for the other branch by id changes nothing: the answer is empty, never "everything"
    $foreign = staffAttention('?branch_id='.$this->b->id);
    expect($foreign['items'])->toBe([])->and($foreign['counts']['total'])->toBe(0);
});

it('narrows to one branch for a manager of several', function () {
    opsOrder($this->a, [], ['status' => 'pending'], opsAgo(30));
    $inB = opsOrder($this->b, [], ['status' => 'pending'], opsAgo(30));
    $both = opsStaff('admin');
    actingAsStaff($both);

    $all = staffAttention();
    $onlyB = staffAttention('?branch_id='.$this->b->id);

    expect($all['counts']['total'])->toBe(2)->and(array_column($onlyB['items'], 'subject'))->toBe([$inB['number']])->and($onlyB['branch_id'])->toBe($this->b->id);
});

it('is calm when nothing waits, and keeps the same permissions as the dashboard (an account with orders only never sees pickups)', function () {
    $data = staffAttention();
    expect($data['items'])->toBe([])->and($data['counts'])->toMatchArray(['total' => 0, 'red' => 0, 'amber' => 0, 'ok' => 0]);

    opsOrder($this->a, [], ['status' => 'pending'], opsAgo(30));
    $pass = opsOrder($this->a, ['fulfillment_type' => 'pickup'], ['status' => 'pickup_ready', 'type' => 'pickup', 'ready_at' => opsUtc(opsAgo(3 * 1440))], opsAgo(4 * 1440));
    opsPickup($pass['part'], $this->a, 'ready', opsAgo(60));
    actingAsStaff(opsStaffWith($this->a, ['orders.view']));
    expect(array_column(staffAttention()['items'], 'kind'))->toBe(['unaccepted']);
    actingAsStaff(opsStaffWith($this->a, ['orders.view', 'pickup.view']));
    expect(array_column(staffAttention()['items'], 'kind'))->toBe(['unaccepted', 'pickup_waiting']);
});

it('refuses a customer and a staff member without access to orders', function () {
    actingAsCustomer();
    $this->getJson('/api/v1/admin/dispatch/attention')->assertStatus(403);
    actingAsStaff(opsStaffWith($this->a, ['inventory.view']));
    $this->getJson('/api/v1/admin/dispatch/attention')->assertForbidden();
});
