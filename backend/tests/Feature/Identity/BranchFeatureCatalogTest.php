<?php

use App\Domain\Branches\Models\Branch;
use App\Domain\Branches\Services\BranchEmployeeService;
use App\Domain\Identity\Support\BranchFeatureCatalog;
use App\Domain\Identity\Support\PermissionRegistry;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Lang;

/**
 * The branch-employee checklist is derived from PermissionRegistry. These tests keep it consistent with the registry itself
 * (every key is real, nothing admin-only is offered, presets and dependencies close) and with the language files.
 */
$allKeys = array_column(PermissionRegistry::permissions(), 'key');

it('offers only permission keys that exist in the registry', function () use ($allKeys) {
    foreach (BranchFeatureCatalog::keyMap() as $feature => $keys) {
        expect($keys)->not->toBeEmpty("{$feature} has no keys");
        foreach ($keys as $key) {
            expect($allKeys)->toContain($key);
        }
    }
});

it('never offers an admin-only permission', function () {
    foreach (BranchFeatureCatalog::offeredKeys() as $key) {
        [$module] = explode('.', $key, 2);
        expect(PermissionRegistry::ADMIN_ONLY_MODULES)->not->toContain($module, "{$key} is admin-only");
        expect(PermissionRegistry::ADMIN_ONLY_KEYS)->not->toContain($key);
    }
    // The named admin-only areas from the owner's brief are really absent.
    foreach (['roles', 'settings', 'notifications', 'cms', 'marketing', 'audit'] as $module) {
        expect(collect(BranchFeatureCatalog::offeredKeys())->filter(fn ($k) => str_starts_with($k, $module.'.')))->toBeEmpty();
    }
});

it('never offers the shared product catalog: catalog.edit is company-wide, so a branch grant would edit every branch’s products through the API', function () {
    expect(PermissionRegistry::ADMIN_ONLY_KEYS)->toContain('catalog.create', 'catalog.edit', 'catalog.delete', 'catalog.approve');
    expect(BranchFeatureCatalog::offeredKeys())->not->toContain('catalog.edit', 'catalog.create', 'catalog.delete', 'catalog.approve');
    expect(BranchFeatureCatalog::ids())->not->toContain('products_edit');
});

it('has unique feature ids and groups that cover every feature once', function () {
    $ids = BranchFeatureCatalog::ids();
    expect($ids)->toBe(array_values(array_unique($ids)))->and(count($ids))->toBeGreaterThan(20);
});

it('only lets a feature need another real feature, with no loops', function () {
    foreach (BranchFeatureCatalog::needsMap() as $feature => $needs) {
        foreach ($needs as $need) {
            expect(BranchFeatureCatalog::exists($need))->toBeTrue("{$feature} needs unknown {$need}");
            expect(BranchFeatureCatalog::needsMap()[$need])->not->toContain($feature);
        }
        // closure terminates and contains the feature itself plus what it needs
        expect(BranchFeatureCatalog::withNeeds([$feature]))->toContain($feature, ...$needs);
    }
});

it('keeps every preset made of real features and closed under what they need', function () {
    expect(array_keys(BranchFeatureCatalog::presets()))->toBe(['branch_manager', 'order_desk', 'cashier', 'stock_keeper', 'delivery_coordinator', 'read_only', 'wall_display']);
    foreach (BranchFeatureCatalog::presets() as $preset => $features) {
        foreach ($features as $f) {
            expect(BranchFeatureCatalog::exists($f))->toBeTrue("{$preset}: unknown feature {$f}");
        }
        $closed = BranchFeatureCatalog::withNeeds($features);
        expect($closed)->toEqualCanonicalizing(array_values(array_unique($features)), "{$preset} is missing a feature it needs");
        foreach (BranchFeatureCatalog::permissionKeys($features) as $key) {
            expect(BranchFeatureCatalog::offeredKeys())->toContain($key);
        }
        expect(BranchFeatureCatalog::presetFor($features))->toBe($preset);
    }
});

it('makes the read-only preset really read only', function () {
    foreach (BranchFeatureCatalog::permissionKeys(BranchFeatureCatalog::presets()['read_only']) as $key) {
        expect($key)->toEndWith('.view');
    }
});

it('derives features from permission keys only when every key of the feature is held', function () {
    expect(BranchFeatureCatalog::featuresFor(['orders.view']))->toBe(['orders_view'])
        ->and(BranchFeatureCatalog::featuresFor(['catalog.view']))->toBe([]) // products_view also needs pricing.view
        ->and(BranchFeatureCatalog::featuresFor(['catalog.view', 'pricing.view']))->toBe(['products_view']);
    expect(BranchFeatureCatalog::withNeeds(['orders_create', 'nope', 7]))->toBe(['orders_view', 'orders_create', 'products_view']);
});

it('can be handed out in full by a system branch manager (every preset is within their authority)', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $branch = Branch::factory()->create();
    $manager = staffWithRole('branch_manager', $branch);
    $grantable = app(BranchEmployeeService::class)->grantable($manager, $branch->id);
    foreach (BranchFeatureCatalog::presets() as $preset => $features) {
        expect($grantable)->toContain(...$features);
    }
    // Support replies are not part of a system branch manager's role, so they stay locked for them.
    expect($grantable)->not->toContain('support_reply');
});

describe('language files', function () {
    it('have the same keys in English and Arabic, none empty', function () {
        $en = require base_path('lang/en/branch_access.php');
        $ar = require base_path('lang/ar/branch_access.php');
        expect(array_keys($ar))->toEqualCanonicalizing(array_keys($en));
        foreach ([$en, $ar] as $file) {
            foreach ($file as $key => $text) {
                expect(trim((string) $text))->not->toBe('', "{$key} is empty");
            }
        }
    });

    it('name every group, feature (with what it opens) and preset in plain, real Arabic', function () {
        $keys = [];
        foreach (BranchFeatureCatalog::groups() as $group => $ids) {
            $keys[] = 'group_'.$group;
            foreach ($ids as $id) {
                array_push($keys, $id, $id.'_hint');
            }
        }
        foreach (array_keys(BranchFeatureCatalog::presets()) as $preset) {
            array_push($keys, 'preset_'.$preset, 'preset_'.$preset.'_hint');
        }
        foreach ($keys as $key) {
            expect(Lang::has('branch_access.'.$key, 'en'))->toBeTrue("en: {$key}");
            expect(Lang::has('branch_access.'.$key, 'ar'))->toBeTrue("ar: {$key}");
            // real Arabic: written in Arabic letters, not the English text copied over
            expect(preg_match('/\p{Arabic}/u', __('branch_access.'.$key, [], 'ar')))->toBe(1, "ar: {$key} is not Arabic");
        }
    });
});
