<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Branches\Models\Branch;
use App\Domain\Branches\Services\EmployeeService;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessControl;
use App\Support\DomainException;

beforeEach(function () {
    [$this->a, $this->b] = Branch::factory()->count(2)->create();
    $this->svc = app(EmployeeService::class);
});

it('assigns an employee to multiple branches with different roles', function () {
    $admin = staffWithRole('admin');
    $emp = User::factory()->staff()->create();
    $this->svc->assignToBranch($admin, $emp, $this->a, ['order_manager']);
    $this->svc->assignToBranch($admin, $emp, $this->b, ['pickup_employee']);
    $acl = app(AccessControl::class);

    expect($acl->allows($emp, 'orders.assign', $this->a->id))->toBeTrue()
        ->and($acl->allows($emp, 'orders.assign', $this->b->id))->toBeFalse()
        ->and($acl->allows($emp, 'pickup.approve', $this->b->id))->toBeTrue();
});

it('prevents branch managers from assigning employees to other branches', function () {
    $mgr = staffWithRole('branch_manager', $this->a);
    $emp = User::factory()->staff()->create();

    expect(fn () => $this->svc->assignToBranch($mgr, $emp, $this->b, ['sales_employee']))->toThrow(DomainException::class);
    $this->svc->assignToBranch($mgr, $emp, $this->a, ['sales_employee']); // own branch is fine
    expect(app(AccessControl::class)->allows($emp, 'orders.view', $this->a->id))->toBeTrue();
});

it('prevents branch managers from granting roles beyond their own authority', function () {
    $mgr = staffWithRole('branch_manager', $this->a);
    $emp = User::factory()->staff()->create();

    // Inventory manager includes purchasing.export / suppliers.delete that a branch manager does not hold.
    expect(fn () => $this->svc->assignToBranch($mgr, $emp, $this->a, ['inventory_manager']))->toThrow(DomainException::class);
    expect(fn () => $this->svc->assignToBranch($mgr, $emp, $this->a, ['super_admin']))->toThrow(DomainException::class);
    expect(fn () => $this->svc->setGlobalRoles($mgr, $emp, ['accountant']))->toThrow(DomainException::class);
});

it('prevents anyone from changing their own assignments', function () {
    $mgr = staffWithRole('branch_manager', $this->a);
    expect(fn () => $this->svc->assignToBranch($mgr, $mgr, $this->b, ['branch_manager']))->toThrow(DomainException::class);
    expect(fn () => $this->svc->assignToBranch($mgr, $mgr, $this->a, ['branch_manager', 'inventory_manager']))->toThrow(DomainException::class);
});

it('revokes sessions and permissions when an employee is deactivated', function () {
    $admin = staffWithRole('admin');
    $emp = staffWithRole('order_manager', $this->a);
    $emp->createToken('t', ['staff']);
    $this->svc->setStatus($admin, $emp, 'deactivated');

    expect($emp->tokens()->count())->toBe(0)
        ->and(app(AccessControl::class)->allows($emp->refresh(), 'orders.view', $this->a->id))->toBeFalse();
});

it('limits branch managers to suspending staff of their own branches only', function () {
    $mgr = staffWithRole('branch_manager', $this->a);
    $own = staffWithRole('sales_employee', $this->a);
    $otherBranch = staffWithRole('sales_employee', $this->b);
    $companyWide = staffWithRole('accountant');

    $this->svc->setStatus($mgr, $own, 'suspended');
    expect($own->refresh()->status->value)->toBe('suspended');
    expect(fn () => $this->svc->setStatus($mgr, $otherBranch, 'suspended'))->toThrow(DomainException::class);
    expect(fn () => $this->svc->setStatus($mgr, $companyWide, 'suspended'))->toThrow(DomainException::class);
    expect($otherBranch->refresh()->status->value)->toBe('active');
});

it('records who changed assignments', function () {
    $admin = staffWithRole('admin');
    $emp = User::factory()->staff()->create();
    $this->svc->assignToBranch($admin, $emp, $this->a, ['sales_employee']);

    $log = AuditLog::where('action', 'employee.branch_assigned')->firstOrFail();
    expect($log->actor_id)->toBe($admin->id)->and($log->branch_id)->toBe($this->a->id)->and($log->new_values['roles'])->toBe(['sales_employee']);
});

it('exposes branch assignment through the admin API with branch scoping', function () {
    $emp = User::factory()->staff()->create();
    actingAsStaff(staffWithRole('branch_manager', $this->a));
    $this->postJson("/api/v1/admin/branches/{$this->b->id}/employees", ['user_id' => $emp->id, 'roles' => ['sales_employee']])->assertForbidden();
    $this->postJson("/api/v1/admin/branches/{$this->a->id}/employees", ['user_id' => $emp->id, 'roles' => ['sales_employee']])->assertCreated();
    $this->getJson("/api/v1/admin/branches/{$this->a->id}/employees")->assertOk()->assertJsonFragment(['roles' => ['sales_employee']]);
});
