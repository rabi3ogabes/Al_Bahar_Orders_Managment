<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Cms\Services\AppFooterService;
use App\Domain\Cms\Services\ThemeService;
use App\Domain\Identity\Models\User;
use App\Domain\Settings\Services\Settings;
use App\Livewire\Admin\Content\AppFooterIndex;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    app(ThemeService::class)->seedPresets();
    Cache::flush();
});

function footerConfig(array $over = []): array
{
    return array_replace(app(AppFooterService::class)->defaults(), $over);
}

it('publishes the default five-tab classic footer in the theme payload', function () {
    $f = $this->getJson('/api/v1/theme')->assertOk()->json('data.footer');
    expect($f['design'])->toBe('classic')->and($f['show_labels'])->toBeTrue()->and($f['cart_badge'])->toBeTrue()->and($f['accent'])->toBeNull()
        ->and(array_column($f['items'], 'key'))->toBe(['home', 'categories', 'auctions', 'cart', 'account']);
});

it('accepts a valid custom footer, cleans it and publishes it at once (cache cleared)', function () {
    $this->getJson('/api/v1/theme'); // warm the cache
    $svc = app(AppFooterService::class);
    $svc->save(footerConfig([
        'design' => 'bubble', 'accent' => '#aabbcc', 'show_labels' => false, 'unknown' => 'x',
        'items' => [
            ['key' => 'home', 'enabled' => true, 'icon' => 'star', 'label_en' => ' <b>Start</b> ', 'label_ar' => 'ابدأ', 'extra' => 1],
            ['key' => 'orders', 'enabled' => true], ['key' => 'cart', 'enabled' => true, 'icon' => 'bag'], ['key' => 'support', 'enabled' => false],
        ],
    ]));
    $f = $this->getJson('/api/v1/theme')->json('data.footer');
    expect($f['design'])->toBe('bubble')->and($f['accent'])->toBe('#AABBCC')->and($f['show_labels'])->toBeFalse()
        ->and($f)->not->toHaveKey('unknown')
        ->and($f['items'][0])->toBe(['key' => 'home', 'enabled' => true, 'icon' => 'star', 'label_en' => 'Start', 'label_ar' => 'ابدأ'])
        ->and($f['items'][1])->toBe(['key' => 'orders', 'enabled' => true, 'icon' => null, 'label_en' => null, 'label_ar' => null])
        ->and($f['items'][3]['enabled'])->toBeFalse();
});

it('rejects anything outside the allow-lists, the limits and the home rule', function (array $over) {
    expect(fn () => app(AppFooterService::class)->validate(footerConfig($over)))->toThrow(ValidationException::class);
})->with([
    'unknown design' => [['design' => 'neon']],
    'bad colour' => [['accent' => 'red']],
    'short colour' => [['accent' => '#abc']],
    'unknown destination' => [['items' => [['key' => 'home', 'enabled' => true], ['key' => 'admin', 'enabled' => true], ['key' => 'cart', 'enabled' => true]]]],
    'unknown icon' => [['items' => [['key' => 'home', 'enabled' => true, 'icon' => 'skull'], ['key' => 'cart', 'enabled' => true], ['key' => 'account', 'enabled' => true]]]],
    'too many items' => [['items' => array_map(fn ($k) => ['key' => $k, 'enabled' => true], ['home', 'categories', 'auctions', 'cart', 'account', 'orders'])]],
    'too few items' => [['items' => [['key' => 'home', 'enabled' => true], ['key' => 'cart', 'enabled' => true]]]],
    'fewer than 3 enabled' => [['items' => [['key' => 'home', 'enabled' => true], ['key' => 'cart', 'enabled' => true], ['key' => 'account', 'enabled' => false]]]],
    'home missing' => [['items' => [['key' => 'categories', 'enabled' => true], ['key' => 'cart', 'enabled' => true], ['key' => 'account', 'enabled' => true]]]],
    'home switched off' => [['items' => [['key' => 'home', 'enabled' => false], ['key' => 'categories', 'enabled' => true], ['key' => 'cart', 'enabled' => true], ['key' => 'account', 'enabled' => true]]]],
    'duplicate item' => [['items' => [['key' => 'home', 'enabled' => true], ['key' => 'cart', 'enabled' => true], ['key' => 'cart', 'enabled' => true]]]],
    'long label' => [['items' => [['key' => 'home', 'enabled' => true, 'label_en' => str_repeat('a', 21)], ['key' => 'cart', 'enabled' => true], ['key' => 'account', 'enabled' => true]]]],
]);

it('falls back to the default when the stored footer is broken', function () {
    app(Settings::class)->set('app.footer', ['design' => 'neon', 'items' => 'nope']);
    Cache::flush();
    expect(app(AppFooterService::class)->config()['design'])->toBe('classic');
    $this->getJson('/api/v1/theme')->assertJsonPath('data.footer.design', 'classic');
});

it('lets marketing staff edit the footer in the dashboard, keeps Save off until something changes and writes an audit entry', function () {
    $mkt = staffWithRole('marketing_manager');
    $this->actingAs($mkt)->get(route('admin.appearance.footer'))->assertOk()->assertSee('App footer');
    $this->actingAs(staffWithRole('pickup_employee', null))->get(route('admin.appearance.footer'))->assertForbidden();

    $c = Livewire::actingAs($mkt)->test(AppFooterIndex::class)->assertViewHas('dirty', false);
    $c->call('pickDesign', 'glass')->assertViewHas('dirty', true)
        ->call('move', 1, 1)->call('removeItem', 4)->call('addItem')->set('form.items.3.label_en', 'Basket')
        ->call('save')->assertHasNoErrors()->assertViewHas('dirty', false)->assertSet('justSaved', true);
    $f = app(AppFooterService::class)->config();
    expect($f['design'])->toBe('glass')->and($f['items'][0]['key'])->toBe('home')->and($f['items'][3]['label_en'])->toBe('Basket')
        ->and(count($f['items']))->toBe(5);
    expect(AuditLog::where('action', 'cms.footer_updated')->count())->toBe(1);
    $this->getJson('/api/v1/theme')->assertJsonPath('data.footer.design', 'glass');
});

it('shows validation errors in the dashboard and never saves a footer without Home on', function () {
    $mkt = staffWithRole('marketing_manager');
    $c = Livewire::actingAs($mkt)->test(AppFooterIndex::class)
        ->set('form.items.0.enabled', false)->call('save')->assertHasErrors(['form.items']);
    expect(app(AppFooterService::class)->config()['items'][0]['enabled'])->toBeTrue();
    $c->set('form.items.0.enabled', true)->set('form.accent', 'red')->call('save')->assertHasErrors(['form.accent']);
    // Home can never be removed and at least 3 items stay.
    $c->set('form.accent', null)->call('removeItem', 0)->assertSet('form.items.0.key', 'home');
    $c->call('removeItem', 4)->call('removeItem', 3)->call('removeItem', 2);
    expect(count($c->get('form.items')))->toBe(3);
});

it('resets to the default footer with an audit entry', function () {
    $admin = User::factory()->superAdmin()->create();
    app(AppFooterService::class)->save(footerConfig(['design' => 'floating']));
    Livewire::actingAs($admin)->test(AppFooterIndex::class)->call('resetToDefault')->assertSet('form.design', 'classic');
    expect(app(AppFooterService::class)->config()['design'])->toBe('classic')->and(AuditLog::where('action', 'cms.footer_reset')->count())->toBe(1);
});

it('does not let read-only staff change the footer', function () {
    $viewer = staffWithRole('pickup_employee', null);
    Livewire::actingAs($viewer)->test(AppFooterIndex::class)->assertForbidden();
});
