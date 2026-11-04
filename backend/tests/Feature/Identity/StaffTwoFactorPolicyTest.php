<?php

use App\Domain\Identity\Services\TwoFactorService;

it('sends staff without 2FA to their profile to enrol when 2FA is mandatory', function () {
    config(['platform.auth.staff_two_factor_required' => true]);
    $staff = staffWithRole('super_admin');

    $this->actingAs($staff)->get(route('admin.dashboard'))
        ->assertRedirect(route('admin.profile'))
        ->assertSessionHas('toast');

    $this->actingAs($staff)->get(route('admin.profile'))->assertOk();
});

it('lets enrolled staff through once the second factor was passed in the session', function () {
    config(['platform.auth.staff_two_factor_required' => true]);
    $staff = staffWithRole('super_admin');
    $staff->forceFill([
        'two_factor_secret' => app(TwoFactorService::class)->generateSecret(),
        'two_factor_confirmed_at' => now(),
    ])->save();

    $this->actingAs($staff)->get(route('admin.dashboard'))->assertRedirect(route('admin.two-factor.challenge'));
    $this->actingAs($staff)->withSession(['two_factor_passed' => true])->get(route('admin.dashboard'))->assertOk();
});

it('does not force enrolment when the policy is off', function () {
    config(['platform.auth.staff_two_factor_required' => false]);
    $this->actingAs(staffWithRole('super_admin'))->get(route('admin.dashboard'))->assertOk();
});
