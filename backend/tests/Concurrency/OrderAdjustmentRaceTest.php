<?php

/*
 * Real concurrency: forked processes, each with its own PostgreSQL connection, adjust the same order at the same moment.
 * Uses committed data (DatabaseTruncation), so it is excluded from the transactional Feature suite.
 * Run: ./vendor/bin/pest tests/Concurrency/OrderAdjustmentRaceTest.php
 */

require_once __DIR__.'/../Feature/Orders/AdjustmentFixtures.php';

use App\Domain\Orders\Models\OrderRevision;
use App\Domain\Orders\Services\OrderAdjustmentService;
use App\Domain\Orders\Services\OrderService;
use App\Domain\Payments\Models\Refund;
use App\Support\DomainException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, DatabaseTruncation::class);

/** Fork $n children running $work($i); each returns an exit code: 0 done, 2 refused with a DomainException, 1 anything else. */
function raceAdjust(int $n, Closure $work): array
{
    DB::disconnect();
    $pids = [];
    for ($i = 0; $i < $n; $i++) {
        $pid = pcntl_fork();
        if ($pid === 0) {
            DB::purge();
            try {
                $work($i);
                $code = 0;
            } catch (DomainException) {
                $code = 2;
            } catch (Throwable $e) {
                fwrite(STDERR, get_class($e).': '.$e->getMessage()."\n");
                $code = 1;
            }
            exit($code); // child
        }
        $pids[] = $pid;
    }
    $codes = [];
    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
        $codes[] = pcntl_wexitstatus($status);
    }
    DB::purge();

    return $codes;
}

it('applies one request once when it is sent many times at the same moment', function () {
    $a = adjustmentWorld(['payment' => 'sadad', 'coupon' => ['percentage', '10']]);
    $changes = [['order_item_id' => $a->l1->id, 'action' => 'reduce', 'new_quantity' => 1], ['order_item_id' => $a->l2->id, 'action' => 'remove']];

    $codes = raceAdjust(6, fn () => app(OrderAdjustmentService::class)->adjust($a->f->fresh(), $a->mgr, $changes, 'out_of_stock'));

    expect(array_count_values($codes))->toEqual([0 => 1, 2 => 5]) // one applied, five told "nothing to change"
        ->and(OrderRevision::where('order_id', $a->order->id)->count())->toBe(1)
        ->and($a->order->fresh()->adjustment_count)->toBe(1)
        ->and(Refund::where('order_id', $a->order->id)->count())->toBe(1)
        ->and(DB::table('notifications')->where('event', 'order.items_changed')->count())->toBe(1)
        ->and(stockOf($a->w->a, $a->w->v1))->toBe(['on_hand' => 9, 'reserved' => 1]) // released and corrected once
        ->and(stockOf($a->w->a, $a->w->v2))->toBe(['on_hand' => 9, 'reserved' => 0])
        ->and($a->l1->fresh()->quantity)->toBe(1)->and($a->l2->fresh()->quantity)->toBe(0);
})->skip(! function_exists('pcntl_fork'), 'pcntl not available');

it('lets two different adjustments of the same order queue up, each seeing the other\'s result', function () {
    $a = adjustmentWorld(['qty' => [4, 2]]);

    $codes = raceAdjust(2, fn (int $i) => app(OrderAdjustmentService::class)->adjust($a->f->fresh(), $a->mgr,
        [$i === 0 ? ['order_item_id' => $a->l1->id, 'action' => 'reduce', 'new_quantity' => 2] : ['order_item_id' => $a->l2->id, 'action' => 'reduce', 'new_quantity' => 1]], 'damaged'));

    $order = $a->order->fresh();
    expect($codes)->toBe([0, 0])->and(OrderRevision::where('order_id', $order->id)->orderBy('number')->pluck('number')->all())->toBe([1, 2])
        ->and($order->subtotal)->toBe('2250.00')->and($order->grand_total)->toBe('2275.00') // 2 × 1000 + 250 + 15 + 10: both changes are in the total, neither was lost
        ->and($a->l1->fresh()->quantity)->toBe(2)->and($a->l2->fresh()->quantity)->toBe(1);
})->skip(! function_exists('pcntl_fork'), 'pcntl not available');

it('never double-releases stock when the customer cancels while the branch adjusts', function (string $status) {
    $a = adjustmentWorld(['qty' => [2, 0], 'status' => $status]); // accepted: the cancellation is approved on the spot and takes the same rows in the other order
    $customer = $a->customer;

    raceAdjust(2, function (int $i) use ($a, $customer) {
        if ($i === 0) {
            app(OrderAdjustmentService::class)->adjust($a->f->fresh(), $a->mgr, [['order_item_id' => $a->l1->id, 'action' => 'reduce', 'new_quantity' => 1]], 'damaged');
        } else {
            app(OrderService::class)->requestCancellation($a->order->fresh(), $customer, 'changed_mind');
        }
    });

    // whichever came first (the adjustment is refused once a cancellation was asked for), the books balance: what is reserved is what is still wanted, nothing is released twice
    $row = stockOf($a->w->a, $a->w->v1);
    $status = $a->f->fresh()->status->value;
    $wanted = $status === 'cancelled' ? 0 : $a->l1->fresh()->quantity;
    expect($row['on_hand'])->toBe(10)->and($row['reserved'])->toBe($wanted)->and(DB::table('stock_movements')->where('type', 'reservation_release')->sum('reserved_delta'))->toBe($wanted - 2);
})->with(['accepted', 'preparing'])->skip(! function_exists('pcntl_fork'), 'pcntl not available');
