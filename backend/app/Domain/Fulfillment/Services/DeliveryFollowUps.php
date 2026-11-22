<?php

namespace App\Domain\Fulfillment\Services;

use App\Domain\Branches\Models\Branch;
use App\Domain\Fulfillment\Enums\DeliveryStatus as DS;
use App\Domain\Fulfillment\Models\Delivery;
use App\Domain\Fulfillment\Models\DeliveryProof;
use App\Domain\Fulfillment\Models\DeliverySlotOffer;
use App\Domain\Orders\Enums\FulfillmentStatus as FS;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderFulfillment;
use App\Support\BusinessTime;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

/**
 * What the customer's own order page says about the delivery follow-ups, per branch part (docs/guides/DELIVERY_FOLLOW_UPS.md): the new times they can choose
 * after a failed delivery, the proof of delivery (when, who received it, the photo) and whether the paid assembly was done.
 *
 *  - Built for the order's OWNER only (the order endpoint 404s for everybody else), from a handful of queries however many parts the order has.
 *  - The proof never carries a position, the driver's name or phone, or a photo of a failed attempt or of the assembly. The photo is a short-lived signed
 *    link (ten minutes) to a private route, never a public file, and only while the dashboard switch "show the proof of delivery to customers" is on.
 *  - The page asks again when it opens, so a time that was taken a minute ago is already marked as taken.
 */
final class DeliveryFollowUps
{
    /** How long the photo's link works. The page asks for a fresh one every time it opens. */
    public const PHOTO_LINK_MINUTES = 10;

    /** Proof rows that are NOT the customer's photo: a failed attempt, a bare position, the assembly, and a courier's own link. */
    private const NOT_THE_PHOTO = ['failed', 'location', 'assembly', 'provider'];

    public function __construct(private readonly SlotOffers $offers, private readonly DeliveryFollowUpSettings $config) {}

    /**
     * @return array<string, array{slot_offer: array<string, mixed>|null, proof: array<string, mixed>|null, assembly: array<string, mixed>|null}> by part id
     */
    public function forOrder(Order $o): array
    {
        $parts = $o->fulfillments->where('type', 'delivery');
        $deliveries = $parts->mapWithKeys(fn (OrderFulfillment $f) => [$f->id => $f->activeDelivery()])->filter();
        $offers = $this->offers->latestFor($deliveries->pluck('id')->values()->all());
        $proofs = $this->proofRows($deliveries->filter(fn (Delivery $d) => $d->status === DS::Delivered));
        $out = [];
        foreach ($o->fulfillments as $f) {
            if (! $f->relationLoaded('items')) {
                $f->setRelation('items', $o->items->where('order_fulfillment_id', $f->id)->values()); // the lines are already loaded with the order: no query per part
            }
            $d = $deliveries->get($f->id);
            $out[$f->id] = [
                'slot_offer' => $d !== null ? $this->slotOffer($f, $d, $offers->get($d->id)) : null,
                'proof' => $d !== null && $d->status === DS::Delivered ? $this->proof($o, $d, $proofs[$d->id] ?? collect()) : null,
                'assembly' => $f->type === 'delivery' ? $this->assembly($f) : null,
            ];
        }

        return $out;
    }

    // ── The new times ────────────────────────────────────────────────────────────────────────────────────────────────

    /** @return array<string, mixed>|null */
    private function slotOffer(OrderFulfillment $f, Delivery $d, ?DeliverySlotOffer $offer): ?array
    {
        if ($offer === null || ! SlotOffers::belongsToFailure($offer, $d)) {
            return null;
        }
        $status = $offer->effectiveStatus();
        $show = match ($status) {
            DeliverySlotOffer::OPEN, DeliverySlotOffer::EXPIRED => $d->status === DS::Failed && $f->status === FS::Failed,
            DeliverySlotOffer::CHOSEN => in_array($d->status, [DS::Rescheduled, DS::Assigned, DS::Accepted, DS::PickedUp, DS::OutForDelivery], true),
            default => false,
        };
        if (! $show) {
            return null;
        }
        $locale = app()->getLocale();
        $f->loadMissing('branch');
        $branch = $f->branch ?? Branch::query()->findOrFail($f->branch_id);
        $options = $status === DeliverySlotOffer::OPEN
            ? array_map(fn (array $t) => $t + ['label' => SlotOffers::when($t['date'], $t['starts_at'], $t['ends_at'], $locale)], $this->offers->describe($branch, $offer->options))
            : [];
        $chosen = null;
        if ($status === DeliverySlotOffer::CHOSEN && $offer->chosen_date !== null && $offer->chosen_slot_id !== null) {
            $t = $this->offers->describe($branch, [['slot_id' => $offer->chosen_slot_id, 'date' => $offer->chosen_date->toDateString()]])[0];
            $chosen = ['slot_id' => $t['slot_id'], 'date' => $t['date'], 'starts_at' => $t['starts_at'], 'ends_at' => $t['ends_at'],
                'label' => SlotOffers::when($t['date'], $t['starts_at'], $t['ends_at'], $locale), 'at' => BusinessTime::iso($offer->chosen_at)];
        }

        return [
            'id' => $offer->id, 'status' => $status, 'source' => $offer->source, 'expires_at' => BusinessTime::iso($offer->expires_at),
            'can_choose' => $status === DeliverySlotOffer::OPEN && collect($options)->contains(fn (array $t) => $t['available']),
            'options' => $options, 'chosen' => $chosen,
        ];
    }

    // ── The proof of delivery ────────────────────────────────────────────────────────────────────────────────────────

    /**
     * The proof rows of the delivered deliveries (the photo and who received it): one query for all of them.
     *
     * @param  Collection<string, Delivery>  $delivered
     * @return array<string, Collection<int, DeliveryProof>> by delivery id, oldest first
     */
    private function proofRows(Collection $delivered): array
    {
        if ($delivered->isEmpty()) {
            return [];
        }

        return DeliveryProof::query()->whereIn('delivery_id', $delivered->pluck('id')->values()->all())->orderBy('captured_at')->orderBy('id')->get()->groupBy('delivery_id')->all();
    }

    /**
     * @param  Collection<int, DeliveryProof>  $rows
     * @return array{delivered_at: ?string, received_by: ?string, photo: array{url: string, expires_at: string}|null}
     */
    private function proof(Order $o, Delivery $d, Collection $rows): array
    {
        $photo = $rows->reject(fn (DeliveryProof $p) => in_array($p->type, self::NOT_THE_PHOTO, true) || $p->disk === null || $p->path === null)->last();
        $expires = now()->addMinutes(self::PHOTO_LINK_MINUTES);
        $name = $rows->reject(fn (DeliveryProof $p) => $p->type === 'failed' || $p->type === 'assembly')->pluck('recipient_name')->filter()->last() ?? $this->recipientFromHistory($d);

        return [
            // Only these three facts: no position, no driver, no phone.
            'delivered_at' => BusinessTime::iso($d->delivered_at),
            'received_by' => is_string($name) && trim($name) !== '' ? trim($name) : null,
            'photo' => $photo !== null && $this->config->proofForCustomer()
                ? ['url' => URL::temporarySignedRoute('api.v1.orders.proof-photo', $expires, ['id' => $o->id, 'proof' => $photo->id]), 'expires_at' => BusinessTime::iso($expires) ?? ''] : null,
        ];
    }

    /** A delivery made before the proof kept the recipient's name on its own row: the history line the driver's step wrote ("received by Mariam"). */
    private function recipientFromHistory(Delivery $d): ?string
    {
        $note = DB::table('delivery_status_history')->where('delivery_id', $d->id)->where('to_status', 'delivered')->where('note', 'like', 'received by %')->orderByDesc('id')->value('note');

        return is_string($note) ? trim(substr($note, strlen('received by '))) : null;
    }

    // ── The assembly ─────────────────────────────────────────────────────────────────────────────────────────────────

    /** @return array{status: string, done_at: ?string, reason_label: ?string}|null null when the customer did not buy assembly for this part */
    private function assembly(OrderFulfillment $f): ?array
    {
        $a = AssemblyFacts::state($f);

        return $a === null ? null : ['status' => $a['status'], 'done_at' => $a['done_at'], 'reason_label' => $a['reason_label']];
    }
}
