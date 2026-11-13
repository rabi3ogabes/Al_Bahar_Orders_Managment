<div>
    <x-admin.page-header :title="__('nav.payments')" :eyebrow="__('nav.finance')" :description="__('finance_admin.payments_hint')" />

    @if ($flagCounts['late_capture'] || $flagCounts['amount_mismatch'])
        <div class="mb-6 grid gap-3 sm:grid-cols-2 [&>*]:min-w-0">
            @foreach (['late_capture' => 'alert', 'amount_mismatch' => 'scale'] as $flag => $icon)
                @if ($flagCounts[$flag])
                    <button type="button" wire:click="$set('filterFlag', '{{ $filterFlag === $flag ? '' : $flag }}')" class="card flex items-center gap-4 p-4 text-start transition hover:border-ruby-soft/40 {{ $filterFlag === $flag ? 'ring-2 ring-ruby-soft/30' : '' }}">
                        <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-ruby-soft/10 text-ruby-soft"><x-icon :name="$icon" class="size-5" /></span>
                        <span class="min-w-0"><span class="block font-semibold text-ink-900 dark:text-ivory">{{ trans_choice('finance_admin.flag_count_'.$flag, $flagCounts[$flag], ['count' => $flagCounts[$flag]]) }}</span><span class="block text-xs text-ink-500">{{ __('finance_admin.flag_hint_'.$flag) }}</span></span>
                    </button>
                @endif
            @endforeach
        </div>
    @endif

    <div class="card">
        <x-admin.toolbar>
            <x-admin.search :placeholder="__('finance_admin.payments_search')" />
            <select wire:model.live="filterStatus" class="input sm:w-44" aria-label="{{ __('admin.status') }}">
                <option value="">{{ __('admin.all_statuses') }}</option>
                @foreach (\App\Domain\Payments\Enums\PaymentStatus::cases() as $st)<option value="{{ $st->value }}">{{ __('status.'.$st->value) }}</option>@endforeach
            </select>
            <select wire:model.live="filterMethod" class="input sm:w-44" aria-label="{{ __('finance_admin.method') }}">
                <option value="">{{ __('finance_admin.all_methods') }}</option>
                @foreach (['sadad', 'cod', 'pay_at_pickup'] as $m)<option value="{{ $m }}">{{ __('status.'.$m) }}</option>@endforeach
            </select>
            <select wire:model.live="filterFlag" class="input sm:w-48" aria-label="{{ __('finance_admin.flags') }}">
                <option value="">{{ __('finance_admin.no_flag_filter') }}</option>
                @foreach (['needs_attention', 'late_capture', 'amount_mismatch'] as $f)<option value="{{ $f }}">{{ __('finance_admin.flag_'.$f) }}</option>@endforeach
            </select>
            @if ($branches->count() > 1)
                <select wire:model.live="branch" class="input sm:w-44" aria-label="{{ __('admin.branch') }}"><option value="">{{ __('admin.all_branches') }}</option>@foreach ($branches as $b)<option value="{{ $b->id }}">{{ $b->code }}</option>@endforeach</select>
            @endif
            <x-slot:meta>{{ trans_choice('finance_admin.payments_count', $payments->total(), ['count' => $payments->total()]) }}</x-slot:meta>
        </x-admin.toolbar>
        @if ($payments->isEmpty())
            <x-admin.empty :title="__('finance_admin.payments_empty')" :description="__('finance_admin.payments_empty_hint')" icon="credit-card" />
        @else
            <div class="table-wrap"><table class="table">
                <thead><tr><th>{{ __('admin.order') }}</th><th>{{ __('finance_admin.method') }}</th><th class="!text-end">{{ __('finance_admin.amount') }}</th><th class="!text-end">{{ __('finance_admin.captured') }}</th><th class="!text-end">{{ __('finance_admin.refunded') }}</th><th>{{ __('admin.status') }}</th><th class="w-12"></th></tr></thead>
                <tbody>@foreach ($payments as $p)
                    <tr wire:key="p-{{ $p->id }}" wire:click="openView('{{ $p->id }}')" tabindex="-1" class="cursor-pointer focus:outline-none">
                        <td>
                            <a href="{{ route('admin.payments.index', ['view' => $p->id]) }}" wire:click.prevent.stop="openView('{{ $p->id }}')" @class(['font-medium text-ink-900 hover:text-brass-600 dark:text-ivory', 'font-mono' => $p->order]) @if ($p->order) dir="ltr" @endif>{{ $p->order ? $p->order->number : __('finance_admin.payable_'.$p->payable_type) }}</a>
                            <span class="block text-xs text-ink-500 tabular-nums">{{ \App\Support\BusinessTime::display($p->created_at) }} · #{{ $p->attempt }}</span>
                        </td>
                        <td><span class="text-sm">{{ __('status.'.$p->method) }}</span>@if ($p->provider_reference)<span class="block max-w-[10rem] truncate font-mono text-xs text-ink-500" dir="ltr" title="{{ $p->provider_reference }}">{{ $p->provider_reference }}</span>@endif</td>
                        <td class="text-end"><x-admin.money :value="$p->amount" /></td>
                        <td class="text-end text-emerald-soft"><x-admin.money :value="$p->captured_amount" /></td>
                        <td class="text-end {{ (float) $p->refunded_amount ? 'text-ruby-soft' : 'text-ink-400' }}">@if ((float) $p->refunded_amount)<x-admin.money :value="$p->refunded_amount" />@else — @endif</td>
                        <td>
                            <div class="flex flex-wrap items-center gap-1.5"><x-admin.status :value="$p->status->value" />
                                @if (! empty($p->metadata['late_capture']))<span class="badge-danger">{{ __('finance_admin.flag_late_capture') }}</span>@endif
                                @if (in_array($p->id, $mismatchIds, true))<span class="badge-danger">{{ __('finance_admin.flag_amount_mismatch') }}</span>@endif
                                @if (! empty($p->metadata['review_resolution']))<span class="badge-success" title="{{ $p->metadata['review_resolution']['note'] ?? '' }}">{{ __('finance_admin.resolved') }}</span>@endif
                            </div>
                        </td>
                        <td><span class="btn-ghost btn-sm pointer-events-none" aria-hidden="true"><x-icon name="chevron-right" class="size-4 rtl-flip" /></span></td>
                    </tr>
                @endforeach</tbody>
            </table></div>
            @if ($payments->hasPages())<div class="border-t border-sand-100 p-4 dark:border-ink-800">{{ $payments->links('components.admin.pagination') }}</div>@endif
        @endif
    </div>

    @include('livewire.admin.finance.partials.payment-window')
</div>
