<?php

use App\Domain\Branches\Models\Branch;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Livewire\Admin\Branches\BranchEditor;
use App\Livewire\Admin\Branches\BranchIndex;
use App\Livewire\Admin\Organization\EmployeeEditor;
use App\Livewire\Admin\Organization\EmployeeIndex;
use App\Livewire\Admin\Organization\RoleEditor;
use App\Livewire\Admin\Organization\RoleIndex;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/**
 * Employees, Roles and Branches are edited in a side window beside the list; the full page stays one click away. The window shows the very
 * editor the page uses (in its `embedded` layout), opens only what the page would open, and keeps its place in the URL (?edit= / ?new=1).
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    [$this->wb, $this->prl] = Branch::factory()->count(2)->create();
    $this->admin = User::factory()->superAdmin()->create();
    $this->manager = staffWithRole('branch_manager', $this->wb);
});

describe('employees', function () {
    it('opens an employee beside the list, with the page editor inside and a link to the full page', function () {
        $staff = staffWithRole('sales_employee', $this->wb, ['name' => 'Wafa Westbay']);
        $this->actingAs($this->admin);

        Livewire::test(EmployeeIndex::class)
            ->assertSet('showEditor', false)
            ->call('openEditor', $staff->id)
            ->assertSet('showEditor', true)->assertSet('editId', $staff->id)
            ->assertSeeLivewire(EmployeeEditor::class)
            ->assertSee(route('admin.employees.edit', $staff), false)
            ->assertSee(__('drawer_sales.open_full'));
    });

    it('lets a link, a reload or a shared URL open the window (?edit=) and a closed window leaves no id behind', function () {
        $staff = staffWithRole('sales_employee', $this->wb);
        $this->actingAs($this->admin);

        Livewire::withQueryParams(['edit' => $staff->id])->test(EmployeeIndex::class)->assertSet('showEditor', true)->assertSet('editId', $staff->id);

        Livewire::test(EmployeeIndex::class)->call('openEditor', $staff->id)->call('closeEditor')->assertSet('showEditor', false)->assertSet('editId', '');
        Livewire::test(EmployeeIndex::class)->call('openEditor', $staff->id)->set('showEditor', false)->assertSet('editId', '');
    });

    it('opens nothing for an id that is not an employee, not an id, or not visible to this viewer', function () {
        $wbStaff = staffWithRole('sales_employee', $this->wb);
        $prlStaff = staffWithRole('sales_employee', $this->prl);
        $customer = User::factory()->create();

        $this->actingAs($this->manager);
        foreach ([$prlStaff->id, $customer->id, 'not-an-id', '../../etc/passwd', str_repeat('a', 200), '00000000-0000-0000-0000-000000000000'] as $bad) {
            Livewire::test(EmployeeIndex::class)->call('openEditor', $bad)->assertSet('showEditor', false)->assertSet('editId', '');
            Livewire::withQueryParams(['edit' => $bad])->test(EmployeeIndex::class)->assertSet('showEditor', false)->assertSet('editId', '');
        }
        Livewire::test(EmployeeIndex::class)->call('openEditor', $wbStaff->id)->assertSet('showEditor', true);
    });

    it('opens the "new employee" window only for people who may add employees', function () {
        $this->actingAs($this->admin);
        Livewire::test(EmployeeIndex::class)->call('openCreate')->assertSet('creating', true)->assertSet('showEditor', false)
            ->assertSee(route('admin.employees.create'), false);
        Livewire::withQueryParams(['new' => '1'])->test(EmployeeIndex::class)->assertSet('creating', true);

        $viewer = staffWithRole('auditor');
        $this->actingAs($viewer);
        if (! $viewer->can('employees.create')) {
            Livewire::test(EmployeeIndex::class)->call('openCreate')->assertSet('creating', false);
            Livewire::withQueryParams(['new' => '1'])->test(EmployeeIndex::class)->assertSet('creating', false);
        }
    });

    it('shows a new employee in the window straight on their access tab, never navigating away', function () {
        $this->actingAs($this->manager);

        Livewire::test(EmployeeEditor::class, ['embedded' => true])
            ->set('form.name', 'Salma Ahmed')->set('form.email', 'salma@albahar.qa')->set('form.locale', 'en')->set('form.password', 'Str0ngPassword!')
            ->call('create')->assertHasNoErrors()->assertNoRedirect()->assertDispatched('record-created', tab: 'access');
        $salma = User::where('email', 'salma@albahar.qa')->firstOrFail();

        Livewire::test(EmployeeIndex::class)->call('openCreate')->dispatch('record-created', id: $salma->id, tab: 'access')
            ->assertSet('creating', false)->assertSet('showEditor', true)->assertSet('editId', $salma->id)->assertSet('landing', 'access');
    });

    it('tells the list when something was saved in the window, so the row behind is current', function () {
        $staff = staffWithRole('sales_employee', $this->wb, ['name' => 'Old Name']);
        $this->actingAs($this->admin);

        Livewire::test(EmployeeEditor::class, ['employee' => $staff, 'embedded' => true])
            ->set('form.name', 'New Name')->call('saveProfile')->assertHasNoErrors()->assertDispatched('record-changed');
        expect($staff->fresh()->name)->toBe('New Name');

        // The full page does not need the event and never sends it.
        Livewire::test(EmployeeEditor::class, ['employee' => $staff])->set('form.name', 'Third Name')->call('saveProfile')->assertNotDispatched('record-changed');
    });

    it('keeps every rule of the page inside the window', function () {
        $wbStaff = staffWithRole('sales_employee', $this->wb);
        $this->actingAs($this->manager);

        // A manager cannot suspend themselves, in the window or on the page.
        Livewire::test(EmployeeEditor::class, ['employee' => $this->manager, 'embedded' => true])->call('setStatus', 'suspended')->assertForbidden();
        Livewire::test(EmployeeEditor::class, ['employee' => $wbStaff, 'embedded' => true])->call('setStatus', 'suspended')->assertDispatched('toast');
        // The embedded flag cannot be switched by the browser.
        expect(fn () => Livewire::test(EmployeeEditor::class, ['employee' => $wbStaff, 'embedded' => true])->set('embedded', false))
            ->toThrow(CannotUpdateLockedPropertyException::class);
    });

    it('keeps the full pages working', function () {
        $staff = staffWithRole('sales_employee', $this->wb);
        $this->actingAs($this->admin);

        $this->get(route('admin.employees.edit', $staff))->assertOk()->assertSee($staff->email);
        $this->get(route('admin.employees.create'))->assertOk();
        $this->get(route('admin.employees.index'))->assertOk();
    });
});

describe('roles', function () {
    it('opens a role beside the list with the permission matrix inside and a link to the full page', function () {
        $role = Role::where('key', 'sales_employee')->firstOrFail();
        $this->actingAs($this->admin);

        Livewire::test(RoleIndex::class)
            ->call('openEditor', (string) $role->id)->assertSet('showEditor', true)->assertSeeLivewire(RoleEditor::class)
            ->assertSee(route('admin.roles.edit', $role), false)->assertSee(__('drawer_sales.open_full'));
        Livewire::withQueryParams(['edit' => (string) $role->id])->test(RoleIndex::class)->assertSet('showEditor', true);
    });

    it('opens nothing for an id that is not a role', function () {
        $this->actingAs($this->admin);
        foreach (['999999', 'abc', '-1', '1; drop table roles', ''] as $bad) {
            Livewire::test(RoleIndex::class)->call('openEditor', $bad)->assertSet('showEditor', false)->assertSet('editId', '');
        }
    });

    it('stays closed for people without the role permissions, like the page', function () {
        $this->actingAs($this->manager);
        $this->get(route('admin.roles.index'))->assertForbidden();
    });

    it('creates a role in the window, hands it to the list and never navigates away', function () {
        $this->actingAs($this->admin);

        Livewire::test(RoleIndex::class)->call('openCreate')->assertSet('creating', true);
        $c = Livewire::test(RoleEditor::class, ['embedded' => true])
            ->set('form.key', 'showroom_lead')->set('form.name_en', 'Showroom lead')->set('form.name_ar', 'مسؤول المعرض')->set('form.scope', 'branch')
            ->call('save')->assertHasNoErrors()->assertNoRedirect()->assertDispatched('record-created');
        $role = Role::where('key', 'showroom_lead')->firstOrFail();

        Livewire::test(RoleIndex::class)->call('openCreate')->dispatch('record-created', id: (string) $role->id)
            ->assertSet('creating', false)->assertSet('showEditor', true)->assertSet('editId', (string) $role->id);
    });

    it('deletes a role from the window and closes it', function () {
        $role = Role::create(['key' => 'temp_role', 'name_en' => 'Temp', 'name_ar' => 'مؤقت', 'scope' => 'branch', 'is_system' => false]);
        $this->actingAs($this->admin);

        Livewire::test(RoleEditor::class, ['role' => $role, 'embedded' => true])->call('delete')->assertNoRedirect()->assertDispatched('record-deleted');
        expect(Role::where('key', 'temp_role')->exists())->toBeFalse();

        Livewire::test(RoleIndex::class)->set('editId', '1')->set('showEditor', true)->dispatch('record-deleted')->assertSet('showEditor', false)->assertSet('editId', '');
    });

    it('tells the list when a role was saved', function () {
        $role = Role::create(['key' => 'temp_role2', 'name_en' => 'Temp', 'name_ar' => 'مؤقت', 'scope' => 'branch', 'is_system' => false]);
        $this->actingAs($this->admin);

        Livewire::test(RoleEditor::class, ['role' => $role, 'embedded' => true])->set('form.name_en', 'Renamed')->call('save')->assertDispatched('record-changed');
        Livewire::test(RoleEditor::class, ['role' => $role])->set('form.name_en', 'Renamed again')->call('save')->assertNotDispatched('record-changed');
        $this->get(route('admin.roles.edit', $role))->assertOk();
    });
});

describe('branches', function () {
    it('opens a branch beside the list with the page editor inside and a link to the full page', function () {
        $this->actingAs($this->admin);

        Livewire::test(BranchIndex::class)
            ->call('openEditor', $this->wb->id)->assertSet('showEditor', true)->assertSeeLivewire(BranchEditor::class)
            ->assertSee(route('admin.branches.edit', $this->wb), false)->assertSee(__('drawer_sales.open_full'));
        Livewire::withQueryParams(['edit' => $this->wb->id])->test(BranchIndex::class)->assertSet('showEditor', true);
    });

    it('lets a branch manager open only their own branch', function () {
        $this->actingAs($this->manager);

        Livewire::test(BranchIndex::class)->call('openEditor', $this->prl->id)->assertSet('showEditor', false)->assertSet('editId', '');
        Livewire::withQueryParams(['edit' => $this->prl->id])->test(BranchIndex::class)->assertSet('showEditor', false);
        Livewire::test(BranchIndex::class)->call('openEditor', $this->wb->id)->assertSet('showEditor', true);
        // and cannot start a new branch (company level), exactly like the page
        Livewire::test(BranchIndex::class)->call('openCreate')->assertSet('creating', false);
        $this->get(route('admin.branches.edit', $this->prl))->assertForbidden();
    });

    it('opens nothing for an id that is not a branch', function () {
        $this->actingAs($this->admin);
        foreach (['not-an-id', '00000000-0000-0000-0000-000000000000', '1', '../x'] as $bad) {
            Livewire::test(BranchIndex::class)->call('openEditor', $bad)->assertSet('showEditor', false)->assertSet('editId', '');
        }
    });

    it('creates a branch in the window and hands it to the list', function () {
        $this->actingAs($this->admin);

        Livewire::test(BranchIndex::class)->call('openCreate')->assertSet('creating', true)->assertSee(route('admin.branches.create'), false);
        Livewire::test(BranchEditor::class, ['embedded' => true])
            ->set('form.code', 'DOH2')->set('form.name_en', 'Doha Two')->set('form.name_ar', 'الدوحة ٢')
            ->call('save')->assertNoRedirect()->assertDispatched('record-created');
    });

    it('tells the list when a branch was saved, and keeps the page working', function () {
        $this->actingAs($this->admin);

        Livewire::test(BranchEditor::class, ['branch' => $this->wb, 'embedded' => true])
            ->set('form.area', 'West Bay')->set('form.dispatch_mode', 'manual')->set('form.pickup_preparation_minutes', 60)->set('form.sort_order', 0)->set('form.is_fulfillment_hub', false)
            ->call('save')->assertHasNoErrors()->assertDispatched('record-changed');
        $this->get(route('admin.branches.edit', $this->wb))->assertOk();
        $this->get(route('admin.branches.index'))->assertOk();
    });
});
