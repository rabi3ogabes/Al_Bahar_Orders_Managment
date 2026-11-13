@php
    use App\Livewire\Admin\Sales\CustomerIndex; use App\Support\Phone;
    $tabs = ['orders' => ['bag', __('admin.orders'), $stats->n], 'addresses' => ['map-pin', __('sales_admin.addresses'), $c->addresses->count()], 'loyalty' => ['gift', __('sales_admin.loyalty'), null], 'consent' => ['shield', __('sales_admin.consents'), null]];
@endphp
<div @class(['mx-auto max-w-7xl' => ! $drawer])>
    @if (! $drawer)
    <a href="{{ route('admin.customers.index') }}" wire:navigate class="mb-4 inline-flex items-center gap-1.5 text-sm text-ink-500 hover:text-brass-600"><x-icon name="chevron-left" class="size-4 rtl-flip" />{{ __('nav.customers') }}</a>
    @endif

    <div @class(['flex gap-5', 'mb-8 flex-col md:flex-row md:items-center' => ! $drawer, 'mb-5 items-center' => $drawer])>
        <span @class(['grid shrink-0 place-items-center bg-gradient-to-br from-brass-100 to-brass-300 font-display font-semibold text-ink-900', 'size-16 rounded-2xl text-2xl' => ! $drawer, 'size-14 rounded-2xl text-xl' => $drawer])>{{ mb_substr($c->name, 0, 1) }}</span>
        <div class="min-w-0 flex-1">
            @if (! $drawer)<h1 class="truncate font-display text-3xl font-semibold tracking-tight text-ink-900 dark:text-ivory">{{ $c->name }}</h1>@endif
            <div @class(['flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-ink-500', 'mt-2' => ! $drawer])>
                <x-admin.status :value="$c->status->value" />
                <span class="inline-flex items-center gap-1.5" dir="ltr"><x-icon name="phone" class="size-3.5" />{{ $reveal ? $c->phone : Phone::mask($c->phone) }}</span>
                @if ($c->email)<span class="inline-flex min-w-0 items-center gap-1.5"><x-icon name="mail" class="size-3.5 shrink-0" /><span class="truncate" dir="ltr">{{ $reveal ? $c->email : CustomerIndex::maskEmail($c->email) }}</span></span>@endif
                <span>{{ __('sales_admin.joined', ['date' => \App\Support\BusinessTime::display($c->created_at, 'd M Y')]) }}</span>
                @unless ($reveal)<span class="inline-flex items-center gap-1 text-xs"><x-icon name="lock" class="size-3" />{{ __('sales_admin.masked') }}</span>@endunless
            </div>
        </div>
        @if ($canEdit && ! $drawer)
            <div class="flex gap-2">
                @if ($c->status->value === 'active')
                    <button type="button" wire:click="askStatus('suspended')" class="btn-secondary text-ruby-soft"><x-icon name="ban" class="size-4" />{{ __('sales_admin.suspend') }}</button>
                @elseif ($c->status->value === 'suspended')
                    <button type="button" wire:click="askStatus('active')" class="btn-secondary"><x-icon name="check" class="size-4" />{{ __('sales_admin.reactivate') }}</button>
                @endif
            </div>
        @endif
    </div>
    @if ($canEdit && $drawer)
        <div class="-mt-2 mb-5 flex flex-wrap gap-2">
            @if ($c->status->value === 'active')
                <button type="button" wire:click="askStatus('suspended')" class="btn-secondary min-h-11 text-ruby-soft"><x-icon name="ban" class="size-4" />{{ __('sales_admin.suspend') }}</button>
            @elseif ($c->status->value === 'suspended')
                <button type="button" wire:click="askStatus('active')" class="btn-secondary min-h-11"><x-icon name="check" class="size-4" />{{ __('sales_admin.reactivate') }}</button>
            @endif
        </div>
    @endif

    @if ($drawer)
        {{-- Compact tiles: the page's big figures would clip in a two-column window on a phone. --}}
        <div class="mb-5 grid grid-cols-2 gap-3 [&>*]:min-w-0">
            @foreach ([
                [__('admin.orders'), number_format($stats->n), null],
                [__('sales_admin.lifetime_spend'), \App\Support\Money::of($stats->spend)->format(), null],
                [__('sales_admin.points_balance'), number_format($account->balance), __('sales_admin.worth', ['amount' => \App\Support\Money::ofMinor($account->balance * $pointValue->minor)->format()])],
                [__('sales_admin.tier'), __('sales_admin.tier_'.$account->tier), $stats->last_at ? __('sales_admin.last_order', ['at' => \App\Support\BusinessTime::display($stats->last_at, 'd M Y')]) : null],
            ] as [$label, $value, $hint])
                <div class="card p-4">
                    <p class="text-xs text-ink-500 dark:text-ink-400">{{ $label }}</p>
                    <p class="mt-1 break-words font-display text-xl font-semibold tabular-nums text-ink-900 dark:text-ivory">{{ $value }}</p>
                    @if ($hint)<p class="mt-1 text-[0.7rem] text-ink-500 dark:text-ink-400">{{ $hint }}</p>@endif
                </div>
            @endforeach
        </div>
    @else
    <div class="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4 [&>*]:min-w-0">
        <x-admin.stat :label="__('admin.orders')" :value="number_format($stats->n)" icon="bag" />
        <x-admin.stat :label="__('sales_admin.lifetime_spend')" :value="\App\Support\Money::of($stats->spend)->format()" icon="wallet" />
        <x-admin.stat :label="__('sales_admin.points_balance')" :value="number_format($account->balance)" icon="gift" tone="brass" :hint="__('sales_admin.worth', ['amount' => \App\Support\Money::ofMinor($account->balance * $pointValue->minor)->format()])" />
        <x-admin.stat :label="__('sales_admin.tier')" :value="__('sales_admin.tier_'.$account->tier)" icon="crown" :hint="$stats->last_at ? __('sales_admin.last_order', ['at' => \App\Support\BusinessTime::display($stats->last_at, 'd M Y')]) : null" />
    </div>
    @endif

    @if (! $drawer && \App\Domain\Marketing\Models\JourneyDelivery::where('user_id', $c->id)->exists())
        <x-admin.journey-touches :touches="app(\App\Domain\Marketing\Services\JourneyService::class)->touchesFor($c->id)" />
    @endif

    <x-admin.tabs :tabs="$tabs" :current="$current" :model="$tabModel" />

    @if ($current === 'orders')
        <div class="card">
            @if (! $orders || $orders->isEmpty())
                <x-admin.empty :title="__('sales_admin.no_orders_customer')" icon="bag" />
            @elseif ($drawer)
                {{-- Side window: the latest orders as tappable rows; one opens the order in a window on top of this one. --}}
                <ul class="divide-y divide-sand-100 dark:divide-ink-800">
                    @foreach ($orders as $o)
                        <li wire:key="co-{{ $o->id }}">
                            <button type="button" wire:click="$dispatch('peek-order', { id: '{{ $o->id }}' })" class="flex min-h-14 w-full items-center gap-3 px-4 py-3 text-start transition hover:bg-sand-50 dark:hover:bg-ink-800/40">
                                <span class="min-w-0 flex-1">
                                    <span class="block font-mono text-sm font-medium text-ink-900 dark:text-ivory" dir="ltr">{{ $o->number }}</span>
                                    <span class="block text-xs text-ink-500 tabular-nums">{{ \App\Support\BusinessTime::display($o->placed_at) }} · <span class="font-mono" dir="ltr">{{ $o->fulfillments->pluck('branch.code')->implode(' · ') }}</span></span>
                                    <span class="mt-1.5 flex flex-wrap gap-1.5"><x-admin.status :value="$o->status->value" class="!text-[0.65rem]" /><x-admin.status :value="$o->payment_status->value" class="!text-[0.65rem]" /></span>
                                </span>
                                <x-admin.money :value="$o->grand_total" class="shrink-0 text-sm font-medium" />
                                <x-icon name="chevron-right" class="size-4 shrink-0 text-ink-400 rtl-flip" />
                            </button>
                        </li>
                    @endforeach
                </ul>
            @else
                <div class="table-wrap"><table class="table">
                    <thead><tr><th>{{ __('admin.order') }}</th><th>{{ __('sales_admin.branches') }}</th><th>{{ __('admin.payment') }}</th><th class="!text-end">{{ __('admin.total') }}</th><th>{{ __('admin.status') }}</th></tr></thead>
                    <tbody>@foreach ($orders as $o)
                        <tr wire:key="co-{{ $o->id }}">
                            <td><a href="{{ route('admin.orders.show', $o) }}" wire:navigate class="font-mono font-medium hover:text-brass-600" dir="ltr">{{ $o->number }}</a><span class="block text-xs text-ink-500 tabular-nums">{{ \App\Support\BusinessTime::display($o->placed_at) }}</span></td>
                            <td><span class="font-mono text-xs" dir="ltr">{{ $o->fulfillments->pluck('branch.code')->implode(' · ') }}</span></td>
                            <td><x-admin.status :value="$o->payment_status->value" /></td>
                            <td class="text-end"><x-admin.money :value="$o->grand_total" /></td>
                            <td><x-admin.status :value="$o->status->value" /></td>
                        </tr>
                    @endforeach</tbody>
                </table></div>
                @if (! $drawer && $orders->hasPages())<div class="border-t border-sand-100 p-4 dark:border-ink-800">{{ $orders->links('components.admin.pagination') }}</div>@endif
            @endif
        </div>
    @elseif ($current === 'addresses')
        @if ($c->addresses->isEmpty())
            <div class="card"><x-admin.empty :title="__('sales_admin.no_addresses')" icon="map-pin" /></div>
        @else
            <div @class(['grid gap-4 [&>*]:min-w-0', 'md:grid-cols-2 xl:grid-cols-3' => ! $drawer])>
                @foreach ($c->addresses as $a)
                    <div class="card card-pad" wire:key="ad-{{ $a->id }}">
                        <div class="mb-2 flex items-center gap-2"><span class="font-medium">{{ $a->label ?: __('sales_admin.address') }}</span>@if ($a->is_default)<span class="badge-brass">{{ __('sales_admin.default') }}</span>@endif</div>
                        <p class="text-sm text-ink-600 dark:text-sand-200">{{ $reveal ? $a->recipient_name : mb_substr((string) $a->recipient_name, 0, 1).'•••' }} · <span dir="ltr">{{ $reveal ? $a->recipient_phone : Phone::mask($a->recipient_phone) }}</span></p>
                        <p class="mt-1 text-sm text-ink-500">{{ $reveal ? collect([__('sales_admin.zone_n', ['n' => $a->zone_number]), __('sales_admin.street_n', ['n' => $a->street_number]), __('sales_admin.building_n', ['n' => $a->building_number]), $a->unit_number, $a->area])->filter()->implode(', ') : __('sales_admin.zone_n', ['n' => $a->zone_number]).' · •••' }}</p>
                    </div>
                @endforeach
            </div>
        @endif
    @elseif ($current === 'loyalty')
        <div @class(['grid gap-6 [&>*]:min-w-0', 'lg:grid-cols-3' => ! $drawer, 'flex flex-col' => $drawer])>
            <div @class(['card overflow-hidden', 'lg:col-span-2' => ! $drawer, 'order-2' => $drawer])>
                <div class="p-5 sm:p-6"><h2 class="font-display text-xl font-semibold">{{ $drawer ? __('drawer_sales.ledger_recent') : __('sales_admin.points_ledger') }}</h2></div>
                @if ($ledger->isEmpty())
                    <x-admin.empty :title="__('sales_admin.ledger_empty')" icon="gift" class="border-t border-sand-100 dark:border-ink-800" />
                @else
                    <div class="table-wrap"><table class="table">
                        <thead><tr><th>{{ __('admin.date') }}</th><th>{{ __('sales_admin.type') }}</th><th>{{ __('admin.reason') }}</th><th class="!text-end">{{ __('sales_admin.points') }}</th><th class="!text-end">{{ __('sales_admin.balance') }}</th></tr></thead>
                        <tbody>@foreach ($ledger as $t)
                            <tr wire:key="lt-{{ $t->id }}">
                                <td class="text-xs text-ink-500 tabular-nums">{{ \App\Support\BusinessTime::display($t->created_at) }}</td>
                                <td><span class="badge-neutral">{{ __('marketing_admin.ltype_'.$t->type) }}</span></td>
                                <td class="max-w-[16rem] truncate text-sm" title="{{ $t->reason }}">{{ $t->reason }}</td>
                                <td class="text-end font-medium tabular-nums {{ $t->points < 0 ? 'text-ruby-soft' : 'text-emerald-soft' }}" dir="ltr">{{ $t->points > 0 ? '+' : '' }}{{ number_format($t->points) }}</td>
                                <td class="text-end tabular-nums">{{ number_format($t->balance_after) }}</td>
                            </tr>
                        @endforeach</tbody>
                    </table></div>
                    @if (! $drawer && $ledger->hasPages())<div class="border-t border-sand-100 p-4 dark:border-ink-800">{{ $ledger->links('components.admin.pagination') }}</div>@endif
                @endif
            </div>
            <div @class(['space-y-6', 'order-1' => $drawer])>
                @if ($canAdjust)
                    <form wire:submit="adjustPoints" class="card card-pad space-y-4">
                        <h2 class="font-display text-xl font-semibold">{{ __('sales_admin.adjust_points') }}</h2>
                        <div class="grid grid-cols-2 gap-2">
                            @foreach (['credit' => __('sales_admin.credit'), 'debit' => __('sales_admin.debit')] as $k => $label)
                                <label class="flex cursor-pointer items-center justify-center rounded-xl border py-2 text-sm font-medium {{ $adjust['direction'] === $k ? 'border-brass-500 bg-brass-50 dark:bg-brass-500/10' : 'border-sand-200 dark:border-ink-700' }}"><input type="radio" wire:model.live="adjust.direction" value="{{ $k }}" class="sr-only">{{ $label }}</label>
                            @endforeach
                        </div>
                        <x-admin.field :label="__('sales_admin.points')" for="adjust.points" required><input id="adjust.points" type="number" min="1" wire:model="adjust.points" class="input tabular-nums"></x-admin.field>
                        <x-admin.field :label="__('admin.reason')" for="adjust.reason" required :hint="__('sales_admin.adjust_reason_hint')"><textarea id="adjust.reason" wire:model="adjust.reason" rows="2" class="input"></textarea></x-admin.field>
                        <button class="btn-primary w-full" wire:loading.attr="disabled" wire:target="adjustPoints"><x-icon name="check" class="size-4" />{{ __('sales_admin.post_adjustment') }}</button>
                    </form>
                @endif
                <div class="card card-pad text-sm">
                    <h2 class="mb-3 font-display text-xl font-semibold">{{ __('sales_admin.referrals') }}</h2>
                    <dl class="space-y-2">
                        <div class="flex justify-between"><dt class="text-ink-500">{{ __('sales_admin.referral_code') }}</dt><dd class="font-mono" dir="ltr">{{ $referral['code'] ?? '—' }}</dd></div>
                        <div class="flex justify-between"><dt class="text-ink-500">{{ __('sales_admin.referred') }}</dt><dd class="tabular-nums">{{ $referral['referred_count'] }}</dd></div>
                        <div class="flex justify-between"><dt class="text-ink-500">{{ __('sales_admin.rewarded') }}</dt><dd class="tabular-nums">{{ $referral['rewarded_count'] }}</dd></div>
                    </dl>
                </div>
            </div>
        </div>
    @else
        <div @class(['grid gap-6 [&>*]:min-w-0', 'lg:grid-cols-3' => ! $drawer, 'flex flex-col' => $drawer])>
            <div class="card card-pad">
                <h2 class="mb-4 font-display text-xl font-semibold">{{ __('sales_admin.marketing_consent') }}</h2>
                @if ($c->marketing_consent_at)
                    <p class="flex items-center gap-2 text-sm text-emerald-soft"><x-icon name="check" class="size-4" />{{ __('sales_admin.consent_given', ['at' => \App\Support\BusinessTime::display($c->marketing_consent_at, 'd M Y')]) }}</p>
                @else
                    <p class="flex items-center gap-2 text-sm text-ink-500"><x-icon name="x" class="size-4" />{{ __('sales_admin.consent_not_given') }}</p>
                @endif
                <dl class="mt-4 space-y-2 border-t border-sand-100 pt-4 text-sm dark:border-ink-800">
                    <div class="flex justify-between"><dt class="text-ink-500">{{ __('sales_admin.phone_verified') }}</dt><dd>{{ $c->phone_verified_at ? __('admin.yes') : __('admin.no') }}</dd></div>
                    <div class="flex justify-between"><dt class="text-ink-500">{{ __('admin.language') }}</dt><dd>{{ $c->locale === 'ar' ? 'العربية' : 'English' }}</dd></div>
                </dl>
                <p class="mt-4 text-xs text-ink-500">{{ __('sales_admin.consent_customer_only') }}</p>
            </div>
            <div @class(['card overflow-hidden', 'lg:col-span-2' => ! $drawer])>
                <div class="p-5 sm:p-6"><h2 class="font-display text-xl font-semibold">{{ __('sales_admin.notification_prefs') }}</h2></div>
                <div class="table-wrap"><table class="table">
                    <thead><tr><th>{{ __('sales_admin.category') }}</th>@foreach (\App\Domain\Notifications\Services\NotificationCatalog::CHANNELS as $ch)<th class="!text-center">{{ __('content_admin.ch_'.$ch) }}</th>@endforeach</tr></thead>
                    <tbody>@foreach ($prefs as $cat => $rows)
                        <tr><td class="font-medium">{{ __('sales_admin.cat_'.$cat) }}</td>
                            @foreach ($rows as $p)<td class="text-center">@if ($p['is_enabled'])<x-icon name="check" class="inline size-4 text-emerald-soft" /><span class="sr-only">{{ __('admin.yes') }}</span>@else<x-icon name="minus" class="inline size-4 text-ink-300" /><span class="sr-only">{{ __('admin.no') }}</span>@endif</td>@endforeach
                        </tr>
                    @endforeach</tbody>
                </table></div>
            </div>
        </div>
    @endif

    <x-admin.dialog variant="modal" wire:model="showStatus" max-width="max-w-lg" :title="$newStatus === 'suspended' ? __('drawer_sales.q_suspend') : __('drawer_sales.q_reactivate')" :description="$newStatus === 'suspended' ? __('sales_admin.suspend_hint') : null">
        <form wire:submit="changeStatus" class="space-y-5">
            <x-admin.field :label="__('admin.reason')" for="statusReason" required><textarea id="statusReason" wire:model="statusReason" rows="3" class="input" autofocus></textarea></x-admin.field>
            {{-- Wrapped once more: this modal can sit inside a side window's .drawer-body, whose pinned-row rule must not match it. --}}
            <div class="border-t border-sand-100 pt-5 dark:border-ink-800">
                <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                    <button type="button" class="btn-secondary min-h-11" @click="open = false">{{ __('admin.back') }}</button>
                    <button class="{{ $newStatus === 'suspended' ? 'btn-danger' : 'btn-primary' }} min-h-11">{{ $newStatus === 'suspended' ? __('sales_admin.suspend') : __('sales_admin.reactivate') }}</button>
                </div>
            </div>
        </form>
    </x-admin.dialog>
</div>
