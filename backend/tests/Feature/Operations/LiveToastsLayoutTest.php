<?php

use App\Domain\Branches\Models\BranchEmployee;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Models\UserRole;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Js;

require_once __DIR__.'/OpsSupport.php';

/** New-order toasts and the chime switch on EVERY admin page, for the right branches only. (The behaviour itself is tested in tests/js/live-toasts-core.test.mjs.) */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->a = opsBranch();
    $this->b = opsBranch();
});

function opsBoot(string $html): ?array
{
    return preg_match('/data-boot="([^"]*)"/', $html, $m) ? json_decode(html_entity_decode($m[1], ENT_QUOTES), true) : null;
}

it('mounts the live new-order feed on every admin page, once, kept across page changes', function (string $route) {
    $mgr = opsStaff('branch_manager', test()->a);
    $html = test()->actingAs($mgr)->get(route($route))->assertOk()->getContent();
    expect(substr_count($html, 'startOpsLive'))->toBe(1)->and($html)->toContain('window.loadLiveToasts()')->toContain('data-route="'.$route.'"');
    // @persist keeps the element alive when the page changes with wire:navigate (so a toast is not cut off by a click)
    expect($html)->toMatch('/x-persist|data-persist|wire:persist/i');
})->with(['admin.dashboard', 'admin.orders.index', 'admin.operations.attention', 'admin.deliveries.index', 'admin.cash.index', 'admin.fleet']);

it('listens only to the channels of the branches the person may see orders of', function () {
    $mgr = opsStaff('branch_manager', test()->a);
    $boot = opsBoot(test()->actingAs($mgr)->get(route('admin.orders.index'))->getContent());
    expect($boot['channels'])->toBe(['live-orders.'.test()->a->id])->and($boot['ordersUrl'])->toBe(route('admin.orders.index'))->and($boot['orderUrl'])->toContain('__ID__');

    $two = opsStaffWith(test()->a, ['orders.view']);
    BranchEmployee::create(['branch_id' => test()->b->id, 'user_id' => $two->id, 'is_active' => true]);
    UserRole::create(['user_id' => $two->id, 'role_id' => UserRole::where('user_id', $two->id)->value('role_id'), 'branch_id' => test()->b->id]);
    $boot = opsBoot(test()->actingAs($two)->get(route('admin.orders.index'))->getContent());
    expect($boot['channels'])->toHaveCount(2)->toContain('live-orders.'.test()->a->id, 'live-orders.'.test()->b->id);

    $admin = User::factory()->superAdmin()->create();
    expect(opsBoot(test()->actingAs($admin)->get(route('admin.orders.index'))->getContent())['channels'])->toBe(['live-orders']);

    $accountant = opsStaff('accountant');
    expect(opsBoot(test()->actingAs($accountant)->get(route('admin.payments.index'))->getContent())['channels'])->toBe(['live-orders']);   // global orders.view
});

it('puts nothing on the page of someone who cannot see orders, or who has a first step to finish', function () {
    $driver = opsStaffWith(test()->a, ['deliveries.view', 'deliveries.edit']);
    $html = test()->actingAs($driver)->get(route('admin.deliveries.index'))->assertOk()->getContent();
    expect($html)->not->toContain('startOpsLive')->not->toContain('data-sound-toggle');
    $fresh = opsStaff('branch_manager', test()->a, ['must_change_password' => true]);
    expect(test()->actingAs($fresh)->get(route('admin.profile'))->assertOk()->getContent())->not->toContain('startOpsLive')->not->toContain('data-sound-toggle');
});

it('puts the chime switch in the header: off by default, labelled, stored only in this browser', function (string $locale) {
    $mgr = opsStaff('branch_manager', test()->a);
    $html = test()->actingAs($mgr)->withSession(['locale' => $locale])->get(route('admin.orders.index'))->getContent();
    expect($html)->toContain('data-sound-toggle')->toContain("localStorage.getItem('live_toasts_sound') === '1'")->toContain('x-data="{ on: false')   // off until the person turns it on
        ->toContain((string) Js::from(__('ops_live.toast.sound_on', [], $locale)))->toContain((string) Js::from(__('ops_live.toast.sound_off', [], $locale)))
        ->toContain(':aria-pressed="on ?')->not->toContain('<audio')->not->toContain('autoplay');
    // the words of the toasts, in the person's language, for the browser to use
    $boot = opsBoot($html);
    expect($boot['text']['new_orders'])->toBe(__('ops_live.toast.new_orders', [], $locale))->and($boot['text']['new_order'])->toBe(__('live.new_order', [], $locale))
        ->and($boot['text']['new_orders_hint'])->toBe(__('ops_live.toast.new_orders_hint', [], $locale))->and($boot['text'])->toHaveKeys(['view', 'close', 'delivery', 'pickup', 'cod']);
})->with(['en', 'ar']);

it('adds "Needs attention" to the menu with a live badge that loads after the page', function () {
    $mgr = opsStaff('branch_manager', test()->a);
    $html = test()->actingAs($mgr)->get(route('admin.dashboard'))->getContent();
    expect($html)->toContain(route('admin.operations.attention'))->toContain(__('ops_live.nav_attention'))->toContain('admin.operations.attention-badge');
    // Arabic menu entry
    $ar = test()->actingAs($mgr)->withSession(['locale' => 'ar'])->get(route('admin.dashboard'))->getContent();
    expect($ar)->toContain(__('ops_live.nav_attention', [], 'ar'));
});

it('lets the live frames refresh the inbox, the dashboard card and the badge by themselves', function () {
    $core = file_get_contents(base_path('resources/js/live-toasts.js'));
    expect($core)->toContain("new CustomEvent('ops-order-live'")->toContain('startOpsLive')->toContain('livewire:navigated');
    foreach (['needs-attention', 'attention-card', 'attention-badge'] as $view) {
        expect(file_get_contents(resource_path('views/livewire/admin/operations/'.$view.'.blade.php')))->toContain('x-on:ops-order-live.window');
    }
});
