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
     *     photos: list<array{id: string, url: string, at: string|null, n: int}>,
     *     external: list<string>,
     *     recipient: string|null, at: string|null, driver: string|null,
     *     gps: array{lat: float, lng: float, at: string|null, map_url: string}|null,
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
                $photos[] = ['id' => $proof->id, 'url' => route('admin.deliveries.proof', [$delivery->id, $proof->id]), 'at' => BusinessTime::display($proof->captured_at, 'd M, H:i'), 'n' => count($photos) + 1];
            } elseif ($proof->path !== null && str_starts_with(strtolower($proof->path), 'https://')) {
                $external[] = $proof->path; // a courier's own proof: a link only, never fetched or embedded
            }
        }
        $last = $proofs->last();
        $anchor = $last?->captured_at ?? $delivery->delivered_at;
        $driver = $delivery->driver_id ? User::query()->whereKey($delivery->driver_id)->value('name') : null;

        return [
            'delivery_id' => $delivery->id,
            'tracking' => (string) $delivery->tracking_number,
            'photos' => $photos,
            'external' => $external,
            'recipient' => $proofs->pluck('recipient_name')->filter()->last(),
            'at' => $anchor !== null ? BusinessTime::display($anchor, 'd M Y, H:i') : null,
            'driver' => is_string($driver) ? $driver : null,
            'gps' => $anchor !== null ? $this->position($delivery, CarbonImmutable::instance($anchor)) : null,
            'cod' => $this->cash($delivery),
        ];
    }

    /**
     * Where the driver was when the proof was taken: the last recorded position of this delivery up to a few minutes after the photo, else the
     * driver's own last position in the ten minutes before it, else the position written with the "delivered" step.
     *
     * @return array{lat: float, lng: float, at: string|null, map_url: string}|null
     */
    private function position(Delivery $delivery, CarbonImmutable $at): ?array
    {
        $fix = DriverLocation::query()->where('delivery_id', $delivery->id)->where('recorded_at', '<=', $at->addMinutes(5))->orderByDesc('recorded_at')->first(['lat', 'lng', 'recorded_at'])
            ?? ($delivery->driver_id ? DriverLocation::query()->where('driver_id', $delivery->driver_id)->whereBetween('recorded_at', [$at->subMinutes(10), $at->addMinutes(5)])->orderByDesc('recorded_at')->first(['lat', 'lng', 'recorded_at']) : null);
        if ($fix !== null) {
            return $this->point((float) $fix->lat, (float) $fix->lng, $fix->recorded_at);
        }
        $row = DB::table('delivery_status_history')->where('delivery_id', $delivery->id)->where('to_status', 'delivered')->whereNotNull('latitude')->whereNotNull('longitude')->orderByDesc('id')->first(['latitude', 'longitude', 'created_at']);

        return $row !== null ? $this->point((float) $row->latitude, (float) $row->longitude, CarbonImmutable::parse((string) $row->created_at)) : null;
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
