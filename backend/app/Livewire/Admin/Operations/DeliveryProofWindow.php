<?php

namespace App\Livewire\Admin\Operations;

use App\Domain\Fulfillment\Models\Delivery;
use App\Livewire\Admin\Concerns\InteractsWithAdmin;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The proof of a delivery in a side window, from any list that has a delivery on it. Mount it once on the page (`<livewire:admin.operations.delivery-proof-window />`),
 * then any button can open it: `wire:click="$dispatch('open-delivery-proof', { delivery: '{{ $d->id }}' })"`. The window shows exactly what
 * the proof card shows (see <x-admin.sales.delivery-proof>): deliveries.view at the delivery's branch, otherwise it does not open.
 */
class DeliveryProofWindow extends Component
{
    use InteractsWithAdmin;

    public bool $open = false;

    /** The delivery in the window. Kept after closing so the window slides out with its content. */
    #[Locked]
    public string $deliveryId = '';

    #[On('open-delivery-proof')]
    public function show(string $delivery): void
    {
        $d = Str::isUuid($delivery) ? Delivery::query()->find($delivery, ['id', 'branch_id']) : null;
        if ($d === null || ! $this->can('deliveries.view', $d->branch_id)) {
            $this->toast(__('ops_live.proof.unavailable'), 'error');

            return;
        }
        $this->deliveryId = $d->id;
        $this->open = true;
    }

    public function render()
    {
        $delivery = $this->deliveryId !== '' && Str::isUuid($this->deliveryId) ? Delivery::query()->find($this->deliveryId) : null;

        return view('livewire.admin.operations.delivery-proof-window', [
            'delivery' => $delivery !== null && $this->can('deliveries.view', $delivery->branch_id) ? $delivery : null,
        ]);
    }
}
