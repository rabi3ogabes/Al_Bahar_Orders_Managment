<?php

use App\Domain\Branches\Models\Branch;
use App\Domain\Identity\Support\BranchScope;
use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Services\InventoryLedger;
use App\Domain\Orders\Models\Order;
use App\Domain\Payments\Models\CashHandover;
use App\Domain\Reports\Services\DailyCloseNumbers;
use App\Domain\Reports\Services\SalesBasis;
use App\Support\BusinessTime;

require_once __DIR__.'/OwnerNumbersSupport.php';

beforeEach(function () {
    $this->world = onWorld();                // today is Thursday 19 Nov 2026, midday in Qatar
    $this->day = '2026-11-19';
    $this->numbers = fn (?Branch $b = null, ?string $day = null) => app(DailyCloseNumbers::class)->for(($b ?? $this->world->a)->id, $day ?? $this->day);
});

describe('what a day holds', function () {
    it('is all zeros for a branch that had nothing, and says no average rather than inventing one', function () {
        $n = ($this->numbers)();

        expect($n['orders'])->toBe(['placed' => 0, 'delivered' => 0, 'cancelled' => 0, 'returned' => 0])
            ->and($n['sales'])->toBe(['total' => '0.00', 'orders' => 0, 'average' => '0.00'])
            ->and($n['payment_mix']['cod'])->toBe(['orders' => 0, 'amount' => '0.00'])
            ->and($n['cash']['collected'])->toBe('0.00')->and($n['refunds'])->toBe(['count' => 0, 'amount' => '0.00'])
            ->and($n['speed'])->toBe(['accept_minutes' => null, 'accepted' => 0, 'prepare_minutes' => null, 'prepared' => 0])
            ->and($n['open'])->toBe(['open' => 0, 'never_accepted' => 0])->and($n['failed_deliveries'])->toBe(0);
    });

    it('counts sales exactly like the dashboard, by payment method, and leaves the other branch and other days out', function () {
        $w = $this->world;
        onOrder([[$w->a, '100.00', '10.00']]);                                                                      // cash on delivery, 110.00
        onOrder([[$w->a, '50.00']], ['payment_method' => 'sadad', 'payment_status' => 'paid']);                   // online, 50.00
        onOrder([[$w->a, '30.00']], ['payment_method' => 'pay_at_pickup']);                                       // pay at pickup, 30.00
        onOrder([[$w->b, '999.00']]);                                                                               // another branch
        onOrder([[$w->a, '77.00']], ['placed_at' => now()->subDay()]);                                              // yesterday
        onOrder([[$w->a, '500.00']], ['status' => 'cancelled', 'confirmed_at' => now()]);                           // cancelled: not a sale
        onOrder([[$w->a, '400.00']], ['status' => 'pending_payment', 'payment_method' => 'sadad']);                // waiting for payment: not an order

        $n = ($this->numbers)();
        $dashboard = SalesBasis::totals(BranchScope::of([$w->a->id]), ...BusinessTimeRange());

        expect($n['sales']['total'])->toBe('190.00')->and($n['sales']['orders'])->toBe(3)->and($n['sales']['average'])->toBe('63.33')
            ->and($n['sales']['total'])->toBe($dashboard['total']->toDecimal())
            ->and($n['payment_mix'])->toBe(['cod' => ['orders' => 1, 'amount' => '110.00'], 'sadad' => ['orders' => 1, 'amount' => '50.00'], 'pay_at_pickup' => ['orders' => 1, 'amount' => '30.00']])
            ->and($n['orders']['placed'])->toBe(4);                // the three sales and the cancelled one; the unpaid online order never happened
    });

    it('gives a branch only its own share of a split order', function () {
        $w = $this->world;
        onOrder([[$w->a, '1000.00', '15.00'], [$w->b, '250.00', '25.00']]);

        expect(($this->numbers)($w->a)['sales']['total'])->toBe('1015.00')->and(($this->numbers)($w->b)['sales']['total'])->toBe('275.00');
    });

    it('counts what happened that day: delivered, collected and cancelled parts, and goods that came back', function () {
        $w = $this->world;
        $a = onOrder([[$w->a, '10.00']]);
        $b = onOrder([[$w->a, '10.00']]);
        $c = onOrder([[$w->a, '10.00']]);
        $old = onOrder([[$w->a, '10.00']], ['placed_at' => now()->subDays(3)]);
        $a->fulfillments->first()->update(['status' => 'delivered', 'delivered_at' => now()]);
        $b->fulfillments->first()->update(['status' => 'picked_up', 'picked_up_at' => now()]);
        $c->fulfillments->first()->update(['status' => 'cancelled', 'cancelled_at' => now()]);
        $old->fulfillments->first()->update(['status' => 'delivered', 'delivered_at' => now()->subDays(2)]);     // delivered another day
        DB::table('return_requests')->insert(['id' => (string) Str::uuid(), 'number' => 'RR1', 'order_id' => $a->id, 'user_id' => $w->customer->id, 'branch_id' => $w->a->id,
            'status' => 'received', 'reason_code' => 'damaged', 'items' => '[]', 'received_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        $n = ($this->numbers)();

        expect($n['orders'])->toBe(['placed' => 3, 'delivered' => 2, 'cancelled' => 1, 'returned' => 1]);
    });

    it('splits the cash: collected today, handed in, still with the people who took it, and the gap the cashier found', function () {
        $w = $this->world;
        $o1 = onOrder([[$w->a, '100.00']]);
        $o2 = onOrder([[$w->a, '60.00']]);
        $o3 = onOrder([[$w->a, '40.00']]);
        $cashier = staffWithRole('branch_manager', $w->a);
        onCashCollection($o1, '100.00');
        $handed = onCashCollection($o2, '60.00');
        onCashCollection($o3, '40.00', ['tender' => 'card_terminal']);                                              // a card is not cash
        onCashCollection(onOrder([[$w->a, '25.00']], ['placed_at' => now()->subDays(2)]), '25.00', ['collected_at' => now()->subDays(2)]); // an older balance
        $h = CashHandover::create(['number' => 'CH1', 'branch_id' => $w->a->id, 'collector_id' => $w->admin->id, 'received_by' => $cashier->id, 'collections_count' => 1,
            'expected_amount' => '60.00', 'counted_amount' => '55.00', 'variance' => '-5.00', 'status' => 'short', 'variance_status' => 'open']);
        $handed->update(['handed_in_at' => now(), 'handed_in_to' => $cashier->id, 'cash_handover_id' => $h->id]);

        $cash = ($this->numbers)()['cash'];

        expect($cash['collected'])->toBe('160.00')->and($cash['handed_in'])->toBe('60.00')->and($cash['with_collectors'])->toBe('100.00')
            ->and($cash['still_out_total'])->toBe('125.00')->and($cash['variance'])->toBe('-5.00')->and($cash['variance_count'])->toBe(1)->and($cash['open_variances'])->toBe(1);
    });

    it('shares a refund between the branches of its order like the sale itself, once per refund', function () {
        $w = $this->world;
        $order = onOrder([[$w->a, '300.00'], [$w->b, '100.00']]);
        onRefund($order, '40.00');                                                                                  // 40 of 400: A carries 3/4
        onRefund($order, '40.00');                                                                                  // an equal second refund is a second refund
        onRefund(onOrder([[$w->b, '10.00']]), '5.00');                                                              // not branch A's

        expect(($this->numbers)()['refunds'])->toBe(['count' => 2, 'amount' => '60.00'])->and(($this->numbers)($w->b)['refunds'])->toBe(['count' => 3, 'amount' => '25.00']);
    });

    it('counts failed deliveries of the day and the orders nobody accepted or still have open', function () {
        $w = $this->world;
        $done = onOrder([[$w->a, '10.00']], []);
        $done->fulfillments->first()->update(['status' => 'delivered', 'delivered_at' => now()]);
        onOrder([[$w->a, '10.00', '0.00', 'pending']]);
        onOrder([[$w->a, '10.00', '0.00', 'preparing']]);
        onOrder([[$w->a, '10.00', '0.00', 'out_for_delivery']]);
        $failed = onOrder([[$w->a, '10.00', '0.00', 'failed']]);
        DB::table('deliveries')->insert(['id' => (string) Str::uuid(), 'order_fulfillment_id' => $failed->fulfillments->first()->id, 'branch_id' => $w->a->id, 'mode' => 'internal',
            'status' => 'failed', 'tracking_number' => 'TRK1', 'failed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        $n = ($this->numbers)();

        expect($n['open'])->toBe(['open' => 4, 'never_accepted' => 1])->and($n['failed_deliveries'])->toBe(1);
    });

    it('measures how fast the branch accepted and prepared, in minutes', function () {
        $w = $this->world;
        foreach ([[10, 30], [20, 50]] as [$accept, $prepare]) {
            $o = onOrder([[$w->a, '10.00', '0.00', 'ready']], ['placed_at' => now()->subHours(3), 'confirmed_at' => now()->subHours(3)]);
            $f = $o->fulfillments->first();
            $f->forceFill(['created_at' => now()->subHours(3), 'accepted_at' => now()->subHours(3)->addMinutes($accept), 'ready_at' => now()->subHours(3)->addMinutes($accept + $prepare)])->save();
        }

        expect(($this->numbers)()['speed'])->toBe(['accept_minutes' => 15, 'accepted' => 2, 'prepare_minutes' => 40, 'prepared' => 2]);
    });

    it('reads stock as it stands: out and low', function () {
        $w = $this->world;
        [$v1, $v2, $v3] = onVariants(3);
        $ledger = app(InventoryLedger::class);
        foreach ([[$v1, 3, 0], [$v2, 2, 5], [$v3, 50, 5]] as [$v, $received, $min]) {
            $ledger->apply($w->a->id, $v->id, MovementType::OpeningBalance, $received);
        }
        $ledger->apply($w->a->id, $v1->id, MovementType::SaleDeduction, -3);                                         // sold out: had stock, now none
        DB::table('branch_inventory')->where('branch_id', $w->a->id)->where('product_variant_id', $v2->id)->update(['minimum_stock' => 5]);

        expect(($this->numbers)()['stock'])->toBe(['out' => 1, 'low' => 1]);
    });
});

/** The first and last instant of the test day in UTC, as the dashboard asks for them. */
function BusinessTimeRange(): array
{
    return BusinessTime::dateRange('2026-11-19', '2026-11-19');
}
