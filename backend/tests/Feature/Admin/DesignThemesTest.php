<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Cms\Models\BrandTheme;
use App\Domain\Cms\Services\ThemeService;
use App\Livewire\Admin\Content\DesignIndex;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    app(ThemeService::class)->seedPresets();
    app(ThemeService::class)->seedDesigns();
    Cache::flush();
    CarbonImmutable::setTestNow('2026-06-10 12:00:00');
});

it('publishes layout, full tokens and the design with defaults from the default base theme', function () {
    $d = $this->getJson('/api/v1/theme')->assertOk()->json('data');
    expect($d['layout'])->toBe('classic')
        ->and($d['tokens'])->toBe(['radius' => 'lg', 'card' => 'soft', 'header' => 'standard', 'nav' => 'classic', 'background' => 'plain', 'density' => 'comfortable'])
        ->and($d['design'])->toBe(['key' => 'qatar-maroon', 'name_en' => 'Al Bahar Classic', 'name_ar' => 'البحار الكلاسيكي', 'name' => 'Al Bahar Classic']);
});

it('seeds the four designs once, with exactly one default', function () {
    expect(app(ThemeService::class)->seedDesigns())->toBe(0);
    expect(BrandTheme::where('kind', 'base')->pluck('key')->sort()->values()->all())->toBe(['fresh-market', 'ocean-breeze', 'qatar-maroon', 'royal-navy'])
        ->and(BrandTheme::where('is_default', true)->count())->toBe(1)
        ->and(BrandTheme::where('key', 'fresh-market')->first())->layout->toBe('fresh')
        ->and(BrandTheme::where('key', 'fresh-market')->first()->colors['primary'])->toBe('#0F5D3A');
});

it('does not overwrite a design the admin already edited', function () {
    BrandTheme::where('key', 'royal-navy')->update(['tokens' => ['radius' => 'xl'], 'name_en' => 'My Navy']);
    app(ThemeService::class)->seedDesigns();
    expect(BrandTheme::where('key', 'royal-navy')->first())->name_en->toBe('My Navy')->tokens->toBe(['radius' => 'xl']);
});

it('keeps the layout and tokens of the design while an event or forced theme recolours it', function () {
    app(ThemeService::class)->applyDesign(BrandTheme::where('key', 'fresh-market')->first());
    app(ThemeService::class)->force(BrandTheme::where('key', 'national-day')->first());
    $d = $this->getJson('/api/v1/theme')->json('data');
    expect($d['key'])->toBe('national-day')->and($d['colors']['accent'])->toBe('#C9A24D')
        ->and($d['layout'])->toBe('fresh')->and($d['tokens']['nav'])->toBe('center_action')->and($d['design']['key'])->toBe('fresh-market');
});

it('previews any base design with ?design= without changing the live look', function () {
    $d = $this->getJson('/api/v1/theme?design=ocean-breeze')->assertOk()->json('data');
    expect($d['key'])->toBe('ocean-breeze')->and($d['layout'])->toBe('fresh')->and($d['colors']['primary'])->toBe('#0B4F6C')->and($d['design']['key'])->toBe('ocean-breeze');
    $this->getJson('/api/v1/theme?design=national-day')->assertJsonPath('data.key', 'qatar-maroon'); // events are not designs
    $this->getJson('/api/v1/theme?design=nope')->assertJsonPath('data.key', 'qatar-maroon');
    expect(BrandTheme::where('key', 'qatar-maroon')->first()->is_default)->toBeTrue();
});

it('fills unknown or missing tokens with defaults', function () {
    expect(BrandTheme::normalizeTokens(null))->toBe(BrandTheme::TOKEN_DEFAULTS)
        ->and(BrandTheme::normalizeTokens(['radius' => 'xl', 'card' => 'glow', 'nav' => 5, 'extra' => 'x']))
        ->toBe(['radius' => 'xl', 'card' => 'soft', 'header' => 'standard', 'nav' => 'classic', 'background' => 'plain', 'density' => 'comfortable']);
    BrandTheme::where('key', 'qatar-maroon')->update(['tokens' => ['radius' => 'bad']]);
    Cache::flush();
    $this->getJson('/api/v1/theme')->assertJsonPath('data.tokens.radius', 'lg');
});

it('applies a design, clears the cache and writes the audit trail', function () {
    $this->getJson('/api/v1/theme')->assertJsonPath('data.layout', 'classic'); // warms the cache
    app(ThemeService::class)->applyDesign(BrandTheme::where('key', 'royal-navy')->first());
    $this->getJson('/api/v1/theme')->assertJsonPath('data.design.key', 'royal-navy')->assertJsonPath('data.tokens.card', 'outlined');
    expect(BrandTheme::where('is_default', true)->pluck('key')->all())->toBe(['royal-navy']);
    $log = AuditLog::where('action', 'cms.design_applied')->latest('id')->first();
    expect($log->old_values['key'])->toBe('qatar-maroon')->and($log->new_values['key'])->toBe('royal-navy');
});

it('shows the gallery to staff with view permission and keeps others out', function () {
    $this->actingAs(staffWithRole('marketing_manager'))->get(route('admin.appearance.designs'))->assertOk()->assertSee('Fresh Market')->assertSee('Royal Navy')->assertSee('Current design')->assertSee('preview_design=fresh-market', false);
    $this->actingAs(staffWithRole('pickup_employee', null))->get(route('admin.appearance.designs'))->assertForbidden();
});

it('switches design after the confirm step and can undo it', function () {
    $mkt = staffWithRole('marketing_manager');
    $fresh = BrandTheme::where('key', 'fresh-market')->first();
    $c = Livewire::actingAs($mkt)->test(DesignIndex::class)->call('ask', $fresh->id)->assertSet('showConfirm', true)->assertSee('Switch the store and apps to Fresh Market?');
    expect(BrandTheme::where('is_default', true)->value('key'))->toBe('qatar-maroon'); // nothing yet
    $c->call('apply')->assertSet('showConfirm', false)->assertDispatched('toast', type: 'success', message: 'Design switched', undoEvent: 'design-undo');
    expect(BrandTheme::where('is_default', true)->value('key'))->toBe('fresh-market');
    $this->getJson('/api/v1/theme')->assertJsonPath('data.layout', 'fresh');
    $c->call('undo', BrandTheme::where('key', 'qatar-maroon')->value('id'));
    expect(BrandTheme::where('is_default', true)->value('key'))->toBe('qatar-maroon');
});

it('stops staff without edit permission from switching', function () {
    $viewer = staffWithRole('pickup_employee', null);
    $fresh = BrandTheme::where('key', 'fresh-market')->first();
    Livewire::actingAs($viewer)->test(DesignIndex::class)->assertForbidden();
    $mkt = staffWithRole('marketing_manager');
    Livewire::actingAs($mkt)->test(DesignIndex::class)->call('apply')->assertSet('showConfirm', false);
    expect(BrandTheme::where('is_default', true)->value('key'))->toBe('qatar-maroon')->and($fresh->fresh()->is_default)->toBeFalse();
});

it('says when an event look is recolouring the current design', function () {
    app(ThemeService::class)->force(BrandTheme::where('key', 'national-day')->first());
    $this->actingAs(staffWithRole('marketing_manager'))->get(route('admin.appearance.designs'))->assertSee('Qatar National Day');
});
