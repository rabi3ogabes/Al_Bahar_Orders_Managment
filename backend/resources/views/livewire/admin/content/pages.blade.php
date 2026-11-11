<div>
    <x-admin.page-header :title="__('nav.pages')" :eyebrow="__('nav.content')" :description="__('content_admin.pages_hint')">
        <x-slot:actions>@if ($canCreate)<button type="button" wire:click="create" class="btn-primary"><x-icon name="plus" class="size-4" />{{ __('content_admin.new_page') }}</button>@endif</x-slot:actions>
    </x-admin.page-header>

    <div class="card">
        <x-admin.toolbar><x-admin.search :placeholder="__('content_admin.pages_search')" /></x-admin.toolbar>
        @if ($pages->isEmpty())
            <x-admin.empty :title="__('content_admin.pages_empty')" :description="__('content_admin.pages_empty_hint')" icon="file-text" />
        @else
            <div class="table-wrap"><table class="table">
                <thead><tr><th>{{ __('content_admin.page') }}</th><th>{{ __('content_admin.slug') }}</th><th>{{ __('content_admin.languages') }}</th><th>{{ __('admin.updated') }}</th><th>{{ __('admin.status') }}</th><th class="w-24"></th></tr></thead>
                <tbody>@foreach ($pages as $p)
                    @php $en = $p->translations->firstWhere('locale', 'en'); $ar = $p->translations->firstWhere('locale', 'ar'); $tr = app()->getLocale() === 'ar' ? ($ar ?? $en) : ($en ?? $ar); $pending = $p->translations->contains(fn ($t) => $t->draft_body !== null && $t->draft_body !== $t->body); @endphp
                    <tr wire:key="pg-{{ $p->id }}">
                        <td class="max-w-[16rem]"><span class="block truncate font-medium text-ink-900 dark:text-ivory">{{ $tr?->title ?? '—' }}</span>@if ($pending && $p->status === 'published')<span class="text-xs text-amber-soft">{{ __('content_admin.unpublished_changes') }}</span>@endif</td>
                        <td class="font-mono text-xs" dir="ltr">/{{ $p->slug }}</td>
                        <td><span class="badge-neutral {{ $en ? '' : 'opacity-40' }}">EN</span> <span class="badge-neutral {{ $ar ? '' : 'opacity-40' }}">ع</span></td>
                        <td class="text-xs text-ink-500 tabular-nums">{{ \App\Support\BusinessTime::display($p->updated_at) }}</td>
                        <td><x-admin.status :value="$p->status" /></td>
                        <td><div class="flex justify-end gap-1">
                            @if ($canEdit && $p->status === 'published')<button type="button" wire:click="unpublish('{{ $p->id }}')" wire:confirm="{{ __('content_admin.confirm_unpublish') }}" class="btn-ghost btn-sm px-2" title="{{ __('content_admin.unpublish') }}"><x-icon name="eye" class="size-4" /></button>@endif
                            @if ($canEdit)<button type="button" wire:click="edit('{{ $p->id }}')" class="btn-ghost btn-sm px-2" aria-label="{{ __('admin.edit') }}"><x-icon name="edit" class="size-4" /></button>@endif
                        </div></td>
                    </tr>
                @endforeach</tbody>
            </table></div>
            @if ($pages->hasPages())<div class="border-t border-sand-100 p-4 dark:border-ink-800">{{ $pages->links('components.admin.pagination') }}</div>@endif
        @endif
    </div>

    <x-admin.dialog wire:model="showEditor" :title="$editingId ? __('content_admin.edit_page') : __('content_admin.new_page')" max-width="max-w-4xl">
        <form wire:submit="save" class="space-y-5" x-data="{ lang: 'en' }">
            <x-admin.field :label="__('content_admin.slug')" for="slug" required :hint="__('content_admin.slug_hint')">
                <div class="flex items-center rounded-xl border border-sand-300 bg-white shadow-sm focus-within:border-brass-500 focus-within:ring-2 focus-within:ring-brass-500/25 dark:border-ink-600 dark:bg-ink-800" dir="ltr">
                    <span class="ps-3.5 text-sm text-ink-400">/pages/</span><input id="slug" wire:model="slug" class="w-full border-0 bg-transparent py-2.5 pe-3.5 ps-0.5 font-mono text-sm focus:ring-0" autocomplete="off">
                </div>
            </x-admin.field>
            <div class="flex items-center gap-2 border-b border-sand-100 dark:border-ink-800" role="tablist">
                @foreach (['en' => 'English', 'ar' => 'العربية'] as $l => $label)
                    <button type="button" @click="lang = '{{ $l }}'" :class="lang === '{{ $l }}' ? 'border-brass-500 text-ink-900 dark:text-ivory' : 'border-transparent text-ink-500'" class="-mb-px border-b-2 px-3 py-2 text-sm font-medium">{{ $label }}@error('tr.'.$l.'.title')<span class="ms-1 text-ruby-soft">•</span>@enderror</button>
                @endforeach
                <button type="button" @click="$wire.set('previewLocale', $wire.previewLocale ? '' : lang)" class="btn-ghost btn-sm ms-auto"><x-icon name="eye" class="size-4" />{{ __('content_admin.preview') }}</button>
            </div>
            @foreach (['en' => 'ltr', 'ar' => 'rtl'] as $l => $dir)
                <div x-show="lang === '{{ $l }}'" @if ($l === 'ar') x-cloak @endif class="space-y-4" dir="{{ $dir }}">
                    <x-admin.field :label="__('content_admin.title_'.$l)" for="tr.{{ $l }}.title" required><input id="tr.{{ $l }}.title" wire:model="tr.{{ $l }}.title" class="input"></x-admin.field>
                    <div>
                        <label for="body-{{ $l }}" class="field-label">{{ __('content_admin.body') }}</label>
                        <x-admin.rich-text id="body-{{ $l }}" :dir="$dir" rows="12" wire:model="tr.{{ $l }}.body" />
                        <p class="field-hint">{{ __('content_admin.body_hint') }}</p>
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2 [&>*]:min-w-0">
                        <x-admin.field :label="__('content_admin.seo_title')" for="tr.{{ $l }}.seo_title"><input id="tr.{{ $l }}.seo_title" wire:model="tr.{{ $l }}.seo_title" class="input"></x-admin.field>
                        <x-admin.field :label="__('content_admin.seo_description')" for="tr.{{ $l }}.seo_description"><input id="tr.{{ $l }}.seo_description" wire:model="tr.{{ $l }}.seo_description" class="input"></x-admin.field>
                    </div>
                </div>
            @endforeach
            @if ($previewHtml !== null)
                <div class="rounded-2xl border border-sand-200 bg-ivory p-5 dark:border-ink-700 dark:bg-ink-950" dir="{{ $previewLocale === 'ar' ? 'rtl' : 'ltr' }}">
                    <p class="mb-3 text-xs font-semibold uppercase tracking-wider text-ink-500">{{ __('content_admin.preview') }} · {{ strtoupper($previewLocale) }}</p>
                    <h2 class="mb-3 font-display text-2xl font-semibold">{{ $tr[$previewLocale]['title'] ?? '' }}</h2>
                    <div class="space-y-3 text-sm leading-relaxed text-ink-700 dark:text-sand-100 [&_h3]:mt-4 [&_h3]:font-semibold [&_ul]:list-disc [&_ul]:ps-5">{!! $previewHtml !!}</div>
                </div>
            @endif
            <div class="flex flex-col-reverse gap-2 border-t border-sand-100 pt-5 sm:flex-row sm:justify-end dark:border-ink-800">
                <button type="button" class="btn-secondary" @click="open = false">{{ __('admin.cancel') }}</button>
                <button class="btn-secondary" wire:loading.attr="disabled" wire:target="save"><x-icon name="file-text" class="size-4" />{{ __('content_admin.save_draft') }}</button>
                <button type="button" wire:click="publish" wire:loading.attr="disabled" wire:target="publish" class="btn-primary"><x-icon name="sparkles" class="size-4" />{{ __('content_admin.publish') }}</button>
            </div>
        </form>
    </x-admin.dialog>
</div>
