<?php

namespace App\Livewire\Admin\Organization;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Branches\Models\Branch;
use App\Domain\Branches\Models\BranchEmployee;
use App\Domain\Branches\Services\EmployeeService;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessControl;
use App\Domain\Identity\Services\TokenService;
use App\Livewire\Admin\Concerns\InteractsWithAdmin;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Staff account: profile, lifecycle status, branch assignments (branch roles), company-wide roles and password reset.
 * Role/assignment changes go through EmployeeService, which enforces authority rules (no self-elevation,
 * cannot grant beyond what the actor holds, super admin only by super admin).
 */
class EmployeeEditor extends Component
{
    use InteractsWithAdmin;

    public ?User $employee = null;

    #[Url]
    public string $tab = 'profile';

    public array $form = [];

    public array $assign = ['branch_id' => '', 'roles' => [], 'job_title' => '', 'is_delivery_driver' => false];

    public array $globalRoles = [];

    public string $newPassword = '';

    public bool $showPassword = false;

    public function mount(?User $employee = null): void
    {
        if ($employee?->exists) {
            abort_unless($employee->isStaff(), 404);
            abort_unless($this->canView($employee), 403);
            $this->employee = $employee;
            $this->form = $employee->only(['name', 'email', 'phone', 'job_title', 'locale']);
            $this->globalRoles = $employee->roleAssignments()->whereNull('branch_id')->with('role')->get()->pluck('role.key')->all();
        } else {
            abort_unless($this->can('employees.create'), 403);
            $this->form = ['name' => '', 'email' => '', 'phone' => '', 'job_title' => '', 'locale' => 'en', 'password' => ''];
        }
    }

    /** Global viewers see every staff member; branch viewers only staff assigned to one of their branches. */
    private function canView(User $employee): bool
    {
        $acl = app(AccessControl::class);
        if ($acl->allowsGlobally(auth()->user(), 'employees.view') || $employee->is(auth()->user())) {
            return true;
        }
        $scope = $acl->scope(auth()->user(), 'employees.view');
        if (BranchEmployee::where('user_id', $employee->id)->whereIn('branch_id', $scope->branchIds)->exists()) {
            return true;
        }

        // Newly created staff with no branch or role yet are visible to anyone who can assign them somewhere.
        return $acl->allows(auth()->user(), 'employees.assign') && self::unassigned($employee);
    }

    private static function unassigned(User $employee): bool
    {
        return ! BranchEmployee::where('user_id', $employee->id)->exists() && ! $employee->roleAssignments()->exists();
    }

    /**
     * Whether the actor may change this person's profile, status or password. A branch-level manager may only manage
     * staff who work exclusively within branches they manage and who hold no company-wide role.
     */
    private function canManage(): bool
    {
        $actor = auth()->user();
        $e = $this->employee;
        if (! $e || $e->is($actor) || ($e->isSuperAdmin() && ! $actor->isSuperAdmin())) {
            return false;
        }
        $acl = app(AccessControl::class);
        if ($acl->allowsGlobally($actor, 'employees.edit')) {
            return true;
        }
        if ($e->roleAssignments()->whereNull('branch_id')->exists()) {
            return false;
        }
        $branchIds = BranchEmployee::where('user_id', $e->id)->pluck('branch_id');
        if ($branchIds->isEmpty()) {
            return $acl->allows($actor, 'employees.edit') && self::unassigned($e);
        }

        return $branchIds->every(fn ($id) => $acl->allows($actor, 'employees.edit', $id));
    }

    public function generatePassword(): void
    {
        $pwd = Str::password(16, symbols: false);
        // Guarantee policy: upper, lower and digit present.
        $pwd = 'Ab9'.substr($pwd, 3);
        if ($this->employee) {
            $this->newPassword = $pwd;
        } else {
            $this->form['password'] = $pwd;
        }
        $this->showPassword = true;
    }

    /** Stored form is lower-case e-mail and a space-free phone, so uniqueness must be checked on that same form. */
    private function normaliseContact(): void
    {
        $this->form['email'] = strtolower(trim((string) ($this->form['email'] ?? '')));
        $this->form['phone'] = preg_replace('/\s+/', '', (string) ($this->form['phone'] ?? ''));
    }

    public function create(EmployeeService $svc)
    {
        abort_unless($this->can('employees.create'), 403);
        $this->normaliseContact();
        $data = $this->validate([
            'form.name' => 'required|string|max:120',
            'form.email' => 'required|email|max:191|unique:users,email',
            'form.phone' => ['nullable', 'string', 'max:20', 'regex:/^\+?[0-9 ]{8,20}$/', 'unique:users,phone'],
            'form.job_title' => 'nullable|string|max:120',
            'form.locale' => 'required|in:en,ar',
            'form.password' => ['required', Password::min(12)->mixedCase()->numbers()],
        ], [], $this->attributeNames())['form'];
        $data['phone'] = $data['phone'] ? preg_replace('/\s+/', '', $data['phone']) : null;

        $user = $this->attempt(fn () => $svc->create(auth()->user(), $data));
        if ($user instanceof User) {
            session()->flash('toast', ['type' => 'success', 'message' => __('org_admin.employee_created')]);

            return $this->redirectRoute('admin.employees.edit', ['employee' => $user, 'tab' => 'access'], navigate: true);
        }
    }

    public function saveProfile(AuditLogger $audit): void
    {
        abort_unless($this->canManage(), 403);
        $this->normaliseContact();
        $data = $this->validate([
            'form.name' => 'required|string|max:120',
            'form.email' => ['required', 'email', 'max:191', Rule::unique('users', 'email')->ignore($this->employee->id)],
            'form.phone' => ['nullable', 'string', 'max:20', 'regex:/^\+?[0-9 ]{8,20}$/', Rule::unique('users', 'phone')->ignore($this->employee->id)],
            'form.job_title' => 'nullable|string|max:120',
            'form.locale' => 'required|in:en,ar',
        ], [], $this->attributeNames())['form'];
        $data['email'] = strtolower($data['email']);
        $data['phone'] = $data['phone'] ? preg_replace('/\s+/', '', $data['phone']) : null;
        $this->employee->fill($data);
        $dirty = $this->employee->getDirty();
        $old = array_intersect_key($this->employee->getOriginal(), $dirty);
        $this->employee->save();
        if ($dirty) {
            $audit->log('employee.updated', $this->employee, $old, $dirty);
        }
        $this->toast(__('admin.saved'));
    }

    public function setStatus(string $status, EmployeeService $svc): void
    {
        abort_unless(in_array($status, ['active', 'suspended', 'deactivated'], true), 422);
        abort_unless($this->canManage(), 403);
        $this->attempt(fn () => $svc->setStatus(auth()->user(), $this->employee, $status), __('org_admin.status_changed'));
        $this->employee->refresh();
    }

    public function resetPassword(TokenService $tokens, AuditLogger $audit): void
    {
        abort_unless($this->canManage(), 403);
        $this->validate(['newPassword' => ['required', Password::min(12)->mixedCase()->numbers()]], [], ['newPassword' => __('admin.new_password')]);
        $this->employee->forceFill(['password' => $this->newPassword, 'remember_token' => Str::random(60)])->save();
        $tokens->revokeAll($this->employee, 'password_reset');
        $audit->log('employee.password_reset', $this->employee);
        $this->reset('newPassword', 'showPassword');
        $this->toast(__('org_admin.password_reset_done'));
    }

    public function editAssignment(string $branchId): void
    {
        $a = BranchEmployee::where('user_id', $this->employee->id)->where('branch_id', $branchId)->firstOrFail();
        $this->assign = [
            'branch_id' => $branchId,
            'roles' => $this->employee->roleAssignments()->where('branch_id', $branchId)->with('role')->get()->pluck('role.key')->all(),
            'job_title' => (string) $a->job_title,
            'is_delivery_driver' => (bool) $a->is_delivery_driver,
        ];
    }

    public function saveAssignment(EmployeeService $svc): void
    {
        $data = $this->validate([
            'assign.branch_id' => 'required|uuid|exists:branches,id',
            'assign.roles' => 'array', 'assign.roles.*' => 'string|exists:roles,key',
            'assign.job_title' => 'nullable|string|max:120',
            'assign.is_delivery_driver' => 'boolean',
        ], [], ['assign.branch_id' => __('admin.branch')])['assign'];
        // Branch assignments may only carry branch-scoped roles.
        if (Role::whereIn('key', $data['roles'])->where('scope', '!=', 'branch')->exists()) {
            $this->addError('assign.roles', __('org_admin.branch_roles_only'));

            return;
        }
        $branch = Branch::findOrFail($data['branch_id']);
        $ok = $this->attempt(fn () => $svc->assignToBranch(auth()->user(), $this->employee, $branch, $data['roles'], $data['job_title'] ?: null, (bool) $data['is_delivery_driver']), __('org_admin.assignment_saved'));
        if ($ok) {
            $this->reset('assign');
        }
    }

    public function toggleAssignment(string $branchId, EmployeeService $svc): void
    {
        $a = BranchEmployee::with('user')->where('user_id', $this->employee->id)->where('branch_id', $branchId)->firstOrFail();
        $this->attempt(fn () => $svc->setBranchAssignmentActive(auth()->user(), $a, ! $a->is_active), __('admin.saved'));
    }

    public function removeAssignment(string $branchId, EmployeeService $svc): void
    {
        $branch = Branch::findOrFail($branchId);
        $this->attempt(fn () => $svc->removeFromBranch(auth()->user(), $this->employee, $branch), __('org_admin.assignment_removed'));
    }

    public function saveGlobalRoles(EmployeeService $svc): void
    {
        $data = $this->validate(['globalRoles' => 'array', 'globalRoles.*' => 'string|exists:roles,key'])['globalRoles'];
        $this->attempt(fn () => $svc->setGlobalRoles(auth()->user(), $this->employee, array_values($data)), __('org_admin.global_roles_saved'));
    }

    private function attributeNames(): array
    {
        return ['form.name' => __('admin.name'), 'form.email' => __('admin.email'), 'form.phone' => __('org_admin.phone'),
            'form.job_title' => __('org_admin.job_title'), 'form.password' => __('admin.password')];
    }

    public function render()
    {
        $acl = app(AccessControl::class);
        $actor = auth()->user();
        $data = ['isSelf' => $this->employee?->is($actor) ?? false];
        if ($this->employee) {
            $this->employee->load(['branchAssignments.branch:id,code,name_en,name_ar', 'roleAssignments.role']);
            $assignable = Branch::query()->orderBy('sort_order')->get(['id', 'code', 'name_en', 'name_ar'])
                ->filter(fn ($b) => $acl->allows($actor, 'employees.assign', $b->id))->values();
            $data += [
                'canManage' => $this->canManage(),
                'assignableBranches' => $assignable,
                'branchRoles' => Role::where('scope', 'branch')->orderBy('name_en')->get(),
                'globalRoleOptions' => Role::where('scope', 'global')->when(! $actor->isSuperAdmin(), fn ($q) => $q->where('key', '!=', Role::SUPER_ADMIN))->orderBy('name_en')->get(),
                'canAssignGlobal' => $acl->allowsGlobally($actor, 'roles.assign') && ! $data['isSelf'],
                'rolesByBranch' => $this->employee->roleAssignments->whereNotNull('branch_id')->groupBy('branch_id'),
                'recentActivity' => AuditLog::with('actor:id,name')->where('auditable_type', 'User')->where('auditable_id', $this->employee->id)->latest('id')->limit(8)->get(),
            ];
        }

        return view('livewire.admin.organization.employee-editor', $data)->title($this->employee?->name ?? __('org_admin.new_employee'));
    }
}
