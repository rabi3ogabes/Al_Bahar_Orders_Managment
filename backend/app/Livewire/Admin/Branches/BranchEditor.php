<?php

namespace App\Livewire\Admin\Branches;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Branches\Models\Branch;
use App\Domain\Branches\Models\BranchDeliveryZone;
use App\Domain\Branches\Models\BranchEmployee;
use App\Domain\Branches\Models\BranchHoliday;
use App\Domain\Branches\Models\DeliverySlot;
use App\Domain\Branches\Models\DeliveryZone;
use App\Domain\Branches\Services\BranchHoursService;
use App\Domain\Branches\Services\BranchService;
use App\Domain\Branches\Services\EmployeeService;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessControl;
use App\Http\Requests\Admin\BranchRequest;
use App\Livewire\Admin\Concerns\InteractsWithAdmin;
use App\Support\BusinessTime;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Url;
use Livewire\Component;

class BranchEditor extends Component
{
    use InteractsWithAdmin;

    public ?Branch $branch = null;

    #[Url]
    public string $tab = 'details';

    public array $form = [];

    public array $week = [];

    public array $holiday = ['type' => 'holiday', 'name_en' => '', 'name_ar' => '', 'starts_at' => '', 'ends_at' => '', 'reason' => ''];

    public array $zone = ['delivery_zone_id' => '', 'delivery_fee' => '15.00', 'free_delivery_threshold' => '', 'min_order_amount' => '', 'max_order_amount' => '', 'cod_enabled' => true, 'estimated_minutes' => 180, 'priority' => 100];

    public array $slot = ['fulfillment_type' => 'pickup', 'day_of_week' => 0, 'starts_at' => '16:00', 'ends_at' => '18:00', 'capacity' => 10];

    public array $assign = ['user_id' => '', 'roles' => [], 'job_title' => '', 'is_delivery_driver' => false];

    public function mount(?Branch $branch = null): void
    {
        $acl = app(AccessControl::class);
        if ($branch?->exists) {
            abort_unless($acl->allows(auth()->user(), 'branches.view', $branch->id), 403);
            $this->branch = $branch;
            $this->form = $branch->only(array_keys(BranchRequest::rulesFor()));
            $this->form['latitude'] = $branch->latitude !== null ? (string) $branch->latitude : null;
            $this->form['longitude'] = $branch->longitude !== null ? (string) $branch->longitude : null;
            $this->form['pay_at_pickup_tenders'] = $branch->pay_at_pickup_tenders ?? ['cash', 'card_terminal'];
            foreach ($branch->hours as $h) {
                $this->week[$h->day_of_week] = ['is_closed' => $h->is_closed, 'opens_at' => substr((string) $h->opens_at, 0, 5), 'closes_at' => substr((string) $h->closes_at, 0, 5),
                    'break_starts_at' => substr((string) $h->break_starts_at, 0, 5), 'break_ends_at' => substr((string) $h->break_ends_at, 0, 5)];
            }
        } else {
            abort_unless($acl->allowsGlobally(auth()->user(), 'branches.create'), 403);
            $this->form = ['code' => '', 'name_en' => '', 'name_ar' => '', 'status' => 'active', 'opening_status' => 'open', 'pickup_enabled' => true,
                'delivery_enabled' => true, 'dispatch_mode' => 'manual', 'cod_enabled' => true, 'pay_at_pickup_enabled' => true, 'pay_at_pickup_tenders' => ['cash', 'card_terminal'],
                'pickup_preparation_minutes' => 60, 'sort_order' => 0, 'is_fulfillment_hub' => false];
        }
        foreach (range(0, 6) as $d) {
            $this->week[$d] ??= app(BranchService::class)->defaultHours()[$d];
        }
    }

    public function save(BranchService $svc)
    {
        $rules = collect(BranchRequest::rulesFor($this->branch?->id))->mapWithKeys(fn ($r, $k) => ['form.'.$k => $r])->all();
        $data = $this->validate($rules)['form'];
        $data = collect($data)->map(fn ($v) => $v === '' ? null : $v)->all();
        $acl = app(AccessControl::class);

        if ($this->branch) {
            $this->authorizeBranch('branches.edit', $this->branch->id);
            if (! $acl->allowsGlobally(auth()->user(), 'branches.edit')) {
                unset($data['status'], $data['code']); // company-level fields
            }
            $this->attempt(fn () => $svc->update($this->branch, $data), __('admin.saved'));
        } else {
            $data['hours'] = $this->week;
            $branch = $this->attempt(fn () => $svc->create($data));
            if ($branch) {
                session()->flash('toast', ['type' => 'success', 'message' => __('branches.created')]);

                return $this->redirectRoute('admin.branches.edit', $branch, navigate: true);
            }
        }
    }

    public function saveHours(BranchService $svc): void
    {
        $this->authorizeBranch('branches.edit', $this->branch->id);
        $this->validate([
            'week.*.opens_at' => 'nullable|date_format:H:i', 'week.*.closes_at' => 'nullable|date_format:H:i',
            'week.*.break_starts_at' => 'nullable|date_format:H:i', 'week.*.break_ends_at' => 'nullable|date_format:H:i',
        ]);
        $svc->setWeeklyHours($this->branch, $this->week);
        $this->toast(__('branches.hours_saved'));
    }

    public function copyHoursToAll(int $from): void
    {
        foreach (range(0, 6) as $d) {
            $this->week[$d] = $this->week[$from];
        }
    }

    public function addHoliday(BranchService $svc): void
    {
        $this->authorizeBranch('branches.edit', $this->branch->id);
        $data = $this->validate([
            'holiday.type' => 'required|in:holiday,temporary_closure', 'holiday.name_en' => 'required|string|max:120', 'holiday.name_ar' => 'required|string|max:120',
            'holiday.starts_at' => 'required|date', 'holiday.ends_at' => 'required|date|after:holiday.starts_at', 'holiday.reason' => 'nullable|string|max:500',
        ])['holiday'];
        // The form collects Qatar local date-times; persist as UTC.
        $data['starts_at'] = CarbonImmutable::parse($data['starts_at'], BusinessTime::zone())->utc();
        $data['ends_at'] = CarbonImmutable::parse($data['ends_at'], BusinessTime::zone())->utc();
        $svc->addHoliday($this->branch, $data);
        $this->reset('holiday');
        $this->toast(__('branches.holiday_added'));
    }

    public function removeHoliday(string $id, BranchService $svc): void
    {
        $this->authorizeBranch('branches.edit', $this->branch->id);
        $svc->removeHoliday(BranchHoliday::where('branch_id', $this->branch->id)->findOrFail($id));
        $this->toast(__('branches.holiday_removed'));
    }

    public function saveZone(): void
    {
        $this->authorizeBranch('zones.edit', $this->branch->id);
        $data = $this->validate([
            'zone.delivery_zone_id' => 'required|uuid|exists:delivery_zones,id', 'zone.delivery_fee' => 'required|numeric|min:0',
            'zone.free_delivery_threshold' => 'nullable|numeric|min:0', 'zone.min_order_amount' => 'nullable|numeric|min:0',
            'zone.max_order_amount' => 'nullable|numeric|min:0|gte:zone.min_order_amount', 'zone.cod_enabled' => 'boolean',
            'zone.estimated_minutes' => 'nullable|integer|min:0', 'zone.priority' => 'nullable|integer|min:0',
        ])['zone'];
        $data = collect($data)->map(fn ($v) => $v === '' ? null : $v)->all();
        BranchDeliveryZone::updateOrCreate(['branch_id' => $this->branch->id, 'delivery_zone_id' => $data['delivery_zone_id']], $data + ['is_active' => true]);
        app(AuditLogger::class)->log('branch.zone_configured', $this->branch, [], $data);
        $this->reset('zone');
        $this->toast(__('admin.saved'));
    }

    public function toggleZone(string $id): void
    {
        $this->authorizeBranch('zones.edit', $this->branch->id);
        $row = BranchDeliveryZone::where('branch_id', $this->branch->id)->findOrFail($id);
        $row->update(['is_active' => ! $row->is_active]);
    }

    public function addSlot(): void
    {
        $this->authorizeBranch('branches.edit', $this->branch->id);
        $data = $this->validate(['slot.fulfillment_type' => 'required|in:pickup,delivery', 'slot.day_of_week' => 'required|integer|between:0,6',
            'slot.starts_at' => 'required|date_format:H:i', 'slot.ends_at' => 'required|date_format:H:i|after:slot.starts_at', 'slot.capacity' => 'required|integer|min:1|max:1000'])['slot'];
        $this->branch->slots()->create($data);
        $this->toast(__('admin.saved'));
    }

    public function removeSlot(string $id): void
    {
        $this->authorizeBranch('branches.edit', $this->branch->id);
        DeliverySlot::where('branch_id', $this->branch->id)->findOrFail($id)->update(['is_active' => false]);
    }

    public function assignEmployee(EmployeeService $svc): void
    {
        $data = $this->validate(['assign.user_id' => 'required|uuid|exists:users,id', 'assign.roles' => 'array', 'assign.roles.*' => 'exists:roles,key',
            'assign.job_title' => 'nullable|string|max:120', 'assign.is_delivery_driver' => 'boolean'])['assign'];
        $employee = User::where('type', 'staff')->findOrFail($data['user_id']);
        if ($this->attempt(fn () => $svc->assignToBranch(auth()->user(), $employee, $this->branch, $data['roles'], $data['job_title'] ?: null, (bool) $data['is_delivery_driver']), __('branches.employee_assigned'))) {
            $this->reset('assign');
        }
    }

    public function toggleEmployee(string $assignmentId, EmployeeService $svc): void
    {
        $a = BranchEmployee::where('branch_id', $this->branch->id)->findOrFail($assignmentId);
        $this->attempt(fn () => $svc->setBranchAssignmentActive(auth()->user(), $a, ! $a->is_active), __('admin.saved'));
    }

    public function render()
    {
        $data = ['days' => collect(range(0, 6))->mapWithKeys(fn ($d) => [$d => Carbon::now()->startOfWeek(Carbon::SUNDAY)->addDays($d)->locale(app()->getLocale())->translatedFormat('l')])];
        if ($this->branch) {
            $this->branch->load(['holidays' => fn ($q) => $q->where('ends_at', '>', now()->subDays(30)), 'zoneConfigs.zone', 'slots' => fn ($q) => $q->where('is_active', true)->orderBy('day_of_week')->orderBy('starts_at'),
                'employeeAssignments.user.roleAssignments' => fn ($q) => $q->where('branch_id', $this->branch->id)->with('role')]);
            $data += [
                'status' => app(BranchHoursService::class)->status($this->branch->load('hours')),
                'zones' => DeliveryZone::where('is_active', true)->orderBy('name_en')->get(),
                'roles' => Role::where('scope', 'branch')->orderBy('name_en')->get(),
                'staff' => User::where('type', 'staff')->where('status', 'active')->whereNotIn('id', $this->branch->employeeAssignments->pluck('user_id'))->orderBy('name')->limit(200)->get(['id', 'name', 'email']),
                'canEdit' => $this->can('branches.edit', $this->branch->id),
                'canGlobal' => $this->canGlobally('branches.edit'),
            ];
        }

        return view('livewire.admin.branches.editor', $data)->title($this->branch ? $this->branch->localized('name') : __('branches.new'));
    }
}
