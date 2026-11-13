<?php

use App\Domain\Branches\Models\Branch;
use App\Domain\Branches\Models\DeliveryZone;
use App\Domain\Branches\Services\DeliveryZoneResolver;
use App\Domain\Identity\Models\User;
use App\Livewire\Admin\Branches\BranchEditor;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;

function addressBody(array $extra = []): array
{
    return ['recipient_name' => 'Noor', 'recipient_phone' => '55123456', 'zone_number' => '66', 'street_number' => '850', 'building_number' => '12'] + $extra;
}

describe('address map pin', function () {
    beforeEach(function () {
        Sanctum::actingAs(User::factory()->create(), ['customer']);
    });

    it('accepts a pair inside Qatar and returns it', function () {
        $this->postJson('/api/v1/me/addresses', addressBody(['latitude' => 25.2854, 'longitude' => 51.531]))->assertCreated()
            ->assertJsonPath('data.latitude', 25.2854)->assertJsonPath('data.longitude', 51.531);
    });

    it('needs latitude and longitude together', function () {
        $this->postJson('/api/v1/me/addresses', addressBody(['latitude' => 25.2854]))->assertStatus(422)->assertJsonValidationErrors('longitude');
        $this->postJson('/api/v1/me/addresses', addressBody(['longitude' => 51.531]))->assertStatus(422)->assertJsonValidationErrors('latitude');
    });

    it('refuses a pin outside Qatar or out of range', function (mixed $lat, mixed $lng) {
        $this->postJson('/api/v1/me/addresses', addressBody(['latitude' => $lat, 'longitude' => $lng]))->assertStatus(422);
    })->with([
        'dubai' => [25.2048, 55.2708],
        'riyadh' => [24.7136, 46.6753],
        'null island' => [0, 0],
        'latitude 91' => [91, 51.5],
        'longitude 181' => [25.3, 181],
        'text' => ['abc', 'def'],
    ]);

    it('applies the same rules when only the pin is edited', function () {
        $id = $this->postJson('/api/v1/me/addresses', addressBody())->assertCreated()->json('data.id');
        $this->patchJson("/api/v1/me/addresses/{$id}", ['latitude' => 25.3])->assertStatus(422)->assertJsonValidationErrors('longitude');
        $this->patchJson("/api/v1/me/addresses/{$id}", ['latitude' => 40.0, 'longitude' => 51.5])->assertStatus(422);
        $this->patchJson("/api/v1/me/addresses/{$id}", ['latitude' => 25.3, 'longitude' => 51.5])->assertOk()->assertJsonPath('data.latitude', 25.3);
    });

    it('needs a pair in the checkout quote too', function () {
        $this->postJson('/api/v1/checkout/quote', ['latitude' => 25.3])->assertStatus(422)->assertJsonValidationErrors('longitude');
    });
});

describe('branch coordinates', function () {
    it('are a pair inside Qatar on the admin API', function () {
        $branch = Branch::factory()->create();
        actingAsStaff(staffWithRole('super_admin'));
        $url = "/api/v1/admin/branches/{$branch->id}";
        $this->patchJson($url, ['latitude' => 25.3])->assertStatus(422)->assertJsonValidationErrors('longitude');
        $this->patchJson($url, ['longitude' => 51.5])->assertStatus(422)->assertJsonValidationErrors('latitude');
        $this->patchJson($url, ['latitude' => 40.0, 'longitude' => 51.5])->assertStatus(422)->assertJsonValidationErrors('latitude');
        $this->patchJson($url, ['latitude' => 25.3, 'longitude' => 55.0])->assertStatus(422)->assertJsonValidationErrors('longitude');
        $this->patchJson($url, ['latitude' => 25.31, 'longitude' => 51.52])->assertOk()->assertJsonPath('data.latitude', 25.31)->assertJsonPath('data.longitude', 51.52);
        // Clearing both is fine: a branch without a pin simply has no map marker.
        $this->patchJson($url, ['latitude' => null, 'longitude' => null])->assertOk()->assertJsonPath('data.latitude', null);
    });

    it('are validated in the dashboard form as a pair', function () {
        $branch = Branch::factory()->create();
        Livewire::actingAs(User::factory()->superAdmin()->create())->test(BranchEditor::class, ['branch' => $branch])
            ->set('form.latitude', '25.3')->set('form.longitude', '')->call('save')->assertHasErrors(['form.longitude'])
            ->set('form.latitude', '')->set('form.longitude', '51.5')->call('save')->assertHasErrors(['form.latitude'])
            ->set('form.latitude', '10')->set('form.longitude', '51.5')->call('save')->assertHasErrors(['form.latitude'])
            ->set('form.latitude', '25.3')->set('form.longitude', '51.5')->call('save')->assertHasNoErrors(['form.latitude', 'form.longitude']);
    });
});

describe('delivery zone polygons', function () {
    it('pick the zone for a map pin when no zone or zone number is given', function () {
        // Two neighbouring squares, [lng, lat] order as the dashboard stores them.
        $west = DeliveryZone::factory()->create(['zone_numbers' => null, 'polygon' => [[51.40, 25.20], [51.50, 25.20], [51.50, 25.40], [51.40, 25.40]]]);
        $east = DeliveryZone::factory()->create(['zone_numbers' => null, 'polygon' => [[51.50, 25.20], [51.60, 25.20], [51.60, 25.40], [51.50, 25.40]]]);
        $inactive = DeliveryZone::factory()->create(['zone_numbers' => null, 'is_active' => false, 'polygon' => [[51.60, 25.20], [51.70, 25.20], [51.70, 25.40], [51.60, 25.40]]]);
        $resolver = app(DeliveryZoneResolver::class);

        expect($resolver->resolve(null, null, 25.3, 51.45)?->id)->toBe($west->id)
            ->and($resolver->resolve(null, null, 25.3, 51.55)?->id)->toBe($east->id)
            ->and($resolver->resolve(null, null, 25.3, 51.65))->toBeNull()      // only an inactive zone covers it
            ->and($resolver->resolve(null, null, 25.9, 51.45))->toBeNull()      // outside every zone
            ->and($resolver->resolve($east->id, null, 25.3, 51.45)?->id)->toBe($east->id)   // an explicit zone wins over the pin
            ->and($inactive->contains(25.3, 51.65))->toBeTrue();
    });
});
