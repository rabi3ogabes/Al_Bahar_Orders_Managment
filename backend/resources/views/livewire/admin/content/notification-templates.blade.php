@php use App\Domain\Notifications\Services\NotificationCatalog; $groups = collect($events)->groupBy(fn ($e) => $e[0], true); @endphp
<div>
    <x-admin.page-header :title="__('nav.notification_templates')" :eyebrow="__('nav.content')" :description="__('content_admin.templates_hint')" />

    <div class="grid gap-6 lg:grid-cols-[16rem_1fr] [&>*]:min-w-0">
        <nav class="card p-2 lg:self-start" aria-label="{{ __('content_admin.events') }}">
            <label class="block p-2 lg:hidden"><span class="sr-only">{{ __('content_admin.events') }}</span>
                <select wire:change="pick($event.target.value)" class="input">@foreach ($events as $key => $e)<option value="{{ $key }}" @selected($event === $key)>{{ __('content_admin.ev_'.str_replace('.', '_', $key)) }}</option>@endforeach</select>
            </label>
            <div class="hidden max-h-[70vh] overflow-y-auto lg:block">
                @foreach ($groups as $cat => $list)
                    <p class="px-3 pb-1 pt-3 text-[0.65rem] font-semibold uppercase tracking-wider text-ink-500">{{ __('sales_admin.cat_'.$cat) }}</p>
                    @foreach ($list as $key => $e)
                        <button type="button" wire:click="pick('{{ $key }}')" class="flex w-full items-center justify-between gap-2 rounded-lg px-3 py-2 text-start text-sm transition {{ $event === $key ? 'bg-ink-900 text-ivory dark:bg-brass-500 dark:text-ink-950' : 'text-ink-700 hover:bg-sand-50 dark:text-sand-200 dark:hover:bg-ink-800' }}">
                            <span class="truncate">{{ __('content_admin.ev_'.str_replace('.', '_', $key)) }}</span>
                            @if ($counts[$key] ?? 0)<span class="rounded-full px-1.5 text-[0.65rem] tabular-nums {{ $event === $key ? 'bg-white/20' : 'bg-brass-50 text-brass-700 dark:bg-brass-500/15 dark:text-brass-300' }}">{{ $counts[$key] }}</span>@endif
                        </button>
                    @endforeach
                @endforeach
            </div>
        </nav>

        <div class="space-y-6">
            <section class="card overflow-hidden">
                <div class="p-5 sm:p-6">
                    <p class="font-mono text-xs text-ink-500" dir="ltr">{{ $event }}</p>
                    <h2 class="font-display text-2xl font-semibold">{{ __('content_admin.ev_'.str_replace('.', '_', $event)) }}</h2>
                    <p class="mt-1 text-sm text-ink-500">{{ __('content_admin.category_label', ['category' => __('sales_admin.cat_'.NotificationCatalog::category($event))]) }} · {{ __('content_admin.default_channels') }}: {{ collect($defaults)->map(fn ($c) => __('content_admin.ch_'.$c))->implode(', ') }}</p>
                </div>
                <div class="table-wrap border-t border-sand-100 dark:border-ink-800"><table class="table">
                    <thead><tr><th>{{ __('content_admin.channel') }}</th><th>English</th><th>العربية</th></tr></thead>
                    <tbody>@foreach (NotificationCatalog::CHANNELS as $ch)
                        <tr wire:key="ch-{{ $ch }}">
                            <td><span class="font-medium">{{ __('content_admin.ch_'.$ch) }}</span>@if (in_array($ch, $defaults, true))<span class="badge-brass ms-2 !text-[0.65rem]">{{ __('content_admin.default') }}</span>@endif</td>
                            @foreach (['en', 'ar'] as $l)
                                @php $o = $overrides[$ch.'|'.$l] ?? null; @endphp
                                <td>
                                    <div class="flex items-center gap-2">
                                        @if ($o)<span class="{{ $o->is_active ? 'badge-success' : 'badge-neutral' }}">{{ $o->is_active ? __('content_admin.custom') : __('content_admin.custom_off') }}</span>@else<span class="badge-neutral">{{ __('content_admin.built_in') }}</span>@endif
                                        @if ($canEdit)<button type="button" wire:click="edit('{{ $ch }}', '{{ $l }}')" class="btn-ghost btn-sm px-2" aria-label="{{ __('admin.edit') }}"><x-icon name="edit" class="size-4" /></button>@endif
                                    </div>
                                </td>
                            @endforeach
                        </tr>
                    @endforeach</tbody>
                </table></div>
            </section>

            <section class="card card-pad">
                <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                    <h2 class="font-display text-xl font-semibold">{{ __('content_admin.preview') }}</h2>
                    <select wire:model.live="channel" class="input w-auto py-2 text-sm" aria-label="{{ __('content_admin.channel') }}">@foreach (NotificationCatalog::CHANNELS as $ch)<option value="{{ $ch }}">{{ __('content_admin.ch_'.$ch) }}</option>@endforeach</select>
                </div>
                <div class="grid gap-4 md:grid-cols-2 [&>*]:min-w-0">
                    @foreach ($previews as $l => $p)
                        <div class="rounded-2xl bg-sand-50 p-4 dark:bg-ink-800/60" dir="{{ $l === 'ar' ? 'rtl' : 'ltr' }}">
                            <p class="mb-2 text-[0.65rem] font-semibold uppercase tracking-wider text-ink-500">{{ $l === 'ar' ? 'العربية' : 'English' }}</p>
                            @if ($p)
                                <div class="rounded-xl bg-white p-3 shadow-sm dark:bg-ink-900">
                                    <p class="text-sm font-semibold text-ink-900 dark:text-ivory">{{ $p['subject'] ?: '—' }}</p>
                                    <p class="mt-1 whitespace-pre-line text-sm text-ink-600 dark:text-sand-200">{{ $p['body'] }}</p>
                                </div>
                            @else
                                <p class="rounded-xl border border-dashed border-sand-300 p-3 text-sm text-ink-500 dark:border-ink-600">{{ __('content_admin.no_built_in') }}</p>
                            @endif
                        </div>
                    @endforeach
                </div>
                <p class="mt-3 text-xs text-ink-500">{{ __('content_admin.preview_sample_hint') }}</p>
            </section>
        </div>
    </div>

    <x-admin.dialog wire:model="showEditor" :title="__('content_admin.edit_template')" :description="__('content_admin.ev_'.str_replace('.', '_', $event)).' · '.__('content_admin.ch_'.$channel).' · '.($locale === 'ar' ? 'العربية' : 'English')" max-width="max-w-3xl">
        <form wire:submit="save" class="grid gap-6 md:grid-cols-[1fr_14rem] [&>*]:min-w-0">
            <div class="space-y-4" dir="{{ $locale === 'ar' ? 'rtl' : 'ltr' }}">
                @if (in_array($channel, ['push', 'email', 'in_app'], true))
                    <x-admin.field :label="__('content_admin.subject')" for="form.subject"><input id="form.subject" wire:model.live.debounce.300ms="form.subject" class="input"></x-admin.field>
                @endif
                <x-admin.field :label="__('content_admin.message_body')" for="form.body" required :hint="$channel === 'sms' ? __('content_admin.sms_hint', ['n' => mb_strlen($form['body'] ?? '')]) : null"><textarea id="form.body" wire:model.live.debounce.300ms="form.body" rows="6" class="input"></textarea></x-admin.field>
                <x-admin.toggle :label="__('content_admin.override_active')" :hint="__('content_admin.override_active_hint')" wire:model="form.is_active" />
                @if ($draftPreview)
                    <div class="rounded-xl bg-sand-50 p-3 dark:bg-ink-800/60">
                        <p class="mb-1 text-[0.65rem] font-semibold uppercase tracking-wider text-ink-500">{{ __('content_admin.live_preview') }}</p>
                        @if ($draftPreview['subject'])<p class="text-sm font-semibold">{{ $draftPreview['subject'] }}</p>@endif
                        <p class="whitespace-pre-line text-sm text-ink-600 dark:text-sand-200">{{ $draftPreview['body'] }}</p>
                    </div>
                @endif
            </div>
            <aside>
                <p class="field-label">{{ __('content_admin.variables') }}</p>
                <p class="mb-2 text-xs text-ink-500">{{ __('content_admin.variables_hint') }}</p>
                <ul class="space-y-1.5" x-data>
                    @forelse ($variables as $v)
                        @php $tok = '{'.'{'.$v.'}'.'}'; @endphp
                        <li><button type="button" class="w-full rounded-lg bg-sand-50 px-2.5 py-1.5 text-start font-mono text-xs hover:bg-brass-50 dark:bg-ink-800 dark:hover:bg-brass-500/10" dir="ltr"
                                    @click="const ta = document.getElementById('form.body'); const t = @js($tok); const s = ta.selectionStart ?? ta.value.length; ta.value = ta.value.slice(0, s) + t + ta.value.slice(ta.selectionEnd ?? s); ta.dispatchEvent(new Event('input')); ta.focus();">{{ $tok }}<span class="block truncate font-sans text-[0.65rem] text-ink-500">{{ \App\Livewire\Admin\Content\NotificationTemplateIndex::SAMPLE[$v] ?? '' }}</span></button></li>
                    @empty
                        <li class="text-xs text-ink-500">{{ __('content_admin.no_variables') }}</li>
                    @endforelse
                </ul>
            </aside>
            <div class="flex flex-col-reverse gap-2 border-t border-sand-100 pt-5 sm:flex-row sm:justify-end md:col-span-2 dark:border-ink-800">
                @if ($overrides->has($channel.'|'.$locale))<button type="button" wire:click="resetDefault('{{ $channel }}', '{{ $locale }}')" wire:confirm="{{ __('content_admin.confirm_reset') }}" class="btn-ghost text-ruby-soft sm:me-auto">{{ __('content_admin.reset_default') }}</button>@endif
                <button type="button" class="btn-secondary" @click="open = false">{{ __('admin.cancel') }}</button>
                <button class="btn-primary" wire:loading.attr="disabled" wire:target="save"><x-icon name="check" class="size-4" />{{ __('content_admin.save_override') }}</button>
            </div>
        </form>
    </x-admin.dialog>
</div>
