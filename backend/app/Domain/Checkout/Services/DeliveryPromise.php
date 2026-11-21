<?php

namespace App\Domain\Checkout\Services;

use App\Domain\Branches\Models\Branch;
use App\Domain\Branches\Models\BranchDeliveryZone;
use App\Domain\Branches\Models\BranchHour;
use App\Domain\Branches\Services\BranchHoursService;
use App\Domain\Branches\Services\SlotService;
use App\Domain\Checkout\Support\Assembly;
use App\Domain\Settings\Services\Settings;
use App\Support\BusinessTime;
use Carbon\CarbonImmutable;

/**
 * The date a customer is promised BEFORE they pay (docs/guides/DELIVERY_PROMISE_ASSEMBLY_AND_SHARING.md). One parcel (one branch) at a time; the
 * basket-level picture (which branches, one date per parcel, "all by") is built by BasketPromise and the quote from these parcels.
 *
 * The rules, in the order they apply, and they are the only rules:
 *  1. Honest first. A parcel that is not in stock at its branch, or a branch that is closed for now, gets NO date: status `unconfirmed` and the words
 *     "We will confirm your delivery date". A date is never invented, and an unconfirmed parcel never blocks buying.
 *  2. Lead time. Every product may have lead_time_days (made to order), and assembly adds the dashboard's "extra days" (delivery only). The earliest date is
 *     today + the longest of them. With the default 0 nothing moves.
 *  3. A branch that publishes slots for this kind of order (delivery or pickup): the date is the FIRST slot with a free place from the earliest date on,
 *     read through the very same SlotService the checkout date picker reads (so the promise can never name a day or a time the picker cannot offer:
 *     a test keeps the two equal). The slot's own booking cut-off decides about same-day.
 *  4. A branch with no slots, delivery: the earliest open day. Today only when the order is placed before the same-day cut-off (Settings, Qatar time) and
 *     the branch is still open long enough for the drive (the zone's estimated minutes, or the default preparation + drive of the live order card):
 *     "Delivery today before 9 pm, if you order by 3 pm". Otherwise the next open day, skipping closed days and holidays.
 *  5. A branch with no slots, pickup: ready = now + the branch's preparation time inside an opening window (rounded up to 15 minutes), else the
 *     opening of the next open day: "Pick up today from 4:15 pm".
 * A branch with no usable opening hours is treated as open all day (the same fallback as the operations clock); it never invents a closing time.
 * Times are Qatar time. All text is in the request's language (lang/{en,ar}/promise.php) and the structured values come along for the clients.
 */
class DeliveryPromise
{
    /** The slot picker offers today ... today + 29 days (BranchController::slotsResponse); the promise never looks further. */
    public const HORIZON_DAYS = 29;

    /** How many days the hours-based search walks before it gives up and says "we will confirm". */
    private const SEARCH_DAYS = 14;

    public function __construct(
        private readonly SlotService $slots,
        private readonly BranchHoursService $hours,
        private readonly Settings $settings,
    ) {}

    /** The dashboard switch (Settings > Checkout & fees > Delivery date promise). Off: no date anywhere, checkout is exactly as before. */
    public function enabled(): bool
    {
        return (bool) $this->settings->get('checkout.promise_enabled', true);
    }

    /**
     * Days the branch needs before these lines can leave: the longest product lead time, plus the assembly days when a delivery line has assembly.
     *
     * @param  list<array{lead_days?: int, assembly?: bool}>  $lines
     */
    public function leadDays(array $lines, string $type): int
    {
        $days = 0;
        $assembly = false;
        foreach ($lines as $l) {
            $days = max($days, (int) ($l['lead_days'] ?? 0));
            $assembly = $assembly || (bool) ($l['assembly'] ?? false);
        }

        return $days + ($type === 'delivery' && $assembly ? Assembly::extraDays() : 0);
    }

    /** The first calendar day (YYYY-MM-DD, Qatar) these lines can be scheduled on: today + their lead time. The checkout refuses an earlier slot date. */
    public function earliestDate(array $lines, string $type, ?CarbonImmutable $now = null): string
    {
        return ($now ?? BusinessTime::now())->startOfDay()->addDays($this->leadDays($lines, $type))->toDateString();
    }

    /**
     * The promise for one parcel.
     *
     * @param  list<array{variant_id: string, quantity: int, lead_days?: int, assembly?: bool}>  $lines
     * @param  ?string  $blocked  the reason the parcel cannot be promised before any date is looked at (`out_of_stock`, `item_unavailable`)
     * @param  ?array{id: string, date: string, starts_at: string, ends_at: string}  $chosen  the slot the customer already picked (the checkout): shown as is
     * @return array<string, mixed>
     */
    public function parcel(Branch $branch, string $type, array $lines, ?BranchDeliveryZone $zone = null, ?string $blocked = null, ?CarbonImmutable $now = null, ?array $chosen = null): array
    {
        $now ??= BusinessTime::now();
        $branch->loadMissing(['hours', 'holidays']);
        $lead = $this->leadDays($lines, $type);
        $base = [
            'branch' => ['id' => $branch->id, 'name' => $branch->localized('name')], 'type' => $type, 'lead_days' => $lead,
            'items' => array_map(fn (array $l) => ['variant_id' => $l['variant_id'], 'quantity' => (int) $l['quantity']], $lines),
        ];

        if ($blocked !== null) {
            return $this->unconfirmed($base, $type, $blocked, $branch);
        }
        if (! $branch->isActive() || $branch->opening_status === 'temporarily_closed') {
            return $this->unconfirmed($base, $type, 'branch_closed', $branch);
        }
        if (($type === 'delivery' && ! $branch->delivery_enabled) || ($type === 'pickup' && ! $branch->pickup_enabled)) {
            return $this->unconfirmed($base, $type, 'not_offered', $branch);
        }

        $earliest = $now->startOfDay()->addDays($lead);
        if ($chosen !== null) {
            return $this->scheduled($base, $type, 'slot', $chosen['date'], $now, from: $chosen['starts_at'], to: $chosen['ends_at'], slotId: $chosen['id']);
        }
        if ($this->slots->hasSlots($branch, $type)) {
            return $this->fromSlots($base, $branch, $type, $earliest, $now);
        }

        return $type === 'delivery' ? $this->deliveryByHours($base, $branch, $zone, $earliest, $now) : $this->pickupByHours($base, $branch, $earliest, $now);
    }

    /**
     * One line for the whole basket from its parcels.
     *
     * @param  list<array<string, mixed>>  $parcels  results of parcel()
     * @return array{status: string, message: string, detail: ?string, all_by: ?array{date: string, label: string}, is_split: bool}
     */
    public function summarize(array $parcels, string $type, bool $shortage = false): array
    {
        $scheduled = array_values(array_filter($parcels, fn (array $p) => $p['status'] === 'scheduled'));
        $split = count($parcels) > 1;
        $allScheduled = $parcels !== [] && count($scheduled) === count($parcels) && ! $shortage;
        $latest = null;
        foreach ($scheduled as $p) {
            $latest = $latest === null || $p['date'] > $latest ? $p['date'] : $latest;
        }

        if ($parcels !== [] && ! $split && ! $shortage) {
            return ['status' => $parcels[0]['status'], 'message' => $parcels[0]['message'], 'detail' => $parcels[0]['detail'] ?? null,
                'all_by' => $latest !== null ? ['date' => $latest, 'label' => $this->dayLabel($latest)] : null, 'is_split' => false];
        }
        if ($allScheduled && $latest !== null) {
            $key = $type === 'pickup' ? 'all_pickup_by' : 'all_delivery_by';

            return ['status' => 'scheduled', 'message' => __('promise.'.$key, ['day' => $this->dayLabel($latest)]), 'detail' => null,
                'all_by' => ['date' => $latest, 'label' => $this->dayLabel($latest)], 'is_split' => true];
        }
        if ($latest !== null) {
            $key = $type === 'pickup' ? 'partial_pickup' : 'partial_delivery';

            return ['status' => 'partial', 'message' => __('promise.'.$key, ['day' => $this->dayLabel($latest)]), 'detail' => null, 'all_by' => null, 'is_split' => $split];
        }

        return ['status' => 'unconfirmed', 'message' => __('promise.'.($type === 'pickup' ? 'unconfirmed_pickup' : 'unconfirmed_delivery')),
            'detail' => $parcels[0]['detail'] ?? null, 'all_by' => null, 'is_split' => $split];
    }

    /** "today", "tomorrow" or the weekday and date ("Sun 22 Nov" / «يوم الأحد 22 نوفمبر») of a YYYY-MM-DD, as a customer reads it inside a sentence. */
    public function dayLabel(string $date, ?CarbonImmutable $now = null): string
    {
        $today = ($now ?? BusinessTime::now())->startOfDay();
        $day = CarbonImmutable::parse($date, BusinessTime::zone())->startOfDay();
        if ($day->equalTo($today)) {
            return __('promise.today');
        }
        if ($day->equalTo($today->addDay())) {
            return __('promise.tomorrow');
        }
        $locale = app()->getLocale();

        return __('promise.on_date', ['date' => $day->locale($locale)->isoFormat($locale === 'ar' ? 'dddd D MMM' : 'ddd D MMM')]);
    }

    /** "9 pm", "4:15 pm" (English) or "9 م", "4:15 م" (Arabic) for a moment in Qatar time. */
    public function timeLabel(CarbonImmutable $at): string
    {
        $t = $at->locale(app()->getLocale());

        return $t->minute === 0 ? $t->isoFormat('h a') : $t->isoFormat('h:mm a');
    }

    /** The same for an "HH:MM" string (a slot's start or end). */
    public function clockLabel(string $hhmm, CarbonImmutable $day): string
    {
        return $this->timeLabel($day->setTimeFromTimeString($hhmm));
    }

    // ── 3. slots ───────────────────────────────────────────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $base */
    private function fromSlots(array $base, Branch $branch, string $type, CarbonImmutable $earliest, CarbonImmutable $now): array
    {
        $last = $now->startOfDay()->addDays(self::HORIZON_DAYS);
        if ($earliest->greaterThan($last)) {
            return $this->unconfirmed($base, $type, 'no_date', $branch);
        }
        foreach ($this->slots->availableRange($branch, $type, $earliest->toDateString(), $last->toDateString(), true) as $day) {
            foreach ($day['slots'] as $slot) {
                if ($slot['available'] > 0) {
                    return $this->scheduled($base, $type, 'slot', $day['date'], $now, from: $slot['starts_at'], to: $slot['ends_at'], slotId: $slot['id']);
                }
            }
        }

        return $this->unconfirmed($base, $type, 'no_date', $branch);
    }

    // ── 4. delivery without slots ──────────────────────────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $base */
    private function deliveryByHours(array $base, Branch $branch, ?BranchDeliveryZone $zone, CarbonImmutable $earliest, CarbonImmutable $now): array
    {
        $eta = max(1, (int) ($zone?->estimated_minutes ?: ((int) $this->settings->get('orders.default_prep_minutes', 25) + (int) $this->settings->get('orders.default_delivery_minutes', 20))));
        $cutoff = $this->cutoff();
        $type = 'delivery';
        for ($i = 0; $i <= self::SEARCH_DAYS; $i++) {
            $day = $earliest->addDays($i);
            $windows = $this->openWindows($branch, $day);
            if ($windows === []) {
                continue;
            }
            if (! $day->isSameDay($now)) {
                return $this->scheduled($base, $type, 'day', $day->toDateString(), $now);
            }
            $closes = end($windows)[1];
            if ($now->greaterThan($day->setTimeFromTimeString($cutoff)) || $now->addMinutes($eta)->greaterThan($closes)) {
                continue; // too late to deliver today: the next open day
            }

            return $this->scheduled($base, $type, 'same_day', $day->toDateString(), $now, until: $closes->isSameDay($day) && $closes->format('H:i') !== '23:59' ? $closes : null, orderBy: $cutoff);
        }

        return $this->unconfirmed($base, $type, 'no_date', $branch);
    }

    // ── 5. pickup without slots ────────────────────────────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $base */
    private function pickupByHours(array $base, Branch $branch, CarbonImmutable $earliest, CarbonImmutable $now): array
    {
        $prep = max(0, (int) ($branch->pickup_preparation_minutes ?? 60));
        for ($i = 0; $i <= self::SEARCH_DAYS; $i++) {
            $day = $earliest->addDays($i);
            foreach ($this->openWindows($branch, $day) as [$opens, $closes]) {
                // Today the goods must be prepared first; on a later day they have been waiting since the order, so the pickup starts at opening.
                $ready = $day->isSameDay($now) ? $now->addMinutes($prep) : $opens;
                $ready = $ready->greaterThan($opens) ? $this->roundUp($ready) : $opens;
                if ($ready->lessThan($closes->subMinutes(15))) {
                    return $this->scheduled($base, 'pickup', 'ready', $day->toDateString(), $now, from: $ready->format('H:i'), readyAt: $ready);
                }
            }
        }

        return $this->unconfirmed($base, 'pickup', 'no_date', $branch);
    }

    // ── the shapes ─────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $base
     * @return array<string, mixed>
     */
    private function scheduled(array $base, string $type, string $kind, string $date, CarbonImmutable $now, ?string $from = null, ?string $to = null, ?string $slotId = null,
        ?CarbonImmutable $until = null, ?string $orderBy = null, ?CarbonImmutable $readyAt = null): array
    {
        $day = CarbonImmutable::parse($date, BusinessTime::zone())->startOfDay();
        $dayLabel = $this->dayLabel($date, $now);
        $detail = null;
        if ($kind === 'slot') {
            $message = __('promise.'.$type.'_slot', ['day' => $dayLabel, 'from' => $this->clockLabel((string) $from, $day), 'to' => $this->clockLabel((string) $to, $day)]);
        } elseif ($kind === 'same_day') {
            $message = $until !== null ? __('promise.delivery_today_before', ['time' => $this->timeLabel($until)]) : __('promise.delivery_today');
            $detail = __('promise.order_by', ['time' => $this->clockLabel((string) $orderBy, $day)]);
        } elseif ($kind === 'ready') {
            $isToday = $date === $now->toDateString();
            $message = $isToday ? __('promise.pickup_today', ['time' => $this->clockLabel((string) $from, $day)]) : __('promise.pickup_day', ['day' => $dayLabel, 'time' => $this->clockLabel((string) $from, $day)]);
        } else {
            $message = __('promise.delivery_day', ['day' => $dayLabel]);
        }

        return $base + [
            'status' => 'scheduled', 'reason' => null, 'kind' => $kind, 'date' => $date, 'label' => $dayLabel, 'message' => $message, 'detail' => $detail,
            'slot' => $slotId !== null ? ['id' => $slotId, 'starts_at' => $from, 'ends_at' => $to] : null,
            'from' => $from, 'until' => $until?->format('H:i'), 'order_by' => $orderBy, 'ready_at' => $readyAt !== null ? BusinessTime::iso($readyAt) : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $base
     * @return array<string, mixed>
     */
    private function unconfirmed(array $base, string $type, string $reason, Branch $branch): array
    {
        return $base + [
            'status' => 'unconfirmed', 'reason' => $reason, 'kind' => null, 'date' => null, 'label' => null,
            'message' => __('promise.'.($type === 'pickup' ? 'unconfirmed_pickup' : 'unconfirmed_delivery')),
            'detail' => __('promise.reason.'.$reason, ['branch' => $branch->localized('name')]),
            'slot' => null, 'from' => null, 'until' => null, 'order_by' => null, 'ready_at' => null,
        ];
    }

    // ── opening hours ──────────────────────────────────────────────────────────────────────────────────────────────

    private function cutoff(): string
    {
        $value = (string) $this->settings->get('checkout.same_day_cutoff', '15:00');

        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) === 1 ? $value : '15:00';
    }

    private function roundUp(CarbonImmutable $at): CarbonImmutable
    {
        $at = $at->setSecond(0);

        return $at->addMinutes((15 - $at->minute % 15) % 15);
    }

    /**
     * When the branch is open on a calendar day (Qatar time), holidays cut out: a list of [opens, closes]. A branch with no usable weekly hours has
     * no schedule to follow and counts as open all day (the operations clock does the same); then the window is the whole day.
     *
     * @return list<array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    private function openWindows(Branch $branch, CarbonImmutable $day): array
    {
        $day = $day->startOfDay();
        $scheduled = $branch->hours->contains(fn (BranchHour $h) => ! $h->is_closed && $h->opens_at !== null && $h->closes_at !== null);
        $windows = $scheduled ? $this->hours->windowsForDate($branch, $day) : [[$day, $day->setTime(23, 59)]];
        foreach ($branch->holidays as $holiday) {
            $from = CarbonImmutable::instance($holiday->starts_at)->setTimezone(BusinessTime::zone());
            $to = CarbonImmutable::instance($holiday->ends_at)->setTimezone(BusinessTime::zone());
            $next = [];
            foreach ($windows as [$a, $z]) {
                if ($to <= $a || $from >= $z) {
                    $next[] = [$a, $z];

                    continue;
                }
                if ($from > $a) {
                    $next[] = [$a, $from];
                }
                if ($to < $z) {
                    $next[] = [$to, $z];
                }
            }
            $windows = $next;
        }

        return array_values(array_filter($windows, fn (array $w) => $w[1] > $w[0]));
    }
}
