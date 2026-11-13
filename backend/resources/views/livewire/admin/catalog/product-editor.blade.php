@php
    $isNew = ! $product;
    $langs = ['en' => ['ltr', __('catalog_admin.lang_en')], 'ar' => ['rtl', __('catalog_admin.lang_ar')]];
@endphp
<div class="mx-auto max-w-7xl">
    <a href="{{ route('admin.products.index') }}" wire:navigate class="mb-4 inline-flex items-center gap-1.5 text-sm text-ink-500 hover:text-brass-600"><x-icon name="chevron-left" class="size-4 rtl-flip" />{{ __('nav.products') }}</a>

    @if ($isNew)
        <x-admin.page-header :title="__('catalog_admin.new_product')" :eyebrow="__('nav.catalog')" :description="__('catalog_admin.new_product_hint')" />
        <form wire:submit="create" class="grid gap-6 [&>*]:min-w-0 lg:grid-cols-3">
            <div class="min-w-0 card card-pad space-y-6 lg:col-span-2">
                @if ($canEdit)
                    <div class="flex flex-wrap items-center justify-between gap-3 rounded-2xl bg-brass-500/10 px-4 py-3">
                        <p class="text-sm text-ink-700 dark:text-sand-100"><span class="font-medium">{{ __('catalog_admin.ai_title') }}</span> <span class="text-ink-500">{{ $aiReady ? __('catalog_admin.ai_hint') : __('catalog_admin.ai_not_ready') }}</span></p>
                        <button type="button" wire:click="describeWithAi" wire:loading.attr="disabled" wire:target="describeWithAi" class="btn-secondary min-h-11"><x-icon name="sparkles" class="size-4" /><span wire:loading.remove wire:target="describeWithAi">{{ __('catalog_admin.ai_write') }}</span><span wire:loading wire:target="describeWithAi">{{ __('catalog_admin.ai_writing') }}</span></button>
                    </div>
                @endif
                <div class="grid gap-6 md:grid-cols-2">
                    @foreach ($langs as $l => [$dir, $label])
                        <div class="space-y-5" wire:key="new-{{ $l }}">
                            <p class="eyebrow">{{ $label }}</p>
                            <x-admin.field :label="__('catalog_admin.f_name')" :for="'tr.'.$l.'.name'" required><input id="tr.{{ $l }}.name" wire:model="tr.{{ $l }}.name" class="input" dir="{{ $dir }}" lang="{{ $l }}"></x-admin.field>
                            <x-admin.field :label="__('catalog_admin.f_short_description')" :for="'tr.'.$l.'.short_description'"><textarea id="tr.{{ $l }}.short_description" wire:model="tr.{{ $l }}.short_description" rows="3" class="input" dir="{{ $dir }}" lang="{{ $l }}"></textarea></x-admin.field>
                        </div>
                    @endforeach
                </div>
            </div>
            <div class="space-y-6">
                <div class="card card-pad space-y-4">
                    <x-admin.field label="SKU" for="form.sku" required :hint="__('catalog_admin.sku_hint')"><input id="form.sku" wire:model="form.sku" class="input font-mono uppercase" dir="ltr"></x-admin.field>
                    <x-admin.field :label="__('catalog_admin.barcode')" for="form.barcode"><input id="form.barcode" wire:model="form.barcode" class="input font-mono" dir="ltr"></x-admin.field>
                    <div class="grid grid-cols-2 gap-3">
                        <x-admin.field :label="__('catalog_admin.price')" for="form.price" required><input id="form.price" wire:model="form.price" inputmode="decimal" class="input tabular-nums" dir="ltr" placeholder="0.00"></x-admin.field>
                        <x-admin.field :label="__('catalog_admin.sale_price')" for="form.sale_price"><input id="form.sale_price" wire:model="form.sale_price" inputmode="decimal" class="input tabular-nums" dir="ltr"></x-admin.field>
                    </div>
                    <x-admin.field :label="__('catalog_admin.category')" for="form.category_id">
                        <select id="form.category_id" wire:model="form.category_id" class="input pe-10 rtl:[background-position:left_0.5rem_center]"><option value="">—</option>@foreach ($categories as $c)<option value="{{ $c->id }}">{{ str_repeat('— ', $c->depth) }}{{ $c->trans('name') }}</option>@endforeach</select>
                    </x-admin.field>
                    <x-admin.field :label="__('catalog_admin.brand')" for="form.brand_id">
                        <select id="form.brand_id" wire:model="form.brand_id" class="input pe-10 rtl:[background-position:left_0.5rem_center]"><option value="">—</option>@foreach ($brands as $b)<option value="{{ $b->id }}">{{ $b->localized('name') }}</option>@endforeach</select>
                    </x-admin.field>
                    <x-admin.field :label="__('admin.status')" for="form.status">
                        <select id="form.status" wire:model="form.status" class="input pe-10 rtl:[background-position:left_0.5rem_center]">@foreach (['draft', 'active', 'inactive'] as $st)<option value="{{ $st }}">{{ __('status.'.$st) }}</option>@endforeach</select>
                    </x-admin.field>
                </div>
                <button class="btn-primary w-full py-3" wire:loading.attr="disabled" wire:target="create"><x-icon name="check" class="size-4" />{{ __('catalog_admin.create_product') }}</button>
            </div>
        </form>
    @else
        <div class="mb-8 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div class="min-w-0">
                <p class="eyebrow mb-2" dir="ltr">{{ $product->sku }}</p>
                <h1 class="font-display text-3xl font-semibold tracking-tight text-ink-900 sm:text-4xl dark:text-ivory">{{ $product->trans('name') ?? $product->sku }}</h1>
                <p class="mt-2 text-sm text-ink-500">{{ __('catalog_admin.updated_at', ['when' => \App\Support\BusinessTime::display($product->updated_at)]) }}</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <x-admin.status :value="$product->status" />
                <span class="badge-neutral tabular-nums">{{ \App\Support\Money::of($product->price)->format() }}</span>
            </div>
        </div>

        <x-admin.tabs :current="$tab" :tabs="[
            'content' => ['file-text', __('catalog_admin.tab_content')],
            'pricing' => ['tag', __('catalog_admin.tab_pricing')],
            'variants' => ['layers', __('catalog_admin.tab_variants'), $counts['variants']],
            'media' => ['image', __('catalog_admin.tab_media'), $counts['media']],
            'upsell' => ['layers', __('catalog_admin.tab_upsell'), $counts['upsell'] ?: null],
            'specs' => ['clipboard', __('catalog_admin.tab_specs')],
            'history' => ['history', __('catalog_admin.tab_history')],
        ]" />

        @if ($tab === 'content')
            <form wire:submit="saveContent" class="grid gap-6 [&>*]:min-w-0 lg:grid-cols-3">
                <fieldset class="min-w-0 space-y-6 lg:col-span-2" @disabled(! $canEdit)>
                    @if ($canEdit)
                    <div class="flex flex-wrap items-center justify-between gap-3 rounded-2xl bg-brass-500/10 px-4 py-3">
                        <p class="text-sm text-ink-700 dark:text-sand-100"><span class="font-medium">{{ __('catalog_admin.ai_title') }}</span> <span class="text-ink-500">{{ $aiReady ? __('catalog_admin.ai_hint') : __('catalog_admin.ai_not_ready') }}</span></p>
                        <button type="button" wire:click="describeWithAi" wire:loading.attr="disabled" wire:target="describeWithAi" class="btn-secondary min-h-11"><x-icon name="sparkles" class="size-4" /><span wire:loading.remove wire:target="describeWithAi">{{ __('catalog_admin.ai_write') }}</span><span wire:loading wire:target="describeWithAi">{{ __('catalog_admin.ai_writing') }}</span></button>
                    </div>
                @endif
                <div class="card overflow-hidden">
                        <div class="grid md:grid-cols-2 md:divide-x md:divide-sand-100 rtl:md:divide-x-reverse dark:md:divide-ink-800">
                            @foreach ($langs as $l => [$dir, $label])
                                <div class="space-y-5 p-5 sm:p-6" wire:key="c-{{ $l }}">
                                    <div class="flex items-center justify-between"><p class="eyebrow">{{ $label }}</p><span class="badge-neutral font-mono uppercase">{{ $l }}</span></div>
                                    <x-admin.field :label="__('catalog_admin.f_name')" :for="'tr.'.$l.'.name'" required><input id="tr.{{ $l }}.name" wire:model="tr.{{ $l }}.name" class="input" dir="{{ $dir }}" lang="{{ $l }}"></x-admin.field>
                                    <x-admin.field :label="__('catalog_admin.f_short_description')" :for="'tr.'.$l.'.short_description'"><textarea id="tr.{{ $l }}.short_description" wire:model="tr.{{ $l }}.short_description" rows="2" class="input" dir="{{ $dir }}" lang="{{ $l }}"></textarea></x-admin.field>
                                    <x-admin.field :label="__('catalog_admin.f_description')" :for="'tr.'.$l.'.description'" :hint="__('catalog_admin.description_hint')">
                                        <x-admin.rich-text :id="'tr.'.$l.'.description'" :dir="$dir" wire:model="tr.{{ $l }}.description" :lang="$l" />
                                    </x-admin.field>
                                    <x-admin.field :label="__('catalog_admin.f_material')" :for="'tr.'.$l.'.material'"><input id="tr.{{ $l }}.material" wire:model="tr.{{ $l }}.material" class="input" dir="{{ $dir }}" lang="{{ $l }}"></x-admin.field>
                                </div>
                            @endforeach
                        </div>
                    </div>
                    <div class="card overflow-hidden">
                        <div class="border-b border-sand-100 p-5 sm:px-6 dark:border-ink-800"><h2 class="font-display text-xl font-semibold">{{ __('catalog_admin.seo_search') }}</h2><p class="mt-1 text-sm text-ink-500">{{ __('catalog_admin.seo_hint') }}</p></div>
                        <div class="grid md:grid-cols-2 md:divide-x md:divide-sand-100 rtl:md:divide-x-reverse dark:md:divide-ink-800">
                            @foreach ($langs as $l => [$dir, $label])
                                <div class="space-y-5 p-5 sm:p-6" wire:key="s-{{ $l }}">
                                    <p class="eyebrow">{{ $label }}</p>
                                    <x-admin.field :label="__('catalog_admin.f_slug')" :for="'tr.'.$l.'.slug'"><input id="tr.{{ $l }}.slug" wire:model="tr.{{ $l }}.slug" class="input font-mono text-xs" dir="{{ $dir }}"></x-admin.field>
                                    <x-admin.field :label="__('catalog_admin.f_seo_title')" :for="'tr.'.$l.'.seo_title'"><input id="tr.{{ $l }}.seo_title" wire:model="tr.{{ $l }}.seo_title" class="input" dir="{{ $dir }}" lang="{{ $l }}" maxlength="255"></x-admin.field>
                                    <x-admin.field :label="__('catalog_admin.f_seo_description')" :for="'tr.'.$l.'.seo_description'">
                                        <div x-data="{ n: ($wire.tr.{{ $l }}.seo_description || '').length }">
                                            <textarea id="tr.{{ $l }}.seo_description" wire:model="tr.{{ $l }}.seo_description" @input="n = $event.target.value.length" rows="3" maxlength="500" class="input" dir="{{ $dir }}" lang="{{ $l }}"></textarea>
                                            <p dir="ltr" class="mt-1 text-start text-[0.7rem] tabular-nums" :class="n > 160 ? 'text-amber-soft' : 'text-ink-400'"><span x-text="n"></span> / 160</p>
                                        </div>
                                    </x-admin.field>
                                    <x-admin.field :label="__('catalog_admin.f_search_keywords')" :for="'tr.'.$l.'.search_keywords'" :hint="__('catalog_admin.keywords_hint')"><input id="tr.{{ $l }}.search_keywords" wire:model="tr.{{ $l }}.search_keywords" class="input" dir="{{ $dir }}" lang="{{ $l }}"></x-admin.field>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </fieldset>
                <div class="space-y-6">
                    <fieldset class="card card-pad space-y-4 lg:sticky lg:top-24" @disabled(! $canEdit)>
                        <h2 class="font-display text-xl font-semibold">{{ __('catalog_admin.organisation') }}</h2>
                        <x-admin.field :label="__('admin.status')" for="form.status">
                            <select id="form.status" wire:model="form.status" class="input pe-10 rtl:[background-position:left_0.5rem_center]">@foreach (\App\Domain\Catalog\Models\Product::STATUSES as $st)<option value="{{ $st }}">{{ __('status.'.$st) }}</option>@endforeach</select>
                        </x-admin.field>
                        <x-admin.field label="SKU" for="form.sku" required><input id="form.sku" wire:model="form.sku" class="input font-mono uppercase" dir="ltr"></x-admin.field>
                        <x-admin.field :label="__('catalog_admin.barcode')" for="form.barcode"><input id="form.barcode" wire:model="form.barcode" class="input font-mono" dir="ltr"></x-admin.field>
                        <x-admin.field :label="__('catalog_admin.category')" for="form.category_id">
                            <select id="form.category_id" wire:model="form.category_id" class="input pe-10 rtl:[background-position:left_0.5rem_center]"><option value="">—</option>@foreach ($categories as $c)<option value="{{ $c->id }}">{{ str_repeat('— ', $c->depth) }}{{ $c->trans('name') }}</option>@endforeach</select>
                        </x-admin.field>
                        <x-admin.field :label="__('catalog_admin.brand')" for="form.brand_id">
                            <select id="form.brand_id" wire:model="form.brand_id" class="input pe-10 rtl:[background-position:left_0.5rem_center]"><option value="">—</option>@foreach ($brands as $b)<option value="{{ $b->id }}">{{ $b->localized('name') }}</option>@endforeach</select>
                        </x-admin.field>
                        <x-admin.field :label="__('catalog_admin.tags')" for="tagsInput" :hint="__('catalog_admin.tags_hint')"><input id="tagsInput" wire:model="tagsInput" class="input" placeholder="linen, oak, living room"></x-admin.field>
                        @if ($canEdit)<button class="btn-primary w-full" wire:loading.attr="disabled" wire:target="saveContent"><x-icon name="check" class="size-4" />{{ __('admin.save_changes') }}</button>@endif
                    </fieldset>
                </div>
            </form>

        @elseif ($tab === 'pricing')
            <livewire:admin.catalog.product-pricing :product="$product" :key="'pricing-'.$product->id" />
        @elseif ($tab === 'variants')
            <livewire:admin.catalog.product-variants :product="$product" :key="'variants-'.$product->id" />
        @elseif ($tab === 'media')
            <livewire:admin.catalog.product-media :product="$product" :key="'media-'.$product->id" />

        @elseif ($tab === 'upsell')
            <livewire:admin.catalog.product-upsell :product="$product" :key="'upsell-'.$product->id" />

        @elseif ($tab === 'specs')
            <form wire:submit="saveSpecs" class="grid gap-6 [&>*]:min-w-0 lg:grid-cols-3">
                <fieldset class="min-w-0 space-y-6 lg:col-span-2" @disabled(! $canEdit)>
                    <div class="card overflow-hidden">
                        <div class="flex items-center justify-between border-b border-sand-100 p-5 sm:px-6 dark:border-ink-800">
                            <div><h2 class="font-display text-xl font-semibold">{{ __('catalog_admin.specifications') }}</h2><p class="mt-1 text-sm text-ink-500">{{ __('catalog_admin.specifications_hint') }}</p></div>
                            @if ($canEdit)<button type="button" wire:click="addSpec" class="btn-secondary btn-sm"><x-icon name="plus" class="size-3.5" />{{ __('catalog_admin.add_spec') }}</button>@endif
                        </div>
                        @forelse ($specs as $i => $s)
                            <div class="grid gap-3 border-b border-sand-100 p-4 sm:grid-cols-[1fr_1fr_auto] sm:px-6 dark:border-ink-800" wire:key="spec-{{ $i }}">
                                <div class="grid grid-cols-2 gap-2">
                                    <input wire:model="specs.{{ $i }}.label_en" class="input" dir="ltr" placeholder="{{ __('catalog_admin.spec_label') }} (EN)" aria-label="{{ __('catalog_admin.spec_label') }} EN">
                                    <input wire:model="specs.{{ $i }}.value_en" class="input" dir="ltr" placeholder="{{ __('catalog_admin.spec_value') }} (EN)" aria-label="{{ __('catalog_admin.spec_value') }} EN">
                                </div>
                                <div class="grid grid-cols-2 gap-2">
                                    <input wire:model="specs.{{ $i }}.label_ar" class="input" dir="rtl" lang="ar" placeholder="{{ __('catalog_admin.spec_label') }} (AR)" aria-label="{{ __('catalog_admin.spec_label') }} AR">
                                    <input wire:model="specs.{{ $i }}.value_ar" class="input" dir="rtl" lang="ar" placeholder="{{ __('catalog_admin.spec_value') }} (AR)" aria-label="{{ __('catalog_admin.spec_value') }} AR">
                                </div>
                                <div class="flex items-center justify-end gap-0.5">
                                    <button type="button" wire:click="moveSpec({{ $i }}, -1)" class="btn-ghost btn-sm px-2" @disabled($loop->first) aria-label="{{ __('catalog_admin.move_up') }}"><x-icon name="chevron-down" class="size-4 rotate-180" /></button>
                                    <button type="button" wire:click="moveSpec({{ $i }}, 1)" class="btn-ghost btn-sm px-2" @disabled($loop->last) aria-label="{{ __('catalog_admin.move_down') }}"><x-icon name="chevron-down" class="size-4" /></button>
                                    <button type="button" wire:click="removeSpec({{ $i }})" class="btn-ghost btn-sm px-2 text-ruby-soft" aria-label="{{ __('org_admin.remove') }}"><x-icon name="trash" class="size-4" /></button>
                                </div>
                                @if ($errors->has("specs.{$i}.*"))<p class="field-error sm:col-span-3">{{ __('catalog_admin.spec_incomplete') }}</p>@endif
                            </div>
                        @empty
                            <x-admin.empty :title="__('catalog_admin.no_specs')" icon="clipboard" class="!py-10" />
                        @endforelse
                    </div>

                    <div class="card card-pad space-y-5">
                        <h2 class="font-display text-xl font-semibold">{{ __('catalog_admin.dimensions') }}</h2>
                        <div class="grid gap-4 sm:grid-cols-4">
                            @foreach (['length', 'width', 'height'] as $d)
                                <x-admin.field :label="__('catalog_admin.'.$d)" :for="'form.'.$d"><input id="form.{{ $d }}" wire:model="form.{{ $d }}" inputmode="decimal" class="input tabular-nums" dir="ltr"></x-admin.field>
                            @endforeach
                            <x-admin.field :label="__('catalog_admin.unit')" for="form.dimension_unit"><select id="form.dimension_unit" wire:model="form.dimension_unit" class="input pe-10 rtl:[background-position:left_0.5rem_center]">@foreach (['cm', 'm', 'mm'] as $u)<option value="{{ $u }}">{{ $u }}</option>@endforeach</select></x-admin.field>
                        </div>
                        <div class="grid gap-4 sm:grid-cols-4">
                            <x-admin.field :label="__('catalog_admin.weight')" for="form.weight" class="sm:col-span-3"><input id="form.weight" wire:model="form.weight" inputmode="decimal" class="input tabular-nums" dir="ltr"></x-admin.field>
                            <x-admin.field :label="__('catalog_admin.unit')" for="form.weight_unit"><select id="form.weight_unit" wire:model="form.weight_unit" class="input pe-10 rtl:[background-position:left_0.5rem_center]"><option value="kg">kg</option><option value="g">g</option></select></x-admin.field>
                        </div>
                        <x-admin.field :label="__('catalog_admin.cost_price')" for="form.cost_price" :hint="__('catalog_admin.cost_price_hint')" class="sm:w-1/2"><input id="form.cost_price" wire:model="form.cost_price" inputmode="decimal" class="input tabular-nums" dir="ltr"></x-admin.field>
                    </div>

                    <div class="card card-pad space-y-5">
                        <h2 class="font-display text-xl font-semibold">{{ __('catalog_admin.warranty') }}</h2>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <x-admin.field :label="__('catalog_admin.warranty_months')" for="warranty.duration_months" :hint="__('catalog_admin.warranty_hint')"><input id="warranty.duration_months" type="number" min="1" max="240" wire:model="warranty.duration_months" class="input tabular-nums"></x-admin.field>
                            <x-admin.field :label="__('catalog_admin.warranty_provider')" for="warranty.provider"><input id="warranty.provider" wire:model="warranty.provider" class="input" placeholder="{{ __('catalog_admin.warranty_provider_ph') }}"></x-admin.field>
                            <x-admin.field :label="__('catalog_admin.terms_en')" for="warranty.terms_en"><textarea id="warranty.terms_en" wire:model="warranty.terms_en" rows="3" class="input" dir="ltr"></textarea></x-admin.field>
                            <x-admin.field :label="__('catalog_admin.terms_ar')" for="warranty.terms_ar"><textarea id="warranty.terms_ar" wire:model="warranty.terms_ar" rows="3" class="input" dir="rtl" lang="ar"></textarea></x-admin.field>
                        </div>
                    </div>
                </fieldset>
                <div class="space-y-6">
                    <fieldset class="card card-pad space-y-3 lg:sticky lg:top-24" @disabled(! $canEdit)>
                        <h2 class="mb-1 font-display text-xl font-semibold">{{ __('catalog_admin.flags') }}</h2>
                        @foreach (['is_featured', 'is_new_arrival', 'is_bestseller', 'requires_assembly', 'assembly_service_available', 'is_oversized', 'available_in_all_branches', 'allow_sale_above_price'] as $flag)
                            <x-admin.toggle :label="__('catalog_admin.flag.'.$flag)" :hint="\Illuminate\Support\Facades\Lang::has('catalog_admin.flag_hint.'.$flag) ? __('catalog_admin.flag_hint.'.$flag) : null" wire:model="form.{{ $flag }}" />
                        @endforeach
                        @if ($canEdit)<button class="btn-primary mt-2 w-full" wire:loading.attr="disabled" wire:target="saveSpecs"><x-icon name="check" class="size-4" />{{ __('admin.save_changes') }}</button>@endif
                    </fieldset>
                </div>
            </form>

        @else
            <div class="grid gap-6 [&>*]:min-w-0 xl:grid-cols-2">
                <div class="card overflow-hidden">
                    <div class="p-5 sm:p-6"><h2 class="font-display text-xl font-semibold">{{ __('catalog_admin.price_history') }}</h2></div>
                    @if ($priceHistory->isEmpty())
                        <x-admin.empty :title="__('catalog_admin.no_price_changes')" icon="tag" class="!py-10" />
                    @else
                        <div class="table-wrap"><table class="table">
                            <thead><tr><th>{{ __('admin.date') }}</th><th>{{ __('catalog_admin.level') }}</th><th class="!text-end">{{ __('catalog_admin.price') }}</th><th class="!text-end">{{ __('catalog_admin.sale_price') }}</th><th>{{ __('admin.reason') }}</th></tr></thead>
                            <tbody>@foreach ($priceHistory as $h)
                                <tr wire:key="ph-{{ $h->id }}">
                                    <td class="whitespace-nowrap text-xs text-ink-500 tabular-nums">{{ \App\Support\BusinessTime::display($h->created_at) }}<span class="block">{{ $h->actor?->name }}</span></td>
                                    <td class="text-xs">
                                        @if ($h->branch_id)<span class="badge-brass font-mono" dir="ltr">{{ $historyBranches[$h->branch_id] ?? '—' }}</span>@else<span class="badge-neutral">{{ __('catalog_admin.global') }}</span>@endif
                                        @if ($h->product_variant_id)<span class="mt-1 block font-mono text-ink-500" dir="ltr">{{ $historyVariants[$h->product_variant_id] ?? '' }}</span>@endif
                                    </td>
                                    <td class="whitespace-nowrap text-end text-xs tabular-nums"><span class="text-ink-400">{{ $h->old_price ?? '—' }}</span> → <span class="font-medium">{{ $h->new_price ?? '—' }}</span></td>
                                    <td class="whitespace-nowrap text-end text-xs tabular-nums"><span class="text-ink-400">{{ $h->old_sale_price ?? '—' }}</span> → <span class="font-medium">{{ $h->new_sale_price ?? '—' }}</span></td>
                                    <td class="max-w-[12rem] truncate text-xs text-ink-500" title="{{ $h->reason }}">{{ $h->reason ?: '—' }}</td>
                                </tr>
                            @endforeach</tbody>
                        </table></div>
                    @endif
                </div>
                <div class="card overflow-hidden">
                    <div class="flex items-center justify-between p-5 sm:p-6">
                        <h2 class="font-display text-xl font-semibold">{{ __('catalog_admin.change_log') }}</h2>
                        @if ($this->can('audit.view'))<a href="{{ route('admin.audit.index', ['subject' => 'Product:'.$product->id]) }}" wire:navigate class="btn-ghost btn-sm">{{ __('admin.view_all') }}<x-icon name="arrow-right" class="size-4 rtl-flip" /></a>@endif
                    </div>
                    @forelse ($auditLog as $log)
                        <details class="group border-t border-sand-100 px-5 py-3.5 sm:px-6 dark:border-ink-800" wire:key="al-{{ $log->id }}">
                            <summary class="flex cursor-pointer list-none items-center gap-3">
                                <span class="grid size-8 shrink-0 place-items-center rounded-lg bg-sand-100 text-ink-600 dark:bg-ink-800 dark:text-sand-200"><x-icon name="history" class="size-4" /></span>
                                <span class="min-w-0 flex-1"><x-admin.audit-action :action="$log->action" class="block text-sm font-medium" /><span class="block text-xs text-ink-500 tabular-nums">{{ \App\Support\BusinessTime::display($log->created_at) }} · {{ $log->actor?->name ?? __('org_admin.system') }}</span></span>
                                <x-icon name="chevron-down" class="size-4 text-ink-400 transition group-open:rotate-180" />
                            </summary>
                            <x-admin.json-diff :old="$log->old_values" :new="$log->new_values" :metadata="$log->metadata" class="mt-3" />
                        </details>
                    @empty
                        <x-admin.empty :title="__('org_admin.no_activity')" icon="history" class="!py-10" />
                    @endforelse
                </div>
            </div>
        @endif
    @endif
</div>
