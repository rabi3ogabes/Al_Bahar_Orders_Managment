@props(['value', 'domain' => 'status'])
@php
    $tone = match ($value) {
        'active', 'open', 'paid', 'completed', 'delivered', 'picked_up', 'approved', 'posted', 'received', 'published', 'collected', 'processed', 'sold', 'matched', 'resolved', 'deposited', 'live', 'success', 'confirmed', 'finalized' => 'badge-success',
        'pending', 'in_drawer', 'pending_payment', 'pending_approval', 'processing', 'preparing', 'in_transit', 'partially_received', 'requested', 'scheduled', 'counting', 'submitted', 'assigned', 'accepted', 'pending_customer', 'pending_staff', 'paused', 'partially_fulfilled', 'partially_refunded', 'draft', 'queued', 'sending' => 'badge-warning',
        'failed', 'cancelled', 'rejected', 'expired', 'suspended', 'inactive', 'archived', 'returned', 'unsold', 'mismatch', 'defaulted', 'blocked', 'closed', 'temporarily_closed', 'refunded' => 'badge-danger',
        'ready', 'ready_for_pickup', 'pickup_ready', 'out_for_delivery', 'dispatched' => 'badge-info',
        default => 'badge-neutral',
    };
@endphp
<span {{ $attributes->merge(['class' => $tone]) }}>
    <span class="size-1.5 rounded-full bg-current opacity-70"></span>{{ \Illuminate\Support\Facades\Lang::has('status.'.$value) ? __('status.'.$value) : \Illuminate\Support\Str::headline($value) }}
</span>
