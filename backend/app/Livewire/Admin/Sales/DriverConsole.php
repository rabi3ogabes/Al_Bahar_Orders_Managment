<?php

namespace App\Livewire\Admin\Sales;

use App\Domain\Branches\Models\BranchEmployee;
use App\Domain\Fulfillment\Models\Delivery;
use App\Domain\Fulfillment\Services\DeliveryService;
use App\Domain\Payments\Services\CashDrawerService;
use App\Livewire\Admin\Concerns\InteractsWithAdmin;
use App\Support\Money;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Phone-first driver view: today's assigned deliveries with accept → picked up → out for delivery → delivered
 * (photo proof + COD collection) or failed. Only active delivery drivers holding deliveries.edit may open it, and
 * every action is re-authorized by DeliveryService (driver may act only on deliveries assigned to them).
 */
#[Layout('layouts.driver')]
class DriverConsole extends Component
{
    use InteractsWithAdmin, WithFileUploads;

    #[Locked]
    public ?string $activeId = null;

    /** deliver | fail */
    public ?string $sheet = null;

    public string $recipient = '';

    public $proof = null;

    public array $cod = ['tender' => 'cash', 'amount' => '', 'receipt_reference' => ''];

    public string $failReason = 'customer_unreachable';

    public string $failNotes = '';

    public function mount(): void
    {
        abort_unless($this->can('deliveries.edit'), 403);
        abort_unless(BranchEmployee::where('user_id', auth()->id())->where('is_active', true)->where('is_delivery_driver', true)->exists(), 403);
    }

    private function mine(string $id): Delivery
    {
        return Delivery::where('driver_id', auth()->id())->findOrFail($id);
    }

    public function act(string $id, string $action, DeliveryService $svc): void
    {
        abort_unless(in_array($action, ['accept', 'pickup', 'out'], true), 422);
        $d = $this->mine($id);
        $this->authorizeBranch('deliveries.edit', $d->branch_id);
        $u = auth()->user();
        match ($action) {
            'accept' => $this->attempt(fn () => $svc->accept($d, $u), __('sales_admin.driver_accepted')),
            'pickup' => $this->attempt(fn () => $svc->pickUp($d, $u), __('sales_admin.delivery_picked_up')),
            'out' => $this->attempt(fn () => $svc->outForDelivery($d, $u), __('sales_admin.delivery_out')),
        };
    }

    public function openSheet(string $id, string $sheet): void
    {
        abort_unless(in_array($sheet, ['deliver', 'fail'], true), 422);
        $d = $this->mine($id)->load('fulfillment.order');
        $this->resetValidation();
        $this->reset('recipient', 'proof', 'failNotes');
        $this->failReason = 'customer_unreachable';
        $this->activeId = $d->id;
        $this->sheet = $sheet;
        $o = $d->fulfillment->order;
        $this->recipient = $o->customer_name;
        $this->cod = ['tender' => 'cash', 'amount' => Money::of($o->grand_total)->minus(Money::of($o->paid_total))->toDecimal(), 'receipt_reference' => ''];
    }

    public function closeSheet(): void
    {
        $this->reset('activeId', 'sheet', 'proof');
    }

    public function deliver(DeliveryService $svc): void
    {
        $d = $this->mine((string) $this->activeId)->load('fulfillment.order');
        $this->authorizeBranch('deliveries.edit', $d->branch_id);
        $o = $d->fulfillment->order;
        $needsCod = $o->payment_method === 'cod' && ! $o->payment_status->isSettled();
        $this->validate([
            'recipient' => 'required|string|min:2|max:120',
            'proof' => ($d->requires_proof && ! $d->proofs()->exists() ? 'required' : 'nullable').'|image|mimes:jpg,jpeg,png,webp|max:8192',
            'cod.tender' => $needsCod ? 'required|in:cash,card_terminal' : 'nullable',
            'cod.amount' => $needsCod ? 'required|numeric|min:0' : 'nullable',
            'cod.receipt_reference' => 'nullable|string|max:120',
        ], [], ['recipient' => __('sales_admin.recipient'), 'proof' => __('sales_admin.proof_photo'), 'cod.amount' => __('sales_admin.amount_received')]);
        $cod = $needsCod ? ['tender' => $this->cod['tender'], 'amount' => (string) $this->cod['amount'], 'receipt_reference' => $this->cod['receipt_reference'] ?: null] : null;
        if ($this->attempt(fn () => $svc->deliver($d, auth()->user(), trim($this->recipient), $this->proof, 'photo', $cod), __('sales_admin.delivered_done'))) {
            $this->closeSheet();
        }
    }

    public function fail(DeliveryService $svc): void
    {
        $d = $this->mine((string) $this->activeId);
        $this->authorizeBranch('deliveries.edit', $d->branch_id);
        $this->validate(['failReason' => 'required|in:'.implode(',', DeliveryIndex::FAIL_REASONS), 'failNotes' => 'nullable|string|max:500']);
        if ($this->attempt(fn () => $svc->fail($d, auth()->user(), $this->failReason, trim($this->failNotes) ?: null), __('sales_admin.delivery_failed'))) {
            $this->closeSheet();
        }
    }

    public function render()
    {
        $base = Delivery::where('driver_id', auth()->id())
            ->with(['fulfillment.order:id,number,customer_name,customer_phone,payment_method,payment_status,grand_total,paid_total,address_snapshot,customer_notes', 'fulfillment.items:id,order_fulfillment_id,name_en,name_ar,quantity,quantity_cancelled', 'branch:id,code,name_en,name_ar,latitude,longitude']);
        $active = (clone $base)->whereNotIn('status', ['delivered', 'returned', 'cancelled', 'returning'])
            ->where(fn ($q) => $q->whereNull('scheduled_for')->orWhere('scheduled_for', '<', now()->endOfDay()))
            ->orderByRaw("CASE status WHEN 'out_for_delivery' THEN 0 WHEN 'picked_up' THEN 1 WHEN 'accepted' THEN 2 WHEN 'assigned' THEN 3 ELSE 4 END")
            ->orderBy('scheduled_for')->get();
        $later = (clone $base)->whereNotIn('status', ['delivered', 'returned', 'cancelled'])->where('scheduled_for', '>=', now()->endOfDay())->count();
        $done = (clone $base)->whereIn('status', ['delivered', 'failed'])->where('updated_at', '>=', now()->startOfDay())->latest('updated_at')->get();

        return view('livewire.admin.sales.driver', [
            'active' => $active,
            'done' => $done,
            'later' => $later,
            'current' => $this->activeId ? $active->firstWhere('id', $this->activeId) : null,
            'codTotal' => $done->where('status.value', 'delivered')->sum(fn ($d) => (float) $d->cod_amount),
            // Cash collected and not yet handed to the branch (all days): the driver must settle this with a cashier.
            'cashToHandIn' => collect(app(CashDrawerService::class)->balancesFor(auth()->user()))->reduce(fn ($c, $b) => $c->plus($b['amount']), Money::zero()),
        ])->title(__('nav.driver'));
    }
}
