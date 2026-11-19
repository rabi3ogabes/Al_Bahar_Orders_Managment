<?php

use Database\Seeders\RolesAndPermissionsSeeder;

require_once __DIR__.'/../Operations/OpsSupport.php';

/*
| The branch staff app's queue: every row carries its order clock, its type, when it was placed and the customer's open request to cancel,
| the oldest wait comes first, and the old keys are still there (docs/api/STAFF_QUEUE.md).
*/

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->travelTo(qatar('2026-11-19 12:00')); // Thursday noon: the branch is open
    $this->branch = opsBranch(attributes: ['code' => 'AAA']);
    $this->mgr = opsStaff('branch_manager', $this->branch);
    actingAsStaff($this->mgr);
});

/** The rows of one stage of the branch's queue. @return array<int, array<string, mixed>> */
function staffQueue(string $stage = 'all', string $extra = ''): array
{
    return test()->getJson('/api/v1/admin/dispatch/queue?stage='.$stage.'&branch_id='.test()->branch->id.$extra)->assertOk()->json('data');
}

it('puts the order clock on every row: green, amber, red, with the age in minutes and a plain label', function () {
    $red = opsOrder($this->branch, [], ['status' => 'pending'], opsAgo(20));
    $amber = opsOrder($this->branch, [], ['status' => 'pending'], opsAgo(7));
    $ok = opsOrder($this->branch, [], ['status' => 'pending'], opsAgo(2));

    $rows = collect(staffQueue('new'))->keyBy('number');

    expect($rows[$red['number']]['clock'])->toMatchArray(['state' => 'red', 'age_minutes' => 20, 'kind' => 'pending', 'paused' => false])
        ->and($rows[$red['number']]['clock']['label'])->toBe('20 min')
        ->and($rows[$amber['number']]['clock'])->toMatchArray(['state' => 'amber', 'age_minutes' => 7])
        ->and($rows[$ok['number']]['clock'])->toMatchArray(['state' => 'ok', 'age_minutes' => 2]);
});

it('keeps every key the older apps read and adds type, placed_at and the money left to collect', function () {
    $o = opsOrder($this->branch, ['payment_method' => 'pay_at_pickup', 'grand_total' => '120.00'], ['status' => 'pending'], opsAgo(3));

    $row = staffQueue('new')[0];

    expect($row)->toHaveKeys(['fulfillment_id', 'order_id', 'seq', 'status', 'number', 'customer_name', 'customer_phone', 'alternate_phone', 'address', 'items_count', 'items',
        'payment_method', 'grand_total', 'delivery', 'was_adjusted', 'can_adjust', 'scheduled_for'])
        ->and($row['number'])->toBe($o['number'])->and($row['type'])->toBe('delivery')->and($row['placed_at'])->not->toBeNull()
        ->and($row['cancellation_request'])->toBeNull()->and($row['payment_status'])->toBe('pending')->and($row['amount_due'])->toBe('120.00');
});

it('measures a part being prepared from when it was accepted, and a part a driver already has as "nobody has to act"', function () {
    $prep = opsOrder($this->branch, [], ['status' => 'preparing', 'accepted_at' => opsUtc(opsAgo(100))], opsAgo(130));
    $out = opsOrder($this->branch, [], ['status' => 'out_for_delivery', 'updated_at' => opsUtc(opsAgo(25))], opsAgo(200));
    opsDelivery($out['part'], $this->branch, 'out_for_delivery');

    $rows = collect(staffQueue())->keyBy('number');

    expect($rows[$prep['number']]['clock'])->toMatchArray(['state' => 'red', 'age_minutes' => 100, 'kind' => 'preparing'])
        ->and($rows[$out['number']]['clock'])->toMatchArray(['state' => 'ok', 'age_minutes' => 25, 'kind' => 'none']);
});

it('lists the longest wait first inside a stage', function () {
    $new = opsOrder($this->branch, [], ['status' => 'pending'], opsAgo(3));
    $old = opsOrder($this->branch, [], ['status' => 'pending'], opsAgo(14));
    $mid = opsOrder($this->branch, [], ['status' => 'pending'], opsAgo(8));

    expect(array_column(staffQueue('new'), 'number'))->toBe([$old['number'], $mid['number'], $new['number']]);

    // In "preparing" it is the time since acceptance that counts, not the time since the order was placed.
    $late = opsOrder($this->branch, [], ['status' => 'preparing', 'accepted_at' => opsUtc(opsAgo(60))], opsAgo(65));
    $early = opsOrder($this->branch, [], ['status' => 'preparing', 'accepted_at' => opsUtc(opsAgo(10))], opsAgo(300));
    expect(array_column(staffQueue('preparing'), 'number'))->toBe([$late['number'], $early['number']]);
});

it('shows the customer\'s open request to cancel, and its own wait, on the order it concerns', function () {
    $o = opsOrder($this->branch, [], ['status' => 'pending'], opsAgo(40));
    $request = opsCancellation($o['order'], opsAgo(20));
    $plain = opsOrder($this->branch, [], ['status' => 'pending'], opsAgo(1));

    $rows = collect(staffQueue('new'))->keyBy('number');
    $row = $rows[$o['number']];

    expect($row['cancellation_request'])->toMatchArray(['id' => $request, 'status' => 'requested', 'reason' => 'Changed my mind'])
        ->and($row['cancellation_request']['requested_at'])->not->toBeNull()
        ->and($row['clock'])->toMatchArray(['kind' => 'cancellation', 'state' => 'red', 'age_minutes' => 20])
        ->and($rows[$plain['number']]['cancellation_request'])->toBeNull();
});

it('forgets a request once a manager decided it', function () {
    $o = opsOrder($this->branch, [], ['status' => 'preparing', 'accepted_at' => opsUtc(opsAgo(5))], opsAgo(30));
    $request = opsCancellation($o['order'], opsAgo(20));
    expect(staffQueue('preparing')[0]['cancellation_request']['id'])->toBe($request);

    DB::table('order_cancellations')->where('id', $request)->update(['status' => 'rejected']);
    DB::table('orders')->where('id', $o['order'])->update(['status' => 'processing']);

    expect(staffQueue('preparing')[0]['cancellation_request'])->toBeNull();
});

it('counts only the minutes the branch was open, and says when the clock is paused', function () {
    // 07:00 on Friday: the branch (Friday 14:00-22:00) has been shut since last night; a part placed at 21:00 yesterday has waited 60 open minutes.
    $this->travelTo(qatar('2026-11-20 07:00'));
    $o = opsOrder($this->branch, [], ['status' => 'pending'], qatar('2026-11-19 21:00'));

    $row = collect(staffQueue('new'))->firstWhere('number', $o['number']);

    expect($row['clock']['age_minutes'])->toBe(60)->and($row['clock']['wall_minutes'])->toBe(600)->and($row['clock']['paused'])->toBeTrue()
        ->and($row['clock']['state'])->toBe('red');
});

it('leaves out an online order that is not paid yet, as before', function () {
    opsOrder($this->branch, ['status' => 'pending_payment', 'payment_method' => 'sadad'], ['status' => 'pending'], opsAgo(40));
    $paid = opsOrder($this->branch, ['payment_method' => 'sadad'], ['status' => 'pending'], opsAgo(2));

    expect(array_column(staffQueue('new'), 'number'))->toBe([$paid['number']]);
});
