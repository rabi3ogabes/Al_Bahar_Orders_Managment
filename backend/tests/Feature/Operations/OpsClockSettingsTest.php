<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Identity\Models\Permission;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Models\UserRole;
use App\Domain\Operations\Services\OpsClock;
use App\Domain\Settings\Models\SystemSetting;
use App\Domain\Settings\Services\Settings;
use App\Livewire\Admin\Organization\SettingsIndex;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

require_once __DIR__.'/OpsSupport.php';

/** Settings > Operations clock: twelve limits, each with a help line, a default, the unit, and amber always below red. */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->admin = User::factory()->superAdmin()->create();
});

const OPS_FIELDS = [
    'pending_amber_minutes' => 5, 'pending_red_minutes' => 15, 'preparing_amber_minutes' => 45, 'preparing_red_minutes' => 90, 'ready_amber_minutes' => 30, 'ready_red_minutes' => 60,
    'driver_amber_minutes' => 5, 'driver_red_minutes' => 10, 'pickup_amber_days' => 2, 'pickup_red_days' => 4, 'cash_amber_hours' => 24, 'cash_red_hours' => 48,
];

function opsField(string $name): string
{
    return 'values.ops_clock.ops_clock__'.$name;
}

it('shows every limit with its default, its unit and a one-line help text, in both languages', function (string $locale) {
    app()->setLocale($locale);
    $page = Livewire::actingAs(test()->admin)->test(SettingsIndex::class)->set('tab', 'ops_clock')->assertOk();
    $html = $page->html();
    foreach (OPS_FIELDS as $name => $default) {
        $page->assertSet(opsField($name), (string) $default);
        expect($html)->toContain('ops_clock__'.$name)->toContain(__('ops_live.setting.ops_clock__'.$name, [], $locale))->toContain(__('ops_live.setting_hint.ops_clock__'.$name, [], $locale));
    }
    expect($html)->toContain(__('ops_live.tab', [], $locale))->toContain(__('ops_live.tab_hint', [], $locale))->not->toContain('ops_live.')->not->toContain('org_admin.')
        ->toContain(__('org_admin.default_value', ['value' => 15], $locale));
    // each help text is a single line: no line breaks, and short enough to read at a glance
    foreach (['en', 'ar'] as $l) {
        foreach (array_keys(OPS_FIELDS) as $name) {
            $hint = __('ops_live.setting_hint.ops_clock__'.$name, [], $l);
            expect($hint)->not->toContain("\n")->and(mb_strlen($hint))->toBeLessThan(160)->and($hint)->not->toBe('ops_live.setting_hint.ops_clock__'.$name);
        }
    }
})->with(['en', 'ar']);

it('lists the section in the settings tabs with its own icon', function () {
    $page = Livewire::actingAs($this->admin)->test(SettingsIndex::class);
    expect(array_keys(SettingsIndex::schema()))->toContain('ops_clock')->and($page->html())->toContain(__('ops_live.tab'));
    expect(array_keys(SettingsIndex::schema()['ops_clock']))->toHaveCount(12)
        ->and(array_keys(SettingsIndex::schema()['ops_clock'])[0])->toBe('ops_clock.pending_amber_minutes');
});

it('saves the limits through the settings service, audits them, and the clock uses them from then on', function () {
    $page = Livewire::actingAs($this->admin)->test(SettingsIndex::class)->set('tab', 'ops_clock')
        ->set(opsField('pending_amber_minutes'), '8')->set(opsField('pending_red_minutes'), '25')->set(opsField('cash_amber_hours'), '12')
        ->call('save')->assertHasNoErrors()->assertDispatched('toast', type: 'success');
    $settings = app(Settings::class);
    expect($settings->get('ops_clock.pending_amber_minutes'))->toBe(8)->and($settings->get('ops_clock.pending_red_minutes'))->toBe(25)->and($settings->get('ops_clock.cash_amber_hours'))->toBe(12)
        ->and(AuditLog::where('action', 'settings.updated')->count())->toBe(3)
        ->and(SystemSetting::where('group', 'ops_clock')->count())->toBe(3);   // only what changed is stored: the rest follows the defaults
    app()->forgetScopedInstances();
    expect(app(OpsClock::class)->limits(OpsClock::PENDING))->toBe(['amber' => 8, 'red' => 25])->and(app(OpsClock::class)->limits(OpsClock::CASH)['amber'])->toBe(720);
    $page->assertSet(opsField('pending_amber_minutes'), '8');
});

it('says "nothing changed" and stores nothing when the defaults are saved as they are', function () {
    Livewire::actingAs($this->admin)->test(SettingsIndex::class)->set('tab', 'ops_clock')->call('save')->assertHasNoErrors()->assertDispatched('toast', type: 'success', message: __('org_admin.settings_unchanged'));
    expect(SystemSetting::where('group', 'ops_clock')->count())->toBe(0);
});

it('refuses an amber limit that is not lower than the red one, and says why', function (string $amber, string $red) {
    $page = Livewire::actingAs($this->admin)->test(SettingsIndex::class)->set('tab', 'ops_clock')->set(opsField('preparing_amber_minutes'), $amber)->set(opsField('preparing_red_minutes'), $red)->call('save');
    $page->assertHasErrors([opsField('preparing_amber_minutes')])->assertSee(__('ops_live.setting_lt'));
    expect(SystemSetting::where('group', 'ops_clock')->count())->toBe(0);
})->with([['90', '90'], ['100', '90'], ['46', '45']]);

it('refuses values that are not a whole number of at least 1 and not above the largest allowed', function (string $value) {
    Livewire::actingAs($this->admin)->test(SettingsIndex::class)->set('tab', 'ops_clock')->set(opsField('pickup_red_days'), $value)->call('save')->assertHasErrors([opsField('pickup_red_days')]);
    expect(SystemSetting::where('group', 'ops_clock')->count())->toBe(0);
})->with(['', '0', '-3', '2.5', 'soon', '91']);

it('validates the pair of each kind separately and the whole tab at once', function () {
    Livewire::actingAs($this->admin)->test(SettingsIndex::class)->set('tab', 'ops_clock')
        ->set(opsField('cash_amber_hours'), '50')->set(opsField('pending_amber_minutes'), '3')->call('save')
        ->assertHasErrors([opsField('cash_amber_hours')])->assertHasNoErrors([opsField('pending_amber_minutes')]);
    expect(SystemSetting::where('group', 'ops_clock')->count())->toBe(0);                // one bad pair saves nothing
});

it('lets only people who may edit settings save the section', function () {
    $viewer = opsStaffWith(opsBranch(), ['settings.view']);
    $role = Role::where('key', 'admin')->firstOrFail();
    $global = User::factory()->staff()->create();
    UserRole::create(['user_id' => $global->id, 'role_id' => Role::create(['key' => 'settings_viewer', 'name_en' => 'V', 'name_ar' => 'ع', 'scope' => 'global'])->id]);
    Role::where('key', 'settings_viewer')->firstOrFail()->permissions()->attach(Permission::where('key', 'settings.view')->pluck('id'));
    Livewire::actingAs($global)->test(SettingsIndex::class)->set('tab', 'ops_clock')->set(opsField('pending_amber_minutes'), '9')->call('save')->assertForbidden();
    expect(SystemSetting::where('group', 'ops_clock')->count())->toBe(0)->and($role->key)->toBe('admin');
    // a branch-scoped viewer cannot even open the page (settings are company-wide)
    Livewire::actingAs($viewer)->test(SettingsIndex::class)->assertForbidden();
});

it('leaves the other settings tabs working', function () {
    Livewire::actingAs($this->admin)->test(SettingsIndex::class)->set('tab', 'checkout')->set('values.checkout.checkout__service_fee', '7.00')->call('save')->assertHasNoErrors();
    expect(app(Settings::class)->get('checkout.service_fee'))->toBe('7.00');
    Livewire::actingAs($this->admin)->withQueryParams(['tab' => 'nonsense'])->test(SettingsIndex::class)->assertSet('tab', 'general');
    Livewire::actingAs($this->admin)->withQueryParams(['tab' => 'ops_clock'])->test(SettingsIndex::class)->assertSet('tab', 'ops_clock');
});
