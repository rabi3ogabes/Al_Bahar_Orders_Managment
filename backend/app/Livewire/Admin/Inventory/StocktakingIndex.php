<?php

namespace App\Livewire\Admin\Inventory;

use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Models\StocktakingItem;
use App\Domain\Inventory\Models\StocktakingSession;
use App\Domain\Inventory\Services\StocktakingService;
use App\Livewire\Admin\Catalog\CategoryIndex;
use App\Livewire\Admin\Concerns\WithAdminTable;
use App\Livewire\Admin\Concerns\WithRecordView;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\Component;

class StocktakingIndex extends Component
{
    use WithAdminTable, WithRecordView;

    #[Url(except: '')]
    public string $filterStatus = '';

    public bool $showEditor = false;

    public array $form = ['branch_id' => '', 'category_id' => '', 'notes' => ''];

    public function mount(): void
    {
        abort_unless($this->can('stocktaking.view'), 403);
    }

    /** Same rule as the count screen: stocktaking.view at the session's branch. The window is a summary; counting stays on its own screen. */
    protected function viewable(string $id): bool
    {
        $session = StocktakingSession::find($id, ['id', 'branch_id']);

        return $session !== null && $this->can('stocktaking.view', $session->branch_id);
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
        // Counts that wait for a second person, inside the viewer's branches: how many, and what the differences are worth at cost.
        $waiting = $this->branchScope('stocktaking.view')->apply(DB::table('stocktaking_sessions')->where('status', 'submitted'))
            ->selectRaw('count(*)::int as n, coalesce(sum(variance_value), 0) as value, count(*) filter (where variance_value is null and coalesce(variance_lines, 0) > 0)::int as unknown')->first();
        $viewSession = ($id = $this->viewing()) ? StocktakingSession::with('branch:id,code,name_en,name_ar')->find($id) : null;
        $viewStats = $viewSession ? StocktakingItem::where('stocktaking_session_id', $viewSession->id)->selectRaw('count(*) as lines, count(counted_quantity) as counted,
            coalesce(sum(case when counted_quantity is not null then counted_quantity - expected_quantity else 0 end), 0) as net,
            sum(case when counted_quantity is not null and counted_quantity <> expected_quantity then 1 else 0 end) as variances')->first() : null;

        return view('livewire.admin.inventory.stocktaking', [
            'sessions' => $sessions,
            'people' => User::whereIn('id', $sessions->pluck('started_by')->merge($sessions->pluck('submitted_by'))->filter()->unique())->pluck('name', 'id'),
            'waiting' => ['count' => (int) ($waiting->n ?? 0), 'value' => Money::of((string) ($waiting->value ?? '0')), 'unknown' => (int) ($waiting->unknown ?? 0)],
            'branches' => $this->scopedBranches('stocktaking.create'),
            'categories' => $this->showEditor ? CategoryIndex::treeOptions() : collect(),
            'viewSession' => $viewSession,
            'viewStats' => $viewStats,
            'viewCanCount' => $viewSession && $viewSession->status === 'counting' && $this->can('stocktaking.edit', $viewSession->branch_id),
        ])->title(__('nav.stocktaking'));
    }
}
