<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Branches\Models\Branch;
use App\Domain\Branches\Models\BranchEmployee;
use App\Domain\Branches\Services\BranchEmployeeService;
use App\Domain\Branches\Services\EmployeeService;
use App\Domain\Identity\Models\Permission;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Models\UserRole;
use App\Domain\Identity\Services\AccessControl;
use App\Domain\Identity\Support\BranchFeatureCatalog;
use App\Livewire\Admin\Branches\BranchEditor;
use App\Livewire\Admin\Organization\EmployeeEditor;
use App\Livewire\Admin\Organization\EmployeeIndex;
use App\Livewire\Admin\Organization\RoleEditor;
use App\Livewire\Admin\Organization\RoleIndex;
use App\Support\DomainException;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

/**
 * "Add branch employee": an account for someone who works at one branch, with a plain-words checklist of the features they may use
 * (a personal role). Covers the create flow, the security rules (no escalation, branch scope, no admin accounts), the editor and the list.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    [$this->wb, $this->prl] = Branch::factory()->count(2)->create();
    $this->admin = User::factory()->superAdmin()->create();
    $this->manager = staffWithRole('branch_manager', $this->wb);
    $this->svc = app(BranchEmployeeService::class);
});

/** A branch employee made through the service as $by (default: the super admin). */
function makeEmployee(array $features, ?Branch $branch = null, ?User $by = null, array $profile = []): User
{
    return app(BranchEmployeeService::class)->create($by ?? test()->admin, $branch ?? test()->wb, $profile + [
        'name' => 'Noor Employee', 'email' => 'noor'.random_int(1000, 9999).'@albahar.qa', 'phone' => null, 'locale' => 'en', 'job_title' => null,
        'password' => 'Str0ngPassword!',
    ], $features);
}

function personalRole(User $u, Branch $b): ?Role
{
    return Role::where('personal_user_id', $u->id)->where('personal_branch_id', $b->id)->first();
}

describe('creating a branch employee', function () {
    it('opens a staff account at the branch with exactly the ticked features, a temporary password and an audit trail', function () {
        $this->actingAs($this->admin);
        Livewire::test(EmployeeEditor::class)
            ->set('form', ['name' => 'Salma Ahmed', 'email' => 'Salma@Albahar.qa', 'phone' => '5512 3456', 'job_title' => 'Cashier', 'locale' => 'ar', 'password' => 'Str0ngPassword!'])
            ->set('branchId', $this->wb->id)->set('features', ['orders_view', 'pickup_view', 'pickup_handover'])
            ->call('create')->assertHasNoErrors()->assertRedirect();

        $u = User::where('email', 'salma@albahar.qa')->firstOrFail();
        expect($u->isStaff())->toBeTrue()->and($u->isActive())->toBeTrue()->and($u->is_super_admin)->toBeFalse()
            ->and($u->phone)->toBe('+97455123456')->and($u->locale)->toBe('ar')->and($u->must_change_password)->toBeTrue();
        expect(BranchEmployee::where('user_id', $u->id)->where('branch_id', $this->wb->id)->where('is_active', true)->exists())->toBeTrue();

        // Exactly one role, branch scoped, and it is the personal one: no company-wide / admin role anywhere.
        $assignments = UserRole::where('user_id', $u->id)->with('role')->get();
        expect($assignments)->toHaveCount(1)->and($assignments[0]->branch_id)->toBe($this->wb->id);
        $role = $assignments[0]->role;
        expect($role->isPersonal())->toBeTrue()->and($role->scope)->toBe('branch')->and($role->is_system)->toBeFalse()
            ->and($role->personal_user_id)->toBe($u->id);
        expect($role->permissions()->pluck('key')->all())->toEqualCanonicalizing(['orders.view', 'pickup.view', 'pickup.edit', 'pickup.approve']);

        $acl = app(AccessControl::class);
        expect($acl->allows($u, 'orders.view', $this->wb->id))->toBeTrue()
            ->and($acl->allows($u, 'orders.view', $this->prl->id))->toBeFalse()
            ->and($acl->allows($u, 'inventory.view', $this->wb->id))->toBeFalse()
            ->and($acl->allowsGlobally($u, 'orders.view'))->toBeFalse();

        $created = AuditLog::where('action', 'employee.created')->where('auditable_id', $u->id)->firstOrFail();
        expect($created->branch_id)->toBe($this->wb->id)->and($created->actor_id)->toBe($this->admin->id);
        $changed = AuditLog::where('action', 'employee.access_changed')->where('auditable_id', $u->id)->firstOrFail();
        expect($changed->old_values['permissions'])->toBe([]) // nothing before
            ->and($changed->new_values['permissions'])->toEqualCanonicalizing(['orders.view', 'pickup.view', 'pickup.edit', 'pickup.approve'])
            ->and($changed->new_values['features'])->toEqualCanonicalizing(['orders_view', 'pickup_view', 'pickup_handover'])
            ->and(json_encode($changed->toArray()))->not->toContain('Str0ngPassword');
    });

    it('lets a branch manager add staff to their own branch only', function () {
        $this->actingAs($this->manager);
        Livewire::test(EmployeeEditor::class)->assertSet('branchId', $this->wb->id) // their only branch is preselected
            ->set('form', ['name' => 'Wafa', 'email' => 'wafa@albahar.qa', 'phone' => '', 'job_title' => '', 'locale' => 'en', 'password' => 'Str0ngPassword!'])
            ->set('features', ['orders_view'])->call('create')->assertHasNoErrors();
        expect(User::where('email', 'wafa@albahar.qa')->exists())->toBeTrue();

        // A tampered branch id: the window drops it, and even if it is forced through the service refuses.
        $evil = Livewire::test(EmployeeEditor::class)
            ->set('form', ['name' => 'Evil', 'email' => 'evil@albahar.qa', 'phone' => '', 'job_title' => '', 'locale' => 'en', 'password' => 'Str0ngPassword!'])
            ->set('branchId', $this->prl->id)->assertSet('branchId', '');
        $evil->set('features', ['orders_view'])->call('create')->assertHasErrors(['branchId']);
        expect(fn () => makeEmployee(['orders_view'], $this->prl, $this->manager))->toThrow(DomainException::class);
        expect(User::where('email', 'evil@albahar.qa')->exists())->toBeFalse();
    });

    it('never offers or accepts a feature that does not exist or is admin-only', function () {
        $this->actingAs($this->admin);
        $base = fn () => Livewire::test(EmployeeEditor::class)
            ->set('form', ['name' => 'X', 'email' => 'x@albahar.qa', 'phone' => '', 'job_title' => '', 'locale' => 'en', 'password' => 'Str0ngPassword!'])
            ->set('branchId', $this->wb->id);
        foreach (['roles_edit', 'settings.edit', 'roles.edit', 'cms.edit', 'marketing.view'] as $bad) {
            $base()->set('features', ['orders_view', $bad])->call('create')->assertHasErrors(['features.1']);
        }
        expect(fn () => $this->svc->create($this->admin, $this->wb, ['name' => 'X', 'email' => 'y@albahar.qa', 'password' => 'Str0ngPassword!'], ['orders_view', 'roles_edit']))
            ->toThrow(DomainException::class);
        expect(User::where('email', 'x@albahar.qa')->exists())->toBeFalse();
    });

    it('adds the features a ticked feature cannot work without', function () {
        $u = makeEmployee(['orders_create'], by: $this->admin);
        expect($this->svc->featuresOf($u, $this->wb->id))->toEqualCanonicalizing(['orders_view', 'orders_create', 'products_view']);
    });

    it('validates the details: contact, unique phone and e-mail, password, branch and features', function () {
        $this->actingAs($this->admin);
        User::factory()->staff()->create(['email' => 'taken@albahar.qa', 'phone' => '+97455123456']);
        $valid = ['name' => 'Ok', 'email' => 'ok@albahar.qa', 'phone' => '', 'job_title' => '', 'locale' => 'en', 'password' => 'Str0ngPassword!'];
        $try = fn (array $over, array $features = ['orders_view'], ?string $branch = null) => Livewire::test(EmployeeEditor::class)
            ->set('form', $over + $valid)->set('branchId', $branch ?? $this->wb->id)->set('features', $features)->call('create');

        $try(['email' => '', 'phone' => ''])->assertHasErrors(['form.email', 'form.phone']);          // one of them is needed
        $try(['email' => 'TAKEN@albahar.qa'])->assertHasErrors(['form.email']);                       // unique, ignoring letter case
        $try(['phone' => '+974 5512 3456'])->assertHasErrors(['form.phone']);                         // unique once normalised
        $try(['phone' => '5512 3456'])->assertHasErrors(['form.phone']);                              // ... also without the country code
        $try(['phone' => '12345'])->assertHasErrors(['form.phone']);                                  // not a mobile number
        $try(['phone' => 'abc'])->assertHasErrors(['form.phone']);
        $try(['password' => 'short1A'])->assertHasErrors(['form.password']);
        $try(['password' => 'alllowercase1234'])->assertHasErrors(['form.password']);
        $try(['password' => 'NoDigitsHereAtAll'])->assertHasErrors(['form.password']);
        $try(['name' => ''])->assertHasErrors(['form.name']);
        $try(['name' => str_repeat('a', 121)])->assertHasErrors(['form.name']);
        $try(['locale' => 'fr'])->assertHasErrors(['form.locale']);
        $try([], [])->assertHasErrors(['features']);                                                  // at least one feature
        Livewire::test(EmployeeEditor::class)->set('form', $valid)->set('features', ['orders_view'])->call('create')->assertHasErrors(['branchId']);
        expect(User::where('email', 'ok@albahar.qa')->exists())->toBeFalse();

        // phone only is fine, and the number is stored in E.164
        $try(['email' => '', 'phone' => '+974 6600 1122'])->assertHasNoErrors();
        expect(User::where('phone', '+97466001122')->whereNull('email')->exists())->toBeTrue();
    });

    it('stops an account being opened twice for the same e-mail by two people at once (database guard)', function () {
        makeEmployee(['orders_view'], profile: ['email' => 'dup@albahar.qa']);
        expect(fn () => makeEmployee(['orders_view'], profile: ['email' => 'dup@albahar.qa']))->toThrow(DomainException::class, __('branch_access.error_contact_taken'));
    });

    it('rate limits opening accounts per person', function () {
        RateLimiter::clear('branch-employee-create:'.$this->admin->id);
        for ($i = 0; $i < BranchEmployeeService::CREATE_PER_HOUR; $i++) {
            makeEmployee(['orders_view'], profile: ['email' => "bulk{$i}@albahar.qa"]);
        }
        $err = null;
        try {
            makeEmployee(['orders_view'], profile: ['email' => 'one-too-many@albahar.qa']);
        } catch (DomainException $e) {
            $err = $e;
        }
        expect($err)->not->toBeNull()->and($err->status)->toBe(429);
        expect(User::where('email', 'one-too-many@albahar.qa')->exists())->toBeFalse();
    });

    it('keeps company-wide accounts to people who may grant company-wide roles', function () {
        $this->actingAs($this->manager);
        Livewire::test(EmployeeEditor::class)->call('useCompanyWide')->assertForbidden();

        $this->actingAs($this->admin);
        Livewire::test(EmployeeEditor::class)->call('useCompanyWide')->assertSet('kind', 'company')
            ->set('form', ['name' => 'Acc Ountant', 'email' => 'acc@albahar.qa', 'phone' => '', 'job_title' => '', 'locale' => 'en', 'password' => 'Str0ngPassword!'])
            ->call('create')->assertHasNoErrors();
        $acc = User::where('email', 'acc@albahar.qa')->firstOrFail();
        expect($acc->isStaff())->toBeTrue()->and($acc->must_change_password)->toBeTrue()
            ->and(UserRole::where('user_id', $acc->id)->exists())->toBeFalse(); // no access until an admin grants company roles
    });
});

describe('security: no escalation, no foreign branches, no admins', function () {
    it('never lets someone grant a feature they do not hold themselves', function () {
        // The manager's role has support.view but not support.create/edit, so "Reply to support requests" is not theirs to give.
        expect($this->svc->grantable($this->manager, $this->wb->id))->not->toContain('support_reply');
        expect(fn () => makeEmployee(['orders_view', 'support_reply'], by: $this->manager))->toThrow(DomainException::class, __('errors.cannot_grant_beyond_authority'));
        // A custom, narrower manager: only order features. Cannot hand out stock.
        $narrow = staffWithRole('order_manager', $this->wb);
        UserRole::where('user_id', $narrow->id)->update(['role_id' => Role::where('key', 'order_manager')->value('id')]);
        app(AccessControl::class)->forget();
        // order_manager has no employees.* at all, so it cannot create or assign anyone.
        expect(fn () => makeEmployee(['orders_view'], by: $narrow))->toThrow(DomainException::class);

        // A manager who may add staff but only holds stock.view cannot give out stock adjustments.
        $custom = Role::create(['key' => 'staff_adder', 'name_en' => 'Staff adder', 'name_ar' => 'مضيف موظفين', 'scope' => 'branch']);
        $custom->permissions()->sync(Permission::whereIn('key', ['employees.view', 'employees.create', 'employees.assign', 'inventory.view'])->pluck('id'));
        $adder = staffWithRole('staff_adder', $this->wb);
        expect($this->svc->grantable($adder, $this->wb->id))->toEqualCanonicalizing(['stock_view', 'staff_view']);
        expect(fn () => makeEmployee(['stock_view', 'stock_adjust'], by: $adder))->toThrow(DomainException::class);
        expect(makeEmployee(['stock_view'], by: $adder, profile: ['email' => 'sv@albahar.qa']))->toBeInstanceOf(User::class);
    });

    it('never lets the window tick a feature the person cannot grant (tampered request), and tells them', function () {
        $this->actingAs($this->manager);
        Livewire::test(EmployeeEditor::class)
            ->set('form', ['name' => 'T', 'email' => 't@albahar.qa', 'phone' => '', 'job_title' => '', 'locale' => 'en', 'password' => 'Str0ngPassword!'])
            ->set('features', ['orders_view', 'support_reply'])->call('create')->assertDispatched('toast', type: 'error');
        expect(User::where('email', 't@albahar.qa')->exists())->toBeFalse();
    });

    it('does not let anyone change their own access', function () {
        expect(fn () => $this->svc->setFeatures($this->manager, $this->manager, $this->wb, ['orders_view']))->toThrow(DomainException::class);
        $this->actingAs($this->manager);
        $page = Livewire::test(EmployeeEditor::class, ['employee' => $this->manager, 'tab' => 'access']);
        $page->assertSet('features', [])->assertSee(__('branch_access.read_only_self'));
        $page->set('branchId', $this->wb->id)->set('features', ['orders_view'])->call('saveFeatures')->assertForbidden();
        // The role they hold is unchanged.
        expect(UserRole::where('user_id', $this->manager->id)->count())->toBe(1);
    });

    it('does not touch an admin, a super admin or anyone with a company-wide role', function () {
        $acct = staffWithRole('accountant');
        BranchEmployee::create(['branch_id' => $this->wb->id, 'user_id' => $acct->id, 'is_active' => true]);
        $sa2 = User::factory()->superAdmin()->create();
        BranchEmployee::create(['branch_id' => $this->wb->id, 'user_id' => $sa2->id, 'is_active' => true]);
        foreach ([$acct, $sa2] as $target) {
            expect(fn () => $this->svc->setFeatures($this->manager, $target, $this->wb, ['orders_view']))->toThrow(DomainException::class);
            // not even a super admin can turn a company-wide account into a branch employee through the checklist
            expect(fn () => $this->svc->setFeatures($this->admin, $target, $this->wb, ['orders_view']))->toThrow(DomainException::class);
        }
        expect(Role::whereNotNull('personal_user_id')->count())->toBe(0);

        $this->actingAs($this->manager);
        $page = Livewire::test(EmployeeEditor::class, ['employee' => $acct, 'tab' => 'access']);
        $page->assertSee(__('branch_access.read_only_company'));
        $page->set('branchId', $this->wb->id)->set('features', ['orders_view'])->call('saveFeatures')->assertForbidden();
        expect(app(AccessControl::class)->allows($acct->fresh(), 'refunds.approve'))->toBeTrue(); // still an accountant
    });

    it('does not let a manager of one branch change somebody at another branch', function () {
        $other = makeEmployee(['orders_view'], $this->prl, $this->admin, ['email' => 'prl@albahar.qa']);
        expect(fn () => $this->svc->setFeatures($this->manager, $other, $this->prl, ['orders_view', 'orders_prepare']))->toThrow(DomainException::class);
        expect(fn () => $this->svc->setFeatures($this->manager, $other, $this->wb, ['orders_view']))->toThrow(DomainException::class); // not assigned there
        $this->actingAs($this->manager);
        $this->get(route('admin.employees.edit', $other))->assertForbidden();
        Livewire::test(EmployeeIndex::class)->assertDontSee('prl@albahar.qa');
    });

    it('lets a manager change only the branches they manage when the employee works at two', function () {
        $two = makeEmployee(['orders_view'], $this->wb, $this->admin, ['email' => 'two@albahar.qa']);
        $this->svc->setFeatures($this->admin, $two, $this->wb, ['orders_view']);
        // add the second branch (an admin does it) with its own checklist
        app(EmployeeService::class)->assignToBranch($this->admin, $two, $this->prl, ['sales_employee']);
        $this->svc->setFeatures($this->admin, $two, $this->prl, ['stock_view']);

        $this->svc->setFeatures($this->manager, $two, $this->wb, ['orders_view', 'orders_prepare']);
        expect($this->svc->featuresOf($two, $this->wb->id))->toEqualCanonicalizing(['orders_view', 'orders_prepare'])
            ->and($this->svc->featuresOf($two, $this->prl->id))->toBe(['stock_view']); // the other branch is untouched
        expect(fn () => $this->svc->setFeatures($this->manager, $two, $this->prl, ['orders_view']))->toThrow(DomainException::class);

        $this->actingAs($this->manager);
        $page = Livewire::test(EmployeeEditor::class, ['employee' => $two]);
        $page->assertSet('branchId', $this->wb->id)->assertSet('features', fn ($f) => in_array('orders_prepare', $f, true));
        $page->set('branchId', $this->prl->id)->assertSet('branchId', $this->wb->id); // the other branch cannot be selected
    });

    it('does not let a manager add or remove a permission they do not hold, even one hidden inside a role', function () {
        // An admin gave the employee support replies; the manager does not hold support.create/edit.
        $emp = makeEmployee(['orders_view', 'support_reply'], by: $this->admin, profile: ['email' => 'pe@albahar.qa']);
        expect(fn () => $this->svc->setFeatures($this->manager, $emp, $this->wb, ['orders_view']))->toThrow(DomainException::class);
        expect($this->svc->featuresOf($emp, $this->wb->id))->toContain('support_reply');
        // ... but they can still change the parts they do hold, while the locked one stays ticked.
        $this->svc->setFeatures($this->manager, $emp, $this->wb, ['orders_view', 'orders_prepare', 'support_reply']);
        expect($this->svc->featuresOf($emp, $this->wb->id))->toEqualCanonicalizing(['orders_view', 'orders_prepare', 'support_reply', 'support_view']);

        // A shared role carrying permissions beyond the manager's: replacing it with a checklist is refused.
        $role = Role::create(['key' => 'super_picker', 'name_en' => 'Picker', 'name_ar' => 'منتقي', 'scope' => 'branch']);
        $role->permissions()->sync(Permission::whereIn('key', ['orders.view', 'settings.view'])->pluck('id'));
        $victim = staffWithRole('super_picker', $this->wb);
        expect(fn () => $this->svc->setFeatures($this->manager, $victim, $this->wb, ['orders_view']))->toThrow(DomainException::class);
        expect(UserRole::where('user_id', $victim->id)->where('role_id', $role->id)->exists())->toBeTrue();
    });

    it('keeps a personal checklist role out of the Roles page, the role pickers and everybody else’s hands', function () {
        $emp = makeEmployee(['orders_view'], by: $this->admin, profile: ['email' => 'hide@albahar.qa']);
        $personal = personalRole($emp, $this->wb);
        $this->actingAs($this->admin);
        Livewire::test(RoleIndex::class)->assertDontSee($personal->key)->assertDontSee('Personal access');
        Livewire::test(RoleIndex::class)->call('openEditor', (string) $personal->id)->assertSet('showEditor', false);
        $this->get(route('admin.roles.edit', $personal))->assertNotFound();
        Livewire::test(RoleEditor::class, ['role' => $personal])->assertNotFound();
        Livewire::test(EmployeeEditor::class, ['employee' => $emp])->assertDontSee('value="'.$personal->key.'"', false);
        Livewire::test(BranchEditor::class, ['branch' => $this->wb])->assertDontSee('value="'.$personal->key.'"', false);

        // the generic "assign roles" paths refuse it
        $someone = staffWithRole('sales_employee', $this->wb);
        expect(fn () => app(EmployeeService::class)->assignToBranch($this->admin, $someone, $this->wb, [$personal->key]))->toThrow(DomainException::class);
        expect(UserRole::where('user_id', $someone->id)->where('role_id', $personal->id)->exists())->toBeFalse();
    });
});

describe('the employee editor', function () {
    it('shows the checklist pre-filled from what the person can do now and saves the new one, replacing roles', function () {
        $emp = staffWithRole('sales_employee', $this->wb, ['email' => 'sales@albahar.qa']); // a ready-made role, not a checklist
        $this->actingAs($this->admin);
        $page = Livewire::test(EmployeeEditor::class, ['employee' => $emp, 'embedded' => true, 'tab' => 'access']);
        // sales_employee: dashboard.view catalog.view inventory.view orders.view orders.create orders.edit customers.view pickup.view
        $page->assertSet('branchId', $this->wb->id)->assertSet('features', fn ($f) => in_array('orders_create', $f, true) && in_array('stock_view', $f, true) && ! in_array('cash_view', $f, true));
        $page->assertSee(__('branch_access.replaces_roles', ['roles' => 'Sales Employee']));

        $page->set('features', ['orders_view', 'cash_view'])->call('saveFeatures')->assertHasNoErrors()->assertDispatched('toast', type: 'success')->assertDispatched('record-changed');
        expect(UserRole::where('user_id', $emp->id)->where('branch_id', $this->wb->id)->count())->toBe(1);
        expect($this->svc->featuresOf($emp, $this->wb->id))->toEqualCanonicalizing(['orders_view', 'cash_view']);
        $log = AuditLog::where('action', 'employee.access_changed')->where('auditable_id', $emp->id)->latest('id')->firstOrFail();
        expect($log->old_values['permissions'])->toContain('orders.create', 'inventory.view')
            ->and($log->new_values['permissions'])->toEqualCanonicalizing(['orders.view', 'payments.view'])
            ->and($log->metadata['replaced_roles'])->toBe(['sales_employee'])->and($log->branch_id)->toBe($this->wb->id);
    });

    it('asks for at least one feature and ignores ids that are not in the checklist', function () {
        $emp = makeEmployee(['orders_view'], by: $this->admin);
        $this->actingAs($this->admin);
        Livewire::test(EmployeeEditor::class, ['employee' => $emp])->set('features', [])->call('saveFeatures')->assertHasErrors(['features']);
        Livewire::test(EmployeeEditor::class, ['employee' => $emp])->set('features', ['settings_edit'])->call('saveFeatures')->assertHasErrors(['features.0']);
        expect($this->svc->featuresOf($emp, $this->wb->id))->toBe(['orders_view']);
    });

    it('removes a feature together with everything that needs it, and writes nothing when nothing changed', function () {
        $emp = makeEmployee(['orders_view', 'orders_prepare', 'orders_cancel'], by: $this->admin);
        $before = AuditLog::where('action', 'employee.access_changed')->count();
        $this->svc->setFeatures($this->admin, $emp, $this->wb, ['orders_view', 'orders_prepare', 'orders_cancel']); // unchanged
        expect(AuditLog::where('action', 'employee.access_changed')->count())->toBe($before);
        $this->svc->setFeatures($this->admin, $emp, $this->wb, ['orders_prepare']); // needs orders_view, which comes back
        expect($this->svc->featuresOf($emp, $this->wb->id))->toEqualCanonicalizing(['orders_view', 'orders_prepare']);
        // the personal role is reused, never duplicated
        expect(Role::where('personal_user_id', $emp->id)->count())->toBe(1);
    });

    it('keeps the personal role when roles are offered over it (refused), and removes it with the branch assignment', function () {
        $emp = makeEmployee(['orders_view'], by: $this->admin);
        // roles cannot replace a checklist from the assignment path
        expect(fn () => app(EmployeeService::class)->assignToBranch($this->admin, $emp, $this->wb, ['sales_employee']))->toThrow(DomainException::class, __('branch_access.error_personal_role_replace'));
        expect(Role::where('personal_user_id', $emp->id)->count())->toBe(1)->and($this->svc->featuresOf($emp, $this->wb->id))->toBe(['orders_view']);
        app(EmployeeService::class)->removeFromBranch($this->admin, $emp, $this->wb);
        expect(Role::where('personal_user_id', $emp->id)->exists())->toBeFalse()->and(UserRole::where('user_id', $emp->id)->exists())->toBeFalse();
    });

    it('lists the personal checklist as a custom or preset role on the assignment, never as an editable role', function () {
        $emp = makeEmployee(BranchFeatureCatalog::presets()['order_desk'], by: $this->admin, profile: ['email' => 'desk@albahar.qa']);
        $this->actingAs($this->admin);
        Livewire::test(EmployeeEditor::class, ['employee' => $emp, 'tab' => 'access'])
            ->assertSee(__('branch_access.personal_role'))
            ->call('editAssignment', $this->wb->id)->assertSet('assign.roles', []); // the checklist is not a role you can tick
    });

    it('reset password makes the new password temporary and ends every session', function () {
        $emp = makeEmployee(['orders_view'], by: $this->admin);
        $emp->forceFill(['must_change_password' => false])->save();
        $emp->createToken('app', ['staff']);
        $this->actingAs($this->manager);
        Livewire::test(EmployeeEditor::class, ['employee' => $emp])->set('newPassword', 'An0therGoodPass')->call('resetPassword')->assertHasNoErrors();
        expect($emp->fresh()->must_change_password)->toBeTrue()->and($emp->tokens()->count())->toBe(0);
    });
});

describe('the employees list', function () {
    it('shows the preset, branches, last sign-in and a features count with the feature names', function () {
        $emp = makeEmployee(BranchFeatureCatalog::presets()['stock_keeper'], by: $this->admin, profile: ['name' => 'Karim Keeper', 'email' => 'karim@albahar.qa']);
        $emp->forceFill(['last_login_at' => now()->subDay()])->save();
        $custom = makeEmployee(['orders_view', 'cash_view'], by: $this->admin, profile: ['name' => 'Cora Custom', 'email' => 'cora@albahar.qa']);
        $this->actingAs($this->admin);
        $count = count(BranchFeatureCatalog::withNeeds(BranchFeatureCatalog::presets()['stock_keeper']));
        Livewire::test(EmployeeIndex::class)
            ->assertSee('Karim Keeper')->assertSee(__('branch_access.preset_stock_keeper'))
            ->assertSee(__('branch_access.features_count', ['n' => $count, 'total' => count(BranchFeatureCatalog::ids())]))
            ->assertSee(__('branch_access.stock_adjust'))              // in the tooltip
            ->assertSee($this->wb->code)->assertSee(__('branch_access.custom_access'))
            ->assertSee(__('branch_access.waiting_first_sign_in'));
        Livewire::test(EmployeeIndex::class)->set('filterRole', EmployeeIndex::FILTER_PERSONAL)->assertSee('Cora Custom')->assertDontSee($this->manager->name);
        Livewire::test(EmployeeIndex::class)->set('branch', $this->prl->id)->assertDontSee('Karim Keeper');
        Livewire::test(EmployeeIndex::class)->set('search', 'karim')->assertSee('Karim Keeper')->assertDontSee('Cora Custom');
        expect($custom->isStaff())->toBeTrue();
    });

    it('switches somebody off and on from the row, with Undo, and signs them out at once', function () {
        $emp = makeEmployee(['orders_view'], by: $this->admin, profile: ['name' => 'Switch Me', 'email' => 'switch@albahar.qa']);
        $emp->createToken('app', ['staff']);
        $this->actingAs($this->manager);
        $list = Livewire::test(EmployeeIndex::class)->assertSeeHtml('role="switch"');
        $list->call('toggleActive', $emp->id)->assertDispatched('toast', fn ($name, $params) => ($params['undoEvent'] ?? null) === 'employee-status-undo' && ($params['undoParams']['status'] ?? null) === 'active');
        expect($emp->fresh()->status->value)->toBe('suspended')->and($emp->tokens()->count())->toBe(0);
        expect(AuditLog::where('action', 'employee.status_changed')->where('auditable_id', $emp->id)->exists())->toBeTrue();
        $list->call('undoStatus', $emp->id, 'active');
        expect($emp->fresh()->status->value)->toBe('active');
    });

    it('does not offer the switch for people the viewer may not manage, and refuses it if forced', function () {
        $other = makeEmployee(['orders_view'], $this->prl, $this->admin, ['email' => 'other@albahar.qa']);
        $this->actingAs($this->manager);
        $list = Livewire::test(EmployeeIndex::class);
        $list->assertDontSee('other@albahar.qa');
        $list->call('toggleActive', $other->id);
        $list->call('toggleActive', $this->manager->id)->assertDispatched('toast', type: 'error'); // not yourself
        $list->call('toggleActive', $this->admin->id);                                              // not a super admin
        expect($other->fresh()->status->value)->toBe('active')->and($this->manager->fresh()->status->value)->toBe('active')->and($this->admin->fresh()->status->value)->toBe('active');
    });
});
