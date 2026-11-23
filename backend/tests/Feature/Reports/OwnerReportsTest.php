<?php

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessControl;
use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Services\InventoryLedger;
use App\Domain\Orders\Models\OrderItem;
use App\Domain\Reports\Services\ReportService;
use App\Livewire\Admin\Insights\ReportIndex;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

require_once __DIR__.'/OwnerNumbersSupport.php';

beforeEach(function () {
    $this->world = onWorld();
    $this->today = now()->toDateString();
    $this->report = fn (string $name, ?User $as = null, ?string $branch = null) => app(ReportService::class)->run($as ?? $this->world->admin, $name, $this->today, $this->today, $branch);
    $this->metric = fn (string $report, string $metric, ?User $as = null) => collect(($this->report)($report, $as)['rows'])->firstWhere('metric', $metric)['value'];
});

describe('two orders or refunds of the same amount are two orders and two refunds', function () {
    it('counts a customer’s lifetime value once per order, whatever the amounts are', function () {
        $w = $this->world;
        $other = User::factory()->create();
        foreach ([$w->customer, $other] as $c) {
            onOrder([[$w->a, '100.00']], ['user_id' => $c->id]);
            onOrder([[$w->a, '100.00']], ['user_id' => $c->id]);                              // the same amount again
        }

        // each customer spent 200.00 (it was 100.00: the two equal orders collapsed into one)
        expect(($this->metric)('customers', 'average_lifetime_value'))->toBe('200.00')
            ->and(($this->metric)('customers', 'customers_with_orders'))->toBe(2)
            ->and(($this->metric)('customers', 'repeat_customers'))->toBe(2)
            ->and(($this->metric)('customers', 'repeat_rate_percent'))->toEqual(100);
    });

    it('counts a split order once in a customer’s value, not once per branch, and gives a branch manager only their part', function () {
        $w = $this->world;
        onOrder([[$w->a, '1000.00', '15.00'], [$w->b, '250.00', '25.00']], ['user_id' => $w->customer->id]);
        $mgrB = staffWithRole('branch_manager', $w->b);

        expect(($this->metric)('customers', 'average_lifetime_value'))->toBe('1290.00')
            ->and(($this->metric)('customers', 'average_lifetime_value', $mgrB))->toBe('275.00');
    });

    it('lists new customers by the date of their first order and leaves older ones out', function () {
        $w = $this->world;
        $old = User::factory()->create();
        onOrder([[$w->a, '30.00']], ['user_id' => $old->id, 'placed_at' => now()->subDays(40)]);
        onOrder([[$w->a, '30.00']], ['user_id' => $old->id]);                                      // came back today
        onOrder([[$w->a, '30.00']], ['user_id' => $w->customer->id]);                              // first order today

        expect(($this->metric)('customers', 'new_customers'))->toBe(1)->and(($this->metric)('customers', 'customers_with_orders'))->toBe(2)
            ->and(($this->metric)('customers', 'repeat_customers'))->toBe(1);
    });

    it('does not load the customers into PHP: one aggregate comes back, however many customers there are', function () {
        $w = $this->world;
        foreach (range(1, 12) as $i) {
            onOrder([[$w->a, '10.00']], ['user_id' => User::factory()->create()->id]);
        }
        $statements = [];
        DB::listen(function ($q) use (&$statements) {
            $statements[] = $q->sql;
        });
        $count = fn (array $sqls, string $needle) => count(array_filter($sqls, fn ($sql) => str_contains($sql, $needle)));

        $first = ($this->report)('customers');                                                      // builds the customer rollup once, then ONE aggregate over it
        $builtOnce = $count($statements, 'order_fulfillments');
        $aggregates = $count($statements, 'select count(*) as customers');
        $statements = [];
        $second = ($this->report)('customers');                                                     // the rollup is fresh: not one order is read again

        expect($builtOnce)->toBe(2)   // the rebuild: one statement for the branch rows, one for the company rows->and($aggregates)->toBe(1)->and($count($statements, 'order_fulfillments'))->toBe(0)
            ->and(collect($first['rows'])->firstWhere('metric', 'customers_with_orders')['value'])->toBe(12)
            ->and($second['rows'])->toBe($first['rows']);
    });

    it('adds up refunds of the same amount instead of merging them', function () {
        $w = $this->world;
        $o1 = onOrder([[$w->a, '300.00']], ['payment_method' => 'sadad', 'payment_status' => 'paid']);
        $o2 = onOrder([[$w->a, '300.00']], ['payment_method' => 'sadad', 'payment_status' => 'paid']);
        onRefund($o1, '50.00', 'return');
        onRefund($o2, '50.00', 'return');                                                           // the same amount, a different order

        $finance = collect(($this->report)('finance_refunds')['rows'])->first();
        expect((int) $finance['refunds'])->toBe(2)->and($finance['amount'])->toBe('100.00')
            ->and(($this->metric)('sales_summary', 'refunds'))->toBe('100.00');
    });

    it('counts a refund of a split order once for the company and once in total for its branches', function () {
        $w = $this->world;
        $order = onOrder([[$w->a, '1000.00', '15.00'], [$w->b, '250.00', '25.00']], ['payment_method' => 'sadad', 'payment_status' => 'paid']);
        onRefund($order, '129.00', 'return');                                                       // a tenth of the order

        $mgrA = staffWithRole('branch_manager', $w->a);
        $mgrB = staffWithRole('branch_manager', $w->b);
        $part = fn (User $u) => ($this->metric)('sales_summary', 'refunds', $u);

        expect($part($w->admin))->toBe('129.00');
        // the two branches' shares of the refund follow their shares of the sale (1015 : 275) and are rounded to the cent
        $a = $part($mgrA);
        $b = $part($mgrB);
        expect($a)->toBe('101.50')->and($b)->toBe('27.50');                                          // 129 x 1015/1290 = 101.50 ; 129 x 275/1290 = 27.50
        expect(app(ReportService::class)->run($mgrA, 'finance_refunds', $this->today, $this->today)['rows'][0]['refunds'])->toBe(1);
    });
});

describe('net sales is never taken off twice', function () {
    it('leaves a whole cancelled order out, with the refund its cancellation raised', function () {
        $w = $this->world;
        onOrder([[$w->a, '500.00']], ['payment_method' => 'sadad', 'payment_status' => 'paid']);
        $cancelled = onOrder([[$w->a, '300.00']], ['status' => 'cancelled', 'payment_method' => 'sadad', 'payment_status' => 'refunded']);
        OrderItem::where('order_id', $cancelled->id)->update(['quantity_cancelled' => 1]);
        onRefund($cancelled, '300.00', 'order_cancelled');                                         // the automatic refund of a cancellation

        expect(($this->metric)('sales_summary', 'net_sales'))->toBe('500.00')                       // it was 200.00: the 300 was taken off a second time
            ->and(($this->metric)('sales_summary', 'orders'))->toBe(1)->and(($this->metric)('sales_summary', 'refunds'))->toBe('0.00')
            ->and(($this->metric)('sales_summary', 'cancelled'))->toBe('0.00');
    });

    it('takes a changed item off once: the line already dropped it, the refund only repays it', function () {
        $w = $this->world;
        // two chairs at 100.00 became one chair after the branch ran out: the line is now 100.00 and 100.00 was refunded
        $order = onOrder([[$w->a, '100.00']], ['payment_method' => 'sadad', 'payment_status' => 'partially_refunded', 'grand_total' => '100.00']);
        onRefund($order, '100.00', 'order_adjustment');

        expect(($this->metric)('sales_summary', 'net_sales'))->toBe('100.00')->and(($this->metric)('sales_summary', 'refunds'))->toBe('0.00');
    });

    it('takes a quantity cancelled after the order was placed off once, and returns and goodwill refunds off in full', function () {
        $w = $this->world;
        $order = onOrder([[$w->a, '400.00']], ['status' => 'partially_cancelled', 'payment_method' => 'sadad', 'payment_status' => 'paid']);
        OrderItem::where('order_id', $order->id)->update(['unit_final_price' => '100.00', 'quantity' => 4, 'quantity_cancelled' => 1, 'line_subtotal' => '400.00', 'line_total' => '400.00']);
        onRefund($order, '100.00', 'order_cancelled');                                              // repays the cancelled unit: already in `cancelled`
        onRefund($order, '40.00', 'return');                                                        // a returned item
        onRefund($order, '10.00', 'goodwill');                                                      // a goodwill refund

        expect(($this->metric)('sales_summary', 'gross_sales'))->toBe('400.00')->and(($this->metric)('sales_summary', 'cancelled'))->toBe('100.00')
            ->and(($this->metric)('sales_summary', 'refunds'))->toBe('50.00')
            ->and(($this->metric)('sales_summary', 'net_sales'))->toBe('250.00');                  // 400 - 100 - 50, not 400 - 100 - 150
    });

    it('shows booked sales next to net sales so the two can be reconciled, and keeps average order value per order', function () {
        $w = $this->world;
        onOrder([[$w->a, '1000.00', '15.00']]);
        onOrder([[$w->a, '500.00', '10.00']]);

        $rows = collect(($this->report)('sales_summary')['rows'])->pluck('value', 'metric');
        expect($rows['booked_sales'])->toBe('1525.00')->and($rows['net_sales'])->toBe('1500.00')->and($rows['average_order_value'])->toBe('750.00');
    });

    it('treats the other sales reports with the same definition: no cancelled or unpaid online order in them', function () {
        $w = $this->world;
        onOrder([[$w->a, '100.00']]);
        onOrder([[$w->a, '900.00']], ['status' => 'cancelled']);
        onOrder([[$w->a, '800.00']], ['status' => 'pending_payment', 'payment_method' => 'sadad']);

        $byBranch = ($this->report)('sales_by_branch');
        expect($byBranch['rows'])->toHaveCount(1)->and($byBranch['rows'][0]['orders'])->toBe(1)->and($byBranch['rows'][0]['net'])->toBe('100.00');
    });
});

describe('stock reports', function () {
    it('adds the on-hand stock of every branch for a product instead of showing the largest one', function () {
        $w = $this->world;
        $ledger = app(InventoryLedger::class);
        [$v] = onVariants(1);
        $ledger->apply($w->a->id, $v->id, MovementType::OpeningBalance, 5);
        $ledger->apply($w->b->id, $v->id, MovementType::OpeningBalance, 7);
        // sales: two deductions at A, one at B. They must neither multiply the stock nor be lost.
        $ledger->apply($w->a->id, $v->id, MovementType::SaleDeduction, -1);
        $ledger->apply($w->a->id, $v->id, MovementType::SaleDeduction, -1);
        $ledger->apply($w->b->id, $v->id, MovementType::SaleDeduction, -2);

        $row = collect(($this->report)('fast_slow_movers')['rows'])->firstWhere('sku', $v->sku);
        expect((int) $row['on_hand'])->toBe(8)->and((int) $row['units_sold'])->toBe(4);       // 3 + 5 on hand; 1 + 1 + 2 sold  (it was 5 on hand: the largest single branch)

        $mgrA = staffWithRole('branch_manager', $w->a);
        $mine = collect(app(ReportService::class)->run($mgrA, 'fast_slow_movers', $this->today, $this->today)['rows'])->firstWhere('sku', $v->sku);
        expect((int) $mine['on_hand'])->toBe(3)->and((int) $mine['units_sold'])->toBe(2);
    });

    it('counts low and out of stock over every row even when the list is cut short, with the same meaning as the dashboard', function () {
        $w = $this->world;
        $ledger = app(InventoryLedger::class);
        [$out, $low, $fine, $never] = onVariants(4);
        $ledger->apply($w->a->id, $out->id, MovementType::OpeningBalance, 2);
        $ledger->apply($w->a->id, $out->id, MovementType::SaleDeduction, -2);
        $ledger->apply($w->a->id, $low->id, MovementType::OpeningBalance, 2);
        $ledger->apply($w->a->id, $fine->id, MovementType::OpeningBalance, 40);
        DB::table('branch_inventory')->insert(['id' => (string) Str::uuid(), 'branch_id' => $w->a->id, 'product_variant_id' => $never->id,
            'on_hand' => 0, 'reserved' => 0, 'minimum_stock' => 0, 'created_at' => now(), 'updated_at' => now()]);

        // a service that lists only two rows, to prove the summary does not depend on the list
        app()->bind(ReportService::class, fn () => new class(app(AccessControl::class)) extends ReportService
        {
            protected int $levelRows = 2;
        });
        $result = app(ReportService::class)->run($w->admin, 'inventory_levels', $this->today, $this->today);

        expect($result['rows'])->toHaveCount(2)->and($result['summary']['out'])->toBe(1)->and($result['summary']['low'])->toBe(1)
            ->and(collect($result['rows'])->pluck('state')->all())->toBe(['out', 'low'])
            ->and(collect($result['rows'])->pluck('sku')->contains($never->sku))->toBeFalse();   // never held here: not a stock position
    });
});

describe('the reports page runs a report once per choice of filters', function () {
    it('does not run the report again for a re-render, only when the report, the dates or the branch change', function () {
        $w = $this->world;
        onOrder([[$w->a, '100.00']]);
        $this->actingAs($w->admin);
        $reportRuns = 0;
        DB::listen(function ($q) use (&$reportRuns) {
            if (str_contains($q->sql, 'sum(oi.line_subtotal)')) {      // the report's own query over the order lines
                $reportRuns++;
            }
        });

        $page = Livewire::test(ReportIndex::class);
        expect($reportRuns)->toBe(1);

        $page->call('$refresh')->call('$refresh');                                                   // anything else that re-renders the page
        expect($reportRuns)->toBe(1);

        $page->set('from', now()->subDays(3)->toDateString());
        expect($reportRuns)->toBe(2);
        $page->set('branch', $w->a->id);
        expect($reportRuns)->toBe(3);
        $page->call('preset', 'today');
        expect($reportRuns)->toBe(4);
        $page->call('$refresh');
        expect($reportRuns)->toBe(4);
        $page->set('report', 'sales_by_product');                                                    // a different report
        $page->assertSee('Majlis sofa');
    });

    it('shows the new figures and the plain-words note under the sales summary, in both languages', function () {
        $w = $this->world;
        onOrder([[$w->a, '100.00', '5.00']]);
        $this->actingAs($w->admin);

        Livewire::test(ReportIndex::class)->assertSee(__('owner_numbers.col_booked_sales'))->assertSee('Booked sales is the dashboard figure');
        app()->setLocale('ar');
        Livewire::test(ReportIndex::class)->assertSee('المبيعات المسجَّلة')->assertSee('رقم لوحة التحكم');
    });

    it('does not show a cached report to someone who lost the right to read reports', function () {
        $w = $this->world;
        $viewer = staffWithRole('accountant');
        $this->actingAs($viewer);
        $page = Livewire::test(ReportIndex::class)->assertOk();

        DB::table('user_roles')->where('user_id', $viewer->id)->delete();
        app(AccessControl::class)->forget($viewer);
        $page->call('$refresh')->assertForbidden();
    });
});
