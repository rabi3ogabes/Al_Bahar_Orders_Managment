{{--
    The product picker of a promotion: a search box, a paste-SKUs box (one per line or comma-separated, the pasted order is kept) and the chosen products as chips.
    Used twice by the promotion window: for the products a promotion COVERS and for the products it LEAVES OUT. What changes is told by the variables:
    $searchModel / $pasteModel (the typed texts), $idsProperty (the chosen ids), $picked, $matches, $add / $remove / $pasteAction (the Livewire methods), $uid (keys).
--}}
<div class="space-y-3" data-product-picker="{{ $uid }}">
    <div class="relative">
        <input type="search" wire:model.live.debounce.250ms="{{ $searchModel }}" placeholder="{{ __('marketing_admin.search_products') }}" class="input" autocomplete="off" aria-label="{{ __('marketing_admin.search_products') }}">
        @if (mb_strlen(trim((string) $this->{$searchModel})) >= 2)
            <ul class="absolute z-20 mt-1 max-h-64 w-full overflow-y-auto rounded-xl border border-sand-200 bg-white p-1 shadow-lg dark:border-ink-700 dark:bg-ink-900">
                @forelse ($matches as $m)
                    <li><button type="button" wire:click="{{ $add }}('{{ $m->id }}')" wire:key="{{ $uid }}-m-{{ $m->id }}" class="flex w-full items-center justify-between gap-3 rounded-lg px-3 py-2 text-start text-sm hover:bg-sand-50 dark:hover:bg-ink-800">
                        <span class="min-w-0 truncate">{{ $m->trans('name') }}</span><span class="shrink-0 font-mono text-xs text-ink-500" dir="ltr">{{ $m->sku }}</span>
                    </button></li>
                @empty
                    <li class="px-3 py-2 text-sm text-ink-500">{{ __('marketing_admin.no_product_results') }}</li>
                @endforelse
            </ul>
        @endif
    </div>
    <details class="rounded-xl border border-sand-200 p-3 text-sm dark:border-ink-700">
        <summary class="cursor-pointer font-medium">{{ __('marketing_admin.paste_skus') }}</summary>
        <p class="mt-2 text-xs text-ink-500">{{ __('marketing_admin.paste_skus_hint') }}</p>
        <textarea wire:model="{{ $pasteModel }}" rows="3" class="input mt-2 font-mono text-xs" dir="ltr" aria-label="{{ __('marketing_admin.paste_skus') }}"></textarea>
        <button type="button" wire:click="{{ $pasteAction }}" class="btn-secondary btn-sm mt-2">{{ __('marketing_admin.add_skus') }}</button>
    </details>
    <div>
        <div class="mb-1.5 flex items-center justify-between text-xs text-ink-500">
            <span>{{ trans_choice('marketing_admin.products_selected', count($this->{$idsProperty}), ['count' => count($this->{$idsProperty}), 'max' => \App\Livewire\Admin\Marketing\PromotionIndex::MAX_PRODUCTS]) }}</span>
            @if ($this->{$idsProperty})<button type="button" wire:click="$set('{{ $idsProperty }}', [])" class="text-ruby-soft hover:underline">{{ __('marketing_admin.clear_all') }}</button>@endif
        </div>
        @if ($picked->isEmpty())
            <p class="rounded-xl border border-dashed border-sand-300 p-3 text-center text-sm text-ink-500 dark:border-ink-700">{{ __('marketing_admin.no_products_yet') }}</p>
        @else
            <ul class="flex max-h-48 flex-wrap gap-1.5 overflow-y-auto rounded-xl border border-sand-200 p-2 dark:border-ink-700">
                @foreach ($picked as $pp)
                    <li wire:key="{{ $uid }}-p-{{ $pp->id }}" class="inline-flex max-w-full items-center gap-1.5 rounded-full bg-sand-100 py-1 ps-3 pe-1 text-xs dark:bg-ink-800">
                        <span class="truncate">{{ $pp->trans('name') }}</span><span class="font-mono text-ink-500" dir="ltr">{{ $pp->sku }}</span>
                        <button type="button" wire:click="{{ $remove }}('{{ $pp->id }}')" class="grid size-5 place-items-center rounded-full hover:bg-sand-200 dark:hover:bg-ink-700" aria-label="{{ __('org_admin.remove') }}"><x-icon name="x" class="size-3" /></button>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</div>
