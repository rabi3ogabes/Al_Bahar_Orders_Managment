<?php

use App\Domain\Cms\Models\BrandTheme;
use App\Domain\Cms\Services\ThemeService;
use App\Livewire\Admin\Content\ThemeIndex;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    app(ThemeService::class)->seedPresets();
    Cache::flush();
});

it('shows the default Qatar maroon look outside event dates', function () {
    CarbonImmutable::setTestNow('2026-06-10 12:00:00');
    $this->getJson('/api/v1/theme')->assertOk()->assertJsonPath('data.key', 'qatar-maroon')->assertJsonPath('data.colors.primary', '#8A1538')
        ->assertJsonPath('data.kind', 'base')->assertJsonStructure(['data' => ['colors' => ['primary', 'primary_soft', 'accent', 'background', 'surface', 'ink'], 'images' => ['hero', 'banner', 'logo', 'splash'], 'banner', 'version']]);
});

it('switches to National Day by itself every year from 14 to 19 December, in Qatar time', function () {
    foreach (['2026-12-15 10:00:00', '2027-12-18 20:00:00', '2026-12-19 20:00:00'] as $day) {
        CarbonImmutable::setTestNow($day);
        Cache::flush();
        $this->getJson('/api/v1/theme')->assertJsonPath('data.key', 'national-day')->assertJsonPath('data.colors.accent', '#C9A24D')->assertJsonPath('data.banner.title', 'Happy Qatar National Day');
    }
    foreach (['2026-12-13 10:00:00', '2026-12-20 10:00:00'] as $day) {
        CarbonImmutable::setTestNow($day);
        Cache::flush();
        $this->getJson('/api/v1/theme')->assertJsonPath('data.key', 'qatar-maroon');
    }
});

it('keeps events that need confirming switched off until the admin turns them on', function () {
    CarbonImmutable::setTestNow('2027-02-20 12:00:00');
    expect(app(ThemeService::class)->active()->key)->toBe('qatar-maroon');
    BrandTheme::where('key', 'ramadan')->update(['is_enabled' => true]);
    Cache::flush();
    expect(app(ThemeService::class)->active()->key)->toBe('ramadan');
});

it('lets the higher priority event win and a hand-activated look beat the calendar', function () {
    CarbonImmutable::setTestNow('2026-12-16 12:00:00');
    BrandTheme::create(['key' => 'promo', 'kind' => 'custom', 'name_en' => 'Promo', 'name_ar' => 'عرض', 'colors' => BrandTheme::where('key', 'qatar-maroon')->value('colors'), 'recur_start' => '12-10', 'recur_end' => '12-20', 'priority' => 100, 'is_enabled' => true]);
    expect(app(ThemeService::class)->active()->key)->toBe('national-day'); // 200 beats 100
    app(ThemeService::class)->force(BrandTheme::where('key', 'promo')->first());
    expect(app(ThemeService::class)->active()->key)->toBe('promo');
    app(ThemeService::class)->force(null);
    expect(app(ThemeService::class)->active()->key)->toBe('national-day');
});

it('handles a yearly window that wraps over new year', function () {
    $t = BrandTheme::create(['key' => 'winter', 'kind' => 'event', 'name_en' => 'Winter', 'name_ar' => 'شتاء', 'colors' => BrandTheme::where('key', 'qatar-maroon')->value('colors'), 'recur_start' => '12-28', 'recur_end' => '01-03', 'is_enabled' => true]);
    $svc = app(ThemeService::class);
    expect($svc->matches($t, CarbonImmutable::parse('2026-12-30 12:00')))->toBeTrue()
        ->and($svc->matches($t, CarbonImmutable::parse('2027-01-02 12:00')))->toBeTrue()
        ->and($svc->matches($t, CarbonImmutable::parse('2027-01-10 12:00')))->toBeFalse();
});

it('lets marketing staff preview, edit and activate looks, and keeps others out', function () {
    CarbonImmutable::setTestNow('2026-06-10 12:00:00');
    $mkt = staffWithRole('marketing_manager');
    $this->actingAs($mkt)->get(route('admin.appearance.index'))->assertOk()->assertSee('Qatar National Day')->assertSee('Showing now');
    $this->actingAs(staffWithRole('pickup_employee', null))->get(route('admin.appearance.index'))->assertForbidden();

    $nd = BrandTheme::where('key', 'national-day')->first();
    Livewire::actingAs($mkt)->test(ThemeIndex::class)->call('activate', $nd->id);
    expect(app(ThemeService::class)->active()->key)->toBe('national-day');
    $this->getJson('/api/v1/theme')->assertJsonPath('data.key', 'national-day');
    Livewire::actingAs($mkt)->test(ThemeIndex::class)->call('backToSchedule');
    expect(app(ThemeService::class)->active()->key)->toBe('qatar-maroon');
});

it('validates colours when saving a custom look and publishes them', function () {
    $mkt = staffWithRole('marketing_manager');
    $component = Livewire::actingAs($mkt)->test(ThemeIndex::class)->call('create')
        ->set('form.name_en', 'Gold Weekend')->set('form.name_ar', 'عطلة ذهبية')->set('form.colors.primary', 'red');
    $component->call('save')->assertHasErrors(['form.colors.primary']);
    $component->set('form.colors.primary', '#112233')->set('form.colors.accent', '#aabbcc')->call('save')->assertHasNoErrors();
    $t = BrandTheme::where('name_en', 'Gold Weekend')->firstOrFail();
    expect($t->colors['primary'])->toBe('#112233')->and($t->colors['accent'])->toBe('#AABBCC')->and($t->kind)->toBe('custom');
    app(ThemeService::class)->force($t);
    $this->getJson('/api/v1/theme')->assertJsonPath('data.colors.primary', '#112233');
});

it('does not let the default look be deleted', function () {
    $mkt = staffWithRole('marketing_manager');
    $default = BrandTheme::where('is_default', true)->first();
    expect(fn () => Livewire::actingAs($mkt)->test(ThemeIndex::class)->call('delete', $default->id))->not->toThrow(Throwable::class);
    expect(BrandTheme::where('is_default', true)->exists())->toBeTrue();
});
