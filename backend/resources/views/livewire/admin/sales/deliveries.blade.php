@php
    $tabs = [
        'to_dispatch' => ['package', __('sales_admin.lane_to_dispatch'), $counts['to_dispatch']],
        'scheduled' => ['calendar', __('sales_admin.lane_scheduled'), $counts['scheduled']],
        'on_road' => ['truck', __('sales_admin.lane_on_road'), $counts['on_road']],
        'exceptions' => ['alert', __('sales_admin.lane_exceptions'), $counts['exceptions']],
        'done' => ['check', __('sales_admin.lane_done')],
    ];
    $addrLine = fn ($a) => collect([isset($a['zone_number']) ? __('sales_admin.zone_n', ['n' => $a['zone_number']]) : null, isset($a['street_number']) ? __('sales_admin.street_n', ['n' => $a['street_number']]) : null, isset($a['building_number']) ? __('sales_admin.building_n', ['n' => $a['building_number']]) : null, $a['area'] ?? null])->filter()->implode(', ');
@endphp
<div>
    <x-admin.page-header :title="__('nav.deliveries')" :eyebrow="__('nav.sales')" :description="__('sales_admin.deliveries_hint')">
        <x-slot:actions>
            @if ($isDriver && Route::has('admin.driver'))<a href="{{ route('admin.driver') }}" wire:navigate class="btn-secondary"><x-icon name="phone" class="size-4" />{{ __('nav.driver') }}</a>@endif
        </x-slot:actions>
    </x-admin.page-header>

    <x-admin.tabs :tabs="$tabs" :current="$lane" model="lane" />

    <div class="card">
        <x-admin.toolbar>
            <x-admin.search :placeholder="__('sales_admin.deliveries_search')" />
            @if ($branches->count() > 1)
                <select wire:model.live="branch" class="input sm:w-52" aria-label="{{ __('admin.branch') }}">
                    <option value="">{{ __('admin.all_my_branches') }}</option>
                    @foreach ($branches as $b)<option value="{{ $b->id }}">{{ $b->code }} · {{ $b->localized('name') }}</option>@endforeach
                </select>
            @endif
            <x-slot:meta>{{ trans_choice('sales_admin.deliveries_count', $rows->total(), ['count' => $rows->total()]) }}</x-slot:meta>
        </x-admin.toolbar>

        @if ($rows->isEmpty())
            <x-admin.empty :title="__('sales_admin.lane_empty_'.$lane)" :description="__('sales_admin.lane_empty_hint')" icon="truck" />
        @else
            <ul class="divide-y divide-sand-100 dark:divide-ink-800">
                @foreach ($rows as $row)
                    @if ($lane === 'to_dispatch')
                        @php $o = $row->order; @endphp
                        <li class="flex flex-col gap-3 p-4 md:flex-row md:items-center sm:px-6" wire:key="fd-{{ $row->id }}">
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <a href="{{ route('admin.orders.show', $o) }}" wire:navigate class="font-mono font-medium hover:text-brass-600" dir="ltr">{{ $o->number }}</a>
                                    <x-admin.status :value="$row->status->value" />
                                    <span class="badge-neutral font-mono" dir="ltr">{{ $row->branch->code }}</span>
                                    @if ($o->payment_method === 'cod' && ! $o->payment_status->isSettled())<span class="badge-warning"><x-icon name="cash" class="size-3" />{{ __('sales_admin.cod_amount', ['amount' => \App\Support\Money::of($o->grand_total)->format()]) }}</span>@endif
                                </div>
                                <p class="mt-1 truncate text-sm text-ink-600 dark:text-sand-200">{{ $o->customer_name }} · <span class="text-ink-500">{{ $addrLine($o->address_snapshot ?? []) }}</span></p>
                                @if ($row->slot_date)<p class="text-xs text-ink-500 tabular-nums"><x-icon name="calendar" class="me-1 inline size-3.5" />{{ \App\Support\BusinessTime::display($row->slot_date, 'D d M') }}@if ($row->slot) · {{ substr($row->slot->starts_at, 0, 5) }}–{{ substr($row->slot->ends_at, 0, 5) }}@endif</p>@endif
                            </div>
                            @if ($row->status->value === 'ready' && $this->can('deliveries.create', $row->branch_id))
                                <button type="button" wire:click="open('create', '{{ $row->id }}')" class="btn-primary btn-sm self-start md:self-auto"><x-icon name="truck" class="size-4" />{{ __('sales_admin.act_arrange_delivery') }}</button>
                            @elseif ($row->status->value !== 'ready')
                                <span class="text-xs text-ink-500">{{ __('sales_admin.still_preparing') }}</span>
                            @endif
                        </li>
                    @else
                        @php
                            $d = $row; $o = $d->fulfillment->order; $st = $d->status->value;
                            $canAssign = $this->can('deliveries.assign', $d->branch_id); $canEdit = $this->can('deliveries.edit', $d->branch_id);
                        @endphp
                        <li class="flex flex-col gap-3 p-4 lg:flex-row lg:items-center sm:px-6" wire:key="d-{{ $d->id }}">
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="font-mono text-sm font-medium" dir="ltr">{{ $d->tracking_number }}</span>
                                    <x-admin.status :value="$st" />
                                    <a href="{{ route('admin.orders.show', $o) }}" wire:navigate class="font-mono text-xs text-ink-500 hover:text-brass-600" dir="ltr">{{ $o->number }}</a>
                                    <span class="badge-neutral font-mono" dir="ltr">{{ $d->branch->code }}</span>
                                    @if ((float) $d->cod_amount > 0 && ! $o->payment_status->isSettled())<span class="badge-warning"><x-icon name="cash" class="size-3" /><x-admin.money :value="$d->cod_amount" /></span>@endif
                                </div>
                                <p class="mt-1 truncate text-sm text-ink-600 dark:text-sand-200">{{ $o->customer_name }} · <span class="text-ink-500">{{ $addrLine($o->address_snapshot ?? []) }}</span></p>
                                <p class="mt-0.5 flex flex-wrap items-center gap-x-3 text-xs text-ink-500">
                                    <span><x-icon :name="$d->mode === 'provider' ? 'building' : 'user'" class="me-1 inline size-3.5" />{{ $d->mode === 'provider' ? ($d->provider?->name ?? __('sales_admin.provider')) : ($d->driver?->name ?? __('sales_admin.unassigned')) }}</span>
                                    @if ($d->scheduled_for)<span class="tabular-nums"><x-icon name="clock" class="me-1 inline size-3.5" />{{ \App\Support\BusinessTime::display($d->scheduled_for) }}</span>@endif
                                    @if ($d->attempt_count)<span>{{ __('sales_admin.attempts_n', ['n' => $d->attempt_count]) }}</span>@endif
                                    @if ($d->failure_reason && in_array($st, ['failed', 'rescheduled', 'returning'], true))<span class="text-ruby-soft">{{ __('sales_admin.fail_'.$d->failure_reason) }}</span>@endif
                                    @if ($d->last_error)<span class="text-ruby-soft" title="{{ $d->last_error }}"><x-icon name="alert" class="me-1 inline size-3.5" />{{ __('sales_admin.booking_error') }}</span>@endif
                                </p>
                            </div>
                            @if ($lane !== 'done')
                                <div class="flex flex-wrap items-center gap-2">
                                    @if ($canAssign && in_array($st, ['pending', 'assigned', 'accepted', 'rescheduled'], true) && $d->mode === 'internal')
                                        <button type="button" wire:click="open('assign', '{{ $d->id }}')" class="btn-secondary btn-sm"><x-icon name="user" class="size-4" />{{ $d->driver_id ? __('sales_admin.reassign') : __('sales_admin.assign_driver') }}</button>
                                    @endif
                                    @if ($canEdit && $d->mode === 'provider' && $d->last_error && $st === 'pending')
                                        <button type="button" wire:click="act('{{ $d->id }}', 'retry')" class="btn-secondary btn-sm"><x-icon name="refresh" class="size-4" />{{ __('sales_admin.retry_booking') }}</button>
                                    @endif
                                    @if ($canAssign && in_array($st, ['pending', 'assigned', 'accepted'], true))
                                        <button type="button" wire:click="act('{{ $d->id }}', 'pickup')" wire:confirm="{{ __('sales_admin.confirm_pickup') }}" class="btn-secondary btn-sm"><x-icon name="package" class="size-4" />{{ __('sales_admin.act_picked_up') }}</button>
                                    @endif
                                    @if ($canAssign && in_array($st, ['picked_up', 'rescheduled', 'failed'], true))
                                        <button type="button" wire:click="act('{{ $d->id }}', 'out')" class="btn-secondary btn-sm"><x-icon name="truck" class="size-4" />{{ __('sales_admin.act_out') }}</button>
                                    @endif
                                    @if ($canAssign && in_array($st, ['failed', 'rescheduled'], true))
                                        <button type="button" wire:click="open('reschedule', '{{ $d->id }}')" class="btn-secondary btn-sm"><x-icon name="calendar" class="size-4" />{{ __('sales_admin.reschedule') }}</button>
                                    @endif
                                    @if ($canAssign && in_array($st, ['picked_up', 'out_for_delivery'], true))
                                        <button type="button" wire:click="open('fail', '{{ $d->id }}')" class="btn-ghost btn-sm text-ruby-soft"><x-icon name="alert" class="size-4" />{{ __('sales_admin.mark_failed') }}</button>
                                    @endif
                                    @if ($canEdit && in_array($st, ['failed', 'rescheduled', 'returning'], true))
                                        <button type="button" wire:click="act('{{ $d->id }}', 'return')" wire:confirm="{{ __('sales_admin.confirm_return') }}" class="btn-secondary btn-sm"><x-icon name="refund" class="size-4" />{{ __('sales_admin.return_to_branch') }}</button>
                                    @endif
                                    @if ($canEdit && in_array($st, ['pending', 'assigned', 'accepted'], true))
                                        <button type="button" wire:click="open('cancel', '{{ $d->id }}')" class="btn-ghost btn-sm text-ruby-soft" aria-label="{{ __('sales_admin.cancel_delivery') }}" title="{{ __('sales_admin.cancel_delivery') }}"><x-icon name="x" class="size-4" /></button>
                                    @endif
                                </div>
                            @endif
                        </li>
                    @endif
                @endforeach
            </ul>
            @if ($rows->hasPages())<div class="border-t border-sand-100 p-4 dark:border-ink-800">{{ $rows->links('components.admin.pagination') }}</div>@endif
        @endif
    </div>

    <x-admin.dialog wire:model="showDialog" :title="match ($dialog) { 'create' => __('sales_admin.act_arrange_delivery'), 'assign' => __('sales_admin.assign_driver'), 'reschedule' => __('sales_admin.reschedule'), 'fail' => __('sales_admin.mark_failed'), 'cancel' => __('sales_admin.cancel_delivery'), default => '' }">
        <form wire:submit="confirmDialog" class="space-y-5">
            @if ($dialog === 'create')
                <div class="grid grid-cols-2 gap-2">
                    @foreach (['internal' => ['user', __('sales_admin.mode_internal')], 'provider' => ['building', __('sales_admin.mode_provider')]] as $m => [$icon, $label])
                        <label class="flex cursor-pointer items-center gap-2 rounded-xl border px-3 py-2.5 text-sm transition {{ ($form['mode'] ?? '') === $m ? 'border-brass-500 bg-brass-50 dark:bg-brass-500/10' : 'border-sand-200 dark:border-ink-700' }}">
                            <input type="radio" wire:model.live="form.mode" value="{{ $m }}" class="sr-only"><x-icon :name="$icon" class="size-4 text-brass-600" />{{ $label }}
                        </label>
                    @endforeach
                </div>
                @if (($form['mode'] ?? '') === 'provider')
                    <x-admin.field :label="__('sales_admin.provider')" for="form.provider_id" required>
                        <select id="form.provider_id" wire:model="form.provider_id" class="input"><option value="">—</option>@foreach ($providers as $pv)<option value="{{ $pv->id }}">{{ $pv->name }}</option>@endforeach</select>
                    </x-admin.field>
                    @if ($providers->isEmpty())<p class="text-xs text-ink-500">{{ __('sales_admin.no_providers') }}</p>@endif
                @endif
            @endif
            @if ($dialog === 'assign' || ($dialog === 'create' && ($form['mode'] ?? '') === 'internal'))
                <x-admin.field :label="__('sales_admin.driver')" for="form.driver_id" :required="$dialog === 'assign'">
                    <select id="form.driver_id" wire:model="form.driver_id" class="input"><option value="">{{ $dialog === 'create' ? __('sales_admin.assign_later') : '—' }}</option>@foreach ($drivers as $dr)<option value="{{ $dr->id }}">{{ $dr->name }}</option>@endforeach</select>
                </x-admin.field>
                @if ($drivers->isEmpty())<p class="text-xs text-ink-500">{{ __('sales_admin.no_drivers') }}</p>@endif
            @endif
            @if ($dialog === 'reschedule' && $slotOptions !== null)
                <x-admin.field :label="__('sales_admin.slot_date')" for="form.slot_date" required><input id="form.slot_date" type="date" wire:model.live="form.slot_date" min="{{ \App\Support\BusinessTime::now()->toDateString() }}" class="input"></x-admin.field>
                <div>
                    <div class="mb-1.5 text-sm font-medium">{{ __('sales_admin.slot') }}</div>
                    @if ($slotOptions->isEmpty())
                        <p class="rounded-xl border border-dashed border-sand-300 p-3 text-sm text-ink-500 dark:border-ink-700">{{ __('sales_admin.no_slots_for_date') }}</p>
                    @else
                        <div class="grid grid-cols-2 gap-2">
                            @foreach ($slotOptions as $sl)
                                <label class="flex cursor-pointer flex-col rounded-xl border px-3 py-2.5 text-sm transition {{ ($form['slot_id'] ?? '') === $sl['id'] ? 'border-brass-500 bg-brass-50 dark:bg-brass-500/10' : 'border-sand-200 dark:border-ink-700' }}">
                                    <input type="radio" wire:model.live="form.slot_id" value="{{ $sl['id'] }}" class="sr-only">
                                    <span class="font-medium tabular-nums" dir="ltr">{{ $sl['starts_at'] }} – {{ $sl['ends_at'] }}</span>
                                    <span class="text-xs text-ink-500">{{ __('sales_admin.slot_left', ['n' => $sl['available']]) }}</span>
                                </label>
                            @endforeach
                        </div>
                    @endif
                    @error('form.slot_id')<p class="mt-1 text-xs text-ruby-soft">{{ $message }}</p>@enderror
                </div>
            @elseif (in_array($dialog, ['create', 'reschedule'], true))
                <x-admin.field :label="__('sales_admin.scheduled_for')" for="form.scheduled_for" :required="$dialog === 'reschedule'"><input id="form.scheduled_for" type="datetime-local" wire:model="form.scheduled_for" class="input"></x-admin.field>
            @endif
            @if ($dialog === 'fail')
                <x-admin.field :label="__('sales_admin.fail_reason')" for="form.reason_code" required>
                    <select id="form.reason_code" wire:model="form.reason_code" class="input">@foreach (\App\Livewire\Admin\Sales\DeliveryIndex::FAIL_REASONS as $rc)<option value="{{ $rc }}">{{ __('sales_admin.fail_'.$rc) }}</option>@endforeach</select>
                </x-admin.field>
                <x-admin.field :label="__('admin.notes')" for="form.notes"><textarea id="form.notes" wire:model="form.notes" rows="2" class="input"></textarea></x-admin.field>
            @endif
            @if ($dialog === 'cancel')
                <x-admin.field :label="__('admin.reason')" for="form.reason" required><textarea id="form.reason" wire:model="form.reason" rows="2" class="input"></textarea></x-admin.field>
            @endif
            <div class="flex flex-col-reverse gap-2 border-t border-sand-100 pt-5 sm:flex-row sm:justify-end dark:border-ink-800">
                <button type="button" class="btn-secondary" @click="open = false">{{ __('admin.back') }}</button>
                <button class="{{ in_array($dialog, ['fail', 'cancel'], true) ? 'btn-danger' : 'btn-primary' }}" wire:loading.attr="disabled" wire:target="confirmDialog">{{ __('admin.confirm') }}</button>
            </div>
        </form>
    </x-admin.dialog>
</div>
