<?php

namespace App\Livewire\Admin\Sales;

use App\Domain\Branches\Models\BranchEmployee;
use App\Domain\Fulfillment\Models\DeliveryProvider;
use App\Domain\Fulfillment\Services\DeliveryService;
use App\Domain\Fulfillment\Services\PickupService;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessControl;
use App\Domain\Orders\Enums\FulfillmentStatus as FS;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderCancellation;
use App\Domain\Orders\Models\OrderFulfillment;
use App\Domain\Orders\Models\ReturnRequest;
use App\Domain\Orders\Services\OrderService;
use App\Domain\Payments\Models\Payment;
use App\Livewire\Admin\Concerns\InteractsWithAdmin;
use App\Support\BusinessTime;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Order workspace: customer, lines, per-branch fulfillments with only the valid next steps (state machine + permission),
 * payments, full timeline, internal notes, invoice, cancellation decisions, partial/full cancel and return requests.
 */
class OrderShow extends Component
{
    use InteractsWithAdmin;

    #[Locked]
    public string $orderId;

    public string $note = '';

    /** Dialog state: cancel_order | cancel_fulfillment | return_fulfillment | delivery | decide_cancellation */
    public ?string $dialog = null;

    #[Locked]
    public ?string $target = null;

    public array $form = [];

    public bool $showDialog = false;

    /** Steps a staff member may take directly from this screen; other transitions belong to pickup/delivery flows. */
    private const DIRECT = [FS::Accepted, FS::Preparing, FS::Ready];

    public function mount(Order $order): void
    {
        $acl = app(AccessControl::class);
        abort_unless(collect($order->branchIds())->contains(fn ($b) => $acl->allows(auth()->user(), 'orders.view', $b)), 403);
        $this->orderId = $order->id;
    }

    private function order(): Order
    {
        return Order::findOrFail($this->orderId);
    }

    private function fulfillment(string $id): OrderFulfillment
    {
        return OrderFulfillment::where('order_id', $this->orderId)->findOrFail($id);
    }

    public function transition(string $fulfillmentId, string $to, OrderService $svc): void
    {
        $f = $this->fulfillment($fulfillmentId);
        $target = FS::tryFrom($to);
        abort_unless($target && in_array($target, self::DIRECT, true), 422);
        $this->authorizeBranch('orders.edit', $f->branch_id);
        $this->attempt(fn () => $svc->transition($f, $target, auth()->user()), __('sales_admin.moved_to', ['status' => __('status.'.$target->value)]));
    }

    public function markPickupReady(string $fulfillmentId, PickupService $pickup): void
    {
        $f = $this->fulfillment($fulfillmentId);
        $this->authorizeBranch('pickup.edit', $f->branch_id);
        $this->attempt(fn () => $pickup->markReady($f, auth()->user()), __('sales_admin.pickup_ready_done'));
    }

    public function open(string $dialog, ?string $target = null): void
    {
        abort_unless(in_array($dialog, ['cancel_order', 'cancel_fulfillment', 'return_fulfillment', 'delivery', 'decide_cancellation'], true), 422);
        $this->resetValidation();
        $this->dialog = $dialog;
        $this->target = $target;
        $this->form = match ($dialog) {
            'delivery' => ['mode' => 'internal', 'provider_id' => '', 'driver_id' => '', 'scheduled_for' => ''],
            'decide_cancellation' => ['note' => ''],
            default => ['reason_code' => 'customer_request', 'reason' => ''],
        };
        $this->showDialog = true;
    }

    public function confirmDialog(OrderService $orders, DeliveryService $deliveries): void
    {
        match ($this->dialog) {
            'cancel_order' => $this->cancelOrder($orders),
            'cancel_fulfillment', 'return_fulfillment' => $this->closeFulfillment($orders),
            'delivery' => $this->arrangeDelivery($deliveries),
            default => abort(422),
        };
    }

    private function reasonRules(): array
    {
        return $this->validate([
            'form.reason_code' => 'required|in:customer_request,out_of_stock,payment_issue,fraud_suspected,duplicate_order,damaged,other',
            'form.reason' => 'required|string|min:3|max:500',
        ], [], ['form.reason' => __('admin.reason'), 'form.reason_code' => __('admin.reason')])['form'];
    }

    private function cancelOrder(OrderService $orders): void
    {
        $data = $this->reasonRules();
        $order = $this->order()->load('fulfillments');
        foreach ($order->fulfillments as $f) {
            $this->authorizeBranch('orders.approve', $f->branch_id);
        }
        if ($this->attempt(fn () => $orders->cancelOrder($order, auth()->user(), $data['reason_code'], $data['reason']), __('sales_admin.order_cancelled'))) {
            $this->showDialog = false;
        }
    }

    private function closeFulfillment(OrderService $orders): void
    {
        $data = $this->reasonRules();
        $f = $this->fulfillment((string) $this->target);
        $to = $this->dialog === 'cancel_fulfillment' ? FS::Cancelled : FS::Returned;
        $this->authorizeBranch($to === FS::Cancelled ? 'orders.approve' : 'orders.edit', $f->branch_id);
        $msg = $to === FS::Cancelled ? __('sales_admin.fulfillment_cancelled') : __('sales_admin.fulfillment_returned');
        if ($this->attempt(fn () => $orders->transition($f, $to, auth()->user(), $data['reason_code'], ['reason' => $data['reason']]), $msg)) {
            $this->showDialog = false;
        }
    }

    private function arrangeDelivery(DeliveryService $deliveries): void
    {
        $f = $this->fulfillment((string) $this->target);
        $this->authorizeBranch('deliveries.create', $f->branch_id);
        $data = $this->validate([
            'form.mode' => 'required|in:internal,provider',
            'form.provider_id' => 'required_if:form.mode,provider|nullable|uuid|exists:delivery_providers,id',
            'form.driver_id' => 'nullable|uuid|exists:users,id',
            'form.scheduled_for' => 'nullable|date|after:now',
        ], [], ['form.provider_id' => __('sales_admin.provider'), 'form.driver_id' => __('sales_admin.driver'), 'form.scheduled_for' => __('sales_admin.scheduled_for')])['form'];
        $u = auth()->user();
        $ok = $this->attempt(function () use ($deliveries, $f, $data, $u) {
            $d = $deliveries->create($f, $u, $data['mode'], $data['provider_id'] ?: null, $data['scheduled_for'] ?: null);
            if ($data['mode'] === 'internal' && $data['driver_id']) {
                $deliveries->assignDriver($d, User::findOrFail($data['driver_id']), $u);
            }

            return $d;
        }, __('sales_admin.delivery_created'));
        if ($ok) {
            $this->showDialog = false;
        }
    }

    public function decideCancellation(string $cancellationId, bool $approve, OrderService $orders): void
    {
        $c = OrderCancellation::where('order_id', $this->orderId)->findOrFail($cancellationId);
        foreach ($this->order()->branchIds() as $b) {
            $this->authorizeBranch('orders.approve', $b);
        }
        $note = trim((string) ($this->form['note'] ?? '')) ?: null;
        if ($this->attempt(fn () => $orders->decideCancellation($c, auth()->user(), $approve, $note), $approve ? __('sales_admin.cancellation_approved') : __('sales_admin.cancellation_rejected'))) {
            $this->form = [];
        }
    }

    public function addNote(OrderService $orders): void
    {
        $this->validate(['note' => 'required|string|min:2|max:2000'], [], ['note' => __('admin.notes')]);
        if ($this->attempt(fn () => $orders->addNote($this->order(), auth()->user(), trim($this->note)), __('sales_admin.note_added'))) {
            $this->reset('note');
        }
    }

    public function render()
    {
        $o = Order::with(['items', 'fulfillments.branch', 'fulfillments.items', 'fulfillments.pickup', 'fulfillments.deliveries.driver:id,name', 'fulfillments.deliveries.provider:id,name',
            'notes.author:id,name', 'cancellations.requester:id,name', 'coupons', 'user:id,name,phone,email,status'])->findOrFail($this->orderId);
        $u = auth()->user();
        $acl = app(AccessControl::class);
        $at = fn (string $perm, string $branch) => $acl->allows($u, $perm, $branch);

        $actions = [];
        foreach ($o->fulfillments as $f) {
            $next = collect($f->status->allowedNext());
            $a = [];
            if ($at('orders.edit', $f->branch_id) && $o->status->value !== 'pending_payment') {
                foreach (self::DIRECT as $step) {
                    if ($next->contains($step) && ! ($step === FS::Ready && $f->type === 'pickup')) {
                        $a[] = ['action' => 'transition', 'to' => $step->value];
                    }
                }
            }
            if ($f->type === 'pickup' && $next->contains(FS::PickupReady) && $at('pickup.edit', $f->branch_id) && $o->status->value !== 'pending_payment') {
                $a[] = ['action' => 'pickup_ready'];
            }
            $active = $f->activeDelivery();
            if ($f->type === 'delivery' && $f->status === FS::Ready && (! $active || ! $active->status->isActive()) && $at('deliveries.create', $f->branch_id)) {
                $a[] = ['action' => 'delivery'];
            }
            $actions[$f->id] = [
                'steps' => $a,
                'cancel' => $next->contains(FS::Cancelled) && $at('orders.approve', $f->branch_id) && $o->fulfillments->count() > 1,
                'return' => in_array($f->status, [FS::Delivered, FS::PickedUp], true) && $at('orders.edit', $f->branch_id),
                'delivery' => $active,
                'scan' => $f->type === 'pickup' && in_array($f->pickup?->status, ['ready', 'partially_collected'], true) && $at('pickup.approve', $f->branch_id),
            ];
        }
        $cancellable = $o->fulfillments->contains(fn ($f) => $f->status->canTransitionTo(FS::Cancelled))
            && $o->fulfillments->every(fn ($f) => $at('orders.approve', $f->branch_id));
        $pendingCancellation = $o->cancellations->firstWhere('status', 'requested');

        $people = User::whereIn('id', $o->history->pluck('actor_id')->filter()->unique())->pluck('name', 'id');
        $timeline = $o->history->reverse()->values()->map(fn ($h) => [
            'label' => $this->historyLabel($h->kind, $h->to_status, $o->fulfillments->firstWhere('id', $h->order_fulfillment_id)?->branch?->code),
            'at' => BusinessTime::display($h->created_at),
            'by' => $h->actor_id ? ($people[$h->actor_id] ?? null) : ($h->actor_type === 'system' ? __('sales_admin.system') : null),
            'reason' => $h->reason,
            'internal' => ! $h->is_customer_visible,
            'state' => in_array($h->to_status, ['cancelled', 'failed', 'expired', 'rejected'], true) ? 'failed' : 'done',
        ])->all();

        $drivers = $actions && collect($actions)->contains(fn ($a) => collect($a['steps'])->contains('action', 'delivery'))
            ? User::whereIn('id', BranchEmployee::whereIn('branch_id', $o->fulfillments->pluck('branch_id'))->where('is_active', true)->where('is_delivery_driver', true)->select('user_id'))->where('status', 'active')->orderBy('name')->get(['id', 'name'])
            : collect();

        return view('livewire.admin.sales.order-show', [
            'o' => $o,
            'actions' => $actions,
            'cancellable' => $cancellable && ! $o->status->isTerminal(),
            'pendingCancellation' => $pendingCancellation,
            'canDecide' => $pendingCancellation && $o->fulfillments->every(fn ($f) => $at('orders.approve', $f->branch_id)),
            'payments' => Payment::where('order_id', $o->id)->with(['refunds', 'collections.collector:id,name'])->orderByDesc('attempt')->get(),
            'returns' => ReturnRequest::where('order_id', $o->id)->latest()->get(),
            'timeline' => $timeline,
            'drivers' => $drivers,
            'providers' => DeliveryProvider::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'canPayments' => $this->can('payments.view'),
            'canCustomer' => $o->user_id && $this->can('customers.view'),
        ])->title($o->number);
    }

    private function historyLabel(string $kind, string $to, ?string $branch): string
    {
        $status = \Illuminate\Support\Facades\Lang::has('status.'.$to) ? __('status.'.$to) : \Illuminate\Support\Str::headline($to);

        return match ($kind) {
            'payment' => __('sales_admin.hist_payment', ['status' => $status]),
            'fulfillment' => __('sales_admin.hist_fulfillment', ['branch' => $branch ?? '—', 'status' => $status]),
            'return' => __('sales_admin.hist_return', ['status' => $status]),
            default => __('sales_admin.hist_order', ['status' => $status]),
        };
    }
}
