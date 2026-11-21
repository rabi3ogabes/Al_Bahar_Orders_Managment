@php
    use App\Livewire\Admin\Sales\CustomerIndex;
    use App\Support\BusinessTime;
    use App\Support\Money;
    use App\Support\Phone;

    $money = fn ($v) => Money::of((string) $v)->format();
    $tiles = [
        'quiet' => ['moon', __('customer_value.tile_quiet'), number_format($summary['quiet']), __('customer_value.tile_quiet_note', ['days' => $quietDays])],
        'top' => ['crown', __('customer_value.tile_top'), number_format($summary['customers']), __('customer_value.tile_top_note', ['amount' => $money($summary['spend'])])],
        'repeat' => ['refresh', __('customer_value.tile_repeat'), number_format($summary['repeat']), __('customer_value.tile_repeat_note')],
        'one_time' => ['user', __('customer_value.tile_one_time'), number_format($summary['one_time']), __('customer_value.tile_one_time_note')],
        'new' => ['sparkles', __('customer_value.tile_new'), number_format($summary['new_month']), __('customer_value.tile_new_note')],
    ];
    $headline = match ($tab) {
        'quiet' => [__('customer_value.q_quiet'), trans_choice('customer_value.hero_quiet', $summary['quiet'], ['count' => number_format($summary['quiet']), 'amount' => $money($summary['quiet_spend']), 'days' => $quietDays])],
        'top' => [__('customer_value.q_top'), __('customer_value.hero_top', ['count' => number_format($summary['customers']), 'amount' => $money($summary['spend'])])],
        'repeat' => [__('customer_value.q_repeat'), __('customer_value.hero_repeat', ['count' => number_format($summary['repeat']), 'total' => number_format($summary['customers'])])],
        'one_time' => [__('customer_value.q_one_time'), __('customer_value.hero_one_time', ['count' => number_format($summary['one_time'])])],
        default => [__('customer_value.q_new'), __('customer_value.hero_new', ['count' => number_format($summary['new_month'])])],
    };
    $ago = fn ($at) => $at?->locale(app()->getLocale())->diffForHumans();
@endphp
<div>
    <x-admin.page-header :title="__('customer_value.title')" :eyebrow="__('nav.sales')" :description="__('customer_value.hint')">
        <x-slot:actions>
            @if ($canExport)
                <button type="button" wire:click="export" wire:loading.attr="disabled" wire:target="export" class="btn-secondary min-h-11" data-testid="export"><x-icon name="download" class="size-4" />{{ __('customer_value.export') }}</button>
            @endif
            @if ($canSettings)
                <button type="button" wire:click="$set('showSettings', true)" class="btn-ghost min-h-11" data-testid="open-settings"><x-icon name="sliders" class="size-4" />{{ __('customer_value.settings') }}</button>
            @endif
        </x-slot:actions>
    </x-admin.page-header>

    {{-- One tile per way of looking; the tile of the list on screen is pressed. The first one answers the question of the page. --}}
    <div class="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-5 [&>*]:min-w-0" role="group" aria-label="{{ __('customer_value.tiles') }}">
        @foreach ($tiles as $key => [$icon, $label, $number, $note])
            <button type="button" wire:click="$set('tab', '{{ $key }}')" aria-pressed="{{ $tab === $key ? 'true' : 'false' }}" data-tab="{{ $key }}"
                    class="card card-pad min-h-11 text-start transition {{ $tab === $key ? 'ring-2 ring-brass-500' : 'hover:border-brass-400' }}">
                <span class="flex items-center gap-2 text-sm text-ink-600 dark:text-ink-300"><x-icon :name="$icon" class="size-4 shrink-0" />{{ $label }}</span>
                <span class="mt-1 block font-display text-2xl font-semibold tabular-nums">{{ $number }}</span>
                <span class="mt-0.5 block text-xs leading-snug text-ink-500">{{ $note }}</span>
            </button>
        @endforeach
    </div>

    <section class="card card-pad mb-6" aria-labelledby="cv-q" data-testid="hero">
        <h2 id="cv-q" class="font-display text-2xl font-semibold">{{ $headline[0] }}</h2>
        <p class="mt-1 max-w-3xl text-sm text-ink-600 dark:text-ink-300">{{ $headline[1] }}</p>
        @if ($tab === 'quiet' && $rows->total() > 0)
            <div class="mt-4 flex flex-wrap items-center gap-2">
                <button type="button" wire:click="startWithFirst" class="btn-primary min-h-11" data-testid="start-with-first"><x-icon name="send" class="size-4 rtl-flip" />{{ __('customer_value.start_with') }}</button>
                <span class="text-xs text-ink-500">{{ $canMessage ? __('customer_value.start_hint') : __('customer_value.start_hint_view') }}</span>
            </div>
        @endif
    </section>

    <div class="card @container">
        <x-admin.toolbar>
            <x-admin.search :placeholder="__('sales_admin.customers_search')" />
            <div class="grid grid-cols-2 gap-3 sm:contents">
                <select wire:model.live="sort" class="input sm:w-56" aria-label="{{ __('customer_value.sort') }}" data-testid="sort">
                    @foreach ($sorts as $s)<option value="{{ $s }}">{{ __('customer_value.sort_'.$s) }}</option>@endforeach
                </select>
                @if ($branches->count() > 1)
                    <select wire:model.live="branch" class="input sm:w-48" aria-label="{{ __('admin.branch') }}"><option value="">{{ __('admin.all_my_branches') }}</option>@foreach ($branches as $b)<option value="{{ $b->id }}">{{ $b->code }} · {{ $b->localized('name') }}</option>@endforeach</select>
                @endif
            </div>
            <x-slot:meta>{{ trans_choice('sales_admin.customers_count', $rows->total(), ['count' => $rows->total()]) }}</x-slot:meta>
        </x-admin.toolbar>
        <p class="flex flex-wrap items-center gap-x-4 gap-y-1 border-b border-sand-100 bg-sand-50/60 px-4 py-2 text-xs text-ink-500 sm:px-6 dark:border-ink-800 dark:bg-ink-800/30">
            <span class="inline-flex items-center gap-1.5"><x-icon name="clock" class="size-3.5" />{{ $refreshedAt ? __('customer_value.updated', ['when' => $ago($refreshedAt)]) : __('customer_value.never_updated') }}
                <button type="button" wire:click="refreshNow" wire:loading.attr="disabled" wire:target="refreshNow" class="min-h-11 px-1 font-semibold text-brass-700 underline underline-offset-2 dark:text-brass-300" data-testid="refresh">{{ __('customer_value.refresh') }}</button></span>
            @unless ($reveal)<span class="inline-flex items-center gap-1.5"><x-icon name="lock" class="size-3.5" />{{ __('sales_admin.pii_masked') }}</span>@endunless
            @if (! app(\App\Domain\Identity\Services\AccessControl::class)->scope(auth()->user(), 'customers.view')->all)<span class="inline-flex items-center gap-1.5" data-testid="branch-only-note"><x-icon name="store" class="size-3.5" />{{ __('customer_value.branch_only') }}</span>@endif
        </p>

        <div wire:loading.class="opacity-60" wire:target="tab, search, sort, branch, gotoPage, previousPage, nextPage">
            @if ($rows->isEmpty())
                <x-admin.empty :title="__('customer_value.empty_'.$tab)" :description="__('customer_value.empty_'.$tab.'_hint')" :icon="$tab === 'quiet' ? 'check' : 'users'" data-empty="{{ $tab }}" />
            @else
                <div class="table-wrap hidden @[900px]:block">
                    <table class="table [&_td]:px-3 [&_th]:px-3">
                        <thead><tr>
                            <th>{{ __('customer_value.col_customer') }}</th><th class="!text-end">{{ __('customer_value.col_orders') }}</th><th class="!text-end">{{ __('customer_value.col_spend') }}</th>
                            <th class="!text-end">{{ __('customer_value.col_basket') }}</th><th>{{ __('customer_value.col_last') }}</th><th>{{ __('customer_value.col_gap') }}</th>
                            @if ($tab === 'quiet')<th>{{ __('customer_value.col_late') }}</th>@endif<th class="w-12"></th>
                        </tr></thead>
                        <tbody>
                        @foreach ($rows as $r)
                            <tr wire:key="v-{{ $r->id }}" wire:click="openView('{{ $r->id }}')" tabindex="-1" class="cursor-pointer focus:outline-none" data-customer="{{ $r->id }}">
                                <td>
                                    <div class="flex items-center gap-3">
                                        <span class="grid size-9 shrink-0 place-items-center rounded-full bg-sand-100 text-sm font-semibold text-ink-700 dark:bg-ink-800 dark:text-sand-100">{{ mb_substr($r->name, 0, 1) }}</span>
                                        <div class="min-w-0">
                                            <a href="{{ route('admin.customer-value.index', ['view' => $r->id]) }}" wire:click.prevent.stop="openView('{{ $r->id }}')" class="block truncate font-medium text-ink-900 hover:text-brass-600 dark:text-ivory">{{ $r->name }}</a>
                                            <span class="block text-xs text-ink-500" dir="ltr">{{ $reveal ? $r->phone : Phone::mask($r->phone) }}</span>
                                        </div>
                                    </div>
                                </td>
                                <td class="text-end tabular-nums">{{ $r->orders }}</td>
                                <td class="text-end"><x-admin.money :value="$r->spend" class="font-medium" /></td>
                                <td class="text-end"><x-admin.money :value="$r->basket" /></td>
                                <td class="whitespace-nowrap text-sm"><span dir="ltr">{{ BusinessTime::display($r->last_order_at, 'd M Y') }}</span><span class="block text-xs text-ink-500">{{ trans_choice('customer_value.days_ago', $r->days_quiet, ['count' => $r->days_quiet]) }}</span></td>
                                <td class="whitespace-nowrap text-sm">@if ($r->usual_gap_days){{ trans_choice('customer_value.every_days', $r->usual_gap_days, ['count' => $r->usual_gap_days]) }}@else<span class="text-ink-400">{{ __('customer_value.gap_unknown') }}</span>@endif</td>
                                @if ($tab === 'quiet')
                                    <td class="whitespace-nowrap text-sm">
                                        @if ($r->late_by !== null)<span class="{{ $r->late_by >= 2 ? 'badge-danger' : 'badge-warning' }}"><x-icon name="clock" class="size-3.5" />{{ __('customer_value.late_by', ['n' => rtrim(rtrim(number_format($r->late_by, 1), '0'), '.')]) }}</span>
                                        @else<span class="text-ink-400">{{ __('customer_value.one_order_only') }}</span>@endif
                                    </td>
                                @endif
                                <td class="!ps-0"><span class="btn-ghost btn-sm pointer-events-none" aria-hidden="true"><x-icon name="chevron-right" class="size-4 rtl-flip" /></span></td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>

                <ul class="divide-y divide-sand-100 @[900px]:hidden dark:divide-ink-800">
                    @foreach ($rows as $r)
                        <li wire:key="vc-{{ $r->id }}">
                            <button type="button" wire:click="openView('{{ $r->id }}')" class="block min-h-11 w-full px-4 py-3 text-start">
                                <span class="flex items-start justify-between gap-3"><span class="min-w-0 truncate font-medium text-ink-900 dark:text-ivory">{{ $r->name }}</span><x-admin.money :value="$r->spend" class="shrink-0 font-medium" /></span>
                                <span class="mt-1 block text-xs text-ink-500" dir="ltr">{{ $reveal ? $r->phone : Phone::mask($r->phone) }}</span>
                                <span class="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-ink-600 dark:text-ink-300">
                                    <span>{{ trans_choice('customer_value.orders_n', $r->orders, ['count' => $r->orders]) }}</span>
                                    <span>{{ trans_choice('customer_value.days_ago', $r->days_quiet, ['count' => $r->days_quiet]) }}</span>
                                    @if ($r->usual_gap_days)<span>{{ trans_choice('customer_value.every_days', $r->usual_gap_days, ['count' => $r->usual_gap_days]) }}</span>@endif
                                    @if ($tab === 'quiet' && $r->late_by !== null)<span class="{{ $r->late_by >= 2 ? 'badge-danger' : 'badge-warning' }}">{{ __('customer_value.late_by', ['n' => rtrim(rtrim(number_format($r->late_by, 1), '0'), '.')]) }}</span>@endif
                                </span>
                            </button>
                        </li>
                    @endforeach
                </ul>
                @if ($rows->hasPages())<div class="border-t border-sand-100 p-4 dark:border-ink-800">{{ $rows->links('components.admin.pagination') }}</div>@endif
            @endif
        </div>
    </div>

    <p class="mt-4 max-w-4xl text-xs leading-relaxed text-ink-500" data-testid="definitions">{{ __('customer_value.definitions', ['days' => $quietDays]) }}</p>

    @php $viewed = $this->viewed; @endphp
    <x-admin.dialog wire:model="showView" :title="$viewed?->name" max-width="max-w-3xl">
        @if ($viewed)
            <x-admin.drawer-bar :url="route('admin.customers.show', $viewed)" />
            @livewire(\App\Livewire\Admin\Sales\CustomerShow::class, ['customer' => $viewed, 'drawer' => true], key('customer-view-'.$viewed->id.'-'.$viewSeq))
        @endif
    </x-admin.dialog>

    @include('livewire.admin.sales.partials.order-peek')

    @if ($canMessage)
        <livewire:admin.messaging.message-composer />
    @endif

    @if ($canSettings)
        <x-admin.dialog wire:model="showSettings" max-width="max-w-lg" :title="__('customer_value.settings')" :description="__('customer_value.settings_hint')">
            <form wire:submit="saveSettings" class="space-y-5">
                <x-admin.field :label="__('customer_value.quiet_days_label')" for="quietDays" :hint="__('customer_value.quiet_days_hint', ['min' => \App\Domain\Reports\Services\CustomerValue::QUIET_MIN, 'max' => \App\Domain\Reports\Services\CustomerValue::QUIET_MAX])" required>
                    <input id="quietDays" type="number" min="{{ \App\Domain\Reports\Services\CustomerValue::QUIET_MIN }}" max="{{ \App\Domain\Reports\Services\CustomerValue::QUIET_MAX }}" wire:model="quietDays" class="input tabular-nums sm:max-w-[10rem]" dir="ltr" inputmode="numeric">
                </x-admin.field>
                <p class="text-xs text-ink-500">{{ __('customer_value.quiet_days_default', ['days' => \App\Domain\Reports\Services\CustomerValue::QUIET_DEFAULT]) }}</p>
                <div class="flex flex-col-reverse gap-2 border-t border-sand-100 pt-5 sm:flex-row sm:justify-end dark:border-ink-800">
                    <button type="button" class="btn-secondary min-h-11" @click="open = false">{{ __('admin.back') }}</button>
                    <button class="btn-primary min-h-11" wire:loading.attr="disabled" wire:target="saveSettings">{{ __('admin.save_changes') }}</button>
                </div>
            </form>
        </x-admin.dialog>
    @endif
</div>
