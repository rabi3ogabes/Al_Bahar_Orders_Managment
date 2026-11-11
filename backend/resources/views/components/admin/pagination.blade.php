{{-- On-brand Livewire pagination: $paginator->links('components.admin.pagination'). Compact on phones, numbered on larger screens. --}}
@php $scroll = "(\$el.closest('main') || document.body).scrollIntoView({ behavior: 'smooth' })"; $page = $paginator->getPageName(); @endphp
<div>
    @if ($paginator->hasPages())
        <nav role="navigation" aria-label="{{ __('org_admin.page_nav') }}" class="flex items-center justify-between gap-3">
            <p class="text-xs text-ink-500 tabular-nums">
                {{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} / {{ $paginator->total() }}
            </p>
            <div class="flex items-center gap-1">
                <button type="button" wire:click="previousPage('{{ $page }}')" x-on:click="{{ $scroll }}" @disabled($paginator->onFirstPage()) class="btn-ghost btn-sm px-2" aria-label="{{ __('org_admin.prev_page') }}"><x-icon name="chevron-left" class="size-4 rtl-flip" /></button>
                <div class="hidden items-center gap-1 sm:flex">
                    @foreach ($elements as $element)
                        @if (is_string($element))
                            <span class="px-1.5 text-xs text-ink-400">…</span>
                        @endif
                        @if (is_array($element))
                            @foreach ($element as $n => $url)
                                <button type="button" wire:key="pg-{{ $page }}-{{ $n }}" wire:click="gotoPage({{ $n }}, '{{ $page }}')" x-on:click="{{ $scroll }}" @if ($n == $paginator->currentPage()) aria-current="page" @endif
                                        class="grid h-8 min-w-8 place-items-center rounded-lg px-2 text-xs font-medium tabular-nums transition {{ $n == $paginator->currentPage() ? 'bg-ink-900 text-ivory dark:bg-brass-500 dark:text-ink-950' : 'text-ink-600 hover:bg-sand-100 dark:text-ink-300 dark:hover:bg-ink-800' }}">{{ $n }}</button>
                            @endforeach
                        @endif
                    @endforeach
                </div>
                <span class="px-2 text-xs text-ink-500 tabular-nums sm:hidden">{{ $paginator->currentPage() }} / {{ $paginator->lastPage() }}</span>
                <button type="button" wire:click="nextPage('{{ $page }}')" x-on:click="{{ $scroll }}" @disabled(! $paginator->hasMorePages()) class="btn-ghost btn-sm px-2" aria-label="{{ __('org_admin.next_page') }}"><x-icon name="chevron-right" class="size-4 rtl-flip" /></button>
            </div>
        </nav>
    @endif
</div>
