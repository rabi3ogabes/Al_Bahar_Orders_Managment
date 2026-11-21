<?php

use App\Domain\Branches\Models\Branch;
use App\Domain\Identity\Models\User;
use App\Domain\Payments\Models\CashHandover;
use App\Domain\Payments\Models\CashShift;
use App\Domain\Payments\Services\CashDrawerService;
use App\Domain\Payments\Services\CashShiftService;
use App\Support\DomainException;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/../Operations/OpsSupport.php';

/**
 * Builders for the cash shift tests. Collections go straight into the tables (no checkout), each on its own order so the hand-in window has an order number
 * to show. Money is always written as the exact QAR string, never computed with floats.
 */

/** @return array{id: string, number: string, order: string} a cash collection a person still holds at the branch */
function csCollect(Branch $branch, User $collector, string $amount = '275.00', string $context = 'cod', ?CarbonInterface $at = null): array
{
    $at ??= CarbonImmutable::now()->subHour();
    $o = opsOrder($branch, ['payment_method' => $context === 'cod' ? 'cod' : 'pay_at_pickup'], ['status' => 'delivered'], $at);
    $paymentId = (string) Str::uuid();
    DB::table('payments')->insert(['id' => $paymentId, 'payable_type' => 'order', 'payable_id' => $o['order'], 'order_id' => $o['order'], 'method' => $context, 'provider' => 'offline',
        'status' => 'paid', 'amount' => $amount, 'captured_amount' => $amount, 'created_at' => now(), 'updated_at' => now()]);
    $id = (string) Str::uuid();
    DB::table('cash_collections')->insert(['id' => $id, 'payment_id' => $paymentId, 'branch_id' => $branch->id, 'collected_by' => $collector->id, 'context' => $context, 'tender' => 'cash',
        'amount_due' => $amount, 'amount_collected' => $amount, 'status' => 'collected', 'collected_at' => opsUtc($at), 'created_at' => opsUtc($at), 'updated_at' => opsUtc($at)]);

    return ['id' => $id, 'number' => $o['number'], 'order' => $o['order']];
}

/** A count that adds up to the given number of 100-riyal notes and 1-riyal notes (the easy way to type a known total in a test). */
function csNotes(int $hundreds = 0, int $ones = 0): array
{
    return ['500' => 0, '200' => 0, '100' => $hundreds, '50' => 0, '10' => 0, '5' => 0, '1' => $ones];
}

/** Opens a shift at the branch for $cashier with a float of $hundreds x QAR 100. */
function csOpen(User $cashier, Branch $branch, int $hundreds = 5, string $coins = '0'): CashShift
{
    return app(CashShiftService::class)->open($cashier, $branch->id, csNotes($hundreds), $coins);
}

/** Closes the shift by counting exactly $hundreds x 100 + $ones x 1 + coins. */
function csClose(User $by, CashShift $shift, int $hundreds, int $ones = 0, string $coins = '0', ?string $note = null): CashShift
{
    return app(CashShiftService::class)->close($by, $shift, csNotes($hundreds, $ones), $coins, $note);
}

/** A hand-in of everything the collector holds at the branch, counted exactly. */
function csHandIn(User $receiver, User $collector, Branch $branch, string $counted, ?string $note = null, ?array $ids = null): CashHandover
{
    return app(CashDrawerService::class)->handIn($receiver, $collector, $branch->id, $counted, $note, $ids);
}

/** The DomainException code a call ends with, or null when it worked. */
function csCode(callable $fn): ?string
{
    try {
        $fn();
    } catch (DomainException $e) {
        return $e->errorCode;
    }

    return null;
}
