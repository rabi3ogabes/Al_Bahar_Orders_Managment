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
        $branch = Branch::active()->where('pickup_enabled', true)->with(['hours', 'holidays'])->findOrFail($id);

        return $this->slotsResponse($request, $branch, 'pickup', $slots);
    }

    /** Delivery time slots of a branch (same shape as pickup slots). */
    public function deliverySlots(Request $request, string $id, SlotService $slots): JsonResponse
    {
        $branch = Branch::active()->where('delivery_enabled', true)->with(['hours', 'holidays'])->findOrFail($id);

        return $this->slotsResponse($request, $branch, 'delivery', $slots);
    }

    /**
     * One date  (?date=YYYY-MM-DD)           → data: [slot…]
     * A span    (?from=…&to=…[&only_available=1]) → data: [{date, available, slots: [slot…]}…], one entry per date that has slots.
     * Dates run from today to +29 days; a span covers at most 30 days.
     */
    private function slotsResponse(Request $request, Branch $branch, string $type, SlotService $slots): JsonResponse
    {
        // "Today" and the 30-day limit are the business day (Qatar), not the server's UTC day, which is a day behind for the first three hours.
        $today = BusinessTime::now()->toDateString();
        $limit = BusinessTime::now()->addDays(30)->toDateString();
        $d = $request->validate([
            'date' => 'nullable|date_format:Y-m-d|after_or_equal:'.$today.'|before:'.$limit.'|required_without_all:from,to|prohibits:from,to',
            'from' => 'nullable|date_format:Y-m-d|after_or_equal:'.$today.'|before:'.$limit.'|required_with:to',
            'to' => 'nullable|date_format:Y-m-d|after_or_equal:from|before:'.$limit.'|required_with:from',
            'only_available' => 'nullable|boolean',
        ]);
        if (isset($d['date'])) {
            return response()->json(['data' => $slots->available($branch, $type, $d['date'])]);
        }

        return response()->json([
            'data' => $slots->availableRange($branch, $type, $d['from'], $d['to'], (bool) ($d['only_available'] ?? false)),
            'meta' => ['from' => $d['from'], 'to' => $d['to']],
        ]);
    }

    public function zones(): JsonResponse
    {
        return response()->json(['data' => DeliveryZone::where('is_active', true)->orderBy('name_en')->get()->map(fn ($z) => [
            'id' => $z->id, 'code' => $z->code, 'name' => $z->localized('name'),
            'zone_numbers' => $z->zone_numbers ? array_map('trim', explode(',', $z->zone_numbers)) : [],
        ])]);
    }
}
