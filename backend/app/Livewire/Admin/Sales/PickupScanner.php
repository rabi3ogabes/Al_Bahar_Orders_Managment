<?php

namespace App\Livewire\Admin\Sales;

use App\Domain\Fulfillment\Models\PickupReservation;
use App\Domain\Fulfillment\Services\PickupService;
use App\Domain\Payments\Models\Payment;
use App\Livewire\Admin\Concerns\InteractsWithAdmin;
use App\Support\Money;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Counter scanner: camera QR (html5-qrcode) or typed code → PickupService::verify at the selected branch → handover
 * (all or part), with pay-at-pickup collection. The verified reservation id is locked server-side so it cannot be swapped.
 */
class PickupScanner extends Component
{
    use InteractsWithAdmin;

    #[Url(as: 'branch', except: '')]
    public string $branchId = '';

    public string $code = '';

    /** success | invalid_token | expired | already_used | wrong_branch | not_ready */
    public ?string $result = null;

    #[Locked]
    public ?string $reservationId = null;

    /** order item id => qty to hand over now */
    public array $handover = [];

    public string $collectorName = '';

    public bool $alternate = false;

    public array $collection = ['tender' => 'cash', 'amount' => '', 'receipt_reference' => ''];

    public bool $completed = false;

    public function mount(): void
    {
        abort_unless($this->can('pickup.view'), 403);
        $branches = $this->branches();
        if (! $this->branchId || ! $branches->contains('id', $this->branchId)) {
            $this->branchId = $branches->count() === 1 ? $branches->first()->id : '';
        }
    }

    private function branches()
    {
        $scope = app(\App\Domain\Identity\Services\AccessControl::class)->scope(auth()->user(), 'pickup.view');

        return \App\Domain\Branches\Models\Branch::query()->when(! $scope->all, fn ($q) => $q->whereIn('id', $scope->branchIds))->orderBy('sort_order')->get(['id', 'code', 'name_en', 'name_ar']);
    }

    public function updatedBranchId(): void
    {
        $this->resetScan();
    }

    public function verify(PickupService $pickup): void
    {
        $code = trim($this->code);
        $this->validate(['branchId' => 'required|uuid'], [], ['branchId' => __('admin.branch')]);
        if ($code === '' || strlen($code) > 200) {
            $this->addError('code', __('sales_admin.code_required'));

            return;
        }
        $this->authorizeBranch('pickup.view', $this->branchId);
        $res = $this->attempt(fn () => $pickup->verify($code, auth()->user(), $this->branchId));
        if (! is_array($res)) {
            return;
        }
        $this->code = '';
        $this->result = $res['result'];
        $this->completed = false;
        if ($res['result'] !== 'success') {
            $this->reservationId = null;
            $this->dispatch('pickup-scan-result', ok: false);

            return;
        }
        $this->reservationId = $res['reservation']->id;
        $f = $res['fulfillment'];
        $this->handover = $f->items->mapWithKeys(fn ($i) => [$i->id => max(0, $i->activeQuantity() - $i->quantity_handed_over)])->all();
        $order = $f->order;
        $this->collectorName = $order->customer_name;
        $this->alternate = false;
        $this->collection = ['tender' => 'cash', 'amount' => Money::of($order->grand_total)->minus(Money::of($order->paid_total))->toDecimal(), 'receipt_reference' => ''];
        $this->dispatch('pickup-scan-result', ok: true);
    }

    public function confirmHandover(PickupService $pickup): void
    {
        abort_unless($this->reservationId, 422);
        $r = PickupReservation::with('fulfillment.order')->findOrFail($this->reservationId);
        abort_unless($r->branch_id === $this->branchId, 403);
        $this->authorizeBranch('pickup.approve', $r->branch_id);
        $this->validate([
            'handover' => 'array', 'handover.*' => 'required|integer|min:0|max:100000',
            'collectorName' => 'required|string|min:2|max:120',
            'collection.tender' => 'in:cash,card_terminal', 'collection.amount' => 'nullable|numeric|min:0', 'collection.receipt_reference' => 'nullable|string|max:120',
        ], [], ['collectorName' => __('sales_admin.collector_name'), 'handover.*' => __('admin.quantity'), 'collection.amount' => __('sales_admin.amount_received')]);

        $order = $r->fulfillment->order;
        $needsPayment = $order->payment_method === 'pay_at_pickup' && ! $order->payment_status->isSettled();
        $items = array_map('intval', $this->handover);
        if (array_sum($items) === 0) {
            $this->addError('handover', __('sales_admin.nothing_to_hand_over'));

            return;
        }
        $collection = $needsPayment ? ['tender' => $this->collection['tender'], 'amount' => (string) $this->collection['amount'], 'receipt_reference' => $this->collection['receipt_reference'] ?: null] : null;
        $done = $this->attempt(fn () => $pickup->handover($r, auth()->user(), $items, trim($this->collectorName), $this->alternate, $collection));
        if ($done instanceof PickupReservation) {
            $this->completed = true;
            $this->toast($done->status === 'collected' ? __('sales_admin.handover_complete') : __('sales_admin.handover_partial'));
        }
    }

    public function resetScan(): void
    {
        $this->reset('code', 'result', 'reservationId', 'handover', 'collectorName', 'alternate', 'collection', 'completed');
        $this->resetValidation();
    }

    public function render()
    {
        $r = $this->reservationId ? PickupReservation::with(['fulfillment.items', 'fulfillment.order', 'branch'])->find($this->reservationId) : null;
        $order = $r?->fulfillment->order;

        return view('livewire.admin.sales.pickup-scanner', [
            'branches' => $this->branches(),
            'r' => $r,
            'order' => $order,
            'due' => $order ? Money::of($order->grand_total)->minus(Money::of($order->paid_total)) : null,
            'needsPayment' => $order && $order->payment_method === 'pay_at_pickup' && ! $order->payment_status->isSettled(),
            'awaitingOnline' => $order && $order->payment_method === 'sadad' && ! $order->payment_status->isSettled(),
            'alternateAllowed' => (bool) config('platform.pickup.alternate_collector_enabled'),
            'payment' => $order ? Payment::where('order_id', $order->id)->latest('attempt')->first() : null,
        ])->title(__('sales_admin.scanner_title'));
    }
}
