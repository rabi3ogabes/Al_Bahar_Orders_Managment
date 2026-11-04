<?php

namespace App\Domain\Identity\Services;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Support\BranchScope;
use App\Domain\Identity\Support\PermissionRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Server-side RBAC with branch scoping.
 *
 * Rules:
 *  - Only active staff hold permissions; customers never do.
 *  - Super Admin: everything, every branch.
 *  - A GLOBAL role assigned company-wide (branch_id NULL) grants its permissions on every branch.
 *  - A BRANCH role assigned to branch X grants its permissions on X only, and only while the user's
 *    branch_employees assignment for X is active.
 *
 * Registered as a scoped singleton so the per-request memo never leaks between requests/workers.
 */
class AccessControl
{
    /** @var array<string, array<string, array{all: bool, branches: array<string, true>}>> */
    private array $grants = [];

    public function allows(?User $user, string $permission, ?string $branchId = null): bool
    {
        if (! $this->eligible($user)) {
            return false;
        }
        if ($user->isSuperAdmin()) {
            return true;
        }
        $grant = $this->grantsFor($user)[$permission] ?? null;
        if ($grant === null) {
            return false;
        }
        if ($branchId === null) {
            return true; // permitted somewhere (menu visibility / non-branch resources)
        }

        return $grant['all'] || isset($grant['branches'][$branchId]);
    }

    /** True only if the permission is granted company-wide (not merely on some branches). */
    public function allowsGlobally(?User $user, string $permission): bool
    {
        if (! $this->eligible($user)) {
            return false;
        }

        return $user->isSuperAdmin() || (($this->grantsFor($user)[$permission]['all'] ?? false) === true);
    }

    public function scope(?User $user, string $permission): BranchScope
    {
        if (! $this->eligible($user)) {
            return BranchScope::none();
        }
        if ($user->isSuperAdmin()) {
            return BranchScope::all();
        }
        $grant = $this->grantsFor($user)[$permission] ?? null;
        if ($grant === null) {
            return BranchScope::none();
        }

        return $grant['all'] ? BranchScope::all() : BranchScope::of(array_keys($grant['branches']));
    }

    /** @throws AuthorizationException */
    public function authorize(?User $user, string $permission, ?string $branchId = null): void
    {
        if (! $this->allows($user, $permission, $branchId)) {
            throw new AuthorizationException(__('errors.forbidden'));
        }
    }

    /** @return list<string> */
    public function permissionKeys(User $user): array
    {
        if ($user->isSuperAdmin()) {
            return PermissionRegistry::expand(['*']);
        }

        return array_keys($this->grantsFor($user));
    }

    public function forget(?User $user = null): void
    {
        if ($user === null) {
            $this->grants = [];
        } else {
            unset($this->grants[$user->getKey()]);
        }
    }

    private function eligible(?User $user): bool
    {
        return $user !== null && $user->isStaff() && $user->isActive();
    }

    private function grantsFor(User $user): array
    {
        $id = $user->getKey();
        if (isset($this->grants[$id])) {
            return $this->grants[$id];
        }

        $rows = DB::table('user_roles as ur')
            ->join('roles as r', 'r.id', '=', 'ur.role_id')
            ->join('role_permissions as rp', 'rp.role_id', '=', 'r.id')
            ->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->leftJoin('branch_employees as be', function ($join) {
                $join->on('be.user_id', '=', 'ur.user_id')->on('be.branch_id', '=', 'ur.branch_id');
            })
            ->leftJoin('branches as b', 'b.id', '=', 'ur.branch_id')
            ->where('ur.user_id', $id)
            ->where(function ($q) {
                // global role, company-wide
                $q->where(fn ($g) => $g->whereNull('ur.branch_id')->where('r.scope', 'global'))
                  // branch assignment with an active employment record
                    ->orWhere(fn ($b) => $b->whereNotNull('ur.branch_id')->where('be.is_active', true));
            })
            ->get(['p.key', 'ur.branch_id']);

        $grants = [];
        foreach ($rows as $row) {
            $grants[$row->key] ??= ['all' => false, 'branches' => []];
            if ($row->branch_id === null) {
                $grants[$row->key]['all'] = true;
            } else {
                $grants[$row->key]['branches'][$row->branch_id] = true;
            }
        }

        return $this->grants[$id] = $grants;
    }
}
