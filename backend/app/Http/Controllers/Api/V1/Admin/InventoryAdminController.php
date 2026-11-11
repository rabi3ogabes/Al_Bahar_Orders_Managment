<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Inventory\Models\BranchInventory;
use App\Domain\Inventory\Models\InventoryAdjustment;
use App\Domain\Inventory\Models\PurchaseOrder;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Inventory\Models\StocktakingSession;
use App\Domain\Inventory\Models\StockTransfer;
use App\Domain\Inventory\Models\Supplier;
use App\Domain\Inventory\Services\AdjustmentService;
use App\Domain\Inventory\Services\PurchasingService;
use App\Domain\Inventory\Services\StocktakingService;
use App\Domain\Inventory\Services\TransferService;
use App\Http\Controllers\Api\V1\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Branch inventory administration. Every list is constrained to the caller's server-derived branch scope;
 * every mutation is re-authorized for the specific branch inside the domain services.
 *
 * @tags Admin · Inventory
 */
class InventoryAdminController extends ApiController
{
    public function stock(Request $request): JsonResponse
    {
        $scope = $this->scope('inventory.view', $request->query('branch_id'));
        $q = BranchInventory::with(['branch:id,code,name_en,name_ar', 'variant:id,product_id,sku,name_en,name_ar', 'variant.product.translations'])
            ->when($request->query('filter') === 'low', fn ($q) => $q->where('minimum_stock', '>', 0)->whereRaw('on_hand - reserved <= minimum_stock'))
            ->when($request->query('filter') === 'out', fn ($q) => $q->whereRaw('on_hand - reserved <= 0'))
            ->when($request->query('search'), fn ($q, $s) => $q->whereHas('variant', fn ($v) => $v->where('sku', 'ilike', "%{$s}%")))
            ->orderByRaw('on_hand - reserved asc');
        $scope->apply($q->getQuery());
        $page = $q->paginate($this->perPage($request, 50));

        return response()->json([
            'data' => $page->getCollection()->map(fn ($r) => [
                'id' => $r->id, 'branch' => ['id' => $r->branch_id, 'code' => $r->branch->code], 'variant_id' => $r->product_variant_id,
                'sku' => $r->variant->sku, 'product' => $r->variant->product->trans('name'), 'on_hand' => $r->on_hand, 'reserved' => $r->reserved,
                'available' => $r->available(), 'minimum_stock' => $r->minimum_stock, 'maximum_stock' => $r->maximum_stock,
                'is_low' => $r->isLow(), 'average_cost' => $r->average_cost, 'last_counted_at' => $r->last_counted_at?->toIso8601String(),
            ]),
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    /** Only stock thresholds are editable here — quantities change exclusively through ledger workflows. */
    public function updateThresholds(Request $request, BranchInventory $inventory): JsonResponse
    {
        $this->authorizeBranch('inventory.edit', $inventory->branch_id);
        $data = $request->validate(['minimum_stock' => 'required|integer|min:0', 'maximum_stock' => 'nullable|integer|gte:minimum_stock', 'bin_location' => 'nullable|string|max:64']);
        $inventory->update($data);

        return response()->json(['data' => $inventory]);
    }

    public function movements(Request $request): JsonResponse
    {
        $scope = $this->scope('inventory.view', $request->query('branch_id'));
        $q = StockMovement::with(['variant:id,sku', 'actor:id,name'])
            ->when($request->query('variant_id'), fn ($q, $v) => $q->where('product_variant_id', $v))
            ->when($request->query('type'), fn ($q, $t) => $q->where('type', $t))
            ->orderByDesc('id');
        $scope->apply($q->getQuery());

        return response()->json($q->paginate($this->perPage($request, 50)));
    }

    public function storeAdjustment(Request $request, AdjustmentService $svc): JsonResponse
    {
        $data = $request->validate([
            'branch_id' => 'required|uuid|exists:branches,id', 'type' => 'required|in:adjustment,damaged,lost,opening_balance,customer_return',
            'reason' => 'required|string|max:500', 'items' => 'required|array|min:1', 'items.*.variant_id' => 'required|uuid|exists:product_variants,id',
            'items.*.quantity' => 'required|integer|not_in:0', 'items.*.unit_cost' => 'nullable|numeric|min:0', 'items.*.note' => 'nullable|string|max:255',
        ]);

        return response()->json(['data' => $svc->create($this->user(), $data['branch_id'], $data['type'], $data['reason'], $data['items'])], 201);
    }

    public function postAdjustment(InventoryAdjustment $adjustment, AdjustmentService $svc): JsonResponse
    {
        return response()->json(['data' => $svc->post($this->user(), $adjustment)]);
    }

    public function transfers(Request $request): JsonResponse
    {
        $scope = $this->scope('transfers.view');
        $q = StockTransfer::with(['source:id,code,name_en', 'destination:id,code,name_en'])->withCount('items')
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))->orderByDesc('created_at');
        if (! $scope->all) {
            $q->where(fn ($w) => $w->whereIn('source_branch_id', $scope->branchIds)->orWhereIn('destination_branch_id', $scope->branchIds));
        }

        return response()->json($q->paginate($this->perPage($request)));
    }

    public function storeTransfer(Request $request, TransferService $svc): JsonResponse
    {
        $data = $request->validate([
            'source_branch_id' => 'required|uuid|exists:branches,id', 'destination_branch_id' => 'required|uuid|exists:branches,id|different:source_branch_id',
            'items' => 'required|array|min:1', 'items.*.variant_id' => 'required|uuid|exists:product_variants,id', 'items.*.quantity' => 'required|integer|min:1',
            'notes' => 'nullable|string|max:1000', 'submit' => 'boolean',
        ]);
        $t = $svc->create($this->user(), $data['source_branch_id'], $data['destination_branch_id'], $data['items'], $data['notes'] ?? null);
        if ($request->boolean('submit')) {
            $t = $svc->submit($this->user(), $t);
        }

        return response()->json(['data' => $t], 201);
    }

    public function transferAction(Request $request, StockTransfer $transfer, string $action, TransferService $svc): JsonResponse
    {
        $u = $this->user();
        $result = match ($action) {
            'submit' => $svc->submit($u, $transfer),
            'approve' => $svc->approve($u, $transfer, $request->validate(['quantities' => 'nullable|array', 'quantities.*' => 'integer|min:0'])['quantities'] ?? []),
            'reject' => $svc->reject($u, $transfer, $request->validate(['reason' => 'required|string|max:500'])['reason']),
            'dispatch' => $svc->dispatch($u, $transfer, $request->validate(['quantities' => 'nullable|array', 'quantities.*' => 'integer|min:0'])['quantities'] ?? []),
            'receive' => $svc->receive($u, $transfer, $request->validate(['lines' => 'required|array', 'lines.*.received' => 'required|integer|min:0',
                'lines.*.damaged' => 'nullable|integer|min:0', 'lines.*.note' => 'nullable|string|max:500'])['lines']),
            'complete' => $svc->complete($u, $transfer),
            'cancel' => $svc->cancel($u, $transfer, $request->validate(['reason' => 'required|string|max:500'])['reason']),
            default => abort(404),
        };

        return response()->json(['data' => $result]);
    }

    public function startStocktake(Request $request, StocktakingService $svc): JsonResponse
    {
        $data = $request->validate(['branch_id' => 'required|uuid|exists:branches,id', 'category_id' => 'nullable|uuid|exists:categories,id', 'notes' => 'nullable|string|max:1000']);

        return response()->json(['data' => $svc->start($this->user(), $data['branch_id'], $data['category_id'] ?? null, $data['notes'] ?? null)], 201);
    }

    public function stocktakeAction(Request $request, StocktakingSession $session, string $action, StocktakingService $svc): JsonResponse
    {
        $u = $this->user();
        $result = match ($action) {
            'count' => $svc->count($u, $session, ...array_values($request->validate(['variant_id' => 'required|uuid|exists:product_variants,id', 'counted' => 'required|integer|min:0']))),
            'submit' => $svc->submit($u, $session),
            'post' => $svc->post($u, $session),
            'cancel' => $svc->cancel($u, $session),
            default => abort(404),
        };

        return response()->json(['data' => $result]);
    }

    public function suppliers(Request $request): JsonResponse
    {
        return response()->json(Supplier::when($request->query('search'), fn ($q, $s) => $q->where('name', 'ilike', "%{$s}%")->orWhere('code', 'ilike', "%{$s}%"))
            ->orderBy('name')->paginate($this->perPage($request)));
    }

    public function storeSupplier(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => 'required|string|max:32|unique:suppliers,code', 'name' => 'required|string|max:191',
            'contact_name' => 'nullable|string|max:120', 'phone' => 'nullable|string|max:32', 'email' => 'nullable|email', 'address' => 'nullable|string|max:255',
            'country' => 'nullable|string|size:2', 'tax_number' => 'nullable|string|max:64', 'payment_terms' => 'nullable|string|max:120', 'notes' => 'nullable|string|max:2000']);
        $s = Supplier::create($data);
        app(AuditLogger::class)->log('purchasing.supplier_created', $s);

        return response()->json(['data' => $s], 201);
    }

    public function storePurchaseOrder(Request $request, PurchasingService $svc): JsonResponse
    {
        $data = $request->validate(['supplier_id' => 'required|uuid|exists:suppliers,id', 'branch_id' => 'required|uuid|exists:branches,id',
            'lines' => 'required|array|min:1', 'lines.*.variant_id' => 'required|uuid|exists:product_variants,id|distinct',
            'lines.*.quantity' => 'required|integer|min:1', 'lines.*.unit_cost' => 'required|numeric|min:0',
            'expected_at' => 'nullable|date', 'notes' => 'nullable|string|max:2000', 'supplier_reference' => 'nullable|string|max:120']);

        return response()->json(['data' => $svc->create($this->user(), $data['supplier_id'], $data['branch_id'], $data['lines'], $data)], 201);
    }

    public function purchaseOrderAction(Request $request, PurchaseOrder $po, string $action, PurchasingService $svc): JsonResponse
    {
        $u = $this->user();
        $result = match ($action) {
            'submit' => $svc->submit($u, $po),
            'approve' => $svc->approve($u, $po),
            'reject' => $svc->reject($u, $po),
            'receive' => $svc->receive($u, $po, $request->validate(['lines' => 'required|array', 'lines.*.received' => 'required|integer|min:0',
                'lines.*.rejected' => 'nullable|integer|min:0', 'lines.*.unit_cost' => 'nullable|numeric|min:0', 'lines.*.rejection_reason' => 'nullable|string|max:255'])['lines'],
                $request->input('supplier_invoice_number'), $request->input('notes')),
            'close' => $svc->close($u, $po),
            'cancel' => $svc->cancel($u, $po),
            default => abort(404),
        };

        return response()->json(['data' => $result]);
    }
}
