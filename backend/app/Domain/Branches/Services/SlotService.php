<?php

namespace App\Domain\Branches\Services;

use App\Domain\Branches\Models\Branch;
use App\Domain\Branches\Models\DeliverySlot;
use App\Domain\Branches\Models\DeliverySlotBooking;
use App\Support\BusinessTime;
use App\Support\DomainException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Pickup/delivery slot availability and capacity-safe booking (row lock on the per-date counter). */
class SlotService
{
    public function __construct(private readonly BranchHoursService $hours) {}

    /** @return list<array{id: string, date: string, starts_at: string, ends_at: string, capacity: int, available: int, is_full: bool}> */
    public function available(Branch $branch, string $type, string $date): array
    {
        $day = CarbonImmutable::parse($date, BusinessTime::zone());
        $now = BusinessTime::now();
        $slots = DeliverySlot::where('branch_id', $branch->id)->where('fulfillment_type', $type)
            ->where('day_of_week', $day->dayOfWeek)->where('is_active', true)->orderBy('starts_at')->get();
        $booked = DeliverySlotBooking::whereIn('delivery_slot_id', $slots->pluck('id'))->whereDate('slot_date', $day->toDateString())
            ->pluck('booked', 'delivery_slot_id');

        return $slots->map(function (DeliverySlot $slot) use ($day, $now, $booked, $branch) {
            $start = $day->setTimeFromTimeString($slot->starts_at);
            $closedDay = ! $this->hours->isOpen($branch, $start->addMinute());
            $cutoffPassed = $now->greaterThan($start->subMinutes($slot->cutoff_minutes));
            $available = ($closedDay || $cutoffPassed) ? 0 : max(0, $slot->capacity - (int) ($booked[$slot->id] ?? 0));

            return [
                'id' => $slot->id,
                'date' => $day->toDateString(),
                'starts_at' => substr($slot->starts_at, 0, 5),
                'ends_at' => substr($slot->ends_at, 0, 5),
                'capacity' => $slot->capacity,
                'available' => $available,
                'is_full' => $available === 0,
            ];
        })->values()->all();
    }

    /** Must be called inside the checkout transaction. Throws when the slot is full. */
    public function book(string $slotId, string $date, string $branchId, string $type): void
    {
        $slot = DeliverySlot::where('id', $slotId)->where('branch_id', $branchId)->where('fulfillment_type', $type)->where('is_active', true)->first();
        if (! $slot) {
            throw DomainException::make('slot_unavailable');
        }
        $day = CarbonImmutable::parse($date, BusinessTime::zone());
        if ($day->dayOfWeek !== $slot->day_of_week || BusinessTime::now()->greaterThan($day->setTimeFromTimeString($slot->starts_at)->subMinutes($slot->cutoff_minutes))) {
            throw DomainException::make('slot_unavailable');
        }
        DB::table('delivery_slot_bookings')->insertOrIgnore([
            'id' => (string) \Illuminate\Support\Str::uuid(), 'delivery_slot_id' => $slot->id, 'slot_date' => $day->toDateString(),
            'booked' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $booking = DeliverySlotBooking::where('delivery_slot_id', $slot->id)->whereDate('slot_date', $day->toDateString())->lockForUpdate()->first();
        if ($booking->booked >= $slot->capacity) {
            throw DomainException::make('slot_full', 409);
        }
        $booking->increment('booked');
    }

    public function release(?string $slotId, ?string $date): void
    {
        if (! $slotId || ! $date) {
            return;
        }
        DeliverySlotBooking::where('delivery_slot_id', $slotId)->whereDate('slot_date', $date)->where('booked', '>', 0)->decrement('booked');
    }
}
