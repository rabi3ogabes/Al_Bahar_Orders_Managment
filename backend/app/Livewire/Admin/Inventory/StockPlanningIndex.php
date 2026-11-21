<?php

namespace App\Livewire\Admin\Inventory;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Branches\Models\Branch;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Identity\Services\AccessControl;
use App\Domain\Identity\Support\BranchScope;
use App\Domain\Inventory\Models\BranchInventory;
use App\Domain\Inventory\Models\PurchaseOrder;
use App\Domain\Inventory\Models\Supplier;
use App\Domain\Inventory\Services\PurchasingService;
use App\Domain\Inventory\Services\StockPlanning;
use App\Domain\Inventory\Services\StockPlanningSettings;
use App\Domain\Inventory\Support\PlanRow;
use App\Livewire\Admin\Concerns\WithAdminTable;
use App\Support\BusinessTime;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * "What do I have to order today?" (roadmap 4.2): every item the viewer's branches hold, the urgent ones first, with days of cover, the reorder point,
 * what is already on order, how many customers wait for it, and a suggested order that becomes a draft purchase order in one tap.
 * Every number comes from {@see StockPlanning}; this component only filters, pages, and hands the suggestion to PurchasingService.
 */
class StockPlanningIndex extends Component
{
    use WithAdminTable;

    /** Most lines "order everything" puts on one purchase order. */
    private const ORDER_LINES = 100;

    /** order | out | low | reorder | slow | all (see StockPlanning::LANES) */
    #[Url(except: 'order')]
    public string $lane = 'order';

    public bool $showRules = false;

    /** @var array<string, mixed> the per-item planning rules being edited */
    public array $plan = [];

    public bool $showOrder = false;

    /** @var array<string, mixed> the purchase order being prepared: branch_id, supplier_id, expected_at, notes */
    public array $draft = [];

    /** @var list<array{variant_id: string, quantity: int|string, unit_cost: string}> */
    public array $lines = [];

    public function mount(): void
    {
        abort_unless($this->can('inventory.view'), 403);
        if (! in_array($this->lane, StockPlanning::LANES, true)) {
            $this->lane = 'order';
        }
    }

    public function updatedLane(): void
    {
        if (! in_array($this->lane, StockPlanning::LANES, true)) {
            $this->lane = 'order';
        }
        $this->resetPage();
    }

    // ── Per-item rules ───────────────────────────────────────────────────────────────────────────────────────────────

    public function openRules(string $id): void
    {
        $row = BranchInventory::query()->findOrFail($id);
        $this->authorizeBranch('inventory.edit', $row->branch_id);
        $this->resetValidation();
        $this->plan = ['id' => $row->id, 'lead_time_days' => $row->lead_time_days ?? '', 'safety_days' => $row->safety_days ?? '', 'reorder_point' => $row->reorder_point ?? '', 'reorder_quantity' => $row->reorder_quantity ?? ''];
        $this->showRules = true;
    }

    public function saveRules(AuditLogger $audit): void
    {
        $row = BranchInventory::query()->findOrFail($this->plan['id'] ?? null);
        $this->authorizeBranch('inventory.edit', $row->branch_id);
        $data = $this->validate([
            'plan.lead_time_days' => 'nullable|integer|min:0|max:365',
            'plan.safety_days' => 'nullable|integer|min:0|max:365',
            'plan.reorder_point' => 'nullable|integer|min:0|max:1000000',
            'plan.reorder_quantity' => 'nullable|integer|min:1|max:1000000',
        ], [], ['plan.lead_time_days' => __('stock_planning.rule_lead'), 'plan.safety_days' => __('stock_planning.rule_safety'),
            'plan.reorder_point' => __('stock_planning.rule_point'), 'plan.reorder_quantity' => __('stock_planning.rule_quantity')])['plan'];
        $row->fill(array_map(fn ($v) => $v === '' || $v === null ? null : (int) $v, [
            'lead_time_days' => $data['lead_time_days'] ?? null, 'safety_days' => $data['safety_days'] ?? null,
            'reorder_point' => $data['reorder_point'] ?? null, 'reorder_quantity' => $data['reorder_quantity'] ?? null,
        ]));
        $dirty = $row->getDirty();
        $old = array_intersect_key($row->getOriginal(), $dirty);
        $row->save(); // the rules only: quantities are never written here
        if ($dirty) {
            $audit->log('inventory.planning_rules_updated', $row, $old, $dirty);
        }
        $this->showRules = false;
        $this->toast(__('admin.saved'));
    }

    // ── From a suggestion to a purchase order ────────────────────────────────────────────────────────────────────────

    /** One item (its stock row id), or, with no id, everything the list suggests for the one branch that is picked. */
    public function openOrder(?string $id = null): void
    {
        if ($id !== null) {
            $branchId = (string) BranchInventory::query()->whereKey($id)->value('branch_id');
            abort_if($branchId === '', 404);
            $this->authorizeBranch('purchasing.create', $branchId);
            $rows = app(StockPlanning::class)->rows($this->scopeOf($branchId), 'all', '', 1, 0, null, [$id]);
        } else {
            $branchId = $this->singleBranch();
            if ($branchId === null) {
                $this->toast(__('stock_planning.pick_branch_first'), 'warning');

                return;
            }
            $this->authorizeBranch('purchasing.create', $branchId);
            $rows = app(StockPlanning::class)->rows($this->scopeOf($branchId), $this->lane === 'all' || $this->lane === 'slow' ? 'order' : $this->lane, $this->search, self::ORDER_LINES);
        }
        $rows = array_values(array_filter($rows, fn (PlanRow $r) => $r->suggested > 0));
        if ($rows === []) {
            $this->toast(__('stock_planning.nothing_to_order'), 'warning');

            return;
        }
        $defaults = app(StockPlanning::class)->purchaseDefaults(array_map(fn (PlanRow $r) => $r->variantId, $rows));
        $lead = max(array_map(fn (PlanRow $r) => $r->leadDays, $rows));
        $this->resetValidation();
        $this->draft = ['branch_id' => $branchId, 'supplier_id' => $defaults['supplier_id'] ?? '', 'expected_at' => BusinessTime::now()->addDays($lead)->toDateString(), 'notes' => ''];
        $this->lines = array_map(fn (PlanRow $r) => ['variant_id' => $r->variantId, 'quantity' => $r->suggested, 'unit_cost' => $defaults['costs'][$r->variantId] ?? ''], $rows);
        $this->showOrder = true;
    }

    public function removeLine(int $i): void
    {
        unset($this->lines[$i]);
        $this->lines = array_values($this->lines);
        if ($this->lines === []) {
            $this->showOrder = false;
        }
    }

    /** Makes the draft purchase order with the existing purchasing code (numbering, audit, permission and totals are its own) and opens it. */
    public function createOrder(PurchasingService $svc)
    {
        $d = $this->validate([
            'draft.supplier_id' => 'required|uuid|exists:suppliers,id',
            'draft.branch_id' => 'required|uuid|exists:branches,id',
            'draft.expected_at' => 'nullable|date',
            'draft.notes' => 'nullable|string|max:2000',
            'lines' => 'required|array|min:1|max:300',
            'lines.*.variant_id' => 'required|uuid|exists:product_variants,id|distinct',
            'lines.*.quantity' => 'required|integer|min:1|max:1000000',
            'lines.*.unit_cost' => ['required', 'numeric', 'min:0', 'max:99999999', 'regex:/^\d+(\.\d{1,4})?$/'],
        ], [], ['draft.supplier_id' => __('inventory_admin.supplier'), 'lines.*.quantity' => __('admin.quantity'), 'lines.*.unit_cost' => __('inventory_admin.unit_cost')]);
        $this->authorizeBranch('purchasing.create', $d['draft']['branch_id']);
        $f = $d['draft'];
        $po = $this->attempt(fn () => $svc->create(auth()->user(), $f['supplier_id'], $f['branch_id'],
            array_map(fn ($l) => ['variant_id' => $l['variant_id'], 'quantity' => (int) $l['quantity'], 'unit_cost' => (string) $l['unit_cost']], $d['lines']),
            ['expected_at' => $f['expected_at'] ?: null, 'notes' => $f['notes'] ?: null]));
        if (! $po instanceof PurchaseOrder) {
            return null;
        }
        // Emptied on purpose: the same press arriving twice (a double click, a retried request) finds nothing left to order and makes no second draft.
        $this->reset('lines', 'draft');
        $this->showOrder = false;
        session()->flash('toast', ['type' => 'success', 'message' => __('stock_planning.po_created', ['number' => $po->number])]);

        return $this->redirectRoute('admin.purchase-orders.index', ['view' => $po->id], navigate: true);
    }

    // ── CSV ──────────────────────────────────────────────────────────────────────────────────────────────────────────

    public function export(AuditLogger $audit)
    {
        abort_unless($this->can('inventory.export'), 403);
        $scope = app(AccessControl::class)->scope(auth()->user(), 'inventory.export')->narrow($this->branch ?: null);
        $view = app(StockPlanning::class)->view($scope, $this->lane, $this->search, StockPlanning::MAX_ROWS);
        $rows = $view['rows'];
        if (($view['counts'][$this->lane] ?? 0) > count($rows)) {
            $this->toast(__('stock_planning.export_cut', ['n' => number_format(count($rows))]), 'warning'); // never let a cut list pass for the whole one
        }
        $variants = ProductVariant::with(['product.translations'])->whereIn('id', array_map(fn (PlanRow $r) => $r->variantId, $rows))->get()->keyBy('id');
        $branches = Branch::pluck('code', 'id');
        $audit->log('inventory.planning_exported', 'StockPlanning', [], [], ['rows' => count($rows), 'lane' => $this->lane, 'branch' => $this->branch ?: null]);
        $guard = fn ($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) ? "'".$v : $v;

        return response()->streamDownload(function () use ($rows, $variants, $branches, $guard) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel shows Arabic correctly
            fputcsv($out, ['branch', 'sku', 'product', 'status', 'on_hand', 'reserved', 'available', 'sold_in_window', 'per_day', 'days_of_cover', 'reorder_point', 'on_order', 'customers_waiting', 'out_since_qatar', 'suggested_order']);
            foreach ($rows as $r) {
                $v = $variants[$r->variantId] ?? null;
                fputcsv($out, [$branches[$r->branchId] ?? '', $v?->sku, $guard($v?->product?->trans('name')), $r->lane, $r->onHand, $r->reserved, $r->available, $r->unitsSold,
                    number_format($r->dailyDemand, 2, '.', ''), $r->coverDays ?? '', $r->reorderPoint, $r->incoming, $r->waiting, $r->outSince !== null ? BusinessTime::local($r->outSince)?->format('Y-m-d') : '', $r->suggested]);
            }
            fclose($out);
        }, 'stock-planning-'.BusinessTime::now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────────────────────────────────────────────

    /** The branch the viewer is looking at: the one picked in the filter, or the only branch they may see. */
    private function singleBranch(): ?string
    {
        if ($this->branch !== '' && $this->can('inventory.view', $this->branch)) {
            return $this->branch;
        }
        $branches = $this->scopedBranches('inventory.view');

        return $branches->count() === 1 ? (string) $branches->first()->id : null;
    }

    /** What the viewer may see at one branch (never wider than their own scope). */
    private function scopeOf(string $branchId): BranchScope
    {
        return app(AccessControl::class)->scope(auth()->user(), 'inventory.view')->narrow($branchId);
    }

    public function render(StockPlanning $planning)
    {
        $scope = $this->branchScope('inventory.view');
        $counts = $planning->counts($scope, $this->search);
        $total = (int) ($counts[$this->lane] ?? $counts['all']);
        $perPage = $this->perPage;
        $page = max(1, min($this->getPage(), (int) ceil(max(1, $total) / $perPage)));
        $rows = $total === 0 ? [] : $planning->rows($scope, $this->lane, $this->search, $perPage, ($page - 1) * $perPage);
        $paginator = new LengthAwarePaginator($rows, $total, $perPage, $page, ['path' => LengthAwarePaginator::resolveCurrentPath(), 'pageName' => 'page']);
        $ids = array_map(fn (PlanRow $r) => $r->variantId, $rows);
        $acl = app(AccessControl::class);

        return view('livewire.admin.inventory.stock-planning', [
            'rows' => $paginator,
            'counts' => $counts,
            'variants' => $ids === [] ? collect() : ProductVariant::with(['product.translations', 'attributeValues'])->whereIn('id', $ids)->get()->keyBy('id'),
            'wishlists' => $planning->wishlistCounts($ids),
            'branches' => $this->scopedBranches('inventory.view'),
            'branchNames' => Branch::whereIn('id', array_map(fn (PlanRow $r) => $r->branchId, $rows))->get(['id', 'code', 'name_en', 'name_ar'])->keyBy('id'),
            'canOrderAt' => $acl->scope(auth()->user(), 'purchasing.create'),
            'canEditAt' => $acl->scope(auth()->user(), 'inventory.edit'),
            'canExport' => $this->can('inventory.export'),
            'canSettings' => $this->canGlobally('settings.view'),
            'numbers' => StockPlanningSettings::all(),
            'orderBranch' => $this->singleBranch(),
            'suppliers' => $this->showOrder ? Supplier::where('status', 'active')->orderBy('name')->get(['id', 'code', 'name']) : collect(),
            'orderVariants' => $this->showOrder ? ProductVariant::with(['product.translations', 'attributeValues'])->whereIn('id', array_column($this->lines, 'variant_id'))->get()->keyBy('id') : collect(),
            'orderTotal' => $this->showOrder ? $this->orderTotal() : Money::zero(),
            'now' => CarbonImmutable::now(),
            'planVariant' => $this->showRules ? $this->ruleSubject() : null,
        ])->title(__('stock_planning.title'));
    }

    private function orderTotal(): Money
    {
        $total = Money::zero();
        foreach ($this->lines as $l) {
            if (is_numeric($l['unit_cost'] ?? null) && is_numeric($l['quantity'] ?? null) && preg_match('/^\d+(\.\d{1,4})?$/', (string) $l['unit_cost'])) {
                $total = $total->plus(Money::of((string) $l['unit_cost'])->times((int) $l['quantity']));
            }
        }

        return $total;
    }

    /** The item whose rules are being edited (for the window's title), or null. */
    private function ruleSubject(): ?BranchInventory
    {
        return ($id = $this->plan['id'] ?? null) ? BranchInventory::with(['branch:id,code,name_en,name_ar', 'variant.product.translations', 'variant.attributeValues'])->find($id) : null;
    }
}
