<?php

namespace App\Domain\Branches\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Branches\Models\Branch;
use App\Domain\Branches\Models\BranchEmployee;
use App\Domain\Identity\Enums\UserType;
use App\Domain\Identity\Models\Permission;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Models\UserRole;
use App\Domain\Identity\Services\AccessControl;
use App\Domain\Identity\Support\BranchFeatureCatalog;
use App\Support\DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Branch employees and their feature access.
 *
 * What a branch employee may use is a checklist of plain-words features (BranchFeatureCatalog). It is stored as a PERSONAL role: an
 * ordinary branch-scoped role row owned by one employee at one branch (roles.personal_user_id / personal_branch_id) and assigned through
 * user_roles. AccessControl therefore resolves it like any other role — there is no second permission path to keep safe — while the
 * Roles page and every role picker never show it.
 *
 * Authority rules (every one is enforced here, never only in the UI):
 *  - The actor needs employees.assign at the branch, and employees.create to open an account.
 *  - Every permission that changes (switched on or off) must be one the actor holds at that branch: nobody grants what they do not have.
 *  - Nobody changes their own access; a super admin, or anyone with a company-wide role, is never touched here.
 *  - The account is staff, in the one branch, with a branch-scoped personal role only: no company-wide role, never a super admin.
 */
class BranchEmployeeService
{
    /** Accounts one person may open per hour (a typo-and-retry is fine; a script is not). */
    public const CREATE_PER_HOUR = 20;

    public function __construct(
        private readonly AccessControl $access,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * The features this actor may switch on or off for somebody at the branch: all their permissions must be held at that branch, and so
     * must those of the features they need (ticking a feature also ticks what it needs).
     *
     * @return list<string>
     */
    public function grantable(User $actor, string $branchId): array
    {
        if (! $this->access->allows($actor, 'employees.assign', $branchId)) {
            return [];
        }
        $needs = BranchFeatureCatalog::needsMap();
        $ok = [];
        foreach (BranchFeatureCatalog::keyMap() as $feature => $keys) {
            if ($this->holdsAll($actor, $keys, $branchId)) {
                $ok[$feature] = true;
            }
        }
        do {
            $changed = false;
            foreach (array_keys($ok) as $feature) {
                foreach ($needs[$feature] as $need) {
                    if (! isset($ok[$need])) {
                        unset($ok[$feature]);
                        $changed = true;
                        break;
                    }
                }
            }
        } while ($changed);

        return array_values(array_filter(BranchFeatureCatalog::ids(), fn (string $id) => isset($ok[$id])));
    }

    /**
     * Permission keys the employee holds at the branch from any role (the personal checklist role or shared roles).
     *
     * @return list<string>
     */
    public function permissionKeysAt(User $employee, string $branchId): array
    {
        /** @var list<string> $keys */
        $keys = DB::table('user_roles as ur')
            ->join('role_permissions as rp', 'rp.role_id', '=', 'ur.role_id')
            ->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->where('ur.user_id', $employee->id)->where('ur.branch_id', $branchId)
            ->distinct()->pluck('p.key')->all();
        sort($keys);

        return $keys;
    }

    /** The checklist as the person has it now at that branch. @return list<string> feature ids */
    public function featuresOf(User $employee, string $branchId): array
    {
        return BranchFeatureCatalog::featuresFor($this->permissionKeysAt($employee, $branchId));
    }

    /**
     * Every feature an employee has anywhere (their active branch assignments, plus company-wide roles), for the employees list.
     * One query for the whole page; a super admin has everything.
     *
     * @param  iterable<User>  $employees
     * @return array<string, list<string>> user id => feature ids
     */
    public function featuresForList(iterable $employees): array
    {
        $ids = [];
        $out = [];
        foreach ($employees as $employee) {
            if ($employee->isSuperAdmin()) {
                $out[$employee->id] = BranchFeatureCatalog::ids();
            } else {
                $ids[] = $employee->id;
            }
        }
        if ($ids === []) {
            return $out;
        }
        $rows = DB::table('user_roles as ur')
            ->join('role_permissions as rp', 'rp.role_id', '=', 'ur.role_id')
            ->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->leftJoin('branch_employees as be', fn ($j) => $j->on('be.user_id', '=', 'ur.user_id')->on('be.branch_id', '=', 'ur.branch_id'))
            ->whereIn('ur.user_id', $ids)
            ->where(fn ($q) => $q->whereNull('ur.branch_id')->orWhere('be.is_active', true))
            ->get(['ur.user_id', 'p.key']);
        $keys = [];
        foreach ($rows as $row) {
            $keys[$row->user_id][$row->key] = true;
        }
        foreach ($ids as $id) {
            $out[$id] = BranchFeatureCatalog::featuresFor(array_keys($keys[$id] ?? []));
        }

        return $out;
    }

    /**
     * Opens a branch employee: the staff account, their place at the branch and the personal role holding the ticked features, in one go.
     * The password is temporary: the person must choose their own at first sign-in.
     *
     * @param  array<string, mixed>  $profile  name, email, phone (already E.164), locale, job_title and the temporary password
     * @param  array<int, string>  $featureIds
     */
    public function create(User $actor, Branch $branch, array $profile, array $featureIds): User
    {
        $this->assertCan($actor, 'employees.create', $branch->id);
        $this->assertCan($actor, 'employees.assign', $branch->id);
        if (empty($profile['email']) && empty($profile['phone'])) {
            throw $this->fail('contact_required', 422);
        }
        $this->throttleCreation($actor);
        $features = $this->resolveFeatures($actor, $branch->id, $featureIds, []);

        try {
            return DB::transaction(function () use ($actor, $branch, $profile, $features) {
                $user = User::create([
                    'type' => UserType::Staff,
                    'status' => 'active',
                    'name' => $profile['name'],
                    'email' => $profile['email'] ?? null,
                    'phone' => $profile['phone'] ?? null,
                    'job_title' => $profile['job_title'] ?? null,
                    'password' => $profile['password'],
                    'must_change_password' => true,
                    'locale' => $profile['locale'] ?? 'en',
                ]);
                BranchEmployee::create([
                    'branch_id' => $branch->id, 'user_id' => $user->id, 'job_title' => $profile['job_title'] ?? null,
                    'is_active' => true, 'is_delivery_driver' => false, 'assigned_by' => $actor->id,
                ]);
                $keys = $this->grant($actor, $user, $branch, $features);
                $this->audit->log('employee.created', $user, [], $user->only(['name', 'email', 'phone', 'job_title']),
                    ['branch_id' => $branch->id, 'sign_in' => 'temporary_password'], branchId: $branch->id, actor: $actor);
                $this->audit->log('employee.access_changed', $user, ['permissions' => [], 'features' => []],
                    ['permissions' => $keys, 'features' => $features], ['branch_id' => $branch->id, 'preset' => BranchFeatureCatalog::presetFor($features)],
                    branchId: $branch->id, actor: $actor);

                return $user;
            });
        } catch (UniqueConstraintViolationException) {
            // Two people adding the same e-mail or number at once: the loser gets the same answer as a sequential duplicate.
            throw $this->fail('contact_taken', 409);
        }
    }

    /**
     * Replaces what the employee may use at this branch with the ticked features (their other roles at the branch are replaced by it).
     *
     * @param  array<int, string>  $featureIds
     */
    public function setFeatures(User $actor, User $employee, Branch $branch, array $featureIds): void
    {
        if ($actor->is($employee)) {
            throw DomainException::make('cannot_grant_beyond_authority', 403);
        }
        $this->assertCan($actor, 'employees.assign', $branch->id);
        $this->assertBranchEmployee($employee, $branch);
        $before = $this->permissionKeysAt($employee, $branch->id);
        $featuresBefore = BranchFeatureCatalog::featuresFor($before);
        $features = $this->resolveFeatures($actor, $branch->id, $featureIds, $before);

        DB::transaction(function () use ($actor, $employee, $branch, $features, $before, $featuresBefore) {
            $replaced = Role::whereIn('id', UserRole::where('user_id', $employee->id)->where('branch_id', $branch->id)->pluck('role_id'))
                ->whereNull('personal_user_id')->pluck('key')->all();
            $after = $this->grant($actor, $employee, $branch, $features);
            if ($before === $after && $replaced === []) {
                return;
            }
            $this->audit->log('employee.access_changed', $employee, ['permissions' => $before, 'features' => $featuresBefore],
                ['permissions' => $after, 'features' => $features],
                ['branch_id' => $branch->id, 'preset' => BranchFeatureCatalog::presetFor($features), 'replaced_roles' => $replaced],
                branchId: $branch->id, actor: $actor);
        });
    }

    /**
     * Validates a ticked list against what the actor may change and returns the final list (with the features they need added).
     *
     * @param  array<int, mixed>  $requested
     * @param  list<string>  $currentKeys  permissions the employee holds now at the branch (empty for a new account)
     * @return list<string>
     */
    private function resolveFeatures(User $actor, string $branchId, array $requested, array $currentKeys): array
    {
        foreach ($requested as $id) {
            if (! is_string($id) || ! BranchFeatureCatalog::exists($id)) {
                throw $this->fail('feature_unknown', 422);
            }
        }
        $features = BranchFeatureCatalog::withNeeds($requested);
        if ($features === []) {
            throw $this->fail('feature_required', 422);
        }
        // A wall display account is a screen, not a person: the live board and nothing else. Mixed with anything it would be an ordinary account in disguise.
        if (in_array('wall_display', $features, true) && count($features) > 1) {
            throw $this->fail('wall_display_alone', 422);
        }
        $after = BranchFeatureCatalog::permissionKeys($features);
        // Same rule as the role editor: every permission that changes, on or off, is one the actor holds at this branch.
        $changed = array_merge(array_diff($after, $currentKeys), array_diff($currentKeys, $after));
        if (! $this->holdsAll($actor, $changed, $branchId)) {
            throw DomainException::make('cannot_grant_beyond_authority', 403);
        }

        return $features;
    }

    /**
     * Writes the checklist onto the employee's personal role at the branch and makes it their only role there.
     *
     * @param  list<string>  $features
     * @return list<string> the permission keys now held at the branch
     */
    private function grant(User $actor, User $employee, Branch $branch, array $features): array
    {
        $keys = BranchFeatureCatalog::permissionKeys($features);
        sort($keys);
        $role = Role::firstOrCreate(
            ['personal_user_id' => $employee->id, 'personal_branch_id' => $branch->id],
            ['key' => 'personal_'.Str::lower((string) Str::ulid()), 'name_en' => 'Personal access', 'name_ar' => 'صلاحيات شخصية', 'scope' => 'branch', 'is_system' => false],
        );
        $role->permissions()->sync(Permission::whereIn('key', $keys)->pluck('id'));
        UserRole::where('user_id', $employee->id)->where('branch_id', $branch->id)->where('role_id', '!=', $role->id)->delete();
        UserRole::firstOrCreate(
            ['user_id' => $employee->id, 'role_id' => $role->id, 'branch_id' => $branch->id],
            ['assigned_by' => $actor->id],
        );
        $this->access->forget($employee);

        return $keys;
    }

    /** Only a plain branch employee: staff, not a super admin, no company-wide role, and working at this branch. */
    private function assertBranchEmployee(User $employee, Branch $branch): void
    {
        if (! $employee->isStaff() || $employee->isSuperAdmin() || UserRole::where('user_id', $employee->id)->whereNull('branch_id')->exists()) {
            throw DomainException::make('cannot_grant_beyond_authority', 403);
        }
        if (! BranchEmployee::where('user_id', $employee->id)->where('branch_id', $branch->id)->exists()) {
            throw DomainException::make('branch_forbidden', 403);
        }
    }

    /** @param  list<string>  $keys */
    private function holdsAll(User $actor, array $keys, string $branchId): bool
    {
        foreach ($keys as $key) {
            if (! $this->access->allows($actor, $key, $branchId)) {
                return false;
            }
        }

        return true;
    }

    private function assertCan(User $actor, string $permission, ?string $branchId): void
    {
        $ok = $branchId === null ? $this->access->allows($actor, $permission) : $this->access->allows($actor, $permission, $branchId);
        if (! $ok) {
            throw DomainException::make('branch_forbidden', 403);
        }
    }

    /** An error of this feature, worded in lang/{en,ar}/branch_access.php (the shared errors.* file holds the generic ones). */
    private function fail(string $code, int $status): DomainException
    {
        return new DomainException($code, __('branch_access.error_'.$code), $status);
    }

    private function throttleCreation(User $actor): void
    {
        $key = 'branch-employee-create:'.$actor->id;
        if (RateLimiter::tooManyAttempts($key, self::CREATE_PER_HOUR)) {
            throw DomainException::make('too_many_requests', 429);
        }
        RateLimiter::hit($key, 3600);
    }
}
