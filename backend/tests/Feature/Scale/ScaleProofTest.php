<?php

use App\Domain\Identity\Models\User;
use App\Domain\Orders\Models\Order;
use App\Domain\Reports\Services\ReportService;
use App\Domain\Reports\Services\SalesBasis;
use App\Livewire\Admin\Sales\OrderIndex;
use App\Support\BoundedPage;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

require_once __DIR__.'/../Reports/NumbersSupport.php';

/*
 | The proof at scale (docs/guides/SCALE_PROOF.md): what the plan test found and fixed, kept as small tests that fail without the fix. The 500,000-order
 | measurements themselves are made by `php artisan scale:explain` on a *_scale database and never run here.
 */

it('refuses to seed or explain any database whose name does not end in _scale', function () {
    expect(DB::connection()->getDatabaseName())->not->toEndWith('_scale');
    foreach (['scale:seed', 'scale:explain'] as $command) {
        expect(Artisan::call($command))->toBe(1)->and(Artisan::output())->toContain('does not end in _scale');
    }
    expect(Order::count())->toBe(0);
});

it('counts a big list only up to a cap and writes the total as 10,000+', function () {
    $rows = DB::query()->fromRaw('generate_series(1, 10050) as g(n)')->select('g.n')->orderBy('g.n');

    $page = BoundedPage::paginate($rows, 25, 2);

    expect($page->total())->toBe(BoundedPage::CAP)->and($page->count())->toBe(25)->and($page->items()[0]->n)->toBe(26)
        ->and(BoundedPage::isCapped($page))->toBeTrue()->and(BoundedPage::label($page))->toBe('10,000+')
        ->and(BoundedPage::count(DB::query()->fromRaw('generate_series(1, 10050) as g(n)')->select('g.n')))->toBe(BoundedPage::CAP + 1)
        ->and(BoundedPage::show(BoundedPage::CAP + 1))->toBe('10,000+');
});

it('shows an exact total and an empty page when the list is short or empty', function () {
    $some = BoundedPage::paginate(DB::query()->fromRaw('generate_series(1, 7) as g(n)')->select('g.n')->orderBy('g.n'), 5, 2);
    $none = BoundedPage::paginate(DB::query()->fromRaw('generate_series(1, 0) as g(n)')->select('g.n'), 5);

    expect($some->total())->toBe(7)->and($some->count())->toBe(2)->and(BoundedPage::label($some))->toBe('7')->and(BoundedPage::isCapped($some))->toBeFalse()
        ->and($none->total())->toBe(0)->and($none->count())->toBe(0)->and(BoundedPage::label($none))->toBe('0');
});

it('gives a one-part order its grand total and divides a split order exactly as the old division did', function () {
    $w = nbWorld();
    nbDelivered($w->a, '2026-11-10 10:00', '2026-11-11 12:00', '2026-11-11', null, '133.33');                                  // one part: the whole order
    nbDelivered($w->b, '2026-11-10 11:00', '2026-11-11 12:00', '2026-11-11', null, '10.01');
    nbSplit([[$w->a, '33.33', '3.33'], [$w->b, '66.67', '0.00'], [$w->c, '0.01', '1.00']], '2026-11-10 12:00', ['2026-11-11 12:00', null, null], ['2026-11-11', null, null]);  // three parts, odd cents
    $cancelledPart = nbDelivered($w->a, '2026-11-10 13:00', '2026-11-11 12:00', '2026-11-11', null, '50.00');
    DB::table('order_fulfillments')->where('id', $cancelledPart['part'])->update(['status' => 'cancelled']);                      // a one-part order whose part was cancelled: not a sale

    // The division of release 1.0.222: every part of every order goes through the lines, the windows and the largest-remainder rule.
    $withLines = new ReflectionMethod(SalesBasis::class, 'withLines');
    $allocate = new ReflectionMethod(SalesBasis::class, 'allocate');
    $every = $withLines->invoke(null, DB::table('order_fulfillments as f')->join('orders as o', 'o.id', '=', 'f.order_id'))
        ->whereNotIn('o.status', SalesBasis::EXCLUDED_STATUSES)
        ->selectRaw("f.id as fulfillment_id, f.order_id as gk, f.order_id, f.branch_id, o.user_id, o.placed_at, o.grand_total as amount, (f.status <> 'cancelled') as live, (li.lines + f.delivery_fee) as weight");
    $expected = $allocate->invoke(null, $every)->orderBy('s.fulfillment_id')->pluck('s.share', 's.fulfillment_id')->map(fn ($v) => number_format((float) $v, 2, '.', ''))->all();

    $now = SalesBasis::orderShares()->orderBy('s.fulfillment_id')->pluck('s.share', 's.fulfillment_id')->map(fn ($v) => number_format((float) $v, 2, '.', ''))->all();

    expect($expected)->toHaveCount(5)->and($now)->toBe($expected)
        ->and($now)->not->toHaveKey($cancelledPart['part'])
        ->and(array_sum(array_map('floatval', $now)))->toEqualWithDelta(133.33 + 10.01 + (33.33 + 66.67 + 0.01 + 3.33 + 0.00 + 1.00), 0.001);
});

it('has every index the plan test asked for, valid and built', function () {
    $names = ['delivery_proofs_delivery_captured_index', 'cash_collections_payment_id_index', 'cash_collections_fulfillment_context_index', 'user_addresses_user_created_index',
        'order_fulfillments_branch_order_index', 'order_fulfillments_open_index', 'payments_created_at_index', 'payments_late_capture_index', 'stock_movements_created_at_index',
        'stock_movements_branch_created_index', 'deliveries_created_at_index', 'users_customers_created_index', 'orders_customer_name_trgm_idx', 'order_items_order_report_index', 'users_type_created_index'];
    $valid = DB::table('pg_class as c')->join('pg_index as i', 'i.indexrelid', '=', 'c.oid')->whereIn('c.relname', $names)->where('i.indisvalid', true)->pluck('c.relname')->all();

    expect(array_diff($names, $valid))->toBe([]);
});

it('counts the orders that need action in one pass, for the owner and for a branch, never from another branch', function () {
    $w = nbWorld();
    $mgrA = staffWithRole('branch_manager', $w->a);
    scOrder($w->a, '2026-11-18 10:00', null, null, ['status' => 'pending'], ['status' => 'confirmed']);                              // a new part nobody accepted: needs action
    scOrder($w->b, '2026-11-18 11:00', null, null, ['status' => 'preparing'], ['status' => 'processing']);                           // at the other branch: needs action there
    scOrder($w->a, '2026-11-18 12:00', null, null, ['status' => 'pending'], ['status' => 'pending_payment']);                         // waiting for the online payment: not yet
    scOrder($w->a, '2026-11-18 13:00', null, null, ['status' => 'accepted'], ['status' => 'cancellation_requested']);                // a cancellation to decide
    scOrder($w->a, '2026-11-18 14:00', null, null, ['status' => 'delivered'], ['status' => 'completed']);                            // finished

    $owner = Livewire::actingAs($w->admin)->test(OrderIndex::class)->viewData('counts');
    $branchA = Livewire::actingAs($mgrA)->test(OrderIndex::class)->viewData('counts');

    expect($owner['needs_action'])->toBe(3)->and($owner['pending_payment'])->toBe(1)
        ->and($branchA['needs_action'])->toBe(2)->and($branchA['pending_payment'])->toBe(1);
});

it('reads the customers report from the customer rollup, for a branch only its own customers', function () {
    $w = nbWorld();
    $repeat = User::factory()->create();
    $once = User::factory()->create();
    nbDelivered($w->a, '2026-11-10 10:00', '2026-11-11 12:00', '2026-11-11', null, '100.00', ['user_id' => $repeat->id]);
    nbDelivered($w->a, '2026-11-12 10:00', '2026-11-13 12:00', '2026-11-13', null, '50.00', ['user_id' => $repeat->id]);
    nbDelivered($w->b, '2026-11-12 11:00', '2026-11-13 12:00', '2026-11-13', null, '70.00', ['user_id' => $once->id]);
    $report = app(ReportService::class);
    $row = fn (array $r, string $metric) => collect($r['rows'])->firstWhere('metric', $metric)['value'];

    $all = $report->run($w->admin, 'customers', '2026-11-01', '2026-11-30');
    $branchA = $report->run(staffWithRole('branch_manager', $w->a), 'customers', '2026-11-01', '2026-11-30');

    expect($row($all, 'customers_with_orders'))->toBe(2)->and($row($all, 'repeat_customers'))->toBe(1)
        ->and($row($branchA, 'customers_with_orders'))->toBe(1)->and($row($branchA, 'average_lifetime_value'))->toBe('150.00');
});
