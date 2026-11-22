<?php

namespace App\Domain\Fulfillment\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Branches\Models\BranchEmployee;
use App\Domain\Branches\Services\SlotService;
use App\Domain\Fulfillment\Enums\DeliveryStatus as DS;
use App\Domain\Fulfillment\Models\Delivery;
use App\Domain\Fulfillment\Models\DeliveryAttempt;
use App\Domain\Fulfillment\Models\DeliveryDecline;
use App\Domain\Fulfillment\Models\DeliveryProof;
use App\Domain\Fulfillment\Models\DeliveryProvider;
use App\Domain\Fulfillment\Models\DeliveryProviderEvent;
use App\Domain\Fulfillment\Models\DeliveryStatusHistory;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessControl;
use App\Domain\Orders\Enums\FulfillmentStatus as FS;
use App\Domain\Orders\Models\OrderFulfillment;
use App\Domain\Orders\Services\OpsError;
use App\Domain\Orders\Services\OrderService;
use App\Domain\Payments\Models\CashCollection;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Services\CodSplit;
use App\Domain\Payments\Services\PaymentService;
use App\Support\BusinessTime;
use App\Support\DomainException;
use App\Support\Money;
use App\Support\Redactor;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Internal delivery (own drivers) and third-party courier deliveries share one model and status machine.
 * Fulfillment transitions are driven from here: picked_up → fulfillment dispatched (stock deducted),
 * out_for_delivery, delivered (requires proof when configured + COD collection), failed (attempt recorded),
 * rescheduled, returning → returned (stock re-added).
 */
class DeliveryService
{
    /** Proof rows that do not show the order was handed over: the photo of a failed attempt and a bare position. */
    private const NOT_A_PROOF = ['failed', 'location', 'assembly'];

    public function __construct(
        private readonly OrderService $orders,
        private readonly DeliveryProviderRegistry $providers,
        private readonly AccessControl $access,
        private readonly AuditLogger $audit,
        private readonly SlotService $slots,
    ) {}

    public function create(OrderFulfillment $f, User $staff, string $mode = 'internal', ?string $providerId = null, ?string $scheduledFor = null): Delivery
    {
        $this->authorize($staff, 'deliveries.create', $f->branch_id);
        if ($f->type !== 'delivery') {
            throw DomainException::make('invalid_state_transition', 409, ['from' => $f->type]);
        }
        if ($f->activeDelivery() && $f->activeDelivery()->status->isActive()) {
            throw DomainException::make('delivery_exists', 409);
        }
        $codAmount = CodSplit::expectedFor($f->order, $f)->toDecimal();
        // The customer's chosen slot is the default schedule for the delivery.
        $scheduledFor ??= $f->delivery_slot_id && $f->slot_date ? $this->slots->startsAt($f->delivery_slot_id, $f->slot_date->toDateString()) : null;
        $delivery = Delivery::create([
            'order_fulfillment_id' => $f->id, 'branch_id' => $f->branch_id, 'mode' => $mode,
            'delivery_provider_id' => $mode === 'provider' ? $providerId : null, 'status' => DS::Pending,
            'tracking_number' => 'ABD'.now()->format('ymd').Str::upper(Str::random(6)), 'quoted_fee' => $f->delivery_fee,
            'cod_amount' => $codAmount, 'requires_proof' => true, 'scheduled_for' => $scheduledFor ? BusinessTime::toUtc($scheduledFor) : null,
        ]);
        $this->log($delivery, null, DS::Pending, $staff, 'staff', 'created');

        if ($mode === 'provider') {
            $this->book($delivery);
        }
        $this->audit->log('delivery.created', $delivery, [], ['mode' => $mode, 'fulfillment' => $f->number], branchId: $f->branch_id, actor: $staff);

        return $delivery->fresh();
    }

    /** Delivery created by the system (auto dispatch), without a staff actor. */
    public function createSystem(OrderFulfillment $f): Delivery
    {
        $codAmount = CodSplit::expectedFor($f->order, $f)->toDecimal();
        $scheduledFor = $f->delivery_slot_id && $f->slot_date ? $this->slots->startsAt($f->delivery_slot_id, $f->slot_date->toDateString()) : null;
        $delivery = Delivery::create([
            'order_fulfillment_id' => $f->id, 'branch_id' => $f->branch_id, 'mode' => 'internal', 'status' => DS::Pending,
            'tracking_number' => 'ABD'.now()->format('ymd').Str::upper(Str::random(6)), 'quoted_fee' => $f->delivery_fee,
            'cod_amount' => $codAmount, 'requires_proof' => true, 'scheduled_for' => $scheduledFor ? BusinessTime::toUtc($scheduledFor) : null,
        ]);
        $this->log($delivery, null, DS::Pending, null, 'system', 'created automatically');

        return $delivery;
    }

    /** Auto dispatch: assigns an eligible driver without a staff actor (eligibility is still enforced). */
    public function assignSystem(Delivery $delivery, User $driver): Delivery
    {
        $isDriver = BranchEmployee::where('branch_id', $delivery->branch_id)->where('user_id', $driver->id)->where('is_active', true)->where('is_delivery_driver', true)->exists();
        if (! $isDriver || ! $driver->isActive()) {
            throw DomainException::make('driver_not_eligible', 422);
        }
        $delivery->update(['driver_id' => $driver->id, 'assigned_at' => now(), 'mode' => 'internal']);
        $this->transition($delivery, DS::Assigned, null, 'system', 'auto-assigned to '.$driver->name, system: true);

        return $delivery->fresh();
    }

    /** Create the courier booking; provider errors/timeouts are stored safely and can be retried. */
    public function book(Delivery $delivery): Delivery
    {
        $provider = DeliveryProvider::findOrFail($delivery->delivery_provider_id);
        $adapter = $this->providers->for($provider);
        if (! ($adapter->capabilities()['create_booking'] ?? false)) {
            throw DomainException::make('delivery_provider_unavailable', 422);
        }
        try {
            $res = $adapter->createBooking($delivery);
            $delivery->update(['provider_reference' => $res['reference'], 'actual_fee' => $res['fee'] ?? null,
                'booking_response' => Redactor::redact($res['raw'] ?? []), 'last_error' => null]);
            $this->transition($delivery, DS::Accepted, null, 'provider', 'booking_confirmed', system: true);
        } catch (Throwable $e) {
            $delivery->update(['last_error' => Str::limit($e instanceof ConnectionException ? 'Provider timeout — retry booking' : $e->getMessage(), 490)]);
            report($e);
        }

        return $delivery->fresh();
    }

    /**
     * Give the job to a driver, or take it from one and give it to another. Allowed while the goods are still at the branch (waiting,
     * assigned, accepted by the previous driver, or rescheduled after a failed attempt), never once a driver has picked them up.
     * One transaction under the parcel's and the delivery's row locks, so two dispatchers (or a double tap) cannot both win.
     * The same driver again changes nothing. A change of driver is written to the delivery's history and the audit log, and both drivers
     * are told (the old one: "this job is no longer yours").
     */
    public function assignDriver(Delivery $delivery, User $driver, User $staff): Delivery
    {
        $this->authorize($staff, 'deliveries.assign', $delivery->branch_id);
        $isDriver = BranchEmployee::where('branch_id', $delivery->branch_id)->where('user_id', $driver->id)->where('is_active', true)->where('is_delivery_driver', true)->exists();
        if (! $isDriver || ! $driver->isActive()) {
            throw DomainException::make('driver_not_eligible', 422);
        }

        return DB::transaction(function () use ($delivery, $driver, $staff) {
            [$f, $d] = $this->lockJob($delivery);
            $this->assertLive($f, $d);
            if (! in_array($d->status, [DS::Pending, DS::Assigned, DS::Accepted, DS::Rescheduled], true)) {
                throw DomainException::make('invalid_state_transition', 409, ['from' => __('status.'.$d->status->value)]);
            }
            if ($d->driver_id === $driver->id && in_array($d->status, [DS::Assigned, DS::Accepted], true)) {
                return $d; // the same driver again (a second tap): nothing to change, nobody to tell
            }
            $previous = $d->driver_id;
            $from = $d->status;
            if ($from === DS::Accepted) {
                // The driver who accepted gives the job up: back to the pool first, then to the new driver (Accepted never goes straight to Assigned).
                $this->transition($d, DS::Pending, $staff, 'staff', 'driver changed');
            }
            $d->update(['driver_id' => $driver->id, 'assigned_at' => now(), 'mode' => 'internal']);
            if ($d->status === DS::Assigned) {
                $this->log($d, DS::Assigned, DS::Assigned, $staff, 'staff', 'reassigned to '.$driver->name);
            } else {
                $this->transition($d, DS::Assigned, $staff, 'staff', ($previous ? 're' : '').'assigned to '.$driver->name);
            }
            $this->audit->log('delivery.assigned', $d, ['driver_id' => $previous, 'status' => $from->value], ['driver_id' => $driver->id], branchId: $d->branch_id, actor: $staff);
            if ($previous !== null && $previous !== $driver->id) {
                $number = (string) $f->order->number;
                DB::afterCommit(fn () => rescue(fn () => app(DriverJobAlerts::class)->removed($previous, $number), report: true));
            }

            return $d->fresh();
        });
    }

    /** The assigned driver (or a dispatcher) accepts the job before collecting it from the branch. */
    public function accept(Delivery $delivery, User $actor): Delivery
    {
        $this->authorizeDriverOrStaff($delivery, $actor);

        return DB::transaction(function () use ($delivery, $actor) {
            [$f, $d] = $this->lockJob($delivery);
            if ($d->status === DS::Accepted) {
                return $d; // the app asked twice
            }
            $this->assertLive($f, $d);
            if ($d->status !== DS::Assigned) {
                throw DomainException::make('invalid_state_transition', 409, ['from' => __('status.'.$d->status->value)]);
            }
            $this->transition($d, DS::Accepted, $actor, $this->source($actor), 'accepted by '.$actor->name);
            $this->audit->log('delivery.accepted', $d, ['status' => DS::Assigned->value], ['status' => DS::Accepted->value], branchId: $d->branch_id, actor: $actor);

            return $d->fresh();
        });
    }

    /** Driver picked the goods up from the branch → fulfillment dispatched (stock deducted). Refused while the order is cancelled or a cancellation waits for a manager. */
    public function pickUp(Delivery $delivery, User $actor): Delivery
    {
        $this->authorizeDriverOrStaff($delivery, $actor);

        return DB::transaction(function () use ($delivery, $actor) {
            [$f, $d] = $this->lockJob($delivery);
            if ($d->status === DS::PickedUp) {
                return $d; // the app asked twice
            }
            $this->assertLive($f, $d);
            $this->orders->assertHandoverAllowed($f->order);
            $this->transition($d, DS::PickedUp, $actor, $this->source($actor), null);
            $d->update(['picked_up_at' => now()]);
            $this->orders->advanceTo($f->fresh(), FS::Dispatched, $actor);

            return $d->fresh();
        });
    }

    public function outForDelivery(Delivery $delivery, User $actor, ?float $lat = null, ?float $lng = null): Delivery
    {
        $this->authorizeDriverOrStaff($delivery, $actor);

        return DB::transaction(function () use ($delivery, $actor, $lat, $lng) {
            [$f, $d] = $this->lockJob($delivery);
            if ($d->status === DS::OutForDelivery) {
                return $d; // the app asked twice
            }
            $this->assertLive($f, $d);
            $this->orders->assertHandoverAllowed($f->order);
            $this->transition($d, DS::OutForDelivery, $actor, $this->source($actor), null, $lat, $lng);
            // Every trip to the door is announced on its own: a delivery that is tried again says "close" and "arrived" again.
            $d->update(['out_for_delivery_at' => now(), 'arrived_at' => null, 'nearby_at' => null]);
            $f = $f->fresh();
            if ($f->status->canTransitionTo(FS::OutForDelivery)) {
                $this->orders->transition($f, FS::OutForDelivery, $actor, system: true);
            }

            return $d->fresh();
        });
    }

    /**
     * Confirm delivery. Requires proof (photo/signature) when configured; for COD the cash collection is recorded here: the amount of THIS parcel
     * (`cod_amount`), not the whole order, so the second driver of a split order collects his own part (see CodSplit).
     * A repeated call (the app retries after a timeout) finds the delivery already delivered and returns it unchanged: no second attempt,
     * proof, collection or audit row.
     *
     * @param  array{tender: string, amount: string, receipt_reference?: ?string}|null  $cod
     * @param  array{latitude: float, longitude: float, accuracy: ?int}|null  $gps  where the driver stood (optional: a delivery never needs GPS); kept with the proof
     */
    public function deliver(Delivery $delivery, User $actor, ?string $recipientName = null, ?UploadedFile $proof = null, string $proofType = 'photo', ?array $cod = null, ?array $gps = null, bool $answersAssembly = false): Delivery
    {
        $this->authorizeDriverOrStaff($delivery, $actor);

        return DB::transaction(function () use ($delivery, $actor, $recipientName, $proof, $proofType, $cod, $gps, $answersAssembly) {
            [$f, $d] = $this->lockJob($delivery);
            if ($d->status === DS::Delivered) {
                return $d;
            }
            $this->assertLive($f, $d);
            if (! $d->status->canTransitionTo(DS::Delivered)) {
                throw DomainException::make('invalid_state_transition', 409, ['from' => __('status.'.$d->status->value)]);
            }
            $order = $f->order;
            $this->orders->assertHandoverAllowed($order);
            // A part with paid assembly is not "delivered" until the driver has said whether the assembly was done (Settings > Checkout & fees can switch the rule off).
            // Only a client that can ask the question says so (`answers_assembly`, the driver app from Wave 5 on): older driver apps and the dashboard's driver
            // view have no assembly step, and refusing them would leave a parcel undeliverable until the phone is updated.
            if ($answersAssembly && $f->assembly_status === null && app(DeliveryFollowUpSettings::class)->assemblyAnswerRequired() && AssemblyFacts::ordered($f)) {
                throw FollowUpError::make('assembly_answer_required', 422);
            }
            // A photo of a failed attempt (or a bare position) is not proof that the order was handed over.
            if ($d->requires_proof && ! $proof && ! $d->proofs()->whereNotIn('type', self::NOT_A_PROOF)->exists()) {
                throw DomainException::make('delivery_proof_required', 422);
            }
            if ($proof) {
                $this->storeProof($d, $actor, $proof, $proofType, $recipientName, $gps);
            } elseif ($gps !== null) {
                $this->storeLocation($d, $actor, $recipientName, $gps);
            }
            if ($order->payment_method === 'cod' && ! $order->payment_status->isSettled()) {
                CodSplit::refresh($order); // a delivery made before the split rules, or after an adjustment, asks for what is really due now
                $d->refresh();
                if (! $cod) {
                    throw DomainException::make('cod_collection_required', 422, ['amount' => Money::of($d->cod_amount)->format()]);
                }
                $payment = Payment::where('order_id', $f->order_id)->latest('attempt')->firstOrFail();
                app(PaymentService::class)->recordCollection($payment, $f, $actor, $cod['tender'], $cod['amount'], $cod['receipt_reference'] ?? null, expected: Money::of($d->cod_amount));
            }
            $attempt = $d->attempt_count + 1;
            DeliveryAttempt::create(['delivery_id' => $d->id, 'attempt_number' => $attempt, 'result' => 'delivered', 'attempted_by' => $actor->id, 'attempted_at' => now()]);
            $d->update(['attempt_count' => $attempt, 'delivered_at' => now()]);
            $this->transition($d, DS::Delivered, $actor, $this->source($actor), $recipientName ? 'received by '.$recipientName : null, $gps['latitude'] ?? null, $gps['longitude'] ?? null);
            $this->orders->transition($f->fresh(), FS::Delivered, $actor, system: true);
            CodSplit::refresh($order->fresh()); // what is left to collect is split among the parcels still on their way
            $this->audit->log('delivery.delivered', $d, [], ['recipient' => $recipientName], branchId: $d->branch_id, actor: $actor);

            return $d->fresh();
        });
    }

    /**
     * Record a failed attempt. A repeat with the same reason (the app retried) changes nothing; another reason on a delivery that already failed is
     * refused like any other step that is not allowed. An optional photo is kept as evidence (a proof row of type `failed`, in the same private
     * storage) and an optional position says where the driver stood; neither is needed for the attempt to count.
     *
     * @param  array{latitude: float, longitude: float, accuracy: ?int}|null  $gps
     */
    public function fail(Delivery $delivery, User $actor, string $reasonCode, ?string $notes = null, ?UploadedFile $photo = null, ?array $gps = null): Delivery
    {
        $this->authorizeDriverOrStaff($delivery, $actor);

        return DB::transaction(function () use ($delivery, $actor, $reasonCode, $notes, $photo, $gps) {
            [$f, $d] = $this->lockJob($delivery);
            if ($d->status === DS::Failed) {
                if ($d->failure_reason === $reasonCode) {
                    return $d;
                }
                throw DomainException::make('invalid_state_transition', 409, ['from' => __('status.'.$d->status->value)]);
            }
            $this->assertLive($f, $d);
            if (! $d->status->canTransitionTo(DS::Failed)) {
                throw DomainException::make('invalid_state_transition', 409, ['from' => __('status.'.$d->status->value)]);
            }
            $attempt = $d->attempt_count + 1;
            DeliveryAttempt::create(['delivery_id' => $d->id, 'attempt_number' => $attempt, 'result' => 'failed', 'failure_reason_code' => $reasonCode,
                'notes' => $notes, 'attempted_by' => $actor->id, 'attempted_at' => now()]);
            $d->update(['attempt_count' => $attempt, 'failed_at' => now(), 'failure_reason' => $reasonCode]);
            $this->transition($d, DS::Failed, $actor, $this->source($actor), $reasonCode.($notes ? ': '.$notes : ''), $gps['latitude'] ?? null, $gps['longitude'] ?? null);
            if ($photo) {
                $this->storeProof($d, $actor, $photo, 'failed', null, $gps);
            }
            $f = $f->fresh();
            if ($f->status->canTransitionTo(FS::Failed)) {
                $this->orders->transition($f, FS::Failed, $actor, $reasonCode, system: true);
            }
            $payment = Payment::where('order_id', $f->order_id)->where('method', 'cod')->latest('attempt')->first();
            if ($payment && ! $payment->status->isSettled()) {
                CashCollection::create(['payment_id' => $payment->id, 'order_fulfillment_id' => $f->id, 'branch_id' => $f->branch_id,
                    'collected_by' => $actor->id, 'context' => 'cod', 'tender' => 'cash', 'amount_due' => $d->cod_amount, 'amount_collected' => '0.00',
                    'status' => 'failed', 'failure_reason' => $reasonCode, 'collected_at' => now()]);
            }
            // The customer is offered new times to choose from (SlotOffers decides whether this failure gets an offer); never at the cost of the attempt itself.
            $id = $d->id;
            DB::afterCommit(fn () => rescue(fn () => app(SlotOffers::class)->offerAfterFailure($id), report: true));

            return $d->fresh();
        });
    }

    /** The new time and the status change are one step: a refused change leaves the old time in place. */
    public function reschedule(Delivery $delivery, User $actor, string $when): Delivery
    {
        $this->authorizeDriverOrStaff($delivery, $actor);

        return DB::transaction(function () use ($delivery, $actor, $when) {
            [$f, $d] = $this->lockJob($delivery);
            $this->assertLive($f, $d);
            $this->transition($d, DS::Rescheduled, $actor, $this->source($actor), 'rescheduled to '.$when);
            $d->update(['scheduled_for' => BusinessTime::toUtc($when)]);

            return $d->fresh();
        });
    }

    /**
     * Reschedule onto another delivery slot: the booking moves atomically (a full slot leaves the old booking untouched),
     * the fulfillment records the new slot and the delivery's scheduled time follows the slot start.
     */
    public function rescheduleToSlot(Delivery $delivery, User $actor, string $slotId, string $date): Delivery
    {
        $this->authorize($actor, 'deliveries.assign', $delivery->branch_id);

        return DB::transaction(fn () => $this->moveToSlot($delivery, $actor, $this->source($actor), $slotId, $date));
    }

    /**
     * The customer chose one of the times offered after a failed delivery (SlotOffers::choose calls this inside its own transaction, after it has checked
     * the offer and whose it is). Same move as the dashboard's reschedule, so capacity and booking cut-offs are the same rules; only a failed delivery of a
     * live order can be moved by a customer. Cash due is not touched.
     */
    public function rescheduleForCustomer(Delivery $delivery, User $customer, string $slotId, string $date): Delivery
    {
        [$f, $d] = $this->lockJob($delivery);
        $this->assertLive($f, $d);
        if ($d->status !== DS::Failed) {
            throw FollowUpError::make('offer_closed', 409);
        }

        return $this->moveToSlot($d, $customer, 'customer', $slotId, $date);
    }

    /** The one move onto a slot, for staff and for the customer: book the new slot (a full one throws and changes nothing), release the old, set the time, audit it. */
    private function moveToSlot(Delivery $delivery, User $actor, string $source, string $slotId, string $date): Delivery
    {
        $f = OrderFulfillment::whereKey($delivery->order_fulfillment_id)->lockForUpdate()->firstOrFail();
        $this->slots->move($f->delivery_slot_id, $f->slot_date?->toDateString(), $slotId, $date, $f->branch_id, 'delivery');
        $old = ['slot_id' => $f->delivery_slot_id, 'date' => $f->slot_date?->toDateString()];
        $f->update(['delivery_slot_id' => $slotId, 'slot_date' => $date]);
        $when = $this->slots->startsAt($slotId, $date);
        $delivery->update(['scheduled_for' => $when->utc()]);
        $this->transition($delivery, DS::Rescheduled, $actor, $source, 'rescheduled to slot '.$when->toDateTimeString());
        $this->audit->log('delivery.rescheduled_to_slot', $delivery, $old, ['slot_id' => $slotId, 'date' => $date], ['source' => $source], branchId: $delivery->branch_id, actor: $actor);

        return $delivery->fresh();
    }

    /**
     * The driver's answer for the paid assembly of this part: `done`, or `not_done` with a reason from {@see AssemblyFacts::REASONS} (`other` needs a note), an
     * optional note and an optional photo (kept as a proof photo of type `assembly`, private like every other). Allowed while the goods are with the driver. The
     * same answer again changes nothing (no second alert, photo or audit row); a different one corrects it. A `not_done` is told to the branch at once and waits in
     * "Needs attention" until someone handles it (refund or rebook, see {@see self::handleAssembly()}).
     */
    public function answerAssembly(Delivery $delivery, User $actor, string $status, ?string $reason = null, ?string $note = null, ?UploadedFile $photo = null): Delivery
    {
        $this->authorizeDriverOrStaff($delivery, $actor);
        $note = $note !== null && trim($note) !== '' ? Str::limit(trim($note), 295, '') : null;
        if (! in_array($status, [AssemblyFacts::DONE, AssemblyFacts::NOT_DONE], true)) {
            throw FollowUpError::make('assembly_answer_invalid', 422);
        }
        if ($status === AssemblyFacts::NOT_DONE && ($reason === null || ! in_array($reason, AssemblyFacts::REASONS, true) || ($reason === 'other' && $note === null))) {
            throw FollowUpError::make('assembly_reason_required', 422);
        }
        $reason = $status === AssemblyFacts::NOT_DONE ? $reason : null;

        return DB::transaction(function () use ($delivery, $actor, $status, $reason, $note, $photo) {
            [$f, $d] = $this->lockJob($delivery);
            $this->assertLive($f, $d);
            if (! AssemblyFacts::ordered($f)) {
                throw FollowUpError::make('assembly_not_ordered', 422);
            }
            if (! in_array($d->status, [DS::PickedUp, DS::OutForDelivery], true)) {
                throw DomainException::make('invalid_state_transition', 409, ['from' => __('status.'.$d->status->value)]);
            }
            $same = $f->assembly_status === $status && $f->assembly_reason === $reason && $f->assembly_note === $note;
            if ($same && $photo === null) {
                return $d; // the app asked twice
            }
            if ($photo) {
                $this->storeProof($d, $actor, $photo, 'assembly', null);
            }
            if ($same) {
                return $d->fresh();
            }
            $old = ['assembly_status' => $f->assembly_status, 'reason' => $f->assembly_reason];
            $f->update(['assembly_status' => $status, 'assembly_answered_at' => now(), 'assembly_answered_by' => $actor->id, 'assembly_reason' => $reason, 'assembly_note' => $note,
                'assembly_done_at' => $status === AssemblyFacts::DONE ? now() : null, 'assembly_handled_at' => null, 'assembly_handled_by' => null]);
            $this->audit->log('delivery.assembly_answered', $d, $old, ['assembly_status' => $status, 'reason' => $reason], ['order' => $f->order->number, 'fulfillment' => $f->number],
                branchId: $d->branch_id, actor: $actor);
            if ($status === AssemblyFacts::NOT_DONE) {
                $partId = $f->id;
                DB::afterCommit(fn () => rescue(fn () => app(AssemblyAlerts::class)->notDone($partId), report: true));
            }

            return $d->fresh();
        });
    }

    /**
     * The branch dealt with an assembly that was not done (refunded the fee through the normal refund approval, or booked it again): the line leaves "Needs attention".
     * Needs deliveries.assign at the part's branch; written to the audit log. Handling twice changes nothing.
     */
    public function handleAssembly(OrderFulfillment $part, User $staff): OrderFulfillment
    {
        $this->authorize($staff, 'deliveries.assign', $part->branch_id);

        return DB::transaction(function () use ($part, $staff) {
            $f = OrderFulfillment::whereKey($part->id)->lockForUpdate()->firstOrFail();
            if ($f->assembly_status !== AssemblyFacts::NOT_DONE) {
                throw FollowUpError::make('assembly_nothing_to_handle', 409);
            }
            if ($f->assembly_handled_at === null) {
                $f->update(['assembly_handled_at' => now(), 'assembly_handled_by' => $staff->id]);
                $this->audit->log('delivery.assembly_handled', $f, [], ['assembly_reason' => $f->assembly_reason], ['fulfillment' => $f->number], branchId: $f->branch_id, actor: $staff);
            }

            return $f->fresh();
        });
    }

    /**
     * The driver is at the customer's door ("I have arrived"). Only the driver who has the job, only while it is out for delivery, once: a repeated tap
     * (or a retry after a lost answer) finds `arrived_at` set and changes nothing, so the customer is told once. The step is written to the delivery's
     * history (with the driver's position when the app sent one) and to the audit log; the customer's message goes out after the commit.
     *
     * @param  array{latitude: float, longitude: float, accuracy: ?int}|null  $gps
     */
    public function arrived(Delivery $delivery, User $actor, ?array $gps = null): Delivery
    {
        $this->authorizeDriver($delivery, $actor);

        return DB::transaction(function () use ($delivery, $actor, $gps) {
            [$f, $d] = $this->lockJob($delivery);
            if ($d->driver_id !== $actor->id) {
                throw OpsError::make('delivery_not_yours', 403);
            }
            if ($d->arrived_at !== null) {
                return $d; // the app asked twice
            }
            $this->assertLive($f, $d);
            if ($d->status !== DS::OutForDelivery) {
                throw DomainException::make('invalid_state_transition', 409, ['from' => __('status.'.$d->status->value)]);
            }
            $d->update(['arrived_at' => now()]);
            $this->log($d, DS::OutForDelivery, DS::OutForDelivery, $actor, 'driver', 'arrived at the customer', $gps['latitude'] ?? null, $gps['longitude'] ?? null);
            $this->audit->log('delivery.arrived', $d, [], ['arrived_at' => $d->arrived_at?->toIso8601String()], branchId: $d->branch_id, actor: $actor);
            $id = $d->id;
            DB::afterCommit(fn () => rescue(fn () => app(DeliveryCustomerAlerts::class)->arrived($id), report: true));

            return $d->fresh();
        });
    }

    /**
     * The driver hands a job that was given to him (and that he has not accepted) back: too far, the wrong vehicle, his shift is ending, other.
     * The job returns to the waiting pool with a history row and an audit row, and the driver is remembered so the automatic dispatch never offers
     * him the same job again. A repeat (a second tap, a retry) finds the job already gone from him and changes nothing. Who is told and who
     * gets the job next is DispatchService's business, after this commit.
     *
     * @return array{0: Delivery, 1: bool} the delivery, and whether THIS call took the job back (false: it was handed back before)
     */
    public function decline(Delivery $delivery, User $actor, string $reason, ?string $note = null): array
    {
        $this->authorizeDriver($delivery, $actor, anyDriver: true);

        return DB::transaction(function () use ($delivery, $actor, $reason, $note) {
            [$f, $d] = $this->lockJob($delivery);
            if ($d->driver_id !== $actor->id) {
                if (DeliveryDecline::query()->where('delivery_id', $d->id)->where('driver_id', $actor->id)->exists()) {
                    return [$d, false]; // handed back already
                }
                throw OpsError::make('delivery_not_yours', 403);
            }
            $this->assertLive($f, $d);
            if ($d->status !== DS::Assigned) {
                throw DomainException::make('invalid_state_transition', 409, ['from' => __('status.'.$d->status->value)]);
            }
            $this->recordDecline($d, $actor->id, $reason, $note);
            $d->update(['driver_id' => null, 'assigned_at' => null]);
            $this->transition($d, DS::Pending, $actor, 'driver', 'declined: '.$reason.($note ? ' ('.$note.')' : ''));
            $this->audit->log('delivery.declined', $d, ['driver_id' => $actor->id, 'status' => DS::Assigned->value], ['status' => DS::Pending->value], ['reason' => $reason], branchId: $d->branch_id, actor: $actor);

            return [$d->fresh(), true];
        });
    }

    /**
     * The system takes a job that was given to a driver (and not accepted) back: he did not answer in time (`timeout`) or went silent (`off_duty`).
     * Same effect as a decline, but nobody asked: he is told after the commit that the job is no longer his. Returns the driver it was taken from,
     * or null when there was nothing to take back (the driver accepted at the last second, someone else was given the job, it was cancelled).
     *
     * @param  ?CarbonInterface  $assignedBefore  only a job that was given at or before this moment is taken back (guards against a job re-given since the scan)
     */
    public function releaseUnaccepted(Delivery $delivery, string $reason, ?CarbonInterface $assignedBefore = null): ?string
    {
        return DB::transaction(function () use ($delivery, $reason, $assignedBefore) {
            [$f, $d] = $this->lockJob($delivery);
            if ($d->status !== DS::Assigned || $d->driver_id === null || $f->status === FS::Cancelled
                || ($assignedBefore !== null && $d->assigned_at !== null && $d->assigned_at->greaterThan($assignedBefore))) {
                return null;
            }
            $previous = $d->driver_id;
            $this->recordDecline($d, $previous, $reason, null);
            $d->update(['driver_id' => null, 'assigned_at' => null]);
            $this->transition($d, DS::Pending, null, 'system', 'taken back: '.$reason, system: true);
            $this->audit->log('delivery.released', $d, ['driver_id' => $previous, 'status' => DS::Assigned->value], ['status' => DS::Pending->value], ['reason' => $reason], branchId: $d->branch_id);
            $number = (string) $f->order->number;
            DB::afterCommit(fn () => rescue(fn () => app(DriverJobAlerts::class)->removed($previous, $number), report: true));

            return $previous;
        });
    }

    /** One row per driver and delivery: a driver who hands the same job back twice keeps one row, with the latest reason. */
    private function recordDecline(Delivery $d, string $driverId, string $reason, ?string $note): void
    {
        $row = DeliveryDecline::query()->where('delivery_id', $d->id)->where('driver_id', $driverId)->first();
        if ($row !== null) {
            $row->forceFill(['reason' => $reason, 'note' => $note !== null ? Str::limit($note, 295, '') : null, 'created_at' => now()])->save();

            return;
        }
        DeliveryDecline::create(['delivery_id' => $d->id, 'driver_id' => $driverId, 'reason' => $reason, 'note' => $note !== null ? Str::limit($note, 295, '') : null]);
    }

    /** Failed delivery brought back to the branch: stock re-added via the fulfillment's return. Only the driver who has the job, or branch staff, may record it. */
    public function returnToBranch(Delivery $delivery, User $actor): Delivery
    {
        $this->authorizeDriverOrStaff($delivery, $actor);

        return DB::transaction(function () use ($delivery, $actor) {
            [$f, $d] = $this->lockJob($delivery);
            if ($d->status === DS::Returned) {
                return $d; // recorded already
            }
            if ($d->status !== DS::Returning) {
                $this->transition($d, DS::Returning, $actor, 'staff', null);
            }
            $this->transition($d, DS::Returned, $actor, 'staff', 'received back at branch');
            $d->update(['returned_at' => now()]);
            $f = $f->fresh();
            if ($f->status->canTransitionTo(FS::Returned)) {
                $this->orders->transition($f, FS::Returned, $actor, 'returned_to_branch', system: true);
            }
            $this->audit->log('delivery.returned', $d, [], ['status' => DS::Returned->value], branchId: $d->branch_id, actor: $actor);

            return $d->fresh();
        });
    }

    /**
     * Cancel a delivery before the goods left the branch (the order itself carries on and can be given to someone else). Only the driver who
     * has the job, or branch staff, may do it; it is written to the audit log with its reason. The courier's booking is cancelled first, so a
     * courier that cannot be reached leaves the delivery as it was and the staff member sees why.
     */
    public function cancel(Delivery $delivery, User $staff, string $reason): Delivery
    {
        $this->authorizeDriverOrStaff($delivery, $staff);

        return DB::transaction(function () use ($delivery, $staff, $reason) {
            [$f, $d] = $this->lockJob($delivery);
            if ($d->status === DS::Cancelled) {
                return $d; // cancelled already
            }
            if (! $d->status->canTransitionTo(DS::Cancelled)) {
                throw DomainException::make('invalid_state_transition', 409, ['from' => __('status.'.$d->status->value)]);
            }
            if ($d->mode === 'provider' && $d->provider_reference) {
                $adapter = $this->providers->for($d->provider);
                if ($adapter->capabilities()['cancel_booking'] ?? false) {
                    $adapter->cancelBooking($d);
                }
            }
            $driverId = $d->driver_id;
            $from = $d->status;
            $this->transition($d, DS::Cancelled, $staff, 'staff', $reason);
            $this->audit->log('delivery.cancelled', $d, ['status' => $from->value, 'driver_id' => $driverId], ['status' => DS::Cancelled->value], ['reason' => $reason, 'order' => $f->order->number], branchId: $d->branch_id, actor: $staff);
            $this->tellDriverCancelled($driverId, (string) $f->order->number);

            return $d->fresh();
        });
    }

    /**
     * The parcel was cancelled: its delivery that has not left the branch (waiting, assigned or accepted) is cancelled with it, in the same
     * transaction, with history and an audit row; the driver who had it is told after the commit, and a courier's booking is released (a courier
     * that cannot be reached never blocks the cancellation: the delivery says so and staff call the courier). A delivery already on the road is
     * left to the failed / returned flow.
     */
    public function cancelForFulfillment(OrderFulfillment $f, ?User $actor, string $reason): void
    {
        $number = (string) $f->order()->value('number');
        foreach (Delivery::where('order_fulfillment_id', $f->id)->whereIn('status', [DS::Pending->value, DS::Assigned->value, DS::Accepted->value])->lockForUpdate()->get() as $d) {
            $driverId = $d->driver_id;
            $from = $d->status;
            $this->transition($d, DS::Cancelled, $actor, $actor ? 'staff' : 'system', 'order cancelled'.($reason !== '' ? ': '.$reason : ''), system: $actor === null);
            $this->audit->log('delivery.cancelled', $d, ['status' => $from->value, 'driver_id' => $driverId], ['status' => DS::Cancelled->value],
                ['reason' => 'fulfillment_cancelled', 'detail' => $reason, 'order' => $number], branchId: $d->branch_id, actor: $actor);
            $this->tellDriverCancelled($driverId, $number);
            if ($d->mode === 'provider' && $d->provider_reference) {
                $id = $d->id;
                DB::afterCommit(fn () => $this->releaseCourier($id));
            }
        }
    }

    /** After the commit: a phone notification for the driver who had the job (the live socket already carries the new state). */
    private function tellDriverCancelled(?string $driverId, string $number): void
    {
        if ($driverId !== null) {
            DB::afterCommit(fn () => rescue(fn () => app(DriverJobAlerts::class)->cancelled($driverId, $number), report: true));
        }
    }

    /** Best effort, after the commit: tell the courier the booking is off. A failure is kept on the delivery for staff to follow up. */
    private function releaseCourier(string $deliveryId): void
    {
        $d = Delivery::with('provider')->find($deliveryId);
        if ($d === null || $d->mode !== 'provider' || ! $d->provider_reference || $d->provider === null) {
            return;
        }
        try {
            $adapter = $this->providers->for($d->provider);
            if ($adapter->capabilities()['cancel_booking'] ?? false) {
                $adapter->cancelBooking($d);
            }
        } catch (Throwable $e) {
            $d->update(['last_error' => 'Order cancelled but the courier booking could not be cancelled: call the courier']);
            report($e);
        }
    }

    /** Provider webhook: authenticated, stored once per provider event id, then applied. */
    public function handleProviderWebhook(DeliveryProvider $provider, Request $request): string
    {
        $adapter = $this->providers->for($provider);
        $data = $adapter->parseWebhook($request);
        $hash = hash('sha256', $request->getContent());
        if (! $data['authentic']) {
            $this->audit->log('delivery.webhook_rejected', $provider, [], [], ['ip' => $request->ip()]);

            return 'invalid_signature';
        }
        try {
            $event = DB::transaction(fn () => DeliveryProviderEvent::create(['delivery_provider_id' => $provider->id, 'provider_event_id' => $data['event_id'] ?: $hash,
                'payload_hash' => $hash, 'payload' => Redactor::redact($data['raw']), 'signature_valid' => true]));
        } catch (UniqueConstraintViolationException) {
            return 'duplicate';
        }
        // A payload without a reference must never match deliveries that have none yet (where(col, null) means "IS NULL").
        $delivery = filled($data['reference']) ? Delivery::where('delivery_provider_id', $provider->id)->where('provider_reference', (string) $data['reference'])->first() : null;
        $status = $data['status'] ? DS::tryFrom($data['status']) : null;
        if (! $delivery || ! $status) {
            $event->update(['status' => 'ignored', 'processed_at' => now()]);

            return 'ignored';
        }
        try {
            DB::transaction(function () use ($delivery, $status, $data) {
                $d = Delivery::whereKey($delivery->id)->lockForUpdate()->firstOrFail();
                if ($d->status === $status || ! $d->status->canTransitionTo($status)) {
                    return; // out-of-order or repeated status — ignored safely
                }
                $this->transition($d, $status, null, 'provider', null, system: true);
                $f = $d->fulfillment->fresh();
                $target = match ($status) {
                    DS::PickedUp => FS::Dispatched, DS::OutForDelivery => FS::OutForDelivery, DS::Delivered => FS::Delivered, DS::Failed => FS::Failed, default => null,
                };
                if ($status === DS::Delivered && $data['proof_url']) {
                    DeliveryProof::create(['delivery_id' => $d->id, 'type' => 'provider', 'path' => $data['proof_url'], 'captured_at' => now()]);
                }
                if ($target === FS::Failed && $f->status->canTransitionTo(FS::Failed)) {
                    $this->orders->transition($f, FS::Failed, null, 'provider_update', system: true);
                } elseif ($target && $target !== FS::Failed) {
                    $this->orders->advanceTo($f, $target, null, 'provider_update');
                }
            });
        } catch (DomainException $e) {
            // The courier says picked up / delivered for an order that was cancelled, or while a cancellation waits for a manager: the step is refused
            // and recorded (staff see it), and the courier gets a normal answer so it does not retry the same message for ever.
            $event->update(['status' => 'rejected', 'error' => $e->errorCode, 'processed_at' => now()]);

            return 'rejected';
        }
        $event->update(['status' => 'processed', 'processed_at' => now()]);

        return 'processed';
    }

    public function transition(Delivery $d, DS $to, ?User $actor, string $source, ?string $note, ?float $lat = null, ?float $lng = null, bool $system = false): void
    {
        $from = $d->status;
        if ($from === $to) {
            return;
        }
        if (! $from->canTransitionTo($to)) {
            throw DomainException::make('invalid_state_transition', 409, ['from' => __('status.'.$from->value)]);
        }
        $d->update(['status' => $to]);
        $this->log($d, $from, $to, $actor, $source, $note, $lat, $lng);
        if ($from === DS::Failed) {
            // Whatever happens next (a new time, a new try, a return), the times that were offered are no longer the plan. A customer's own choice is written first, so it stays.
            app(SlotOffers::class)->closeOpen($d->id, match ($to) {
                DS::Rescheduled => 'rescheduled_by_branch', DS::OutForDelivery => 'tried_again', default => 'delivery_moved_on',
            }, $actor);
        }
        // The customer, the branch and the driver hear about it from the Delivery model itself (OrderRealtime), once, after the commit.
    }

    /**
     * The parcel and its delivery, both locked, in that order: the same order OrderService::transition takes its locks in (parcel, then order),
     * so a cancellation and a driver's step on the same job wait for each other instead of locking each other out.
     *
     * @return array{0: OrderFulfillment, 1: Delivery}
     */
    private function lockJob(Delivery $delivery): array
    {
        $f = OrderFulfillment::whereKey($delivery->order_fulfillment_id)->lockForUpdate()->firstOrFail();
        $f->load('order');
        $d = Delivery::whereKey($delivery->id)->lockForUpdate()->firstOrFail();

        return [$f, $d];
    }

    /** A cancelled job is never picked up, taken out or delivered: a clear 409 (the driver's app shows the message and drops the job). */
    private function assertLive(OrderFulfillment $f, Delivery $d): void
    {
        if ($f->status === FS::Cancelled || $d->status === DS::Cancelled) {
            throw OpsError::make('delivery_cancelled', 409);
        }
    }

    private function log(Delivery $d, ?DS $from, DS $to, ?User $actor, string $source, ?string $note, ?float $lat = null, ?float $lng = null): void
    {
        DeliveryStatusHistory::create(['delivery_id' => $d->id, 'from_status' => $from?->value, 'to_status' => $to->value, 'actor_id' => $actor?->id,
            'source' => $source, 'note' => $note ? Str::limit($note, 495) : null, 'latitude' => $lat, 'longitude' => $lng]);
    }

    /** @param array{latitude: float, longitude: float, accuracy: ?int}|null $gps */
    private function storeProof(Delivery $d, User $actor, UploadedFile $file, string $type, ?string $recipient, ?array $gps = null): void
    {
        if (! in_array($file->getMimeType(), ['image/jpeg', 'image/png', 'image/webp'], true) || $file->getSize() > 8 * 1024 * 1024) {
            throw DomainException::make('media_invalid');
        }
        $disk = config('platform.media.disk') === 'public' ? 'local' : config('platform.media.disk'); // proofs are private
        $path = $file->store('delivery-proofs/'.$d->id, $disk);
        DeliveryProof::create(['delivery_id' => $d->id, 'type' => $type, 'disk' => $disk, 'path' => $path, 'recipient_name' => $recipient, 'captured_by' => $actor->id, 'captured_at' => now()]
            + $this->gpsColumns($gps));
    }

    /** The driver gave a position but no photo (a delivery that needs none): the position is still kept, as a proof row without a file. @param array{latitude: float, longitude: float, accuracy: ?int} $gps */
    private function storeLocation(Delivery $d, User $actor, ?string $recipient, array $gps): void
    {
        DeliveryProof::create(['delivery_id' => $d->id, 'type' => 'location', 'recipient_name' => $recipient, 'captured_by' => $actor->id, 'captured_at' => now()] + $this->gpsColumns($gps));
    }

    /**
     * @param  array{latitude: float, longitude: float, accuracy: ?int}|null  $gps
     * @return array{latitude: ?float, longitude: ?float, accuracy_m: ?int}
     */
    private function gpsColumns(?array $gps): array
    {
        return ['latitude' => $gps['latitude'] ?? null, 'longitude' => $gps['longitude'] ?? null, 'accuracy_m' => $gps['accuracy'] ?? null];
    }

    private function authorize(User $u, string $permission, string $branchId): void
    {
        if (! $this->access->allows($u, $permission, $branchId)) {
            throw DomainException::make('branch_forbidden', 403);
        }
    }

    /**
     * The driver's own steps (arrived, decline): never a dispatcher's, whatever rights he holds. The person needs deliveries.edit at the branch, and
     * the job must be his (a driver of the branch who tries another driver's job is told so). With $anyDriver the ownership is checked later, under
     * the row lock (a driver whose job was already taken from him may still repeat his own decline harmlessly).
     */
    private function authorizeDriver(Delivery $d, User $actor, bool $anyDriver = false): void
    {
        if (! $this->access->allows($actor, 'deliveries.edit', $d->branch_id)) {
            throw DomainException::make('branch_forbidden', 403);
        }
        if (! $anyDriver && $d->driver_id !== $actor->id) {
            throw OpsError::make('delivery_not_yours', 403);
        }
    }

    /**
     * Drivers may act only on deliveries assigned to them; branch staff need deliveries.assign for the branch. A driver of the branch who tries a job
     * that is another driver's is told so (not "you are not assigned to this branch").
     */
    private function authorizeDriverOrStaff(Delivery $d, User $actor): void
    {
        if ($d->driver_id === $actor->id && $this->access->allows($actor, 'deliveries.edit', $d->branch_id)) {
            return;
        }
        if ($this->access->allows($actor, 'deliveries.assign', $d->branch_id)) {
            return;
        }
        if ($this->access->allows($actor, 'deliveries.edit', $d->branch_id)) {
            throw OpsError::make('delivery_not_yours', 403);
        }
        throw DomainException::make('branch_forbidden', 403);
    }

    private function source(User $actor): string
    {
        return 'staff';
    }
}
