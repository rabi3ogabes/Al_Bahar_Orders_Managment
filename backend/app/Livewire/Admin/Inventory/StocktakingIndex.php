<?php

namespace App\Livewire\Admin\Inventory;

use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Models\StocktakingSession;
use App\Domain\Inventory\Services\StocktakingService;
use App\Livewire\Admin\Catalog\CategoryIndex;
use App\Livewire\Admin\Concerns\WithAdminTable;
use Livewire\Attributes\Url;
use Livewire\Component;

class StocktakingIndex extends Component
{
    use WithAdminTable;

    #[Url(except: '')]
    public string $filterStatus = '';

    public bool $showEditor = false;

    public array $form = ['branch_id' => '', 'category_id' => '', 'notes' => ''];

    public function mount(): void
    {
        abort_unless($this->can('stocktaking.view'), 403);
    }

    public function create(): void
    {
        abort_unless($this->can('stocktaking.create'), 403);
        $branches = $this->scopedBranches('stocktaking.create');
        $this->form = ['branch_id' => $branches->count() === 1 ? $branches->first()->id : '', 'category_id' => '', 'notes' => ''];
        $this->resetValidation();
        $this->showEditor = true;
    }

    public function start(StocktakingService $svc)
    {
        $d = $this->validate([
            'form.branch_id' => 'required|uuid|exists:branches,id',
            'form.category_id' => 'nullable|uuid|exists:categories,id',
            'form.notes' => 'nullable|string|max:1000',
        ], [], ['form.branch_id' => __('admin.branch')])['form'];
        $this->authorizeBranch('stocktaking.create', $d['branch_id']);
        if (StocktakingSession::where('branch_id', $d['branch_id'])->whereIn('status', ['counting', 'submitted'])->exists()) {
            $this->addError('form.branch_id', __('inventory_admin.session_already_open'));

            return;
        }
        $session = $this->attempt(fn () => $svc->start(auth()->user(), $d['branch_id'], $d['category_id'] ?: null, $d['notes'] ?: null));
        if ($session instanceof StocktakingSession) {
            session()->flash('toast', ['type' => 'success', 'message' => __('inventory_admin.session_started')]);

            return $this->redirectRoute('admin.stocktaking.show', $session, navigate: true);
        }
    }

    public function render()
    {
        $q = StocktakingSession::query()->with('branch:id,code,name_en,name_ar')
            ->withCount(['items', 'items as counted_count' => fn ($i) => $i->whereNotNull('counted_quantity')])
            ->when($this->filterStatus, fn ($q, $s) => $q->where('status', $s))
            ->when($this->search, fn ($q, $s) => $q->where('number', 'ilike', "%{$s}%"))
            ->latest();
        $this->branchScope('stocktaking.view')->apply($q->getQuery());
        $sessions = $q->paginate($this->perPage);

        return view('livewire.admin.inventory.stocktaking', [
            'sessions' => $sessions,
            'people' => User::whereIn('id', $sessions->pluck('started_by')->filter()->unique())->pluck('name', 'id'),
            'branches' => $this->scopedBranches('stocktaking.create'),
            'categories' => $this->showEditor ? CategoryIndex::treeOptions() : collect(),
        ])->title(__('nav.stocktaking'));
    }
}
