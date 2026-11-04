<?php

namespace App\Domain\Fulfillment\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Branches\Models\BranchEmployee;
use App\Domain\Fulfillment\Enums\DeliveryStatus as DS;
use App\Domain\Fulfillment\Models\Delivery;
use App\Domain\Fulfillment\Models\DeliveryAttempt;
use App\Domain\Fulfillment\Models\DeliveryProof;
use App\Domain\Fulfillment\Models\DeliveryProvider;
use App\Domain\Fulfillment\Models\DeliveryProviderEvent;
use App\Domain\Fulfillment\Models\DeliveryStatusHistory;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessControl;
use App\Domain\Orders\Enums\FulfillmentStatus as FS;
use App\Domain\Orders\Models\OrderFulfillment;
use App\Domain\Orders\Services\OrderService;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Services\PaymentService;
use App\Support\DomainException;
use App\Support\Redactor;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Internal delivery (own drivers) and third-party courier deliveries share one model and status machine.
 * Fulfillment transitions are driven from here: picked_up → fulfillment dispatched (stock deducted),
 * out_for_delivery, delivered (requires proof when configured + COD collection), failed (attempt recorded),
 * rescheduled, returning → returned (stock re-added).
 */
class DeliveryService
{
    public function __construct(
        private readonly OrderService $orders,
        private readonly DeliveryProviderRegistry $providers,
        private readonly AccessControl $access,
        private readonly AuditLogger $audit,
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
        $codAmount = $f->order->payment_method === 'cod' ? $f->order->grand_total : '0.00';
        $delivery = Delivery::create([
            'order_fulfillment_id' => $f->id, 'branch_id' => $f->branch_id, 'mode' => $mode,
            'delivery_provider_id' => $mode === 'provider' ? $providerId : null, 'status' => DS::Pending,
            'tracking_number' => 'ABD'.now()->format('ymd').Str::upper(Str::random(6)), 'quoted_fee' => $f->delivery_fee,
            'cod_amount' => $codAmount, 'requires_proof' => true, 'scheduled_for' => $scheduledFor,
        ]);
        $this->log($delivery, null, DS::Pending, $staff, 'staff', 'created');

        if ($mode === 'provider') {
            $this->book($delivery);
        }
        $this->audit->log('delivery.created', $delivery, [], ['mode' => $mode, 'fulfillment' => $f->number], branchId: $f->branch_id, actor: $staff);

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
        } catch (\Throwable $e) {
            $delivery->update(['last_error' => Str::limit($e instanceof \Illuminate\Http\Client\ConnectionException ? 'Provider timeout — retry booking' : $e->getMessage(), 490)]);
            report($e);
        }

        return $delivery->fresh();
    }

    public function assignDriver(Delivery $delivery, User $driver, User $staff): Delivery
    {
        $this->authorize($staff, 'deliveries.assign', $delivery->branch_id);
        $isDriver = BranchEmployee::where('branch_id', $delivery->branch_id)->where('user_id', $driver->id)->where('is_active', true)->where('is_delivery_driver', true)->exists();
        if (! $isDriver || ! $driver->isActive()) {
            throw DomainException::make('driver_not_eligible', 422);
        }
        $delivery->update(['driver_id' => $driver->id, 'assigned_at' => now(), 'mode' => 'internal']);
        $this->transition($delivery, DS::Assigned, $staff, 'staff', 'assigned to '.$driver->name);
        $this->audit->log('delivery.assigned', $delivery, [], ['driver_id' => $driver->id], branchId: $delivery->branch_id, actor: $staff);

        return $delivery->fresh();
    }

    /** Driver picked the goods up from the branch → fulfillment dispatched (stock deducted). */
    public function pickUp(Delivery $delivery, User $actor): Delivery
    {
        $this->authorizeDriverOrStaff($delivery, $actor);

        return DB::transaction(function () use ($delivery, $actor) {
            $this->transition($delivery, DS::PickedUp, $actor, $this->source($actor), null);
            $delivery->update(['picked_up_at' => now()]);
            $this->orders->advanceTo($delivery->fulfillment, FS::Dispatched, $actor);

            return $delivery->fresh();
        });
    }

    public function outForDelivery(Delivery $delivery, User $actor, ?float $lat = null, ?float $lng = null): Delivery
    {
        $this->authorizeDriverOrStaff($delivery, $actor);

        return DB::transaction(function () use ($delivery, $actor, $lat, $lng) {
            $this->transition($delivery, DS::OutForDelivery, $actor, $this->source($actor), null, $lat, $lng);
            $delivery->update(['out_for_delivery_at' => now()]);
            $f = $delivery->fulfillment->fresh();
            if ($f->status->canTransitionTo(FS::OutForDelivery)) {
                $this->orders->transition($f, FS::OutForDelivery, $actor, system: true);
            }

            return $delivery->fresh();
        });
    }

    /**
     * Confirm delivery. Requires proof (photo/signature) when configured; for COD the cash collection is recorded here.
     *
     * @param  array{tender: string, amount: string, receipt_reference?: ?string}|null  $cod
     */
    public function deliver(Delivery $delivery, User $actor, ?string $recipientName = null, ?UploadedFile $proof = null, string $proofType = 'photo', ?array $cod = null): Delivery
    {
        $this->authorizeDriverOrStaff($delivery, $actor);

        return DB::transaction(function () use ($delivery, $actor, $recipientName, $proof, $proofType, $cod) {
            $d = Delivery::whereKey($delivery->id)->lockForUpdate()->firstOrFail();
            $f = $d->fulfillment()->with('order')->first();
            if ($d->requires_proof && ! $proof && ! $d->proofs()->exists()) {
                throw DomainException::make('delivery_proof_required', 422);
            }
            if ($proof) {
                $this->storeProof($d, $actor, $proof, $proofType, $recipientName);
            }
            if ($f->order->payment_method === 'cod' && ! $f->order->payment_status->isSettled()) {
                if (! $cod) {
                    throw DomainException::make('cod_collection_required', 422, ['amount' => \App\Support\Money::of($d->cod_amount)->format()]);
                }
                $payment = Payment::where('order_id', $f->order_id)->latest('attempt')->firstOrFail();
                app(PaymentService::class)->recordCollection($payment, $f, $actor, $cod['tender'], $cod['amount'], $cod['receipt_reference'] ?? null);
            }
            $attempt = $d->attempt_count + 1;
            DeliveryAttempt::create(['delivery_id' => $d->id, 'attempt_number' => $attempt, 'result' => 'delivered', 'attempted_by' => $actor->id, 'attempted_at' => now()]);
            $d->update(['attempt_count' => $attempt, 'delivered_at' => now()]);
            $this->transition($d, DS::Delivered, $actor, $this->source($actor), $recipientName ? 'received by '.$recipientName : null);
            $this->orders->transition($f->fresh(), FS::Delivered, $actor, system: true);
            $this->audit->log('delivery.delivered', $d, [], ['recipient' => $recipientName], branchId: $d->branch_id, actor: $actor);

            return $d->fresh();
        });
    }

    public function fail(Delivery $delivery, User $actor, string $reasonCode, ?string $notes = null): Delivery
    {
        $this->authorizeDriverOrStaff($delivery, $actor);

        return DB::transaction(function () use ($delivery, $actor, $reasonCode, $notes) {
            $d = Delivery::whereKey($delivery->id)->lockForUpdate()->firstOrFail();
            $attempt = $d->attempt_count + 1;
            DeliveryAttempt::create(['delivery_id' => $d->id, 'attempt_number' => $attempt, 'result' => 'failed', 'failure_reason_code' => $reasonCode,
                'notes' => $notes, 'attempted_by' => $actor->id, 'attempted_at' => now()]);
            $d->update(['attempt_count' => $attempt, 'failed_at' => now(), 'failure_reason' => $reasonCode]);
            $this->transition($d, DS::Failed, $actor, $this->source($actor), $reasonCode.($notes ? ': '.$notes : ''));
            $f = $d->fulfillment->fresh();
            if ($f->status->canTransitionTo(FS::Failed)) {
                $this->orders->transition($f, FS::Failed, $actor, $reasonCode, system: true);
            }
            $payment = Payment::where('order_id', $f->order_id)->where('method', 'cod')->latest('attempt')->first();
            if ($payment && ! $payment->status->isSettled()) {
                \App\Domain\Payments\Models\CashCollection::create(['payment_id' => $payment->id, 'order_fulfillment_id' => $f->id, 'branch_id' => $f->branch_id,
                    'collected_by' => $actor->id, 'context' => 'cod', 'tender' => 'cash', 'amount_due' => $payment->amount, 'amount_collected' => '0.00',
                    'status' => 'failed', 'failure_reason' => $reasonCode, 'collected_at' => now()]);
            }

            return $d->fresh();
        });
    }

    public function reschedule(Delivery $delivery, User $actor, string $when): Delivery
    {
        $this->authorizeDriverOrStaff($delivery, $actor);
        $delivery->update(['scheduled_for' => $when]);
        $this->transition($delivery, DS::Rescheduled, $actor, $this->source($actor), 'rescheduled to '.$when);

        return $delivery->fresh();
    }

    /** Failed delivery brought back to the branch: stock re-added via the fulfillment's return. */
    public function returnToBranch(Delivery $delivery, User $actor): Delivery
    {
        $this->authorize($actor, 'deliveries.edit', $delivery->branch_id);

        return DB::transaction(function () use ($delivery, $actor) {
            if ($delivery->status !== DS::Returning) {
                $this->transition($delivery, DS::Returning, $actor, 'staff', null);
            }
            $this->transition($delivery, DS::Returned, $actor, 'staff', 'received back at branch');
            $delivery->update(['returned_at' => now()]);
            $f = $delivery->fulfillment->fresh();
            if ($f->status->canTransitionTo(FS::Returned)) {
                $this->orders->transition($f, FS::Returned, $actor, 'returned_to_branch', system: true);
            }

            return $delivery->fresh();
        });
    }

    public function cancel(Delivery $delivery, User $staff, string $reason): Delivery
    {
        $this->authorize($staff, 'deliveries.edit', $delivery->branch_id);
        if ($delivery->mode === 'provider' && $delivery->provider_reference) {
            $adapter = $this->providers->for($delivery->provider);
            if ($adapter->capabilities()['cancel_booking'] ?? false) {
                $adapter->cancelBooking($delivery);
            }
        }
        $this->transition($delivery, DS::Cancelled, $staff, 'staff', $reason);

        return $delivery->fresh();
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
        $delivery = Delivery::where('delivery_provider_id', $provider->id)->where('provider_reference', $data['reference'])->first();
        $status = $data['status'] ? DS::tryFrom($data['status']) : null;
        if (! $delivery || ! $status) {
            $event->update(['status' => 'ignored', 'processed_at' => now()]);

            return 'ignored';
        }
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
    }

    private function log(Delivery $d, ?DS $from, DS $to, ?User $actor, string $source, ?string $note, ?float $lat = null, ?float $lng = null): void
    {
        DeliveryStatusHistory::create(['delivery_id' => $d->id, 'from_status' => $from?->value, 'to_status' => $to->value, 'actor_id' => $actor?->id,
            'source' => $source, 'note' => $note ? Str::limit($note, 495) : null, 'latitude' => $lat, 'longitude' => $lng]);
    }

    private function storeProof(Delivery $d, User $actor, UploadedFile $file, string $type, ?string $recipient): void
    {
        if (! in_array($file->getMimeType(), ['image/jpeg', 'image/png', 'image/webp'], true) || $file->getSize() > 8 * 1024 * 1024) {
            throw DomainException::make('media_invalid');
        }
        $disk = config('platform.media.disk') === 'public' ? 'local' : config('platform.media.disk'); // proofs are private
        $path = $file->store('delivery-proofs/'.$d->id, $disk);
        DeliveryProof::create(['delivery_id' => $d->id, 'type' => $type, 'disk' => $disk, 'path' => $path, 'recipient_name' => $recipient, 'captured_by' => $actor->id, 'captured_at' => now()]);
    }

    private function authorize(User $u, string $permission, string $branchId): void
    {
        if (! $this->access->allows($u, $permission, $branchId)) {
            throw DomainException::make('branch_forbidden', 403);
        }
    }

    /** Drivers may act only on deliveries assigned to them; staff need deliveries.edit on the branch. */
    private function authorizeDriverOrStaff(Delivery $d, User $actor): void
    {
        if ($d->driver_id === $actor->id && $this->access->allows($actor, 'deliveries.edit', $d->branch_id)) {
            return;
        }
        $this->authorize($actor, 'deliveries.assign', $d->branch_id);
    }

    private function source(User $actor): string
    {
        return 'staff';
    }
}
