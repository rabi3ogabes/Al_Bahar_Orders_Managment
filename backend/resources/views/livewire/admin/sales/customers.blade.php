@php use App\Livewire\Admin\Sales\CustomerIndex; use App\Support\Phone; @endphp
<div>
    <x-admin.page-header :title="__('nav.customers')" :eyebrow="__('nav.sales')" :description="__('sales_admin.customers_hint')" />

    <div class="card">
        <x-admin.toolbar>
            <x-admin.search :placeholder="__('sales_admin.customers_search')" />
            <select wire:model.live="filterStatus" class="input sm:w-44" aria-label="{{ __('admin.status') }}">
                <option value="">{{ __('admin.all_statuses') }}</option>
                @foreach (['active', 'suspended', 'deactivated'] as $st)<option value="{{ $st }}">{{ __('status.'.$st) }}</option>@endforeach
            </select>
            <select wire:model.live="filterConsent" class="input sm:w-52" aria-label="{{ __('sales_admin.marketing_consent') }}">
                <option value="">{{ __('sales_admin.any_consent') }}</option>
                <option value="yes">{{ __('sales_admin.consent_yes') }}</option>
                <option value="no">{{ __('sales_admin.consent_no') }}</option>
            </select>
            <x-slot:meta>{{ trans_choice('sales_admin.customers_count', $customers->total(), ['count' => $customers->total()]) }}</x-slot:meta>
        </x-admin.toolbar>
        @unless ($reveal)
            <p class="flex items-center gap-2 border-b border-sand-100 bg-sand-50/60 px-4 py-2 text-xs text-ink-500 sm:px-6 dark:border-ink-800 dark:bg-ink-800/30"><x-icon name="lock" class="size-3.5" />{{ __('sales_admin.pii_masked') }}</p>
        @endunless

        @if ($customers->isEmpty())
            <x-admin.empty :title="__('sales_admin.customers_empty')" :description="__('sales_admin.customers_empty_hint')" icon="users" />
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead><tr>
                        <th>{{ __('admin.customer') }}</th><th>{{ __('sales_admin.contact') }}</th><th class="!text-end">{{ __('admin.orders') }}</th>
                        <th class="!text-end">{{ __('sales_admin.lifetime_spend') }}</th><th class="!text-end">{{ __('sales_admin.points') }}</th><th>{{ __('admin.status') }}</th><th class="w-12"></th>
                    </tr></thead>
                    <tbody>
                    @foreach ($customers as $c)
                        <tr wire:key="c-{{ $c->id }}">
                            <td>
                                <div class="flex items-center gap-3">
                                    <span class="grid size-9 shrink-0 place-items-center rounded-full bg-sand-100 text-sm font-semibold text-ink-700 dark:bg-ink-800 dark:text-sand-100">{{ mb_substr($c->name, 0, 1) }}</span>
                                    <div class="min-w-0">
                                        <a href="{{ route('admin.customers.show', $c) }}" wire:navigate class="block truncate font-medium text-ink-900 hover:text-brass-600 dark:text-ivory">{{ $c->name }}</a>
                                        <span class="text-xs text-ink-500">{{ __('sales_admin.joined', ['date' => \App\Support\BusinessTime::display($c->created_at, 'M Y')]) }}</span>
                                    </div>
                                </div>
                            </td>
                            <td class="text-xs">
                                <span class="block" dir="ltr">{{ $reveal ? $c->phone : Phone::mask($c->phone) }}</span>
                                <span class="block text-ink-500" dir="ltr">{{ $reveal ? $c->email : CustomerIndex::maskEmail($c->email) }}</span>
                            </td>
                            <td class="text-end tabular-nums">{{ $c->orders_count }}</td>
                            <td class="text-end"><x-admin.money :value="$c->spend ?? '0'" /></td>
                            <td class="text-end tabular-nums">{{ number_format($c->loyaltyAccount?->balance ?? 0) }}@if ($c->loyaltyAccount && $c->loyaltyAccount->tier !== 'classic')<span class="badge-brass ms-1.5">{{ __('sales_admin.tier_'.$c->loyaltyAccount->tier) }}</span>@endif</td>
                            <td><x-admin.status :value="$c->status->value" /></td>
                            <td><a href="{{ route('admin.customers.show', $c) }}" wire:navigate class="btn-ghost btn-sm" aria-label="{{ __('admin.details') }}"><x-icon name="chevron-right" class="size-4 rtl-flip" /></a></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            @if ($customers->hasPages())<div class="border-t border-sand-100 p-4 dark:border-ink-800">{{ $customers->links('components.admin.pagination') }}</div>@endif
        @endif
    </div>
</div>
