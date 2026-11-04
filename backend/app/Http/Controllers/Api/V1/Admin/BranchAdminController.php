<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Branches\Models\Branch;
use App\Domain\Branches\Models\BranchDeliveryZone;
use App\Domain\Branches\Models\BranchEmployee;
use App\Domain\Branches\Models\BranchHoliday;
use App\Domain\Branches\Services\BranchService;
use App\Domain\Branches\Services\EmployeeService;
use App\Domain\Identity\Models\User;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\BranchRequest;
use App\Http\Resources\V1\Admin\BranchAdminResource;
use App\Support\DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** @tags Admin · Branches */
class BranchAdminController extends ApiController
{
    public function __construct(private readonly BranchService $branches) {}

    public function index(Request $request)
    {
        $scope = $this->scope('branches.view');
        $q = Branch::query()->withCount('employeeAssignments')
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('search'), fn ($q, $s) => $q->where(fn ($w) => $w->whereRaw('lower(name_en) like ?', ['%'.mb_strtolower($s).'%'])->orWhere('name_ar', 'like', "%{$s}%")->orWhere('code', 'ilike', "%{$s}%")))
            ->orderBy('sort_order');
        $scope->apply($q->getQuery(), 'id');

        return BranchAdminResource::collection($q->paginate($this->perPage($request)));
    }

    public function store(BranchRequest $request): JsonResponse
    {
        if (! app(\App\Domain\Identity\Services\AccessControl::class)->allowsGlobally($this->user(), 'branches.create')) {
            abort(403);
        }

        return (new BranchAdminResource($this->branches->create($request->validated())->load('hours')))->response()->setStatusCode(201);
    }

    public function show(Branch $branch): BranchAdminResource
    {
        $this->authorizeBranch('branches.view', $branch->id);

        return new BranchAdminResource($branch->load(['hours', 'holidays'])->loadCount('employeeAssignments'));
    }

    public function update(BranchRequest $request, Branch $branch): BranchAdminResource
    {
        $this->authorizeBranch('branches.edit', $branch->id);
        $data = $request->validated();
        // Activation/deactivation and code changes are company-level decisions.
        if ((isset($data['status']) || isset($data['code'])) && ! app(\App\Domain\Identity\Services\AccessControl::class)->allowsGlobally($this->user(), 'branches.edit')) {
            abort(403);
        }

        return new BranchAdminResource($this->branches->update($branch, $data));
    }

    public function updateHours(Request $request, Branch $branch): JsonResponse
    {
        $this->authorizeBranch('branches.edit', $branch->id);
        $data = $request->validate([
            'week' => 'required|array|size:7',
            'week.*.is_closed' => 'boolean',
            'week.*.opens_at' => 'nullable|date_format:H:i',
            'week.*.closes_at' => 'nullable|date_format:H:i',
            'week.*.break_starts_at' => 'nullable|date_format:H:i',
            'week.*.break_ends_at' => 'nullable|date_format:H:i|required_with:week.*.break_starts_at',
        ]);
        $this->branches->setWeeklyHours($branch, $data['week']);

        return response()->json(['data' => $branch->hours()->get()]);
    }

    public function storeHoliday(Request $request, Branch $branch): JsonResponse
    {
        $this->authorizeBranch('branches.edit', $branch->id);
        $data = $request->validate([
            'type' => 'required|in:holiday,temporary_closure',
            'name_en' => 'required|string|max:120', 'name_ar' => 'required|string|max:120',
            'starts_at' => 'required|date', 'ends_at' => 'required|date|after:starts_at',
            'reason' => 'nullable|string|max:500',
        ]);

        return response()->json(['data' => $this->branches->addHoliday($branch, $data)], 201);
    }

    public function destroyHoliday(Branch $branch, BranchHoliday $holiday): JsonResponse
    {
        abort_unless($holiday->branch_id === $branch->id, 404);
        $this->authorizeBranch('branches.edit', $branch->id);
        $this->branches->removeHoliday($holiday);

        return response()->json(['data' => ['deleted' => true]]);
    }

    public function employees(Branch $branch): JsonResponse
    {
        $this->authorizeBranch('employees.view', $branch->id);
        $rows = BranchEmployee::with(['user', 'user.roleAssignments' => fn ($q) => $q->where('branch_id', $branch->id)->with('role')])
            ->where('branch_id', $branch->id)->get()
            ->map(fn ($a) => [
                'user_id' => $a->user_id, 'name' => $a->user->name, 'email' => $a->user->email, 'job_title' => $a->job_title,
                'is_active' => $a->is_active, 'is_delivery_driver' => $a->is_delivery_driver,
                'roles' => $a->user->roleAssignments->pluck('role.key'),
            ]);

        return response()->json(['data' => $rows]);
    }

    public function assignEmployee(Request $request, Branch $branch, EmployeeService $employees): JsonResponse
    {
        $data = $request->validate([
            'user_id' => 'required|uuid|exists:users,id',
            'roles' => 'array', 'roles.*' => 'string|exists:roles,key',
            'job_title' => 'nullable|string|max:120',
            'is_delivery_driver' => 'boolean',
        ]);
        $employee = User::where('type', 'staff')->findOrFail($data['user_id']);
        $assignment = $employees->assignToBranch($this->user(), $employee, $branch, $data['roles'] ?? [], $data['job_title'] ?? null, (bool) ($data['is_delivery_driver'] ?? false));

        return response()->json(['data' => $assignment], 201);
    }

    public function removeEmployee(Branch $branch, User $user, EmployeeService $employees): JsonResponse
    {
        $employees->removeFromBranch($this->user(), $user, $branch);

        return response()->json(['data' => ['removed' => true]]);
    }

    public function zones(Branch $branch): JsonResponse
    {
        $this->authorizeBranch('zones.view', $branch->id);

        return response()->json(['data' => BranchDeliveryZone::with('zone')->where('branch_id', $branch->id)->orderBy('priority')->get()]);
    }

    public function upsertZone(Request $request, Branch $branch): JsonResponse
    {
        $this->authorizeBranch('zones.edit', $branch->id);
        $data = $request->validate([
            'delivery_zone_id' => 'required|uuid|exists:delivery_zones,id',
            'delivery_fee' => 'required|numeric|min:0',
            'free_delivery_threshold' => 'nullable|numeric|min:0',
            'min_order_amount' => 'nullable|numeric|min:0',
            'max_order_amount' => 'nullable|numeric|min:0|gte:min_order_amount',
            'oversized_surcharge' => 'nullable|numeric|min:0',
            'cod_enabled' => 'boolean', 'express_enabled' => 'boolean',
            'express_fee' => 'nullable|numeric|min:0|required_if:express_enabled,true',
            'estimated_minutes' => 'nullable|integer|min:0',
            'priority' => 'nullable|integer|min:0', 'is_active' => 'boolean',
        ]);
        $row = BranchDeliveryZone::updateOrCreate(['branch_id' => $branch->id, 'delivery_zone_id' => $data['delivery_zone_id']], $data + ['oversized_surcharge' => 0]);
        app(\App\Domain\Audit\Services\AuditLogger::class)->log('branch.zone_configured', $branch, [], $data);

        return response()->json(['data' => $row], 201);
    }
}
