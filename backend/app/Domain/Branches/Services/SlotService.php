<?php

namespace App\Domain\Branches\Services;

use App\Domain\Branches\Models\Branch;
use App\Domain\Branches\Models\DeliverySlot;
use App\Domain\Branches\Models\DeliverySlotBooking;
use App\Support\BusinessTime;
use App\Support\DomainException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Pickup/delivery slot availability and capacity-safe booking (row lock on the per-date counter). */
class SlotService
{
    public function __construct(private readonly BranchHoursService $hours) {}

    /** @return list<array{id: string, date: string, starts_at: string, ends_at: string, capacity: int, available: int, is_full: bool, bookable_until: ?string}> */
    public function available(Branch $branch, string $type, string $date): array
    {
        $day = CarbonImmutable::parse($date, BusinessTime::zone());
        $slots = DeliverySlot::where('branch_id', $branch->id)->where('fulfillment_type', $type)
            ->where('day_of_week', $day->dayOfWeek)->where('is_active', true)->orderBy('starts_at')->get();
        $booked = DeliverySlotBooking::whereIn('delivery_slot_id', $slots->pluck('id'))->whereDate('slot_date', $day->toDateString())
            ->pluck('booked', 'delivery_slot_id');

        return $this->rows($branch, $slots, $day, $booked->all(), BusinessTime::now());
    }

    /**
     * Availability for a span of dates in one go (two queries however long the span), one entry per date that has slots:
     * [{date, available (total free places that day), slots: [...same rows as available()]}].
     *
     * @return list<array{date: string, available: int, slots: list<array<string, mixed>>}>
     */
    public function availableRange(Branch $branch, string $type, string $from, string $to, bool $onlyAvailable = false): array
    {
        $start = CarbonImmutable::parse($from, BusinessTime::zone())->startOfDay();
        $end = CarbonImmutable::parse($to, BusinessTime::zone())->startOfDay();
        $slots = DeliverySlot::where('branch_id', $branch->id)->where('fulfillment_type', $type)->where('is_active', true)->orderBy('starts_at')->get()->groupBy('day_of_week');
        if ($slots->isEmpty()) {
            return [];
        }
        $booked = [];
        foreach (DeliverySlotBooking::whereIn('delivery_slot_id', $slots->flatten()->pluck('id'))->whereBetween('slot_date', [$start->toDateString(), $end->toDateString()])->get() as $b) {
            $booked[substr((string) $b->getRawOriginal('slot_date'), 0, 10)][$b->delivery_slot_id] = (int) $b->booked;
        }

        $now = BusinessTime::now();
        $out = [];
        for ($day = $start; $day->lessThanOrEqualTo($end); $day = $day->addDay()) {
            $forDay = $slots->get($day->dayOfWeek);
            if ($forDay === null) {
                continue;
            }
            $rows = $this->rows($branch, $forDay, $day, $booked[$day->toDateString()] ?? [], $now);
            $free = array_sum(array_column($rows, 'available'));
            if ($onlyAvailable && $free === 0) {
                continue;
            }
            $out[] = ['date' => $day->toDateString(), 'available' => $free, 'slots' => $rows];
        }

        return $out;
    }

    /**
     * @param  iterable<DeliverySlot>  $slots  the branch's active slots for this weekday
     * @param  array<string, int>  $booked  slot id => places already booked on $day
     * @return list<array{id: string, date: string, starts_at: string, ends_at: string, capacity: int, available: int, is_full: bool, bookable_until: ?string}>
     */
    private function rows(Branch $branch, iterable $slots, CarbonImmutable $day, array $booked, CarbonImmutable $now): array
    {
        $rows = [];
        foreach ($slots as $slot) {
            $start = $day->setTimeFromTimeString($slot->starts_at);
            $closedDay = ! $this->hours->isOpen($branch, $start->addMinute());
            $cutoffPassed = $now->greaterThan($start->subMinutes($slot->cutoff_minutes));
            $available = ($closedDay || $cutoffPassed) ? 0 : max(0, $slot->capacity - (int) ($booked[$slot->id] ?? 0));
            $rows[] = [
                'id' => $slot->id,
                'date' => $day->toDateString(),
                'starts_at' => substr($slot->starts_at, 0, 5),
                'ends_at' => substr($slot->ends_at, 0, 5),
                'capacity' => $slot->capacity,
                'available' => $available,
                'is_full' => $available === 0,
                // The last moment this slot can still be booked (its start minus the cutoff), so a screen left open can tell by itself when a time has gone.
                'bookable_until' => BusinessTime::iso($start->subMinutes($slot->cutoff_minutes)),
            ];
        }

        return $rows;
    }

    /** Whether the branch publishes any active slot for this fulfillment type. */
    public function hasSlots(Branch $branch, string $type): bool
    {
        return DeliverySlot::where('branch_id', $branch->id)->where('fulfillment_type', $type)->where('is_active', true)->exists();
    }

    /** Start of the slot on $date in business time (used as the delivery's scheduled time). */
    public function startsAt(string $slotId, string $date): CarbonImmutable
    {
        $slot = DeliverySlot::findOrFail($slotId);

        return CarbonImmutable::parse($date, BusinessTime::zone())->setTimeFromTimeString($slot->starts_at);
    }

    /** Move a booking to another slot/date atomically (book the new one first so a full slot leaves the old booking intact). */
    public function move(?string $fromSlotId, ?string $fromDate, string $toSlotId, string $toDate, string $branchId, string $type): void
    {
        DB::transaction(function () use ($fromSlotId, $fromDate, $toSlotId, $toDate, $branchId, $type) {
            if ($fromSlotId === $toSlotId && $fromDate === $toDate) {
                return;
            }
            $this->book($toSlotId, $toDate, $branchId, $type);
            $this->release($fromSlotId, $fromDate);
        });
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
            'id' => (string) Str::uuid(), 'delivery_slot_id' => $slot->id, 'slot_date' => $day->toDateString(),
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
