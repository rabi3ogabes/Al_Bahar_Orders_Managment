<?php

namespace App\Domain\Payments\Services;

use App\Domain\Payments\Models\CashHandover;
use App\Domain\Payments\Models\CashPayout;
use App\Domain\Payments\Models\CashShift;
use App\Support\Money;
use Illuminate\Support\Collection;

/**
 * Everything the Z-report of a closed shift shows (docs/guides/CASH_SHIFT.md). The headline figures are the ones frozen in the shift's `summary` when it was
 * closed; the lists beneath them are the hand-ins and bank deposits that carry the shift's id, which are never moved to another shift.
 *
 * Privacy: staff names and amounts only. No order number, customer name, phone or address ever goes in here, so the page can be printed, filed or shown
 * to an auditor without a second thought.
 */
final class CashShiftReport
{
    /** The longest list printed; the figures above it always cover everything. */
    public const LIST_LIMIT = 500;

    /** @return array<string, mixed> shift, z, summary, handins (a collection), listed_all, deposits (reference, at, handins, amount), payouts (a collection, voided ones included), payouts_listed_all, opening, closing, float, expected, counted, variance */
    public function for(CashShift $shift): array
    {
        $shift->loadMissing(['branch', 'opener:id,name', 'closer:id,name', 'reviewer:id,name']);
        $handins = CashHandover::query()->where('cash_shift_id', $shift->id)->with(['collector:id,name', 'receiver:id,name'])
            ->orderBy('created_at')->orderBy('id')->limit(self::LIST_LIMIT + 1)->get();
        $listedAll = $handins->count() <= self::LIST_LIMIT;

        $deposits = CashHandover::query()->where('deposit_shift_id', $shift->id)->orderBy('deposited_at')->orderBy('id')->limit(self::LIST_LIMIT)
            ->get(['id', 'deposit_reference', 'deposited_at', 'counted_amount'])
            ->groupBy('deposit_reference')
            ->map(fn (Collection $rows, $reference) => [
                'reference' => (string) $reference, 'at' => $rows->first()->deposited_at, 'handins' => $rows->count(),
                'amount' => $rows->reduce(fn (Money $c, CashHandover $h) => $c->plus(Money::of((string) $h->counted_amount)), Money::zero()),
            ])->values()->all();

        // Petty cash paid out of the drawer, in the order it happened. A reversed payout is listed too (marked), so nothing quietly disappears from the page.
        $payouts = CashPayout::query()->where('cash_shift_id', $shift->id)->with(['payer:id,name', 'approver:id,name', 'voider:id,name'])
            ->orderBy('created_at')->orderBy('id')->limit(self::LIST_LIMIT + 1)->get();

        return [
            'shift' => $shift, 'z' => (string) $shift->zLabel(), 'summary' => $shift->summary ?? [],
            'handins' => $handins->take(self::LIST_LIMIT), 'listed_all' => $listedAll, 'deposits' => $deposits,
            'payouts' => $payouts->take(self::LIST_LIMIT), 'payouts_listed_all' => $payouts->count() <= self::LIST_LIMIT,
            'opening' => ['notes' => $shift->opening_notes ?? [], 'coins' => Money::of((string) $shift->opening_coins), 'total' => Money::of((string) $shift->opening_total)],
            'closing' => ['notes' => $shift->closing_notes ?? [], 'coins' => Money::of((string) $shift->closing_coins), 'total' => Money::of((string) $shift->closing_total)],
            'float' => Money::of((string) $shift->opening_total), 'expected' => Money::of((string) $shift->expected_total),
            'counted' => Money::of((string) $shift->closing_total), 'variance' => Money::of((string) $shift->variance),
        ];
    }
}
