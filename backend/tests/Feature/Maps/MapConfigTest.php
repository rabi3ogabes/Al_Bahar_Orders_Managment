<?php

use App\Domain\Identity\Models\User;
use App\Domain\Settings\Models\SystemSetting;
use App\Domain\Settings\Services\Settings;
use App\Livewire\Admin\Organization\SettingsIndex;
use App\Support\MapConfig;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

function mapSet(string $key, mixed $value, bool $secret = false): void
{
    app(Settings::class)->set($key, $value, $secret);
}

it('defaults to OpenStreetMap centred on Doha', function () {
    $p = MapConfig::payload();
    expect($p['provider'])->toBe('osm')
        ->and($p['tiles']['light_url'])->toBe('https://tile.openstreetmap.org/{z}/{x}/{y}.png')
        ->and($p['tiles']['dark_url'])->toBe($p['tiles']['light_url'])           // no dark OSM tiles: clients always get both URLs
        ->and($p['tiles']['attribution'])->toContain('OpenStreetMap')->and($p['tiles']['attribution_text'])->toBe('© OpenStreetMap contributors')
        ->and($p['tiles']['subdomains'])->toBe('')->and($p['tiles']['max_zoom'])->toBe(19)->and($p['tiles']['retina'])->toBeFalse()
        ->and($p['center'])->toBe(['lat' => 25.2854, 'lng' => 51.531])->and($p['zoom'])->toBe(11)->and($p['stale_after_seconds'])->toBe(120);
});

it('serves the payload publicly, cacheable for five minutes', function () {
    $r = $this->getJson('/api/v1/map-config')->assertOk()
        ->assertJsonStructure(['data' => ['provider', 'tiles' => ['light_url', 'dark_url', 'attribution', 'attribution_text', 'subdomains', 'max_zoom', 'retina'], 'center' => ['lat', 'lng'], 'zoom', 'stale_after_seconds']]);
    expect($r->headers->get('Cache-Control'))->toContain('public')->toContain('max-age=300')->and($r->headers->get('ETag'))->not->toBeNull();
    $this->getJson('/api/v1/map-config', ['If-None-Match' => $r->headers->get('ETag')])->assertStatus(304);
});

it('builds the MapTiler, Stadia and Carto presets', function (string $provider, string $needle, string $darkNeedle, string $subdomains) {
    mapSet('map.provider', $provider);
    mapSet('map.api_key', 'k3y/with+chars', secret: true);
    $t = MapConfig::payload()['tiles'];
    expect(MapConfig::payload()['provider'])->toBe($provider)
        ->and($t['light_url'])->toContain($needle)->and($t['dark_url'])->toContain($darkNeedle)->and($t['light_url'])->not->toBe($t['dark_url'])
        ->and($t['subdomains'])->toBe($subdomains)->and($t['retina'])->toBeTrue()->and($t['light_url'])->toContain('{r}')
        ->and($t['attribution_text'])->toContain('OpenStreetMap');
})->with([
    'maptiler' => ['maptiler', 'api.maptiler.com/maps/streets-v2/{z}/{x}/{y}{r}.png?key=k3y%2Fwith%2Bchars', 'streets-v2-dark', ''],
    'stadia' => ['stadia', 'alidade_smooth/{z}/{x}/{y}{r}.png?api_key=k3y%2Fwith%2Bchars', 'alidade_smooth_dark', ''],
    'carto' => ['carto', '{s}.basemaps.cartocdn.com/rastertiles/voyager', 'dark_all', 'abcd'],
]);

it('strips the retina placeholder when high-density tiles are switched off', function () {
    mapSet('map.provider', 'carto');
    mapSet('map.retina', false);
    $t = MapConfig::payload()['tiles'];
    expect($t['retina'])->toBeFalse()->and($t['light_url'])->not->toContain('{r}')->and($t['dark_url'])->not->toContain('{r}')->and($t['light_url'])->toContain('/{z}/{x}/{y}.png');
});

it('falls back to OpenStreetMap when a key-only provider has no key, and says why', function () {
    mapSet('map.provider', 'maptiler');
    expect(MapConfig::payload()['provider'])->toBe('osm')->and(MapConfig::payload()['tiles']['light_url'])->toContain('tile.openstreetmap.org')->and(MapConfig::problem())->toBe('missing_key');
    mapSet('map.api_key', 'abcdefgh123', secret: true);
    expect(MapConfig::payload()['provider'])->toBe('maptiler')->and(MapConfig::problem())->toBeNull();
});

it('uses custom light and dark templates, and the light one for dark mode when no dark one is set', function () {
    mapSet('map.provider', 'custom');
    mapSet('map.tile_url_light', 'https://{s}.tiles.example.com/light/{z}/{x}/{y}{r}.png?token={key}');
    mapSet('map.api_key', 'tok123456', secret: true);
    mapSet('map.attribution', '© <a href="https://example.com/credit">Example</a> tiles');
    $p = MapConfig::payload();
    expect($p['provider'])->toBe('custom')->and($p['tiles']['light_url'])->toBe('https://{s}.tiles.example.com/light/{z}/{x}/{y}{r}.png?token=tok123456')
        ->and($p['tiles']['dark_url'])->toBe($p['tiles']['light_url'])->and($p['tiles']['subdomains'])->toBe('abc')->and($p['tiles']['retina'])->toBeTrue()
        ->and($p['tiles']['attribution'])->toBe('© <a href="https://example.com/credit">Example</a> tiles')->and($p['tiles']['attribution_text'])->toBe('© Example tiles');
    mapSet('map.tile_url_dark', 'https://tiles.example.com/dark/{z}/{x}/{y}.png');
    expect(MapConfig::payload()['tiles']['dark_url'])->toBe('https://tiles.example.com/dark/{z}/{x}/{y}.png');
});

it('falls back to OpenStreetMap when the custom template is unusable', function () {
    mapSet('map.provider', 'custom');
    mapSet('map.tile_url_light', 'http://insecure.example.com/{z}/{x}/{y}.png');
    expect(MapConfig::payload()['provider'])->toBe('osm')->and(MapConfig::problem())->toBe('invalid_template');
});

it('validates tile URL templates', function (string $url, ?string $error) {
    expect(MapConfig::tileTemplateError($url))->toBe($error);
})->with([
    'plain' => ['https://tiles.example.com/{z}/{x}/{y}.png', null],
    'with every placeholder' => ['https://{s}.tiles.example.com/{z}/{x}/{y}{r}.png?key={key}', null],
    'empty' => ['', 'empty'],
    'blank' => ['   ', 'empty'],
    'http' => ['http://tiles.example.com/{z}/{x}/{y}.png', 'https'],
    'ftp' => ['ftp://tiles.example.com/{z}/{x}/{y}.png', 'https'],
    'no scheme' => ['tiles.example.com/{z}/{x}/{y}.png', 'https'],
    'missing y' => ['https://tiles.example.com/{z}/{x}.png', 'placeholders'],
    'missing z' => ['https://tiles.example.com/{x}/{y}.png', 'placeholders'],
    'unknown placeholder' => ['https://tiles.example.com/{z}/{x}/{y}/{style}.png', 'unknown_placeholder'],
    'credentials' => ['https://user:pass@tiles.example.com/{z}/{x}/{y}.png', 'credentials'],
    'localhost' => ['https://localhost/{z}/{x}/{y}.png', 'host'],
    'loopback ip' => ['https://127.0.0.1/{z}/{x}/{y}.png', 'host'],
    'cloud metadata ip' => ['https://169.254.169.254/{z}/{x}/{y}.png', 'host'],
    'ipv6 loopback' => ['https://[::1]/{z}/{x}/{y}.png', 'host'],
    '.internal host' => ['https://tiles.internal/{z}/{x}/{y}.png', 'host'],
    'too long' => ['https://tiles.example.com/'.str_repeat('a', 500).'/{z}/{x}/{y}.png', 'too_long'],
]);

it('validates the routing service URL', function (string $url, ?string $error) {
    expect(MapConfig::serviceUrlError($url))->toBe($error);
})->with([
    'plain' => ['https://router.example.com', null],
    'with path' => ['https://router.example.com/osrm', null],
    'private lan address' => ['https://10.0.0.5:5000', null],
    'http' => ['http://router.example.com', 'https'],
    'query' => ['https://router.example.com/?a=1', 'query'],
    'fragment' => ['https://router.example.com/#x', 'query'],
    'credentials' => ['https://u:p@router.example.com', 'credentials'],
    'loopback' => ['https://127.0.0.1:5000', 'host'],
    'localhost' => ['https://localhost', 'host'],
    'link local' => ['https://169.254.169.254', 'host'],
]);

it('only ever renders safe HTML in the credit line', function (string $input, string $expected) {
    expect(MapConfig::cleanAttribution($input))->toBe($expected);
})->with([
    'plain text' => ['© OpenStreetMap contributors', '© OpenStreetMap contributors'],
    'entity kept' => ['&copy; OSM', '&copy; OSM'],
    'https link kept' => ['<a href="https://osm.org/c">OSM</a>', '<a href="https://osm.org/c">OSM</a>'],
    'script escaped' => ['<script>alert(1)</script>', '&lt;script&gt;alert(1)&lt;/script&gt;'],
    'javascript link escaped' => ['<a href="javascript:alert(1)">x</a>', '&lt;a href=&quot;javascript:alert(1)&quot;&gt;x'],
    'link with extra attributes escaped' => ['<a href="https://x.org" onclick="evil()">x</a>', '&lt;a href=&quot;https://x.org&quot; onclick=&quot;evil()&quot;&gt;x'],
    'unclosed link is closed' => ['<a href="https://x.org">x', '<a href="https://x.org">x</a>'],
    'dangling closer dropped' => ['x</a> y', 'x y'],
    'nested link flattened' => ['<a href="https://a.org">a<a href="https://b.org">b</a></a>', '<a href="https://a.org">ab</a>'],
]);

it('never exposes the API key except inside the tile URLs, and never the routing URL', function () {
    mapSet('map.provider', 'maptiler');
    mapSet('map.api_key', 'SECRET-KEY-VALUE-1', secret: true);
    mapSet('map.routing_url', 'https://router-secret.example.com');
    $p = MapConfig::payload();
    $withoutUrls = $p;
    unset($withoutUrls['tiles']['light_url'], $withoutUrls['tiles']['dark_url']);
    expect($p['tiles']['light_url'])->toContain('SECRET-KEY-VALUE-1')
        ->and(json_encode($withoutUrls))->not->toContain('SECRET-KEY-VALUE-1')
        ->and(json_encode($p))->not->toContain('router-secret');

    // The same through the unauthenticated endpoint, and the key at rest is encrypted.
    $this->getJson('/api/v1/map-config')->assertOk()->assertJsonPath('data.tiles.light_url', $p['tiles']['light_url'])->assertDontSee('router-secret');
    expect(json_encode(SystemSetting::where('group', 'map')->where('key', 'api_key')->first()->value))->not->toContain('SECRET-KEY-VALUE-1');
});

it('keeps the payload cached for five minutes and drops it the moment a map setting is saved', function () {
    expect(MapConfig::payload()['provider'])->toBe('osm');
    // A change that bypasses the Settings service is not seen: proof the payload is served from the cache.
    SystemSetting::create(['group' => 'map', 'key' => 'provider', 'value' => ['v' => 'carto'], 'is_secret' => false]);
    expect(MapConfig::payload()['provider'])->toBe('osm');
    // Saving through Settings busts both caches.
    mapSet('map.provider', 'carto');
    expect(MapConfig::payload()['provider'])->toBe('carto');
    $this->getJson('/api/v1/map-config')->assertJsonPath('data.provider', 'carto');
    mapSet('map.zoom', 14);
    expect(MapConfig::payload()['zoom'])->toBe(14);
});

it('reads the centre, zoom and max zoom from the settings and ignores nonsense', function () {
    mapSet('map.center_lat', '25.40');
    mapSet('map.center_lng', '51.20');
    mapSet('map.zoom', 13);
    mapSet('map.max_zoom', 17);
    $p = MapConfig::payload();
    expect($p['center'])->toBe(['lat' => 25.4, 'lng' => 51.2])->and($p['zoom'])->toBe(13)->and($p['tiles']['max_zoom'])->toBe(17);

    mapSet('map.center_lat', '95');          // not a latitude
    mapSet('map.zoom', 40);                  // out of range
    mapSet('map.max_zoom', 99);
    $p = MapConfig::payload();
    expect($p['center'])->toBe(['lat' => 25.2854, 'lng' => 51.531])->and($p['zoom'])->toBe(11)->and($p['tiles']['max_zoom'])->toBe(19);
});

it('builds a real sample tile URL for the Test tiles check', function () {
    expect(MapConfig::sampleTileUrl())->toBe('https://tile.openstreetmap.org/11/1317/875.png');
    mapSet('map.provider', 'carto');
    expect(MapConfig::sampleTileUrl())->toBe('https://a.basemaps.cartocdn.com/rastertiles/voyager/11/1317/875.png');
});

it('exposes the routing URL to the server only when it is valid', function () {
    expect(MapConfig::routingUrl())->toBeNull();
    mapSet('map.routing_url', 'http://insecure.example.com');
    expect(MapConfig::routingUrl())->toBeNull();
    mapSet('map.routing_url', 'https://router.example.com/');
    expect(MapConfig::routingUrl())->toBe('https://router.example.com');
});

describe('settings screen', function () {
    beforeEach(function () {
        $this->admin = User::factory()->superAdmin()->create();
        $this->actingAs($this->admin);
    });

    it('saves the map settings, keeps the key sealed and refreshes the payload', function () {
        Livewire::test(SettingsIndex::class)->set('tab', 'integrations')
            ->set('values.integrations.map__provider', 'maptiler')->set('secrets.map__api_key', 'mt_live_ABCDEFGH1234')
            ->set('values.integrations.map__zoom', '12')->set('values.integrations.map__center_lat', '25.30')->set('values.integrations.map__center_lng', '51.45')
            ->call('save')->assertHasNoErrors()->assertDontSee('mt_live_ABCDEFGH1234')->assertSee('1234');
        $p = MapConfig::payload();
        expect($p['provider'])->toBe('maptiler')->and($p['zoom'])->toBe(12)->and($p['center'])->toBe(['lat' => 25.3, 'lng' => 51.45])
            ->and(SystemSetting::where('group', 'map')->where('key', 'api_key')->value('is_secret'))->toBeTrue();
    });

    it('refuses bad tile templates, a lone latitude, a bad zoom and an insecure routing URL', function () {
        Livewire::test(SettingsIndex::class)->set('tab', 'integrations')
            ->set('values.integrations.map__tile_url_light', 'http://x.example.com/{z}/{x}/{y}.png')
            ->set('values.integrations.map__tile_url_dark', 'https://x.example.com/{z}/{x}.png')
            ->set('values.integrations.map__center_lat', '25.3')->set('values.integrations.map__center_lng', '')
            ->set('values.integrations.map__zoom', '30')->set('values.integrations.map__max_zoom', '0')
            ->set('values.integrations.map__routing_url', 'http://router.example.com')
            ->call('save')->assertHasErrors(['values.integrations.map__tile_url_light', 'values.integrations.map__tile_url_dark', 'values.integrations.map__center_lng',
                'values.integrations.map__zoom', 'values.integrations.map__max_zoom', 'values.integrations.map__routing_url']);
        expect(MapConfig::payload()['provider'])->toBe('osm');
    });

    it('refuses an unknown provider', function () {
        Livewire::test(SettingsIndex::class)->set('tab', 'integrations')->set('values.integrations.map__provider', 'bing')->call('save')->assertHasErrors(['values.integrations.map__provider']);
    });

    it('tests the saved tiles by fetching one and reporting the result', function () {
        Http::fake(['tile.openstreetmap.org/*' => Http::sequence()
            ->push('PNG', 200, ['Content-Type' => 'image/png'])
            ->push('denied', 403, ['Content-Type' => 'text/plain'])
            ->push('<html>', 200, ['Content-Type' => 'text/html'])]);

        Livewire::test(SettingsIndex::class)->call('testTiles')->assertDispatched('toast', type: 'success', message: __('org_admin.map_test.ok'));
        Http::assertSent(fn ($r) => $r->url() === 'https://tile.openstreetmap.org/11/1317/875.png');
        Livewire::test(SettingsIndex::class)->call('testTiles')->assertDispatched('toast', type: 'error', message: __('org_admin.map_test.refused', ['status' => 403]));
        Livewire::test(SettingsIndex::class)->call('testTiles')->assertDispatched('toast', type: 'error', message: __('org_admin.map_test.not_image'));
    });

    it('explains a missing key instead of fetching', function () {
        mapSet('map.provider', 'stadia');
        Http::fake();
        Livewire::test(SettingsIndex::class)->call('testTiles')->assertDispatched('toast', type: 'error', message: __('org_admin.map_test.missing_key'));
        Http::assertNothingSent();
    });

    it('reports an unreachable provider', function () {
        Http::fake(fn () => throw new Illuminate\Http\Client\ConnectionException('timeout'));
        Livewire::test(SettingsIndex::class)->call('testTiles')->assertDispatched('toast', type: 'error', message: __('org_admin.map_test.unreachable'));
    });
});

it('has the map settings in both languages', function () {
    foreach (['en', 'ar'] as $locale) {
        app()->setLocale($locale);
        foreach (SettingsIndex::schema()['integrations'] as $key => $def) {
            if (str_starts_with($key, 'map.')) {
                $f = SettingsIndex::field($key);
                expect(Lang::has('org_admin.setting.'.$f, $locale))->toBeTrue("label {$f} ({$locale})")->and(Lang::has('org_admin.setting_hint.'.$f, $locale) || in_array($f, ['map__center_lng'], true))->toBeTrue("hint {$f} ({$locale})");
            }
        }
        foreach (MapConfig::PROVIDERS as $p) {
            expect(Lang::has('org_admin.option.'.$p, $locale))->toBeTrue("option {$p} ({$locale})");
        }
    }
});
