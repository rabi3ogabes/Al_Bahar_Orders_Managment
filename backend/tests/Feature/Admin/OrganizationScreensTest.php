<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Branches\Models\Branch;
use App\Domain\Branches\Models\BranchDeliveryZone;
use App\Domain\Branches\Models\BranchEmployee;
use App\Domain\Branches\Models\DeliveryZone;
use App\Domain\Identity\Models\Permission;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Models\UserRole;
use App\Domain\Identity\Services\AccessControl;
use App\Domain\Settings\Services\Settings;
use App\Livewire\Admin\Organization\AuditIndex;
use App\Livewire\Admin\Organization\EmployeeEditor;
use App\Livewire\Admin\Organization\EmployeeIndex;
use App\Livewire\Admin\Organization\RoleEditor;
use App\Livewire\Admin\Organization\SettingsIndex;
use App\Livewire\Admin\Organization\ZoneIndex;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    [$this->wb, $this->prl] = Branch::factory()->count(2)->create();
    $this->admin = User::factory()->superAdmin()->create();
    $this->manager = staffWithRole('branch_manager', $this->wb);
});

it('renders every organization screen for a super admin', function (string $route, array $params = []) {
    $this->actingAs($this->admin)->get(route($route, $params))->assertOk();
})->with([
    ['admin.zones.index'], ['admin.employees.index'], ['admin.employees.create'], ['admin.roles.index'], ['admin.roles.create'],
    ['admin.settings.index'], ['admin.audit.index'],
]);

it('forbids organization screens to staff without the permission', function (string $route) {
    $this->actingAs(staffWithRole('pickup_employee', $this->wb))->get(route($route))->assertForbidden();
})->with(['admin.zones.index', 'admin.employees.index', 'admin.roles.index', 'admin.settings.index', 'admin.audit.index']);

it('keeps roles and settings to company-wide holders even if a branch role grants them', function () {
    // branch managers have no roles.* — and even a branch-scoped grant must not open the role editor
    $this->actingAs($this->manager)->get(route('admin.roles.index'))->assertForbidden();
    $this->actingAs($this->manager)->get(route('admin.settings.index'))->assertForbidden();
});

it('creates, edits and audits delivery zones, and protects zones in use', function () {
    $this->actingAs($this->admin);
    Livewire::test(ZoneIndex::class)
        ->call('create')
        ->set('form.code', 'westbay')->set('form.name_en', 'West Bay')->set('form.name_ar', 'الخليج الغربي')
        ->set('form.zone_numbers', '60, 61,62')
        ->set('form.polygon', '[[51.52,25.31],[51.54,25.31],[51.54,25.33]]')
        ->call('save')->assertHasNoErrors()->assertSet('showEditor', false);

    $zone = DeliveryZone::where('code', 'WESTBAY')->firstOrFail();
    expect($zone->zone_numbers)->toBe('60,61,62')->and($zone->polygon)->toHaveCount(3);
    expect(AuditLog::where('action', 'zone.created')->exists())->toBeTrue();

    Livewire::test(ZoneIndex::class)->call('create')->set('form.code', 'BAD')->set('form.name_en', 'x')->set('form.name_ar', 'س')
        ->set('form.polygon', '[[1,2]]')->set('form.zone_numbers', 'abc')->call('save')->assertHasErrors(['form.polygon', 'form.zone_numbers']);

    BranchDeliveryZone::create(['branch_id' => $this->wb->id, 'delivery_zone_id' => $zone->id, 'delivery_fee' => '10.00']);
    Livewire::test(ZoneIndex::class)->assertSee('West Bay')->assertSee($this->wb->code)->call('delete', $zone->id);
    expect(DeliveryZone::find($zone->id))->not->toBeNull();
});

it('lets branch managers view zones but not change them', function () {
    $zone = DeliveryZone::factory()->create();
    $this->actingAs($this->manager);
    Livewire::test(ZoneIndex::class)->assertOk()->assertDontSeeHtml('wire:click="create"')->assertDontSeeHtml('wire:click="edit(');
    Livewire::test(ZoneIndex::class)->call('edit', $zone->id)->assertForbidden();
});

it('scopes the employee list to the viewer’s branches', function () {
    $wbStaff = staffWithRole('sales_employee', $this->wb, ['name' => 'Wafa Westbay']);
    $prlStaff = staffWithRole('sales_employee', $this->prl, ['name' => 'Pari Pearl']);
    $this->actingAs($this->manager);
    Livewire::test(EmployeeIndex::class)->assertSee('Wafa Westbay')->assertDontSee('Pari Pearl');
    // URL branch filter cannot widen scope
    Livewire::test(EmployeeIndex::class)->set('branch', $this->prl->id)->assertDontSee('Pari Pearl');
    $this->get(route('admin.employees.edit', $prlStaff))->assertForbidden();
    $this->get(route('admin.employees.edit', $wbStaff))->assertOk();
});

it('creates an employee and assigns branch roles through EmployeeService', function () {
    $this->actingAs($this->manager);
    Livewire::test(EmployeeEditor::class)
        ->set('form.name', 'Salma Ahmed')->set('form.email', 'Salma@Albahar.qa')->set('form.locale', 'ar')->set('form.password', 'Str0ngPassword!')
        ->call('create')->assertHasNoErrors()->assertRedirect();
    $salma = User::where('email', 'salma@albahar.qa')->firstOrFail();
    expect($salma->isStaff())->toBeTrue();

    Livewire::test(EmployeeEditor::class, ['employee' => $salma])
        ->set('assign.branch_id', $this->wb->id)->set('assign.roles', ['sales_employee'])->call('saveAssignment')->assertHasNoErrors();
    expect(UserRole::where('user_id', $salma->id)->where('branch_id', $this->wb->id)->count())->toBe(1);

    // Cannot assign at a branch the manager does not manage — enforced by the service, surfaced as a toast.
    Livewire::test(EmployeeEditor::class, ['employee' => $salma])
        ->set('assign.branch_id', $this->prl->id)->set('assign.roles', ['sales_employee'])->call('saveAssignment')->assertDispatched('toast');
    expect(UserRole::where('user_id', $salma->id)->where('branch_id', $this->prl->id)->exists())->toBeFalse();
});

it('never lets an employee change their own roles or status', function () {
    $this->actingAs($this->manager);
    Livewire::test(EmployeeEditor::class, ['employee' => $this->manager])
        ->set('assign.branch_id', $this->wb->id)->set('assign.roles', ['branch_manager', 'inventory_manager'])->call('saveAssignment')
        ->assertDispatched('toast', type: 'error');
    expect(UserRole::where('user_id', $this->manager->id)->count())->toBe(1);

    Livewire::test(EmployeeEditor::class, ['employee' => $this->manager])->call('setStatus', 'suspended')->assertForbidden();

    $this->actingAs($this->admin);
    Livewire::test(EmployeeEditor::class, ['employee' => $this->admin])->set('globalRoles', ['auditor'])->call('saveGlobalRoles')->assertDispatched('toast', type: 'error');
    expect(UserRole::where('user_id', $this->admin->id)->exists())->toBeFalse();
});

it('suspends and resets passwords only for staff the manager fully manages', function () {
    $wbStaff = staffWithRole('sales_employee', $this->wb);
    $multi = staffWithRole('sales_employee', $this->wb);
    BranchEmployee::create(['branch_id' => $this->prl->id, 'user_id' => $multi->id, 'is_active' => true]);

    $this->actingAs($this->manager);
    Livewire::test(EmployeeEditor::class, ['employee' => $wbStaff])->call('setStatus', 'suspended')->assertDispatched('toast');
    expect($wbStaff->refresh()->status->value)->toBe('suspended');
    Livewire::test(EmployeeEditor::class, ['employee' => $wbStaff])->set('newPassword', 'An0therGoodPass')->call('resetPassword')->assertHasNoErrors();
    expect(AuditLog::where('action', 'employee.password_reset')->where('auditable_id', $wbStaff->id)->exists())->toBeTrue();

    Livewire::test(EmployeeEditor::class, ['employee' => $multi])->call('setStatus', 'suspended')->assertForbidden();
});

it('saves the role permission matrix with an audit trail', function () {
    $this->actingAs($this->admin);
    $role = Role::where('key', 'sales_employee')->firstOrFail();
    Livewire::test(RoleEditor::class, ['role' => $role])
        ->set('granted.inventory.export', true)->set('granted.orders.edit', false)->call('save')->assertHasNoErrors();
    $keys = $role->permissions()->pluck('key');
    expect($keys)->toContain('inventory.export')->not->toContain('orders.edit');
    $log = AuditLog::where('action', 'role.permissions_updated')->latest('id')->firstOrFail();
    expect($log->metadata['added'])->toBe(['inventory.export'])->and($log->metadata['removed'])->toBe(['orders.edit']);

    // System roles cannot be deleted; super admin is locked.
    Livewire::test(RoleEditor::class, ['role' => $role])->call('delete')->assertDispatched('toast', type: 'error');
    expect(Role::find($role->id))->not->toBeNull();
    $super = Role::where('key', 'super_admin')->firstOrFail();
    Livewire::test(RoleEditor::class, ['role' => $super])->set('granted.audit.view', false)->call('save')->assertDispatched('toast', type: 'error');
    expect($super->permissions()->where('key', 'audit.view')->exists())->toBeTrue();
});

it('creates custom roles and blocks granting permissions the actor lacks', function () {
    $this->actingAs($this->admin);
    Livewire::test(RoleEditor::class)->set('form.key', 'showroom_host')->set('form.name_en', 'Showroom host')->set('form.name_ar', 'مضيف المعرض')
        ->set('form.scope', 'branch')->set('granted.catalog.view', true)->call('save')->assertHasNoErrors()->assertRedirect();
    expect(Role::where('key', 'showroom_host')->firstOrFail()->permissions()->pluck('key')->all())->toBe(['catalog.view']);

    // A company-wide user with roles.* but not settings.edit cannot add settings.edit to a role.
    $limited = User::factory()->staff()->create();
    $r = Role::create(['key' => 'role_admin', 'name_en' => 'Role admin', 'name_ar' => 'مسؤول الأدوار', 'scope' => 'global']);
    $r->permissions()->sync(Permission::where('key', 'like', 'roles.%')->pluck('id'));
    UserRole::create(['user_id' => $limited->id, 'role_id' => $r->id, 'branch_id' => null]);
    $this->actingAs($limited);
    $target = Role::where('key', 'showroom_host')->first();
    Livewire::test(RoleEditor::class, ['role' => $target])->set('granted.settings.edit', true)->call('save')->assertDispatched('toast', type: 'error');
    expect($target->permissions()->where('key', 'settings.edit')->exists())->toBeFalse();
});

it('saves settings, masks secrets and audits changes', function () {
    $this->actingAs($this->admin);
    Livewire::test(SettingsIndex::class)->set('tab', 'checkout')->set('values.checkout.checkout__cod_fee', '12.50')->call('save')->assertHasNoErrors();
    expect(app(Settings::class)->get('checkout.cod_fee'))->toBe('12.50');
    Livewire::test(SettingsIndex::class)->set('tab', 'checkout')->set('values.checkout.checkout__cod_fee', '-1')->call('save')->assertHasErrors();

    Livewire::test(SettingsIndex::class)->set('tab', 'integrations')->set('secrets.payments__sadad__secret_key', 'sk_live_abcdef123456')->call('save')
        ->assertDontSee('sk_live_abcdef123456')->assertSee('3456');
    expect(AuditLog::where('action', 'settings.updated')->count())->toBe(2);
    expect(json_encode(AuditLog::latest('id')->first()->new_values))->not->toContain('sk_live');
});

it('scopes the audit log to the viewer’s branches and audits exports', function () {
    $audit = app(AuditLogger::class);
    $audit->log('inventory.adjustment_posted', 'InventoryAdjustment', [], ['n' => 'WB-ENTRY'], branchId: $this->wb->id);
    $audit->log('inventory.adjustment_posted', 'InventoryAdjustment', [], ['n' => 'PRL-ENTRY'], branchId: $this->prl->id);
    $viewer = staffWithRole('branch_manager', $this->wb);
    Role::where('key', 'branch_manager')->first()->permissions()->syncWithoutDetaching(Permission::whereIn('key', ['audit.view', 'audit.export'])->pluck('id'));
    app(AccessControl::class)->forget();

    $this->actingAs($viewer);
    $c = Livewire::test(AuditIndex::class);
    $ids = $c->viewData('logs')->pluck('branch_id')->unique()->values()->all();
    expect($ids)->toBe([$this->wb->id]);
    $c->call('export')->assertFileDownloaded();
    expect(AuditLog::where('action', 'audit.exported')->where('actor_id', $viewer->id)->exists())->toBeTrue();
});
