<div>
    <x-admin.dialog wire:model="open" :title="__('ops_live.proof.title')" max-width="max-w-lg">
        @if ($delivery)
            <x-admin.sales.delivery-proof :delivery="$delivery" />
        @endif
    </x-admin.dialog>
</div>
