@php $tabs = ['ledger' => ['history', __('marketing_admin.points_ledger')], 'referrals' => ['users', __('marketing_admin.referrals')], 'rules' => ['settings', __('marketing_admin.rules_tab')]]; @endphp
<div>
    <x-admin.page-header :title="__('nav.loyalty')" :eyebrow="__('nav.marketing')" :description="__('marketing_admin.loyalty_hint')" />

    <div class="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4 [&>*]:min-w-0">
        <x-admin.stat :label="__('marketing_admin.outstanding_points')" :value="number_format($stats['outstanding'])" icon="gift" tone="brass" :hint="__('sales_admin.worth', ['amount' => \App\Support\Money::ofMinor((int) round($stats['outstanding'] * (float) $rules['redeem_value_per_point'] * 100))->format()])" />
        <x-admin.stat :label="__('marketing_admin.active_members')" :value="number_format($stats['members'])" icon="users" />
        <x-admin.stat :label="__('marketing_admin.earned_30d')" :value="number_format($stats['earned'])" icon="plus" />
        <x-admin.stat :label="__('marketing_admin.redeemed_30d')" :value="number_format($stats['redeemed'])" icon="minus" />
    </div>

    <x-admin.tabs :tabs="$tabs" :current="$tab" />

    @if ($tab === 'rules')
        <form wire:submit="saveRules" class="card card-pad max-w-3xl space-y-6">
            <div class="flex items-start justify-between gap-4">
                <div><h2 class="font-display text-xl font-semibold">{{ __('marketing_admin.programme_rules') }}</h2><p class="mt-1 text-sm text-ink-500">{{ __('marketing_admin.rules_hint') }}</p></div>
                @if ($canEditRules && ! $editingRules)<button type="button" wire:click="$set('editingRules', true)" class="btn-secondary btn-sm"><x-icon name="edit" class="size-4" />{{ __('admin.edit') }}</button>@endif
            </div>
            <fieldset @disabled(! $editingRules) class="space-y-4">
                <x-admin.toggle :label="__('marketing_admin.rule_enabled')" wire:model="rules.enabled" />
                <div class="grid gap-4 sm:grid-cols-2 [&>*]:min-w-0">
                    @foreach (['points_per_qar', 'redeem_value_per_point', 'max_redeem_percent', 'points_expiry_days', 'referral_referrer_points', 'referral_referee_points'] as $k)
                        <x-admin.field :label="__('marketing_admin.rule_'.$k)" for="rules.{{ $k }}" :hint="__('marketing_admin.rule_'.$k.'_hint')"><input id="rules.{{ $k }}" type="number" step="{{ $k === 'redeem_value_per_point' ? '0.01' : '1' }}" min="0" wire:model="rules.{{ $k }}" class="input tabular-nums" dir="ltr"></x-admin.field>
                    @endforeach
                </div>
            </fieldset>
            <div class="rounded-xl bg-sand-50 p-4 text-sm text-ink-600 dark:bg-ink-800/60 dark:text-sand-200">
                <p>{{ __('marketing_admin.rules_example', ['spend' => \App\Support\Money::of('1000')->format(), 'points' => number_format(1000 * (int) $rules['points_per_qar']), 'value' => \App\Support\Money::ofMinor((int) round(1000 * (int) $rules['points_per_qar'] * (float) $rules['redeem_value_per_point'] * 100))->format()]) }}</p>
            </div>
            <div class="flex justify-end gap-2">
                <p class="me-auto text-xs text-ink-500">{{ __('marketing_admin.tiers') }}: @foreach (['classic', 'silver', 'gold', 'platinum'] as $t){{ __('sales_admin.tier_'.$t) }} {{ $tiers[$t] ?? 0 }}@if (! $loop->last) · @endif @endforeach</p>
                @if ($editingRules)<button type="button" wire:click="$set('editingRules', false)" class="btn-secondary">{{ __('admin.cancel') }}</button><button class="btn-primary"><x-icon name="check" class="size-4" />{{ __('admin.save_changes') }}</button>@endif
            </div>
        </form>
    @else
        <div class="card">
            <x-admin.toolbar>
                <x-admin.search :placeholder="__('marketing_admin.loyalty_search')" />
                @if ($tab === 'ledger')
                    <select wire:model.live="filterType" class="input sm:w-44" aria-label="{{ __('sales_admin.type') }}">
                        <option value="">{{ __('marketing_admin.all_types') }}</option>
                        @foreach (['earn', 'redeem', 'expire', 'reverse', 'adjust', 'referral'] as $t)<option value="{{ $t }}">{{ __('marketing_admin.ltype_'.$t) }}</option>@endforeach
                    </select>
                @endif
            </x-admin.toolbar>
            @if ($tab === 'ledger')
                @if ($ledger->isEmpty())
                    <x-admin.empty :title="__('marketing_admin.ledger_empty')" :description="__('marketing_admin.ledger_empty_hint')" icon="gift" />
                @else
                    <div class="table-wrap"><table class="table">
                        <thead><tr><th>{{ __('admin.date') }}</th><th>{{ __('admin.customer') }}</th><th>{{ __('sales_admin.type') }}</th><th>{{ __('admin.reason') }}</th><th class="!text-end">{{ __('sales_admin.points') }}</th><th class="!text-end">{{ __('sales_admin.balance') }}</th></tr></thead>
                        <tbody>@foreach ($ledger as $t)
                            @php $acc = $accounts[$t->loyalty_account_id] ?? null; @endphp
                            <tr wire:key="lt-{{ $t->id }}">
                                <td class="text-xs text-ink-500 tabular-nums">{{ \App\Support\BusinessTime::display($t->created_at) }}</td>
                                <td>@if ($acc)<a href="{{ route('admin.customers.show', $acc->user_id) }}" wire:navigate class="font-medium hover:text-brass-600">{{ $names[$acc->user_id] ?? '—' }}</a>@endif</td>
                                <td><span class="badge-neutral">{{ __('marketing_admin.ltype_'.$t->type) }}</span></td>
                                <td class="max-w-[18rem] truncate text-sm" title="{{ $t->reason }}">{{ $t->reason }}</td>
                                <td class="text-end font-medium tabular-nums {{ $t->points < 0 ? 'text-ruby-soft' : 'text-emerald-soft' }}" dir="ltr">{{ $t->points > 0 ? '+' : '' }}{{ number_format($t->points) }}</td>
                                <td class="text-end tabular-nums">{{ number_format($t->balance_after) }}</td>
                            </tr>
                        @endforeach</tbody>
                    </table></div>
                    @if ($ledger->hasPages())<div class="border-t border-sand-100 p-4 dark:border-ink-800">{{ $ledger->links('components.admin.pagination') }}</div>@endif
                @endif
            @else
                @if ($referrals->isEmpty())
                    <x-admin.empty :title="__('marketing_admin.referrals_empty')" icon="users" />
                @else
                    <div class="table-wrap"><table class="table">
                        <thead><tr><th>{{ __('marketing_admin.referrer') }}</th><th>{{ __('marketing_admin.referee') }}</th><th>{{ __('marketing_admin.code') }}</th><th>{{ __('admin.date') }}</th><th>{{ __('admin.status') }}</th></tr></thead>
                        <tbody>@foreach ($referrals as $r)
                            <tr wire:key="ref-{{ $r->id }}">
                                <td>{{ $refUsers[$r->referrer_id] ?? '—' }}</td><td>{{ $refUsers[$r->referee_id] ?? '—' }}</td>
                                <td class="font-mono text-xs" dir="ltr">{{ $r->code }}</td>
                                <td class="text-xs text-ink-500 tabular-nums">{{ \App\Support\BusinessTime::display($r->created_at) }}</td>
                                <td><x-admin.status :value="$r->status" />@if ($r->rejection_reason)<span class="mt-1 block text-xs text-ink-500">{{ $r->rejection_reason }}</span>@endif</td>
                            </tr>
                        @endforeach</tbody>
                    </table></div>
                    @if ($referrals->hasPages())<div class="border-t border-sand-100 p-4 dark:border-ink-800">{{ $referrals->links('components.admin.pagination') }}</div>@endif
                @endif
            @endif
        </div>
    @endif
</div>
