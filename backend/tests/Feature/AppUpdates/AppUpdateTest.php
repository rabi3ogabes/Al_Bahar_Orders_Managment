<?php

use App\Domain\AppUpdates\Models\AppRelease;
use App\Domain\AppUpdates\Services\AppUpdateService;
use App\Livewire\Admin\Organization\AppUpdatesPage;
use App\Support\DomainException;
use Livewire\Livewire;

function release(string $app = 'customer', string $platform = 'android'): AppRelease
{
    return AppRelease::where('app', $app)->where('platform', $platform)->firstOrFail();
}

it('starts with a row per app and platform, Google Play link prefilled, nobody forced', function () {
    expect(AppRelease::count())->toBe(6)->and(release()->store_url)->toBe('https://play.google.com/store/apps/details?id=qa.albahar.customer')
        ->and(release('driver', 'ios')->store_url)->toBeNull()->and(AppRelease::where('min_build', '>', 0)->count())->toBe(0);
    $this->getJson('/api/v1/app-update?app=customer&platform=android&build=1')->assertOk()->assertJsonPath('data.status', 'ok');
});

it('tells an old build an update is available, and a very old build it is required', function () {
    $staff = staffWithRole('super_admin');
    app(AppUpdateService::class)->save(release(), ['latest_version' => '1.2.0', 'latest_build' => 20, 'min_build' => 15, 'store_url' => 'https://play.google.com/x', 'message_en' => 'Please update', 'message_ar' => 'يرجى التحديث'], $staff);

    $this->getJson('/api/v1/app-update?app=customer&platform=android&build=20')->assertOk()->assertJsonPath('data.status', 'ok');
    $this->getJson('/api/v1/app-update?app=customer&platform=android&build=17')->assertOk()->assertJsonPath('data.status', 'available')->assertJsonPath('data.latest_version', '1.2.0');
    $r = $this->getJson('/api/v1/app-update?app=customer&platform=android&build=14')->assertOk()->assertJsonPath('data.status', 'required')->json('data');
    expect($r['message']['ar'])->toBe('يرجى التحديث')->and($r['store_url'])->toBe('https://play.google.com/x');
    // other app / platform untouched
    $this->getJson('/api/v1/app-update?app=driver&platform=android&build=1')->assertJsonPath('data.status', 'ok');
    $this->getJson('/api/v1/app-update?app=customer&platform=ios&build=1')->assertJsonPath('data.status', 'ok');
    $this->getJson('/api/v1/app-update?app=customer&platform=android')->assertStatus(422);
});

it('refuses every API call from a forced-out build with 426, but not the update check, other apps, or requests without the headers', function () {
    $staff = staffWithRole('super_admin');
    app(AppUpdateService::class)->save(release(), ['latest_build' => 20, 'min_build' => 20, 'store_url' => 'https://play.google.com/x'], $staff);
    $old = ['X-App' => 'customer', 'X-App-Platform' => 'android', 'X-App-Build' => '12'];

    $this->getJson('/api/v1/products', $old)->assertStatus(426)->assertJsonPath('code', 'update_required')->assertJsonPath('update.status', 'required');
    $this->getJson('/api/v1/app-update?app=customer&platform=android&build=12', $old)->assertOk();
    $this->getJson('/api/v1/products', ['X-App' => 'customer', 'X-App-Platform' => 'android', 'X-App-Build' => '20'])->assertOk();
    $this->getJson('/api/v1/products', ['X-App' => 'driver', 'X-App-Platform' => 'android', 'X-App-Build' => '1'])->assertOk();
    $this->getJson('/api/v1/products')->assertOk(); // website, partners, older apps
    $this->getJson('/api/v1/products', ['X-App' => 'customer', 'X-App-Platform' => 'ios', 'X-App-Build' => '1'])->assertOk();
});

it('validates the rules: minimum cannot pass the newest build, link must be https, force needs a build and a link', function () {
    $staff = staffWithRole('super_admin');
    $svc = app(AppUpdateService::class);
    expect(fn () => $svc->save(release(), ['latest_build' => 5, 'min_build' => 9], $staff))->toThrow(DomainException::class)
        ->and(fn () => $svc->save(release(), ['latest_build' => 5, 'min_build' => 0, 'store_url' => 'http://insecure'], $staff))->toThrow(DomainException::class)
        ->and(fn () => $svc->forceNow(release(), $staff))->toThrow(DomainException::class)               // no build yet
        ->and(fn () => $svc->forceNow(release('customer', 'ios'), $staff))->toThrow(DomainException::class); // no store link
    $svc->save(release(), ['latest_build' => 7, 'min_build' => 0, 'store_url' => 'https://play.google.com/store/apps/details?id=qa.albahar.customer'], $staff);
    $svc->forceNow(release(), $staff);
    expect(release()->min_build)->toBe(7)->and(release()->forced_at)->not->toBeNull();
    $svc->stopForcing(release(), $staff);
    expect(release()->min_build)->toBe(0)->and(release()->forced_at)->toBeNull();
});

it('lets an admin save, force with a confirmation and stop from the dashboard; branch staff cannot', function () {
    $admin = staffWithRole('super_admin');
    $id = release()->id;
    $c = Livewire::actingAs($admin)->test(AppUpdatesPage::class)->assertOk()->assertSee('Force update')
        ->set("forms.$id.latest_version", '1.3.0')->set("forms.$id.latest_build", '30')->call('save', $id)->assertHasNoErrors();
    expect(release()->latest_build)->toBe(30)->and(release()->min_build)->toBe(0);
    $c->set("forms.$id.store_url", 'http://nope')->call('save', $id)->assertHasErrors("forms.$id.store_url");
    $c->set("forms.$id.store_url", 'https://play.google.com/store/apps/details?id=qa.albahar.customer')->call('askForce', $id)->assertSet('confirmForce', $id)->call('force');
    expect(release()->min_build)->toBe(30);
    $c->call('stop', $id);
    expect(release()->min_build)->toBe(0);
    $this->actingAs(staffWithRole('branch_manager', commerceWorld()->a))->get(route('admin.app-updates.index'))->assertForbidden();
});
