{{-- Order side window for a list screen using PeeksAtOrder. Include once, anywhere inside the list's root element. --}}
@php $peek = $this->orderPeek; @endphp
<x-admin.dialog wire:model="showOrder" :title="$peek?->number" max-width="max-w-3xl">
    @if ($peek)
        <x-admin.drawer-bar close="closeOrderPeek" :url="route('admin.orders.show', $peek)">
            <x-admin.status :value="$peek->status->value" />
            <x-admin.status :value="$peek->payment_status->value" />
            <span class="badge-neutral">{{ __('status.'.$peek->payment_method) }}</span>
            <span class="tabular-nums">{{ __('sales_admin.placed_at', ['at' => \App\Support\BusinessTime::display($peek->placed_at)]) }}</span>
        </x-admin.drawer-bar>
        @livewire(\App\Livewire\Admin\Sales\OrderShow::class, ['order' => $peek, 'drawer' => true], key('order-peek-'.$peek->id.'-'.$orderPeekSeq))
    @endif
</x-admin.dialog>
