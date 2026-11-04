<?php

/*
 * Real concurrency: forked processes, each with its own PostgreSQL connection, race to reserve the same stock.
 * Uses committed data (DatabaseTruncation), so it is excluded from the transactional Feature suite.
 * Run: ./vendor/bin/pest tests/Concurrency
 */

use App\Domain\Branches\Models\Branch;
use App\Domain\Catalog\Models\Product;
use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Models\BranchInventory;
use App\Domain\Inventory\Models\StockReservation;
use App\Domain\Inventory\Services\InventoryLedger;
use App\Domain\Inventory\Services\ReservationService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, DatabaseTruncation::class);

/** Fork $n children running $work($i); returns their exit codes (0 = success). */
function race(int $n, Closure $work): array
{
    DB::disconnect();
    $pids = [];
    for ($i = 0; $i < $n; $i++) {
        $pid = pcntl_fork();
        if ($pid === 0) {
            DB::purge();
            try {
                $code = $work($i) ? 0 : 1;
            } catch (Throwable) {
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

it('never oversells when many checkouts reserve the last units simultaneously', function () {
    $branch = Branch::factory()->create();
    $variant = Product::factory()->create()->variants()->first();
    app(InventoryLedger::class)->apply($branch->id, $variant->id, MovementType::OpeningBalance, 5);

    $codes = race(16, fn (int $i) => (bool) app(ReservationService::class)->reserve([[
        'branch_id' => $branch->id, 'variant_id' => $variant->id, 'quantity' => 1, 'reference_type' => 'order_item', 'reference_id' => "race-{$i}",
    ]]));

    $row = BranchInventory::where('branch_id', $branch->id)->where('product_variant_id', $variant->id)->first();
    expect(count(array_filter($codes, fn ($c) => $c === 0)))->toBe(5)
        ->and($row->reserved)->toBe(5)->and($row->available())->toBe(0)
        ->and(StockReservation::where('status', 'active')->count())->toBe(5);
})->skip(! function_exists('pcntl_fork'), 'pcntl not available');

it('releases an expiring reservation exactly once under concurrent expiry workers', function () {
    $branch = Branch::factory()->create();
    $variant = Product::factory()->create()->variants()->first();
    app(InventoryLedger::class)->apply($branch->id, $variant->id, MovementType::OpeningBalance, 5);
    app(ReservationService::class)->reserve([['branch_id' => $branch->id, 'variant_id' => $variant->id, 'quantity' => 3,
        'reference_type' => 'order_item', 'reference_id' => 'exp-1']], now()->subMinute());

    race(6, fn () => app(ReservationService::class)->expireDue() >= 0);

    $row = BranchInventory::where('branch_id', $branch->id)->where('product_variant_id', $variant->id)->first();
    expect($row->reserved)->toBe(0)
        ->and(DB::table('stock_movements')->where('type', 'reservation_release')->count())->toBe(1);
})->skip(! function_exists('pcntl_fork'), 'pcntl not available');
