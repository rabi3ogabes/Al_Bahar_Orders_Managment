<?php

namespace App\Livewire\Admin\Sales;

use App\Domain\Branches\Models\BranchEmployee;
use App\Domain\Fulfillment\Models\Delivery;
use App\Domain\Fulfillment\Models\DeliveryProvider;
use App\Domain\Fulfillment\Services\DeliveryService;
use App\Domain\Identity\Models\User;
use App\Domain\Orders\Models\OrderFulfillment;
use App\Livewire\Admin\Concerns\WithAdminTable;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Dispatch board: fulfillments to dispatch, active deliveries and exceptions, with assign/provider/reschedule/fail/return actions. */
class DeliveryIndex extends Component
{
    use WithAdminTable;

    public const LANES = ['to_dispatch', 'scheduled', 'on_road', 'exceptions', 'done'];

    public const FAIL_REASONS = ['customer_unreachable', 'customer_absent', 'wrong_address', 'refused', 'damaged', 'access_denied', 'other'];

    #[Url(except: 'to_dispatch')]
    public string $lane = 'to_dispatch';

    public bool $showDialog = false;

    /** create | assign | reschedule | fail | cancel */
    public ?string $dialog = null;

    #[Locked]
    public ?string $target = null;

    public array $form = [];

    public function mount(): void
    {
        abort_unless($this->can('deliveries.view'), 403);
        if (! in_array($this->lane, self::LANES, true)) {
            $this->lane = 'to_dispatch';
        }
    }

    public function updatedLane(): void
    {
        $this->resetPage();
    }

    public function open(string $dialog, string $target): void
    {
        abort_unless(in_array($dialog, ['create', 'assign', 'reschedule', 'fail', 'cancel'], true), 422);
        $branch = $dialog === 'create' ? OrderFulfillment::findOrFail($target)->branch_id : Delivery::findOrFail($target)->branch_id;
        $this->authorizeBranch(match ($dialog) { 'create' => 'deliveries.create', 'assign', 'reschedule', 'fail' => 'deliveries.assign', default => 'deliveries.edit' }, $branch);
        $this->resetValidation();
        $this->dialog = $dialog;
        $this->target = $target;
        $this->form = ['mode' => 'internal', 'provider_id' => '', 'driver_id' => '', 'scheduled_for' => '', 'reason_code' => 'customer_unreachable', 'notes' => '', 'reason' => ''];
        $this->showDialog = true;
    }

    public function confirmDialog(DeliveryService $svc): void
    {
        $u = auth()->user();
        $ok = match ($this->dialog) {
            'create' => $this->createDelivery($svc),
            'assign' => $this->withDelivery('deliveries.assign', function (Delivery $d) use ($svc, $u) {
                $id = $this->validate(['form.driver_id' => 'required|uuid|exists:users,id'], [], ['form.driver_id' => __('sales_admin.driver')])['form']['driver_id'];

                return $this->attempt(fn () => $svc->assignDriver($d, User::findOrFail($id), $u), __('sales_admin.driver_assigned'));
            }),
            'reschedule' => $this->withDelivery('deliveries.assign', function (Delivery $d) use ($svc, $u) {
                $when = $this->validate(['form.scheduled_for' => 'required|date|after:now'], [], ['form.scheduled_for' => __('sales_admin.scheduled_for')])['form']['scheduled_for'];

                return $this->attempt(fn () => $svc->reschedule($d, $u, $when), __('sales_admin.delivery_rescheduled'));
            }),
            'fail' => $this->withDelivery('deliveries.assign', function (Delivery $d) use ($svc, $u) {
                $f = $this->validate(['form.reason_code' => 'required|in:'.implode(',', self::FAIL_REASONS), 'form.notes' => 'nullable|string|max:500'])['form'];

                return $this->attempt(fn () => $svc->fail($d, $u, $f['reason_code'], $f['notes'] ?: null), __('sales_admin.delivery_failed'));
            }),
            'cancel' => $this->withDelivery('deliveries.edit', function (Delivery $d) use ($svc, $u) {
                $reason = $this->validate(['form.reason' => 'required|string|min:3|max:500'], [], ['form.reason' => __('admin.reason')])['form']['reason'];

                return $this->attempt(fn () => $svc->cancel($d, $u, $reason), __('sales_admin.delivery_cancelled'));
            }),
            default => abort(422),
        };
        if ($ok) {
            $this->showDialog = false;
        }
    }

    private function createDelivery(DeliveryService $svc): mixed
    {
        $f = OrderFulfillment::where('type', 'delivery')->findOrFail($this->target);
        $this->authorizeBranch('deliveries.create', $f->branch_id);
        $data = $this->validate([
            'form.mode' => 'required|in:internal,provider',
            'form.provider_id' => 'required_if:form.mode,provider|nullable|uuid|exists:delivery_providers,id',
            'form.driver_id' => 'nullable|uuid|exists:users,id',
            'form.scheduled_for' => 'nullable|date|after:now',
        ], [], ['form.provider_id' => __('sales_admin.provider'), 'form.driver_id' => __('sales_admin.driver'), 'form.scheduled_for' => __('sales_admin.scheduled_for')])['form'];
        $u = auth()->user();

        return $this->attempt(function () use ($svc, $f, $data, $u) {
            $d = $svc->create($f, $u, $data['mode'], $data['provider_id'] ?: null, $data['scheduled_for'] ?: null);
            if ($data['mode'] === 'internal' && $data['driver_id']) {
                $d = $svc->assignDriver($d, User::findOrFail($data['driver_id']), $u);
            }

            return $d;
        }, __('sales_admin.delivery_created'));
    }

    private function withDelivery(string $permission, callable $fn): mixed
    {
        $d = Delivery::findOrFail($this->target);
        $this->authorizeBranch($permission, $d->branch_id);

        return $fn($d);
    }

    /** One-click steps on an existing delivery. */
    public function act(string $deliveryId, string $action, DeliveryService $svc): void
    {
        abort_unless(in_array($action, ['pickup', 'out', 'return', 'retry'], true), 422);
        $d = Delivery::findOrFail($deliveryId);
        $this->authorizeBranch(match ($action) { 'return', 'retry' => 'deliveries.edit', default => 'deliveries.assign' }, $d->branch_id);
        $u = auth()->user();
        match ($action) {
            'pickup' => $this->attempt(fn () => $svc->pickUp($d, $u), __('sales_admin.delivery_picked_up')),
            'out' => $this->attempt(fn () => $svc->outForDelivery($d, $u), __('sales_admin.delivery_out')),
            'return' => $this->attempt(fn () => $svc->returnToBranch($d, $u), __('sales_admin.delivery_returned')),
            'retry' => $this->attempt(fn () => $svc->book($d), __('sales_admin.booking_retried')),
        };
    }

    private function laneQuery(string $lane, $scope)
    {
        if ($lane === 'to_dispatch') {
            return tap(OrderFulfillment::query()->where('type', 'delivery')->whereIn('status', ['ready', 'preparing'])
                ->whereDoesntHave('deliveries', fn ($q) => $q->whereNotIn('status', ['cancelled', 'returned'])), fn ($q) => $scope->apply($q->getQuery(), 'branch_id'));
        }
        $statuses = match ($lane) {
            'scheduled' => ['pending', 'assigned', 'accepted'],
            'on_road' => ['picked_up', 'out_for_delivery'],
            'exceptions' => ['failed', 'rescheduled', 'returning'],
            default => ['delivered', 'returned', 'cancelled'],
        };

        return tap(Delivery::query()->whereIn('status', $statuses)->when($lane === 'done', fn ($q) => $q->where('updated_at', '>=', now()->subDay())),
            fn ($q) => $scope->apply($q->getQuery(), 'branch_id'));
    }

    public function render()
    {
        $scope = $this->branchScope('deliveries.view');
        $s = trim($this->search);
        $counts = collect(self::LANES)->mapWithKeys(fn ($l) => [$l => $l === 'done' ? null : $this->laneQuery($l, $scope)->count()])->all();

        $q = $this->laneQuery($this->lane, $scope);
        if ($this->lane === 'to_dispatch') {
            $rows = $q->with(['order:id,number,customer_name,customer_phone,payment_method,payment_status,grand_total,address_snapshot', 'branch:id,code,name_en,name_ar', 'slot'])
                ->when($s, fn ($q) => $q->whereHas('order', fn ($o) => $o->where('number', 'ilike', "%{$s}%")->orWhere('customer_name', 'ilike', "%{$s}%")))
                ->orderByRaw("CASE WHEN status = 'ready' THEN 0 ELSE 1 END")->orderBy('slot_date')->paginate($this->perPage);
        } else {
            $rows = $q->with(['fulfillment.order:id,number,customer_name,customer_phone,payment_method,payment_status,grand_total,address_snapshot', 'branch:id,code,name_en,name_ar', 'driver:id,name', 'provider:id,name'])
                ->when($s, fn ($q) => $q->where(fn ($w) => $w->where('tracking_number', 'ilike', "%{$s}%")->orWhereHas('fulfillment.order', fn ($o) => $o->where('number', 'ilike', "%{$s}%")->orWhere('customer_name', 'ilike', "%{$s}%"))))
                ->orderByRaw('scheduled_for IS NULL')->orderBy('scheduled_for')->latest('updated_at')->paginate($this->perPage);
        }

        $drivers = $this->showDialog && in_array($this->dialog, ['create', 'assign'], true)
            ? User::whereIn('id', BranchEmployee::where('is_active', true)->where('is_delivery_driver', true)
                ->where('branch_id', $this->dialog === 'create' ? OrderFulfillment::find($this->target)?->branch_id : Delivery::find($this->target)?->branch_id)->select('user_id'))
                ->where('status', 'active')->orderBy('name')->get(['id', 'name'])
            : collect();

        return view('livewire.admin.sales.deliveries', [
            'rows' => $rows,
            'counts' => $counts,
            'branches' => $this->scopedBranches('deliveries.view'),
            'drivers' => $drivers,
            'providers' => DeliveryProvider::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'isDriver' => BranchEmployee::where('user_id', auth()->id())->where('is_active', true)->where('is_delivery_driver', true)->exists(),
        ])->title(__('nav.deliveries'));
    }
}
