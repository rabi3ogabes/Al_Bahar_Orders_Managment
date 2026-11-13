<?php

use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\UserRole;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(fn () => $this->seed(RolesAndPermissionsSeeder::class));

/** Load an admin page over real HTTP and return the Livewire update URL + the first component's snapshot. */
function livewireSnapshot(object $t, string $path, string $component = 'admin.insights.issue-index'): array
{
    $html = $t->get($path)->assertOk()->getContent();
    preg_match_all('/wire:snapshot="([^"]+)"/', $html, $all);
    $snapshots = array_map('html_entity_decode', $all[1]);
    $mine = collect($snapshots)->first(fn ($s) => str_contains($s, '"name":"'.$component.'"'));
    preg_match('/data-update-uri="([^"]+)"/', $html, $u);

    return [$mine, $u[1]];
}

function livewireCall(object $t, string $url, string $snapshot, string $method, array $params = [])
{
    return $t->postJson($url, ['components' => [['snapshot' => $snapshot, 'updates' => (object) [], 'calls' => [['path' => '', 'method' => $method, 'params' => $params]]]]],
        ['X-Livewire' => '1']);
}

it('stops a staff member whose permission was revoked after the page loaded (Livewire updates re-run the route middleware)', function () {
    $user = staffWithRole('order_manager', App\Domain\Branches\Models\Branch::factory()->create());
    $this->actingAs($user);
    [$snap, $url] = livewireSnapshot($this, '/admin/issues');
    UserRole::where('user_id', $user->id)->delete();
    $this->app->forgetScopedInstances();

    livewireCall($this, $url, $snap, 'create')->assertForbidden();
});

it('stops a deactivated staff member mid-session', function () {
    $user = staffWithRole('order_manager', App\Domain\Branches\Models\Branch::factory()->create());
    $this->actingAs($user);
    [$snap, $url] = livewireSnapshot($this, '/admin/issues');

    $user->forceFill(['status' => 'suspended'])->save();
    $this->app->forgetScopedInstances();

    livewireCall($this, $url, $snap, 'create')->assertForbidden();
});

it('lets an authorised staff member use the same Livewire action', function () {
    $user = staffWithRole('order_manager', App\Domain\Branches\Models\Branch::factory()->create());
    $this->actingAs($user);
    [$snap, $url] = livewireSnapshot($this, '/admin/issues');

    livewireCall($this, $url, $snap, 'create')->assertOk();
});
