<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Fulfillment\Models\Delivery;
use App\Domain\Fulfillment\Models\DriverProfile;
use App\Domain\Fulfillment\Services\DriverPosition;
use App\Domain\Fulfillment\Services\RouteHistory;
use App\Http\Controllers\Api\V1\ApiController;
use App\Support\Geo;
use Illuminate\Http\JsonResponse;

/**
 * The recorded path of one delivery for the dashboard order page and the staff apps. Served on both the staff API
 * (GET /api/v1/admin/deliveries/{delivery}/route, bearer token) and the dashboard session
 * (GET /admin/deliveries/{delivery}/route); the delivery's branch must be one the staff member may see deliveries of.
 *
 * @tags Dispatch
 */
class DeliveryRouteController extends ApiController
{
    public function show(Delivery $delivery, RouteHistory $routes): JsonResponse
    {
        $this->authorizeBranch('deliveries.view', $delivery->branch_id);
        $delivery->load('fulfillment.order:id,number,address_snapshot', 'fulfillment.branch:id,name_en,name_ar,latitude,longitude', 'driver:id,name');
        $order = $delivery->fulfillment?->order;
        $branch = $delivery->fulfillment?->branch;
        $dest = $order?->address_snapshot ?? [];
        $live = $delivery->driver_id && in_array($delivery->status->value, ['accepted', 'picked_up', 'out_for_delivery'], true)
            ? DriverPosition::of(DriverProfile::find($delivery->driver_id)) : null;

        return response()->json(['data' => [
            'delivery_id' => $delivery->id, 'status' => $delivery->status->value,
            'order_id' => $order?->id, 'order_number' => $order?->number,
            'driver' => $delivery->driver ? ['id' => $delivery->driver->id, 'name' => $delivery->driver->name] : null,
            'destination' => Geo::validPoint($dest['latitude'] ?? null, $dest['longitude'] ?? null) ? ['lat' => (float) $dest['latitude'], 'lng' => (float) $dest['longitude']] : null,
            'branch' => $branch ? ['name' => $branch->localized('name'), 'lat' => $branch->latitude !== null ? (float) $branch->latitude : null, 'lng' => $branch->longitude !== null ? (float) $branch->longitude : null] : null,
            'live' => $live,
            'route' => $routes->forDelivery($delivery->id),
        ]]);
    }
}
