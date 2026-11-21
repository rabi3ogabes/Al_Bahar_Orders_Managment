<?php

use App\Domain\Identity\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

require_once __DIR__.'/../Operations/OpsSupport.php';
require_once __DIR__.'/SearchSupport.php';

/** The search window the layout puts on every dashboard page: present for the right people, in both languages, built for the keyboard, a screen reader and right-to-left. */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->branch = opsBranch();
    $this->mgr = opsStaff('branch_manager', $this->branch);
});

it('puts the search button and the search window on every dashboard page, in the person\'s language', function (string $route, string $locale) {
    $html = test()->withSession(['locale' => $locale])->actingAs($this->mgr)->get(route($route))->assertOk()->getContent();
    $boot = bootOf($html, 'commandPalette');
    expect($html)->toContain('data-command-palette-trigger')->toContain('data-command-palette')->toContain('open-command-palette')
        ->toContain('aria-label="'.e(__('search.open', [], $locale)).'"')
        ->and($boot['url'])->toBe(route('admin.search'))->and($boot['user'])->toBe((string) $this->mgr->id)
        ->and($boot['text']['placeholder'])->toBe(__('search.placeholder', [], $locale))->and($boot['text']['results_none'])->toBe(__('search.results_none', [], $locale));
})->with(function () {
    foreach (['admin.dashboard', 'admin.orders.index', 'admin.operations.attention', 'admin.operations.board', 'admin.customers.index'] as $route) {
        foreach (['en', 'ar'] as $locale) {
            yield "{$route} {$locale}" => [$route, $locale];
        }
    }
});

it('builds the window for the keyboard and a screen reader: a combobox with a listbox, a named dialog, a trapped focus', function () {
    $html = test()->actingAs($this->mgr)->get(route('admin.dashboard'))->getContent();
    expect($html)->toContain('role="dialog"')->toContain('aria-modal="true"')->toContain('role="combobox"')->toContain('aria-controls="cp-list"')
        ->toContain('aria-autocomplete="list"')->toContain(':aria-activedescendant')->toContain('role="listbox"')->toContain('role="option"')
        ->toContain('x-trap.noscroll.noautofocus')->toContain('role="status"')->toContain('aria-live="polite"')
        ->toContain('enterkeyhint="search"')->toContain('autocomplete="off"')->toContain('maxlength="64"')->toContain('dir="auto"');
    // buttons and rows are 44 px tall
    expect($html)->toContain('min-h-11')->and(substr_count($html, 'size-11'))->toBeGreaterThan(1);
});

it('has no search button for somebody who must first choose a password', function () {
    $fresh = opsStaff('branch_manager', $this->branch, ['must_change_password' => true]);
    $html = test()->actingAs($fresh)->get(route('admin.profile'))->assertOk()->getContent();
    expect($html)->not->toContain('data-command-palette')->not->toContain('commandPalette(');
});

it('uses start and end, never left and right, so Arabic mirrors itself', function (string $file) {
    $source = (string) file_get_contents(base_path($file));
    expect(preg_match('/(?<![\w-])(?:ml|mr|pl|pr)-(?:\d|auto)|(?<![\w-])text-(?:left|right)\b|(?<![\w:-])(?:left|right)-(?:\d|\[|full|1\/2)|\b(?:border-l|border-r|rounded-l|rounded-r)(?:-|\b)/', $source, $m))->toBe(0, 'physical direction in '.$file.': '.($m[0] ?? ''));
})->with([
    'resources/views/components/admin/command-palette.blade.php',
    'resources/views/components/admin/ops/board-card.blade.php',
    'resources/views/livewire/admin/operations/live-board.blade.php',
    'resources/views/livewire/admin/operations/partials/board-summary.blade.php',
    'resources/views/livewire/admin/operations/partials/board-link.blade.php',
]);

it('is wired into the dashboard script, and the answer\'s icons are the dashboard\'s own drawings', function () {
    $app = (string) file_get_contents(resource_path('js/app.js'));
    expect($app)->toContain("import './command-palette';")->toContain("import './ops-board';");
    $json = test()->actingAs(User::factory()->superAdmin()->create())->getJson(route('admin.search', ['q' => 'cash']))->json();
    foreach ($json['groups'] as $group) {
        foreach ($group['items'] as $item) {
            expect($item['svg'])->toStartWith('<svg')->and($item['svg'])->not->toContain('<script')->not->toContain('onload');
        }
    }
    expect($json['groups'][0]['items'][0]['svg'])->toContain('viewBox="0 0 24 24"');
});

it('is one place for the Ctrl/Cmd+K and "/" keys, which leaves the login page alone', function () {
    $js = (string) file_get_contents(resource_path('js/command-palette.js'));
    expect(substr_count($js, "addEventListener('keydown'"))->toBe(1)->and($js)->toContain("document.querySelector('[data-command-palette]')")->toContain('isOpenShortcut')->toContain('isSlashShortcut');
});
