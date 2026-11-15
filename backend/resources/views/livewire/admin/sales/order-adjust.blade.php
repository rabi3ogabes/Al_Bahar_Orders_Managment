<div>
    <button type="button" wire:click="open" wire:loading.attr="disabled" wire:target="open" class="btn-secondary min-h-11"><x-icon name="transfer" class="size-4" />{{ __('adjust_admin.button') }}</button>

    <x-admin.dialog wire:model="show" :variant="$drawer ? 'modal' : 'drawer'" :max-width="$drawer ? 'max-w-3xl' : 'max-w-2xl'" :title="__('adjust_admin.title')"
                    :description="__('adjust_admin.description', ['branch' => $f->branch->localized('name'), 'number' => $f->number])">
        @if ($show && $context)
            <form wire:submit="confirm" class="space-y-5" novalidate>
                <ul class="space-y-3" aria-label="{{ __('adjust_admin.lines') }}">
                    @foreach ($context['items'] as $it)
                        @php
                            $id = $it['order_item_id'];
                            $l = $lines[$id] ?? null;
                            $qty = $it['quantity'];
                            $choices = ['keep' => ['check', __('adjust_admin.act_keep')]] + ($qty > 1 ? ['reduce' => ['minus', __('adjust_admin.act_fewer')]] : []) + ['replace' => ['transfer', __('adjust_admin.act_replace')], 'remove' => ['trash', __('adjust_admin.act_remove')]];
                        @endphp
                        @continue(! $l)
                        <li wire:key="adj-{{ $id }}" @class(['rounded-2xl border p-4 transition', 'border-sand-200 bg-white dark:border-ink-700 dark:bg-ink-900' => $l['action'] === 'keep', 'border-brass-500/60 bg-brass-50/40 dark:bg-brass-500/5' => in_array($l['action'], ['reduce', 'replace'], true), 'border-ruby-soft/50 bg-ruby-soft/5' => $l['action'] === 'remove'])>
                            <div class="flex items-start gap-3">
                                <div class="min-w-0 flex-1">
                                    <p @class(['font-medium text-ink-900 dark:text-ivory', 'line-through opacity-60' => in_array($l['action'], ['remove', 'replace'], true)])>{{ $it['name'] }}</p>
                                    <p class="flex flex-wrap items-center gap-x-2 text-xs text-ink-500"><span class="font-mono" dir="ltr">{{ $it['sku'] }}</span>@if ($it['variant_name'])<span>· {{ $it['variant_name'] }}</span>@endif
                                        @if ($it['adjustment'])<span class="badge-warning !text-[0.65rem]">{{ __('adjustments.kind.'.$it['adjustment']['kind']) }}</span>@endif</p>
                                </div>
                                <div class="shrink-0 text-end text-sm tabular-nums">
                                    <p class="text-ink-500"><span dir="ltr">{{ $qty }} ×</span> {{ $it['unit_price']['formatted'] }}</p>
                                    <p class="font-medium">{{ $it['line_total']['formatted'] }}</p>
                                </div>
                            </div>

                            <div @class(['mt-3 grid gap-2', 'grid-cols-2 sm:grid-cols-4' => count($choices) === 4, 'grid-cols-3' => count($choices) === 3]) role="group" aria-label="{{ $it['name'] }}">
                                @foreach ($choices as $key => [$icon, $label])
                                    <button type="button" wire:click="choose('{{ $id }}', '{{ $key }}')" aria-pressed="{{ $l['action'] === $key ? 'true' : 'false' }}"
                                            @class(['flex min-h-11 items-center justify-center gap-1.5 rounded-xl border px-2 text-sm font-medium transition',
                                                'border-brass-500 bg-brass-50 text-ink-900 dark:bg-brass-500/15 dark:text-ivory' => $l['action'] === $key && $key !== 'remove',
                                                'border-ruby-soft bg-ruby-soft/10 text-ruby-soft' => $l['action'] === $key && $key === 'remove',
                                                'border-sand-200 text-ink-600 hover:border-brass-400 dark:border-ink-700 dark:text-sand-200' => $l['action'] !== $key])>
                                        <x-icon :name="$icon" class="size-4" />{{ $label }}
                                    </button>
                                @endforeach
                            </div>

                            @if ($l['action'] === 'reduce')
                                <div class="mt-3 flex items-center justify-between gap-3">
                                    <span class="text-sm text-ink-600 dark:text-sand-200">{{ __('adjust_admin.new_quantity') }} <span class="text-xs text-ink-500">({{ __('adjust_admin.of_ordered', ['n' => $qty]) }})</span></span>
                                    <div class="inline-flex items-center rounded-xl border border-sand-200 dark:border-ink-700">
                                        <button type="button" wire:click="step('{{ $id }}', -1, {{ $qty }})" class="btn-ghost size-11 p-0" aria-label="{{ __('adjust_admin.fewer') }}" @disabled($l['to'] <= 1)><x-icon name="minus" class="size-4" /></button>
                                        <span class="min-w-10 text-center font-semibold tabular-nums" aria-live="polite">{{ $l['to'] }}</span>
                                        <button type="button" wire:click="step('{{ $id }}', 1, {{ $qty }})" class="btn-ghost size-11 p-0" aria-label="{{ __('adjust_admin.more') }}" @disabled($l['to'] >= $qty - 1)><x-icon name="plus" class="size-4" /></button>
                                    </div>
                                </div>
                            @elseif ($l['action'] === 'remove')
                                <p class="mt-3 flex items-center gap-2 text-sm text-ruby-soft"><x-icon name="info" class="size-4 shrink-0" />{{ __('adjust_admin.will_remove') }}</p>
                            @elseif ($l['action'] === 'replace')
                                <div class="mt-3 space-y-3">
                                    @if ($l['sub'])
                                        <div class="flex items-start gap-3 rounded-xl bg-white p-3 ring-1 ring-sand-200 dark:bg-ink-900 dark:ring-ink-700">
                                            <x-icon name="package" class="mt-0.5 size-5 shrink-0 text-brass-600" />
                                            <div class="min-w-0 flex-1">
                                                <p class="font-medium text-ink-900 dark:text-ivory">{{ $l['sub']['name'] }}@if ($l['sub']['variant_name']) <span class="font-normal text-ink-500">· {{ $l['sub']['variant_name'] }}</span>@endif</p>
                                                <p class="text-xs text-ink-500">{{ __('adjust_admin.replacement_price', ['price' => $l['sub']['price']]) }}@if ($l['sub']['capped']) <span class="text-amber-soft">{{ __('adjust_admin.price_capped', ['list' => $l['sub']['list_price']]) }}</span>@endif</p>
                                            </div>
                                            <button type="button" wire:click="openSearch('{{ $id }}')" class="btn-ghost btn-sm min-h-11 shrink-0">{{ __('adjust_admin.change') }}</button>
                                        </div>
                                        <div class="flex items-center justify-between gap-3">
                                            <span class="text-sm text-ink-600 dark:text-sand-200">{{ __('adjust_admin.replacement_quantity') }} <span class="text-xs text-ink-500">({{ __('adjust_admin.of_ordered', ['n' => $qty]) }})</span></span>
                                            <div class="inline-flex items-center rounded-xl border border-sand-200 dark:border-ink-700">
                                                <button type="button" wire:click="step('{{ $id }}', -1, {{ $qty }})" class="btn-ghost size-11 p-0" aria-label="{{ __('adjust_admin.fewer') }}" @disabled($l['qty'] <= 1)><x-icon name="minus" class="size-4" /></button>
                                                <span class="min-w-10 text-center font-semibold tabular-nums" aria-live="polite">{{ $l['qty'] }}</span>
                                                <button type="button" wire:click="step('{{ $id }}', 1, {{ $qty }})" class="btn-ghost size-11 p-0" aria-label="{{ __('adjust_admin.more') }}" @disabled($l['qty'] >= $qty)><x-icon name="plus" class="size-4" /></button>
                                            </div>
                                        </div>
                                    @elseif ($searching !== $id)
                                        <button type="button" wire:click="openSearch('{{ $id }}')" class="btn-secondary min-h-11 w-full justify-center"><x-icon name="search" class="size-4" />{{ __('adjust_admin.choose_replacement') }}</button>
                                    @endif

                                    @if ($searching === $id)
                                        <div class="rounded-xl border border-sand-200 bg-white p-3 dark:border-ink-700 dark:bg-ink-900" wire:key="search-{{ $id }}">
                                            <div class="flex items-center gap-2">
                                                <div class="relative flex-1">
                                                    <x-icon name="search" class="pointer-events-none absolute top-1/2 start-3 size-4 -translate-y-1/2 text-ink-400" />
                                                    <input type="search" wire:model.live.debounce.300ms="search" class="input ps-10" placeholder="{{ __('adjust_admin.search_ph') }}" aria-label="{{ __('adjust_admin.search_ph') }}" autocomplete="off" data-no-dirty>
                                                </div>
                                                <button type="button" wire:click="closeSearch" class="btn-ghost size-11 shrink-0 p-0" aria-label="{{ __('admin.close') }}"><x-icon name="x" class="size-4" /></button>
                                            </div>
                                            <ul class="mt-2 max-h-64 divide-y divide-sand-100 overflow-y-auto dark:divide-ink-800" wire:loading.class="opacity-60" wire:target="search">
                                                @forelse ($results as $r)
                                                    <li wire:key="res-{{ $id }}-{{ $r['variant_id'] }}">
                                                        <button type="button" wire:click="pick('{{ $id }}', '{{ $r['variant_id'] }}')" class="flex min-h-14 w-full items-center gap-3 px-1 py-2 text-start hover:bg-sand-50 dark:hover:bg-ink-800">
                                                            <span class="min-w-0 flex-1">
                                                                <span class="block truncate text-sm font-medium text-ink-900 dark:text-ivory">{{ $r['name'] }}@if ($r['variant_name']) <span class="font-normal text-ink-500">· {{ $r['variant_name'] }}</span>@endif</span>
                                                                <span class="block text-xs text-ink-500"><span class="font-mono" dir="ltr">{{ $r['sku'] }}</span> · {{ __('adjust_admin.in_stock', ['n' => $r['available']]) }}</span>
                                                            </span>
                                                            @php $capped = (float) $r['unit_price']['amount'] > (float) $it['unit_price']['amount']; @endphp
                                                            <span class="shrink-0 text-end tabular-nums">
                                                                <span @class(['block', 'text-sm font-medium' => ! $capped, 'text-xs text-ink-400 line-through' => $capped])>{{ $r['unit_price']['formatted'] }}</span>
                                                                @if ($capped)<span class="block text-sm font-semibold text-emerald-soft">{{ $it['unit_price']['formatted'] }}</span>@endif
                                                            </span>
                                                        </button>
                                                    </li>
                                                @empty
                                                    <li class="px-1 py-6 text-center text-sm text-ink-500">{{ __('adjust_admin.no_results') }}</li>
                                                @endforelse
                                            </ul>
                                        </div>
                                    @endif
                                </div>
                            @endif
                        </li>
                    @endforeach
                </ul>
                @error('lines')<p class="field-error" role="alert">{{ $message }}</p>@enderror

                <div class="space-y-4">
                    <x-admin.field :label="__('adjust_admin.reason')" for="adj-reason" required>
                        <select id="adj-reason" wire:model.live="reason" class="input pe-10 rtl:[background-position:left_0.5rem_center] sm:max-w-sm">
                            @foreach ($reasons as $rc)<option value="{{ $rc }}">{{ __('adjustments.reason.'.$rc) }}</option>@endforeach
                        </select>
                    </x-admin.field>
                    <x-admin.field :label="__('adjust_admin.note')" for="adj-note" :hint="__('adjust_admin.note_hint')">
                        <input id="adj-note" wire:model="note" maxlength="500" class="input" placeholder="{{ __('adjust_admin.note_ph') }}">
                    </x-admin.field>
                </div>
                @error('note')<p class="field-error" role="alert">{{ $message }}</p>@enderror

                @if ($problem)
                    <p class="flex items-start gap-2 rounded-xl bg-ruby-soft/10 p-3 text-sm text-ruby-soft" role="alert"><x-icon name="alert" class="mt-0.5 size-4 shrink-0" />{{ $problem }}</p>
                @endif

                @if ($preview)
                    <section class="rounded-2xl bg-sand-50 p-4 dark:bg-ink-800/60" aria-live="polite" aria-label="{{ __('adjust_admin.preview_title') }}">
                        <h3 class="mb-3 text-sm font-semibold text-ink-900 dark:text-ivory">{{ __('adjust_admin.preview_title') }}</h3>
                        <dl class="space-y-1.5 text-sm tabular-nums">
                            <div class="flex justify-between gap-4"><dt class="text-ink-500">{{ __('adjust_admin.total_now') }}</dt><dd>{{ $preview['before']['grand_total']['formatted'] }}</dd></div>
                            <div class="flex justify-between gap-4"><dt class="text-ink-500">{{ __('adjust_admin.difference') }}</dt><dd class="text-ruby-soft">{{ $preview['difference']['formatted'] }}</dd></div>
                            <div class="flex justify-between gap-4 border-t border-sand-200 pt-2 text-base font-semibold dark:border-ink-700"><dt>{{ __('adjust_admin.total_after') }}</dt><dd>{{ $preview['after']['grand_total']['formatted'] }}</dd></div>
                        </dl>
                        <ul class="mt-3 space-y-1.5 text-xs text-ink-600 dark:text-sand-200">
                            @if ($preview['order_cancelled'])
                                <li class="flex items-start gap-2 text-ruby-soft"><x-icon name="alert" class="mt-0.5 size-3.5 shrink-0" />{{ __('adjust_admin.cancels_order') }}</li>
                            @elseif ($preview['fulfillment_cancelled'])
                                <li class="flex items-start gap-2 text-amber-soft"><x-icon name="alert" class="mt-0.5 size-3.5 shrink-0" />{{ __('adjust_admin.cancels_part') }}</li>
                            @endif
                            @if ($preview['refund'])
                                <li class="flex items-start gap-2"><x-icon name="refund" class="mt-0.5 size-3.5 shrink-0 text-sky-soft" />{{ __('adjust_admin.refund_notice', ['amount' => $preview['refund']['amount']['formatted']]) }}</li>
                            @elseif ($order->payment_method !== 'sadad' && ! $preview['order_cancelled'])
                                <li class="flex items-start gap-2"><x-icon name="cash" class="mt-0.5 size-3.5 shrink-0 text-sky-soft" />{{ __('adjust_admin.cod_notice', ['amount' => $preview['after']['grand_total']['formatted']]) }}</li>
                            @endif
                            @if ($preview['loyalty_points_returned'] > 0)
                                <li class="flex items-start gap-2"><x-icon name="gift" class="mt-0.5 size-3.5 shrink-0 text-sky-soft" />{{ __('adjust_admin.points_returned', ['n' => $preview['loyalty_points_returned']]) }}</li>
                            @endif
                            <li class="flex items-start gap-2"><x-icon name="bell" class="mt-0.5 size-3.5 shrink-0 text-brass-600" />{{ __('adjust_admin.notify_hint') }}</li>
                        </ul>
                    </section>
                @endif

                <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                    <button type="button" class="btn-secondary min-h-11" @click="open = false">{{ __('admin.back') }}</button>
                    <button class="{{ $preview && ($preview['order_cancelled'] ?? false) ? 'btn-danger' : 'btn-primary' }} min-h-11" wire:loading.attr="disabled" wire:target="confirm" @disabled(! $ready)>
                        <x-icon name="check" class="size-4" />{{ __('adjust_admin.confirm') }}
                    </button>
                </div>
            </form>
        @endif
    </x-admin.dialog>
</div>
