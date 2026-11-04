<?php

namespace App\Domain\Branches\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Branches\Models\Branch;
use App\Domain\Branches\Models\BranchEmployee;
use App\Domain\Identity\Enums\UserType;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Models\UserRole;
use App\Domain\Identity\Services\AccessControl;
use App\Domain\Identity\Services\TokenService;
use App\Support\DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Employee lifecycle and branch/role assignment with authority checks:
 *  - The actor needs employees.assign on the target branch (or company-wide for global roles).
 *  - Nobody can change their own assignments (no self-elevation).
 *  - A non-super-admin can only grant roles whose permissions they themselves hold on that branch.
 *  - Only a Super Admin can grant the Super Admin flag or the super_admin role.
 */
class EmployeeService
{
    public function __construct(
        private readonly AccessControl $access,
        private readonly AuditLogger $audit,
        private readonly TokenService $tokens,
    ) {}

    public function create(User $actor, array $data): User
    {
        $this->assertCan($actor, 'employees.create', null);
        $user = User::create([
            'type' => UserType::Staff,
            'status' => 'active',
            'name' => $data['name'],
            'email' => strtolower($data['email']),
            'phone' => $data['phone'] ?? null,
            'job_title' => $data['job_title'] ?? null,
            'password' => $data['password'],
            'locale' => $data['locale'] ?? 'en',
        ]);
        $this->audit->log('employee.created', $user, [], $user->only(['name', 'email', 'job_title']), actor: $actor);

        return $user;
    }

    /**
     * Assign the employee to a branch with the given branch-scoped roles (replaces their roles at that branch).
     *
     * @param  list<string>  $roleKeys
     */
    public function assignToBranch(User $actor, User $employee, Branch $branch, array $roleKeys, ?string $jobTitle = null, bool $isDriver = false): BranchEmployee
    {
        $this->assertNotSelf($actor, $employee);
        $this->assertCan($actor, 'employees.assign', $branch->id);
        $roles = Role::whereIn('key', $roleKeys)->get();
        foreach ($roles as $role) {
            $this->assertCanGrantRole($actor, $role, $branch->id);
        }

        return DB::transaction(function () use ($actor, $employee, $branch, $roles, $jobTitle, $isDriver) {
            $assignment = BranchEmployee::updateOrCreate(
                ['branch_id' => $branch->id, 'user_id' => $employee->id],
                ['job_title' => $jobTitle, 'is_active' => true, 'is_delivery_driver' => $isDriver, 'assigned_by' => $actor->id],
            );
            $before = UserRole::where('user_id', $employee->id)->where('branch_id', $branch->id)->with('role')->get()->pluck('role.key')->all();
            UserRole::where('user_id', $employee->id)->where('branch_id', $branch->id)->delete();
            foreach ($roles as $role) {
                UserRole::create(['user_id' => $employee->id, 'role_id' => $role->id, 'branch_id' => $branch->id, 'assigned_by' => $actor->id]);
            }
            $this->access->forget($employee);
            $this->audit->log('employee.branch_assigned', $employee, ['roles' => $before], ['roles' => $roles->pluck('key')->all(), 'job_title' => $jobTitle], ['branch_id' => $branch->id], branchId: $branch->id, actor: $actor);

            return $assignment;
        });
    }

    public function setBranchAssignmentActive(User $actor, BranchEmployee $assignment, bool $active): void
    {
        $this->assertNotSelf($actor, $assignment->user);
        $this->assertCan($actor, 'employees.assign', $assignment->branch_id);
        $assignment->update(['is_active' => $active]);
        $this->access->forget($assignment->user);
        $this->audit->log($active ? 'employee.branch_activated' : 'employee.branch_deactivated', $assignment->user, [], [], ['branch_id' => $assignment->branch_id], branchId: $assignment->branch_id, actor: $actor);
    }

    public function removeFromBranch(User $actor, User $employee, Branch $branch): void
    {
        $this->assertNotSelf($actor, $employee);
        $this->assertCan($actor, 'employees.assign', $branch->id);
        DB::transaction(function () use ($employee, $branch) {
            UserRole::where('user_id', $employee->id)->where('branch_id', $branch->id)->delete();
            BranchEmployee::where('user_id', $employee->id)->where('branch_id', $branch->id)->delete();
        });
        $this->access->forget($employee);
        $this->audit->log('employee.branch_removed', $employee, [], [], ['branch_id' => $branch->id], branchId: $branch->id, actor: $actor);
    }

    /** @param list<string> $roleKeys company-wide (global) roles */
    public function setGlobalRoles(User $actor, User $employee, array $roleKeys): void
    {
        $this->assertNotSelf($actor, $employee);
        if (! $this->access->allowsGlobally($actor, 'roles.assign')) {
            throw DomainException::make('cannot_grant_beyond_authority', 403);
        }
        $roles = Role::whereIn('key', $roleKeys)->get();
        foreach ($roles as $role) {
            if (! $role->isGlobal()) {
                throw DomainException::make('cannot_grant_beyond_authority', 422);
            }
            $this->assertCanGrantRole($actor, $role, null);
        }
        DB::transaction(function () use ($actor, $employee, $roles) {
            $before = UserRole::where('user_id', $employee->id)->whereNull('branch_id')->with('role')->get()->pluck('role.key')->all();
            UserRole::where('user_id', $employee->id)->whereNull('branch_id')->delete();
            foreach ($roles as $role) {
                UserRole::create(['user_id' => $employee->id, 'role_id' => $role->id, 'branch_id' => null, 'assigned_by' => $actor->id]);
            }
            $this->access->forget($employee);
            $this->audit->log('employee.global_roles_updated', $employee, ['roles' => $before], ['roles' => $roles->pluck('key')->all()], actor: $actor);
        });
    }

    public function setStatus(User $actor, User $employee, string $status): void
    {
        $this->assertNotSelf($actor, $employee);
        if ($employee->isSuperAdmin() && ! $actor->isSuperAdmin()) {
            throw DomainException::make('cannot_grant_beyond_authority', 403);
        }
        // Company-wide authority may change anyone; branch authority only covers staff who work exclusively
        // in branches the actor manages and hold no company-wide role.
        if (! $this->access->allowsGlobally($actor, 'employees.edit')) {
            $hasGlobalRole = UserRole::where('user_id', $employee->id)->whereNull('branch_id')->exists();
            $branchIds = $employee->branchAssignments()->pluck('branch_id');
            if ($hasGlobalRole || $branchIds->isEmpty()) {
                throw DomainException::make('branch_forbidden', 403);
            }
            foreach ($branchIds as $branchId) {
                $this->assertCan($actor, 'employees.edit', $branchId);
            }
        }
        $old = $employee->status->value;
        $employee->update(['status' => $status]);
        if ($status !== 'active') {
            $this->tokens->revokeAll($employee, 'deactivated');
        }
        $this->access->forget($employee);
        $this->audit->log('employee.status_changed', $employee, ['status' => $old], ['status' => $status], actor: $actor);
    }

    private function assertCan(User $actor, string $permission, ?string $branchId): void
    {
        $ok = $branchId === null
            ? $this->access->allows($actor, $permission)
            : $this->access->allows($actor, $permission, $branchId);
        if (! $ok) {
            throw DomainException::make('branch_forbidden', 403);
        }
    }

    private function assertNotSelf(User $actor, User $employee): void
    {
        if ($actor->is($employee)) {
            throw DomainException::make('cannot_grant_beyond_authority', 403);
        }
    }

    private function assertCanGrantRole(User $actor, Role $role, ?string $branchId): void
    {
        if ($actor->isSuperAdmin()) {
            return;
        }
        if ($role->key === Role::SUPER_ADMIN || ($role->isGlobal() && $branchId === null && ! $this->access->allowsGlobally($actor, 'roles.assign'))) {
            throw DomainException::make('cannot_grant_beyond_authority', 403);
        }
        foreach ($role->permissions()->pluck('key') as $permission) {
            $held = $branchId === null ? $this->access->allowsGlobally($actor, $permission) : $this->access->allows($actor, $permission, $branchId);
            if (! $held) {
                throw DomainException::make('cannot_grant_beyond_authority', 403);
            }
        }
    }
}
