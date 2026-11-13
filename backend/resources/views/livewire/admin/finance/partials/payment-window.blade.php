{{-- Payment side window (PaymentShow in compact mode) for a list screen using HasRecordWindow + PeeksAtOrder. Include once, inside the list's root element. --}}
    @php $viewed = $this->viewed; @endphp
    <x-admin.dialog wire:model="showView" :title="$viewed ? \App\Support\Money::of($viewed->amount)->format() : null" :description="$viewed ? __('finance_admin.payment').' · '.__('status.'.$viewed->method).' · '.__('sales_admin.attempt_n', ['n' => $viewed->attempt]) : null" max-width="max-w-3xl">
        @if ($viewed)
            <x-admin.drawer-bar :url="route('admin.payments.show', $viewed)">
                <x-admin.status :value="$viewed->status->value" />
                @if ($viewed->order)
                    <x-admin.order-peek-link :id="$viewed->order_id" :number="$viewed->order->number" :can="\App\Livewire\Admin\Sales\OrderShow::viewableBy(auth()->user(), $viewed->order)" class="underline-offset-2 hover:underline" />
                    <span class="truncate">{{ $viewed->order->customer_name }}</span>
                @endif
            </x-admin.drawer-bar>
            @livewire(\App\Livewire\Admin\Finance\PaymentShow::class, ['payment' => $viewed, 'drawer' => true], key('payment-view-'.$viewed->id.'-'.$viewSeq))
        @endif
    </x-admin.dialog>

    {{-- Declared after the payment window so an order opened from it lands on top. --}}
    @include('livewire.admin.sales.partials.order-peek')
