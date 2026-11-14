<?php

use App\Domain\Identity\Models\User;
use App\Support\BuildInfo;
use Illuminate\Support\Facades\File;

/** The dashboard footer says which version is running and when it was last updated. */
beforeEach(function () {
    $this->file = base_path('bootstrap/build.json');
    $this->had = is_file($this->file) ? file_get_contents($this->file) : null;
});

afterEach(function () {
    $this->had !== null ? file_put_contents($this->file, $this->had) : File::delete($this->file);
});

it('reads the version, commit and build time the release script baked into the image', function () {
    file_put_contents($this->file, '{"version":"1.0.412","commit":"8e3f1a2","built_at":"2026-11-14T16:08:00Z"}');

    $b = BuildInfo::get();

    expect($b['version'])->toBe('1.0.412')->and($b['commit'])->toBe('8e3f1a2')->and($b['dev'])->toBeFalse()
        ->and($b['built_at']->utc()->toIso8601ZuluString())->toBe('2026-11-14T16:08:00Z');
});

it('says "dev" when there is no build file, and ignores anything that is not plain version text', function () {
    File::delete($this->file);
    expect(BuildInfo::get())->toMatchArray(['version' => 'dev', 'commit' => null, 'dev' => true]);

    file_put_contents($this->file, json_encode(['version' => '<script>alert(1)</script>', 'commit' => 'a b', 'built_at' => 'not a date']));
    $b = BuildInfo::get();
    expect($b['version'])->toBe('dev')->and($b['commit'])->toBeNull();

    file_put_contents($this->file, '{broken');
    expect(BuildInfo::get()['version'])->toBe('dev');
});

it('shows the version and the update time (Qatar time) in the footer of every dashboard page, in English and Arabic', function () {
    file_put_contents($this->file, '{"version":"1.0.412","commit":"8e3f1a2","built_at":"2026-11-14T16:08:00Z"}');
    $admin = User::factory()->superAdmin()->create();

    $en = $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk();
    $en->assertSee('data-build-footer', false)->assertSee('1.0.412')->assertSee('8e3f1a2')->assertSee('14 Nov 2026, 19:08')->assertSee('datetime="2026-11-14T16:08:00Z"', false);

    $ar = $this->actingAs($admin)->get(route('admin.locale', 'ar'))->assertRedirect();
    $this->actingAs($admin)->withSession(['locale' => 'ar'])->get(route('admin.dashboard'))->assertOk()->assertSee('1.0.412')->assertSee('الإصدار');
});
