<?php

namespace App\Domain\Fulfillment\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Branches\Models\Branch;
use App\Domain\Branches\Models\DeliverySlot;
use App\Domain\Branches\Services\SlotService;
use App\Domain\Fulfillment\Enums\DeliveryStatus as DS;
use App\Domain\Fulfillment\Models\Delivery;
use App\Domain\Fulfillment\Models\DeliverySlotOffer;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessControl;
use App\Domain\Marketing\Support\QuietHours;
use App\Domain\Notifications\Services\NotificationService;
use App\Domain\Notifications\Services\PushNotifier;
use App\Domain\Operations\Services\StaffAlerts;
use App\Domain\Orders\Enums\FulfillmentStatus as FS;
use App\Domain\Orders\Models\Order;
use App\Support\BusinessTime;
use App\Support\DomainException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * "We could not deliver. Choose a new time": after a failed delivery the customer is offered a few free delivery slots and picks one in the app or on
 * the web order page, instead of waiting for a phone call (docs/guides/DELIVERY_FOLLOW_UPS.md).
 *
 *  - WHEN the system offers: the switch is on, the delivery failed for a reason a new time can fix (the customer was not there or could not be reached, the
 *    address was wrong, the driver had no access), it is the first, second or third failed attempt, the order has a customer account, and the branch has
 *    free slots. Otherwise nothing changes and the old "we will call you" path stays.
 *  - WHICH times: the earliest free delivery slots from the branch's own slot list, one per day first (so the customer sees different days), at least three
 *    hours ahead (the van must come back to the branch first). Capacity, booking cut-offs, opening hours, holidays and closures are the checkout's own rules
 *    (SlotService), so an offer can never name a time the checkout would refuse.
 *  - THE CHOICE: the customer's pick goes through the same move as a dashboard reschedule (the booking moves atomically: a full slot leaves everything as it was),
 *    the delivery goes back to "rescheduled" for dispatch, and the branch and the driver are told. A pick is idempotent (same Idempotency-Key = same answer),
 *    a second pick, a stale time, another customer's order and an offer that ran out are refused with a plain message.
 *  - THE BRANCH can offer times itself from the order window ("Offer new times"); that replaces an open offer.
 *  - Quiet hours (22:00 to 08:00 Qatar time): an offer made at night is kept and the customer is told at 08:00; its lifetime starts when they are told.
 * Every step is audited. Nothing here changes money: cash due stays exactly as it was.
 */
final class SlotOffers
{
    /** Failure reasons a new time can fix: the customer was not there, could not be reached, gave a wrong address, or the driver could not get in. */
    public const OFFER_REASONS = ['customer_absent', 'customer_unreachable', 'wrong_address', 'access_denied'];

    /** After this many failed attempts the system stops offering by itself: the branch phones the customer (a manual offer is still possible). */
    public const MAX_FAILED_ATTEMPTS = 3;

    /** How many days ahead the system looks for free slots. */
    public const HORIZON_DAYS = 14;

    /** A new time starts at least this long from now: the goods have to come back to the branch first. */
    public const LEAD_MINUTES = 180;

    /** The most times a branch can put in one offer. */
    public const MAX_MANUAL = 5;

    public const NOTIFY_EVENT = 'delivery.slots_offered';

    public const STAFF_EVENT = 'staff.slot_chosen';

    /** Nobody is messaged in the night (the same hours as every other automatic message). */
    private const QUIET_FROM = '22:00';

    private const QUIET_TO = '08:00';

    public function __construct(
        private readonly SlotService $slots,
        private readonly DeliveryFollowUpSettings $config,
        private readonly AuditLogger $audit,
        private readonly NotificationService $notify,
        private readonly StaffAlerts $staff,
        private readonly AccessControl $acl,
    ) {}

    // ── Making an offer ──────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * The system's offer for a delivery that has just failed. Returns the offer (open: the customer is told, or will be at 08:00), a closed record with
     * `closed_reason = no_slots` when the branch had nothing free to offer (so the branch sees why nobody was asked), or null when the rules above say
     * "no offer". Safe to call twice: a delivery with an open offer gets no second one.
     */
    public function offerAfterFailure(string $deliveryId): ?DeliverySlotOffer
    {
        $d = Delivery::query()->with(['fulfillment.order'])->find($deliveryId);
        if ($d === null || ! $this->mayOfferByItself($d)) {
            return null;
        }
        $offer = $this->create($d, null, 'system', $this->pickOptions(Branch::findOrFail($d->branch_id), $this->config->offerCount(), $d->fulfillment->delivery_slot_id, $d->fulfillment->slot_date?->toDateString()));
        if ($offer === null) {
            return null;
        }
        $this->deliver($offer->id);
        $this->refreshCard($offer->order_id);

        return $offer->fresh();
    }

    /**
     * The branch offers the customer these times (the order window's "Offer new times"). Needs deliveries.assign at the branch; the delivery must be
     * waiting for a new plan (failed). Replaces an open offer. Every time must be free right now.
     *
     * @param  list<array{slot_id: string, date: string}>  $options  one to five times
     */
    public function propose(Delivery $delivery, User $staff, array $options): DeliverySlotOffer
    {
        if (! $this->acl->allows($staff, 'deliveries.assign', $delivery->branch_id)) {
            throw DomainException::make('branch_forbidden', 403);
        }
        $options = $this->normalise($options);
        if ($options === [] || count($options) > self::MAX_MANUAL) {
            throw FollowUpError::make('offer_choose_times', 422, ['max' => self::MAX_MANUAL]);
        }
        $offer = DB::transaction(function () use ($delivery, $staff, $options) {
            $d = Delivery::query()->whereKey($delivery->id)->lockForUpdate()->with('fulfillment.order')->firstOrFail();
            if ($d->status !== DS::Failed || $d->fulfillment->status === FS::Cancelled || $d->fulfillment->order->user_id === null) {
                throw FollowUpError::make($d->fulfillment->order->user_id === null ? 'offer_no_account' : 'offer_needs_failed_delivery', 409);
            }
            $free = collect($this->describe(Branch::findOrFail($d->branch_id), $options))->every(fn (array $o) => $o['available']);
            if (! $free) {
                throw FollowUpError::make('offer_time_taken', 422);
            }
            $this->closeOpen($d->id, 'replaced', $staff);

            return $this->create($d, $staff, 'branch', $options) ?? throw FollowUpError::make('offer_choose_times', 422, ['max' => self::MAX_MANUAL]);
        });
        $this->deliver($offer->id);
        $this->refreshCard($offer->order_id);

        return $offer->fresh();
    }

    /** The customer's live order card says "choose a new time" (or "your new time is booked") from now on: tell the phones. Never throws into the caller. */
    private function refreshCard(string $orderId): void
    {
        rescue(fn () => app(PushNotifier::class)->refreshOrder($orderId), report: true);
    }

    private function mayOfferByItself(Delivery $d): bool
    {
        $f = $d->fulfillment;
        $order = $f?->order;

        return $this->config->offersEnabled() && $f !== null && $order !== null && $order->user_id !== null
            && $d->status === DS::Failed && $d->mode === 'internal' && $f->type === 'delivery' && $f->status !== FS::Cancelled
            && in_array($d->failure_reason, self::OFFER_REASONS, true) && $d->attempt_count <= self::MAX_FAILED_ATTEMPTS
            && ! DeliverySlotOffer::query()->where('delivery_id', $d->id)->where('status', DeliverySlotOffer::OPEN)->exists();
    }

    /**
     * Writes the offer. An empty list of times is kept as a closed record (the branch is told why nobody was asked) and is not an offer.
     *
     * @param  list<array{slot_id: string, date: string}>  $options
     */
    private function create(Delivery $d, ?User $by, string $source, array $options): ?DeliverySlotOffer
    {
        $f = $d->fulfillment;
        $now = CarbonImmutable::now();
        $notifyAt = self::quietHours()->endAfter($now);
        if ($options === []) {
            DeliverySlotOffer::create(['delivery_id' => $d->id, 'order_fulfillment_id' => $f->id, 'order_id' => $f->order_id, 'branch_id' => $d->branch_id,
                'user_id' => $f->order->user_id, 'offered_by' => $by?->id, 'source' => $source, 'status' => DeliverySlotOffer::CANCELLED, 'failure_reason' => $d->failure_reason,
                'options' => [], 'expires_at' => $now, 'closed_at' => $now, 'closed_reason' => 'no_slots']);
            $this->audit->log('delivery.slots_none', $d, [], ['reason' => $d->failure_reason], ['order' => $f->order->number], branchId: $d->branch_id);

            return null;
        }
        $offer = DeliverySlotOffer::create(['delivery_id' => $d->id, 'order_fulfillment_id' => $f->id, 'order_id' => $f->order_id, 'branch_id' => $d->branch_id,
            'user_id' => $f->order->user_id, 'offered_by' => $by?->id, 'source' => $source, 'status' => DeliverySlotOffer::OPEN, 'failure_reason' => $d->failure_reason,
            'options' => $options, 'notify_at' => $notifyAt, 'expires_at' => $notifyAt->addHours($this->config->offerHours())]);
        $this->audit->log('delivery.slots_offered', $d, [], ['offer' => $offer->id, 'source' => $source, 'options' => $options, 'expires_at' => $offer->expires_at->toIso8601String()],
            ['order' => $f->order->number], branchId: $d->branch_id, actor: $by);

        return $offer;
    }

    // ── Choosing ─────────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * The customer picks one of the offered times. Returns the offer and whether this was a repeat of the same request (same key: nothing is done twice).
     *
     * @return array{offer: DeliverySlotOffer, replayed: bool}
     *
     * @throws DomainException offer_not_found (404), offer_expired, offer_already_chosen, offer_closed (409), offer_time_not_offered (422), slot_full (409), slot_unavailable (422)
     */
    public function choose(Order $order, User $customer, string $offerId, string $slotId, string $date, string $key): array
    {
        $offer = DeliverySlotOffer::query()->where('order_id', $order->id)->where('user_id', $customer->id)->find($offerId);
        if ($order->user_id !== $customer->id || $offer === null) {
            throw FollowUpError::make('offer_not_found', 404);
        }
        // A time that ran out is written down first (and alone), so the refusal below cannot roll that back.
        if ($offer->status === DeliverySlotOffer::OPEN && ! $offer->expires_at->isFuture()) {
            $this->expire($offer->id);
        }

        return DB::transaction(function () use ($offer, $customer, $slotId, $date, $key): array {
            $o = DeliverySlotOffer::query()->whereKey($offer->id)->lockForUpdate()->firstOrFail();
            if ($o->status === DeliverySlotOffer::CHOSEN) {
                if ($o->chosen_key === $key && (string) $o->chosen_slot_id === $slotId && $o->chosen_date?->toDateString() === $date) {
                    return ['offer' => $o, 'replayed' => true];
                }
                throw FollowUpError::make('offer_already_chosen', 409);
            }
            if ($o->status === DeliverySlotOffer::EXPIRED) {
                throw FollowUpError::make('offer_expired', 409);
            }
            if ($o->status !== DeliverySlotOffer::OPEN) {
                throw FollowUpError::make('offer_closed', 409);
            }
            if (! collect($o->options)->contains(fn (array $t) => $t['slot_id'] === $slotId && $t['date'] === $date)) {
                throw FollowUpError::make('offer_time_not_offered', 422);
            }
            // Written first: the delivery's own move (below) closes any offer still open, and this one must stay "chosen".
            $o->forceFill(['status' => DeliverySlotOffer::CHOSEN, 'chosen_at' => now(), 'chosen_slot_id' => $slotId, 'chosen_date' => $date, 'chosen_key' => $key])->save();
            $delivery = Delivery::query()->with('fulfillment.order:id,number')->findOrFail($o->delivery_id);
            app(DeliveryService::class)->rescheduleForCustomer($delivery, $customer, $slotId, $date);
            $this->audit->log('delivery.slot_chosen', $delivery, ['status' => DS::Failed->value], ['offer' => $o->id, 'slot_id' => $slotId, 'date' => $date], ['order' => $delivery->fulfillment->order->number ?? null],
                branchId: $delivery->branch_id, actor: $customer);
            $id = $o->id;
            $orderId = $o->order_id;
            DB::afterCommit(function () use ($id, $orderId) {
                rescue(fn () => $this->tellBranch($id), report: true);
                $this->refreshCard($orderId);
            });

            return ['offer' => $o->fresh(), 'replayed' => false];
        });
    }

    // ── Closing ──────────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Time ran out for one offer (the scheduled job, or the first touch after the deadline). Written once; a customer's pick in the same moment wins.
     * Returns whether this call closed it.
     */
    public function expire(string $offerId): bool
    {
        $n = DeliverySlotOffer::query()->whereKey($offerId)->where('status', DeliverySlotOffer::OPEN)->where('expires_at', '<=', now())
            ->update(['status' => DeliverySlotOffer::EXPIRED, 'closed_at' => now(), 'closed_reason' => 'expired', 'updated_at' => now()]);
        if ($n > 0) {
            $offer = DeliverySlotOffer::query()->find($offerId);
            $this->audit->log('delivery.slots_expired', 'DeliverySlotOffer', [], ['offer' => $offerId], ['order_id' => $offer?->order_id], branchId: $offer?->branch_id);
            if ($offer !== null) {
                $this->refreshCard($offer->order_id); // the card goes back to "we will call you"
            }
        }

        return $n > 0;
    }

    /** The branch arranged something else (another time, a return, a new try): the open offer is no longer the plan. */
    public function closeOpen(string $deliveryId, string $reason, ?User $actor = null): int
    {
        $offers = DeliverySlotOffer::query()->where('delivery_id', $deliveryId)->where('status', DeliverySlotOffer::OPEN)->get(['id', 'branch_id']);
        $n = 0;
        foreach ($offers as $offer) {
            $n += DeliverySlotOffer::query()->whereKey($offer->id)->where('status', DeliverySlotOffer::OPEN)
                ->update(['status' => DeliverySlotOffer::CANCELLED, 'closed_at' => now(), 'closed_reason' => $reason, 'updated_at' => now()]);
            $this->audit->log('delivery.slots_closed', 'DeliverySlotOffer', [], ['offer' => $offer->id, 'reason' => $reason], branchId: $offer->branch_id, actor: $actor);
        }

        return $n;
    }

    /**
     * The scheduled job (`deliveries:process-slot-offers`, every five minutes): tells the customers whose offer was held back for the night, and closes the
     * offers whose time ran out. Safe to run twice at once.
     *
     * @return array{told: int, expired: int}
     */
    public function processDue(): array
    {
        $told = 0;
        $due = DeliverySlotOffer::query()->where('status', DeliverySlotOffer::OPEN)->whereNull('notified_at')->where('notify_at', '<=', now())->orderBy('notify_at')->orderBy('id')->limit(200)->pluck('id');
        foreach ($due as $id) {
            $told += $this->deliver((string) $id) ? 1 : 0;
        }
        $expired = 0;
        $late = DeliverySlotOffer::query()->where('status', DeliverySlotOffer::OPEN)->where('expires_at', '<=', now())->orderBy('expires_at')->orderBy('id')->limit(200)->pluck('id');
        foreach ($late as $id) {
            $expired += $this->expire((string) $id) ? 1 : 0;
        }

        return ['told' => $told, 'expired' => $expired];
    }

    // ── Telling people ───────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Tells the customer, once (the claim on `notified_at` is the lock: two workers or a repeated call send one message). Held back until the quiet
     * hours are over. Returns whether THIS call sent it.
     */
    public function deliver(string $offerId): bool
    {
        $claimed = DeliverySlotOffer::query()->whereKey($offerId)->where('status', DeliverySlotOffer::OPEN)->whereNull('notified_at')->where('notify_at', '<=', now())
            ->update(['notified_at' => now(), 'updated_at' => now()]);
        if ($claimed === 0) {
            return false;
        }
        $offer = DeliverySlotOffer::query()->with(['order:id,number,user_id', 'customer'])->findOrFail($offerId);
        $customer = $offer->customer;
        if ($customer === null || $offer->order === null) {
            return false;
        }
        $until = fn (string $locale) => (string) BusinessTime::local($offer->expires_at)?->locale($locale)->translatedFormat('D j M, H:i');
        $mine = $customer->preferredLocale();
        $this->notify->notify($customer, self::NOTIFY_EVENT, [
            'order_number' => $offer->order->number, 'until' => $until($mine), 'deep_link' => 'albahar://orders/'.$offer->order_id, 'reference' => $offer->order_id,
            'by_locale' => collect((array) config('platform.supported_locales', ['en']))->mapWithKeys(fn (string $l) => [$l => ['until' => $until($l)]])->all(),
        ]);

        return true;
    }

    /** The branch and the driver learn the customer chose a time: the delivery is back in the queue, and the push tells them when and which order. */
    private function tellBranch(string $offerId): void
    {
        $offer = DeliverySlotOffer::query()->with(['order:id,number', 'delivery:id,driver_id,branch_id'])->find($offerId);
        if ($offer === null || $offer->order === null || $offer->chosen_slot_id === null || $offer->chosen_date === null) {
            return;
        }
        $people = $this->staff->recipients($offer->branch_id, ['deliveries.assign']);
        $driver = $offer->delivery?->driver_id ? User::query()->find($offer->delivery->driver_id) : null;
        if ($driver !== null && $driver->isActive() && ! $people->contains('id', $driver->id)) {
            $people->push($driver);
        }
        $slot = DeliverySlot::query()->find($offer->chosen_slot_id);
        $number = $offer->order->number;
        $day = $offer->chosen_date->toDateString();
        $this->staff->send($people, self::STAFF_EVENT,
            fn (string $locale) => [
                'title' => __('delivery_followup.alert.chosen_title', ['number' => $number], $locale),
                'body' => __('delivery_followup.alert.chosen_body', ['number' => $number, 'time' => self::when($day, (string) $slot?->starts_at, (string) $slot?->ends_at, $locale)], $locale),
            ],
            ['order_id' => $offer->order_id, 'number' => $number, 'branch_id' => $offer->branch_id], route('admin.orders.show', $offer->order_id, false), push: ! self::quietNow());
    }

    // ── Which times ──────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * The free delivery slots the system would offer: the earliest free slot of each day first, then more of the earliest days, in time order.
     *
     * @return list<array{slot_id: string, date: string}>
     */
    public function pickOptions(Branch $branch, int $count, ?string $exceptSlotId = null, ?string $exceptDate = null): array
    {
        $now = BusinessTime::now();
        $earliest = $now->addMinutes(self::LEAD_MINUTES);
        $free = [];
        foreach ($this->slots->availableRange($branch, 'delivery', $now->toDateString(), $now->addDays(self::HORIZON_DAYS)->toDateString(), onlyAvailable: true) as $day) {
            foreach ($day['slots'] as $slot) {
                $start = CarbonImmutable::parse($day['date'].' '.$slot['starts_at'], BusinessTime::zone());
                if ($slot['available'] < 1 || $start->lessThan($earliest) || ($slot['id'] === $exceptSlotId && $day['date'] === $exceptDate)) {
                    continue;
                }
                $free[] = ['slot_id' => (string) $slot['id'], 'date' => (string) $day['date']];
            }
        }
        $picked = [];
        $days = [];
        foreach ($free as $i => $t) {
            if (count($picked) < $count && ! isset($days[$t['date']])) {
                $picked[$i] = $t;
                $days[$t['date']] = true;
            }
        }
        foreach ($free as $i => $t) {
            if (count($picked) < $count && ! isset($picked[$i])) {
                $picked[$i] = $t;
            }
        }
        ksort($picked);

        return array_values($picked);
    }

    /**
     * What each time of an offer is right now: its hours, and whether a place is still free (the checkout's rules: capacity, booking cut-off, opening hours).
     *
     * @param  list<array{slot_id: string, date: string}>  $options
     * @return list<array{slot_id: string, date: string, starts_at: string, ends_at: string, available: bool, left: int}>
     */
    public function describe(Branch $branch, array $options): array
    {
        if ($options === []) {
            return [];
        }
        $dates = array_column($options, 'date');
        $index = [];
        foreach ($this->slots->availableRange($branch, 'delivery', min($dates), max($dates)) as $day) {
            foreach ($day['slots'] as $s) {
                $index[$day['date']][$s['id']] = $s;
            }
        }
        $known = DeliverySlot::query()->whereIn('id', array_column($options, 'slot_id'))->get()->keyBy('id');
        $out = [];
        foreach ($options as $t) {
            $row = $index[$t['date']][$t['slot_id']] ?? null;
            $slot = $known->get($t['slot_id']);
            $left = (int) ($row['available'] ?? 0);
            $out[] = ['slot_id' => $t['slot_id'], 'date' => $t['date'], 'starts_at' => substr((string) ($row['starts_at'] ?? $slot?->starts_at ?? ''), 0, 5),
                'ends_at' => substr((string) ($row['ends_at'] ?? $slot?->ends_at ?? ''), 0, 5), 'available' => $left > 0, 'left' => $left];
        }

        return $out;
    }

    /**
     * Every open or recent offer of these deliveries, newest first for each delivery. One query however many deliveries (the dashboard lists and the order page).
     *
     * @param  list<string>  $deliveryIds
     * @return Collection<string, DeliverySlotOffer> the latest offer of each delivery that was not simply replaced, by delivery id
     */
    public function latestFor(array $deliveryIds): Collection
    {
        if ($deliveryIds === []) {
            return new Collection;
        }

        return DeliverySlotOffer::query()->whereIn('delivery_id', $deliveryIds)
            ->orderByDesc('created_at')->orderByDesc('id')->get()->unique('delivery_id')->keyBy('delivery_id');
    }

    /** A time in words, in the language asked for: "Tue 25 Nov, 14:00-16:00" (without the hours when they are not known). */
    public static function when(string $date, string $from, string $to, string $locale): string
    {
        $day = CarbonImmutable::parse($date, BusinessTime::zone())->locale($locale)->translatedFormat('D j M');

        return $from === '' ? $day : $day.', '.substr($from, 0, 5).'-'.substr($to, 0, 5);
    }

    /** Does this offer belong to the delivery's CURRENT failure? An older offer (before the delivery failed again) says nothing about what is going on now. */
    public static function belongsToFailure(DeliverySlotOffer $offer, Delivery $d): bool
    {
        return $d->failed_at === null || ! $offer->created_at->lessThan($d->failed_at->startOfSecond());
    }

    /**
     * One plain line per delivery for the dashboard (the order window, "Needs attention", the live board, the deliveries list), in the dashboard's language:
     * what the customer was offered and what came of it. A delivery with nothing worth saying has no entry.
     *
     * `state`: waiting (told, or to be told at 08:00), chosen, expired, none (no free time to offer).
     *
     * @param  iterable<Delivery>  $deliveries  with `failed_at` loaded
     * @return array<string, array{state: string, text: string}> by delivery id
     */
    public function staffNotes(iterable $deliveries): array
    {
        $byId = [];
        foreach ($deliveries as $d) {
            $byId[$d->id] = $d;
        }
        $latest = $this->latestFor(array_keys($byId));
        $slots = DeliverySlot::query()->whereIn('id', $latest->pluck('chosen_slot_id')->filter()->unique()->values()->all())->get()->keyBy('id');
        $locale = app()->getLocale();
        $out = [];
        foreach ($latest as $id => $offer) {
            $d = $byId[$id] ?? null;
            if ($d === null || ! self::belongsToFailure($offer, $d)) {
                continue;
            }
            $until = (string) BusinessTime::local($offer->expires_at)?->locale($locale)->translatedFormat('D j M, H:i');
            $slot = $offer->chosen_slot_id !== null ? $slots->get($offer->chosen_slot_id) : null;
            $entry = match (true) {
                $offer->status === DeliverySlotOffer::CHOSEN && $offer->chosen_date !== null => ['chosen',
                    __('delivery_followup.staff.chosen', ['time' => self::when($offer->chosen_date->toDateString(), (string) $slot?->starts_at, (string) $slot?->ends_at, $locale)])],
                $offer->effectiveStatus() === DeliverySlotOffer::EXPIRED => ['expired', __('delivery_followup.staff.expired')],
                $offer->status === DeliverySlotOffer::OPEN => ['waiting', $offer->notified_at === null
                    ? __('delivery_followup.staff.waiting_to_tell', ['at' => (string) BusinessTime::local($offer->notify_at)?->format('H:i'), 'until' => $until])
                    : __('delivery_followup.staff.waiting', ['until' => $until])],
                $offer->closed_reason === 'no_slots' => ['none', __('delivery_followup.staff.none')],
                default => null,
            };
            if ($entry !== null) {
                $out[$id] = ['state' => $entry[0], 'text' => $entry[1]];
            }
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $options
     * @return list<array{slot_id: string, date: string}> clean, without repeats
     */
    private function normalise(array $options): array
    {
        $out = [];
        foreach ($options as $t) {
            $slot = (string) ($t['slot_id'] ?? '');
            $date = (string) ($t['date'] ?? '');
            if ($slot !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1) {
                $out[$slot.'|'.$date] = ['slot_id' => $slot, 'date' => $date];
            }
        }

        return array_values($out);
    }

    /** The hours of the night in which nobody is messaged: 22:00 to 08:00 shop time. */
    public static function quietHours(): QuietHours
    {
        return new QuietHours(self::QUIET_FROM, self::QUIET_TO);
    }

    /** Is it night now? Alerts that nobody waits on minute by minute go to the inbox only, without waking a phone. */
    public static function quietNow(): bool
    {
        return self::quietHours()->contains(CarbonImmutable::now());
    }
}
