@php $isNew = ! $branch; @endphp
<div class="mx-auto max-w-6xl">
    <a href="{{ route('admin.branches.index') }}" wire:navigate class="mb-4 inline-flex items-center gap-1.5 text-sm text-ink-500 hover:text-brass-600"><x-icon name="chevron-left" class="size-4 rtl-flip" />{{ __('nav.branches') }}</a>
    <x-admin.page-header :title="$isNew ? __('branches.new') : $branch->localized('name')" :eyebrow="$isNew ? __('nav.organization') : $branch->code">
        <x-slot:actions>
            @unless ($isNew)
                @if ($status['is_open'])<span class="badge-success">{{ __('branches.open_now') }}</span>@else<span class="badge-neutral">{{ __('branches.closed_reason.'.$status['reason']) }}</span>@endif
                <x-admin.status :value="$branch->status" />
            @endunless
        </x-slot:actions>
    </x-admin.page-header>

    @unless ($isNew)
        <nav class="mb-6 flex gap-1 overflow-x-auto rounded-2xl border border-sand-200/70 bg-white p-1 dark:border-ink-700 dark:bg-ink-900" role="tablist">
            @foreach (['details' => ['store', __('branches.tab_details')], 'hours' => ['clock', __('branches.tab_hours')], 'holidays' => ['calendar', __('branches.tab_holidays')], 'zones' => ['map', __('branches.tab_zones')], 'slots' => ['clipboard', __('branches.tab_slots')], 'team' => ['users', __('branches.tab_team')]] as $key => [$icon, $label])
                <button type="button" wire:click="$set('tab', '{{ $key }}')" role="tab" aria-selected="{{ $tab === $key ? 'true' : 'false' }}"
                        class="flex shrink-0 items-center gap-2 rounded-xl px-4 py-2 text-sm font-medium transition {{ $tab === $key ? 'bg-ink-900 text-ivory shadow dark:bg-brass-500 dark:text-ink-950' : 'text-ink-600 hover:bg-sand-50 dark:text-ink-300 dark:hover:bg-ink-800' }}">
                    <x-icon :name="$icon" class="size-4" />{{ $label }}
                </button>
            @endforeach
        </nav>
    @endunless

    @if ($isNew || $tab === 'details')
        <form wire:submit="save" class="grid gap-6 lg:grid-cols-3">
            <div class="card card-pad space-y-5 lg:col-span-2">
                <h2 class="font-display text-xl font-semibold">{{ __('branches.identity') }}</h2>
                <div class="grid gap-5 sm:grid-cols-3">
                    <x-admin.field :label="__('branches.code')" for="form.code" required :hint="__('branches.code_hint')">
                        <input id="form.code" wire:model="form.code" class="input font-mono uppercase" dir="ltr" maxlength="24" @disabled(! $isNew && ! ($canGlobal ?? false))>
                    </x-admin.field>
                    <x-admin.field :label="__('branches.name_en')" for="form.name_en" required class="sm:col-span-2"><input id="form.name_en" wire:model="form.name_en" class="input" dir="ltr"></x-admin.field>
                </div>
                <x-admin.field :label="__('branches.name_ar')" for="form.name_ar" required><input id="form.name_ar" wire:model="form.name_ar" class="input" dir="rtl" lang="ar"></x-admin.field>
                <div class="grid gap-5 sm:grid-cols-2">
                    <x-admin.field :label="__('branches.description_en')" for="form.description_en"><textarea id="form.description_en" wire:model="form.description_en" rows="3" class="input" dir="ltr"></textarea></x-admin.field>
                    <x-admin.field :label="__('branches.description_ar')" for="form.description_ar"><textarea id="form.description_ar" wire:model="form.description_ar" rows="3" class="input" dir="rtl" lang="ar"></textarea></x-admin.field>
                </div>

                <h2 class="pt-2 font-display text-xl font-semibold">{{ __('branches.contact_location') }}</h2>
                <div class="grid gap-5 sm:grid-cols-2">
                    <x-admin.field :label="__('branches.phone')" for="form.phone"><input id="form.phone" wire:model="form.phone" class="input" dir="ltr" placeholder="+974 4444 0000"></x-admin.field>
                    <x-admin.field :label="__('admin.email')" for="form.email"><input id="form.email" type="email" wire:model="form.email" class="input" dir="ltr"></x-admin.field>
                    <x-admin.field :label="__('branches.address_en')" for="form.address_en"><input id="form.address_en" wire:model="form.address_en" class="input" dir="ltr"></x-admin.field>
                    <x-admin.field :label="__('branches.address_ar')" for="form.address_ar"><input id="form.address_ar" wire:model="form.address_ar" class="input" dir="rtl"></x-admin.field>
                    <x-admin.field :label="__('branches.area')" for="form.area"><input id="form.area" wire:model="form.area" class="input"></x-admin.field>
                    <x-admin.field :label="__('branches.zone_number')" for="form.zone_number"><input id="form.zone_number" wire:model="form.zone_number" class="input" dir="ltr"></x-admin.field>
                    <x-admin.field :label="__('branches.latitude')" for="form.latitude" :hint="__('branches.coords_hint')"><input id="form.latitude" wire:model="form.latitude" inputmode="decimal" class="input font-mono" dir="ltr" placeholder="25.2854"></x-admin.field>
                    <x-admin.field :label="__('branches.longitude')" for="form.longitude"><input id="form.longitude" wire:model="form.longitude" inputmode="decimal" class="input font-mono" dir="ltr" placeholder="51.5310"></x-admin.field>
                </div>
            </div>

            <div class="space-y-6">
                <div class="card card-pad space-y-4">
                    <h2 class="font-display text-xl font-semibold">{{ __('branches.services') }}</h2>
                    @foreach (['pickup_enabled' => 'branches.pickup_enabled', 'delivery_enabled' => 'branches.delivery_enabled', 'cod_enabled' => 'branches.cod_enabled', 'pay_at_pickup_enabled' => 'branches.pay_at_pickup_enabled', 'is_fulfillment_hub' => 'branches.is_hub'] as $field => $label)
                        <label class="flex items-center justify-between gap-4 rounded-xl border border-sand-100 px-4 py-3 dark:border-ink-800">
                            <span class="text-sm">{{ __($label) }}</span>
                            <input type="checkbox" wire:model="form.{{ $field }}" class="checkbox">
                        </label>
                    @endforeach
                    <x-admin.field :label="__('branches.tenders')">
                        <div class="flex gap-4 text-sm">
                            <label class="flex items-center gap-2"><input type="checkbox" value="cash" wire:model="form.pay_at_pickup_tenders" class="checkbox">{{ __('branches.cash') }}</label>
                            <label class="flex items-center gap-2"><input type="checkbox" value="card_terminal" wire:model="form.pay_at_pickup_tenders" class="checkbox">{{ __('branches.card_terminal') }}</label>
                        </div>
                    </x-admin.field>
                    <div class="grid grid-cols-2 gap-3">
                        <x-admin.field :label="__('branches.cod_min')" for="form.cod_min_order"><input id="form.cod_min_order" wire:model="form.cod_min_order" inputmode="decimal" class="input" dir="ltr"></x-admin.field>
                        <x-admin.field :label="__('branches.cod_max')" for="form.cod_max_order"><input id="form.cod_max_order" wire:model="form.cod_max_order" inputmode="decimal" class="input" dir="ltr"></x-admin.field>
                    </div>
                    <x-admin.field :label="__('branches.prep_minutes')" for="form.pickup_preparation_minutes"><input id="form.pickup_preparation_minutes" type="number" min="0" wire:model="form.pickup_preparation_minutes" class="input"></x-admin.field>
                </div>
                <div class="card card-pad space-y-4">
                    <h2 class="font-display text-xl font-semibold">{{ __('admin.status') }}</h2>
                    <x-admin.field :label="__('branches.opening_status')" for="form.opening_status">
                        <select id="form.opening_status" wire:model="form.opening_status" class="input"><option value="open">{{ __('status.open') }}</option><option value="temporarily_closed">{{ __('status.temporarily_closed') }}</option></select>
                    </x-admin.field>
                    @if ($isNew || ($canGlobal ?? false))
                        <x-admin.field :label="__('branches.lifecycle')" for="form.status">
                            <select id="form.status" wire:model="form.status" class="input"><option value="active">{{ __('status.active') }}</option><option value="inactive">{{ __('status.inactive') }}</option></select>
                        </x-admin.field>
                    @endif
                    <x-admin.field :label="__('branches.sort_order')" for="form.sort_order"><input id="form.sort_order" type="number" min="0" wire:model="form.sort_order" class="input"></x-admin.field>
                </div>
                @if ($isNew || $canEdit)
                    <button class="btn-primary w-full py-3" wire:loading.attr="disabled"><x-icon name="check" class="size-4" />{{ $isNew ? __('branches.create') : __('admin.save_changes') }}</button>
                @endif
            </div>
        </form>
    @elseif ($tab === 'hours')
        <form wire:submit="saveHours" class="card overflow-hidden">
            <div class="flex items-center justify-between p-5 sm:p-6">
                <div><h2 class="font-display text-xl font-semibold">{{ __('branches.weekly_hours') }}</h2><p class="mt-1 text-sm text-ink-500">{{ __('branches.hours_hint') }}</p></div>
                <span class="badge-neutral"><x-icon name="globe" class="size-3" />Asia/Qatar</span>
            </div>
            <div class="table-wrap"><table class="table">
                <thead><tr><th>{{ __('branches.day') }}</th><th>{{ __('branches.closed') }}</th><th>{{ __('branches.opens') }}</th><th>{{ __('branches.closes') }}</th><th>{{ __('branches.break') }}</th><th></th></tr></thead>
                <tbody>
                @foreach ($days as $d => $label)
                    <tr wire:key="day-{{ $d }}">
                        <td class="font-medium">{{ $label }}</td>
                        <td><input type="checkbox" wire:model.live="week.{{ $d }}.is_closed" class="checkbox" aria-label="{{ __('branches.closed') }}"></td>
                        <td><input type="time" wire:model="week.{{ $d }}.opens_at" class="input w-32" @disabled($week[$d]['is_closed'] ?? false)></td>
                        <td><input type="time" wire:model="week.{{ $d }}.closes_at" class="input w-32" @disabled($week[$d]['is_closed'] ?? false)></td>
                        <td><div class="flex items-center gap-2"><input type="time" wire:model="week.{{ $d }}.break_starts_at" class="input w-28" @disabled($week[$d]['is_closed'] ?? false)><span class="text-ink-400">–</span><input type="time" wire:model="week.{{ $d }}.break_ends_at" class="input w-28" @disabled($week[$d]['is_closed'] ?? false)></div></td>
                        <td><button type="button" wire:click="copyHoursToAll({{ $d }})" class="btn-ghost btn-sm" title="{{ __('branches.copy_to_all') }}"><x-icon name="copy" class="size-4" /></button></td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
            @if ($canEdit)<div class="flex justify-end border-t border-sand-100 p-4 dark:border-ink-800"><button class="btn-primary">{{ __('branches.save_hours') }}</button></div>@endif
        </form>
    @elseif ($tab === 'holidays')
        <div class="grid gap-6 lg:grid-cols-3">
            <div class="card lg:col-span-2">
                <div class="p-5 sm:p-6"><h2 class="font-display text-xl font-semibold">{{ __('branches.holidays_closures') }}</h2></div>
                @forelse ($branch->holidays as $h)
                    <div class="flex items-center gap-4 border-t border-sand-100 px-6 py-4 dark:border-ink-800" wire:key="h-{{ $h->id }}">
                        <span class="grid size-10 place-items-center rounded-xl {{ $h->type === 'holiday' ? 'bg-brass-50 text-brass-600' : 'bg-ruby-soft/10 text-ruby-soft' }}"><x-icon :name="$h->type === 'holiday' ? 'calendar' : 'ban'" class="size-4" /></span>
                        <div class="min-w-0 flex-1"><div class="font-medium">{{ $h->localized('name') }}</div>
                            <div class="text-xs text-ink-500">{{ \App\Support\BusinessTime::display($h->starts_at) }} → {{ \App\Support\BusinessTime::display($h->ends_at) }}</div></div>
                        <span class="badge-neutral">{{ __('branches.type_'.$h->type) }}</span>
                        @if ($canEdit)<button wire:click="removeHoliday('{{ $h->id }}')" wire:confirm="{{ __('admin.confirm_delete') }}" class="btn-ghost btn-sm text-ruby-soft" aria-label="{{ __('admin.delete') }}"><x-icon name="trash" class="size-4" /></button>@endif
                    </div>
                @empty
                    <x-admin.empty :title="__('branches.no_holidays')" icon="calendar" />
                @endforelse
            </div>
            @if ($canEdit)
                <form wire:submit="addHoliday" class="card card-pad space-y-4">
                    <h3 class="font-display text-lg font-semibold">{{ __('branches.add_closure') }}</h3>
                    <x-admin.field :label="__('branches.type')" for="holiday.type"><select id="holiday.type" wire:model="holiday.type" class="input"><option value="holiday">{{ __('branches.type_holiday') }}</option><option value="temporary_closure">{{ __('branches.type_temporary_closure') }}</option></select></x-admin.field>
                    <x-admin.field :label="__('branches.name_en')" for="holiday.name_en"><input id="holiday.name_en" wire:model="holiday.name_en" class="input" placeholder="National Day"></x-admin.field>
                    <x-admin.field :label="__('branches.name_ar')" for="holiday.name_ar"><input id="holiday.name_ar" wire:model="holiday.name_ar" class="input" dir="rtl" placeholder="اليوم الوطني"></x-admin.field>
                    <x-admin.field :label="__('branches.starts')" for="holiday.starts_at"><input id="holiday.starts_at" type="datetime-local" wire:model="holiday.starts_at" class="input"></x-admin.field>
                    <x-admin.field :label="__('branches.ends')" for="holiday.ends_at"><input id="holiday.ends_at" type="datetime-local" wire:model="holiday.ends_at" class="input"></x-admin.field>
                    <button class="btn-primary w-full">{{ __('branches.add_closure') }}</button>
                </form>
            @endif
        </div>
    @elseif ($tab === 'zones')
        <div class="grid gap-6 lg:grid-cols-3">
            <div class="card overflow-hidden lg:col-span-2">
                <div class="p-5 sm:p-6"><h2 class="font-display text-xl font-semibold">{{ __('branches.served_zones') }}</h2><p class="mt-1 text-sm text-ink-500">{{ __('branches.zones_hint') }}</p></div>
                @if ($branch->zoneConfigs->isEmpty())
                    <x-admin.empty :title="__('branches.no_zones')" icon="map" />
                @else
                    <div class="table-wrap"><table class="table">
                        <thead><tr><th>{{ __('nav.zones') }}</th><th class="!text-end">{{ __('branches.fee') }}</th><th class="!text-end">{{ __('branches.free_above') }}</th><th>{{ __('branches.limits') }}</th><th>COD</th><th>{{ __('branches.eta') }}</th><th></th></tr></thead>
                        <tbody>@foreach ($branch->zoneConfigs->sortBy('priority') as $z)
                            <tr wire:key="z-{{ $z->id }}" class="{{ $z->is_active ? '' : 'opacity-50' }}">
                                <td class="font-medium">{{ $z->zone->localized('name') }}</td>
                                <td class="text-end tabular-nums">{{ \App\Support\Money::of($z->delivery_fee)->format() }}</td>
                                <td class="text-end tabular-nums">{{ $z->free_delivery_threshold ? \App\Support\Money::of($z->free_delivery_threshold)->format() : '—' }}</td>
                                <td class="text-xs text-ink-500">{{ $z->min_order_amount ?? '0' }} – {{ $z->max_order_amount ?? '∞' }}</td>
                                <td>{!! $z->cod_enabled ? '<span class="badge-success">✓</span>' : '<span class="badge-neutral">—</span>' !!}</td>
                                <td class="text-ink-500">{{ $z->estimated_minutes ? __('branches.minutes', ['n' => $z->estimated_minutes]) : '—' }}</td>
                                <td>@if ($canEdit)<button wire:click="toggleZone('{{ $z->id }}')" class="btn-ghost btn-sm">{{ $z->is_active ? __('admin.disable') : __('admin.enable') }}</button>@endif</td>
                            </tr>
                        @endforeach</tbody>
                    </table></div>
                @endif
            </div>
            @if ($canEdit)
                <form wire:submit="saveZone" class="card card-pad space-y-4">
                    <h3 class="font-display text-lg font-semibold">{{ __('branches.configure_zone') }}</h3>
                    <x-admin.field :label="__('nav.zones')" for="zone.delivery_zone_id"><select id="zone.delivery_zone_id" wire:model="zone.delivery_zone_id" class="input"><option value="">—</option>@foreach ($zones as $z)<option value="{{ $z->id }}">{{ $z->localized('name') }}</option>@endforeach</select></x-admin.field>
                    <div class="grid grid-cols-2 gap-3">
                        <x-admin.field :label="__('branches.fee')" for="zone.delivery_fee"><input id="zone.delivery_fee" wire:model="zone.delivery_fee" class="input" dir="ltr"></x-admin.field>
                        <x-admin.field :label="__('branches.free_above')" for="zone.free_delivery_threshold"><input id="zone.free_delivery_threshold" wire:model="zone.free_delivery_threshold" class="input" dir="ltr"></x-admin.field>
                        <x-admin.field :label="__('branches.min_order')" for="zone.min_order_amount"><input id="zone.min_order_amount" wire:model="zone.min_order_amount" class="input" dir="ltr"></x-admin.field>
                        <x-admin.field :label="__('branches.max_order')" for="zone.max_order_amount"><input id="zone.max_order_amount" wire:model="zone.max_order_amount" class="input" dir="ltr"></x-admin.field>
                        <x-admin.field :label="__('branches.eta_minutes')" for="zone.estimated_minutes"><input id="zone.estimated_minutes" type="number" wire:model="zone.estimated_minutes" class="input"></x-admin.field>
                        <x-admin.field :label="__('branches.priority')" for="zone.priority" :hint="__('branches.priority_hint')"><input id="zone.priority" type="number" wire:model="zone.priority" class="input"></x-admin.field>
                    </div>
                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="zone.cod_enabled" class="checkbox">{{ __('branches.cod_enabled') }}</label>
                    <button class="btn-primary w-full">{{ __('admin.save') }}</button>
                </form>
            @endif
        </div>
    @elseif ($tab === 'slots')
        <div class="grid gap-6 lg:grid-cols-3">
            <div class="card overflow-hidden lg:col-span-2">
                <div class="p-5 sm:p-6"><h2 class="font-display text-xl font-semibold">{{ __('branches.slots') }}</h2><p class="mt-1 text-sm text-ink-500">{{ __('branches.slots_hint') }}</p></div>
                @if ($branch->slots->isEmpty())
                    <x-admin.empty :title="__('branches.no_slots')" icon="clock" />
                @else
                    <div class="table-wrap"><table class="table"><thead><tr><th>{{ __('branches.type') }}</th><th>{{ __('branches.day') }}</th><th>{{ __('branches.window') }}</th><th class="!text-end">{{ __('branches.capacity') }}</th><th></th></tr></thead>
                        <tbody>@foreach ($branch->slots as $s)
                            <tr wire:key="s-{{ $s->id }}"><td><span class="{{ $s->fulfillment_type === 'pickup' ? 'badge-brass' : 'badge-info' }}">{{ __('branches.'.$s->fulfillment_type) }}</span></td>
                                <td>{{ $days[$s->day_of_week] }}</td><td class="font-mono text-xs" dir="ltr">{{ substr($s->starts_at, 0, 5) }}–{{ substr($s->ends_at, 0, 5) }}</td>
                                <td class="text-end tabular-nums">{{ $s->capacity }}</td>
                                <td>@if ($canEdit)<button wire:click="removeSlot('{{ $s->id }}')" wire:confirm="{{ __('admin.confirm_delete') }}" class="btn-ghost btn-sm text-ruby-soft"><x-icon name="trash" class="size-4" /></button>@endif</td></tr>
                        @endforeach</tbody></table></div>
                @endif
            </div>
            @if ($canEdit)
                <form wire:submit="addSlot" class="card card-pad space-y-4">
                    <h3 class="font-display text-lg font-semibold">{{ __('branches.add_slot') }}</h3>
                    <x-admin.field :label="__('branches.type')" for="slot.fulfillment_type"><select id="slot.fulfillment_type" wire:model="slot.fulfillment_type" class="input"><option value="pickup">{{ __('branches.pickup') }}</option><option value="delivery">{{ __('branches.delivery') }}</option></select></x-admin.field>
                    <x-admin.field :label="__('branches.day')" for="slot.day_of_week"><select id="slot.day_of_week" wire:model="slot.day_of_week" class="input">@foreach ($days as $d => $l)<option value="{{ $d }}">{{ $l }}</option>@endforeach</select></x-admin.field>
                    <div class="grid grid-cols-2 gap-3">
                        <x-admin.field :label="__('branches.opens')" for="slot.starts_at"><input id="slot.starts_at" type="time" wire:model="slot.starts_at" class="input"></x-admin.field>
                        <x-admin.field :label="__('branches.closes')" for="slot.ends_at"><input id="slot.ends_at" type="time" wire:model="slot.ends_at" class="input"></x-admin.field>
                    </div>
                    <x-admin.field :label="__('branches.capacity')" for="slot.capacity"><input id="slot.capacity" type="number" min="1" wire:model="slot.capacity" class="input"></x-admin.field>
                    <button class="btn-primary w-full">{{ __('branches.add_slot') }}</button>
                </form>
            @endif
        </div>
    @elseif ($tab === 'team')
        <div class="grid gap-6 lg:grid-cols-3">
            <div class="card overflow-hidden lg:col-span-2">
                <div class="p-5 sm:p-6"><h2 class="font-display text-xl font-semibold">{{ __('branches.team') }}</h2></div>
                @if ($branch->employeeAssignments->isEmpty())
                    <x-admin.empty :title="__('branches.no_team')" icon="users" />
                @else
                    <ul class="divide-y divide-sand-100 border-t border-sand-100 dark:divide-ink-800 dark:border-ink-800">
                        @foreach ($branch->employeeAssignments as $a)
                            <li class="flex items-center gap-4 px-6 py-4 {{ $a->is_active ? '' : 'opacity-50' }}" wire:key="e-{{ $a->id }}">
                                <span class="grid size-10 place-items-center rounded-full bg-ink-900 text-sm text-ivory dark:bg-ink-700">{{ mb_substr($a->user->name, 0, 1) }}</span>
                                <div class="min-w-0 flex-1"><div class="truncate font-medium">{{ $a->user->name }} @if ($a->is_delivery_driver)<span class="badge-info ms-1"><x-icon name="truck" class="size-3" />{{ __('branches.driver') }}</span>@endif</div>
                                    <div class="truncate text-xs text-ink-500">{{ $a->job_title ?: $a->user->email }}</div></div>
                                <div class="hidden flex-wrap gap-1 sm:flex">@foreach ($a->user->roleAssignments as $ra)<span class="badge-neutral">{{ $ra->role->localized('name') }}</span>@endforeach</div>
                                @if ($this->can('employees.assign', $branch->id) && $a->user_id !== auth()->id())
                                    <button wire:click="toggleEmployee('{{ $a->id }}')" class="btn-ghost btn-sm">{{ $a->is_active ? __('admin.disable') : __('admin.enable') }}</button>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
            @if ($this->can('employees.assign', $branch->id))
                <form wire:submit="assignEmployee" class="card card-pad space-y-4">
                    <h3 class="font-display text-lg font-semibold">{{ __('branches.assign_employee') }}</h3>
                    <x-admin.field :label="__('nav.employees')" for="assign.user_id"><select id="assign.user_id" wire:model="assign.user_id" class="input"><option value="">—</option>@foreach ($staff as $s)<option value="{{ $s->id }}">{{ $s->name }} · {{ $s->email }}</option>@endforeach</select></x-admin.field>
                    <x-admin.field :label="__('admin.roles')" error="assign.roles">
                        <div class="max-h-56 space-y-1 overflow-y-auto rounded-xl border border-sand-200 p-2 dark:border-ink-700">
                            @foreach ($roles as $r)<label class="flex items-center gap-2 rounded-lg px-2 py-1.5 text-sm hover:bg-sand-50 dark:hover:bg-ink-800"><input type="checkbox" value="{{ $r->key }}" wire:model="assign.roles" class="checkbox">{{ $r->localized('name') }}</label>@endforeach
                        </div>
                    </x-admin.field>
                    <x-admin.field :label="__('branches.job_title')" for="assign.job_title"><input id="assign.job_title" wire:model="assign.job_title" class="input"></x-admin.field>
                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="assign.is_delivery_driver" class="checkbox">{{ __('branches.is_driver') }}</label>
                    <button class="btn-primary w-full">{{ __('branches.assign') }}</button>
                    <p class="text-xs text-ink-500">{{ __('branches.assign_hint') }}</p>
                </form>
            @endif
        </div>
    @endif
</div>
