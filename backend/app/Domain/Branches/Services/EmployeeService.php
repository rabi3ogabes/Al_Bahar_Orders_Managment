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

    /**
     * Whether the actor may change this person's profile, status or password. Company-wide authority (employees.edit without a branch)
     * may manage anyone except a super admin; a branch manager only staff who work exclusively in branches they manage and hold no
     * company-wide role. Nobody manages themselves. One rule for the editor, the list's status switch and this service.
     *
     * @param  list<string>  $branchIds  the branches the employee is assigned to
     */
    public function canManage(User $actor, User $employee, bool $hasGlobalRole, array $branchIds): bool
    {
        if ($employee->is($actor) || ($employee->isSuperAdmin() && ! $actor->isSuperAdmin())) {
            return false;
        }
        if ($this->access->allowsGlobally($actor, 'employees.edit')) {
            return true;
        }
        if ($hasGlobalRole) {
            return false;
        }
        if ($branchIds === []) {
            return $this->access->allows($actor, 'employees.edit') && ! UserRole::where('user_id', $employee->id)->exists();
        }

        foreach ($branchIds as $branchId) {
            if (! $this->access->allows($actor, 'employees.edit', $branchId)) {
                return false;
            }
        }

        return true;
    }

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
            'must_change_password' => true,
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
            $this->dropPersonalRole($employee, $branch->id);
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
        if (! $active) {
            $this->endSessionsWithoutAccess($assignment->user);
        }
        $this->audit->log($active ? 'employee.branch_activated' : 'employee.branch_deactivated', $assignment->user, [], [], ['branch_id' => $assignment->branch_id], branchId: $assignment->branch_id, actor: $actor);
    }

    public function removeFromBranch(User $actor, User $employee, Branch $branch): void
    {
        $this->assertNotSelf($actor, $employee);
        $this->assertCan($actor, 'employees.assign', $branch->id);
        DB::transaction(function () use ($employee, $branch) {
            UserRole::where('user_id', $employee->id)->where('branch_id', $branch->id)->delete();
            BranchEmployee::where('user_id', $employee->id)->where('branch_id', $branch->id)->delete();
            $this->dropPersonalRole($employee, $branch->id);
        });
        $this->access->forget($employee);
        $this->endSessionsWithoutAccess($employee);
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
            $this->tokens->forgetWebSessions($employee);
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

    /**
     * Signs the person out everywhere once no branch assignment of theirs is active any more (and they hold no company-wide role):
     * their tokens would only ever answer 403, so there is no reason to keep them alive.
     */
    private function endSessionsWithoutAccess(User $employee): void
    {
        $stillWorks = BranchEmployee::where('user_id', $employee->id)->where('is_active', true)->exists()
            || UserRole::where('user_id', $employee->id)->whereNull('branch_id')->exists();
        if (! $stillWorks && ! $employee->isSuperAdmin()) {
            $this->tokens->revokeAll($employee, 'no_branch_access');
            $this->tokens->forgetWebSessions($employee);
        }
    }

    /** A personal feature role that no assignment uses any more is deleted (its permission rows go with it). */
    private function dropPersonalRole(User $employee, string $branchId): void
    {
        Role::where('personal_user_id', $employee->id)->where('personal_branch_id', $branchId)
            ->whereNotExists(fn ($q) => $q->from('user_roles')->whereColumn('user_roles.role_id', 'roles.id'))->delete();
    }

    private function assertCanGrantRole(User $actor, Role $role, ?string $branchId): void
    {
        // A personal checklist role belongs to one employee at one branch: it is changed through BranchEmployeeService only.
        if ($role->isPersonal()) {
            throw DomainException::make('cannot_grant_beyond_authority', 403);
        }
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
