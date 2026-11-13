<?php

namespace App\Livewire\Admin\Sales;

use App\Domain\Checkout\Services\StaffOrderService;
use Livewire\Attributes\On;
use Livewire\Component;

/** Header / sidebar launcher of the order cart panel, with a live unit count (one cheap query; mounted lazily in the layout). */
class OrderCartButton extends Component
{
    public string $variant = 'header';

    public function placeholder(): string
    {
        return '<div class="size-11" aria-hidden="true"></div>';
    }

    #[On('order-cart-updated')]
    public function refresh(): void
    {
        // re-render only
    }

    public function render()
    {
        $svc = app(StaffOrderService::class);
        $user = auth()->user();

        return view('livewire.admin.sales.order-cart-button', ['count' => $svc->canCreate($user) ? $svc->count($user) : 0, 'can' => $svc->canCreate($user)]);
    }
}
