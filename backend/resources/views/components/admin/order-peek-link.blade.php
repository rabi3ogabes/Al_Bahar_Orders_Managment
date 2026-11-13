@props(['id', 'number', 'can' => true])
{{-- An order number that opens the order in a side window of the host list (PeeksAtOrder). The real href opens the orders list with the same window, for middle-click and copy-link. --}}
@if ($can)
    <a href="{{ route('admin.orders.index', ['view' => $id]) }}" wire:click.prevent.stop="peekOrder('{{ $id }}')" dir="ltr" {{ $attributes->merge(['class' => 'font-mono hover:text-brass-600']) }}>{{ $number }}</a>
@else
    <span dir="ltr" {{ $attributes->merge(['class' => 'font-mono']) }}>{{ $number }}</span>
@endif
