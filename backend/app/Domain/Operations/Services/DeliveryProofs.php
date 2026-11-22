<?php

namespace App\Domain\Operations\Services;

use App\Domain\Fulfillment\Models\Delivery;
use App\Domain\Fulfillment\Models\DeliveryProof;
use App\Domain\Fulfillment\Models\DriverLocation;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessControl;
use App\Domain\Payments\Models\CashCollection;
use App\Support\BusinessTime;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What the proof-of-delivery viewer shows for one delivery: its photos, who received it and when, the driver, where the driver was, and the
 * cash collected. Built only for a person who may see that delivery's branch (deliveries.view there); anyone else gets null, so the card
 * never shows more than the delivery page would. A handful of small queries, none per photo.
 */
final class DeliveryProofs
{
    public function __construct(private readonly AccessControl $acl) {}

    /**
     * @return array{
     *     delivery_id: string, tracking: string,
     *     photos: list<array{id: string, url: string, at: string|null, n: int, kind: string}>,
     *     external: list<string>,
     *     recipient: string|null, at: string|null, driver: string|null,
     *     gps: array{lat: float, lng: float, at: string|null, map_url: string, accuracy_m: int|null}|null,
     *     cod: array{due: string, collected: string|null, tender: string|null}|null,
     * }|null
     */
    public function for(Delivery $delivery, ?User $viewer): ?array
    {
        if ($viewer === null || ! $this->acl->allows($viewer, 'deliveries.view', $delivery->branch_id)) {
            return null;
        }
        $proofs = DeliveryProof::query()->where('delivery_id', $delivery->id)->orderBy('captured_at')->orderBy('id')->get();
        $photos = [];
        $external = [];
        foreach ($proofs as $proof) {
            if ($proof->disk !== null && $proof->path !== null) {
                // `kind`: the photo of a failed attempt is shown too (a delivery that was tried, failed and delivered later has both), and says which it is.
                $photos[] = ['id' => $proof->id, 'url' => route('admin.deliveries.proof', [$delivery->id, $proof->id]), 'at' => BusinessTime::display($proof->captured_at, 'd M, H:i'), 'n' => count($photos) + 1,
                    'kind' => match ($proof->type) {
                        'failed' => 'failed', 'assembly' => 'assembly', default => 'delivered'
                    }];
            } elseif ($proof->path !== null && str_starts_with(strtolower($proof->path), 'https://')) {
                $external[] = $proof->path; // a courier's own proof: a link only, never fetched or embedded
            }
        }
        $last = $proofs->reject(fn (DeliveryProof $p) => $p->type === 'assembly')->last(); // the photo of the assembly is not the moment of the handover
        $anchor = $last?->captured_at ?? $delivery->delivered_at;
        $driver = $delivery->driver_id ? User::query()->whereKey($delivery->driver_id)->value('name') : null;

        return [
            'delivery_id' => $delivery->id,
            'tracking' => (string) $delivery->tracking_number,
            'photos' => $photos,
            'external' => $external,
            'recipient' => $proofs->reject(fn (DeliveryProof $p) => $p->type === 'assembly')->pluck('recipient_name')->filter()->last(),
            'at' => $anchor !== null ? BusinessTime::display($anchor, 'd M Y, H:i') : null,
            'driver' => is_string($driver) ? $driver : null,
            // The position the driver's phone wrote with the proof comes first; the driver's trail is only the fallback for deliveries made before that.
            'gps' => $this->recorded($proofs) ?? ($anchor !== null ? $this->position($delivery, CarbonImmutable::instance($anchor)) : null),
            'cod' => $this->cash($delivery),
        ];
    }

    /**
     * The position saved WITH the proof (latitude, longitude, accuracy): the last proof of the handover that has one, else the last one of a failed
     * attempt. Null when no proof carries a position, so the caller falls back to the trail.
     *
     * @param  Collection<int, DeliveryProof>  $proofs
     * @return array{lat: float, lng: float, at: string|null, map_url: string, accuracy_m: int|null}|null
     */
    private function recorded(Collection $proofs): ?array
    {
        $withPoint = $proofs->filter(fn (DeliveryProof $p) => $p->latitude !== null && $p->longitude !== null);
        $pick = $withPoint->reject(fn (DeliveryProof $p) => $p->type === 'failed')->last() ?? $withPoint->last();
        if ($pick === null) {
            return null;
        }
        $point = $this->point((float) $pick->latitude, (float) $pick->longitude, $pick->captured_at);

        return $point === null ? null : $point + ['accuracy_m' => $pick->accuracy_m];
    }

    /**
     * Where the driver was when the proof was taken: the last recorded position of this delivery up to a few minutes after the photo, else the
     * driver's own last position in the ten minutes before it, else the position written with the "delivered" step.
     *
     * @return array{lat: float, lng: float, at: string|null, map_url: string, accuracy_m: int|null}|null
     */
    private function position(Delivery $delivery, CarbonImmutable $at): ?array
    {
        $fix = DriverLocation::query()->where('delivery_id', $delivery->id)->where('recorded_at', '<=', $at->addMinutes(5))->orderByDesc('recorded_at')->first(['lat', 'lng', 'recorded_at'])
            ?? ($delivery->driver_id ? DriverLocation::query()->where('driver_id', $delivery->driver_id)->whereBetween('recorded_at', [$at->subMinutes(10), $at->addMinutes(5)])->orderByDesc('recorded_at')->first(['lat', 'lng', 'recorded_at']) : null);
        if ($fix !== null) {
            return $this->withAccuracy($this->point((float) $fix->lat, (float) $fix->lng, $fix->recorded_at));
        }
        $row = DB::table('delivery_status_history')->where('delivery_id', $delivery->id)->where('to_status', 'delivered')->whereNotNull('latitude')->whereNotNull('longitude')->orderByDesc('id')->first(['latitude', 'longitude', 'created_at']);

        return $row !== null ? $this->withAccuracy($this->point((float) $row->latitude, (float) $row->longitude, CarbonImmutable::parse((string) $row->created_at))) : null;
    }

    /**
     * @param  array{lat: float, lng: float, at: string|null, map_url: string}|null  $point
     * @return array{lat: float, lng: float, at: string|null, map_url: string, accuracy_m: int|null}|null
     */
    private function withAccuracy(?array $point): ?array
    {
        return $point === null ? null : $point + ['accuracy_m' => null];
    }

    /** @return array{lat: float, lng: float, at: string|null, map_url: string}|null */
    private function point(float $lat, float $lng, mixed $at): ?array
    {
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            return null;
        }

        return ['lat' => $lat, 'lng' => $lng, 'at' => $at !== null ? BusinessTime::display($at, 'H:i') : null, 'map_url' => 'https://www.google.com/maps/search/?api=1&query='.round($lat, 6).','.round($lng, 6)];
    }

    /** @return array{due: string, collected: string|null, tender: string|null}|null null when the order was not cash on delivery */
    private function cash(Delivery $delivery): ?array
    {
        $due = Money::of($delivery->cod_amount);
        $rows = CashCollection::query()->where('order_fulfillment_id', $delivery->order_fulfillment_id)->where('context', 'cod')->whereIn('status', ['collected', 'partial'])->get(['amount_collected', 'tender']);
        if (! $due->isPositive() && $rows->isEmpty()) {
            return null;
        }
        $collected = $rows->reduce(fn (Money $sum, CashCollection $c) => $sum->plus(Money::of($c->amount_collected)), Money::zero());

        return ['due' => $due->format(), 'collected' => $rows->isEmpty() ? null : $collected->format(), 'tender' => $rows->pluck('tender')->unique()->count() === 1 ? (string) $rows->first()?->tender : null];
    }
}
