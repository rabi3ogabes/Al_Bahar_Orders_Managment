<?php

namespace App\Livewire\Admin\Branches;

use App\Domain\Branches\Models\Branch;
use App\Domain\Branches\Services\BranchHoursService;
use App\Domain\Identity\Services\AccessControl;
use App\Livewire\Admin\Concerns\WithAdminTable;
use Livewire\Attributes\Url;
use Livewire\Component;

class BranchIndex extends Component
{
    use WithAdminTable;

    #[Url(except: '')]
    public string $filterStatus = '';

    public function mount(): void
    {
        abort_unless($this->can('branches.view'), 403);
    }

    public function render()
    {
        $scope = $this->branchScope('branches.view');
        $q = Branch::query()->withCount(['employeeAssignments' => fn ($q) => $q->where('is_active', true)])->with('hours', 'holidays')
            ->when($this->search, fn ($q, $s) => $q->where(fn ($w) => $w->where('name_en', 'ilike', "%{$s}%")->orWhere('name_ar', 'like', "%{$s}%")->orWhere('code', 'ilike', "%{$s}%")->orWhere('area', 'ilike', "%{$s}%")))
            ->when($this->filterStatus, fn ($q, $s) => $q->where('status', $s))
            ->orderBy('sort_order')->orderBy('name_en');
        $scope->apply($q->getQuery(), 'id');

        return view('livewire.admin.branches.index', [
            'branches' => $q->paginate($this->perPage),
            'canCreate' => app(AccessControl::class)->allowsGlobally(auth()->user(), 'branches.create'),
            'hours' => app(BranchHoursService::class),
        ])->title(__('nav.branches'));
    }
}
