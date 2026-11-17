<?php

namespace App\Livewire\Admin\Organization;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Branches\Models\Branch;
use App\Domain\Branches\Models\BranchEmployee;
use App\Domain\Branches\Services\BranchEmployeeService;
use App\Domain\Branches\Services\EmployeeService;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessControl;
use App\Domain\Identity\Services\TokenService;
use App\Domain\Identity\Support\BranchFeatureCatalog;
use App\Livewire\Admin\Concerns\InteractsWithAdmin;
use App\Support\Phone;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Staff account. A NEW account is a branch employee: profile, the one branch they work at, a temporary password they must change at
 * first sign-in, and a plain-words checklist of the features they may use (stored as a personal role, see BranchEmployeeService).
 * An existing account has the profile, the same checklist for each of their branches, the branch assignments (roles), company-wide roles,
 * and the security tools. Every change goes through a service that enforces authority (no self-elevation, nothing beyond what the actor
 * holds at that branch, company-wide staff and super admins only by their own authority).
 */
class EmployeeEditor extends Component
{
    use InteractsWithAdmin;

    public ?User $employee = null;

    /** Shown in a side window beside the employees list (the layout is single-column; saves tell the list; nothing navigates away). */
    #[Locked]
    public bool $embedded = false;

    #[Url]
    public string $tab = 'profile';

    public array $form = [];

    /** New account: 'branch' (the normal case) or 'company' (company-wide staff such as an accountant; only for those who may grant company-wide roles). */
    public string $kind = 'branch';

    /** New account: the branch they work at. Existing account: the branch whose checklist is shown. */
    public string $branchId = '';

    /** @var list<string> the ticked features (BranchFeatureCatalog ids) */
    public array $features = [];

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
            $this->branchId = (string) ($this->accessBranches()->first()?->id ?? '');
            $this->loadFeatures();
        } else {
            abort_unless($this->can('employees.create'), 403);
            $this->form = ['name' => '', 'email' => '', 'phone' => '', 'job_title' => '', 'locale' => 'en', 'password' => ''];
            $branches = $this->assignableBranches();
            $this->branchId = $branches->count() === 1 ? (string) $branches->first()->id : '';
        }
    }

    /** Global viewers see every staff member; branch viewers only staff assigned to one of their branches. The list's side window asks the same question. */
    public static function viewableBy(User $actor, User $employee): bool
    {
        $acl = app(AccessControl::class);
        if (! $employee->isStaff()) {
            return false;
        }
        if ($acl->allowsGlobally($actor, 'employees.view') || $employee->is($actor)) {
            return true;
        }
        $scope = $acl->scope($actor, 'employees.view');
        if (BranchEmployee::where('user_id', $employee->id)->whereIn('branch_id', $scope->branchIds)->exists()) {
            return true;
        }

        // Newly created staff with no branch or role yet are visible to anyone who can assign them somewhere.
        return $acl->allows($actor, 'employees.assign') && self::unassigned($employee);
    }

    private function canView(User $employee): bool
    {
        return self::viewableBy(auth()->user(), $employee);
    }

    private static function unassigned(User $employee): bool
    {
        return ! BranchEmployee::where('user_id', $employee->id)->exists() && ! $employee->roleAssignments()->exists();
    }

    /** Whether the actor may change this person's profile, status or password (one rule, EmployeeService::canManage). */
    private function canManage(): bool
    {
        $e = $this->employee;
        if (! $e) {
            return false;
        }

        return app(EmployeeService::class)->canManage(
            auth()->user(),
            $e,
            $e->roleAssignments()->whereNull('branch_id')->exists(),
            BranchEmployee::where('user_id', $e->id)->pluck('branch_id')->all(),
        );
    }

    /** Branches the actor may put somebody to work at (employees.assign there). @return Collection<int, Branch> */
    private function assignableBranches(): Collection
    {
        $acl = app(AccessControl::class);
        $actor = auth()->user();

        return Branch::query()->orderBy('sort_order')->get(['id', 'code', 'name_en', 'name_ar'])
            ->filter(fn (Branch $b) => $acl->allows($actor, 'employees.assign', $b->id))->values();
    }

    /**
     * Existing account: the branches whose checklist the actor may edit (the employee works there and the actor assigns there).
     * Empty for yourself, a super admin or anyone with a company-wide role: they are not branch employees.
     *
     * @return Collection<int, Branch>
     */
    private function accessBranches(): Collection
    {
        $e = $this->employee;
        $actor = auth()->user();
        if (! $e || $e->is($actor) || $e->isSuperAdmin() || $e->roleAssignments()->whereNull('branch_id')->exists()) {
            return collect();
        }
        $mine = BranchEmployee::where('user_id', $e->id)->pluck('branch_id')->all();

        return $this->assignableBranches()->filter(fn (Branch $b) => in_array($b->id, $mine, true))->values();
    }

    private function loadFeatures(): void
    {
        $this->features = $this->employee && $this->branchId !== ''
            ? app(BranchEmployeeService::class)->featuresOf($this->employee, $this->branchId)
            : [];
    }

    /** Another branch's checklist, or (new account) another branch: the choice must be one the actor may use. */
    public function updatedBranchId(): void
    {
        $actor = auth()->user();
        if ($this->employee) {
            if (! $this->accessBranches()->contains('id', $this->branchId)) {
                $this->branchId = (string) ($this->accessBranches()->first()?->id ?? '');
            }
            $this->loadFeatures();

            return;
        }
        if ($this->branchId !== '' && ! $this->assignableBranches()->contains('id', $this->branchId)) {
            $this->branchId = '';
        }
        // Keep only what the actor may hand out at the chosen branch.
        $allowed = $this->branchId === '' ? [] : app(BranchEmployeeService::class)->grantable($actor, $this->branchId);
        $this->features = array_values(array_intersect($this->features, $allowed));
    }

    public function useCompanyWide(): void
    {
        abort_unless($this->employee === null && $this->canGlobally('roles.assign'), 403);
        $this->kind = 'company';
    }

    public function useBranch(): void
    {
        $this->kind = 'branch';
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

    /** Stored form is lower-case e-mail and an E.164 phone, so uniqueness must be checked on that same form. */
    private function normaliseContact(): void
    {
        $this->form['email'] = strtolower(trim((string) ($this->form['email'] ?? '')));
        $phone = trim((string) ($this->form['phone'] ?? ''));
        $this->form['phone'] = $phone === '' ? '' : (Phone::normalize($phone) ?? $phone);
    }

    /** @return array<string, mixed> */
    private function contactRules(?string $ignoreId = null): array
    {
        $phoneOk = function (string $attribute, mixed $value, \Closure $fail): void {
            if (is_string($value) && $value !== '' && ! Phone::isValid($value)) {
                $fail(__('branch_access.phone_invalid'));
            }
        };
        $email = Rule::unique('users', 'email');
        $phone = Rule::unique('users', 'phone');
        if ($ignoreId) {
            $email->ignore($ignoreId);
            $phone->ignore($ignoreId);
        }

        return [
            'form.name' => 'required|string|max:120',
            'form.email' => ['nullable', 'required_without:form.phone', 'email', 'max:191', $email],
            'form.phone' => ['nullable', 'required_without:form.email', 'string', 'max:20', $phoneOk, $phone],
            'form.job_title' => 'nullable|string|max:120',
            'form.locale' => 'required|in:en,ar',
        ];
    }

    public function create(EmployeeService $svc, BranchEmployeeService $branchSvc)
    {
        abort_unless($this->can('employees.create'), 403);
        $this->normaliseContact();
        $company = $this->kind === 'company';
        abort_if($company && ! $this->canGlobally('roles.assign'), 403);

        $rules = $this->contactRules() + ['form.password' => ['required', Password::min(12)->mixedCase()->numbers()]];
        if (! $company) {
            $rules += [
                'branchId' => ['required', 'uuid', 'exists:branches,id'],
                'features' => ['required', 'array', 'min:1'],
                'features.*' => ['string', Rule::in(BranchFeatureCatalog::ids())],
            ];
        }
        $data = $this->validate($rules, [
            'features.required' => __('branch_access.features_required'),
            'features.min' => __('branch_access.features_required'),
            'form.email.required_without' => __('branch_access.contact_required'),
            'form.phone.required_without' => __('branch_access.contact_required'),
        ], $this->attributeNames());
        $form = $data['form'];
        $form['email'] = $form['email'] ? strtolower($form['email']) : null;
        $form['phone'] = $form['phone'] ?: null;

        $user = $company
            ? $this->attempt(fn () => $svc->create(auth()->user(), $form))
            : $this->attempt(fn () => $branchSvc->create(auth()->user(), Branch::findOrFail($data['branchId']), $form, $data['features']));
        if (! $user instanceof User) {
            return null;
        }
        $message = $company ? __('org_admin.employee_created') : __('branch_access.employee_added');
        if ($this->embedded) {
            $this->dispatch('toast', type: 'success', message: $message);

            // The list closes this window and opens the new employee's, straight on the access tab.
            $this->dispatch('record-created', id: $user->id, tab: 'access');

            return null;
        }
        session()->flash('toast', ['type' => 'success', 'message' => $message]);

        return $this->redirectRoute('admin.employees.edit', ['employee' => $user, 'tab' => 'access'], navigate: true);
    }

    public function saveProfile(AuditLogger $audit): void
    {
        abort_unless($this->canManage(), 403);
        $this->normaliseContact();
        $data = $this->validate($this->contactRules($this->employee->id), [
            'form.email.required_without' => __('branch_access.contact_required'),
            'form.phone.required_without' => __('branch_access.contact_required'),
        ], $this->attributeNames())['form'];
        $data['email'] = $data['email'] ? strtolower($data['email']) : null;
        $data['phone'] = $data['phone'] ?: null;
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
        // The new password is temporary: the person must choose their own at the next sign-in, and every device they were signed in on is signed out.
        $this->employee->forceFill(['password' => $this->newPassword, 'must_change_password' => true, 'remember_token' => Str::random(60)])->save();
        $tokens->revokeAll($this->employee, 'password_reset');
        $tokens->forgetWebSessions($this->employee);
        $audit->log('employee.password_reset', $this->employee);
        $this->reset('newPassword', 'showPassword');
        $this->toast(__('org_admin.password_reset_done'));
    }

    /** Turns two-factor off for this employee (a lost or replaced phone); they can enrol again from their profile. Written to the audit log. */
    public function turnOffTwoFactor(AuditLogger $audit): void
    {
        abort_unless($this->canManage(), 403);
        if (! $this->employee->hasTwoFactorEnabled()) {
            $this->toast(__('org_admin.two_factor_already_off'));

            return;
        }
        $this->employee->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->save();
        $audit->log('employee.two_factor_turned_off', $this->employee);
        $this->employee->refresh();
        $this->toast(__('org_admin.two_factor_turned_off'));
    }

    /** Saves the ticked features for the chosen branch. The service re-checks every permission against what the actor holds there. */
    public function saveFeatures(BranchEmployeeService $svc): void
    {
        abort_unless($this->employee && $this->accessBranches()->contains('id', $this->branchId), 403);
        $data = $this->validate([
            'features' => ['required', 'array', 'min:1'],
            'features.*' => ['string', Rule::in(BranchFeatureCatalog::ids())],
        ], ['features.required' => __('branch_access.features_required'), 'features.min' => __('branch_access.features_required')]);
        $branch = Branch::findOrFail($this->branchId);
        $ok = $this->attempt(fn () => $svc->setFeatures(auth()->user(), $this->employee, $branch, $data['features']), __('branch_access.saved'));
        $this->employee->refresh();
        if ($ok) {
            $this->loadFeatures();
        }
    }

    public function editAssignment(string $branchId): void
    {
        $a = BranchEmployee::where('user_id', $this->employee->id)->where('branch_id', $branchId)->firstOrFail();
        $this->assign = [
            'branch_id' => $branchId,
            'roles' => $this->employee->roleAssignments()->where('branch_id', $branchId)->with('role')->get()
                ->reject(fn ($ur) => $ur->role->isPersonal())->pluck('role.key')->values()->all(),
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
        // Branch assignments may only carry shared, branch-scoped roles (a personal checklist is saved from "Feature access").
        if (Role::whereIn('key', $data['roles'])->where(fn ($q) => $q->where('scope', '!=', 'branch')->orWhereNotNull('personal_user_id'))->exists()) {
            $this->addError('assign.roles', __('org_admin.branch_roles_only'));

            return;
        }
        $branch = Branch::findOrFail($data['branch_id']);
        $ok = $this->attempt(fn () => $svc->assignToBranch(auth()->user(), $this->employee, $branch, $data['roles'], $data['job_title'] ?: null, (bool) $data['is_delivery_driver']), __('org_admin.assignment_saved'));
        if ($ok) {
            $this->reset('assign');
            $this->employee->refresh();
            $this->loadFeatures();
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
        if ($this->branchId === $branchId) {
            $this->branchId = (string) ($this->accessBranches()->first()?->id ?? '');
        }
        $this->loadFeatures();
    }

    public function saveGlobalRoles(EmployeeService $svc): void
    {
        $data = $this->validate(['globalRoles' => 'array', 'globalRoles.*' => 'string|exists:roles,key'])['globalRoles'];
        $this->attempt(fn () => $svc->setGlobalRoles(auth()->user(), $this->employee, array_values($data)), __('org_admin.global_roles_saved'));
    }

    private function attributeNames(): array
    {
        return ['form.name' => __('admin.name'), 'form.email' => __('admin.email'), 'form.phone' => __('org_admin.phone'),
            'form.job_title' => __('org_admin.job_title'), 'form.password' => __('admin.password'), 'branchId' => __('admin.branch'),
            'features' => __('branch_access.features')];
    }

    /**
     * What the feature picker needs: every group with its texts, the dependency map, the presets, and which features the actor may switch.
     *
     * @param  list<string>  $grantable
     * @return array<string, mixed>
     */
    private function pickerData(array $grantable): array
    {
        $needs = BranchFeatureCatalog::needsMap();
        $groups = [];
        foreach (BranchFeatureCatalog::groups() as $group => $ids) {
            $groups[] = [
                'id' => $group,
                'label' => BranchFeatureCatalog::groupLabel($group),
                'features' => array_map(fn (string $id) => [
                    'id' => $id, 'label' => BranchFeatureCatalog::label($id), 'hint' => BranchFeatureCatalog::hint($id),
                    'needs' => array_map(fn (string $n) => BranchFeatureCatalog::label($n), $needs[$id]),
                ], $ids),
            ];
        }
        $presets = [];
        foreach (BranchFeatureCatalog::presets() as $id => $set) {
            $presets[] = ['id' => $id, 'label' => BranchFeatureCatalog::presetLabel($id), 'hint' => __('branch_access.preset_'.$id.'_hint'), 'features' => BranchFeatureCatalog::withNeeds($set)];
        }

        return ['groups' => $groups, 'presets' => $presets, 'needs' => $needs, 'allIds' => BranchFeatureCatalog::ids(), 'grantable' => $grantable];
    }

    public function render()
    {
        $acl = app(AccessControl::class);
        $branchSvc = app(BranchEmployeeService::class);
        $actor = auth()->user();
        $data = ['isSelf' => $this->employee?->is($actor) ?? false];
        if ($this->employee) {
            $this->employee->load(['branchAssignments.branch:id,code,name_en,name_ar', 'roleAssignments.role']);
            $assignable = $this->assignableBranches();
            $accessBranches = $this->accessBranches();
            $canEditFeatures = $accessBranches->contains('id', $this->branchId);
            $grantable = $canEditFeatures ? $branchSvc->grantable($actor, $this->branchId) : [];
            $shared = $this->employee->roleAssignments->where('branch_id', $this->branchId)->filter(fn ($ur) => ! $ur->role->isPersonal())->map(fn ($ur) => $ur->role->localized('name'))->values();
            $data += [
                'canManage' => $this->canManage(),
                'assignableBranches' => $assignable,
                'accessBranches' => $accessBranches,
                'canEditFeatures' => $canEditFeatures,
                'picker' => $this->pickerData($grantable),
                'baseline' => $canEditFeatures ? $branchSvc->featuresOf($this->employee, $this->branchId) : [],
                'replacedRoles' => $shared,
                'readOnlyFeatures' => $canEditFeatures ? [] : ($branchSvc->featuresForList([$this->employee])[$this->employee->id] ?? []),
                'branchRoles' => Role::shared()->where('scope', 'branch')->orderBy('name_en')->get(),
                'globalRoleOptions' => Role::shared()->where('scope', 'global')->when(! $actor->isSuperAdmin(), fn ($q) => $q->where('key', '!=', Role::SUPER_ADMIN))->orderBy('name_en')->get(),
                'canAssignGlobal' => $acl->allowsGlobally($actor, 'roles.assign') && ! $data['isSelf'],
                'rolesByBranch' => $this->employee->roleAssignments->whereNotNull('branch_id')->groupBy('branch_id'),
                'recentActivity' => AuditLog::with('actor:id,name')->where('auditable_type', 'User')->where('auditable_id', $this->employee->id)->latest('id')->limit(8)->get(),
            ];
        } else {
            $branches = $this->assignableBranches();
            $data += [
                'newBranches' => $branches,
                'picker' => $this->pickerData($this->branchId !== '' ? $branchSvc->grantable($actor, $this->branchId) : []),
                'canCompanyWide' => $this->canGlobally('roles.assign'),
            ];
        }

        return view('livewire.admin.organization.employee-editor', $data)->title($this->employee?->name ?? __('org_admin.new_employee'));
    }
}
