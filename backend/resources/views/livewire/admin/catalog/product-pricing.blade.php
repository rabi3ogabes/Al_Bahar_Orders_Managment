@php
    $fmt = fn ($m) => $m ? $m->format() : '—';
    $sourceTone = ['variant_branch' => 'badge-brass', 'product_branch' => 'badge-brass', 'variant' => 'badge-info', 'product' => 'badge-neutral'];
    $multi = $variants->count() > 1;
@endphp
<div class="space-y-6">
    <div class="grid gap-6 [&>*]:min-w-0 lg:grid-cols-3">
        <div class="card card-pad">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <p class="eyebrow mb-1">{{ __('catalog_admin.global_price') }}</p>
                    @php $gp = \App\Support\Money::of($product->price); $sp = $product->sale_price !== null ? \App\Support\Money::of($product->sale_price) : null; @endphp
                    <p class="font-display text-3xl font-semibold tabular-nums">{{ $gp->format() }}</p>
                    @if ($sp)<p class="mt-1 text-sm"><span class="font-medium text-ruby-soft tabular-nums">{{ $sp->format() }}</span> <span class="text-ink-500">{{ __('catalog_admin.on_sale') }}</span></p>@endif
                    @if ($product->sale_starts_at || $product->sale_ends_at)
                        <p class="mt-1 text-xs text-ink-500 tabular-nums">{{ $product->sale_starts_at ? \App\Support\BusinessTime::display($product->sale_starts_at) : '…' }} → {{ $product->sale_ends_at ? \App\Support\BusinessTime::display($product->sale_ends_at) : '…' }}</p>
                    @endif
                </div>
                @if ($canGlobal)<button type="button" wire:click="open('product')" class="btn-secondary btn-sm"><x-icon name="edit" class="size-3.5" />{{ __('admin.edit') }}</button>@endif
            </div>
            <p class="mt-5 rounded-xl bg-sand-50 p-3 text-xs leading-relaxed text-ink-500 dark:bg-ink-800/60 dark:text-ink-400">{{ __('catalog_admin.precedence_hint') }}</p>
        </div>

        <div class="min-w-0 card overflow-hidden lg:col-span-2">
            <div class="p-5 sm:p-6"><h2 class="font-display text-xl font-semibold">{{ __('catalog_admin.variant_prices') }}</h2><p class="mt-1 text-sm text-ink-500">{{ __('catalog_admin.variant_prices_hint') }}</p></div>
            <div class="table-wrap"><table class="table">
                <thead><tr><th>{{ __('catalog_admin.variant') }}</th><th class="!text-end">{{ __('catalog_admin.own_price') }}</th><th class="!text-end">{{ __('catalog_admin.effective') }}</th><th class="w-12"></th></tr></thead>
                <tbody>@foreach ($variants as $v)
                    <tr wire:key="vp-{{ $v->id }}">
                        <td><x-admin.variant :variant="$v" /></td>
                        <td class="whitespace-nowrap text-end tabular-nums">@if ($v->price !== null){{ \App\Support\Money::of($v->price)->format() }}@if ($v->sale_price !== null)<span class="block text-xs text-ruby-soft">{{ \App\Support\Money::of($v->sale_price)->format() }}</span>@endif @else<span class="text-xs text-ink-400">{{ __('catalog_admin.inherits_product') }}</span>@endif</td>
                        <td class="whitespace-nowrap text-end font-medium tabular-nums">{{ $global[$v->id]->final()->format() }}</td>
                        <td>@if ($canGlobal)<button type="button" wire:click="open('variant', null, '{{ $v->id }}')" class="btn-ghost btn-sm px-2" aria-label="{{ __('admin.edit') }}"><x-icon name="edit" class="size-4" /></button>@endif</td>
                    </tr>
                @endforeach</tbody>
            </table></div>
        </div>
    </div>

    <div class="card overflow-hidden">
        <div class="flex flex-col gap-2 p-5 sm:flex-row sm:items-end sm:justify-between sm:p-6">
            <div><h2 class="font-display text-xl font-semibold">{{ __('catalog_admin.branch_matrix') }}</h2><p class="mt-1 text-sm text-ink-500">{{ __('catalog_admin.branch_matrix_hint') }}</p></div>
            <div class="flex flex-wrap gap-1.5 text-[0.7rem]">
                <span class="badge-brass">{{ __('catalog_admin.source.product_branch') }}</span>
                <span class="badge-info">{{ __('catalog_admin.source.variant') }}</span>
                <span class="badge-neutral">{{ __('catalog_admin.source.product') }}</span>
            </div>
        </div>
        @if ($branches->isEmpty())
            <x-admin.empty :title="__('catalog_admin.no_branches_in_scope')" icon="store" class="!py-10" />
        @else
            <div class="table-wrap border-t border-sand-100 dark:border-ink-800">
                <table class="table">
                    <thead><tr>
                        <th class="sticky start-0 z-10 min-w-[11rem] !bg-sand-50 dark:!bg-ink-800">{{ __('admin.branch') }}</th>
                        <th>{{ __('catalog_admin.branch_override') }}</th>
                        @foreach ($variants as $v)<th class="min-w-[10rem] !normal-case !tracking-normal"><span class="font-mono" dir="ltr">{{ $v->sku }}</span>@if ($multi && $v->displayName())<span class="block font-normal text-ink-400">{{ $v->displayName() }}</span>@endif</th>@endforeach
                    </tr></thead>
                    <tbody>
                    @foreach ($branches as $b)
                        @php
                            $ps = $productSettings[$b->id] ?? null;
                            $enabled = $product->available_in_all_branches ? ($ps === null || $ps->is_enabled) : ($ps?->is_enabled ?? false);
                            $editable = isset($editableBranches[$b->id]);
                        @endphp
                        <tr wire:key="br-{{ $b->id }}" class="{{ $enabled ? '' : 'bg-sand-50/40 dark:bg-ink-800/20' }}">
                            <td class="sticky start-0 z-10 bg-white dark:bg-ink-900">
                                <div class="flex items-center gap-3">
                                    @if ($editable)
                                        <button type="button" wire:click="toggleBranch('{{ $b->id }}')" role="switch" aria-checked="{{ $enabled ? 'true' : 'false' }}" aria-label="{{ __('catalog_admin.sell_at_branch') }}"
                                                class="relative inline-flex h-5 w-9 shrink-0 items-center rounded-full transition {{ $enabled ? 'bg-emerald-soft' : 'bg-sand-300 dark:bg-ink-600' }}">
                                            <span class="inline-block size-4 rounded-full bg-white shadow transition {{ $enabled ? 'translate-x-4 rtl:-translate-x-4' : 'translate-x-0.5 rtl:-translate-x-0.5' }}"></span>
                                        </button>
                                    @endif
                                    <span class="min-w-0"><span class="block truncate font-medium">{{ $b->localized('name') }}</span><span class="block font-mono text-xs text-ink-500" dir="ltr">{{ $b->code }}</span></span>
                                </div>
                            </td>
                            <td class="whitespace-nowrap">
                                @if ($ps?->price !== null)
                                    <span class="block font-medium tabular-nums">{{ \App\Support\Money::of($ps->price)->format() }}</span>
                                    @if ($ps->sale_price !== null)<span class="block text-xs text-ruby-soft tabular-nums">{{ \App\Support\Money::of($ps->sale_price)->format() }}</span>@endif
                                @else
                                    <span class="text-xs text-ink-400">{{ __('catalog_admin.no_override') }}</span>
                                @endif
                                @if ($editable)<button type="button" wire:click="open('product_branch', '{{ $b->id }}')" class="mt-1 inline-flex items-center gap-1 text-xs font-medium text-brass-600 hover:text-brass-700"><x-icon name="edit" class="size-3" />{{ __('catalog_admin.override') }}</button>@endif
                            </td>
                            @foreach ($variants as $v)
                                @php $cell = $matrix[$b->id][$v->id]; $p = $cell['price']; $vOff = $cell['override'] && ! $cell['override']->is_enabled; @endphp
                                <td class="group/cell align-top">
                                    @if ($cell['available'])
                                        @if ($p->sale)
                                            <span class="block font-semibold text-ruby-soft tabular-nums">{{ $p->sale->format() }}</span>
                                            <span class="block text-xs text-ink-400 line-through tabular-nums">{{ $p->regular->format() }}</span>
                                        @else
                                            <span class="block font-semibold tabular-nums">{{ $p->regular->format() }}</span>
                                        @endif
                                        <span class="{{ $sourceTone[$p->source] }} mt-1 !px-1.5 !py-0 text-[0.65rem]">{{ __('catalog_admin.source.'.$p->source) }}</span>
                                    @else
                                        <span class="block text-ink-400 line-through tabular-nums">{{ $p->final()->format() }}</span>
                                        <span class="badge-danger mt-1 !px-1.5 !py-0 text-[0.65rem]">{{ __('catalog_admin.unavailable.'.$cell['reason']) }}</span>
                                    @endif
                                    @if ($editable)
                                        <div class="mt-1.5 flex gap-2 text-xs">
                                            <button type="button" wire:click="open('variant_branch', '{{ $b->id }}', '{{ $v->id }}')" class="font-medium text-brass-600 hover:text-brass-700">{{ __('catalog_admin.override') }}</button>
                                            <button type="button" wire:click="toggleVariantBranch('{{ $v->id }}', '{{ $b->id }}')" class="text-ink-500 hover:text-ink-800 dark:hover:text-sand-100">{{ $vOff ? __('admin.enable') : __('admin.disable') }}</button>
                                        </div>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <x-admin.dialog wire:model="showEditor" :title="__('catalog_admin.edit_price')" :description="isset($editor['level']) ? __('catalog_admin.level_hint.'.$editor['level']) : null">
        @if ($editor)
            <form wire:submit="save" class="space-y-5">
                @if ($editor['branch_id'] || $editor['variant_id'])
                    <div class="flex flex-wrap gap-2 text-xs">
                        @if ($editor['branch_id'])<span class="badge-brass font-mono" dir="ltr">{{ $branches->firstWhere('id', $editor['branch_id'])?->code }}</span>@endif
                        @if ($editor['variant_id'])<span class="badge-neutral font-mono" dir="ltr">{{ $variants->firstWhere('id', $editor['variant_id'])?->sku }}</span>@endif
                    </div>
                @endif
                <div class="grid grid-cols-2 gap-4">
                    <x-admin.field :label="__('catalog_admin.price')" for="editor.price" :required="$editor['level'] === 'product'" :hint="$editor['level'] !== 'product' ? __('catalog_admin.blank_inherits') : null">
                        <input id="editor.price" wire:model="editor.price" inputmode="decimal" class="input tabular-nums" dir="ltr" placeholder="0.00" autofocus>
                    </x-admin.field>
                    <x-admin.field :label="__('catalog_admin.sale_price')" for="editor.sale_price"><input id="editor.sale_price" wire:model="editor.sale_price" inputmode="decimal" class="input tabular-nums" dir="ltr"></x-admin.field>
                    <x-admin.field :label="__('catalog_admin.sale_starts')" for="editor.sale_starts_at"><input id="editor.sale_starts_at" type="datetime-local" wire:model="editor.sale_starts_at" class="input" dir="ltr"></x-admin.field>
                    <x-admin.field :label="__('catalog_admin.sale_ends')" for="editor.sale_ends_at"><input id="editor.sale_ends_at" type="datetime-local" wire:model="editor.sale_ends_at" class="input" dir="ltr"></x-admin.field>
                </div>
                <x-admin.field :label="__('admin.reason')" for="editor.reason" required :hint="__('catalog_admin.reason_hint')"><input id="editor.reason" wire:model="editor.reason" class="input" maxlength="255" placeholder="{{ __('catalog_admin.reason_ph') }}"></x-admin.field>
                <p class="flex items-center gap-1.5 text-xs text-ink-500"><x-icon name="globe" class="size-3.5" />{{ __('catalog_admin.qatar_time') }}</p>
                <div class="flex flex-col-reverse gap-2 border-t border-sand-100 pt-5 sm:flex-row sm:items-center dark:border-ink-800">
                    @if ($editor['level'] !== 'product')
                        <button type="button" wire:click="clear" wire:confirm="{{ __('catalog_admin.confirm_clear_override') }}" class="btn-ghost text-ruby-soft sm:me-auto"><x-icon name="x" class="size-4" />{{ __('catalog_admin.clear_override') }}</button>
                    @endif
                    <button type="button" class="btn-secondary sm:ms-auto" @click="requestClose()">{{ __('admin.cancel') }}</button>
                    <button class="btn-primary" wire:loading.attr="disabled" wire:target="save"><x-icon name="check" class="size-4" />{{ __('admin.save') }}</button>
                </div>
            </form>
        @endif
    </x-admin.dialog>
</div>
