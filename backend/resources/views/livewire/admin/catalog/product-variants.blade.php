<div class="space-y-6">
    <div class="card overflow-hidden">
        <div class="p-5 sm:p-6"><h2 class="font-display text-xl font-semibold">{{ __('catalog_admin.variants') }}</h2><p class="mt-1 text-sm text-ink-500">{{ __('catalog_admin.variants_hint') }}</p></div>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>SKU</th><th>{{ __('catalog_admin.attributes') }}</th><th>{{ __('catalog_admin.barcode') }}</th><th>{{ __('admin.status') }}</th><th class="w-40"></th></tr></thead>
                <tbody>
                @foreach ($variants as $v)
                    <tr wire:key="var-{{ $v->id }}" class="{{ $v->status === 'active' ? '' : 'opacity-60' }}">
                        <td>
                            <span class="flex items-center gap-2 font-mono text-sm font-medium" dir="ltr">{{ $v->sku }}@if ($v->is_default)<span class="badge-brass font-sans text-[0.65rem]">{{ __('catalog_admin.default') }}</span>@endif</span>
                            @if ($v->name_en || $v->name_ar)<span class="block text-xs text-ink-500">{{ $v->displayName() }}</span>@endif
                        </td>
                        <td>
                            <div class="flex flex-wrap gap-1.5">
                                @forelse ($v->attributeValues as $av)
                                    <span class="badge-neutral">@if ($av->color_hex)<span class="size-2.5 rounded-full border border-black/10" style="background-color: {{ $av->color_hex }}"></span>@endif{{ $av->localized('value') }}</span>
                                @empty
                                    <span class="text-xs text-ink-400">{{ __('catalog_admin.no_attributes') }}</span>
                                @endforelse
                            </div>
                        </td>
                        <td class="font-mono text-xs text-ink-500" dir="ltr">{{ $v->barcode ?: '—' }}</td>
                        <td><x-admin.status :value="$v->status" /></td>
                        <td>
                            @if ($canEdit)
                                <div class="flex justify-end gap-0.5">
                                    @unless ($v->is_default)<button type="button" wire:click="makeDefault('{{ $v->id }}')" class="btn-ghost btn-sm px-2" title="{{ __('catalog_admin.make_default') }}" aria-label="{{ __('catalog_admin.make_default') }}"><x-icon name="star" class="size-4" /></button>@endunless
                                    <button type="button" wire:click="toggleStatus('{{ $v->id }}')" class="btn-ghost btn-sm px-2" title="{{ $v->status === 'active' ? __('admin.disable') : __('admin.enable') }}" aria-label="{{ $v->status === 'active' ? __('admin.disable') : __('admin.enable') }}"><x-icon :name="$v->status === 'active' ? 'pause' : 'play'" class="size-4" /></button>
                                    <button type="button" wire:click="openEdit('{{ $v->id }}')" class="btn-ghost btn-sm px-2" aria-label="{{ __('admin.edit') }}"><x-icon name="edit" class="size-4" /></button>
                                </div>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>

    @if ($canEdit)
        <div class="card overflow-hidden">
            <div class="border-b border-sand-100 p-5 sm:p-6 dark:border-ink-800">
                <h2 class="font-display text-xl font-semibold">{{ __('catalog_admin.variant_builder') }}</h2>
                <p class="mt-1 text-sm text-ink-500">{{ __('catalog_admin.variant_builder_hint') }}</p>
            </div>
            @if ($attributes->isEmpty())
                <x-admin.empty :title="__('catalog_admin.no_axes')" :description="__('catalog_admin.no_axes_hint')" icon="tag" class="!py-10">
                    <a href="{{ route('admin.attributes.index') }}" wire:navigate class="btn-secondary">{{ __('nav.attributes') }}</a>
                </x-admin.empty>
            @else
                <div class="space-y-5 p-5 sm:p-6">
                    @foreach ($attributes as $a)
                        <div wire:key="axis-{{ $a->id }}">
                            <p class="mb-2 text-sm font-medium">{{ $a->localized('name') }} @if (! empty($picked[$a->id]))<span class="badge-brass ms-1 tabular-nums">{{ count($picked[$a->id]) }}</span>@endif</p>
                            <div class="flex flex-wrap gap-2">
                                @foreach ($a->values as $val)
                                    @php $on = in_array($val->id, $picked[$a->id] ?? [], true); @endphp
                                    <button type="button" wire:click="togglePick({{ $a->id }}, {{ $val->id }})" aria-pressed="{{ $on ? 'true' : 'false' }}"
                                            class="inline-flex items-center gap-2 rounded-full border px-3 py-1.5 text-sm transition {{ $on ? 'border-brass-500 bg-brass-50 text-ink-900 dark:bg-brass-500/15 dark:text-ivory' : 'border-sand-200 text-ink-600 hover:border-sand-300 dark:border-ink-700 dark:text-sand-200' }}">
                                        @if ($val->color_hex)<span class="size-3.5 rounded-full border border-black/10" style="background-color: {{ $val->color_hex }}"></span>@endif
                                        {{ $val->localized('value') }}
                                        @if ($on)<x-icon name="check" class="size-3.5 text-brass-600" />@endif
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                    <div class="flex justify-end"><button type="button" wire:click="generate" class="btn-secondary"><x-icon name="sparkles" class="size-4" />{{ __('catalog_admin.generate_combinations') }}</button></div>
                </div>
                @if ($generated)
                    <div class="border-t border-sand-100 dark:border-ink-800">
                        <div class="table-wrap">
                            <table class="table">
                                <thead><tr><th class="w-10"></th><th>{{ __('catalog_admin.combination') }}</th><th>SKU</th><th>{{ __('catalog_admin.barcode') }}</th></tr></thead>
                                <tbody>
                                @foreach ($generated as $i => $g)
                                    <tr wire:key="gen-{{ $i }}-{{ implode('-', $g['values']) }}">
                                        <td><input type="checkbox" wire:model="generated.{{ $i }}.include" class="checkbox" aria-label="{{ __('catalog_admin.include') }}"></td>
                                        <td><div class="flex flex-wrap gap-1.5">@foreach ($g['values'] as $vid)<span class="badge-neutral">@if ($valueLabels[$vid]->color_hex ?? null)<span class="size-2.5 rounded-full" style="background-color: {{ $valueLabels[$vid]->color_hex }}"></span>@endif{{ $valueLabels[$vid]?->localized('value') }}</span>@endforeach</div></td>
                                        <td><input wire:model="generated.{{ $i }}.sku" class="input py-2 font-mono text-xs uppercase" dir="ltr" aria-label="SKU">@error("generated.$i.sku")<p class="field-error">{{ $message }}</p>@enderror</td>
                                        <td><input wire:model="generated.{{ $i }}.barcode" class="input py-2 font-mono text-xs" dir="ltr" aria-label="{{ __('catalog_admin.barcode') }}"></td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                        <div class="flex flex-col gap-3 border-t border-sand-100 p-5 sm:flex-row sm:items-center sm:justify-between sm:px-6 dark:border-ink-800">
                            @if ($bareDefault)
                                <label class="flex items-center gap-2 text-sm text-ink-600 dark:text-sand-200"><input type="checkbox" wire:model="reuseDefault" class="checkbox">{{ __('catalog_admin.reuse_default') }}</label>
                            @else<span></span>@endif
                            <button type="button" wire:click="createGenerated" class="btn-primary" wire:loading.attr="disabled" wire:target="createGenerated"><x-icon name="plus" class="size-4" />{{ trans_choice('catalog_admin.create_n_variants', count($generated), ['count' => count($generated)]) }}</button>
                        </div>
                    </div>
                @endif
            @endif
        </div>
    @endif

    <x-admin.dialog wire:model="showEditor" :title="__('catalog_admin.edit_variant')" max-width="max-w-2xl">
        @if ($edit)
            <form wire:submit="saveEdit" class="space-y-5">
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-admin.field label="SKU" for="edit.sku" required><input id="edit.sku" wire:model="edit.sku" class="input font-mono uppercase" dir="ltr"></x-admin.field>
                    <x-admin.field :label="__('catalog_admin.barcode')" for="edit.barcode"><input id="edit.barcode" wire:model="edit.barcode" class="input font-mono" dir="ltr"></x-admin.field>
                    <x-admin.field :label="__('catalog_admin.variant_name_en')" for="edit.name_en" :hint="__('catalog_admin.variant_name_hint')"><input id="edit.name_en" wire:model="edit.name_en" class="input" dir="ltr"></x-admin.field>
                    <x-admin.field :label="__('catalog_admin.variant_name_ar')" for="edit.name_ar"><input id="edit.name_ar" wire:model="edit.name_ar" class="input" dir="rtl" lang="ar"></x-admin.field>
                    @foreach ($attributes as $a)
                        <x-admin.field :label="$a->localized('name')" :for="'edit.values.'.$a->id" wire:key="ev-{{ $a->id }}">
                            <select id="edit.values.{{ $a->id }}" wire:model="edit.values.{{ $a->id }}" class="input pe-10 rtl:[background-position:left_0.5rem_center]"><option value="">—</option>@foreach ($a->values as $val)<option value="{{ $val->id }}">{{ $val->localized('value') }}</option>@endforeach</select>
                        </x-admin.field>
                    @endforeach
                </div>
                <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                    @foreach (['weight', 'length', 'width', 'height'] as $d)
                        <x-admin.field :label="__('catalog_admin.'.$d)" :for="'edit.'.$d"><input id="edit.{{ $d }}" wire:model="edit.{{ $d }}" inputmode="decimal" class="input tabular-nums" dir="ltr"></x-admin.field>
                    @endforeach
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <x-admin.field :label="__('admin.status')" for="edit.status"><select id="edit.status" wire:model="edit.status" class="input pe-10 rtl:[background-position:left_0.5rem_center]"><option value="active">{{ __('status.active') }}</option><option value="inactive">{{ __('status.inactive') }}</option></select></x-admin.field>
                    <div class="sm:pt-7"><x-admin.toggle :label="__('catalog_admin.default')" wire:model="edit.is_default" /></div>
                </div>
                <div class="flex flex-col-reverse gap-2 border-t border-sand-100 pt-5 sm:flex-row sm:justify-end dark:border-ink-800">
                    <button type="button" class="btn-secondary" @click="requestClose()">{{ __('admin.cancel') }}</button>
                    <button class="btn-primary" wire:loading.attr="disabled" wire:target="saveEdit"><x-icon name="check" class="size-4" />{{ __('admin.save') }}</button>
                </div>
            </form>
        @endif
    </x-admin.dialog>
</div>
