<?php

namespace App\Domain\Fulfillment\Services;

use App\Domain\Fulfillment\Models\Delivery;
use App\Domain\Fulfillment\Models\DriverLocation;
use App\Domain\Fulfillment\Models\DriverProfile;
use App\Domain\Fulfillment\Services\Eta\EtaService;
use App\Domain\Orders\Models\Order;
use App\Support\Geo;

/**
 * What a customer may see of their own delivery. The driver's position is shown only while the order is on the road
 * (picked up, out for delivery) and for platform.tracking.grace_minutes after it was delivered (then it is the last
 * recorded point of THIS delivery, never wherever the driver went next). Before pickup and after that window the
 * response carries the order, destination and branch but no driver.
 */
class TrackingService
{
    /** Which of an order's deliveries to follow, best first. */
    private const PRIORITY = ['out_for_delivery' => 4, 'picked_up' => 3, 'delivered' => 2, 'accepted' => 1];

    public function __construct(private readonly EtaService $eta) {}

    /**
     * @return array{
     *     order_id: string, number: string, status: string, tracking: bool, live: bool, delivery_status: string|null,
     *     driver: array<string, mixed>|null, destination: array{lat: float, lng: float}|null,
     *     branch: array{name: string, lat: float|null, lng: float|null}|null,
     *     eta: array{minutes: int, distance_m: int, updated_at: string, source: string}|null, stale_after_seconds: int
     * }
     */
    public function forOrder(Order $order): array
    {
        $order->loadMissing('fulfillments.branch:id,name_en,name_ar,latitude,longitude');
        $grace = now()->subMinutes((int) config('platform.tracking.grace_minutes', 10));
        $delivery = Delivery::with('driver:id,name')->whereIn('order_fulfillment_id', $order->fulfillments->pluck('id'))
            ->where(fn ($q) => $q->whereIn('status', ['accepted', 'picked_up', 'out_for_delivery'])->orWhere(fn ($w) => $w->where('status', 'delivered')->where('delivered_at', '>=', $grace)))
            ->get()->sortByDesc(fn (Delivery $d) => self::PRIORITY[$d->status->value].'|'.($d->assigned_at?->getTimestamp() ?? 0))->first();

        $live = $delivery !== null && in_array($delivery->status->value, ['picked_up', 'out_for_delivery'], true);
        $showDriver = $live || $delivery?->status->value === 'delivered';
        $profile = $showDriver ? DriverProfile::find($delivery->driver_id) : null;
        $position = match (true) {
            $live => DriverPosition::of($profile),
            $showDriver => ($crumb = DriverLocation::where('delivery_id', $delivery->id)->orderByDesc('recorded_at')->first()) ? DriverPosition::ofBreadcrumb($crumb) : null,
            default => null,
        };
        $dest = $order->address_snapshot ?? [];
        $destination = Geo::validPoint($dest['latitude'] ?? null, $dest['longitude'] ?? null) ? ['lat' => (float) $dest['latitude'], 'lng' => (float) $dest['longitude']] : null;
        $branch = ($delivery ? $order->fulfillments->firstWhere('id', $delivery->order_fulfillment_id) : null)?->branch ?? $order->fulfillments->first()?->branch;

        return [
            'order_id' => $order->id, 'number' => $order->number, 'status' => $order->status->value,
            'tracking' => $showDriver && $position !== null, 'live' => $live, 'delivery_status' => $delivery?->status->value,
            'driver' => $showDriver ? ['name' => DriverPosition::firstName($delivery->driver?->name), 'vehicle' => $profile?->vehicle, 'status' => $delivery->status->value]
                + ($position ?? ['lat' => null, 'lng' => null, 'heading' => null, 'speed_kmh' => null, 'accuracy_m' => null, 'at' => null, 'age_seconds' => null, 'stale' => true]) : null,
            'destination' => $destination,
            'branch' => $branch ? ['name' => $branch->localized('name'), 'lat' => $branch->latitude !== null ? (float) $branch->latitude : null, 'lng' => $branch->longitude !== null ? (float) $branch->longitude : null] : null,
            'eta' => $live && $position !== null && $destination !== null ? $this->eta->between($delivery->id, $position['lat'], $position['lng'], $destination['lat'], $destination['lng']) : null,
            'stale_after_seconds' => DriverPosition::staleAfter(),
        ];
    }
}
