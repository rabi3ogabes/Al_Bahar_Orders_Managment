<?php

namespace App\Livewire\Admin\Organization;

use App\Domain\Branches\Services\BranchEmployeeService;
use App\Domain\Branches\Services\EmployeeService;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Support\BranchFeatureCatalog;
use App\Livewire\Admin\Concerns\WithAdminTable;
use App\Livewire\Admin\Concerns\WithRecordEditor;
use App\Support\DomainException;
use Illuminate\Support\Str;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

class EmployeeIndex extends Component
{
    use WithAdminTable, WithRecordEditor;

    /** The role filter's value for "personal feature checklist" (employees whose access was ticked one by one). */
    public const FILTER_PERSONAL = '_personal';

    #[Url(except: '')]
    public string $filterStatus = '';

    #[Url(except: '')]
    public string $filterRole = '';

    public function mount(): void
    {
        abort_unless($this->can('employees.view'), 403);
    }

    /** The side window shows the full page's editor, so it opens exactly what that page would (the same access rule). */
    protected function editable(string $id): bool
    {
        $employee = Str::isUuid($id) ? User::find($id) : null;

        return $employee !== null && EmployeeEditor::viewableBy(auth()->user(), $employee);
    }

    protected function creatable(): bool
    {
        return $this->can('employees.create');
    }

    /**
     * The row's on/off switch: disabled (suspended) people cannot sign in and are signed out everywhere at once; the toast offers Undo.
     * The service decides who may do it (company-wide authority, or a manager of every branch the person works at).
     */
    public function toggleActive(string $id, EmployeeService $svc): void
    {
        $employee = $this->employeeFor($id);
        if (! $employee) {
            return;
        }
        $previous = $employee->status->value;
        $next = $previous === 'active' ? 'suspended' : 'active';
        try {
            $svc->setStatus(auth()->user(), $employee, $next);
        } catch (DomainException $e) {
            $this->dispatch('toast', type: 'error', message: $e->getMessage());

            return;
        }
        $this->dispatch('toast', type: 'success', message: __($next === 'active' ? 'branch_access.enabled_toast' : 'branch_access.disabled_toast', ['name' => $employee->name]),
            undoLabel: __('admin.undo'), undoEvent: 'employee-status-undo', undoParams: ['id' => $id, 'status' => $previous]);
    }

    /** Undo of the switch: puts the account back to the state it had (the same authority check applies). */
    #[On('employee-status-undo')]
    public function undoStatus(string $id, string $status, EmployeeService $svc): void
    {
        $employee = $this->employeeFor($id);
        if (! $employee || ! in_array($status, ['active', 'suspended', 'deactivated'], true)) {
            return;
        }
        try {
            $svc->setStatus(auth()->user(), $employee, $status);
        } catch (DomainException $e) {
            $this->dispatch('toast', type: 'error', message: $e->getMessage());

            return;
        }
        $this->dispatch('toast', type: 'success', message: __('admin.saved'));
    }

    /** Only a person this viewer may open (the same rule as the side window and the full page). */
    private function employeeFor(string $id): ?User
    {
        $employee = Str::isUuid($id) ? User::find($id) : null;

        return $employee && EmployeeEditor::viewableBy(auth()->user(), $employee) ? $employee : null;
    }

    public function render(BranchEmployeeService $branchSvc, EmployeeService $employeeSvc)
    {
        $actor = auth()->user();
        $scope = $this->branchScope('employees.view');
        $s = $this->search;
        $q = User::query()->where('type', 'staff')
            ->with(['branchAssignments' => fn ($b) => $b->with('branch:id,code,name_en,name_ar'), 'roleAssignments.role'])
            ->when($s, fn ($q) => $q->where(fn ($w) => $w->where('name', 'ilike', "%{$s}%")->orWhere('email', 'ilike', "%{$s}%")
                ->orWhere('phone', 'like', "%{$s}%")->orWhere('job_title', 'ilike', "%{$s}%")))
            ->when($this->filterStatus, fn ($q, $st) => $q->where('status', $st))
            ->when($this->filterRole === self::FILTER_PERSONAL, fn ($q) => $q->whereHas('roleAssignments.role', fn ($x) => $x->whereNotNull('personal_user_id')))
            ->when($this->filterRole && $this->filterRole !== self::FILTER_PERSONAL, fn ($q) => $q->whereHas('roleAssignments.role', fn ($x) => $x->where('key', $this->filterRole)))
            ->orderBy('name');

        // Non-global viewers (and any ?branch filter) only see staff assigned to branches in scope.
        if (! $scope->all || $this->branch) {
            $inScope = function ($sub) use ($scope) {
                $sub->from('branch_employees as be')->whereColumn('be.user_id', 'users.id');
                $scope->apply($sub, 'be.branch_id');
            };
            $showUnassigned = ! $this->branch && $this->can('employees.assign');
            $q->where(fn ($w) => $w->whereExists($inScope)->when($showUnassigned, fn ($o) => $o->orWhere(fn ($u) => $u
                ->whereNotExists(fn ($s) => $s->from('branch_employees')->whereColumn('branch_employees.user_id', 'users.id'))
                ->whereNotExists(fn ($s) => $s->from('user_roles')->whereColumn('user_roles.user_id', 'users.id')))));
        }

        $employees = $q->paginate($this->perPage);
        $features = $branchSvc->featuresForList($employees->items());
        $manageable = [];
        foreach ($employees->items() as $e) {
            $manageable[$e->id] = $employeeSvc->canManage($actor, $e, $e->roleAssignments->whereNull('branch_id')->isNotEmpty(), $e->branchAssignments->pluck('branch_id')->all());
        }

        return view('livewire.admin.organization.employees', [
            'employees' => $employees,
            'features' => $features,
            'presets' => collect($features)->map(fn (array $ids) => BranchFeatureCatalog::presetFor($ids)),
            'manageable' => $manageable,
            'totalFeatures' => count(BranchFeatureCatalog::ids()),
            'branches' => $this->scopedBranches('employees.view'),
            'roles' => Role::shared()->orderBy('scope')->orderBy('name_en')->get(),
            'canCreate' => $this->can('employees.create'),
            'editing' => $this->editing(),
            'editEmployee' => ($id = $this->editing()) ? User::find($id) : null,
        ])->title(__('nav.employees'));
    }
}
