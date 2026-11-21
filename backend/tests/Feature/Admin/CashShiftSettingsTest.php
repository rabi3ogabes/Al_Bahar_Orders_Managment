<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Identity\Models\User;
use App\Domain\Payments\Services\CashShiftSettings;
use App\Domain\Settings\Services\Settings;
use App\Livewire\Admin\Organization\SettingsIndex;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

/** Settings > Cash: what happens to cash while no shift is open, the reason limit, the second-person limit and when an open shift needs attention. */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->admin = User::factory()->superAdmin()->create();
});

it('lists the Cash tab with its four settings, their defaults and a help line, in English and Arabic', function (string $locale) {
    app()->setLocale($locale);
    $page = Livewire::actingAs($this->admin)->test(SettingsIndex::class)->set('tab', 'cash')->assertOk();
    $html = $page->html();

    expect(array_keys(SettingsIndex::schema()['cash']))->toBe(['cash.shift_policy', 'cash.shift_note_above', 'cash.shift_review_above', 'cash.shift_max_open_hours']);
    $page->assertSet('values.cash.cash__shift_policy', 'attach')->assertSet('values.cash.cash__shift_note_above', '1.00')->assertSet('values.cash.cash__shift_review_above', '50.00')
        ->assertSet('values.cash.cash__shift_max_open_hours', '16');
    foreach (['policy', 'note_above', 'review_above', 'max_open_hours'] as $name) {
        expect($html)->toContain(e(__('cash_shift.setting.'.$name, [], $locale)))->toContain(e(__('cash_shift.setting_hint.'.$name, [], $locale)));
    }
    expect($html)->toContain(__('org_admin.settings_tab.cash', [], $locale))->toContain(__('org_admin.settings_tab_hint.cash', [], $locale))
        ->toContain(__('org_admin.option.attach', [], $locale))->toContain(__('org_admin.option.block', [], $locale))
        ->not->toContain('cash_shift.')->not->toContain('org_admin.');
})->with(['en', 'ar']);

it('saves the settings, writes them to the audit log and uses them', function () {
    Livewire::actingAs($this->admin)->test(SettingsIndex::class)->set('tab', 'cash')
        ->set('values.cash.cash__shift_policy', 'block')->set('values.cash.cash__shift_note_above', '2.50')->set('values.cash.cash__shift_review_above', '75')->set('values.cash.cash__shift_max_open_hours', '10')
        ->call('save')->assertHasNoErrors()->assertDispatched('toast', type: 'success');

    $s = app(CashShiftSettings::class);
    expect($s->policy())->toBe('block')->and($s->blocksWithoutShift())->toBeTrue()->and($s->noteAbove()->toDecimal())->toBe('2.50')->and($s->reviewAbove()->toDecimal())->toBe('75.00')->and($s->maxOpenHours())->toBe(10)
        ->and(AuditLog::where('action', 'settings.updated')->where('metadata->key', 'cash.shift_policy')->count())->toBe(1);
});

it('refuses values that make no sense', function () {
    $page = Livewire::actingAs($this->admin)->test(SettingsIndex::class)->set('tab', 'cash');
    $page->set('values.cash.cash__shift_policy', 'whenever')->call('save')->assertHasErrors(['values.cash.cash__shift_policy']);
    $page->set('values.cash.cash__shift_policy', 'attach')->set('values.cash.cash__shift_note_above', '-1')->call('save')->assertHasErrors(['values.cash.cash__shift_note_above']);
    $page->set('values.cash.cash__shift_note_above', '20')->set('values.cash.cash__shift_review_above', '5')->call('save')->assertHasErrors(['values.cash.cash__shift_review_above'])
        ->assertSee('cannot be lower than the reason limit');
    $page->set('values.cash.cash__shift_review_above', '50')->set('values.cash.cash__shift_max_open_hours', '0')->call('save')->assertHasErrors(['values.cash.cash__shift_max_open_hours']);
    $page->set('values.cash.cash__shift_max_open_hours', '73')->call('save')->assertHasErrors(['values.cash.cash__shift_max_open_hours']);
    expect(app(Settings::class)->get('cash.shift_policy'))->toBeNull();                       // nothing was stored by the refused saves
});

it('falls back to the safe default when a stored value is not valid, so a bad row never blocks a cashier', function () {
    $settings = app(Settings::class);
    foreach (['cash.shift_policy' => 'nonsense', 'cash.shift_note_above' => 'lots', 'cash.shift_review_above' => '-5', 'cash.shift_max_open_hours' => '0'] as $key => $value) {
        $settings->set($key, $value);
    }
    $s = app(CashShiftSettings::class);
    expect($s->policy())->toBe('attach')->and($s->noteAbove()->toDecimal())->toBe('1.00')->and($s->reviewAbove()->toDecimal())->toBe('50.00')->and($s->maxOpenHours())->toBe(16);
});
