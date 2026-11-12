@php
    $delayLabel = fn (int $m) => $m >= 1440 && $m % 1440 === 0 ? trans_choice('journeys_admin.n_days', intdiv($m, 1440), ['n' => intdiv($m, 1440)])
        : ($m >= 60 && $m % 60 === 0 ? trans_choice('journeys_admin.n_hours', intdiv($m, 60), ['n' => intdiv($m, 60)]) : trans_choice('journeys_admin.n_minutes', $m, ['n' => $m]));
@endphp
<div>
    <x-admin.page-header :title="__('journeys_admin.title')" :eyebrow="__('nav.marketing')" :description="__('journeys_admin.hint')" />

    <x-admin.tabs :tabs="[
        'abandoned_cart' => ['bag', __('journeys_admin.tab_cart')],
        'birthday' => ['gift', __('journeys_admin.tab_birthday')],
        'templates' => ['file-text', __('journeys_admin.tab_templates')],
        'activity' => ['history', __('journeys_admin.tab_activity')],
    ]" :current="$tab" />

    @if ($journeyRow)
        {{-- On/off: a switch acts at once (no Save button) --}}
        <div class="card mb-6 flex flex-col gap-4 p-5 sm:flex-row sm:items-center sm:justify-between sm:px-6">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <h2 class="font-display text-xl font-semibold text-ink-900 dark:text-ivory">{{ __('journeys_admin.name_'.$journeyRow->key) }}</h2>
                    <span class="{{ $journeyRow->is_enabled ? 'badge-success' : 'badge-neutral' }}">{{ $journeyRow->is_enabled ? __('journeys_admin.running') : __('journeys_admin.paused') }}</span>
                </div>
                <p class="mt-1 text-sm text-ink-500">{{ __('journeys_admin.about_'.$journeyRow->key) }}</p>
            </div>
            <button type="button" role="switch" aria-checked="{{ $journeyRow->is_enabled ? 'true' : 'false' }}" wire:click="toggle" @disabled(! $canEdit) wire:loading.attr="disabled"
                    class="relative inline-flex h-11 w-[4.5rem] shrink-0 items-center rounded-full p-1 transition {{ $journeyRow->is_enabled ? 'bg-emerald-soft' : 'bg-sand-300 dark:bg-ink-700' }}"
                    aria-label="{{ __('journeys_admin.name_'.$journeyRow->key) }}">
                <span class="size-9 rounded-full bg-white shadow transition-transform {{ $journeyRow->is_enabled ? 'ltr:translate-x-7 rtl:-translate-x-7' : '' }}"></span>
            </button>
        </div>

        <div class="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
            <x-admin.stat :label="__('journeys_admin.st_started')" :value="number_format($stats['started'])" icon="users" :hint="__('journeys_admin.last_30')" />
            <x-admin.stat :label="__('journeys_admin.st_sent')" :value="number_format($stats['sent'])" icon="megaphone" tone="brass" />
            <x-admin.stat :label="__('journeys_admin.st_converted')" :value="number_format($stats['converted']).' · '.$stats['rate'].'%'" icon="check" />
            <x-admin.stat :label="__('journeys_admin.st_revenue')" :value="number_format($stats['revenue'], 2).' QAR'" icon="cash" tone="brass" />
        </div>

        @unless ($n8nReady)
            <div class="mb-6 flex items-start gap-3 rounded-2xl border border-amber-soft/30 bg-amber-soft/10 p-4 text-sm" role="note">
                <x-icon name="alert" class="mt-0.5 size-5 shrink-0 text-amber-soft" />
                <p>{{ __('journeys_admin.n8n_off') }} <a class="font-medium underline" href="{{ route('admin.settings.index', ['tab' => 'integrations']) }}">{{ __('journeys_admin.open_settings') }}</a></p>
            </div>
        @endunless

        <div class="grid gap-6 xl:grid-cols-[1fr_20rem] [&>*]:min-w-0">
            {{-- Timeline --}}
            <section class="card p-5 sm:p-6" aria-label="{{ __('journeys_admin.timeline') }}">
                <div class="mb-5 flex items-center justify-between gap-3">
                    <h3 class="font-display text-lg font-semibold">{{ __('journeys_admin.timeline') }}</h3>
                    @if ($canEdit)<button type="button" wire:click="editStep" class="btn-primary btn-sm"><x-icon name="plus" class="size-4" />{{ __('journeys_admin.add_step') }}</button>@endif
                </div>
                @if ($steps->isEmpty())
                    <x-admin.empty :title="__('journeys_admin.no_steps')" :description="__('journeys_admin.no_steps_hint')" icon="clock" />
                @else
                    <ol class="relative space-y-4 ps-8 before:absolute before:inset-y-2 before:start-3 before:w-px before:bg-sand-200 dark:before:bg-ink-700">
                        @foreach ($steps as $s)
                            <li class="relative" wire:key="st-{{ $s->id }}">
                                <span class="absolute -start-8 top-3 grid size-6 place-items-center rounded-full text-xs font-semibold tabular-nums {{ $s->is_enabled ? 'bg-brass-500 text-ink-950' : 'bg-sand-200 text-ink-500 dark:bg-ink-700' }}">{{ $s->position }}</span>
                                <div class="rounded-2xl border border-sand-100 p-4 dark:border-ink-800 {{ $s->is_enabled ? '' : 'opacity-60' }}">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="font-medium text-ink-900 dark:text-ivory">{{ $s->label ?: __('journeys_admin.step_n', ['n' => $s->position]) }}</span>
                                        <span class="badge-neutral tabular-nums"><x-icon name="clock" class="size-3.5" />
                                            @if ($journeyRow->key === 'birthday'){{ $s->offset_days === 0 ? __('journeys_admin.on_the_day') : trans_choice('journeys_admin.days_from', abs($s->offset_days), ['n' => abs($s->offset_days), 'dir' => $s->offset_days < 0 ? __('journeys_admin.before') : __('journeys_admin.after_word')]) }} · {{ $s->send_time }}
                                            @else{{ __('journeys_admin.after_delay', ['t' => $delayLabel($s->delay_minutes)]) }}@endif
                                        </span>
                                        @foreach ($s->channels as $ch)<span class="badge-neutral">{{ __('content_admin.ch_'.$ch) }}</span>@endforeach
                                        @unless ($s->is_enabled)<span class="badge-warning">{{ __('journeys_admin.step_off') }}</span>@endunless
                                    </div>
                                    <p class="mt-2 text-sm text-ink-600 dark:text-ink-300">
                                        <x-icon name="file-text" class="inline size-4 text-ink-400" /> {{ $s->template?->name ?? __('journeys_admin.no_template') }}
                                        @if ($s->template?->kind === 'offer')<span class="badge-brass ms-1">{{ __('journeys_admin.offer') }}</span>@endif
                                    </p>
                                    @if ($canEdit)
                                        <div class="mt-3 flex gap-2">
                                            <button type="button" wire:click="editStep('{{ $s->id }}')" class="btn-secondary btn-sm"><x-icon name="edit" class="size-4" />{{ __('admin.edit') }}</button>
                                            <button type="button" wire:click="deleteStep('{{ $s->id }}')" wire:confirm="{{ __('journeys_admin.confirm_delete_step') }}" class="btn-ghost btn-sm text-ruby-soft"><x-icon name="trash" class="size-4" />{{ __('admin.delete') }}</button>
                                        </div>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ol>
                @endif
            </section>

            {{-- Safety rules --}}
            <aside class="space-y-6">
                <form wire:submit="saveRules" class="card space-y-4 p-5" x-data="{ dirty: false }" @input="dirty = true" @submit="dirty = false">
                    <h3 class="font-display text-lg font-semibold">{{ __('journeys_admin.rules') }}</h3>
                    <div class="grid grid-cols-2 gap-3">
                        <x-admin.field :label="__('journeys_admin.quiet_from')" for="rules.quiet_start"><input id="rules.quiet_start" type="time" wire:model="rules.quiet_start" class="input" @disabled(! $canEdit)></x-admin.field>
                        <x-admin.field :label="__('journeys_admin.quiet_to')" for="rules.quiet_end"><input id="rules.quiet_end" type="time" wire:model="rules.quiet_end" class="input" @disabled(! $canEdit)></x-admin.field>
                    </div>
                    <p class="-mt-2 text-xs text-ink-500">{{ __('journeys_admin.quiet_hint') }}</p>
                    <x-admin.field :label="__('journeys_admin.max_per_day')" for="rules.max_per_day" :hint="__('journeys_admin.max_per_day_hint')"><input id="rules.max_per_day" type="number" min="0" max="20" wire:model="rules.max_per_day" class="input tabular-nums" @disabled(! $canEdit)></x-admin.field>
                    <p class="rounded-xl bg-sand-50 p-3 text-xs text-ink-600 dark:bg-ink-800 dark:text-ink-300"><x-icon name="shield" class="me-1 inline size-4 text-emerald-soft" />{{ __('journeys_admin.consent_note') }}</p>
                    @if ($canEdit)<button class="btn-primary w-full" :disabled="!dirty" wire:loading.attr="disabled" wire:target="saveRules"><x-icon name="check" class="size-4" />{{ __('admin.save_changes') }}</button>@endif
                </form>
            </aside>
        </div>
    @endif

    @if ($tab === 'templates')
        <div class="card">
            <div class="flex items-center justify-between gap-3 border-b border-sand-100 p-4 sm:px-6 dark:border-ink-800">
                <p class="text-sm text-ink-500">{{ __('journeys_admin.templates_hint') }}</p>
                @if ($canEdit)<button type="button" wire:click="editTemplate" class="btn-primary btn-sm shrink-0"><x-icon name="plus" class="size-4" />{{ __('journeys_admin.new_template') }}</button>@endif
            </div>
            @if ($templates->isEmpty())
                <x-admin.empty :title="__('journeys_admin.no_templates')" :description="__('journeys_admin.no_templates_hint')" icon="file-text" />
            @else
                <ul class="divide-y divide-sand-100 dark:divide-ink-800">
                    @foreach ($templates as $t)
                        <li class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:px-6" wire:key="tp-{{ $t->id }}">
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2"><span class="font-medium">{{ $t->name }}</span><span class="{{ $t->kind === 'offer' ? 'badge-brass' : 'badge-neutral' }}">{{ __('journeys_admin.kind_'.$t->kind) }}</span>
                                    @if ($t->promotion_id)<span class="badge-success"><x-icon name="tag" class="size-3.5" />{{ __('journeys_admin.with_coupon') }}</span>@endif
                                    @unless ($t->is_active)<span class="badge-warning">{{ __('journeys_admin.inactive') }}</span>@endunless</div>
                                <p class="mt-1 truncate text-sm text-ink-500">{{ app()->getLocale() === 'ar' ? $t->title_ar : $t->title_en }} — {{ app()->getLocale() === 'ar' ? $t->body_ar : $t->body_en }}</p>
                            </div>
                            @if ($canEdit)
                                <div class="flex gap-2">
                                    <button type="button" wire:click="editTemplate('{{ $t->id }}')" class="btn-secondary btn-sm"><x-icon name="edit" class="size-4" />{{ __('admin.edit') }}</button>
                                    <button type="button" wire:click="deleteTemplate('{{ $t->id }}')" wire:confirm="{{ __('journeys_admin.confirm_delete_template') }}" class="btn-ghost btn-sm text-ruby-soft" aria-label="{{ __('admin.delete') }}"><x-icon name="trash" class="size-4" /></button>
                                </div>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endif

    @if ($tab === 'activity')
        <div class="card">
            <x-admin.toolbar>
                <select wire:model.live="filterStatus" class="input sm:w-48" aria-label="{{ __('admin.status') }}">
                    <option value="">{{ __('admin.all_statuses') }}</option>
                    @foreach (['sent', 'delivered', 'failed', 'skipped', 'queued'] as $st)<option value="{{ $st }}">{{ __('journeys_admin.status_'.$st) }}</option>@endforeach
                </select>
            </x-admin.toolbar>
            @if ($deliveries->isEmpty())
                <x-admin.empty :title="__('journeys_admin.no_activity')" :description="__('journeys_admin.no_activity_hint')" icon="history" />
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-start text-xs uppercase tracking-wider text-ink-500"><tr>
                            <th class="px-4 py-3 text-start">{{ __('journeys_admin.customer') }}</th><th class="px-4 py-3 text-start">{{ __('journeys_admin.journey') }}</th><th class="px-4 py-3 text-start">{{ __('journeys_admin.channels') }}</th>
                            <th class="px-4 py-3 text-start">{{ __('admin.status') }}</th><th class="px-4 py-3 text-start">{{ __('journeys_admin.coupon') }}</th><th class="px-4 py-3 text-start">{{ __('journeys_admin.when') }}</th></tr></thead>
                        <tbody class="divide-y divide-sand-100 dark:divide-ink-800">
                            @foreach ($deliveries as $d)
                                <tr wire:key="dl-{{ $d->id }}">
                                    <td class="px-4 py-3 font-medium">{{ $d->user?->name }}</td>
                                    <td class="px-4 py-3 text-ink-600">{{ __('journeys_admin.name_'.($d->run?->journey?->key ?? 'abandoned_cart')) }} · {{ $d->step?->label ?? '#'.$d->step?->position }}</td>
                                    <td class="px-4 py-3"><span class="badge-neutral">{{ __('content_admin.ch_'.$d->channel) }}</span> <span class="text-xs text-ink-500 uppercase">{{ $d->locale }}</span></td>
                                    <td class="px-4 py-3"><span class="{{ match ($d->status) { 'sent', 'delivered' => 'badge-success', 'failed' => 'badge-danger', 'skipped' => 'badge-neutral', default => 'badge-warning' } }}">{{ __('journeys_admin.status_'.$d->status) }}</span>
                                        @if ($d->reason)<span class="block text-xs text-ink-500">{{ __('journeys_admin.reason.'.$d->reason) !== 'journeys_admin.reason.'.$d->reason ? __('journeys_admin.reason.'.$d->reason) : $d->reason }}</span>@endif</td>
                                    <td class="px-4 py-3 font-mono text-xs">{{ $d->coupon_code ?? '—' }}</td>
                                    <td class="px-4 py-3 whitespace-nowrap tabular-nums text-ink-500">{{ \App\Support\BusinessTime::display($d->sent_at ?? $d->created_at) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if ($deliveries->hasPages())<div class="border-t border-sand-100 p-4 dark:border-ink-800">{{ $deliveries->links('components.admin.pagination') }}</div>@endif
            @endif
        </div>
    @endif

    {{-- Step editor --}}
    <x-admin.dialog wire:model="showStep" :title="$stepId ? __('journeys_admin.edit_step') : __('journeys_admin.add_step')" max-width="max-w-xl">
        <form wire:submit="saveStep" class="space-y-5">
            <x-admin.field :label="__('journeys_admin.step_name')" for="step.label"><input id="step.label" wire:model="step.label" class="input" maxlength="80" placeholder="{{ __('journeys_admin.step_name_ph') }}"></x-admin.field>
            @if ($journeyRow?->key === 'birthday')
                <div class="grid gap-4 sm:grid-cols-2 [&>*]:min-w-0">
                    <x-admin.field :label="__('journeys_admin.day')" for="step.offset_days" :hint="__('journeys_admin.day_hint')" required><input id="step.offset_days" type="number" min="-30" max="30" wire:model="step.offset_days" class="input tabular-nums"></x-admin.field>
                    <x-admin.field :label="__('journeys_admin.at_time')" for="step.send_time" :hint="__('journeys_admin.qatar_time')" required><input id="step.send_time" type="time" wire:model="step.send_time" class="input"></x-admin.field>
                </div>
            @else
                <div>
                    <label class="field-label" for="step.delay_value">{{ __('journeys_admin.after') }}</label>
                    <div class="flex gap-2">
                        <input id="step.delay_value" type="number" min="1" wire:model="step.delay_value" class="input w-28 tabular-nums">
                        <select wire:model="step.delay_unit" class="input w-40" aria-label="{{ __('journeys_admin.unit') }}"><option value="minutes">{{ __('journeys_admin.u_minutes') }}</option><option value="hours">{{ __('journeys_admin.u_hours') }}</option><option value="days">{{ __('journeys_admin.u_days') }}</option></select>
                    </div>
                    <p class="mt-1 text-xs text-ink-500">{{ __('journeys_admin.after_hint') }}</p>
                    @error('step.delay_value')<p class="field-error">{{ $message }}</p>@enderror
                </div>
            @endif
            <div>
                <span class="field-label">{{ __('journeys_admin.channels') }}</span>
                <div class="flex flex-wrap gap-2">@foreach ($channels as $ch)<label class="inline-flex min-h-11 cursor-pointer items-center gap-2 rounded-full border px-4 text-sm {{ in_array($ch, $step['channels'] ?? [], true) ? 'border-brass-500 bg-brass-50 dark:bg-brass-500/10' : 'border-sand-200 dark:border-ink-700' }}"><input type="checkbox" wire:model.live="step.channels" value="{{ $ch }}" class="checkbox">{{ __('content_admin.ch_'.$ch) }}</label>@endforeach</div>
                @if (in_array('whatsapp', $step['channels'] ?? [], true))<p class="mt-2 text-xs text-ink-500">{{ __('journeys_admin.whatsapp_note') }}</p>@endif
                @error('step.channels')<p class="field-error">{{ $message }}</p>@enderror
            </div>
            <x-admin.field :label="__('journeys_admin.template')" for="step.template_id" required>
                <select id="step.template_id" wire:model="step.template_id" class="input"><option value="">{{ __('journeys_admin.choose_template') }}</option>@foreach ($templates->where('is_active', true) as $t)<option value="{{ $t->id }}">{{ $t->name }} — {{ __('journeys_admin.kind_'.$t->kind) }}</option>@endforeach</select>
            </x-admin.field>
            <x-admin.toggle :label="__('journeys_admin.step_enabled')" wire:model="step.is_enabled" />
            <div class="flex flex-col-reverse gap-2 border-t border-sand-100 pt-5 sm:flex-row sm:justify-end dark:border-ink-800">
                <button type="button" class="btn-secondary" @click="open = false">{{ __('admin.cancel') }}</button>
                <button class="btn-primary" wire:loading.attr="disabled" wire:target="saveStep"><x-icon name="check" class="size-4" />{{ __('admin.save') }}</button>
            </div>
        </form>
    </x-admin.dialog>

    {{-- Template editor --}}
    <x-admin.dialog wire:model="showTemplate" :title="$templateId ? __('journeys_admin.edit_template') : __('journeys_admin.new_template')" max-width="max-w-5xl">
        <form wire:submit="saveTemplate" class="grid gap-6 lg:grid-cols-[1fr_18rem] [&>*]:min-w-0">
            <div class="space-y-5">
                <div class="grid gap-4 sm:grid-cols-2 [&>*]:min-w-0">
                    <x-admin.field :label="__('journeys_admin.template_name')" for="tpl.name" required :hint="__('marketing_admin.internal_only')"><input id="tpl.name" wire:model="tpl.name" class="input"></x-admin.field>
                    <x-admin.field :label="__('journeys_admin.kind')" for="tpl.kind"><select id="tpl.kind" wire:model="tpl.kind" class="input"><option value="reminder">{{ __('journeys_admin.kind_reminder') }}</option><option value="offer">{{ __('journeys_admin.kind_offer') }}</option></select></x-admin.field>
                </div>
                <div class="flex flex-wrap items-center gap-1.5 text-xs text-ink-500"><span>{{ __('journeys_admin.placeholders') }}</span>
                    @foreach (['name', 'items', 'first_item', 'coupon', 'expires', 'app_link'] as $ph)<code class="rounded bg-sand-100 px-1.5 py-0.5 dark:bg-ink-800" x-data @click="navigator.clipboard?.writeText('@{{ {{ $ph }} }}')" title="{{ __('journeys_admin.copy') }}">@{{ {{ $ph }} }}</code>@endforeach</div>
                <div class="grid gap-4 sm:grid-cols-2 [&>*]:min-w-0">
                    <x-admin.field :label="__('marketing_admin.title_en')" for="tpl.title_en" required><input id="tpl.title_en" wire:model.live.debounce.300ms="tpl.title_en" class="input" dir="ltr" maxlength="120"></x-admin.field>
                    <x-admin.field :label="__('marketing_admin.title_ar')" for="tpl.title_ar" required><input id="tpl.title_ar" wire:model.live.debounce.300ms="tpl.title_ar" class="input" dir="rtl" maxlength="120"></x-admin.field>
                    <x-admin.field :label="__('marketing_admin.body_en')" for="tpl.body_en" required><textarea id="tpl.body_en" wire:model.live.debounce.300ms="tpl.body_en" rows="3" class="input" dir="ltr" maxlength="1000"></textarea></x-admin.field>
                    <x-admin.field :label="__('marketing_admin.body_ar')" for="tpl.body_ar" required><textarea id="tpl.body_ar" wire:model.live.debounce.300ms="tpl.body_ar" rows="3" class="input" dir="rtl" maxlength="1000"></textarea></x-admin.field>
                    <x-admin.field :label="__('journeys_admin.wa_en')" for="tpl.whatsapp_en" :hint="__('journeys_admin.wa_hint')"><textarea id="tpl.whatsapp_en" wire:model.live.debounce.300ms="tpl.whatsapp_en" rows="3" class="input" dir="ltr" maxlength="1500"></textarea></x-admin.field>
                    <x-admin.field :label="__('journeys_admin.wa_ar')" for="tpl.whatsapp_ar" :hint="__('journeys_admin.wa_hint')"><textarea id="tpl.whatsapp_ar" wire:model.live.debounce.300ms="tpl.whatsapp_ar" rows="3" class="input" dir="rtl" maxlength="1500"></textarea></x-admin.field>
                </div>
                <x-admin.field :label="__('marketing_admin.deep_link')" for="tpl.deep_link" :hint="__('marketing_admin.deep_link_hint')"><input id="tpl.deep_link" wire:model="tpl.deep_link" class="input font-mono text-xs" dir="ltr" placeholder="albahar://cart"></x-admin.field>
                <fieldset class="rounded-2xl border border-sand-200 p-4 dark:border-ink-700">
                    <legend class="px-2 text-sm font-semibold">{{ __('journeys_admin.coupon_title') }}</legend>
                    <div class="grid gap-4 sm:grid-cols-3 [&>*]:min-w-0">
                        <x-admin.field class="sm:col-span-3" :label="__('journeys_admin.promotion')" for="tpl.promotion_id" :hint="__('journeys_admin.promotion_hint')">
                            <select id="tpl.promotion_id" wire:model="tpl.promotion_id" class="input"><option value="">{{ __('journeys_admin.no_coupon') }}</option>@foreach ($promotions as $p)<option value="{{ $p->id }}">{{ app()->getLocale() === 'ar' ? $p->name_ar : $p->name_en }} ({{ rtrim(rtrim($p->value, '0'), '.') }}{{ in_array($p->type, ['percentage', 'flash_sale']) ? '%' : '' }})</option>@endforeach</select></x-admin.field>
                        <x-admin.field :label="__('journeys_admin.coupon_prefix')" for="tpl.coupon_prefix"><input id="tpl.coupon_prefix" wire:model="tpl.coupon_prefix" class="input font-mono uppercase" maxlength="8" dir="ltr"></x-admin.field>
                        <x-admin.field :label="__('journeys_admin.coupon_valid')" for="tpl.coupon_valid_hours"><input id="tpl.coupon_valid_hours" type="number" min="1" max="2160" wire:model="tpl.coupon_valid_hours" class="input tabular-nums"></x-admin.field>
                    </div>
                </fieldset>
                <x-admin.toggle :label="__('journeys_admin.template_active')" wire:model="tpl.is_active" />
            </div>

            <aside class="space-y-4">
                <div x-data="{ l: @entangle('previewLocale') }">
                    <div class="mb-2 flex items-center justify-between"><span class="text-xs font-semibold uppercase tracking-wider text-ink-500">{{ __('marketing_admin.preview') }}</span>
                        <div class="flex rounded-lg border border-sand-200 p-0.5 text-xs dark:border-ink-700"><button type="button" @click="l = 'en'" :class="l === 'en' && 'bg-sand-100 dark:bg-ink-800'" class="min-h-8 rounded px-2.5">EN</button><button type="button" @click="l = 'ar'" :class="l === 'ar' && 'bg-sand-100 dark:bg-ink-800'" class="min-h-8 rounded px-2.5">ع</button></div></div>
                    <div class="rounded-[2rem] border-4 border-ink-900 bg-gradient-to-b from-sand-100 to-sand-200 p-3 pt-8 dark:from-ink-800 dark:to-ink-900">
                        @foreach (['en' => 'ltr', 'ar' => 'rtl'] as $l => $dir)
                            <div x-show="l === '{{ $l }}'" @if ($l === 'ar') x-cloak @endif dir="{{ $dir }}" class="rounded-2xl bg-white/90 p-3 shadow-lg backdrop-blur dark:bg-ink-950/90">
                                <div class="flex items-center gap-2 text-[0.65rem] text-ink-500"><span class="grid size-4 place-items-center rounded bg-brass-500 text-[0.5rem] font-bold text-ink-950">A</span>{{ $l === 'ar' ? config('platform.brand.name_ar') : config('platform.brand.name_en') }} · {{ $l === 'ar' ? 'الآن' : 'now' }}</div>
                                <p class="mt-1.5 text-sm font-semibold text-ink-900 dark:text-ivory">{{ ($tpl['title_'.$l] ?? '') ?: '…' }}</p>
                                <p class="mt-0.5 line-clamp-4 text-xs text-ink-600 dark:text-sand-200">{{ ($tpl['body_'.$l] ?? '') ?: '…' }}</p>
                            </div>
                            <div x-show="l === '{{ $l }}'" @if ($l === 'ar') x-cloak @endif dir="{{ $dir }}" class="mt-3 rounded-2xl rounded-tl-sm bg-[#d9fdd3] p-3 text-xs text-ink-900 shadow dark:bg-emerald-900/40 dark:text-ivory">
                                <p class="whitespace-pre-line">{{ ($tpl['whatsapp_'.$l] ?? '') ?: (trim(($tpl['title_'.$l] ?? '')."\n".($tpl['body_'.$l] ?? '')) ?: '…') }}</p>
                                <p class="mt-1 text-end text-[0.6rem] text-ink-500">WhatsApp</p>
                            </div>
                        @endforeach
                        <div class="h-8"></div>
                    </div>
                </div>
                @if ($templateId)
                    <div class="rounded-2xl border border-sand-200 p-3 dark:border-ink-700">
                        <p class="mb-2 text-xs font-semibold uppercase tracking-wider text-ink-500">{{ __('journeys_admin.send_test') }}</p>
                        <div class="mb-3 flex flex-wrap gap-2">@foreach ($channels as $ch)<label class="inline-flex min-h-9 cursor-pointer items-center gap-1.5 text-sm"><input type="checkbox" wire:model="testChannels" value="{{ $ch }}" class="checkbox">{{ __('content_admin.ch_'.$ch) }}</label>@endforeach</div>
                        <button type="button" wire:click="sendTest" class="btn-secondary w-full" wire:loading.attr="disabled" wire:target="sendTest"><x-icon name="bell" class="size-4" />{{ __('marketing_admin.send_test_to_me') }}</button>
                        <p class="mt-2 text-xs text-ink-500">{{ __('journeys_admin.test_note') }}</p>
                    </div>
                @endif
            </aside>

            <div class="flex flex-col-reverse gap-2 border-t border-sand-100 pt-5 sm:flex-row sm:justify-end lg:col-span-2 dark:border-ink-800">
                <button type="button" class="btn-secondary" @click="open = false">{{ __('admin.cancel') }}</button>
                <button class="btn-primary" wire:loading.attr="disabled" wire:target="saveTemplate"><x-icon name="check" class="size-4" />{{ __('admin.save') }}</button>
            </div>
        </form>
    </x-admin.dialog>
</div>
