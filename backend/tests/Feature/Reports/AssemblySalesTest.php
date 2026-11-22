<?php

use App\Domain\Branches\Models\Branch;
use App\Domain\Identity\Models\User;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderFulfillment;
use App\Domain\Orders\Models\OrderItem;
use App\Domain\Reports\Models\DataExport;
use App\Domain\Reports\Services\ReportService;
use App\Livewire\Admin\Insights\ReportIndex;
use App\Support\DomainException;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

require_once __DIR__.'/OwnerNumbersSupport.php';

// Paid assembly in the sales reports (docs/guides/ASSEMBLY_SALES_AND_PETTY_CASH.md). Rows are written directly with concrete amounts so every figure can be checked by hand.

beforeEach(function () {
    $this->world = onWorld();
    $this->today = now()->toDateString();
    $this->report = fn (string $name, ?User $as = null, ?string $branch = null, bool $assemblyOnly = false) => app(ReportService::class)->run($as ?? $this->world->admin, $name, $this->today, $this->today, $branch, $assemblyOnly);
    $this->metric = fn (string $report, string $metric, ?User $as = null, bool $assemblyOnly = false) => collect(($this->report)($report, $as, null, $assemblyOnly)['rows'])->firstWhere('metric', $metric)['value'];
});

/**
 * An order with one part at $branch whose lines are [fee per unit or null, units, optional extras]. Each line sells for 100.00 a unit.
 *
 * @param  list<array{0: ?string, 1: int, 2?: array<string, mixed>}>  $lines
 * @param  array<string, mixed>  $attrs  overrides for the orders row
 */
function asOrder(Branch $branch, array $lines, array $attrs = []): Order
{
    $order = onOrder([[$branch, '0.00']], $attrs);
    $part = $order->fulfillments->first();
    OrderItem::where('order_fulfillment_id', $part->id)->delete();
    asLines($order, $part, $lines);

    return $order->fresh(['fulfillments', 'items']);
}

/** @param list<array{0: ?string, 1: int, 2?: array<string, mixed>}> $lines */
function asLines(Order $order, OrderFulfillment $part, array $lines): void
{
    foreach ($lines as $i => $l) {
        [$fee, $units] = $l;
        $extra = $l[2] ?? [];
        OrderItem::create($extra + ['order_id' => $order->id, 'order_fulfillment_id' => $part->id, 'branch_id' => $part->branch_id, 'sku' => 'AS-'.$i.'-'.substr($order->number, -4),
            'name_en' => 'Wardrobe', 'name_ar' => 'خزانة', 'unit_price' => '100.00', 'unit_final_price' => '100.00', 'quantity' => $units, 'line_subtotal' => (string) (100 * $units).'.00',
            'line_total' => (string) (100 * $units).'.00', 'assembly_fee' => $fee, 'assembly_total' => $fee === null ? '0.00' : number_format((float) $fee * $units, 2, '.', '')]);
    }
}

describe('what assembly revenue means', function () {
    it('is the fee per unit times the units, counted per branch, with jobs and units', function () {
        $w = $this->world;
        asOrder($w->a, [['40.00', 2], [null, 1]]);                 // 2 units x 40 = 80.00, one extra line with no assembly
        asOrder($w->a, [['25.00', 1]]);                            // 25.00
        asOrder($w->b, [['60.00', 3]]);                            // 180.00
        asOrder($w->b, [[null, 4]]);                               // no assembly: not a job

        $report = ($this->report)('sales_assembly');

        expect($report['columns'])->toBe(['branch', 'name', 'jobs', 'units', 'returned_units', 'gross', 'refunded', 'net'])
            ->and($report['rows'])->toHaveCount(2)
            ->and($report['rows'][0])->toMatchArray(['branch' => 'WKR', 'jobs' => 1, 'units' => 3, 'gross' => '180.00', 'refunded' => '0.00', 'net' => '180.00'])
            ->and($report['rows'][1])->toMatchArray(['branch' => 'DOH', 'jobs' => 2, 'units' => 3, 'gross' => '105.00', 'refunded' => '0.00', 'net' => '105.00'])
            ->and($report['summary'])->toMatchArray(['jobs' => 3, 'units' => 6, 'gross' => '285.00', 'refunded' => '0.00', 'net' => '285.00']);
    });

    it('takes the assembly of returned units off, and a part whose units all came back is no longer a job', function () {
        $w = $this->world;
        asOrder($w->a, [['40.00', 3, ['quantity_handed_over' => 3, 'quantity_returned' => 1]]]);   // 120.00 gross, one unit back = 40.00
        asOrder($w->a, [['30.00', 2, ['quantity_handed_over' => 2, 'quantity_returned' => 2]]]);   // 60.00 gross, all back = 60.00

        $row = ($this->report)('sales_assembly')['rows'][0];

        expect($row)->toMatchArray(['jobs' => 1, 'units' => 5, 'returned_units' => 3, 'gross' => '180.00', 'refunded' => '100.00', 'net' => '80.00']);
    });

    it('drops cancelled units, cancelled and unpaid orders, and a removed line', function () {
        $w = $this->world;
        asOrder($w->a, [['40.00', 3, ['quantity_cancelled' => 1]]]);                                 // 2 units left = 80.00
        asOrder($w->a, [['40.00', 1, ['quantity_cancelled' => 1]]]);                                 // nothing left: no job, no money
        asOrder($w->a, [['50.00', 1]], ['status' => 'cancelled']);
        asOrder($w->a, [['50.00', 1]], ['status' => 'pending_payment']);

        $total = ($this->report)('sales_assembly')['summary'];

        expect($total)->toMatchArray(['jobs' => 1, 'units' => 2, 'gross' => '80.00', 'net' => '80.00']);
    });

    it('never counts more than the line still owes (an adjustment lowered it)', function () {
        asOrder($this->world->a, [['40.00', 3, ['assembly_total' => '40.00']]]);                      // the line owes 40.00 although 3 x 40 = 120.00

        expect(($this->report)('sales_assembly')['summary']['gross'])->toBe('40.00');
    });

    it('counts an order on the day it was placed, in shop time, and not before or after', function () {
        $w = $this->world;
        asOrder($w->a, [['40.00', 1]], ['placed_at' => now()->subDays(3)]);
        asOrder($w->a, [['40.00', 1]], ['placed_at' => now()->addDays(2)]);
        asOrder($w->a, [['40.00', 1]]);

        expect(($this->report)('sales_assembly')['summary']['gross'])->toBe('40.00');
    });
});

describe('boundaries', function () {
    it('answers zero, not an error, when there is no assembly at all', function () {
        asOrder($this->world->a, [[null, 2]]);

        $report = ($this->report)('sales_assembly');

        expect($report['rows'])->toBe([])->and($report['summary'])->toMatchArray(['jobs' => 0, 'units' => 0, 'gross' => '0.00', 'refunded' => '0.00', 'net' => '0.00']);
    });

    it('works with one order and one unit', function () {
        asOrder($this->world->b, [['15.00', 1]]);

        expect(($this->report)('sales_assembly')['rows'])->toHaveCount(1)->and(($this->report)('sales_assembly')['rows'][0])->toMatchArray(['jobs' => 1, 'units' => 1, 'net' => '15.00']);
    });

    it('lists a branch that sold nothing with assembly as no row at all', function () {
        asOrder($this->world->a, [['15.00', 1]]);

        expect(collect(($this->report)('sales_assembly')['rows'])->pluck('branch')->all())->toBe(['DOH']);
    });

    it('counts two parts of one order as two jobs (one visit each)', function () {
        $w = $this->world;
        $order = onOrder([[$w->a, '0.00'], [$w->b, '0.00']]);
        foreach ($order->fulfillments as $part) {
            OrderItem::where('order_fulfillment_id', $part->id)->delete();
            asLines($order, $part, [['20.00', 2]]);
        }

        expect(($this->report)('sales_assembly')['summary'])->toMatchArray(['jobs' => 2, 'units' => 4, 'gross' => '80.00']);
    });
});

describe('the sales summary and the filter "orders with assembly"', function () {
    it('shows the assembly rows beside the other numbers and leaves net sales as it was', function () {
        $w = $this->world;
        asOrder($w->a, [['40.00', 2]]);
        asOrder($w->a, [[null, 1]]);

        expect(($this->metric)('sales_summary', 'assembly_jobs'))->toBe(1)
            ->and(($this->metric)('sales_summary', 'assembly_units'))->toBe(2)
            ->and(($this->metric)('sales_summary', 'assembly_gross'))->toBe('80.00')
            ->and(($this->metric)('sales_summary', 'assembly_refunded'))->toBe('0.00')
            ->and(($this->metric)('sales_summary', 'assembly_net'))->toBe('80.00')
            ->and(($this->metric)('sales_summary', 'net_sales'))->toBe('300.00');                    // 2 x 100 + 1 x 100 of items, assembly not in it
    });

    it('narrows every sales report to the parts that have assembly', function () {
        $w = $this->world;
        asOrder($w->a, [['40.00', 2]]);                          // items 200.00
        asOrder($w->a, [[null, 1]]);                             // items 100.00, no assembly
        asOrder($w->b, [[null, 5]]);                             // items 500.00, no assembly

        expect(($this->metric)('sales_summary', 'net_sales'))->toBe('800.00')
            ->and(($this->metric)('sales_summary', 'net_sales', null, true))->toBe('200.00')
            ->and(($this->metric)('sales_summary', 'orders', null, true))->toBe(1)
            ->and(($this->report)('sales_by_branch', null, null, true)['rows'])->toHaveCount(1)
            ->and(($this->report)('sales_by_branch', null, null, true)['rows'][0]['branch'])->toBe('DOH')
            ->and(($this->report)('sales_by_branch', null, null, true)['rows'][0]['net'])->toBe('200.00')
            ->and(($this->report)('sales_by_product', null, null, true)['summary']['net'])->toBe('200.00')
            ->and(($this->report)('sales_by_category', null, null, true)['summary']['net'])->toBe('200.00')
            ->and(($this->report)('sales_by_brand', null, null, true)['summary']['net'])->toBe('200.00')
            ->and(($this->report)('sales_by_payment_method', null, null, true)['summary']['net'])->toBe('200.00')
            ->and(($this->report)('sales_by_fulfillment', null, null, true)['summary']['net'])->toBe('200.00')
            ->and(collect(($this->report)('order_status', null, null, true)['rows'])->sum('orders'))->toBe(1);
    });

    it('takes only the refunds of the orders with assembly off the narrowed net sales', function () {
        $w = $this->world;
        $with = asOrder($w->a, [['40.00', 2]], ['payment_method' => 'sadad', 'payment_status' => 'paid']);
        $without = asOrder($w->a, [[null, 1]], ['payment_method' => 'sadad', 'payment_status' => 'paid']);
        onRefund($with, '30.00');
        onRefund($without, '10.00');

        expect(($this->metric)('sales_summary', 'refunds'))->toBe('40.00')
            ->and(($this->metric)('sales_summary', 'refunds', null, true))->toBe('30.00');
    });

    it('does not take a refund off a branch\'s narrowed net sales when the assembly of that split order sits at another branch', function () {
        $w = $this->world;
        $order = onOrder([[$w->a, '0.00'], [$w->b, '0.00']], ['payment_method' => 'sadad', 'payment_status' => 'paid']);
        [$partA, $partB] = [$order->fulfillments[0], $order->fulfillments[1]];
        OrderItem::where('order_id', $order->id)->delete();
        asLines($order, $partA, [[null, 1]]);
        asLines($order, $partB, [['40.00', 1]]);
        onRefund($order, '60.00');
        $mgrA = staffWithRole('branch_manager', $w->a);

        expect(($this->metric)('sales_summary', 'refunds', null, true))->toBe('60.00')             // the company sees an order with assembly
            ->and(($this->metric)('sales_summary', 'refunds', $mgrA, true))->toBe('0.00');         // branch A's part has none
    });

    it('ignores the filter on a report that has no meaning for it', function () {
        asOrder($this->world->a, [[null, 1]]);

        expect(($this->report)('customers', null, null, true)['rows'])->toBe(($this->report)('customers')['rows'])
            ->and(($this->report)('sales_assembly', null, null, true)['rows'])->toBe([]);
    });
});

describe('who sees what', function () {
    it('gives a branch manager only their own branch, and nothing for another branch', function () {
        $w = $this->world;
        asOrder($w->a, [['40.00', 1]]);
        asOrder($w->b, [['60.00', 1]]);
        $mgrA = staffWithRole('branch_manager', $w->a);

        $mine = ($this->report)('sales_assembly', $mgrA);

        expect($mine['rows'])->toHaveCount(1)->and($mine['rows'][0]['branch'])->toBe('DOH')->and($mine['summary']['gross'])->toBe('40.00')
            ->and(($this->report)('sales_assembly', $mgrA, $w->b->id)['rows'])->toBe([])
            ->and(($this->metric)('sales_summary', 'assembly_gross', $mgrA))->toBe('40.00')
            ->and(($this->metric)('sales_summary', 'assembly_gross', $mgrA, true))->toBe('40.00');
    });

    it('is closed to a person without the reports right (403)', function () {
        $w = $this->world;
        $desk = staffWithRole('pickup_employee', $w->a);

        expect(fn () => ($this->report)('sales_assembly', $desk))->toThrow(DomainException::class);
        actingAsStaff($desk);
        $this->getJson('/api/v1/admin/reports/sales_assembly?from='.$this->today.'&to='.$this->today)->assertForbidden();
    });

    it('answers over the staff API, with the filter, for the viewer who may', function () {
        asOrder($this->world->a, [['40.00', 2]]);
        asOrder($this->world->a, [[null, 1]]);
        actingAsStaff($this->world->admin);

        $this->getJson('/api/v1/admin/reports/sales_assembly?from='.$this->today.'&to='.$this->today)->assertOk()->assertJsonPath('data.summary.gross', '80.00');
        $this->getJson('/api/v1/admin/reports/sales_summary?from='.$this->today.'&to='.$this->today.'&assembly_only=1')->assertOk()
            ->assertJsonPath('data.rows.0.value', 1);
    });
});

describe('the report screen and the CSV', function () {
    it('shows the assembly report with its numbers and the filter switch on a sales report', function () {
        asOrder($this->world->a, [['40.00', 2]]);
        asOrder($this->world->a, [[null, 1]]);
        $this->actingAs($this->world->admin);

        Livewire::test(ReportIndex::class)->set('report', 'sales_assembly')
            ->assertSee(__('content_admin.r_sales_assembly'))->assertSee(__('assembly_sales.note'))->assertSee('DOH')->assertSee('80.00')
            ->assertDontSee('data-assembly-filter', false)
            ->set('report', 'sales_summary')->assertSee('data-assembly-filter', false)->assertDontSee('data-assembly-filter-on', false)
            ->set('assemblyOnly', true)->assertSee('data-assembly-filter-on', false)->assertSee(__('assembly_sales.filter_on'))
            ->assertSee(__('assembly_sales.col_assembly_gross'));
    });

    it('is the same in Arabic', function () {
        asOrder($this->world->a, [['40.00', 2]]);
        $this->actingAs($this->world->admin);
        app()->setLocale('ar');

        Livewire::test(ReportIndex::class)->set('report', 'sales_assembly')->assertSee('مبيعات التركيب')->assertSee('مهام التركيب')->assertSee(__('assembly_sales.note'))
            ->set('report', 'sales_summary')->assertSee(__('assembly_sales.filter'));
    });

    it('queues a CSV that carries the filter, and the CSV is narrowed', function () {
        asOrder($this->world->a, [['40.00', 2]]);
        asOrder($this->world->a, [[null, 1]]);
        $this->actingAs($this->world->admin);

        Livewire::test(ReportIndex::class)->set('report', 'sales_by_branch')->set('assemblyOnly', true)->call('export');

        $export = DataExport::firstOrFail();
        expect($export->filters)->toMatchArray(['assembly_only' => true])->and($export->status)->toBe('completed');
        $csv = file_get_contents(Storage::disk($export->disk)->path($export->path));
        expect($csv)->toContain('DOH')->and($csv)->toContain('200.00')->and($csv)->not->toContain('300.00');
    });

    it('does not write the filter into an export of a report it does not apply to', function () {
        asOrder($this->world->a, [['40.00', 2]]);
        $this->actingAs($this->world->admin);

        Livewire::test(ReportIndex::class)->set('report', 'customers')->set('assemblyOnly', true)->call('export');

        expect(DataExport::firstOrFail()->filters)->not->toHaveKey('assembly_only');
    });

    it('answers a double click on the export button with two exports, never an error', function () {
        asOrder($this->world->a, [['40.00', 2]]);
        $this->actingAs($this->world->admin);

        Livewire::test(ReportIndex::class)->set('report', 'sales_assembly')->call('export')->call('export');

        expect(DataExport::where('type', 'sales_assembly')->count())->toBe(2);
    });
});
