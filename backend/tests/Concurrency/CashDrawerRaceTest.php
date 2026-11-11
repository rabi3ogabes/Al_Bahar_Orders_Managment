<?php

use App\Domain\Orders\Models\Order;
use App\Domain\Payments\Models\CashCollection;
use App\Domain\Payments\Models\CashHandover;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Services\CashDrawerService;
use App\Domain\Payments\Services\PaymentService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\TestCase;

uses(TestCase::class, DatabaseTruncation::class);

/* Real multi-process races on committed data: cash can be received once and banked once, whoever is fastest. */

function raceCollect(object $w, $collector): void
{
    test()->postJson('/api/v1/cart/items', ['variant_id' => $w->v2->id, 'quantity' => 1])->assertCreated();
    $id = quoteAndPlace(['address_id' => $w->address->id, 'payment_method' => 'cod', 'allow_split' => false])->assertCreated()->json('data.order.id');
    $order = Order::with('fulfillments')->findOrFail($id);
    app(PaymentService::class)->recordCollection(Payment::where('order_id', $id)->firstOrFail(), $order->fulfillments->first(), $collector, 'cash', $order->grand_total);
}

it('lets exactly one cashier receive the same cash when several try at once', function () {
    $w = commerceWorld([10, 10]);
    $driver = staffWithRole('delivery_employee', $w->a);
    raceCollect($w, $driver);
    raceCollect($w, $driver);
    $cashiers = collect(range(1, 6))->map(fn () => staffWithRole('branch_manager', $w->a))->all();

    $codes = race(6, fn (int $i) => (bool) app(CashDrawerService::class)->handIn($cashiers[$i], $driver, $w->a->id, '550.00'));

    expect(count(array_filter($codes, fn ($c) => $c === 0)))->toBe(1)
        ->and(CashHandover::count())->toBe(1)
        ->and(CashCollection::whereNull('cash_handover_id')->count())->toBe(0)
        ->and(CashCollection::where('cash_handover_id', CashHandover::value('id'))->count())->toBe(2)
        ->and(CashHandover::value('counted_amount'))->toBe('550.00');
})->skip(! function_exists('pcntl_fork'), 'pcntl not available');

it('banks a hand-in exactly once when several managers deposit it at once', function () {
    $w = commerceWorld([10, 10]);
    $driver = staffWithRole('delivery_employee', $w->a);
    raceCollect($w, $driver);
    $cashier = staffWithRole('branch_manager', $w->a);
    $h = app(CashDrawerService::class)->handIn($cashier, $driver, $w->a->id, '275.00');
    $managers = collect(range(1, 6))->map(fn () => staffWithRole('branch_manager', $w->a))->all();

    $codes = race(6, fn (int $i) => (bool) app(CashDrawerService::class)->deposit($managers[$i], [$h->id], 'REF-'.$i));

    expect(count(array_filter($codes, fn ($c) => $c === 0)))->toBe(1)
        ->and($h->fresh()->deposit_reference)->toStartWith('REF-')
        ->and(app(CashDrawerService::class)->drawerBalance($w->a->id)->toDecimal())->toBe('0.00');
})->skip(! function_exists('pcntl_fork'), 'pcntl not available');
