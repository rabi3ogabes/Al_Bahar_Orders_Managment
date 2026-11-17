<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Cms\Services\AppFooterService;
use App\Domain\Cms\Services\ThemeService;
use App\Domain\Settings\Services\Settings;
use App\Livewire\Admin\Content\AppFooterIndex;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    app(ThemeService::class)->seedPresets();
    Cache::flush();
});

function dealFooterWith(array $over): array
{
    return array_replace(app(AppFooterService::class)->defaults(), $over);
}

/** Home, categories, Deals, cart, account. */
function dealsItems(bool $dealsOn = true): array
{
    return [['key' => 'home', 'enabled' => true], ['key' => 'categories', 'enabled' => true], ['key' => 'deals', 'enabled' => $dealsOn], ['key' => 'cart', 'enabled' => true], ['key' => 'account', 'enabled' => true]];
}

it('publishes the middle button in the theme payload, and "none" by default', function () {
    expect($this->getJson('/api/v1/theme')->assertOk()->json('data.footer.center'))->toBe('none');

    app(AppFooterService::class)->save(dealFooterWith(['center' => 'deals', 'items' => dealsItems()]));
    $f = $this->getJson('/api/v1/theme')->json('data.footer');

    expect($f['center'])->toBe('deals')->and(array_column($f['items'], 'key'))->toBe(['home', 'categories', 'deals', 'cart', 'account']);
});

it('knows the Deals destination and its icons', function () {
    expect(AppFooterService::ITEMS)->toContain('deals')->and(AppFooterService::DEFAULT_ICONS['deals'])->toBe('flame')->and(AppFooterService::ICONS)->toContain('flame', 'bolt', 'timer', 'crown')
        ->and(AppFooterService::CENTERS)->toBe(['none', 'auctions', 'deals']);
    $saved = app(AppFooterService::class)->save(dealFooterWith(['items' => [['key' => 'home', 'enabled' => true, 'icon' => 'crown'], ['key' => 'deals', 'enabled' => true, 'icon' => 'bolt'], ['key' => 'cart', 'enabled' => true, 'icon' => 'timer']]]));
    expect(array_column($saved['items'], 'icon'))->toBe(['crown', 'bolt', 'timer']);
});

it('accepts a middle button only when its item is in the menu and switched on', function () {
    $svc = app(AppFooterService::class);
    expect($svc->save(dealFooterWith(['center' => 'deals', 'items' => dealsItems()]))['center'])->toBe('deals');
    expect($svc->save(dealFooterWith(['center' => 'auctions', 'items' => [['key' => 'home', 'enabled' => true], ['key' => 'auctions', 'enabled' => true], ['key' => 'cart', 'enabled' => true]]]))['center'])->toBe('auctions');
    expect($svc->save(dealFooterWith(['center' => 'none', 'items' => dealsItems(false)]))['center'])->toBe('none');
});

it('refuses a bad middle button', function (array $over) {
    expect(fn () => app(AppFooterService::class)->validate(dealFooterWith($over)))->toThrow(ValidationException::class);
})->with([
    'deals switched off' => [['center' => 'deals', 'items' => dealsItems(false)]],
    'deals not in the menu' => [['center' => 'deals']],
    'auctions not in the menu' => [['center' => 'auctions', 'items' => dealsItems()]],
    'a destination that cannot be the middle' => [['center' => 'cart']],
    'an unknown word' => [['center' => 'everything']],
    'six items' => [['center' => 'deals', 'items' => array_merge(dealsItems(), [['key' => 'orders', 'enabled' => true]])]],
    'home off' => [['center' => 'deals', 'items' => [['key' => 'home', 'enabled' => false], ['key' => 'categories', 'enabled' => true], ['key' => 'deals', 'enabled' => true], ['key' => 'cart', 'enabled' => true]]]],
    'fewer than three on' => [['center' => 'deals', 'items' => [['key' => 'home', 'enabled' => true], ['key' => 'deals', 'enabled' => true], ['key' => 'cart', 'enabled' => false]]]],
]);

it('treats an older saved footer without the middle button as "none" and still accepts it', function () {
    app(Settings::class)->set('app.footer', ['design' => 'glass', 'show_labels' => true, 'cart_badge' => true, 'accent' => null,
        'items' => [['key' => 'home', 'enabled' => true, 'icon' => null, 'label_en' => null, 'label_ar' => null], ['key' => 'auctions', 'enabled' => true, 'icon' => null, 'label_en' => null, 'label_ar' => null],
            ['key' => 'cart', 'enabled' => true, 'icon' => null, 'label_en' => null, 'label_ar' => null]]]);
    Cache::flush();

    $config = app(AppFooterService::class)->config();

    expect($config['design'])->toBe('glass')->and($config['center'])->toBe('none')->and($this->getJson('/api/v1/theme')->json('data.footer.center'))->toBe('none');
});

it('lets staff choose the middle button in the dashboard: it switches the item on, shows it round and raised, saves and is audited', function () {
    $mkt = staffWithRole('marketing_manager');
    $c = Livewire::actingAs($mkt)->test(AppFooterIndex::class)->assertSet('form.center', 'none')->assertSee('Middle button')->call('removeItem', 4); // room for Deals

    $c->call('pickCenter', 'deals')->assertSet('form.center', 'deals')->assertViewHas('dirty', true)->assertSee('The middle button is raised and round', false);
    $items = $c->get('form.items');
    expect(collect($items)->firstWhere('key', 'deals'))->toMatchArray(['enabled' => true]);
    $c->call('save')->assertHasNoErrors()->assertViewHas('dirty', false);

    expect(app(AppFooterService::class)->config()['center'])->toBe('deals')->and(AuditLog::where('action', 'cms.footer_updated')->count())->toBe(1);
    $this->getJson('/api/v1/theme')->assertJsonPath('data.footer.center', 'deals');
});

it('switches an item that was off back on when it becomes the middle button', function () {
    app(AppFooterService::class)->save(dealFooterWith(['items' => dealsItems(false)]));
    $c = Livewire::actingAs(staffWithRole('marketing_manager'))->test(AppFooterIndex::class);

    $c->call('pickCenter', 'deals');

    expect(collect($c->get('form.items'))->firstWhere('key', 'deals')['enabled'])->toBeTrue();
});

it('will not choose a middle button when the menu is full, and says why', function () {
    $c = Livewire::actingAs(staffWithRole('marketing_manager'))->test(AppFooterIndex::class); // the default menu has five items, none of them Deals

    $c->call('pickCenter', 'deals')->assertSet('form.center', 'none')->assertDispatched('toast');
    expect(collect($c->get('form.items'))->pluck('key')->all())->not->toContain('deals');
    // Making room first works.
    $c->call('removeItem', 4)->call('pickCenter', 'deals')->assertSet('form.center', 'deals');
});

it('puts the middle button back to "none" when its item is switched off or removed, and says so', function () {
    $c = Livewire::actingAs(staffWithRole('marketing_manager'))->test(AppFooterIndex::class)->call('removeItem', 4)->call('pickCenter', 'deals')->assertSet('form.center', 'deals');
    $at = collect($c->get('form.items'))->search(fn ($i) => $i['key'] === 'deals');

    $c->set("form.items.$at.enabled", false)->assertSet('form.center', 'none')->assertSet('centerReset', true)->assertSee('went back to None');
    $c->call('pickCenter', 'deals')->assertSet('centerReset', false)->assertSet('form.center', 'deals');
    $c->call('removeItem', $at)->assertSet('form.center', 'none');
});

it('cannot be talked into a middle button whose item is off: the page puts it back to none, and the service refuses it anyway', function () {
    $c = Livewire::actingAs(staffWithRole('marketing_manager'))->test(AppFooterIndex::class);
    $c->set('form.center', 'deals')->assertSet('form.center', 'none')->call('save')->assertHasNoErrors();
    expect(app(AppFooterService::class)->config()['center'])->toBe('none');
    expect(fn () => app(AppFooterService::class)->save(dealFooterWith(['center' => 'deals'])))->toThrow(ValidationException::class);
});

it('draws the raised round button in the preview, in the middle of the bar', function () {
    $c = Livewire::actingAs(staffWithRole('marketing_manager'))->test(AppFooterIndex::class)->call('removeItem', 4)->call('pickCenter', 'deals');

    $html = $c->html();

    expect(substr_count($html, '-mt-5'))->toBeGreaterThanOrEqual(1)->and($html)->toContain('ring-4');
});
