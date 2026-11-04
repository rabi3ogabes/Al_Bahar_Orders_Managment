<?php

use App\Domain\Branches\Models\Branch;
use App\Domain\Branches\Models\BranchEmployee;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Models\UserRole;
use App\Domain\Identity\Services\AccessControl;

beforeEach(function () {
    $this->a = Branch::factory()->create();
    $this->b = Branch::factory()->create();
    $this->acl = app(AccessControl::class);
});

it('grants super admin every permission on every branch', function () {
    $sa = User::factory()->superAdmin()->create();
    expect($this->acl->allows($sa, 'orders.refund', $this->b->id))->toBeTrue()
        ->and($this->acl->scope($sa, 'inventory.edit')->all)->toBeTrue();
});

it('restricts a branch manager to their assigned branch', function () {
    $mgr = staffWithRole('branch_manager', $this->a);

    expect($this->acl->allows($mgr, 'inventory.edit', $this->a->id))->toBeTrue()
        ->and($this->acl->allows($mgr, 'inventory.edit', $this->b->id))->toBeFalse()
        ->and($this->acl->scope($mgr, 'orders.view')->branchIds)->toBe([$this->a->id])
        ->and($this->acl->allows($mgr, 'settings.edit'))->toBeFalse();
});

it('supports staff assigned to multiple branches', function () {
    $mgr = staffWithRole('order_manager', $this->a);
    UserRole::create(['user_id' => $mgr->id, 'role_id' => Role::where('key', 'order_manager')->value('id'), 'branch_id' => $this->b->id]);
    BranchEmployee::create(['branch_id' => $this->b->id, 'user_id' => $mgr->id, 'is_active' => true]);

    expect($this->acl->scope($mgr, 'orders.view')->branchIds)->toEqualCanonicalizing([$this->a->id, $this->b->id]);
});

it('drops branch permissions as soon as the branch assignment is deactivated', function () {
    $mgr = staffWithRole('branch_manager', $this->a);
    BranchEmployee::where('user_id', $mgr->id)->update(['is_active' => false]);
    $this->acl->forget();

    expect($this->acl->allows($mgr, 'orders.view', $this->a->id))->toBeFalse();
});

it('gives company-wide roles access to all branches', function () {
    $acct = staffWithRole('accountant');
    expect($this->acl->allows($acct, 'refunds.approve', $this->b->id))->toBeTrue()
        ->and($this->acl->allows($acct, 'inventory.edit', $this->a->id))->toBeFalse();
});

it('narrows but never widens scope with a client-supplied branch id', function () {
    $mgr = staffWithRole('branch_manager', $this->a);
    $scope = $this->acl->scope($mgr, 'orders.view');

    expect($scope->narrow($this->b->id)->isEmpty())->toBeTrue()
        ->and($scope->narrow($this->a->id)->branchIds)->toBe([$this->a->id]);
});

it('never grants permissions to customers or inactive staff', function () {
    $customer = User::factory()->create();
    $inactive = staffWithRole('admin', null, ['status' => 'suspended']);

    expect($this->acl->allows($customer, 'orders.view'))->toBeFalse()
        ->and($this->acl->allows($inactive, 'orders.view'))->toBeFalse();
});

it('integrates with Laravel Gate using branch-aware arguments', function () {
    $mgr = staffWithRole('branch_manager', $this->a);
    expect($mgr->can('orders.view', $this->a->id))->toBeTrue()
        ->and($mgr->can('orders.view', $this->b))->toBeFalse()
        ->and($mgr->can('orders.view'))->toBeTrue();
});

it('exposes effective permissions and branches to staff clients', function () {
    $mgr = actingAsStaff(staffWithRole('pickup_employee', $this->a));
    $this->getJson('/api/v1/admin/me')->assertOk()
        ->assertJsonPath('data.branches.0.id', $this->a->id)
        ->assertJsonFragment(['pickup.approve']);
});
