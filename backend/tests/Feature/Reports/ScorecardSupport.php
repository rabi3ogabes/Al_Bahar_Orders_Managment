<?php

use App\Domain\Branches\Models\Branch;
use App\Domain\Branches\Models\BranchEmployee;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Models\UserRole;
use App\Domain\Reports\Services\ScorecardPeriod;
use App\Domain\Reports\Services\ScorecardService;
use App\Domain\Reports\Services\ScorecardSettings;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/../Operations/OpsSupport.php';

// Fixtures for the scorecard tests. Rows are written straight into the tables with concrete moments, so every expected figure can be checked by hand.
// "Today" is Thursday 19 Nov 2026 at midday in Qatar; the period used by most tests is the week of 12 to 18 Nov, the one before it 5 to 11 Nov.

/** Two branches without opening hours (so minutes are plain clock minutes), an owner, a customer, the roles, and a minimum sample of 1. */
function scWorld(int $minSample = 1): object
{
    test()->travelTo(CarbonImmutable::parse('2026-11-19 12:00:00', 'Asia/Qatar'));
    test()->seed(RolesAndPermissionsSeeder::class);
    $world = (object) [
        'a' => opsBranch([], ['code' => 'DOH', 'name_en' => 'Doha', 'name_ar' => 'الدوحة']),
        'b' => opsBranch([], ['code' => 'WKR', 'name_en' => 'Wakra', 'name_ar' => 'الوكرة']),
        'admin' => staffWithRole('admin'),
        'svc' => app(ScorecardService::class),
    ];
    app(ScorecardSettings::class)->setMinSample($minSample);

    return $world;
}

/** The week of 12 to 18 Nov (Qatar days): the period most tests look at. */
function scWeek(): ScorecardPeriod
{
    return ScorecardPeriod::between('2026-11-12', '2026-11-18');
}

/** A Qatar local moment as the UTC string the tables hold. */
function scAt(string $local): string
{
    return CarbonImmutable::parse($local, 'Asia/Qatar')->utc()->format('Y-m-d H:i:s');
}

/**
 * An order with one part at the branch, placed at a Qatar moment, accepted $accept minutes later and ready $prepare minutes after that (null: not yet).
 * $part and $order override columns of the part and of the order.
 *
 * @param  array<string, mixed>  $part
 * @param  array<string, mixed>  $order
 * @return array{order: string, part: string, number: string}
 */
function scOrder(Branch $branch, string $placed, ?int $accept = null, ?int $prepare = null, array $part = [], array $order = []): array
{
    $at = CarbonImmutable::parse($placed, 'Asia/Qatar');
    $times = ['created_at' => scAt($placed)];
    if ($accept !== null) {
        $times['accepted_at'] = $at->addMinutes($accept)->utc()->format('Y-m-d H:i:s');
        $times['status'] = 'accepted';
        if ($prepare !== null) {
            $times['ready_at'] = $at->addMinutes($accept + $prepare)->utc()->format('Y-m-d H:i:s');
            $times['status'] = 'ready';
        }
    }

    return opsOrder($branch, $order + ['confirmed_at' => scAt($placed)], $part + $times, $at);
}

/** A delivery part that reached the customer at a Qatar moment, promised for a day (or none). Returns the order row like scOrder. */
function scDelivered(Branch $branch, string $placed, string $deliveredAt, ?string $slotDate, array $part = [], array $order = []): array
{
    return scOrder($branch, $placed, 5, 10, ['status' => 'delivered', 'type' => 'delivery', 'delivered_at' => scAt($deliveredAt), 'slot_date' => $slotDate] + $part, $order);
}

/** A staff member who is a delivery driver of the branch(es), with the role a driver holds (and so no right to see reports). */
function scDriver(array|Branch $branches, string $name = 'Omar Driver'): User
{
    $driver = staffWithRole('delivery_employee', null, ['name' => $name]);
    foreach (is_array($branches) ? $branches : [$branches] as $b) {
        BranchEmployee::create(['branch_id' => $b->id, 'user_id' => $driver->id, 'is_active' => true, 'is_delivery_driver' => true]);
        UserRole::create(['user_id' => $driver->id, 'role_id' => Role::where('key', 'delivery_employee')->value('id'), 'branch_id' => $b->id]);
    }

    return $driver;
}

/** A delivery of a part given to a driver, with its timestamps (Qatar moments) and anything else to override. @return string the delivery id */
function scTrip(array $order, Branch $branch, User $driver, string $status, array $attributes = []): string
{
    foreach (['delivered_at', 'out_for_delivery_at', 'failed_at', 'assigned_at'] as $col) {
        if (isset($attributes[$col])) {
            $attributes[$col] = scAt($attributes[$col]);
        }
    }

    return opsDelivery($order['part'], $branch, $status, $attributes + ['driver_id' => $driver->id]);
}

/** A hand-in a cashier counted: the collector gave the cashier the cash, the counted amount differed from the expected one by $variance. */
function scHandIn(Branch $branch, User $collector, string $at, string $variance = '0.00', ?User $receiver = null): string
{
    static $n = 0;
    $n++;
    $receiver ??= staffWithRole('branch_manager', $branch);
    $id = (string) Str::uuid();
    $expected = '100.00';
    $counted = number_format(100 + (float) $variance, 2, '.', '');
    DB::table('cash_handovers')->insert(['id' => $id, 'number' => 'CH'.str_pad((string) $n, 6, '0', STR_PAD_LEFT), 'branch_id' => $branch->id, 'collector_id' => $collector->id, 'received_by' => $receiver->id,
        'collections_count' => 1, 'expected_amount' => $expected, 'counted_amount' => $counted, 'variance' => $variance, 'status' => (float) $variance === 0.0 ? 'balanced' : ((float) $variance < 0 ? 'short' : 'over'),
        'variance_status' => (float) $variance === 0.0 ? null : 'open', 'created_at' => scAt($at), 'updated_at' => scAt($at)]);

    return $id;
}

/** Cash a driver took on delivery and still holds (or handed in), collected at a Qatar moment. */
function scCash(array $order, Branch $branch, User $collector, string $amount, string $collectedAt, bool $handedIn = false): string
{
    $paymentId = (string) Str::uuid();
    DB::table('payments')->insert(['id' => $paymentId, 'payable_type' => 'order', 'payable_id' => $order['order'], 'order_id' => $order['order'], 'method' => 'cod', 'provider' => 'cod', 'status' => 'paid',
        'amount' => $amount, 'captured_amount' => $amount, 'paid_at' => scAt($collectedAt), 'created_at' => scAt($collectedAt), 'updated_at' => scAt($collectedAt)]);
    $id = (string) Str::uuid();
    DB::table('cash_collections')->insert(['id' => $id, 'payment_id' => $paymentId, 'order_fulfillment_id' => $order['part'], 'branch_id' => $branch->id, 'collected_by' => $collector->id, 'context' => 'cod',
        'tender' => 'cash', 'amount_due' => $amount, 'amount_collected' => $amount, 'status' => 'collected', 'collected_at' => scAt($collectedAt),
        'handed_in_at' => $handedIn ? scAt($collectedAt) : null, 'created_at' => scAt($collectedAt), 'updated_at' => scAt($collectedAt)]);

    return $id;
}

/** A part cancelled at a Qatar moment, with the reason written in its history. */
function scCancelled(Branch $branch, string $placed, string $cancelledAt, string $reason, array $order = []): array
{
    $o = scOrder($branch, $placed, null, null, ['status' => 'cancelled', 'cancelled_at' => scAt($cancelledAt)], ['status' => 'cancelled'] + $order);
    DB::table('order_status_history')->insert(['order_id' => $o['order'], 'order_fulfillment_id' => $o['part'], 'kind' => 'fulfillment', 'from_status' => 'pending', 'to_status' => 'cancelled',
        'reason' => $reason, 'created_at' => scAt($cancelledAt)]);

    return $o;
}

/** A delivery that failed at a Qatar moment for a reason, at the branch. */
function scFailed(Branch $branch, string $placed, string $failedAt, string $reason, ?User $driver = null): array
{
    $o = scOrder($branch, $placed, 5, 10, ['status' => 'failed', 'type' => 'delivery']);
    opsDelivery($o['part'], $branch, 'failed', ['failed_at' => scAt($failedAt), 'failure_reason' => $reason, 'driver_id' => $driver?->id]);

    return $o;
}

/** The viewer's whole scorecard of branches, as the service gives it. */
function scBranches(object $w, ?User $viewer = null, ?ScorecardPeriod $period = null, ?string $branchId = null): array
{
    Cache::flush(); // a test changes the rows between two looks: the short cache of the service must not hide it

    return $w->svc->branches($viewer ?? $w->admin, $period ?? scWeek(), $branchId);
}

/** One branch's line of the Branches tab. @return array<string, mixed> */
function scBranch(object $w, Branch $branch, ?ScorecardPeriod $period = null): array
{
    return collect(scBranches($w, null, $period)['rows'])->firstWhere('id', $branch->id);
}

function scDrivers(object $w, ?User $viewer = null, ?ScorecardPeriod $period = null, ?string $branchId = null): array
{
    Cache::flush();

    return $w->svc->drivers($viewer ?? $w->admin, $period ?? scWeek(), $branchId);
}

/** One driver's line of the Drivers tab. @return array<string, mixed>|null */
function scDriverRow(object $w, User $driver, ?ScorecardPeriod $period = null, ?User $viewer = null): ?array
{
    return collect(scDrivers($w, $viewer, $period)['rows'])->firstWhere('id', $driver->id);
}
