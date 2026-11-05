<?php

namespace App\Http\Controllers\Api\V1\Branches;

use App\Domain\Branches\Models\Branch;
use App\Domain\Branches\Models\DeliveryZone;
use App\Domain\Branches\Services\BranchDiscovery;
use App\Domain\Branches\Services\SlotService;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Resources\V1\BranchResource;
use App\Support\BusinessTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** @tags Branches */
class BranchController extends ApiController
{
    public function __construct(private readonly BranchDiscovery $discovery) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['search' => 'nullable|string|max:100', 'pickup' => 'nullable|boolean', 'delivery' => 'nullable|boolean']);

        return BranchResource::collection($this->discovery->query($request->only('search', 'pickup', 'delivery'))->paginate($this->perPage($request, 50)));
    }

    /** Nearby branches. Only call with coordinates the customer explicitly shared (location permission). */
    public function nearby(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'lat' => 'required|numeric|between:-90,90',
            'lng' => 'required|numeric|between:-180,180',
            'radius_km' => 'nullable|numeric|min:1|max:200',
            'pickup' => 'nullable|boolean', 'delivery' => 'nullable|boolean',
        ]);

        return BranchResource::collection($this->discovery->nearby((float) $data['lat'], (float) $data['lng'], (float) ($data['radius_km'] ?? 30), $data));
    }

    public function show(string $id): BranchResource
    {
        return new BranchResource(Branch::active()->with(['hours', 'holidays'])->findOrFail($id));
    }

    public function hours(string $id): JsonResponse
    {
        $branch = Branch::active()->with(['hours', 'holidays' => fn ($q) => $q->where('ends_at', '>', now())])->findOrFail($id);

        return response()->json(['data' => [
            'timezone' => $branch->timezone,
            'weekly' => $branch->hours->map(fn ($h) => [
                'day_of_week' => $h->day_of_week,
                'is_closed' => $h->is_closed,
                'opens_at' => $h->opens_at ? substr($h->opens_at, 0, 5) : null,
                'closes_at' => $h->closes_at ? substr($h->closes_at, 0, 5) : null,
                'break_starts_at' => $h->break_starts_at ? substr($h->break_starts_at, 0, 5) : null,
                'break_ends_at' => $h->break_ends_at ? substr($h->break_ends_at, 0, 5) : null,
            ]),
            'holidays' => $branch->holidays->map(fn ($h) => [
                'name' => $h->localized('name'), 'type' => $h->type,
                'starts_at' => BusinessTime::iso($h->starts_at), 'ends_at' => BusinessTime::iso($h->ends_at),
            ]),
        ]]);
    }

    public function pickupSlots(Request $request, string $id, SlotService $slots): JsonResponse
    {
        $request->validate(['date' => 'required|date_format:Y-m-d|after_or_equal:today|before:+30 days']);
        $branch = Branch::active()->where('pickup_enabled', true)->with(['hours', 'holidays'])->findOrFail($id);

        return response()->json(['data' => $slots->available($branch, 'pickup', $request->input('date'))]);
    }

    /** Delivery time slots of a branch for one date (same shape as pickup slots). */
    public function deliverySlots(Request $request, string $id, SlotService $slots): JsonResponse
    {
        $request->validate(['date' => 'required|date_format:Y-m-d|after_or_equal:today|before:+30 days']);
        $branch = Branch::active()->where('delivery_enabled', true)->with(['hours', 'holidays'])->findOrFail($id);

        return response()->json(['data' => $slots->available($branch, 'delivery', $request->input('date'))]);
    }

    public function zones(): JsonResponse
    {
        return response()->json(['data' => DeliveryZone::where('is_active', true)->orderBy('name_en')->get()->map(fn ($z) => [
            'id' => $z->id, 'code' => $z->code, 'name' => $z->localized('name'),
            'zone_numbers' => $z->zone_numbers ? array_map('trim', explode(',', $z->zone_numbers)) : [],
        ])]);
    }
}
