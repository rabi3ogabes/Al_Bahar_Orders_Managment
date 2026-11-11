<?php

namespace App\Livewire\Admin\Finance;

use App\Domain\Identity\Models\User;
use App\Domain\Payments\Models\CashCollection;
use App\Domain\Payments\Models\CashHandover;
use App\Domain\Payments\Services\CashDrawerService;
use App\Livewire\Admin\Concerns\WithAdminTable;
use App\Support\BusinessTime;
use App\Support\Money;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Cash drawer. Three views of the same money:
 *  collections (what was collected) → to hand in (what collectors still hold) → hand-ins (counted, variances, bank deposits).
 */
class CashIndex extends Component
{
    use WithAdminTable;

    public const TABS = ['collections', 'outstanding', 'handovers'];

    #[Url(except: 'collections')]
    public string $tab = 'collections';

    public bool $showDialog = false;

    /** handin | resolve | deposit */
    public ?string $dialog = null;

    /** handin: "collectorId|branchId"; resolve: hand-in id. */
    #[Locked]
    public ?string $target = null;

    public array $form = ['counted' => '', 'note' => '', 'resolution' => '', 'reference' => ''];

    /** Hand-in ids ticked for a bank deposit. */
    public array $selected = [];

    #[Url(except: '')]
    public string $filterHandover = '';

    #[Url(except: '')]
    public string $filterContext = '';

    #[Url(except: '')]
    public string $filterTender = '';

    #[Url(except: '')]
    public string $filterStatus = '';

    #[Url(except: '')]
    public string $filterFrom = '';

    #[Url(except: '')]
    public string $filterTo = '';

    public function mount(): void
    {
        abort_unless($this->can('payments.view'), 403);
        if (! in_array($this->tab, self::TABS, true)) {
            $this->tab = 'collections';
        }
        $this->filterFrom = $this->filterFrom ?: BusinessTime::now()->subDays(6)->toDateString();
        $this->filterTo = $this->filterTo ?: BusinessTime::now()->toDateString();
    }

    public function updatedTab(): void
    {
        $this->resetPage();
        $this->selected = [];
    }

    public function openHandIn(string $collectorId, string $branchId): void
    {
        $this->authorizeBranch('payments.edit', $branchId);
        abort_if($collectorId === auth()->id(), 403);
        $this->dialog = 'handin';
        $this->target = $collectorId.'|'.$branchId;
        $this->form = ['counted' => '', 'note' => '', 'resolution' => '', 'reference' => ''];
        $this->resetValidation();
        $this->showDialog = true;
    }

    public function openResolve(string $handoverId): void
    {
        $h = CashHandover::findOrFail($handoverId);
        $this->authorizeBranch('payments.override', $h->branch_id);
        $this->dialog = 'resolve';
        $this->target = $h->id;
        $this->form = ['counted' => '', 'note' => '', 'resolution' => '', 'reference' => ''];
        $this->resetValidation();
        $this->showDialog = true;
    }

    public function openDeposit(): void
    {
        abort_if($this->selected === [], 422);
        $branchIds = CashHandover::whereIn('id', $this->selected)->pluck('branch_id')->unique();
        abort_unless($branchIds->isNotEmpty() && $branchIds->every(fn ($b) => $this->can('payments.edit', $b)), 403);
        $this->dialog = 'deposit';
        $this->target = null;
        $this->form = ['counted' => '', 'note' => '', 'resolution' => '', 'reference' => ''];
        $this->resetValidation();
        $this->showDialog = true;
    }

    public function confirmDialog(CashDrawerService $drawer): void
    {
        $user = auth()->user();
        $ok = match ($this->dialog) {
            'handin' => $this->handIn($drawer, $user),
            'resolve' => $this->resolve($drawer, $user),
            'deposit' => $this->deposit($drawer, $user),
            default => abort(422),
        };
        if ($ok) {
            $this->showDialog = false;
        }
    }

    private function handIn(CashDrawerService $drawer, User $user): bool
    {
        [$collectorId, $branchId] = array_pad(explode('|', (string) $this->target), 2, null);
        $this->authorizeBranch('payments.edit', $branchId);
        $f = $this->validate(['form.counted' => 'required|numeric|min:0', 'form.note' => 'nullable|string|max:500'], [], ['form.counted' => __('finance_admin.counted'), 'form.note' => __('admin.notes')])['form'];

        return (bool) $this->attempt(fn () => $drawer->handIn($user, User::findOrFail($collectorId), $branchId, (string) $f['counted'], $f['note'] ?: null), __('finance_admin.handed_in_ok'));
    }

    private function resolve(CashDrawerService $drawer, User $user): bool
    {
        $h = CashHandover::findOrFail($this->target);
        $this->authorizeBranch('payments.override', $h->branch_id);
        $f = $this->validate(['form.resolution' => 'required|string|min:3|max:500'], [], ['form.resolution' => __('finance_admin.resolution')])['form'];

        return (bool) $this->attempt(fn () => $drawer->resolveVariance($user, $h, $f['resolution']), __('finance_admin.variance_resolved_ok'));
    }

    private function deposit(CashDrawerService $drawer, User $user): bool
    {
        $f = $this->validate(['form.reference' => 'required|string|min:2|max:128'], [], ['form.reference' => __('finance_admin.bank_reference')])['form'];
        $res = $this->attempt(fn () => $drawer->deposit($user, $this->selected, $f['reference']));
        if ($res) {
            $this->selected = [];
            $this->toast(__('finance_admin.deposited_ok', ['amount' => $res['amount']->format(), 'n' => $res['count']]));
        }

        return (bool) $res;
    }

    public function render()
    {
        $scope = $this->branchScope('payments.view');
        $from = strtotime($this->filterFrom) ? $this->filterFrom : BusinessTime::now()->toDateString();
        $to = strtotime($this->filterTo) && $this->filterTo >= $from ? $this->filterTo : $from;
        [$start, $end] = BusinessTime::dateRange($from, $to);
        $s = trim($this->search);
        $base = fn () => tap(CashCollection::query()->whereBetween('collected_at', [$start, $end]), fn ($q) => $scope->apply($q->getQuery(), 'branch_id'))
            ->when($this->filterContext, fn ($q, $c) => $q->where('context', $c))
            ->when($this->filterTender, fn ($q, $t) => $q->where('tender', $t))
            ->when($this->filterStatus, fn ($q, $st) => $q->where('status', $st))
            ->when($s, fn ($q) => $q->where(fn ($w) => $w->where('receipt_reference', 'ilike', "%{$s}%")->orWhereHas('payment.order', fn ($o) => $o->where('number', 'ilike', "%{$s}%"))
                ->orWhereHas('collector', fn ($u) => $u->where('name', 'ilike', "%{$s}%"))));

        $totals = $base()->selectRaw("coalesce(sum(amount_collected),0) as collected,
            coalesce(sum(CASE WHEN tender = 'cash' THEN amount_collected ELSE 0 END),0) as cash,
            coalesce(sum(CASE WHEN tender = 'card_terminal' THEN amount_collected ELSE 0 END),0) as card,
            coalesce(sum(CASE WHEN handed_in_at IS NULL AND tender = 'cash' THEN amount_collected ELSE 0 END),0) as not_handed_in,
            sum(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed")->first();

        // Cash still held by collectors, the drawer (handed in, not banked) and hand-ins awaiting variance review.
        $held = tap(CashCollection::query()->where('tender', 'cash')->whereIn('status', ['collected', 'partial'])->where('amount_collected', '>', 0)->whereNull('handed_in_at'),
            fn ($q) => $scope->apply($q->getQuery(), 'branch_id'))->when($this->branch, fn ($q, $b) => $q->where('branch_id', $b));
        $handovers = fn () => tap(CashHandover::query(), fn ($q) => $scope->apply($q->getQuery(), 'branch_id'))->when($this->branch, fn ($q, $b) => $q->where('branch_id', $b));
        $drawer = Money::of((string) $handovers()->whereNull('deposited_at')->sum('counted_amount'));
        $openVariances = (int) $handovers()->where('variance_status', 'open')->count();

        $outstanding = $this->tab === 'outstanding'
            ? (clone $held)->groupBy('branch_id', 'collected_by')->selectRaw('branch_id, collected_by, count(*) as n, sum(amount_collected) as total, min(collected_at) as oldest')->orderBy('oldest')->get()
            : collect();
        $collectors = User::whereIn('id', $outstanding->pluck('collected_by'))->pluck('name', 'id');
        $branchCodes = $this->scopedBranches('payments.view')->pluck('code', 'id');

        $handoverRows = $this->tab === 'handovers'
            ? $handovers()->with(['branch:id,code', 'collector:id,name', 'receiver:id,name'])
                ->when($this->filterHandover === 'in_drawer', fn ($q) => $q->whereNull('deposited_at'))
                ->when($this->filterHandover === 'variance', fn ($q) => $q->where('variance_status', 'open'))
                ->when($s, fn ($q) => $q->where(fn ($w) => $w->where('number', 'ilike', "%{$s}%")->orWhere('deposit_reference', 'ilike', "%{$s}%")->orWhereHas('collector', fn ($u) => $u->where('name', 'ilike', "%{$s}%"))))
                ->latest()->paginate($this->perPage)
            : null;
        $dialogHandover = $this->dialog === 'resolve' && $this->target ? CashHandover::with(['collector:id,name', 'receiver:id,name'])->find($this->target) : null;
        $dialogExpected = null;
        if ($this->dialog === 'handin' && $this->target) {
            [$cid, $bid] = array_pad(explode('|', $this->target), 2, null);
            $dialogExpected = Money::of((string) app(CashDrawerService::class)->openCollections((string) $cid, $bid)->sum('amount_collected'));
        }

        return view('livewire.admin.finance.cash', [
            'rows' => $this->tab === 'collections' ? $base()->with(['payment.order:id,number,customer_name', 'collector:id,name', 'branch:id,code'])->latest('collected_at')->paginate($this->perPage) : null,
            'totals' => $totals,
            'branches' => $this->scopedBranches('payments.view'),
            'drawer' => $drawer,
            'openVariances' => $openVariances,
            'outstanding' => $outstanding,
            'collectors' => $collectors,
            'branchCodes' => $branchCodes,
            'handoverRows' => $handoverRows,
            'dialogHandover' => $dialogHandover,
            'dialogExpected' => $dialogExpected,
            'selectedTotal' => $this->selected ? Money::of((string) CashHandover::whereIn('id', $this->selected)->sum('counted_amount')) : null,
        ])->title(__('nav.cash'));
    }
}
