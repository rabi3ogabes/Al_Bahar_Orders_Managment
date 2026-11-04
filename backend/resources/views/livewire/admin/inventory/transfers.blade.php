<div>
    <x-admin.page-header :title="__('nav.transfers')" :eyebrow="__('nav.inventory')" :description="__('inventory_admin.transfers_hint')">
        <x-slot:actions>
            @if ($sources->isNotEmpty())<button type="button" wire:click="create" class="btn-primary"><x-icon name="plus" class="size-4" />{{ __('inventory_admin.new_transfer') }}</button>@endif
        </x-slot:actions>
    </x-admin.page-header>

    <div class="card">
        <x-admin.toolbar>
            <x-admin.search :placeholder="__('inventory_admin.transfers_search')" />
            <select wire:model.live="filterStatus" class="input pe-10 rtl:[background-position:left_0.5rem_center] sm:w-48" aria-label="{{ __('admin.status') }}">
                <option value="">{{ __('admin.all_statuses') }}</option>
                <option value="open">{{ __('inventory_admin.open_only') }}</option>
                @foreach (['draft', 'pending_approval', 'approved', 'in_transit', 'partially_received', 'received', 'completed', 'rejected', 'cancelled'] as $st)<option value="{{ $st }}">{{ __('status.'.$st) }}</option>@endforeach
            </select>
            @unless ($scopeAll)
                <select wire:model.live="direction" class="input pe-10 rtl:[background-position:left_0.5rem_center] sm:w-40" aria-label="{{ __('inventory_admin.direction') }}">
                    <option value="">{{ __('inventory_admin.both_directions') }}</option>
                    <option value="outgoing">{{ __('inventory_admin.outgoing') }}</option>
                    <option value="incoming">{{ __('inventory_admin.incoming') }}</option>
                </select>
            @endunless
            <x-slot:meta>{{ trans_choice('inventory_admin.transfers_count', $transfers->total(), ['count' => $transfers->total()]) }}</x-slot:meta>
        </x-admin.toolbar>
        @if ($transfers->isEmpty())
            <x-admin.empty :title="__('inventory_admin.transfers_empty')" :description="__('inventory_admin.transfers_empty_hint')" icon="transfer" />
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>{{ __('inventory_admin.transfer') }}</th><th>{{ __('inventory_admin.route') }}</th><th class="!text-end">{{ __('inventory_admin.lines') }}</th><th class="!text-end">{{ __('inventory_admin.units') }}</th><th>{{ __('admin.status') }}</th><th class="w-12"></th></tr></thead>
                    <tbody>
                    @foreach ($transfers as $t)
                        <tr wire:key="t-{{ $t->id }}">
                            <td><a href="{{ route('admin.transfers.show', $t) }}" wire:navigate class="font-mono font-medium hover:text-brass-600" dir="ltr">{{ $t->number }}</a><span class="block text-xs text-ink-500 tabular-nums">{{ \App\Support\BusinessTime::display($t->created_at) }}</span></td>
                            <td>
                                <span class="inline-flex items-center gap-2 text-sm">
                                    <span class="badge-neutral font-mono" dir="ltr" title="{{ $t->source->localized('name') }}">{{ $t->source->code }}</span>
                                    <x-icon name="arrow-right" class="size-3.5 text-ink-400 rtl-flip" />
                                    <span class="badge-neutral font-mono" dir="ltr" title="{{ $t->destination->localized('name') }}">{{ $t->destination->code }}</span>
                                </span>
                            </td>
                            <td class="text-end tabular-nums">{{ $t->items_count }}</td>
                            <td class="text-end tabular-nums">{{ number_format((int) $t->units) }}</td>
                            <td><x-admin.status :value="$t->status" /></td>
                            <td><a href="{{ route('admin.transfers.show', $t) }}" wire:navigate class="btn-ghost btn-sm" aria-label="{{ __('admin.details') }}"><x-icon name="chevron-right" class="size-4 rtl-flip" /></a></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            @if ($transfers->hasPages())<div class="border-t border-sand-100 p-4 dark:border-ink-800">{{ $transfers->links('components.admin.pagination') }}</div>@endif
        @endif
    </div>

    <x-admin.dialog wire:model="showEditor" :title="__('inventory_admin.new_transfer')" :description="__('inventory_admin.new_transfer_hint')" max-width="max-w-3xl">
        <form wire:submit="save" class="space-y-5">
            <div class="grid items-end gap-3 sm:grid-cols-[1fr_auto_1fr]">
                <x-admin.field :label="__('inventory_admin.from_branch')" for="form.source_branch_id" required>
                    <select id="form.source_branch_id" wire:model.live="form.source_branch_id" class="input pe-10 rtl:[background-position:left_0.5rem_center]"><option value="">—</option>@foreach ($sources as $b)<option value="{{ $b->id }}">{{ $b->code }} · {{ $b->localized('name') }}</option>@endforeach</select>
                </x-admin.field>
                <span class="hidden pb-3 text-ink-400 sm:block"><x-icon name="arrow-right" class="size-4 rtl-flip" /></span>
                <x-admin.field :label="__('inventory_admin.to_branch')" for="form.destination_branch_id" required>
                    <select id="form.destination_branch_id" wire:model="form.destination_branch_id" class="input pe-10 rtl:[background-position:left_0.5rem_center]"><option value="">—</option>@foreach ($destinations as $b)@if ($b->id !== $form['source_branch_id'])<option value="{{ $b->id }}">{{ $b->code }} · {{ $b->localized('name') }}</option>@endif @endforeach</select>
                </x-admin.field>
            </div>
            <x-admin.variant-picker :results="$matches" :label="__('inventory_admin.add_products')" />
            @error('lines')<p class="field-error">{{ $message }}</p>@enderror
            @if ($lines)
                <div class="overflow-hidden rounded-xl border border-sand-200/70 dark:border-ink-700"><div class="table-wrap"><table class="table">
                    <thead><tr><th>{{ __('catalog_admin.product') }}</th><th class="!text-end">{{ __('inventory_admin.available_at_source') }}</th><th class="w-28">{{ __('admin.quantity') }}</th><th></th></tr></thead>
                    <tbody>@foreach ($lines as $i => $l)
                        @php $av = $available[$l['variant_id']] ?? 0; @endphp
                        <tr wire:key="tl-{{ $l['variant_id'] }}">
                            <td>@if ($lineVariants[$l['variant_id']] ?? null)<x-admin.variant :variant="$lineVariants[$l['variant_id']]" />@endif</td>
                            <td class="text-end tabular-nums {{ $form['source_branch_id'] && (int) $l['quantity'] > $av ? 'text-ruby-soft font-semibold' : 'text-ink-500' }}">{{ $form['source_branch_id'] ? $av : '—' }}</td>
                            <td><input type="number" min="1" wire:model.live.debounce.400ms="lines.{{ $i }}.quantity" class="input py-2 tabular-nums" aria-label="{{ __('admin.quantity') }}">@error("lines.$i.quantity")<p class="field-error">{{ $message }}</p>@enderror</td>
                            <td><button type="button" wire:click="removeLine({{ $i }})" class="btn-ghost btn-sm px-2 text-ruby-soft" aria-label="{{ __('org_admin.remove') }}"><x-icon name="x" class="size-4" /></button></td>
                        </tr>
                    @endforeach</tbody>
                </table></div></div>
            @endif
            <x-admin.field :label="__('admin.notes')" for="form.notes"><textarea id="form.notes" wire:model="form.notes" rows="2" class="input"></textarea></x-admin.field>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="form.submit" class="checkbox">{{ __('inventory_admin.submit_for_approval_now') }}</label>
            <div class="flex flex-col-reverse gap-2 border-t border-sand-100 pt-5 sm:flex-row sm:justify-end dark:border-ink-800">
                <button type="button" class="btn-secondary" @click="open = false">{{ __('admin.cancel') }}</button>
                <button class="btn-primary" wire:loading.attr="disabled" wire:target="save"><x-icon name="check" class="size-4" />{{ __('inventory_admin.create_transfer') }}</button>
            </div>
        </form>
    </x-admin.dialog>
</div>
