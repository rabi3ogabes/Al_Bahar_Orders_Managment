<?php

namespace App\Http\Controllers\Api\V1\Commerce;

use App\Domain\Orders\Models\UserAddress;
use App\Http\Controllers\Api\V1\ApiController;
use App\Rules\PhoneNumber;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** @tags Account · Addresses */
class AddressController extends ApiController
{
    private const MAX_ADDRESSES = 25;

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->user()->addresses()->with('zone')->orderByDesc('is_default')->latest()->get()->map(fn ($a) => $this->present($a))]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        abort_if($this->user()->addresses()->count() >= self::MAX_ADDRESSES, 422, __('errors.address_limit'));
        $address = DB::transaction(function () use ($data) {
            $first = ! $this->user()->addresses()->exists();
            if ($first || ! empty($data['is_default'])) {
                $this->user()->addresses()->update(['is_default' => false]);
            }

            return $this->user()->addresses()->create($data + ['is_default' => $first || ! empty($data['is_default'])]);
        });

        return response()->json(['data' => $this->present($address->load('zone'))], 201);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json(['data' => $this->present($this->user()->addresses()->with('zone')->findOrFail($id))]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $address = $this->user()->addresses()->findOrFail($id);
        $data = $this->validated($request, true);
        DB::transaction(function () use ($address, $data) {
            // Making this one the default must demote the previous default (never two defaults).
            if (! empty($data['is_default'])) {
                $this->user()->addresses()->whereKeyNot($address->id)->update(['is_default' => false]);
            }
            $address->update($data);
        });

        return response()->json(['data' => $this->present($address->load('zone'))]);
    }

    public function destroy(string $id): JsonResponse
    {
        $address = $this->user()->addresses()->findOrFail($id);
        $address->delete(); // soft delete: orders keep their own snapshot
        if ($address->is_default && ($next = $this->user()->addresses()->latest()->first())) {
            $next->update(['is_default' => true]);
        }

        return response()->json(['data' => ['deleted' => true]]);
    }

    public function makeDefault(string $id): JsonResponse
    {
        $address = $this->user()->addresses()->findOrFail($id);
        DB::transaction(function () use ($address) {
            $this->user()->addresses()->update(['is_default' => false]);
            $address->update(['is_default' => true]);
        });

        return response()->json(['data' => $this->present($address->fresh('zone'))]);
    }

    private function validated(Request $request, bool $partial = false): array
    {
        if ($request->has('recipient_phone')) {
            $request->merge(['recipient_phone' => Phone::normalize($request->input('recipient_phone')) ?? $request->input('recipient_phone')]);
        }
        $req = $partial ? 'sometimes' : 'required';
        // A partial update (PATCH) may touch one field only (label, default flag…): the "needs a zone or coordinates" rules apply
        // only when the request actually carries location data.
        $loc = $partial ? 'sometimes|' : '';

        return $request->validate([
            'label' => 'nullable|string|max:48', 'recipient_name' => "$req|string|max:120", 'recipient_phone' => [$req, 'string', new PhoneNumber],
            'delivery_zone_id' => 'nullable|uuid|exists:delivery_zones,id', 'zone_number' => $loc.'nullable|string|max:8|required_without_all:latitude,delivery_zone_id',
            'street_number' => $loc.'nullable|string|max:16|required_with:zone_number', 'building_number' => $loc.'nullable|string|max:16|required_with:zone_number',
            'unit_number' => 'nullable|string|max:16', 'area' => 'nullable|string|max:120', 'city' => 'nullable|string|max:120', 'landmark' => 'nullable|string|max:255',
            'instructions' => 'nullable|string|max:500', 'latitude' => 'nullable|numeric|between:24.4,26.3|required_with:longitude',
            'longitude' => 'nullable|numeric|between:50.7,51.7|required_with:latitude', 'is_default' => 'sometimes|boolean',
        ]);
    }

    private function present(UserAddress $a): array
    {
        return ['id' => $a->id, 'label' => $a->label, 'recipient_name' => $a->recipient_name, 'recipient_phone' => $a->recipient_phone,
            'delivery_zone' => $a->zone ? ['id' => $a->zone->id, 'name' => $a->zone->localized('name')] : null,
            'zone_number' => $a->zone_number, 'street_number' => $a->street_number, 'building_number' => $a->building_number, 'unit_number' => $a->unit_number,
            'area' => $a->area, 'city' => $a->city, 'landmark' => $a->landmark, 'instructions' => $a->instructions,
            'latitude' => $a->latitude, 'longitude' => $a->longitude, 'is_default' => $a->is_default, 'summary' => $a->oneLine()];
    }
}
