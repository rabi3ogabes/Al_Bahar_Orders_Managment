<?php

use App\Domain\Settings\Services\Settings;
use App\Livewire\Admin\Organization\SettingsIndex;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

/** Settings → Checkout & fees → "Staff and driver apps: how to change an order status" (buttons | slide), served by GET /api/v1/app-config. */
it('serves buttons until the admin chooses otherwise', function () {
    $this->getJson('/api/v1/app-config')->assertOk()->assertJsonPath('data.ops.status_style', 'buttons');
    expect(config('platform.ops.status_style'))->toBe('buttons');
});

it('serves slide to confirm once the admin chose it, and buttons again when they switch back', function () {
    app(Settings::class)->set('ops.status_style', 'slide');
    $this->getJson('/api/v1/app-config')->assertOk()->assertJsonPath('data.ops.status_style', 'slide');
    app(Settings::class)->set('ops.status_style', 'buttons');
    $this->getJson('/api/v1/app-config')->assertOk()->assertJsonPath('data.ops.status_style', 'buttons');
});

it('reads anything that is not one of the two as buttons, so a stale or hand-edited value never breaks the apps', function (mixed $stored) {
    app(Settings::class)->set('ops.status_style', $stored);
    $this->getJson('/api/v1/app-config')->assertOk()->assertJsonPath('data.ops.status_style', 'buttons');
})->with(['swipe', '', 'SLIDE', ' slide', null, 7, [['slide']]]);

it('lets the admin choose between the two styles in Settings → Checkout & fees and saves it', function () {
    $this->actingAs(staffWithRole('super_admin'));
    $page = Livewire::test(SettingsIndex::class)->set('tab', 'checkout')->assertSet('values.checkout.ops__status_style', 'buttons')
        ->assertSee(__('org_admin.setting.ops__status_style'))->assertSee(__('org_admin.option.buttons'))->assertSee(__('org_admin.option.slide'));
    $page->set('values.checkout.ops__status_style', 'slide')->call('save')->assertHasNoErrors();
    expect(app(Settings::class)->get('ops.status_style'))->toBe('slide');
    Cache::flush();
    $this->getJson('/api/v1/app-config')->assertJsonPath('data.ops.status_style', 'slide');
    Livewire::test(SettingsIndex::class)->set('tab', 'checkout')->assertSet('values.checkout.ops__status_style', 'slide');
});

it('refuses any other value, and a user who cannot edit settings cannot change it', function () {
    $this->actingAs(staffWithRole('super_admin'));
    Livewire::test(SettingsIndex::class)->set('tab', 'checkout')->set('values.checkout.ops__status_style', 'swipe')->call('save')->assertHasErrors(['values.checkout.ops__status_style']);
    expect(app(Settings::class)->get('ops.status_style'))->toBe('buttons');

    $this->actingAs(staffWithRole('auditor'));
    Livewire::test(SettingsIndex::class)->set('tab', 'checkout')->set('values.checkout.ops__status_style', 'slide')->call('save')->assertForbidden();
    expect(app(Settings::class)->get('ops.status_style'))->toBe('buttons');
});

it('has the label, the help text and both options in English and Arabic', function () {
    foreach (['en', 'ar'] as $locale) {
        foreach (['org_admin.setting.ops__status_style', 'org_admin.setting_hint.ops__status_style', 'org_admin.option.buttons', 'org_admin.option.slide'] as $key) {
            expect(__($key, [], $locale))->not->toBe($key)->and(__($key, [], $locale))->not->toBe('');
        }
    }
    expect(__('org_admin.setting.ops__status_style', [], 'en'))->toBe('Staff and driver apps: how to change an order status')
        ->and(__('org_admin.option.slide', [], 'en'))->toBe('Slide to confirm');
});

it('stays a public, cacheable, secret-free document', function () {
    $r = $this->getJson('/api/v1/app-config')->assertOk();
    expect($r->headers->get('Cache-Control'))->toContain('public')->toContain('max-age=60')->and(array_keys($r->json('data.ops')))->toBe(['status_style']);
});
