@php use App\Livewire\Admin\Sales\CustomerIndex; use App\Support\BusinessTime; use App\Support\Phone; use Carbon\CarbonImmutable; @endphp
<div @if ($canSend) x-data="customerSelection" @endif>
    <x-admin.page-header :title="__('nav.customers')" :eyebrow="__('nav.sales')" :description="__('sales_admin.customers_hint')" />

    @if ($canSend)
        <span class="hidden" x-ref="selBoot" data-boot="{{ json_encode(['text' => __('messaging_customers.js'), 'cap' => $bulkMax], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) }}"></span>
    @endif

    <div class="card @container">
        <x-admin.toolbar>
            <x-admin.search :placeholder="__('sales_admin.customers_search')" />
            <div class="grid grid-cols-2 gap-3 sm:contents">
                <select wire:model.live="filterStatus" class="input sm:w-44" aria-label="{{ __('admin.status') }}">
                    <option value="">{{ __('admin.all_statuses') }}</option>
                    @foreach (['active', 'suspended', 'deactivated'] as $st)<option value="{{ $st }}">{{ __('status.'.$st) }}</option>@endforeach
                </select>
                <select wire:model.live="filterConsent" class="input sm:w-52" aria-label="{{ __('sales_admin.marketing_consent') }}">
                    <option value="">{{ __('sales_admin.any_consent') }}</option>
                    <option value="yes">{{ __('sales_admin.consent_yes') }}</option>
                    <option value="no">{{ __('sales_admin.consent_no') }}</option>
                </select>
                <select wire:model.live="filterCart" class="input sm:w-56" aria-label="{{ __('messaging_customers.filter_cart') }}" data-testid="filter-cart">
                    <option value="">{{ __('messaging_customers.cart_any') }}</option>
                    <option value="yes">{{ __('messaging_customers.cart_yes') }}</option>
                    <option value="no">{{ __('messaging_customers.cart_no') }}</option>
                </select>
                <select wire:model.live="filterContact" class="input sm:w-56" aria-label="{{ __('messaging_customers.filter_contact') }}" data-testid="filter-contact">
                    <option value="">{{ __('messaging_customers.contact_any') }}</option>
                    @foreach (array_keys(\App\Domain\Messaging\Services\CustomerFilter::CONTACT) as $k)<option value="{{ $k }}">{{ __('messaging_customers.contact_'.$k) }}</option>@endforeach
                </select>
            </div>
            <x-slot:meta>{{ trans_choice('sales_admin.customers_count', $customers->total(), ['count' => \App\Support\BoundedPage::label($customers)]) }}</x-slot:meta>
        </x-admin.toolbar>
        @unless ($reveal)
            <p class="flex items-center gap-2 border-b border-sand-100 bg-sand-50/60 px-4 py-2 text-xs text-ink-500 sm:px-6 dark:border-ink-800 dark:bg-ink-800/30"><x-icon name="lock" class="size-3.5" />{{ __('sales_admin.pii_masked') }}</p>
        @endunless

        @if ($canSend)
            {{-- Gmail-style: the page is ticked, so offer everyone matching the filters; once taken, say so and let it go again. --}}
            <div x-show="offerAll || all" x-cloak class="flex flex-wrap items-center justify-center gap-x-3 gap-y-1 border-b border-brass-200/60 bg-brass-50 px-4 py-2.5 text-center text-sm text-ink-800 dark:border-brass-500/20 dark:bg-brass-500/10 dark:text-sand-100" role="status" data-testid="select-banner">
                <span x-text="bannerText"></span>
                <button type="button" class="min-h-11 px-1 font-semibold text-brass-700 underline underline-offset-2 hover:text-brass-600 dark:text-brass-300" @click="all ? clear() : selectAll()" x-text="all ? t.clear : t.select_all.replace(':total', total.toLocaleString('en'))" data-testid="select-all-matching"></button>
            </div>
        @endif

        @if ($customers->isEmpty())
            <x-admin.empty :title="__('sales_admin.customers_empty')" :description="__('sales_admin.customers_empty_hint')" icon="users" />
        @else
            {{-- Wide cards: the table. --}}
            <div class="table-wrap hidden @[900px]:block">
                <table class="table [&_td]:px-3 [&_th]:px-3">
                    <thead><tr>
                        @if ($canSend)
                            <th class="w-12 !pe-0">
                                <label class="grid size-11 cursor-pointer place-items-center"><span class="sr-only">{{ __('messaging_customers.select_page') }}</span>
                                    <input type="checkbox" class="checkbox size-5" :checked="pageOn" x-effect="$el.indeterminate = pageSome" @change="togglePage($event.target.checked)" data-testid="select-page"></label>
                            </th>
                        @endif
                        <th>{{ __('admin.customer') }}</th><th>{{ __('sales_admin.contact') }}</th><th class="!text-end">{{ __('admin.orders') }}</th>
                        <th class="!text-end">{{ __('sales_admin.lifetime_spend') }}</th><th class="!text-end hidden 2xl:table-cell">{{ __('sales_admin.points') }}</th><th>{{ __('admin.status') }}</th>
                        <th>{{ __('messaging_customers.col_last_message') }}</th><th class="w-24"></th>
                    </tr></thead>
                    <tbody>
                    @foreach ($customers as $c)
                        <tr wire:key="c-{{ $c->id }}" wire:click="openView('{{ $c->id }}')" tabindex="-1" class="cursor-pointer focus:outline-none">
                            @if ($canSend)
                                <td class="!pe-0" @click.stop>
                                    <label class="grid size-11 cursor-pointer place-items-center"><span class="sr-only">{{ __('messaging_customers.select_row', ['name' => $c->name]) }}</span>
                                        <input type="checkbox" class="checkbox size-5" :checked="on('{{ $c->id }}')" @change="tick('{{ $c->id }}', $event.target.checked)" data-testid="select-row"></label>
                                </td>
                            @endif
                            <td>
                                <div class="flex items-center gap-3">
                                    <span class="grid size-9 shrink-0 place-items-center rounded-full bg-sand-100 text-sm font-semibold text-ink-700 dark:bg-ink-800 dark:text-sand-100">{{ mb_substr($c->name, 0, 1) }}</span>
                                    <div class="min-w-0">
                                        <a href="{{ route('admin.customers.index', ['view' => $c->id]) }}" wire:click.prevent.stop="openView('{{ $c->id }}')" class="block truncate font-medium text-ink-900 hover:text-brass-600 dark:text-ivory">{{ $c->name }}</a>
                                        <span class="text-xs text-ink-500">{{ __('sales_admin.joined', ['date' => BusinessTime::display($c->created_at, 'M Y')]) }}</span>
                                    </div>
                                </div>
                            </td>
                            <td class="text-xs">
                                <span class="block" dir="ltr">{{ $reveal ? $c->phone : Phone::mask($c->phone) }}</span>
                                <span class="block max-w-[10rem] truncate text-ink-500" dir="ltr" title="{{ $reveal ? $c->email : '' }}">{{ $reveal ? $c->email : CustomerIndex::maskEmail($c->email) }}</span>
                            </td>
                            <td class="text-end tabular-nums">{{ $c->orders_count }}</td>
                            <td class="text-end"><x-admin.money :value="$c->spend ?? '0'" /></td>
                            <td class="hidden text-end tabular-nums 2xl:table-cell">{{ number_format($c->loyaltyAccount?->balance ?? 0) }}@if ($c->loyaltyAccount && $c->loyaltyAccount->tier !== 'classic')<span class="badge-brass ms-1.5">{{ __('sales_admin.tier_'.$c->loyaltyAccount->tier) }}</span>@endif</td>
                            <td><x-admin.status :value="$c->status->value" /></td>
                            <td class="whitespace-nowrap text-xs" data-testid="last-message">
                                @if ($c->last_contact_at)
                                    @php $last = CarbonImmutable::parse($c->last_contact_at); @endphp
                                    <time datetime="{{ $last->utc()->toIso8601ZuluString() }}" title="{{ BusinessTime::display($last) }}" class="text-ink-700 dark:text-sand-200">{{ $last->locale(app()->getLocale())->diffForHumans() }}</time>
                                @else
                                    <span class="text-ink-400">{{ __('messaging_customers.never') }}</span>
                                @endif
                            </td>
                            <td class="!ps-0">
                                <div class="flex items-center justify-end">
                                    @if ($canSend)
                                        <button type="button" wire:click.stop="composeFor('{{ $c->id }}')" class="btn-ghost size-11 p-0" title="{{ __('messaging_customers.send') }}" aria-label="{{ __('messaging_customers.send_to', ['name' => $c->name]) }}" data-testid="row-send"><x-icon name="send" class="size-4 rtl-flip" /></button>
                                    @endif
                                    <span class="btn-ghost btn-sm pointer-events-none" aria-hidden="true"><x-icon name="chevron-right" class="size-4 rtl-flip" /></span>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>

            {{-- Narrow cards (phones, tablets, small laptops with the menu open): one card per customer. --}}
            <div class="@[900px]:hidden">
                @if ($canSend)
                    <label class="flex min-h-12 cursor-pointer items-center gap-3 border-b border-sand-100 bg-sand-50/60 px-4 text-sm font-medium text-ink-700 dark:border-ink-800 dark:bg-ink-800/30 dark:text-sand-200">
                        <input type="checkbox" class="checkbox size-5" :checked="pageOn" x-effect="$el.indeterminate = pageSome" @change="togglePage($event.target.checked)">{{ __('messaging_customers.select_page') }}
                    </label>
                @endif
                <ul class="divide-y divide-sand-100 dark:divide-ink-800">
                    @foreach ($customers as $c)
                        <li wire:key="cc-{{ $c->id }}" class="flex items-start gap-2 py-3 ps-2 pe-2">
                            @if ($canSend)
                                <label class="grid size-11 shrink-0 cursor-pointer place-items-center"><span class="sr-only">{{ __('messaging_customers.select_row', ['name' => $c->name]) }}</span>
                                    <input type="checkbox" class="checkbox size-5" :checked="on('{{ $c->id }}')" @change="tick('{{ $c->id }}', $event.target.checked)"></label>
                            @endif
                            <button type="button" wire:click="openView('{{ $c->id }}')" class="min-w-0 flex-1 py-1 text-start {{ $canSend ? '' : 'ps-2' }}">
                                <span class="flex flex-wrap items-center gap-x-2 gap-y-1"><span class="truncate font-medium text-ink-900 dark:text-ivory">{{ $c->name }}</span><x-admin.status :value="$c->status->value" /></span>
                                <span class="mt-1 block text-xs text-ink-500" dir="ltr">{{ $reveal ? $c->phone : Phone::mask($c->phone) }}</span>
                                <span class="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-0.5 text-xs text-ink-600 dark:text-ink-300">
                                    <span>{{ __('admin.orders') }}: <span class="tabular-nums">{{ $c->orders_count }}</span></span>
                                    <x-admin.money :value="$c->spend ?? '0'" />
                                    <span>{{ __('messaging_customers.col_last_message') }}:
                                        @if ($c->last_contact_at)
                                            @php $last = CarbonImmutable::parse($c->last_contact_at); @endphp
                                            <time datetime="{{ $last->utc()->toIso8601ZuluString() }}" title="{{ BusinessTime::display($last) }}">{{ $last->locale(app()->getLocale())->diffForHumans() }}</time>
                                        @else{{ __('messaging_customers.never') }}@endif
                                    </span>
                                </span>
                            </button>
                            @if ($canSend)
                                <button type="button" wire:click="composeFor('{{ $c->id }}')" class="btn-ghost size-11 shrink-0 p-0" aria-label="{{ __('messaging_customers.send_to', ['name' => $c->name]) }}" data-testid="row-send"><x-icon name="send" class="size-4 rtl-flip" /></button>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
            @if ($customers->hasPages())<div class="border-t border-sand-100 p-4 dark:border-ink-800">{{ $customers->links('components.admin.pagination') }}</div>@endif
        @endif
    </div>

    @if ($canSend)
        {{-- Sticky bulk bar: appears as soon as anyone is ticked. --}}
        <div x-show="count > 0" x-cloak x-transition.opacity.duration.150ms class="pointer-events-none sticky bottom-4 z-20 mt-5 flex justify-center" data-testid="bulk-bar">
            <div class="pointer-events-auto flex w-full max-w-xl flex-wrap items-center gap-x-3 gap-y-2 rounded-2xl border border-sand-200 bg-white p-2 ps-4 shadow-[var(--shadow-lift)] dark:border-ink-700 dark:bg-ink-900" role="region" aria-label="{{ __('messaging_customers.bulk_label') }}">
                <p class="min-w-0 flex-1 text-sm" aria-live="polite">
                    <span class="font-semibold tabular-nums text-ink-900 dark:text-ivory" x-text="count.toLocaleString('en')"></span> <span class="text-ink-600 dark:text-ink-300">{{ __('messaging_customers.selected') }}</span>
                    <span x-show="count > cap" x-cloak class="block text-xs font-medium text-ruby-soft" x-text="t.too_many.replace(':max', cap.toLocaleString('en'))"></span>
                </p>
                <button type="button" class="btn-primary min-h-11" wire:click="composeSelected" wire:loading.attr="disabled" wire:target="composeSelected" :disabled="count > cap" data-testid="bulk-send"><x-icon name="send" class="size-4 rtl-flip" />{{ __('messaging_customers.send') }}</button>
                <button type="button" class="btn-ghost min-h-11" @click="clear()" data-testid="bulk-clear">{{ __('messaging_customers.clear') }}</button>
            </div>
        </div>
    @endif

    @php $viewed = $this->viewed; @endphp
    <x-admin.dialog wire:model="showView" :title="$viewed?->name" max-width="max-w-3xl">
        @if ($viewed)
            <x-admin.drawer-bar :url="route('admin.customers.show', $viewed)" />
            @livewire(\App\Livewire\Admin\Sales\CustomerShow::class, ['customer' => $viewed, 'drawer' => true], key('customer-view-'.$viewed->id.'-'.$viewSeq))
        @endif
    </x-admin.dialog>

    {{-- Declared after the customer window so an order opened from it lands on top. --}}
    @include('livewire.admin.sales.partials.order-peek')

    {{-- The message window: last, so it opens above the customer window (the "Send message" button inside it uses this one too). --}}
    @if ($canSend)
        <livewire:admin.messaging.message-composer />
    @endif
</div>

@if ($canSend)
@script
<script>
    // Ticking is instant: the choices live in the browser and travel with the next request (nothing is sent per tick). The server turns them
    // into the list handed to the message window and checks it again for the signed-in staff member.
    Alpine.data('customerSelection', () => ({
        t: {}, cap: 1000,
        init() {
            const b = JSON.parse(this.$refs.selBoot.dataset.boot);
            this.t = b.text;
            this.cap = b.cap;
        },
        get all() { return this.$wire.allMatching; },
        get sel() { return this.$wire.selected; },
        get exc() { return this.$wire.except; },
        get total() { return this.$wire.matching; },
        get page() { return this.$wire.pageIds; },
        get count() { return this.all ? Math.max(0, this.total - this.exc.length) : this.sel.length; },
        on(id) { return this.all ? !this.exc.includes(id) : this.sel.includes(id); },
        tick(id, on) { this.write(on ? { add: [id] } : { remove: [id] }); },
        togglePage(on) { this.write(on ? { add: [...this.page] } : { remove: [...this.page] }); },
        /** In "all matching" mode a tick takes an id off the left-out list; otherwise it adds to the ticked list. */
        write({ add = [], remove = [] }) {
            const key = this.all ? 'except' : 'selected';
            const current = this.all ? this.exc : this.sel;
            const grow = this.all ? remove : add; // ids that join the list
            const shrink = this.all ? add : remove; // ids that leave it
            const next = [...new Set([...current.filter((x) => !shrink.includes(x)), ...grow])];
            this.$wire.$set(key, next, false);
        },
        get pageOn() { return this.page.length > 0 && this.page.every((id) => this.on(id)); },
        get pageSome() { return !this.pageOn && this.page.some((id) => this.on(id)); },
        get offerAll() { return this.pageOn && !this.all && this.total > this.page.length; },
        get bannerText() {
            const n = (v) => v.toLocaleString('en');
            if (this.all) return this.exc.length ? this.t.some_selected.replace(':count', n(this.count)).replace(':total', n(this.total)) : this.t.all_selected.replace(':total', n(this.total));
            return this.t.page_selected.replace(':count', n(this.page.length));
        },
        selectAll() {
            this.$wire.$set('allMatching', true, false);
            this.$wire.$set('selected', [], false);
            this.$wire.$set('except', [], false);
        },
        clear() {
            this.$wire.$set('allMatching', false, false);
            this.$wire.$set('selected', [], false);
            this.$wire.$set('except', [], false);
        },
    }));
</script>
@endscript
@endif
