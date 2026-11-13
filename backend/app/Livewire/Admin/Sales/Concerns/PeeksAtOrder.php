<?php

namespace App\Livewire\Admin\Sales\Concerns;

use App\Domain\Orders\Models\Order;
use App\Livewire\Admin\Sales\OrderShow;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;

/**
 * Lets a list screen open an order in a side window (the order page's content in compact mode) without leaving the list.
 * Pair it with partials/order-peek.blade.php. The window shows exactly what the order page would: OrderShow applies the same
 * branch gate, and every action inside it keeps its own permission, audit trail and confirm dialog.
 */
trait PeeksAtOrder
{
    public bool $showOrder = false;

    /** The order in the window. Kept after closing so the window slides out with its content instead of emptying first. */
    #[Locked]
    public string $orderPeekId = '';

    /** Bumped on every open so the nested order component is rebuilt with fresh data. */
    #[Locked]
    public int $orderPeekSeq = 0;

    /** @param bool $quiet true for a deep link on page load: an order the viewer may not see simply does not open (no toast). */
    public function peekOrder(string $id, bool $quiet = false): void
    {
        $order = Str::isUuid($id) ? Order::find($id) : null;
        if (! $order || ! OrderShow::viewableBy(auth()->user(), $order)) {
            $this->orderPeekId = '';
            $this->showOrder = false;
            if (! $quiet) {
                $this->toast(__('drawer_sales.not_available'), 'error');
            }

            return;
        }
        $this->orderPeekId = $order->id;
        $this->orderPeekSeq++;
        $this->showOrder = true;
    }

    public function closeOrderPeek(): void
    {
        $this->showOrder = false;
    }

    /** Another side window (a customer's, a payment's) asks to show one of its orders on top of itself. */
    #[On('peek-order')]
    public function onPeekOrder(string $id): void
    {
        $this->peekOrder($id);
    }

    /** An action inside the window changed the order: the list re-renders (this method only exists to be the listener). */
    #[On('record-changed')]
    public function onRecordChanged(): void {}

    /** The order for the window, or null when none is open or the viewer may not see it (never trusts the stored id blindly). */
    #[Computed]
    public function orderPeek(): ?Order
    {
        if ($this->orderPeekId === '') {
            return null;
        }
        $order = Str::isUuid($this->orderPeekId) ? Order::find($this->orderPeekId) : null;

        return $order && OrderShow::viewableBy(auth()->user(), $order) ? $order : null;
    }
}
