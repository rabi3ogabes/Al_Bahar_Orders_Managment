<?php

use App\Domain\Branches\Models\Branch;
use App\Domain\Branches\Models\BranchDeliveryZone;
use App\Domain\Branches\Models\DeliverySlot;
use App\Domain\Branches\Models\DeliveryZone;
use App\Domain\Branches\Services\BranchService;
use App\Domain\Branches\Services\DeliveryZoneResolver;
use App\Domain\Branches\Services\SlotService;
use App\Support\DomainException;

it('resolves zones by zone number or polygon and lists the serving branches by priority', function () {
    $zone = DeliveryZone::factory()->create(['zone_numbers' => '63, 66', 'polygon' => [[51.50, 25.30], [51.56, 25.30], [51.56, 25.35], [51.50, 25.35]]]);
    [$a, $b, $inactive] = Branch::factory()->count(3)->create();
    $inactive->update(['status' => 'inactive']);
    foreach ([[$a, 20], [$b, 10], [$inactive, 1]] as [$br, $prio]) {
        BranchDeliveryZone::create(['branch_id' => $br->id, 'delivery_zone_id' => $zone->id, 'delivery_fee' => '15.00', 'priority' => $prio]);
    }
    $resolver = app(DeliveryZoneResolver::class);

    expect($resolver->resolve(null, '66')?->id)->toBe($zone->id)
        ->and($resolver->resolve(null, null, 25.32, 51.53)?->id)->toBe($zone->id)
        ->and($resolver->resolve(null, '99', 25.10, 51.10))->toBeNull()
        ->and($resolver->servingBranches($zone)->pluck('branch_id')->all())->toBe([$b->id, $a->id]);
});

it('enforces pickup slot capacity', function () {
    $branch = Branch::factory()->create();
    app(BranchService::class)->setWeeklyHours($branch, app(BranchService::class)->defaultHours(), audit: false);
    $date = now('Asia/Qatar')->addDays(2);
    $slot = DeliverySlot::create(['branch_id' => $branch->id, 'fulfillment_type' => 'pickup', 'day_of_week' => $date->dayOfWeek,
        'starts_at' => '16:00', 'ends_at' => '18:00', 'capacity' => 2]);
    $svc = app(SlotService::class);

    $svc->book($slot->id, $date->toDateString(), $branch->id, 'pickup');
    $svc->book($slot->id, $date->toDateString(), $branch->id, 'pickup');
    expect(fn () => $svc->book($slot->id, $date->toDateString(), $branch->id, 'pickup'))->toThrow(DomainException::class);

    $this->getJson("/api/v1/branches/{$branch->id}/pickup-slots?date=".$date->toDateString())
        ->assertOk()->assertJsonPath('data.0.available', 0)->assertJsonPath('data.0.is_full', true);

    $svc->release($slot->id, $date->toDateString());
    $this->getJson("/api/v1/branches/{$branch->id}/pickup-slots?date=".$date->toDateString())->assertJsonPath('data.0.available', 1);
});

it('lets branch managers configure zones only for their branch', function () {
    [$a, $b] = Branch::factory()->count(2)->create();
    $zone = DeliveryZone::factory()->create();
    actingAsStaff(staffWithRole('admin'));
    $this->postJson("/api/v1/admin/branches/{$b->id}/zones", ['delivery_zone_id' => $zone->id, 'delivery_fee' => 20, 'min_order_amount' => 100, 'max_order_amount' => 50])
        ->assertStatus(422)->assertJsonValidationErrors('max_order_amount');
    $this->postJson("/api/v1/admin/branches/{$b->id}/zones", ['delivery_zone_id' => $zone->id, 'delivery_fee' => 20, 'free_delivery_threshold' => 500])->assertCreated();
});
