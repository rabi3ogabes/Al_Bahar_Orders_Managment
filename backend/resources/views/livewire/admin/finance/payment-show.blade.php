<div @class(['mx-auto max-w-7xl' => ! $drawer])>
    @if (! $drawer)
    <a href="{{ route('admin.payments.index') }}" wire:navigate class="mb-4 inline-flex items-center gap-1.5 text-sm text-ink-500 hover:text-brass-600"><x-icon name="chevron-left" class="size-4 rtl-flip" />{{ __('nav.payments') }}</a>
    <div class="mb-8 flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <div class="min-w-0">
            <p class="eyebrow mb-2">{{ __('finance_admin.payment') }} · {{ __('status.'.$p->method) }}</p>
            <h1 class="font-display text-3xl font-semibold tracking-tight text-ink-900 sm:text-4xl dark:text-ivory"><x-admin.money :value="$p->amount" /></h1>
            <div class="mt-3 flex flex-wrap items-center gap-2 text-sm text-ink-500">
                <x-admin.status :value="$p->status->value" />
                @if ($p->order)<a href="{{ route('admin.orders.show', $p->order) }}" wire:navigate class="font-mono hover:text-brass-600" dir="ltr">{{ $p->order->number }}</a> · <span>{{ $p->order->customer_name }}</span>@endif
                <span>· {{ __('sales_admin.attempt_n', ['n' => $p->attempt]) }}</span>
            </div>
        </div>
        <div class="flex flex-wrap gap-2">
            @if ($canRecheck)<button type="button" wire:click="recheck" wire:loading.attr="disabled" wire:target="recheck" class="btn-secondary"><x-icon name="refresh" class="size-4" wire:loading.class="animate-spin" wire:target="recheck" />{{ __('finance_admin.recheck_provider') }}</button>@endif
            @if ($canRefund)<button type="button" wire:click="openRefund" class="btn-primary"><x-icon name="refund" class="size-4" />{{ __('finance_admin.request_refund') }}</button>@endif
        </div>
    </div>
    @elseif ($canRecheck || $canRefund)
    {{-- Side window: amount and status are in the window's title and top bar; the two payment actions stay here. --}}
    <div class="mb-5 flex flex-wrap gap-2">
        @if ($canRecheck)<button type="button" wire:click="recheck" wire:loading.attr="disabled" wire:target="recheck" class="btn-secondary min-h-11"><x-icon name="refresh" class="size-4" wire:loading.class="animate-spin" wire:target="recheck" />{{ __('finance_admin.recheck_provider') }}</button>@endif
        @if ($canRefund)<button type="button" wire:click="openRefund" class="btn-primary min-h-11"><x-icon name="refund" class="size-4" />{{ __('finance_admin.request_refund') }}</button>@endif
    </div>
    @endif

    @if ($flagged)
        <div class="card mb-6 border-ruby-soft/30 bg-ruby-soft/5 p-5 sm:p-6">
            <div class="flex flex-col gap-4 md:flex-row">
                <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-ruby-soft/15 text-ruby-soft"><x-icon name="alert" class="size-5" /></span>
                <div class="min-w-0 flex-1">
                    <h2 class="font-semibold text-ruby-soft">{{ $mismatch ? __('finance_admin.flag_amount_mismatch') : __('finance_admin.flag_late_capture') }}</h2>
                    <p class="mt-1 text-sm text-ink-700 dark:text-sand-100">{{ $mismatch ? __('finance_admin.mismatch_explain') : __('finance_admin.late_capture_explain', ['amount' => \App\Support\Money::of($p->metadata['late_capture']['amount'] ?? '0')->format(), 'ref' => $p->metadata['late_capture']['provider_reference'] ?? '—']) }}</p>
                    @if (! empty($p->metadata['review_resolution']))
                        <p class="mt-3 rounded-xl bg-white/70 p-3 text-sm dark:bg-ink-900/60"><span class="badge-success me-2">{{ __('finance_admin.resolved') }}</span>{{ $p->metadata['review_resolution']['note'] }} <span class="block text-xs text-ink-500">{{ $p->metadata['review_resolution']['by_name'] ?? '' }} · {{ \App\Support\BusinessTime::display($p->metadata['review_resolution']['at'] ?? null) }}</span></p>
                    @elseif ($canResolve)
                        <form wire:submit="resolve" class="mt-4 flex flex-col gap-2 sm:flex-row sm:items-start">
                            <div class="flex-1"><input wire:model="resolution" class="input" placeholder="{{ __('finance_admin.resolution_ph') }}" aria-label="{{ __('finance_admin.resolution') }}">@error('resolution')<p class="field-error">{{ $message }}</p>@enderror</div>
                            <button class="btn-primary min-h-11"><x-icon name="check" class="size-4" />{{ __('finance_admin.mark_resolved') }}</button>
                        </form>
                    @endif
                </div>
            </div>
        </div>
    @endif

    {{-- Page: two columns. Side window: one column in reading order (display: contents + order-*), because lg: follows the screen, not the window. --}}
    <div @class(['grid gap-6 lg:grid-cols-3 [&>*]:min-w-0' => ! $drawer, 'flex flex-col gap-5 [&>*]:min-w-0' => $drawer])>
        <div @class(['min-w-0 space-y-6 lg:col-span-2' => ! $drawer, 'contents' => $drawer])>
            <section class="card overflow-hidden {{ $drawer ? 'order-4' : '' }}">
                <div class="p-5 sm:p-6"><h2 class="font-display text-xl font-semibold">{{ __('finance_admin.transactions') }}</h2><p class="mt-1 text-sm text-ink-500">{{ __('finance_admin.transactions_hint') }}</p></div>
                @if ($p->transactions->isEmpty())
                    <p class="border-t border-sand-100 px-6 py-8 text-center text-sm text-ink-500 dark:border-ink-800">{{ __('finance_admin.no_transactions') }}</p>
                @else
                    <ul class="divide-y divide-sand-100 border-t border-sand-100 dark:divide-ink-800 dark:border-ink-800">
                        @foreach ($p->transactions as $t)
                            <li x-data="{ open: false }" class="px-5 py-3.5 sm:px-6" wire:key="t-{{ $t->id }}">
                                <button type="button" @click="open = !open" class="flex min-h-11 w-full flex-wrap items-center gap-x-3 gap-y-1 text-start" :aria-expanded="open">
                                    <span class="badge-neutral font-mono">{{ $t->type }}</span>
                                    <x-admin.status :value="$t->status" />
                                    <span class="min-w-0 flex-1 truncate font-mono text-xs text-ink-500" dir="ltr">{{ $t->transaction_reference }}</span>
                                    @if ($t->amount !== null)<x-admin.money :value="$t->amount" class="text-sm" />@endif
                                    <span class="text-xs text-ink-500 tabular-nums">{{ \App\Support\BusinessTime::display($t->created_at, 'd M H:i:s') }}</span>
                                    <x-icon name="chevron-down" class="size-4 text-ink-400 transition" ::class="open && 'rotate-180'" />
                                </button>
                                <div x-show="open" x-collapse x-cloak @class(['mt-3 grid gap-3 [&>*]:min-w-0', 'md:grid-cols-2' => ! $drawer])>
                                    <div><p class="mb-1 text-xs font-semibold uppercase tracking-wider text-ink-500">{{ __('finance_admin.request') }}</p><pre class="max-h-64 overflow-auto rounded-xl bg-sand-50 p-3 font-mono text-xs dark:bg-ink-800" dir="ltr">{{ $t->request ? json_encode($t->request, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '—' }}</pre></div>
                                    <div><p class="mb-1 text-xs font-semibold uppercase tracking-wider text-ink-500">{{ __('finance_admin.response') }}</p><pre class="max-h-64 overflow-auto rounded-xl bg-sand-50 p-3 font-mono text-xs dark:bg-ink-800" dir="ltr">{{ $t->response ? json_encode($t->response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '—' }}</pre></div>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            <section class="card overflow-hidden {{ $drawer ? 'order-2' : '' }}">
                <div class="p-5 sm:p-6"><h2 class="font-display text-xl font-semibold">{{ __('nav.refunds') }}</h2></div>
                @if ($p->refunds->isEmpty())
                    <p class="border-t border-sand-100 px-6 py-8 text-center text-sm text-ink-500 dark:border-ink-800">{{ __('finance_admin.no_refunds') }}</p>
                @else
                    <ul class="divide-y divide-sand-100 border-t border-sand-100 dark:divide-ink-800 dark:border-ink-800">
                        @foreach ($p->refunds as $r)
                            <li class="flex flex-wrap items-center gap-3 px-5 py-3.5 sm:px-6" wire:key="r-{{ $r->id }}">
                                <a href="{{ route('admin.refunds.index', ['search' => $r->number]) }}" wire:navigate class="font-mono text-sm hover:text-brass-600" dir="ltr">{{ $r->number }}</a>
                                <x-admin.status :value="$r->status" />
                                <span class="text-xs text-ink-500">{{ __('finance_admin.method_'.$r->method) }} · {{ $r->requester?->name ?? __('sales_admin.system') }}</span>
                                <x-admin.money :value="$r->amount" class="ms-auto font-medium" />
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            @if ($p->collections->isNotEmpty())
                <section class="card overflow-hidden {{ $drawer ? 'order-3' : '' }}">
                    <div class="p-5 sm:p-6"><h2 class="font-display text-xl font-semibold">{{ __('nav.cash') }}</h2></div>
                    <ul class="divide-y divide-sand-100 border-t border-sand-100 dark:divide-ink-800 dark:border-ink-800">
                        @foreach ($p->collections as $c)
                            <li class="flex flex-wrap items-center gap-3 px-5 py-3.5 text-sm sm:px-6" wire:key="c-{{ $c->id }}">
                                <x-admin.status :value="$c->status" />
                                <span>{{ __('sales_admin.tender_'.($c->tender === 'card_terminal' ? 'card' : 'cash')) }}</span>
                                <span class="text-xs text-ink-500">{{ $c->collector?->name }} · <span class="font-mono" dir="ltr">{{ $c->branch?->code }}</span> · {{ \App\Support\BusinessTime::display($c->collected_at) }}</span>
                                @if ($c->failure_reason)<span class="text-xs text-ruby-soft">{{ $c->failure_reason }}</span>@endif
                                <x-admin.money :value="$c->amount_collected" class="ms-auto font-medium" />
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif
        </div>

        <aside @class(['card card-pad h-fit', 'order-1' => $drawer])>
            <h2 class="mb-4 font-display text-xl font-semibold">{{ __('admin.details') }}</h2>
            <dl class="space-y-2.5 text-sm">
                @foreach ([
                    'provider' => $p->provider, 'reference' => $p->provider_reference, 'captured' => \App\Support\Money::of($p->captured_amount)->format(),
                    'refunded' => \App\Support\Money::of($p->refunded_amount)->format(), 'refundable' => $p->refundable()->format(),
                    'created' => \App\Support\BusinessTime::display($p->created_at), 'paid_at' => \App\Support\BusinessTime::display($p->paid_at),
                    'expires_at' => \App\Support\BusinessTime::display($p->expires_at), 'failure' => $p->failure_reason,
                ] as $k => $v)
                    @if ($v !== null && $v !== '')
                        <div class="flex justify-between gap-4"><dt class="text-ink-500">{{ __('finance_admin.d_'.$k) }}</dt><dd class="truncate text-end {{ in_array($k, ['reference', 'provider'], true) ? 'font-mono text-xs' : 'tabular-nums' }}" dir="{{ in_array($k, ['reference', 'captured', 'refunded', 'refundable'], true) ? 'ltr' : 'auto' }}" title="{{ $v }}">{{ $v }}</dd></div>
                    @endif
                @endforeach
            </dl>
        </aside>
    </div>

    {{-- On the page a refund request is a side-window form; over a payment window it is a centred modal (never a window on a window). --}}
    <x-admin.dialog wire:model="showRefund" :variant="$drawer ? 'modal' : 'drawer'" max-width="max-w-lg" :title="__('finance_admin.request_refund')" :description="__('finance_admin.request_refund_hint', ['max' => $p->refundable()->format()])">
        <form wire:submit="requestRefund" class="space-y-5">
            <div class="grid gap-4 sm:grid-cols-2 [&>*]:min-w-0">
                <x-admin.field :label="__('finance_admin.amount')" for="refund.amount" required><input id="refund.amount" type="number" step="0.01" min="0.01" wire:model="refund.amount" class="input tabular-nums" dir="ltr"></x-admin.field>
                <x-admin.field :label="__('finance_admin.method')" for="refund.method">
                    <select id="refund.method" wire:model="refund.method" class="input">@foreach (['original', 'manual_cash', 'manual_bank_transfer'] as $m)<option value="{{ $m }}">{{ __('finance_admin.method_'.$m) }}</option>@endforeach</select>
                </x-admin.field>
            </div>
            <x-admin.field :label="__('sales_admin.reason_code')" for="refund.reason_code">
                <select id="refund.reason_code" wire:model="refund.reason_code" class="input">@foreach (['customer_request', 'order_cancelled', 'damaged', 'goodwill', 'duplicate_charge', 'other'] as $rc)<option value="{{ $rc }}">{{ __('finance_admin.rrc_'.$rc) }}</option>@endforeach</select>
            </x-admin.field>
            <x-admin.field :label="__('admin.notes')" for="refund.reason"><textarea id="refund.reason" wire:model="refund.reason" rows="2" class="input"></textarea></x-admin.field>
            <p class="rounded-xl bg-sky-soft/5 p-3 text-xs text-sky-soft dark:text-sky-300"><x-icon name="shield" class="me-1 inline size-3.5" />{{ __('finance_admin.dual_control_notice') }}</p>
            <div class="flex flex-col-reverse gap-2 border-t border-sand-100 pt-5 sm:flex-row sm:justify-end dark:border-ink-800">
                <button type="button" class="btn-secondary min-h-11" @click="open = false">{{ __('admin.cancel') }}</button>
                <button class="btn-primary min-h-11" wire:loading.attr="disabled" wire:target="requestRefund">{{ __('finance_admin.submit_request') }}</button>
            </div>
        </form>
    </x-admin.dialog>
</div>
