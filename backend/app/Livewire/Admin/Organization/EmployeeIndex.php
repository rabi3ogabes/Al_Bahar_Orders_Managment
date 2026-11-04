<?php

namespace App\Livewire\Admin\Organization;

use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Livewire\Admin\Concerns\WithAdminTable;
use Livewire\Attributes\Url;
use Livewire\Component;

class EmployeeIndex extends Component
{
    use WithAdminTable;

    #[Url(except: '')]
    public string $filterStatus = '';

    #[Url(except: '')]
    public string $filterRole = '';

    public function mount(): void
    {
        abort_unless($this->can('employees.view'), 403);
    }

    public function render()
    {
        $scope = $this->branchScope('employees.view');
        $s = $this->search;
        $q = User::query()->where('type', 'staff')
            ->with(['branchAssignments' => fn ($b) => $b->with('branch:id,code,name_en,name_ar'), 'roleAssignments.role'])
            ->when($s, fn ($q) => $q->where(fn ($w) => $w->where('name', 'ilike', "%{$s}%")->orWhere('email', 'ilike', "%{$s}%")
                ->orWhere('phone', 'like', "%{$s}%")->orWhere('job_title', 'ilike', "%{$s}%")))
            ->when($this->filterStatus, fn ($q, $st) => $q->where('status', $st))
            ->when($this->filterRole, fn ($q, $r) => $q->whereHas('roleAssignments.role', fn ($x) => $x->where('key', $r)))
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

        return view('livewire.admin.organization.employees', [
            'employees' => $q->paginate($this->perPage),
            'branches' => $this->scopedBranches('employees.view'),
            'roles' => Role::orderBy('scope')->orderBy('name_en')->get(),
            'canCreate' => $this->can('employees.create'),
        ])->title(__('nav.employees'));
    }
}
