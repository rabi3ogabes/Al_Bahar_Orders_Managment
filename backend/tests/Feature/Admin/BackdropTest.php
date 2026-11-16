<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Cms\Models\BrandTheme;
use App\Domain\Cms\Services\BackdropService;
use App\Domain\Cms\Services\ThemeService;
use App\Domain\Identity\Models\User;
use App\Domain\Settings\Services\Settings;
use App\Livewire\Admin\Content\BackdropIndex;
use App\Support\AdminNavigation;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    app(ThemeService::class)->seedPresets();
    Cache::flush();
    Storage::fake(config('platform.media.disk'));
});

function backdrop(): BackdropService
{
    return app(BackdropService::class);
}

function pic(int $w = 1200, int $h = 600, string $name = 'city.jpg'): UploadedFile
{
    return UploadedFile::fake()->image($name, $w, $h);
}

function backdropFiles(): array
{
    return Storage::disk(config('platform.media.disk'))->allFiles('backdrop');
}

// --- the public block ---------------------------------------------------------------------------------------------

it('publishes the default Doha skyline block in the theme payload', function () {
    $b = $this->getJson('/api/v1/theme')->assertOk()->json('data.backdrop');
    expect($b)->toBe(['style' => 'skyline', 'image_url' => null, 'fit' => 'bottom', 'opacity' => 0.08, 'color' => null, 'tint' => null]);
});

it('carries the block in the design preview too', function () {
    backdrop()->save(['style' => 'none', 'opacity' => 20, 'color' => '#f5eedc']);
    $b = $this->getJson('/api/v1/theme?design=royal-navy')->assertOk()->json('data.backdrop');
    expect($b['style'])->toBe('none')->and($b['opacity'])->toBe(0.2)->and($b['color'])->toBe('#F5EEDC');
    // An unknown design key answers with the normal payload, which has the block as well.
    $this->getJson('/api/v1/theme?design=nope')->assertJsonPath('data.backdrop.style', 'none');
});

it('saves, cleans and publishes a background at once (cache cleared)', function () {
    $this->getJson('/api/v1/theme'); // warm the 60 s cache
    backdrop()->save(['style' => 'skyline', 'fit' => 'cover', 'opacity' => '25', 'color' => '#f7f1e6', 'tint' => '#1b2a49', 'evil' => 'x', 'image' => 'backdrop/not-mine.jpg']);
    $b = $this->getJson('/api/v1/theme')->json('data.backdrop');
    expect($b)->toBe(['style' => 'skyline', 'image_url' => null, 'fit' => 'cover', 'opacity' => 0.25, 'color' => '#F7F1E6', 'tint' => '#1B2A49']);
    expect(backdrop()->config())->toBe(['style' => 'skyline', 'image' => null, 'fit' => 'cover', 'opacity' => 25, 'color' => '#F7F1E6', 'tint' => '#1B2A49']);
});

it('stores the strength as a whole percent and publishes it as 0.02 to 0.50', function () {
    backdrop()->save(['opacity' => 2]);
    expect(backdrop()->payload()['opacity'])->toBe(0.02);
    backdrop()->save(['opacity' => 50]);
    expect(backdrop()->payload()['opacity'])->toBe(0.5)->and(backdrop()->config()['opacity'])->toBe(50);
    // A stored value from outside the allowed range is never published: the whole block falls back to the default.
    app(Settings::class)->set('app.backdrop', ['style' => 'skyline', 'fit' => 'bottom', 'opacity' => 99, 'color' => null, 'tint' => null]);
    expect(backdrop()->payload()['opacity'])->toBe(0.08);
});

it('rejects anything outside the allow-lists and the limits', function (array $over) {
    expect(fn () => backdrop()->validate($over))->toThrow(ValidationException::class);
})->with([
    'unknown style' => [['style' => 'video']],
    'unknown fit' => [['fit' => 'tile']],
    'strength below 2' => [['opacity' => 1]],
    'strength above 50' => [['opacity' => 51]],
    'strength not a number' => [['opacity' => 'strong']],
    'strength with decimals' => [['opacity' => 8.5]],
    'bad page colour' => [['color' => 'red']],
    'short page colour' => [['color' => '#fff']],
    'bad skyline colour' => [['tint' => '#12345']],
    'picture path outside backdrop/' => [['image' => '../../.env']],
    'picture path with a sub folder trick' => [['image' => 'backdrop/../secret.png']],
]);

it('turns empty colours into null and writes the others in upper case', function () {
    $c = backdrop()->validate(['color' => '', 'tint' => '#abcdef']);
    expect($c['color'])->toBeNull()->and($c['tint'])->toBe('#ABCDEF');
});

it('falls back to the default when the stored background is broken', function () {
    app(Settings::class)->set('app.backdrop', ['style' => 'neon', 'opacity' => 'x']);
    expect(backdrop()->config())->toBe(backdrop()->defaults());
    $this->getJson('/api/v1/theme')->assertJsonPath('data.backdrop.style', 'skyline')->assertJsonPath('data.backdrop.opacity', 0.08);
    app(Settings::class)->set('app.backdrop', 'nope');
    expect(backdrop()->config())->toBe(backdrop()->defaults());
});

// --- readability of the page colour -------------------------------------------------------------------------------

it('refuses a page colour that makes the theme text hard to read, with a plain message', function () {
    // The default look has dark text (#2A1A20): a dark page colour hides it.
    $e = null;
    try {
        backdrop()->save(['color' => '#8A1538']);
    } catch (ValidationException $e) {
    }
    expect($e)->not->toBeNull()->and($e->errors()['color'][0])->toContain('too close to the text colour')->toContain('4.5');
    expect(backdrop()->config()['color'])->toBeNull();
    // A pale colour is fine.
    expect(backdrop()->save(['color' => '#F5EEDC'])['color'])->toBe('#F5EEDC');
});

it('checks the colour against the text colour of the look that is live now', function () {
    $dark = BrandTheme::where('key', 'qatar-maroon')->value('colors');
    BrandTheme::create(['key' => 'night', 'kind' => 'custom', 'name_en' => 'Night', 'name_ar' => 'ليل', 'is_forced' => true, 'is_enabled' => true,
        'colors' => ['ink' => '#FFFFFF', 'background' => '#101820'] + $dark]);
    Cache::flush();
    expect(backdrop()->ink())->toBe('#FFFFFF');
    expect(fn () => backdrop()->save(['color' => '#FFFFFF']))->toThrow(ValidationException::class); // white on white
    expect(backdrop()->save(['color' => '#1B2A49'])['color'])->toBe('#1B2A49');                      // light text on navy
});

// --- the picture --------------------------------------------------------------------------------------------------

it('stores an uploaded picture under backdrop/ and publishes its URL', function () {
    $c = backdrop()->save(['style' => 'image', 'fit' => 'cover'], pic());
    expect($c['image'])->toStartWith('backdrop/')->and(backdropFiles())->toHaveCount(1);
    $b = $this->getJson('/api/v1/theme')->json('data.backdrop');
    expect($b['style'])->toBe('image')->and($b['fit'])->toBe('cover')->and($b['image_url'])->toEndWith($c['image'])->and($b['image_url'])->toStartWith('http');
});

it('replaces the picture and deletes the old file', function () {
    $first = backdrop()->save(['style' => 'image'], pic())['image'];
    $second = backdrop()->save(['style' => 'image'], pic(1400, 700, 'two.png'))['image'];
    expect($second)->not->toBe($first)->and(backdropFiles())->toBe([$second]);
});

it('refuses a bad picture and keeps the old one', function (UploadedFile $file) {
    $keep = backdrop()->save(['style' => 'image'], pic())['image'];
    expect(fn () => backdrop()->save(['style' => 'image'], $file))->toThrow(ValidationException::class);
    expect(backdrop()->config()['image'])->toBe($keep)->and(backdropFiles())->toBe([$keep]);
})->with([
    'SVG (it can carry script)' => fn () => UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
    'PDF' => fn () => UploadedFile::fake()->create('menu.pdf', 100, 'application/pdf'),
    'narrower than 600 px' => fn () => UploadedFile::fake()->image('small.jpg', 599, 400),
    'larger than 4 MB' => fn () => UploadedFile::fake()->image('huge.jpg', 1200, 600)->size(4097),
]);

it('accepts JPG, PNG and WebP up to 4 MB and 600 px wide', function (string $name) {
    $c = backdrop()->save(['style' => 'image'], UploadedFile::fake()->image($name, 600, 300)->size(4096));
    expect($c['image'])->toStartWith('backdrop/');
})->with(['a.jpg', 'a.png', 'a.webp']);

it('never takes the picture path from the browser', function () {
    expect(backdrop()->save(['style' => 'skyline', 'image' => 'backdrop/someone-elses.jpg'])['image'])->toBeNull();
});

it('will not save "My picture" when there is no picture', function () {
    expect(fn () => backdrop()->save(['style' => 'image']))->toThrow(ValidationException::class);
    expect(backdrop()->config()['style'])->toBe('skyline');
});

it('removes the picture for good and falls back to the skyline', function () {
    $path = backdrop()->save(['style' => 'image'], pic())['image'];
    $c = backdrop()->removeImage();
    expect($c['image'])->toBeNull()->and($c['style'])->toBe('skyline')->and(backdropFiles())->toBe([]);
    expect(Storage::disk(config('platform.media.disk'))->exists($path))->toBeFalse();
    $this->getJson('/api/v1/theme')->assertJsonPath('data.backdrop.style', 'skyline')->assertJsonPath('data.backdrop.image_url', null);
});

it('answers with the skyline when "My picture" is chosen but the file is gone', function () {
    $path = backdrop()->save(['style' => 'image'], pic())['image'];
    Storage::disk(config('platform.media.disk'))->delete($path); // lost behind our back
    Cache::flush();
    $b = $this->getJson('/api/v1/theme')->json('data.backdrop');
    expect($b['style'])->toBe('skyline')->and($b['image_url'])->toBeNull();
    expect(backdrop()->config()['style'])->toBe('image'); // what the admin chose is untouched
});

it('goes back to the default but keeps an uploaded picture', function () {
    $path = backdrop()->save(['style' => 'image', 'opacity' => 30, 'color' => '#F5EEDC', 'tint' => '#1B2A49'], pic())['image'];
    $c = backdrop()->reset();
    expect($c)->toBe(['style' => 'skyline', 'image' => $path, 'fit' => 'bottom', 'opacity' => 8, 'color' => null, 'tint' => null]);
    expect(backdrop()->payload())->toBe(['style' => 'skyline', 'image_url' => null, 'fit' => 'bottom', 'opacity' => 0.08, 'color' => null, 'tint' => null]);
    expect(backdropFiles())->toBe([$path]);
});

// --- the dashboard page -------------------------------------------------------------------------------------------

it('sends guests to the sign-in page and lets the right people open it', function () {
    $this->get(route('admin.appearance.background'))->assertRedirect();
    $this->actingAs(staffWithRole('pickup_employee', null))->get(route('admin.appearance.background'))->assertForbidden();
    $this->actingAs(staffWithRole('marketing_manager'))->get(route('admin.appearance.background'))->assertOk()->assertSee('Page background')->assertSee('Save changes');
    $this->actingAs(staffWithRole('auditor'))->get(route('admin.appearance.background'))->assertOk()->assertSee('You can look at this page but not change it.')->assertDontSee('Save changes');
});

it('adds the page to the Appearance part of the menu for people who may see it', function () {
    $routes = fn (User $u) => collect(AdminNavigation::for($u))->flatMap(fn ($g) => array_column($g['items'], 'route'));
    expect($routes(staffWithRole('marketing_manager')))->toContain('admin.appearance.background')
        ->and($routes(staffWithRole('pickup_employee', null)))->not->toContain('admin.appearance.background');
});

it('opens with the saved values, keeps Save off until something changes and writes an audit entry', function () {
    $mkt = staffWithRole('marketing_manager');
    $c = Livewire::actingAs($mkt)->test(BackdropIndex::class)->assertOk()
        ->assertSet('form', ['style' => 'skyline', 'fit' => 'bottom', 'opacity' => 8, 'color' => null, 'tint' => null])
        ->assertViewHas('dirty', false);

    $c->set('form.opacity', 20)->assertViewHas('dirty', true)
        ->set('form.opacity', 8)->assertViewHas('dirty', false)
        ->set('form.opacity', 20)->set('form.tint', '#1b2a49')->set('form.style', 'none')
        ->call('save')->assertHasNoErrors()->assertSet('justSaved', true)->assertViewHas('dirty', false)->assertDispatched('backdrop-saved');

    expect(backdrop()->config())->toMatchArray(['style' => 'none', 'opacity' => 20, 'tint' => '#1B2A49']);
    $log = AuditLog::where('action', 'cms.backdrop_updated')->sole();
    expect($log->old_values['style'])->toBe('skyline')->and($log->new_values['style'])->toBe('none')->and($log->new_values['opacity'])->toBe(20)
        ->and($log->actor_id)->toBe($mkt->id);
    $this->getJson('/api/v1/theme')->assertJsonPath('data.backdrop.style', 'none')->assertJsonPath('data.backdrop.opacity', 0.2);

    // Editing again clears the "Saved" state.
    $c->set('form.fit', 'cover')->assertSet('justSaved', false)->assertViewHas('dirty', true);
});

it('shows what the preview needs and reacts to every control', function () {
    $c = Livewire::actingAs(staffWithRole('marketing_manager'))->test(BackdropIndex::class)
        ->assertViewHas('theme', fn ($t) => $t['primary'] === '#8A1538' && $t['background'] === '#FCF9FA' && $t['surface'] === '#FFFFFF')
        ->assertSee('Qatar skyline')->assertSee('My picture')->assertSee('Live preview');
    // A readable page colour: good news, Save allowed.
    $c->set('form.color', '#F5EEDC')->assertViewHas('ratio', fn ($r) => $r > 4.5)->assertViewHas('blocked', false)->assertSee('easy to read');
    // A colour that hides the text: the page says so before Save, and Save stays off.
    $c->set('form.color', '#8A1538')->assertViewHas('blocked', true)->assertSee('Too close to the text colour')->assertSee('4.5 : 1');
    // "My picture" with no picture yet cannot be saved.
    $c->set('form.color', null)->set('form.style', 'image')->assertViewHas('needsPicture', true)->assertViewHas('blocked', true)->assertSee('Add a picture to use this option.');
});

it('shows the same colour error when Save is pressed anyway', function () {
    $c = Livewire::actingAs(staffWithRole('marketing_manager'))->test(BackdropIndex::class)
        ->set('form.color', '#8A1538')->call('save')->assertHasErrors(['form.color']);
    expect(backdrop()->config()['color'])->toBeNull();
    $c->set('form.color', '#F5EEDC')->call('save')->assertHasNoErrors();
    expect(backdrop()->config()['color'])->toBe('#F5EEDC');
});

it('rejects tampered values with an error instead of saving them', function () {
    $c = Livewire::actingAs(staffWithRole('marketing_manager'))->test(BackdropIndex::class)
        ->set('form.opacity', 400)->call('save')->assertHasErrors(['form.opacity'])
        ->set('form.opacity', 10)->set('form.style', 'video')->call('save')->assertHasErrors(['form.style']);
    expect(backdrop()->config())->toBe(backdrop()->defaults());
    // An extra field the page never offered is dropped, and the picture path cannot be set from the browser.
    $c->set('form.style', 'skyline')->set('form.image', 'backdrop/other.jpg')->set('form.extra', 'x')->call('save')->assertHasNoErrors();
    expect(backdrop()->config()['image'])->toBeNull()->and(backdrop()->config())->not->toHaveKey('extra');
});

it('uploads a picture, checks it at once and saves it on Save', function () {
    $mkt = staffWithRole('marketing_manager');
    $c = Livewire::actingAs($mkt)->test(BackdropIndex::class)
        ->set('upload', pic(300, 300, 'tiny.jpg'))->assertHasErrors(['upload'])->assertSet('upload', null)->assertSet('form.style', 'skyline');
    expect(backdropFiles())->toBe([]);

    $c->set('upload', pic())->assertHasNoErrors()->assertSet('form.style', 'image')->assertViewHas('dirty', true)->assertViewHas('previewImage', fn ($u) => is_string($u));
    expect(backdropFiles())->toBe([]); // nothing is stored until Save (deferred save)
    $this->getJson('/api/v1/theme')->assertJsonPath('data.backdrop.style', 'skyline');

    $c->call('save')->assertHasNoErrors()->assertSet('upload', null)->assertViewHas('dirty', false);
    expect(backdropFiles())->toHaveCount(1);
    $this->getJson('/api/v1/theme')->assertJsonPath('data.backdrop.style', 'image');
});

it('discards a chosen picture without storing anything', function () {
    $c = Livewire::actingAs(staffWithRole('marketing_manager'))->test(BackdropIndex::class)
        ->set('upload', pic())->assertSet('form.style', 'image')->call('discardUpload')->assertSet('upload', null)->assertSet('form.style', 'skyline')->assertViewHas('dirty', false);
    expect(backdropFiles())->toBe([]);
});

it('removes the stored picture from the page and writes an audit entry', function () {
    $mkt = staffWithRole('marketing_manager');
    $path = backdrop()->save(['style' => 'image'], pic())['image'];
    Livewire::actingAs($mkt)->test(BackdropIndex::class)->assertSet('form.style', 'image')->assertViewHas('storedUrl', fn ($u) => str_ends_with($u, $path))
        ->set('form.opacity', 30)
        ->call('removePicture')->assertSet('form.style', 'skyline')->assertSet('form.opacity', 30)->assertViewHas('storedUrl', null)->assertViewHas('dirty', true);
    expect(backdropFiles())->toBe([])->and(backdrop()->config()['style'])->toBe('skyline');
    $log = AuditLog::where('action', 'cms.backdrop_image_removed')->sole();
    expect($log->old_values['image'])->toBe($path)->and($log->new_values['style'])->toBe('skyline')->and($log->actor_id)->toBe($mkt->id);
});

it('goes back to the default from the page with an audit entry', function () {
    backdrop()->save(['style' => 'none', 'opacity' => 40, 'color' => '#F5EEDC']);
    Livewire::actingAs(staffWithRole('marketing_manager'))->test(BackdropIndex::class)->call('resetToDefault')
        ->assertSet('form', ['style' => 'skyline', 'fit' => 'bottom', 'opacity' => 8, 'color' => null, 'tint' => null])->assertViewHas('dirty', false);
    expect(backdrop()->config())->toBe(backdrop()->defaults())->and(AuditLog::where('action', 'cms.backdrop_reset')->count())->toBe(1);
});

it('lets people with view only look, never change', function () {
    $viewer = staffWithRole('auditor');
    $c = Livewire::actingAs($viewer)->test(BackdropIndex::class)->assertOk()->assertViewHas('canEdit', false);
    $c->set('form.opacity', 30)->assertForbidden();
    Livewire::actingAs($viewer)->test(BackdropIndex::class)->call('save')->assertForbidden();
    Livewire::actingAs($viewer)->test(BackdropIndex::class)->call('removePicture')->assertForbidden();
    Livewire::actingAs($viewer)->test(BackdropIndex::class)->call('resetToDefault')->assertForbidden();
    Livewire::actingAs($viewer)->test(BackdropIndex::class)->call('discardUpload')->assertForbidden();
    Livewire::actingAs($viewer)->test(BackdropIndex::class)->set('upload', pic())->assertForbidden();
    expect(backdrop()->config())->toBe(backdrop()->defaults())->and(AuditLog::where('action', 'like', 'cms.backdrop%')->count())->toBe(0);
});

it('does not open for staff without the content permission', function () {
    Livewire::actingAs(staffWithRole('pickup_employee', null))->test(BackdropIndex::class)->assertForbidden();
});

// --- the three copies of the drawing are generated, never edited ---------------------------------------------------

it('keeps the skyline drawing identical in the server, the web store and the app', function () {
    $server = base_path('public/brand/qatar-skyline.svg');
    $web = base_path('../storefront/src/assets/qatar-skyline.svg');
    $dart = base_path('../mobile/lib/core/theme/qatar_skyline_paths.dart');
    foreach ([$server, $web, $dart] as $file) {
        if (! is_file($file)) {
            $this->markTestSkipped('The drawing is generated by scripts/qatar-skyline/build.py; this checkout has no '.basename($file).'.');
        }
    }
    expect(hash_file('sha256', $web))->toBe(hash_file('sha256', $server), 'The web store copy differs from the server copy: run scripts/qatar-skyline/build.py, never edit them.');
    preg_match('#^// source-sha256: ([0-9a-f]{64})$#m', (string) file_get_contents($dart), $m);
    expect($m[1] ?? null)->toBe(hash_file('sha256', $server), 'The app paths were not generated from this drawing: run scripts/qatar-skyline/build.py.');
});
