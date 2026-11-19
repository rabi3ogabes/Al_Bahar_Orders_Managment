<?php

use App\Domain\Fulfillment\Services\DispatchSettings;
use App\Domain\Identity\Models\User;
use App\Domain\Settings\Services\Settings;
use App\Livewire\Admin\Organization\SettingsIndex;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

/** Settings > Operations clock, the driver rules under the limits: shown with defaults and help, saved, validated, and read by dispatch. */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->admin = User::factory()->superAdmin()->create();
});

const DISPATCH_FIELDS = [
    'dispatch.two_person_weight_kg' => 25, 'dispatch.accept_timeout_min' => 5, 'dispatch.stale_duty_min' => 20,
    'dispatch.max_auto_attempts' => 3, 'dispatch.nearby_eta_min' => 10, 'dispatch.nearby_distance_m' => 1500,
];

function dispatchField(string $key): string
{
    return 'values.ops_clock.'.str_replace('.', '__', $key);
}

it('shows every driver rule with its default, a label and a help line, in both languages', function (string $locale) {
    app()->setLocale($locale);
    $page = Livewire::actingAs($this->admin)->test(SettingsIndex::class)->set('tab', 'ops_clock')->assertOk();
    $html = $page->html();
    foreach (DISPATCH_FIELDS as $key => $default) {
        $field = str_replace('.', '__', $key);
        $page->assertSet(dispatchField($key), (string) $default);
        expect(__('ops_live.setting.'.$field, [], $locale))->not->toBe('ops_live.setting.'.$field)->and(__('ops_live.setting_hint.'.$field, [], $locale))->not->toBe('ops_live.setting_hint.'.$field);
        expect($html)->toContain(e(__('ops_live.setting.'.$field, [], $locale)))->toContain(e(__('ops_live.setting_hint.'.$field, [], $locale)));
    }
    expect($html)->not->toContain('ops_live.')->not->toContain('org_admin.unit');
})->with(['en', 'ar']);

it('saves the driver rules and dispatch reads them from then on, and zero switches the accept time-out off', function () {
    $page = Livewire::actingAs($this->admin)->test(SettingsIndex::class)->set('tab', 'ops_clock')
        ->set(dispatchField('dispatch.two_person_weight_kg'), '30')->set(dispatchField('dispatch.accept_timeout_min'), '0')->set(dispatchField('dispatch.stale_duty_min'), '45')
        ->set(dispatchField('dispatch.max_auto_attempts'), '2')->set(dispatchField('dispatch.nearby_eta_min'), '7')->set(dispatchField('dispatch.nearby_distance_m'), '900')
        ->call('save')->assertHasNoErrors();

    $settings = app(Settings::class)->all();
    expect($settings['dispatch.two_person_weight_kg'])->toBe(30)->and($settings['dispatch.accept_timeout_min'])->toBe(0);

    $dispatch = app(DispatchSettings::class);
    expect($dispatch->twoPersonWeightKg())->toBe(30.0)->and($dispatch->acceptTimeoutMin())->toBe(0)->and($dispatch->staleDutyMin())->toBe(45)
        ->and($dispatch->maxAutoAttempts())->toBe(2)->and($dispatch->nearbyEtaMin())->toBe(7)->and($dispatch->nearbyDistanceM())->toBe(900);
    $page->assertSet(dispatchField('dispatch.accept_timeout_min'), '0');
});

it('refuses a driver rule outside its range, and says which one', function (string $key, string $value) {
    Livewire::actingAs($this->admin)->test(SettingsIndex::class)->set('tab', 'ops_clock')->set(dispatchField($key), $value)->call('save')->assertHasErrors([dispatchField($key)]);
    expect(app(Settings::class)->get($key))->toBeNull();
})->with([
    'weight zero' => ['dispatch.two_person_weight_kg', '0'],
    'weight too high' => ['dispatch.two_person_weight_kg', '501'],
    'accept time-out too long' => ['dispatch.accept_timeout_min', '121'],
    'accept time-out negative' => ['dispatch.accept_timeout_min', '-1'],
    'silent driver too soon' => ['dispatch.stale_duty_min', '4'],
    'attempts zero' => ['dispatch.max_auto_attempts', '0'],
    'attempts too many' => ['dispatch.max_auto_attempts', '11'],
    'close by minutes zero' => ['dispatch.nearby_eta_min', '0'],
    'close by distance too small' => ['dispatch.nearby_distance_m', '99'],
    'not a number' => ['dispatch.stale_duty_min', 'soon'],
]);

it('says nothing changed and stores nothing when the defaults are saved as they are', function () {
    Livewire::actingAs($this->admin)->test(SettingsIndex::class)->set('tab', 'ops_clock')->call('save')->assertHasNoErrors();
    foreach (array_keys(DISPATCH_FIELDS) as $key) {
        expect(app(Settings::class)->all())->not->toHaveKey($key);
    }
});
