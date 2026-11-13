<div>
    <x-admin.page-header :title="__('nav.reconciliation')" :eyebrow="__('nav.finance')" :description="__('finance_admin.recon_hint')" />

    <div class="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4 [&>*]:min-w-0">
        <x-admin.stat :label="__('finance_admin.recon_payments')" :value="number_format($total)" icon="credit-card" />
        <x-admin.stat :label="__('finance_admin.captured')" :value="\App\Support\Money::of($summary['captured'] ?? '0')->format()" icon="wallet" tone="brass" />
        <x-admin.stat :label="__('finance_admin.recon_exceptions')" :value="number_format($summary['exceptions'] ?? 0)" icon="alert" />
        <x-admin.stat :label="__('finance_admin.recon_matched')" :value="number_format($checks['ok'] ?? 0)" icon="check" />
    </div>

    <div class="card">
        <x-admin.toolbar>
            <div class="flex items-center gap-2">
                <input type="date" wire:model.live="from" class="input w-auto" aria-label="{{ __('sales_admin.from_date') }}">
                <span class="text-ink-400">–</span>
                <input type="date" wire:model.live="to" class="input w-auto" aria-label="{{ __('sales_admin.to_date') }}">
            </div>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model.live="exceptionsOnly" class="checkbox">{{ __('finance_admin.exceptions_only') }}</label>
            <x-slot:meta>
                @foreach (['missing_callback', 'amount_mismatch', 'late_capture'] as $k)@if ($checks[$k] ?? 0)<span class="badge-danger ms-1">{{ __('finance_admin.check_'.$k) }} · {{ $checks[$k] }}</span>@endif @endforeach
            </x-slot:meta>
        </x-admin.toolbar>
        @if ($error)
            <x-admin.empty :title="__('finance_admin.recon_unavailable')" :description="$error" icon="lock" />
        @elseif ($rows->isEmpty())
            <x-admin.empty :title="$exceptionsOnly ? __('finance_admin.recon_clean') : __('finance_admin.recon_empty')" :description="__('finance_admin.recon_empty_hint')" icon="scale" />
        @else
            <div class="table-wrap"><table class="table">
                <thead><tr><th>{{ __('finance_admin.payment') }}</th><th>{{ __('finance_admin.d_reference') }}</th><th class="!text-end">{{ __('finance_admin.amount') }}</th><th class="!text-end">{{ __('finance_admin.captured') }}</th><th>{{ __('admin.status') }}</th><th>{{ __('finance_admin.check') }}</th><th class="!text-end">{{ __('admin.actions') }}</th></tr></thead>
                <tbody>@foreach ($rows as $row)
                    @php $p = $payments[$row['id']] ?? null; $res = $p?->metadata['review_resolution'] ?? null; @endphp
                    <tr wire:key="rc-{{ $row['id'] }}" @if ($p) wire:click="openView('{{ $p->id }}')" tabindex="-1" class="cursor-pointer focus:outline-none" @endif>
                        <td>@if ($p)<a href="{{ route('admin.reconciliation.index', ['view' => $p->id]) }}" wire:click.prevent.stop="openView('{{ $p->id }}')" class="font-mono text-sm font-medium hover:text-brass-600" dir="ltr">{{ $p->order?->number ?? \Illuminate\Support\Str::limit($row['id'], 8, '') }}</a><span class="block text-xs text-ink-500 tabular-nums">{{ \App\Support\BusinessTime::display($p->created_at) }}</span>@endif</td>
                        <td class="max-w-[10rem] truncate font-mono text-xs" dir="ltr">{{ $row['provider_reference'] ?? '—' }}</td>
                        <td class="text-end"><x-admin.money :value="$row['amount']" /></td>
                        <td class="text-end"><x-admin.money :value="$row['captured_amount']" /></td>
                        <td><x-admin.status :value="$row['status']" /></td>
                        <td>@if ($row['check'] === 'ok')<span class="badge-success">{{ __('finance_admin.check_ok') }}</span>@else<span class="badge-danger">{{ __('finance_admin.check_'.$row['check']) }}</span>@endif
                            @if ($res)<span class="mt-1 block max-w-[14rem] truncate text-xs text-emerald-soft" title="{{ $res['note'] }}"><x-icon name="check" class="me-0.5 inline size-3" />{{ $res['note'] }}</span>@endif</td>
                        <td class="text-end">
                            <div class="flex justify-end gap-1" @click.stop>
                                @if ($canRecheck && in_array($row['status'], ['pending', 'processing'], true))<button type="button" wire:click="recheck('{{ $row['id'] }}')" class="btn-ghost btn-sm" title="{{ __('finance_admin.recheck_provider') }}"><x-icon name="refresh" class="size-4" /></button>@endif
                                @if ($canResolve && $row['check'] !== 'ok' && ! $res)<button type="button" wire:click="askResolve('{{ $row['id'] }}')" class="btn-secondary btn-sm">{{ __('finance_admin.resolve') }}</button>@endif
                            </div>
                        </td>
                    </tr>
                @endforeach</tbody>
            </table></div>
        @endif
    </div>

    @include('livewire.admin.finance.partials.payment-window')

    <x-admin.dialog wire:model="showResolve" :title="__('finance_admin.resolve_exception')" :description="__('finance_admin.resolve_exception_hint')">
        <form wire:submit="resolve" class="space-y-5">
            <x-admin.field :label="__('finance_admin.resolution')" for="note" required><textarea id="note" wire:model="note" rows="3" class="input" placeholder="{{ __('finance_admin.resolution_ph') }}"></textarea></x-admin.field>
            <div class="flex flex-col-reverse gap-2 border-t border-sand-100 pt-5 sm:flex-row sm:justify-end dark:border-ink-800">
                <button type="button" class="btn-secondary min-h-11" @click="open = false">{{ __('admin.cancel') }}</button>
                <button class="btn-primary min-h-11">{{ __('finance_admin.mark_resolved') }}</button>
            </div>
        </form>
    </x-admin.dialog>
</div>
