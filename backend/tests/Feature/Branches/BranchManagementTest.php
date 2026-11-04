<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Branches\Models\Branch;
use App\Domain\Branches\Models\BranchHoliday;
use App\Domain\Branches\Services\BranchHoursService;
use App\Domain\Branches\Services\BranchService;
use App\Domain\Identity\Models\User;
use Carbon\CarbonImmutable;

function branchPayload(array $over = []): array
{
    return array_merge(['code' => 'WB', 'name_en' => 'West Bay Showroom', 'name_ar' => 'معرض الخليج الغربي', 'latitude' => 25.3215, 'longitude' => 51.5310, 'pickup_enabled' => true], $over);
}

it('lets an admin create, edit and deactivate a branch with audit history', function () {
    actingAsStaff(staffWithRole('admin'));
    $id = $this->postJson('/api/v1/admin/branches', branchPayload())->assertCreated()->json('data.id');
    expect(Branch::find($id)->hours()->count())->toBe(7); // sensible default hours created

    $this->patchJson("/api/v1/admin/branches/{$id}", ['name_en' => 'West Bay Flagship'])->assertOk()->assertJsonPath('data.name_en', 'West Bay Flagship');
    $this->patchJson("/api/v1/admin/branches/{$id}", ['status' => 'inactive'])->assertOk();

    expect(AuditLog::where('auditable_id', $id)->pluck('action')->all())->toContain('branch.created', 'branch.updated');
    $this->getJson('/api/v1/branches')->assertOk()->assertJsonCount(0, 'data'); // inactive hidden from customers
});

it('validates branch codes and Qatar coordinates', function () {
    actingAsStaff(staffWithRole('admin'));
    $this->postJson('/api/v1/admin/branches', branchPayload(['code' => 'bad code', 'latitude' => 40]))
        ->assertStatus(422)->assertJsonValidationErrors(['code', 'latitude']);
});

it('prevents a branch manager from creating branches, changing status, or touching other branches', function () {
    [$a, $b] = Branch::factory()->count(2)->create();
    actingAsStaff(staffWithRole('branch_manager', $a));

    $this->postJson('/api/v1/admin/branches', branchPayload())->assertForbidden();
    $this->patchJson("/api/v1/admin/branches/{$a->id}", ['phone' => '+97444000000'])->assertOk();
    $this->patchJson("/api/v1/admin/branches/{$a->id}", ['status' => 'inactive'])->assertForbidden();
    $this->getJson("/api/v1/admin/branches/{$b->id}")->assertForbidden();
    $this->patchJson("/api/v1/admin/branches/{$b->id}", ['phone' => '+97444000000'])->assertForbidden();
    $this->getJson('/api/v1/admin/branches')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $a->id);
});

it('calculates opening status with breaks, overnight hours, holidays and closures in Qatar time', function () {
    $branch = Branch::factory()->create();
    $svc = app(BranchService::class);
    $week = $svc->defaultHours();
    $week[3] = ['opens_at' => '10:00', 'closes_at' => '22:00', 'break_starts_at' => '13:00', 'break_ends_at' => '16:00']; // Wed
    $week[4] = ['opens_at' => '18:00', 'closes_at' => '02:00']; // Thu overnight
    $svc->setWeeklyHours($branch, $week);
    $hours = app(BranchHoursService::class);
    $qa = fn (string $t) => CarbonImmutable::parse($t, 'Asia/Qatar');
    $branch->refresh();

    expect($hours->isOpen($branch, $qa('2026-11-04 11:00')))->toBeTrue()    // Wednesday morning
        ->and($hours->isOpen($branch, $qa('2026-11-04 14:00')))->toBeFalse() // break
        ->and($hours->isOpen($branch, $qa('2026-11-05 23:30')))->toBeTrue()  // Thursday night
        ->and($hours->isOpen($branch, $qa('2026-11-06 01:30')))->toBeTrue()  // overnight into Friday
        ->and($hours->isOpen($branch, $qa('2026-11-06 03:00')))->toBeFalse()
        ->and($hours->status($branch, $qa('2026-11-04 14:00'))['next_open_at'])->toStartWith('2026-11-04T16:00:00+03:00');

    BranchHoliday::create(['branch_id' => $branch->id, 'type' => 'holiday', 'name_en' => 'National Day', 'name_ar' => 'اليوم الوطني',
        'starts_at' => $qa('2026-12-18 00:00'), 'ends_at' => $qa('2026-12-19 00:00')]);
    $branch->unsetRelation('holidays');
    expect($hours->status($branch, $qa('2026-12-18 12:00')))->toMatchArray(['is_open' => false, 'reason' => 'holiday']);

    $branch->update(['opening_status' => 'temporarily_closed']);
    expect($hours->status($branch, $qa('2026-11-04 11:00'))['reason'])->toBe('temporarily_closed');
});

it('finds nearby branches ordered by distance and only within the radius', function () {
    $near = Branch::factory()->create(['latitude' => 25.2850, 'longitude' => 51.5310]);
    $far = Branch::factory()->create(['latitude' => 25.3000, 'longitude' => 51.4500]);
    Branch::factory()->create(['latitude' => 26.1000, 'longitude' => 51.2000]); // Al Khor-ish, outside 20km

    $res = $this->getJson('/api/v1/branches/nearby?lat=25.2860&lng=51.5300&radius_km=20')->assertOk();
    expect($res->json('data.*.id'))->toBe([$near->id, $far->id])
        ->and($res->json('data.0.distance_km'))->toBeLessThan(1)
        ->and($res->json('data.0.opening'))->toHaveKeys(['is_open', 'reason']);
});

it('exposes branch details and hours publicly in Arabic', function () {
    $branch = Branch::factory()->create(['name_ar' => 'فرع اللؤلؤة']);
    app(BranchService::class)->setWeeklyHours($branch, app(BranchService::class)->defaultHours(), audit: false);
    $this->getJson("/api/v1/branches/{$branch->id}", ['Accept-Language' => 'ar'])->assertOk()->assertJsonPath('data.name', 'فرع اللؤلؤة');
    $this->getJson("/api/v1/branches/{$branch->id}/hours")->assertOk()->assertJsonCount(7, 'data.weekly')->assertJsonPath('data.timezone', 'Asia/Qatar');
});
