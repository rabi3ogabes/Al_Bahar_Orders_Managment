@php
    use App\Domain\Cms\Services\BannerLinks;
    $type = (string) ($slide['link_type'] ?? '');
    $value = (string) ($slide['link_value'] ?? '');
    $kinds = ['' => 'ban', 'category' => 'layers', 'page' => 'file-text', 'product' => 'package', 'brand' => 'crown', 'auction' => 'gavel', 'url' => 'globe', 'search' => 'search'];
    $summary = $editor['summary'];
@endphp
<fieldset class="space-y-3">
    <legend class="field-label">{{ __('banners_admin.links_to') }}</legend>
    <div class="flex flex-wrap gap-2" role="radiogroup" aria-label="{{ __('banners_admin.links_to') }}">
        @foreach ($kinds as $key => $icon)
            <label class="relative cursor-pointer">
                <input type="radio" class="peer sr-only" wire:model.live="slide.link_type" value="{{ $key }}" name="link_kind">
                <span class="inline-flex min-h-10 items-center gap-1.5 rounded-full border border-sand-200 px-3.5 text-sm text-ink-600 transition hover:border-brass-400 peer-checked:border-brass-500 peer-checked:bg-brass-50 peer-checked:font-medium peer-checked:text-ink-900 peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-brass-500 dark:border-ink-700 dark:text-ink-300 dark:peer-checked:bg-brass-500/10 dark:peer-checked:text-ivory">
                    <x-icon :name="$icon" class="size-4" />{{ $key === '' ? __('content_admin.no_link') : __('content_admin.link_'.$key) }}
                </span>
            </label>
        @endforeach
    </div>

    @if ($type === '')
        <p class="flex items-start gap-2 rounded-xl bg-sand-50 px-3.5 py-2.5 text-sm text-ink-500 dark:bg-ink-800/50"><x-icon name="info" class="mt-0.5 size-4 shrink-0" />{{ __('banners_admin.no_link_hint') }}</p>
    @else
        {{-- What the slide opens, in plain words (and a warning when it no longer works). --}}
        @if ($summary && ($summary['ok'] || ! in_array($type, ['url', 'search'], true)))
            <div class="flex items-center gap-3 rounded-xl border px-3.5 py-2.5 {{ $summary['ok'] ? 'border-brass-400/50 bg-brass-50/60 dark:bg-brass-500/10' : 'border-ruby-soft/40 bg-ruby-soft/5' }}" role="status">
                <x-icon :name="$summary['ok'] ? ($kinds[$type] ?? 'link') : 'alert'" class="size-5 shrink-0 {{ $summary['ok'] ? 'text-brass-600' : 'text-ruby-soft' }}" />
                <div class="min-w-0 flex-1 text-sm">
                    <p class="truncate font-medium text-ink-900 dark:text-ivory">{{ $summary['ok'] ? __('banners_admin.opens_'.$type, ['name' => $summary['label']]) : __('banners_admin.link_gone') }}</p>
                    @if ($summary['ok'] && $summary['sub'])<p class="truncate text-xs text-ink-500" dir="ltr">{{ $summary['sub'] }}</p>@endif
                    @unless ($summary['ok'])<p class="text-xs text-ink-500">{{ __('banners_admin.link_gone_hint') }}</p>@endunless
                </div>
                @if ($type === 'url' && $summary['ok'])<a href="{{ $value }}" target="_blank" rel="noopener noreferrer" class="btn-ghost btn-sm shrink-0" data-no-dirty><x-icon name="external" class="size-3.5" />{{ __('banners_admin.test_link') }}</a>@endif
                @if (! in_array($type, ['url', 'search'], true))<button type="button" wire:click="pickLink('')" @click="dirty = true" class="btn-ghost btn-sm shrink-0">{{ __('banners_admin.change') }}</button>@endif
            </div>
        @endif

        @if ($type === 'url')
            <x-admin.field for="slide.link_value" :hint="__('banners_admin.url_hint')">
                <input id="slide.link_value" type="url" inputmode="url" wire:model.blur="slide.link_value" class="input font-mono text-xs" dir="ltr" placeholder="https://example.com/offers" maxlength="1000" autocomplete="off" aria-label="{{ __('content_admin.link_value') }}">
            </x-admin.field>
        @elseif ($type === 'search')
            <x-admin.field for="slide.link_value" :hint="__('banners_admin.search_hint')">
                <input id="slide.link_value" wire:model.blur="slide.link_value" class="input" placeholder="{{ __('banners_admin.search_ph') }}" maxlength="120" autocomplete="off" aria-label="{{ __('content_admin.link_value') }}">
            </x-admin.field>
        @elseif ($type === 'product')
            <div>
                <input type="search" wire:model.live.debounce.300ms="linkSearch" class="input" placeholder="{{ __('content_admin.search_product') }}" aria-label="{{ __('content_admin.search_product') }}" autocomplete="off">
                @if ($editor['choices'])
                    <ul class="mt-2 divide-y divide-sand-100 overflow-hidden rounded-xl border border-sand-200 dark:divide-ink-800 dark:border-ink-700" role="listbox" aria-label="{{ __('banners_admin.results') }}">
                        @foreach ($editor['choices'] as $c)
                            <li><button type="button" wire:click="pickLink('{{ $c['value'] }}')" @click="dirty = true" role="option" aria-selected="{{ $c['value'] === $value ? 'true' : 'false' }}" class="flex w-full items-center justify-between gap-3 px-3.5 py-2.5 text-start text-sm hover:bg-sand-50 dark:hover:bg-ink-800"><span class="min-w-0"><span class="block truncate">{{ $c['label'] }}</span>@if ($c['sub'])<span class="block truncate text-xs text-ink-500" dir="ltr">{{ $c['sub'] }}</span>@endif</span>@if ($c['value'] === $value)<x-icon name="check" class="size-4 shrink-0 text-brass-600" />@endif</button></li>
                        @endforeach
                    </ul>
                @elseif (mb_strlen(trim($linkSearch)) >= 2)
                    <p class="field-hint">{{ __('banners_admin.no_results') }}</p>
                @else
                    <p class="field-hint">{{ __('banners_admin.search_product_hint') }}</p>
                @endif
                @error('slide.link_value')<p class="field-error" role="alert">{{ $message }}</p>@enderror
            </div>
        @else
            {{-- Category tree, pages, brands, auctions: a short list the admin filters by typing. --}}
            <div x-data="{ q: '' }" class="overflow-hidden rounded-xl border border-sand-200 dark:border-ink-700">
                <div class="border-b border-sand-100 p-2 dark:border-ink-800"><input type="search" x-model="q" class="input" placeholder="{{ __('banners_admin.filter_list') }}" aria-label="{{ __('banners_admin.filter_list') }}" autocomplete="off" data-no-dirty></div>
                @if ($editor['choices'])
                    <ul class="max-h-60 overflow-y-auto p-1" role="listbox" aria-label="{{ __('content_admin.link_'.$type) }}">
                        @foreach ($editor['choices'] as $c)
                            <li x-show="! q || @js(mb_strtolower($c['label'].' '.($c['sub'] ?? ''))).includes(q.toLowerCase())">
                                <button type="button" wire:click="pickLink('{{ $c['value'] }}')" @click="dirty = true" role="option" aria-selected="{{ $c['value'] === $value ? 'true' : 'false' }}"
                                        class="flex w-full items-center justify-between gap-3 rounded-lg py-2 pe-3 text-start text-sm hover:bg-sand-50 dark:hover:bg-ink-800 {{ $c['value'] === $value ? 'bg-brass-50 font-medium dark:bg-brass-500/10' : '' }}" style="padding-inline-start: {{ 0.75 + $c['depth'] * 1.1 }}rem">
                                    <span class="min-w-0 truncate">@if ($c['depth'] > 0)<span class="text-ink-300" aria-hidden="true">└ </span>@endif{{ $c['label'] }}@if ($c['sub'])<span class="ms-2 text-xs text-ink-500" dir="ltr">{{ $c['sub'] }}</span>@endif</span>
                                    @if ($c['value'] === $value)<x-icon name="check" class="size-4 shrink-0 text-brass-600" />@endif
                                </button>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <p class="px-4 py-6 text-center text-sm text-ink-500">{{ __('banners_admin.nothing_to_pick_'.$type) }}</p>
                @endif
            </div>
            @error('slide.link_value')<p class="field-error" role="alert">{{ $message }}</p>@enderror
        @endif
    @endif
</fieldset>
