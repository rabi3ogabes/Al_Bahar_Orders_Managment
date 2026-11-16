<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Cms\Models\BrandTheme;
use App\Domain\Cms\Services\BackdropService;
use App\Domain\Cms\Services\ThemeService;
use App\Domain\Cms\Support\BackdropScenes;
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
    expect($b)->toBe(['style' => 'skyline', 'scene' => 'doha', 'image_url' => null, 'fit' => 'bottom', 'opacity' => 0.08, 'color' => null, 'tint' => null]);
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
    backdrop()->save(['style' => 'skyline', 'scene' => 'souq', 'fit' => 'cover', 'opacity' => '25', 'color' => '#f7f1e6', 'tint' => '#1b2a49', 'evil' => 'x', 'image' => 'backdrop/not-mine.jpg']);
    $b = $this->getJson('/api/v1/theme')->json('data.backdrop');
    expect($b)->toBe(['style' => 'skyline', 'scene' => 'souq', 'image_url' => null, 'fit' => 'cover', 'opacity' => 0.25, 'color' => '#F7F1E6', 'tint' => '#1B2A49']);
    expect(backdrop()->config())->toBe(['style' => 'skyline', 'scene' => 'souq', 'image' => null, 'fit' => 'cover', 'opacity' => 25, 'color' => '#F7F1E6', 'tint' => '#1B2A49']);
});

it('stores the strength as a whole percent and publishes it as 0.02 to 0.50', function () {
    backdrop()->save(['opacity' => 2]);
    expect(backdrop()->payload()['opacity'])->toBe(0.02);
    backdrop()->save(['opacity' => 50]);
    expect(backdrop()->payload()['opacity'])->toBe(0.5)->and(backdrop()->config()['opacity'])->toBe(50);
    // A stored value from outside the allowed range is never published: the whole block falls back to the default.
    app(Settings::class)->set('app.backdrop', ['style' => 'skyline', 'scene' => 'dhow', 'fit' => 'bottom', 'opacity' => 99, 'color' => null, 'tint' => null]);
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

// --- the six built-in artworks ------------------------------------------------------------------------------------

const SCENE_KEYS = ['doha', 'dhow', 'souq', 'dunes', 'museum', 'arabesque'];

it('has six artworks in one registry, each drawn in a file that exists, and only the arabesque is a tile', function () {
    expect(BackdropScenes::keys())->toBe(SCENE_KEYS)->and(BackdropScenes::DEFAULT)->toBe('doha')->and(BackdropScenes::TILE_SIZE)->toBe(168);
    expect(BackdropScenes::file('doha'))->toBe('qatar-skyline.svg')->and(BackdropScenes::file('dhow'))->toBe('qatar-dhow.svg');
    foreach (BackdropScenes::keys() as $key) {
        $svg = (string) file_get_contents(BackdropScenes::path($key));
        preg_match('/viewBox="0 0 (\d+) (\d+)"/', $svg, $box);
        $scene = BackdropScenes::ALL[$key];
        expect([(int) ($box[1] ?? 0), (int) ($box[2] ?? 0)])->toBe([$scene['width'], $scene['height']], "{$key}: the registry box differs from the drawing")
            ->and(BackdropScenes::isTile($key))->toBe($key === 'arabesque')
            ->and(BackdropScenes::url($key))->toStartWith('http')->toEndWith('/brand/'.BackdropScenes::file($key))
            ->and(BackdropScenes::has($key))->toBeTrue();
    }
    // Scenes are 1600 x 340 and stand on the bottom edge; the tile is one 200 x 200 cell.
    expect(collect(BackdropScenes::ALL)->except('arabesque')->every(fn ($s) => $s['width'] === 1600 && $s['height'] === 340))->toBeTrue()
        ->and(BackdropScenes::ALL['arabesque'])->toMatchArray(['width' => 200, 'height' => 200, 'tile' => true]);
});

it('turns anything that is not one of the six keys into the Doha skyline', function (mixed $value) {
    expect(BackdropScenes::has($value))->toBeFalse()->and(BackdropScenes::normalize($value))->toBe('doha');
})->with(['unknown' => 'moon', 'empty' => '', 'null' => null, 'upper case' => 'DOHA', 'with a path' => '../doha', 'a number' => 3, 'a list' => [['dhow']]]);

it('names every artwork in English and in Arabic, written once in the language files', function () {
    $ar = ['doha' => 'أفق الدوحة', 'dhow' => 'الداو على الخليج', 'souq' => 'سوق واقف', 'dunes' => 'كثبان الصحراء', 'museum' => 'الكورنيش ومتحف الفن الإسلامي', 'arabesque' => 'الزخرفة الإسلامية'];
    $en = ['doha' => 'Doha skyline', 'dhow' => 'Dhows on the Gulf', 'souq' => 'Souq Waqif', 'dunes' => 'Desert dunes', 'museum' => 'Corniche and the Museum of Islamic Art', 'arabesque' => 'Islamic geometry'];
    foreach (SCENE_KEYS as $key) {
        expect(BackdropScenes::name($key, 'en'))->toBe($en[$key])->and(BackdropScenes::name($key, 'ar'))->toBe($ar[$key]);
    }
});

it('keeps the English and Arabic language files in step, with no English left in the Arabic one', function () {
    $flat = function (array $lines, string $prefix = '') use (&$flat): array {
        $out = [];
        foreach ($lines as $key => $value) {
            is_array($value) ? $out += $flat($value, "{$prefix}{$key}.") : $out["{$prefix}{$key}"] = $value;
        }

        return $out;
    };
    $en = $flat(require lang_path('en/backdrop_admin.php'));
    $ar = $flat(require lang_path('ar/backdrop_admin.php'));
    expect(array_keys($ar))->toEqualCanonicalizing(array_keys($en));
    foreach ($ar as $key => $text) {
        expect(preg_match('/\p{Arabic}/u', (string) $text))->toBe(1, "Arabic text of {$key} has no Arabic in it");
        expect($text)->not->toBe($en[$key], "Arabic text of {$key} is still English");
    }
    foreach (SCENE_KEYS as $key) {
        expect($en)->toHaveKey('scene_'.$key)->and($ar)->toHaveKey('scene_'.$key);
    }
});

it('defaults to the Doha skyline and keeps the scene next to the style', function () {
    expect(backdrop()->defaults())->toBe(['style' => 'skyline', 'scene' => 'doha', 'image' => null, 'fit' => 'bottom', 'opacity' => 8, 'color' => null, 'tint' => null])
        ->and(array_keys(backdrop()->config()))->toBe(['style', 'scene', 'image', 'fit', 'opacity', 'color', 'tint'])
        ->and(BackdropService::FIELDS)->toBe(['style', 'scene', 'fit', 'opacity', 'color', 'tint']);
});

it('saves every artwork and publishes it in the theme payload, always as the skyline style', function (string $scene) {
    $this->getJson('/api/v1/theme'); // warm the 60 s cache: saving must clear it
    $saved = backdrop()->save(['scene' => $scene, 'opacity' => 12, 'tint' => '#1b2a49']);
    expect($saved['scene'])->toBe($scene)->and(backdrop()->config())->toBe($saved);
    $b = $this->getJson('/api/v1/theme')->assertOk()->json('data.backdrop');
    expect($b)->toBe(['style' => 'skyline', 'scene' => $scene, 'image_url' => null, 'fit' => 'bottom', 'opacity' => 0.12, 'color' => null, 'tint' => '#1B2A49']);
})->with(SCENE_KEYS);

it('carries the scene in the design preview too', function () {
    backdrop()->save(['scene' => 'museum']);
    $this->getJson('/api/v1/theme?design=royal-navy')->assertOk()->assertJsonPath('data.backdrop.scene', 'museum')->assertJsonPath('data.backdrop.style', 'skyline');
    $this->getJson('/api/v1/theme?design=nope')->assertOk()->assertJsonPath('data.backdrop.scene', 'museum');
});

it('answers with the Doha skyline when the scene is unknown, missing or not text', function (mixed $scene) {
    $input = ['style' => 'none', 'opacity' => 20];
    $scene === '__missing__' || $input['scene'] = $scene;
    $c = backdrop()->save($input);
    expect($c['scene'])->toBe('doha')->and($c['style'])->toBe('none')->and($c['opacity'])->toBe(20);
    expect(backdrop()->validate($input)['scene'])->toBe('doha');
    $this->getJson('/api/v1/theme')->assertJsonPath('data.backdrop.scene', 'doha');
})->with(['unknown key' => 'moon', 'empty' => '', 'null' => null, 'missing' => '__missing__', 'a list' => [['dhow']], 'upper case' => 'DHOW', 'a path' => '../../.env']);

it('reads a background saved before scenes existed and keeps everything else', function () {
    app(Settings::class)->set('app.backdrop', ['style' => 'none', 'image' => null, 'fit' => 'cover', 'opacity' => 20, 'color' => '#F5EEDC', 'tint' => '#1B2A49']);
    expect(backdrop()->config())->toBe(['style' => 'none', 'scene' => 'doha', 'image' => null, 'fit' => 'cover', 'opacity' => 20, 'color' => '#F5EEDC', 'tint' => '#1B2A49']);
    $this->getJson('/api/v1/theme')->assertOk()->assertJsonPath('data.backdrop', ['style' => 'none', 'scene' => 'doha', 'image_url' => null, 'fit' => 'cover', 'opacity' => 0.2, 'color' => '#F5EEDC', 'tint' => '#1B2A49']);
    // A stored scene that is no longer known (an older or newer server wrote it) becomes the Doha skyline, the rest is kept.
    app(Settings::class)->set('app.backdrop', ['style' => 'skyline', 'scene' => 'moon', 'fit' => 'bottom', 'opacity' => 30, 'color' => null, 'tint' => null]);
    expect(backdrop()->config())->toMatchArray(['style' => 'skyline', 'scene' => 'doha', 'opacity' => 30]);
});

it('keeps the chosen artwork when "My picture" is live, and when that picture is gone', function () {
    $path = backdrop()->save(['style' => 'image', 'scene' => 'dunes'], pic())['image'];
    $b = $this->getJson('/api/v1/theme')->json('data.backdrop');
    expect($b['style'])->toBe('image')->and($b['scene'])->toBe('dunes');
    Storage::disk(config('platform.media.disk'))->delete($path); // lost behind our back
    Cache::flush();
    $b = $this->getJson('/api/v1/theme')->json('data.backdrop');
    expect($b['style'])->toBe('skyline')->and($b['scene'])->toBe('dunes')->and($b['image_url'])->toBeNull(); // the page draws the artwork that was chosen
    // Removing the picture for good falls back to that same artwork, not to Doha.
    backdrop()->save(['style' => 'image', 'scene' => 'souq'], pic());
    expect(backdrop()->removeImage())->toMatchArray(['style' => 'skyline', 'scene' => 'souq', 'image' => null]);
});

it('goes back to the Doha skyline on "Back to default"', function () {
    backdrop()->save(['scene' => 'arabesque']);
    expect(backdrop()->reset()['scene'])->toBe('doha')->and(backdrop()->payload()['scene'])->toBe('doha');
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
    $path = backdrop()->save(['style' => 'image', 'scene' => 'dunes', 'opacity' => 30, 'color' => '#F5EEDC', 'tint' => '#1B2A49'], pic())['image'];
    $c = backdrop()->reset();
    expect($c)->toBe(['style' => 'skyline', 'scene' => 'doha', 'image' => $path, 'fit' => 'bottom', 'opacity' => 8, 'color' => null, 'tint' => null]);
    expect(backdrop()->payload())->toBe(['style' => 'skyline', 'scene' => 'doha', 'image_url' => null, 'fit' => 'bottom', 'opacity' => 0.08, 'color' => null, 'tint' => null]);
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
        ->assertSet('form', ['style' => 'skyline', 'scene' => 'doha', 'fit' => 'bottom', 'opacity' => 8, 'color' => null, 'tint' => null])
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
        ->assertSee('Doha skyline')->assertSee('My picture')->assertSee('Live preview');
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
    backdrop()->save(['style' => 'none', 'scene' => 'museum', 'opacity' => 40, 'color' => '#F5EEDC']);
    Livewire::actingAs(staffWithRole('marketing_manager'))->test(BackdropIndex::class)->call('resetToDefault')
        ->assertSet('form', ['style' => 'skyline', 'scene' => 'doha', 'fit' => 'bottom', 'opacity' => 8, 'color' => null, 'tint' => null])->assertViewHas('dirty', false);
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

// --- the gallery on the dashboard page -------------------------------------------------------------------------

/** The value of every radio in the gallery, in page order, and which of them is checked. @return array{values: list<string>, checked: list<string>} */
function galleryRadios(string $html): array
{
    preg_match_all('/<input\b[^>]*\bname="bd-pick"[^>]*>/s', $html, $m);
    $values = [];
    $checked = [];
    foreach ($m[0] as $tag) {
        preg_match('/\bvalue="([^"]+)"/', $tag, $v);
        $values[] = $v[1];
        // Only the server-rendered attribute counts here: Alpine's :checked is a different attribute name.
        if (preg_match('/\schecked(?:=|\s|>|\/)/', preg_replace('/:checked="[^"]*"/', '', $tag))) {
            $checked[] = $v[1];
        }
    }

    return ['values' => $values, 'checked' => $checked];
}

it('shows one gallery: the six artworks, then My picture and None, with the Doha skyline picked', function () {
    $c = Livewire::actingAs(staffWithRole('marketing_manager'))->test(BackdropIndex::class)->assertOk()->assertViewHas('picked', 'doha');
    $radios = galleryRadios($c->html());
    expect($radios['values'])->toBe([...SCENE_KEYS, 'image', 'none'])->and($radios['checked'])->toBe(['doha']);
    $c->assertSeeInOrder(['Doha skyline', 'Dhows on the Gulf', 'Souq Waqif', 'Desert dunes', 'Corniche and the Museum of Islamic Art', 'Islamic geometry', 'My picture', 'None']);
    // Each card also carries the name in the other language, marked as such (it is read once by screen readers).
    $c->assertSeeHtml('lang="ar" dir="rtl">أفق الدوحة</bdi>')->assertSeeHtml('lang="ar" dir="rtl">الزخرفة الإسلامية</bdi>');
    // One radio group, named and keyboard friendly (native radios: arrow keys move and select, Tab enters on the checked one).
    $c->assertSeeHtml('role="radiogroup" aria-labelledby="bd-style-h"');
});

it('shows the Arabic names first in Arabic, with the English name as the small second line', function () {
    app()->setLocale('ar');
    $c = Livewire::actingAs(staffWithRole('marketing_manager'))->test(BackdropIndex::class)->assertOk();
    $c->assertSeeInOrder(['أفق الدوحة', 'الداو على الخليج', 'سوق واقف', 'كثبان الصحراء', 'الكورنيش ومتحف الفن الإسلامي', 'الزخرفة الإسلامية', 'صورتي', 'بدون'])
        ->assertSeeHtml('lang="en" dir="ltr">Souq Waqif</bdi>')->assertSee('لون الرسم')->assertDontSee('Artwork colour');
});

it('picks an artwork in one move (style and scene together) and checks that radio', function (string $scene) {
    $c = Livewire::actingAs(staffWithRole('marketing_manager'))->test(BackdropIndex::class)
        ->set('form.style', 'skyline')->set('form.scene', $scene)
        ->assertViewHas('picked', $scene)->assertViewHas('dirty', $scene !== 'doha');
    expect(galleryRadios($c->html())['checked'])->toBe([$scene]);
    // The preview draws that artwork's own file in its own layout: bottom-standing scenes as a masked strip, the arabesque as a repeating tile.
    $c->assertSeeHtml("x-show=\"\$wire.form.style === 'skyline' && \$wire.form.scene === '{$scene}'\"");
    $c->assertSeeHtml('/brand/'.BackdropScenes::file($scene));
})->with(SCENE_KEYS);

it('moves the check to My picture and to None, and remembers the last artwork', function () {
    $c = Livewire::actingAs(staffWithRole('marketing_manager'))->test(BackdropIndex::class)->set('form.scene', 'dunes')->assertViewHas('picked', 'dunes');
    $c->set('form.style', 'none')->assertViewHas('picked', 'none')->assertSet('form.scene', 'dunes');
    expect(galleryRadios($c->html())['checked'])->toBe(['none']);
    $c->set('form.style', 'image')->assertViewHas('picked', 'image');
    expect(galleryRadios($c->html())['checked'])->toBe(['image']);
    $c->set('form.style', 'skyline')->assertViewHas('picked', 'dunes');
});

it('draws every artwork in the phone and the web preview, each with its own mask, and the arabesque as a repeating tile', function () {
    $html = Livewire::actingAs(staffWithRole('marketing_manager'))->test(BackdropIndex::class)->html();
    foreach (SCENE_KEYS as $key) {
        // gallery card + phone preview + web preview
        expect(substr_count($html, BackdropScenes::url($key)))->toBeGreaterThanOrEqual(3, "{$key} is missing from the gallery or a preview");
    }
    // Scenes: the mask fills a 1600 x 340 box that stands on the bottom edge (195 % wide on the phone, 100 % on the web, centred).
    expect($html)->toContain('aspect-ratio: 1600 / 340')->toContain('w-[195%]');
    // The tile repeats from the top-left corner, scaled down in proportion to the real 168 px (phone 59 px, web 35 px, gallery card 40 px).
    foreach (['59px', '35px', '40px'] as $size) {
        expect($html)->toContain('qatar-arabesque.svg\') 0 0 / '.$size.' '.$size.' repeat');
    }
    // The gallery thumbnails follow the colour of the artwork (and the page colour) the admin is choosing.
    expect($html)->toContain(':style="{ backgroundColor: skyColor }"')->toContain(':style="{ background: pageColor }"');
});

it('says in plain words how the chosen artwork sits, and offers the artwork colour for all six but not for the other two', function () {
    $c = Livewire::actingAs(staffWithRole('marketing_manager'))->test(BackdropIndex::class)
        ->assertSee('Artwork colour')->assertDontSee('Skyline colour')
        ->assertSee('This artwork stands on the bottom edge of every page.')->assertSee('This pattern repeats softly over the whole page.');
    // The page switches these on and off with the form (no round trip): the artwork colour belongs to the built-in style, the fit to My picture only.
    $html = preg_replace('/\s+/', ' ', $c->html());
    expect($html)->toContain('<div x-cloak x-show="$wire.form.style === \'skyline\'"> <span class="field-label" id="bd-tint-l">')
        ->toContain('aria-labelledby="bd-pic-h" x-cloak x-show="$wire.form.style === \'image\'"')
        ->toContain('<div x-show="$wire.form.style !== \'none\'">')
        ->toContain('x-show="$wire.form.style === \'skyline\' && ! isTile"')->toContain('x-show="$wire.form.style === \'skyline\' && isTile"')
        ->toContain('tiles: JSON.parse(\'[\\u0022arabesque\\u0022]\')');
});

it('saves the chosen artwork from the page and writes the scene to the audit log', function () {
    $mkt = staffWithRole('marketing_manager');
    $c = Livewire::actingAs($mkt)->test(BackdropIndex::class)->set('form.scene', 'dunes')->assertViewHas('dirty', true)
        ->call('save')->assertHasNoErrors()->assertSet('form.scene', 'dunes')->assertViewHas('dirty', false)->assertViewHas('picked', 'dunes');
    expect(backdrop()->config()['scene'])->toBe('dunes');
    $log = AuditLog::where('action', 'cms.backdrop_updated')->sole();
    expect($log->old_values['scene'])->toBe('doha')->and($log->new_values['scene'])->toBe('dunes')->and($log->actor_id)->toBe($mkt->id);
    $this->getJson('/api/v1/theme')->assertJsonPath('data.backdrop.scene', 'dunes')->assertJsonPath('data.backdrop.style', 'skyline');
    // Back to the saved artwork: nothing to save, so a second press stores and audits nothing.
    $c->set('form.scene', 'souq')->set('form.scene', 'dunes')->assertViewHas('dirty', false)->call('save');
    expect(AuditLog::where('action', 'cms.backdrop_updated')->count())->toBe(1);
});

it('never stores a scene the page did not offer', function () {
    $c = Livewire::actingAs(staffWithRole('marketing_manager'))->test(BackdropIndex::class)->set('form.scene', 'dunes')->call('save');
    $c->set('form.scene', 'moon')->assertViewHas('picked', 'doha')->call('save')->assertHasNoErrors();
    expect(backdrop()->config()['scene'])->toBe('doha');
    $c->set('form.scene', ['dhow'])->call('save')->assertHasNoErrors();
    expect(backdrop()->config()['scene'])->toBe('doha');
});

it('lets people with view only look at the gallery but not pick', function () {
    $c = Livewire::actingAs(staffWithRole('auditor'))->test(BackdropIndex::class)->assertOk();
    expect(galleryRadios($c->html())['checked'])->toBe(['doha']);
    preg_match_all('/<input\b[^>]*\bname="bd-pick"[^>]*>/s', $c->html(), $m);
    expect($m[0])->toHaveCount(8)->and(collect($m[0])->every(fn ($tag) => str_contains($tag, 'disabled')))->toBeTrue();
    $c->set('form.scene', 'dunes')->assertForbidden();
    expect(backdrop()->config()['scene'])->toBe('doha');
});

// --- the copies of the drawings are generated, never edited -----------------------------------------------------

it('keeps the Doha skyline drawing identical in the server, the web store and the app', function () {
    $server = BackdropScenes::path('doha');
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

it('keeps all six artworks identical in the server and the web store, and the app paths generated from them', function () {
    $dart = base_path('../mobile/lib/core/theme/qatar_art_paths.dart');
    $header = is_file($dart) ? (string) file_get_contents($dart) : null;
    $checked = 0;
    foreach (BackdropScenes::keys() as $scene) {
        $server = BackdropScenes::path($scene);
        $web = base_path('../storefront/src/assets/'.BackdropScenes::file($scene));
        expect(is_file($server))->toBeTrue("backend/public/brand/{$scene} is missing: run scripts/qatar-skyline/build.py and build_scenes.py.");
        if (is_file($web)) {
            expect(hash_file('sha256', $web))->toBe(hash_file('sha256', $server), "{$scene}: the web store copy differs from the server copy. Run the build scripts, never edit the files.");
            $checked++;
        }
        // The five artworks besides Doha carry their sha256 in the header of the Dart file (Doha has its own file, checked above).
        if ($scene !== 'doha' && $header !== null) {
            preg_match('#^// source-sha256 '.$scene.': ([0-9a-f]{64})$#m', $header, $m);
            expect($m[1] ?? null)->toBe(hash_file('sha256', $server), "{$scene}: the app paths were not generated from this drawing. Run scripts/qatar-skyline/build_scenes.py.");
            $checked++;
        }
    }
    if ($checked === 0) {
        $this->markTestSkipped('The web store and the app are not in this checkout; only the server drawings exist.');
    }
});
