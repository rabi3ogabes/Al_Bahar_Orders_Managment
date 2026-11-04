<?php

namespace App\Domain\Inventory\Services;

use App\Domain\Branches\Models\Branch;
use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Exceptions\InsufficientStock;
use App\Domain\Inventory\Models\StockReservation;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Reservation lifecycle: active → consumed | released | expired. Every transition happens exactly once:
 * the reservation row is locked and its status checked before the ledger is touched.
 */
class ReservationService
{
    public function __construct(private readonly InventoryLedger $ledger) {}

    /**
     * Atomically reserve every line or nothing. Idempotent per (reference_type, reference_id).
     *
     * @param  list<array{branch_id: string, variant_id: string, quantity: int, reference_type: string, reference_id: string}>  $lines
     * @return list<StockReservation>
     *
     * @throws InsufficientStock
     */
    public function reserve(array $lines, ?CarbonInterface $expiresAt = null, ?string $actorId = null): array
    {
        return DB::transaction(function () use ($lines, $expiresAt, $actorId) {
            $rows = $this->ledger->lockMany(array_map(fn ($l) => [$l['branch_id'], $l['variant_id']], $lines));

            $existing = StockReservation::where(function ($q) use ($lines) {
                foreach ($lines as $l) {
                    $q->orWhere(fn ($w) => $w->where('reference_type', $l['reference_type'])->where('reference_id', $l['reference_id']));
                }
            })->get()->keyBy(fn ($r) => $r->reference_type.'|'.$r->reference_id);

            // Validate everything first (aggregate quantities for the same branch/variant).
            $needed = [];
            foreach ($lines as $l) {
                if (! isset($existing[$l['reference_type'].'|'.$l['reference_id']])) {
                    $key = $l['branch_id'].'|'.$l['variant_id'];
                    $needed[$key] = ($needed[$key] ?? 0) + $l['quantity'];
                }
            }
            $shortages = [];
            foreach ($needed as $key => $qty) {
                if ($rows[$key]->available() < $qty) {
                    [$b, $v] = explode('|', $key);
                    $shortages[] = ['branch_id' => $b, 'variant_id' => $v, 'requested' => $qty, 'available' => $rows[$key]->available()];
                }
            }
            if ($shortages) {
                throw InsufficientStock::for($shortages, Branch::find($shortages[0]['branch_id'])?->localized('name') ?? '');
            }

            $out = [];
            foreach ($lines as $l) {
                $key = $l['reference_type'].'|'.$l['reference_id'];
                if (isset($existing[$key])) {
                    $out[] = $existing[$key];

                    continue;
                }
                $reservation = StockReservation::create([
                    'branch_id' => $l['branch_id'], 'product_variant_id' => $l['variant_id'], 'quantity' => $l['quantity'],
                    'status' => 'active', 'reference_type' => $l['reference_type'], 'reference_id' => $l['reference_id'],
                    'expires_at' => $expiresAt, 'created_by' => $actorId,
                ]);
                $this->ledger->apply($l['branch_id'], $l['variant_id'], MovementType::Reservation, 0, $l['quantity'],
                    'stock_reservation', $reservation->id, null, $actorId);
                $out[] = $reservation;
            }

            return $out;
        });
    }

    /** Release an active reservation. Returns false (no-op) if it was already consumed/released/expired. */
    public function release(StockReservation|string $reservation, string $reason = 'cancelled', bool $expired = false, ?string $actorId = null): bool
    {
        return DB::transaction(function () use ($reservation, $reason, $expired, $actorId) {
            $r = StockReservation::whereKey(is_string($reservation) ? $reservation : $reservation->id)->lockForUpdate()->first();
            if (! $r || $r->status !== 'active') {
                return false;
            }
            $this->ledger->apply($r->branch_id, $r->product_variant_id, MovementType::ReservationRelease, 0, -$r->quantity,
                'stock_reservation', $r->id, $reason, $actorId);
            $r->update(['status' => $expired ? 'expired' : 'released', 'released_at' => now(), 'release_reason' => $reason]);

            return true;
        });
    }

    /**
     * Consume (ship/hand over) $quantity of an active reservation: on_hand and reserved both decrease.
     * Any unconsumed remainder is released. Returns false if not active.
     */
    public function consume(StockReservation|string $reservation, ?int $quantity = null, MovementType $type = MovementType::SaleDeduction, ?string $actorId = null, ?string $referenceType = null, ?string $referenceId = null): bool
    {
        return DB::transaction(function () use ($reservation, $quantity, $type, $actorId, $referenceType, $referenceId) {
            $r = StockReservation::whereKey(is_string($reservation) ? $reservation : $reservation->id)->lockForUpdate()->first();
            if (! $r || $r->status !== 'active') {
                return false;
            }
            $quantity ??= $r->quantity;
            $quantity = max(0, min($quantity, $r->quantity));
            if ($quantity > 0) {
                $this->ledger->apply($r->branch_id, $r->product_variant_id, $type, -$quantity, -$quantity,
                    $referenceType ?? $r->reference_type, $referenceId ?? $r->reference_id, null, $actorId);
            }
            $remainder = $r->quantity - $quantity;
            if ($remainder > 0) {
                $this->ledger->apply($r->branch_id, $r->product_variant_id, MovementType::ReservationRelease, 0, -$remainder,
                    'stock_reservation', $r->id, 'partial_consumption_remainder', $actorId);
            }
            $r->update(['status' => 'consumed', 'consumed_at' => now()]);

            return true;
        });
    }

    /** Release all reservations past their expiry. Safe to run concurrently (row locks + status check). */
    public function expireDue(int $limit = 500): int
    {
        $count = 0;
        StockReservation::where('status', 'active')->whereNotNull('expires_at')->where('expires_at', '<', now())
            ->orderBy('expires_at')->limit($limit)->pluck('id')
            ->each(function ($id) use (&$count) {
                if ($this->release($id, 'expired', expired: true)) {
                    $count++;
                }
            });

        return $count;
    }

    public function forReference(string $type, string $id): ?StockReservation
    {
        return StockReservation::where('reference_type', $type)->where('reference_id', $id)->first();
    }
}
