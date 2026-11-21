{{--
    The dashboard's global search: a window in the middle of the screen (the whole screen on a phone) opened by the Search button in the top bar,
    Ctrl/Cmd+K or "/". One box; the answer comes in groups (orders, customers, products and variants, pages to go to). Arrow keys move, Enter opens,
    Esc closes. It is a combobox with a listbox (focus stays in the box, the highlighted result is named by aria-activedescendant), traps the focus while
    open and gives it back to what had it. It registers with the overlay stack (resources/js/overlays.js), so Esc and the phone's Back button close it first.
    The logic is resources/js/command-palette.js; what each group may contain is decided on the server (App\Domain\Search\Services\GlobalSearch).
    Include once, anywhere outside the sticky header (the header's blur would clip a fixed window).
--}}
@php
    $boot = [
        'url' => route('admin.search'),
        'user' => (string) auth()->id(),
        'text' => collect(['title', 'placeholder', 'results_none', 'results_one', 'results_many'])->mapWithKeys(fn ($k) => [$k => __('search.'.$k)])->all(),
    ];
    $hit = 'flex min-h-11 w-full items-center gap-3 rounded-xl px-3 py-2 text-start text-sm outline-none';
    $tile = 'grid size-9 shrink-0 place-items-center rounded-lg bg-sand-100 text-ink-600 dark:bg-ink-800 dark:text-sand-200';
@endphp
<div data-command-palette x-data="commandPalette(@js($boot))" x-on:open-command-palette.window="toggle($event)">
    <div x-cloak x-show="open" class="fixed inset-0 z-[74] flex items-start justify-center sm:px-4 sm:pt-[10vh]" role="dialog" aria-modal="true" aria-label="{{ __('search.title') }}">
        <div x-show="open" x-transition.opacity.duration.150ms class="absolute inset-0 bg-ink-950/50 backdrop-blur-[2px]" @click="hide()"></div>
        <div x-show="open" x-trap.noscroll.noautofocus="open"
             x-transition:enter="transition duration-200 ease-out motion-reduce:transition-none" x-transition:enter-start="opacity-0 sm:-translate-y-2 sm:scale-[0.98]" x-transition:enter-end="opacity-100 sm:translate-y-0 sm:scale-100"
             x-transition:leave="transition duration-150 ease-in motion-reduce:transition-none" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
             class="relative flex h-[100dvh] w-full flex-col overflow-hidden bg-ivory shadow-[var(--shadow-lift)] sm:h-auto sm:max-h-[min(36rem,80dvh)] sm:max-w-2xl sm:rounded-2xl sm:border sm:border-sand-200/70 dark:bg-ink-900 dark:sm:border-ink-700">

            {{-- The box --}}
            <div class="flex shrink-0 items-center gap-2 border-b border-sand-200/70 px-3 pt-[max(0.5rem,env(safe-area-inset-top))] pb-2 sm:px-4 sm:py-2 dark:border-ink-800">
                <x-icon name="search" class="size-5 shrink-0 text-ink-400" />
                <input x-ref="input" type="text" x-model="q" @input="input()" @keydown="key($event)" maxlength="64" dir="auto"
                       role="combobox" aria-autocomplete="list" aria-haspopup="listbox" aria-controls="cp-list" :aria-expanded="(phase === 'results' && rows.length > 0) ? 'true' : 'false'" :aria-activedescendant="active >= 0 ? 'cp-o-' + active : null"
                       autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false" enterkeyhint="search" inputmode="search"
                       aria-label="{{ __('search.label') }}" placeholder="{{ __('search.placeholder') }}"
                       class="min-h-11 min-w-0 flex-1 border-0 bg-transparent px-1 text-base text-ink-900 placeholder:text-ink-400 focus:ring-0 dark:text-sand-100">
                <span x-show="busy && slow" x-cloak class="size-4 shrink-0 animate-spin rounded-full border-2 border-sand-300 border-t-brass-500 motion-reduce:animate-none" aria-hidden="true"></span>
                <button type="button" x-show="q !== ''" x-cloak @click="clearInput()" class="btn-ghost size-11 shrink-0 p-0" aria-label="{{ __('search.clear_input') }}"><x-icon name="x" class="size-4" /></button>
                <button type="button" @click="hide()" class="btn-ghost min-h-11 shrink-0 px-3 text-xs font-medium" aria-label="{{ __('search.close') }}">
                    <span class="sm:hidden"><x-icon name="x" class="size-5" /></span>
                    <kbd class="hidden rounded-md border border-sand-300 px-1.5 py-0.5 font-sans text-[0.7rem] text-ink-500 sm:inline dark:border-ink-600 dark:text-ink-400">Esc</kbd>
                </button>
            </div>

            {{-- The answer --}}
            <div id="cp-list" role="listbox" aria-label="{{ __('search.title') }}" class="min-h-0 flex-1 overflow-y-auto overscroll-contain p-2 transition-opacity motion-reduce:transition-none" :class="busy && slow && phase === 'results' ? 'opacity-50' : ''">

                {{-- Nothing typed: the searches this person made lately, then what to type --}}
                <div x-show="phase === 'idle'" x-cloak>
                    <template x-if="recent.length">
                        <div role="group" aria-labelledby="cp-recent" class="mb-2">
                            <div class="flex items-center justify-between px-3 pb-1 pt-2">
                                <span id="cp-recent" class="eyebrow">{{ __('search.recent') }}</span>
                                <button type="button" @click="clearRecent()" class="min-h-11 rounded-lg px-2 text-xs font-medium text-ink-500 hover:text-ink-900 dark:text-ink-400 dark:hover:text-sand-100">{{ __('search.clear_recent') }}</button>
                            </div>
                            <ul>
                                <template x-for="r in recent" :key="r">
                                    <li class="flex items-center">
                                        <button type="button" @click="useRecent(r)" class="{{ $hit }} flex-1 hover:bg-sand-100 dark:hover:bg-ink-800"><span class="{{ $tile }}"><x-icon name="history" class="size-4" /></span><span class="min-w-0 flex-1 truncate" dir="auto" x-text="r"></span></button>
                                        <button type="button" @click="forget(r)" class="btn-ghost size-11 shrink-0 p-0" aria-label="{{ __('search.forget') }}"><x-icon name="x" class="size-4" /></button>
                                    </li>
                                </template>
                            </ul>
                        </div>
                    </template>
                    <div class="px-3 py-6 text-center">
                        <p class="text-sm font-medium text-ink-700 dark:text-sand-200">{{ __('search.hint') }}</p>
                        <p class="mt-1 text-xs text-ink-500 dark:text-ink-400">{{ __('search.hint_examples') }}</p>
                    </div>
                </div>

                {{-- One letter --}}
                <p x-show="phase === 'short'" x-cloak class="px-3 py-8 text-center text-sm text-ink-500 dark:text-ink-400">{{ __('search.short') }}</p>

                {{-- Waiting for the first answer: rows in the final layout, never a blank box --}}
                <div x-show="phase === 'loading' && slow" x-cloak aria-hidden="true">
                    @foreach (range(1, 4) as $n)
                        <div class="flex min-h-11 items-center gap-3 px-3 py-2"><span class="skeleton size-9 shrink-0"></span><span class="flex-1 space-y-1.5"><span class="skeleton block h-3.5 w-1/3"></span><span class="skeleton block h-3 w-2/3"></span></span></div>
                    @endforeach
                </div>

                {{-- The groups --}}
                <template x-for="(group, g) in view" :key="group.key">
                    <div role="group" :aria-labelledby="'cp-g-' + g" class="mb-1">
                        <div :id="'cp-g-' + g" class="eyebrow px-3 pb-1 pt-3" x-text="group.label"></div>
                        <template x-for="row in group.rows" :key="row.key">
                            <a role="option" tabindex="-1" :id="'cp-o-' + row.i" :href="row.item.url" :aria-selected="active === row.i ? 'true' : 'false'" @click="pick($event, row)" @mousemove="active = row.i"
                               class="{{ $hit }}" :class="active === row.i ? 'bg-sand-100 ring-1 ring-inset ring-brass-400/60 dark:bg-ink-800' : 'hover:bg-sand-50 dark:hover:bg-ink-800/60'">
                                <template x-if="row.kind === 'item'">
                                    <span class="flex min-w-0 flex-1 items-center gap-3">
                                        <span class="{{ $tile }}" x-html="row.item.svg"></span>
                                        <span class="min-w-0 flex-1">
                                            <span class="block truncate font-medium text-ink-900 dark:text-ivory" :class="row.item.ltr ? 'font-mono' : ''" :dir="row.item.ltr ? 'ltr' : null" x-text="row.item.title"></span>
                                            <span x-show="row.item.subtitle" class="block truncate text-xs text-ink-500 dark:text-ink-400" x-text="row.item.subtitle"></span>
                                        </span>
                                        <span x-show="row.item.badge" class="badge-neutral" x-text="row.item.badge"></span>
                                        <span x-show="row.item.meta" class="shrink-0 text-xs tabular-nums text-ink-500 dark:text-ink-400" dir="ltr" x-text="row.item.meta"></span>
                                    </span>
                                </template>
                                <template x-if="row.kind === 'more'">
                                    <span class="flex min-w-0 flex-1 items-center gap-3 font-medium text-brass-700 dark:text-brass-300"><span class="min-w-0 flex-1 truncate" x-text="row.item.title"></span><x-icon name="arrow-right" class="rtl-flip size-4 shrink-0" /></span>
                                </template>
                                <x-icon name="arrow-right" class="rtl-flip size-4 shrink-0 text-ink-400" x-show="active === row.i" x-cloak />
                            </a>
                        </template>
                    </div>
                </template>
                <p x-show="phase === 'results' && state === 'pages' && rows.length" x-cloak class="px-3 pb-3 pt-2 text-xs text-ink-500 dark:text-ink-400">{{ __('search.pages_only') }}</p>

                {{-- No answer found --}}
                <div x-show="phase === 'results' && rows.length === 0" x-cloak class="px-3 py-8 text-center">
                    <p class="text-sm font-medium text-ink-700 dark:text-sand-200" dir="auto" x-text="@js(__('search.none')).replace(':q', q.trim())"></p>
                    <p class="mt-1 text-xs text-ink-500 dark:text-ink-400"><span x-show="state === 'pages'" x-cloak>{{ __('search.pages_only') }}</span><span x-show="state !== 'pages'">{{ __('search.none_hint') }}</span></p>
                </div>

                {{-- Too many questions, or it did not work: a plain sentence and a way to try again --}}
                <div x-show="phase === 'throttled' || phase === 'error'" x-cloak class="px-3 py-8 text-center" role="alert">
                    <p class="text-sm font-medium text-ink-700 dark:text-sand-200" x-text="phase === 'throttled' ? @js(__('search.throttled')) : @js(__('search.error'))"></p>
                    <p class="mt-1 text-xs text-ink-500 dark:text-ink-400" x-text="phase === 'throttled' ? @js(__('search.throttled_hint')) : @js(__('search.error_hint'))"></p>
                    <button type="button" @click="askNow()" class="btn-secondary mt-4 min-h-11">{{ __('search.retry') }}</button>
                </div>
            </div>

            {{-- Keys (a keyboard is not assumed: the legend is hidden on touch screens) --}}
            <div class="hidden shrink-0 items-center gap-4 border-t border-sand-200/70 px-4 py-2 text-xs text-ink-500 sm:flex dark:border-ink-800 dark:text-ink-400" aria-hidden="true">
                <span class="inline-flex items-center gap-1.5"><kbd class="rounded border border-sand-300 px-1 font-sans dark:border-ink-600">↑</kbd><kbd class="rounded border border-sand-300 px-1 font-sans dark:border-ink-600">↓</kbd>{{ __('search.keys.move') }}</span>
                <span class="inline-flex items-center gap-1.5"><kbd class="rounded border border-sand-300 px-1 font-sans dark:border-ink-600">Enter</kbd>{{ __('search.keys.open') }}</span>
                <span class="inline-flex items-center gap-1.5"><kbd class="rounded border border-sand-300 px-1 font-sans dark:border-ink-600">Esc</kbd>{{ __('search.keys.close') }}</span>
            </div>
            <p class="sr-only" role="status" aria-live="polite" x-text="announce"></p>
        </div>
    </div>
</div>
