<?php

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\TwoFactorService;
use App\Domain\Settings\Services\Settings;
use App\Livewire\Admin\Organization\EmployeeEditor;
use App\Livewire\Admin\Organization\SettingsIndex;
use App\Livewire\Admin\Profile;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

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

function enrolTwoFactor($user): void
{
    $user->forceFill(['two_factor_secret' => app(TwoFactorService::class)->generateSecret(), 'two_factor_confirmed_at' => now()])->save();
}

it('follows the dashboard setting over the environment default', function () {
    config(['platform.auth.staff_two_factor_required' => true]);
    $staff = staffWithRole('super_admin');
    $this->actingAs($staff)->get(route('admin.dashboard'))->assertRedirect(route('admin.profile'));

    app(Settings::class)->set('auth.staff_two_factor_required', false);

    $this->actingAs($staff)->get(route('admin.dashboard'))->assertOk();
    expect(app(TwoFactorService::class)->required())->toBeFalse();
});

it('lets the admin switch the requirement off from Settings → Security', function () {
    config(['platform.auth.staff_two_factor_required' => true]);
    $this->actingAs(User::factory()->superAdmin()->create());
    session(['two_factor_passed' => true, 'two_factor_confirmed_at' => time()]); // a fresh two-factor proof: the owner just typed a code

    Livewire::test(SettingsIndex::class, ['tab' => 'security'])
        ->set('values.security.auth__staff_two_factor_required', false)
        ->call('save')->assertHasNoErrors();

    expect(app(TwoFactorService::class)->required())->toBeFalse();
});

it('lets staff turn their own two-factor off only while it is not required, and only with their password', function () {
    config(['platform.auth.staff_two_factor_required' => true]);
    $staff = staffWithRole('super_admin');
    enrolTwoFactor($staff);
    $this->actingAs($staff);
    session(['two_factor_passed' => true]);

    Livewire::test(Profile::class)->set('current_password', 'wrong-password')->call('disableTwoFactor');
    expect($staff->fresh()->hasTwoFactorEnabled())->toBeTrue('still required: refused');

    app(Settings::class)->set('auth.staff_two_factor_required', false);

    Livewire::test(Profile::class)->set('current_password', 'wrong-password')->call('disableTwoFactor')->assertHasErrors('current_password');
    expect($staff->fresh()->hasTwoFactorEnabled())->toBeTrue('wrong password: refused');

    Livewire::test(Profile::class)->set('current_password', 'Password123')->call('disableTwoFactor')->assertHasNoErrors();
    expect($staff->fresh()->hasTwoFactorEnabled())->toBeFalse();
    expect(DB::table('audit_logs')->where('action', 'staff.two_factor_disabled')->exists())->toBeTrue();
});

it('lets an administrator turn off another employee\'s two-factor and records it', function () {
    $admin = staffWithRole('super_admin');
    $colleague = staffWithRole('super_admin');
    enrolTwoFactor($colleague);
    $this->actingAs($admin);
    session(['two_factor_passed' => true]);

    Livewire::test(EmployeeEditor::class, ['employee' => $colleague])->call('turnOffTwoFactor');

    expect($colleague->fresh()->hasTwoFactorEnabled())->toBeFalse();
    expect(DB::table('audit_logs')->where('action', 'employee.two_factor_turned_off')->exists())->toBeTrue();
});

it('turns two-factor off from the server for a locked-out admin', function () {
    $locked = staffWithRole('super_admin');
    enrolTwoFactor($locked);

    $this->artisan('staff:two-factor-off', ['email' => $locked->email])->assertSuccessful();
    expect($locked->fresh()->hasTwoFactorEnabled())->toBeFalse();

    $this->artisan('staff:two-factor-off', ['email' => 'nobody@example.com'])->assertFailed();
});

it('tells a person who turned two-factor on for themselves that the team requirement is off and how to switch theirs off', function () {
    app(Settings::class)->set('auth.staff_two_factor_required', false);
    $staff = staffWithRole('super_admin');
    enrolTwoFactor($staff);

    $this->actingAs($staff)->get(route('admin.two-factor.challenge'))
        ->assertOk()
        ->assertSee(__('admin.two_factor_optional_hint'), false);

    $this->actingAs($staff)->withSession(['two_factor_passed' => true])->get(route('admin.profile'))
        ->assertOk()
        ->assertSee(__('admin.two_factor_optional_profile'), false);
});

it('does not show the "you can switch it off" hint while the whole team is required to use two-factor', function () {
    app(Settings::class)->set('auth.staff_two_factor_required', true);
    $staff = staffWithRole('super_admin');
    enrolTwoFactor($staff);

    $this->actingAs($staff)->get(route('admin.two-factor.challenge'))
        ->assertOk()
        ->assertDontSee(__('admin.two_factor_optional_hint'), false);

    $this->actingAs($staff)->withSession(['two_factor_passed' => true])->get(route('admin.profile'))
        ->assertOk()
        ->assertDontSee(__('admin.two_factor_optional_profile'), false);
});

it('lets a person with two-factor on switch it off themselves with their password while the team requirement is off', function () {
    app(Settings::class)->set('auth.staff_two_factor_required', false);
    $staff = staffWithRole('super_admin');
    enrolTwoFactor($staff);
    session(['two_factor_passed' => true]);

    Livewire::actingAs($staff)->test(Profile::class)
        ->set('current_password', 'Password123')
        ->call('disableTwoFactor');

    expect($staff->fresh()->hasTwoFactorEnabled())->toBeFalse();
});
