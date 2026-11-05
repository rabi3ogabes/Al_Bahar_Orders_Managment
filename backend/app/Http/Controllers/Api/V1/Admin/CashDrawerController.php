<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessControl;
use App\Domain\Payments\Models\CashCollection;
use App\Domain\Payments\Models\CashHandover;
use App\Domain\Payments\Services\CashDrawerService;
use App\Http\Controllers\Api\V1\ApiController;
use App\Support\BusinessTime;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Cash drawer: what collectors still hold, hand-ins with variance review, and bank deposits. */
class CashDrawerController extends ApiController
{
    public function __construct(private readonly CashDrawerService $drawer, private readonly AccessControl $access) {}

    /** Cash each collector still holds, per branch (branch-scoped). */
    public function outstanding(Request $request): JsonResponse
    {
        $scope = $this->access->scope($this->user(), 'payments.view');
        $q = CashCollection::query()->where('tender', 'cash')->whereIn('status', ['collected', 'partial'])->where('amount_collected', '>', 0)->whereNull('handed_in_at');
        $scope->apply($q->getQuery(), 'branch_id');
        if ($request->filled('branch_id')) {
            $q->where('branch_id', $request->query('branch_id'));
        }
        $rows = $q->groupBy('branch_id', 'collected_by')->selectRaw('branch_id, collected_by, count(*) as n, sum(amount_collected) as total, min(collected_at) as oldest')->get();
        $names = User::whereIn('id', $rows->pluck('collected_by'))->pluck('name', 'id');

        return response()->json(['data' => $rows->map(fn ($r) => [
            'branch_id' => $r->branch_id, 'collector' => ['id' => $r->collected_by, 'name' => $names[$r->collected_by] ?? null],
            'collections' => (int) $r->n, 'amount' => Money::of((string) $r->total)->jsonSerialize(), 'oldest_collected_at' => BusinessTime::iso($r->oldest),
        ])->values()]);
    }

    public function handovers(Request $request): JsonResponse
    {
        $q = CashHandover::query()->with(['collector:id,name', 'receiver:id,name', 'branch:id,code'])->latest();
        $this->access->scope($this->user(), 'payments.view')->apply($q->getQuery(), 'branch_id');
        $q->when($request->query('branch_id'), fn ($x, $b) => $x->where('branch_id', $b))
            ->when($request->query('status') === 'in_drawer', fn ($x) => $x->whereNull('deposited_at'))
            ->when($request->query('variance') === 'open', fn ($x) => $x->where('variance_status', 'open'));
        $page = $q->paginate($this->perPage($request));

        return response()->json(['data' => $page->getCollection()->map(fn ($h) => $this->present($h)), 'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()]]);
    }

    public function handIn(Request $request): JsonResponse
    {
        $d = $request->validate(['collector_id' => 'required|uuid|exists:users,id', 'branch_id' => 'required|uuid|exists:branches,id', 'counted_amount' => 'required|numeric|min:0',
            'note' => 'nullable|string|max:500', 'collection_ids' => 'nullable|array|min:1', 'collection_ids.*' => 'uuid']);
        $h = $this->drawer->handIn($this->user(), User::findOrFail($d['collector_id']), $d['branch_id'], (string) $d['counted_amount'], $d['note'] ?? null, $d['collection_ids'] ?? null);

        return response()->json(['data' => $this->present($h->load(['collector:id,name', 'receiver:id,name', 'branch:id,code']))], 201);
    }

    public function resolve(Request $request, CashHandover $handover): JsonResponse
    {
        $h = $this->drawer->resolveVariance($this->user(), $handover, $request->validate(['resolution' => 'required|string|min:3|max:500'])['resolution']);

        return response()->json(['data' => $this->present($h->load(['collector:id,name', 'receiver:id,name', 'branch:id,code']))]);
    }

    public function deposit(Request $request): JsonResponse
    {
        $d = $request->validate(['handover_ids' => 'required|array|min:1', 'handover_ids.*' => 'uuid', 'reference' => 'required|string|min:2|max:128']);
        $res = $this->drawer->deposit($this->user(), $d['handover_ids'], $d['reference']);

        return response()->json(['data' => ['handovers' => $res['count'], 'amount' => $res['amount']->jsonSerialize(), 'reference' => $res['reference']]], 201);
    }

    private function present(CashHandover $h): array
    {
        return [
            'id' => $h->id, 'number' => $h->number, 'branch' => $h->branch?->code, 'collector' => $h->collector?->name, 'received_by' => $h->receiver?->name,
            'collections' => $h->collections_count, 'expected' => Money::of($h->expected_amount)->jsonSerialize(), 'counted' => Money::of($h->counted_amount)->jsonSerialize(),
            'variance' => Money::of($h->variance)->jsonSerialize(), 'status' => $h->status, 'variance_status' => $h->variance_status, 'note' => $h->note,
            'variance_resolution' => $h->variance_resolution, 'deposited_at' => BusinessTime::iso($h->deposited_at), 'deposit_reference' => $h->deposit_reference,
            'created_at' => BusinessTime::iso($h->created_at),
        ];
    }
}
