@php
    use App\Support\BusinessTime;

    $toneClass = ['good' => 'text-emerald-soft dark:text-emerald-300', 'bad' => 'text-ruby-soft dark:text-red-300', 'flat' => 'text-ink-500', 'none' => 'text-ink-500'];
    $pill = fn (bool $on) => 'inline-flex min-h-11 items-center gap-2 rounded-full border px-4 text-sm font-medium transition '.($on
        ? 'border-ink-900 bg-ink-900 text-ivory dark:border-brass-500 dark:bg-brass-500 dark:text-ink-950'
        : 'border-sand-300 bg-white hover:border-brass-400 dark:border-ink-600 dark:bg-ink-800');
    $weekLabel = function (string $w) {
        [$from, $to] = \App\Domain\Reports\Services\OwnerPackData::bounds($w);

        return __('owner_pack.week_option', ['week' => (int) substr($w, -2), 'from' => \Carbon\CarbonImmutable::parse($from)->locale(app()->getLocale())->translatedFormat('d M'), 'to' => \Carbon\CarbonImmutable::parse($to)->locale(app()->getLocale())->translatedFormat('d M Y')]);
    };
    $statusLook = ['sent' => 'badge-success', 'partial' => 'badge-warning', 'failed' => 'badge-danger', 'sending' => 'badge-warning', 'not_connected' => 'badge-neutral'];
@endphp
<div>
    <x-admin.page-header :title="__('owner_pack.q', ['day' => $due->locale(app()->getLocale())->translatedFormat('l')])" :eyebrow="__('nav.insights')" :description="__('owner_pack.hint')">
        <x-slot:actions>
            <button type="button" wire:click="sendTest" wire:loading.attr="disabled" wire:target="sendTest" class="btn-primary min-h-11" data-testid="send-test"><x-icon name="mail" class="size-4" /><span wire:loading.remove wire:target="sendTest">{{ __('owner_pack.send_test') }}</span><span wire:loading wire:target="sendTest">{{ __('owner_pack.sending') }}</span></button>
            @if ($canEdit)<button type="button" wire:click="openSettings" class="btn-secondary min-h-11" data-testid="open-settings"><x-icon name="sliders" class="size-4" />{{ __('owner_pack.settings') }}</button>@endif
        </x-slot:actions>
    </x-admin.page-header>

    {{-- The state of the pack in one look: on or off, when the next one goes, to whom, on which channels. --}}
    <section class="card card-pad mb-6" aria-labelledby="op-state" data-testid="pack-state">
        <div class="flex flex-wrap items-center gap-2">
            <h2 id="op-state" class="sr-only">{{ __('owner_pack.state') }}</h2>
            <span class="{{ $enabled ? 'badge-success' : 'badge-neutral' }}"><x-icon :name="$enabled ? 'check' : 'pause'" class="size-3.5" />{{ $enabled ? __('owner_pack.on') : __('owner_pack.off') }}</span>
            @if ($enabled)
                <span class="text-sm text-ink-700 dark:text-sand-200">{{ __('owner_pack.next', ['week' => (int) substr($nextWeek, -2), 'when' => BusinessTime::display($due, 'l d M, H:i')]) }}</span>
            @else
                <span class="text-sm text-ink-600 dark:text-ink-300">{{ __('owner_pack.off_hint') }}</span>
            @endif
        </div>
        @php $already = $sentThisWeek->where('channel', 'email')->first(); @endphp
        @if ($enabled && $already)
            <p class="mt-2 text-sm text-ink-600 dark:text-ink-300">{{ __('owner_pack.already', ['status' => __('owner_pack.status.'.$already->status), 'week' => (int) substr($nextWeek, -2)]) }}</p>
        @endif
        <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2">
            <div class="rounded-xl bg-sand-50 p-3 dark:bg-ink-800/60">
                <dt class="flex items-center gap-2 font-medium"><x-icon name="mail" class="size-4" />{{ __('owner_pack.ch_email') }}
                    <span class="{{ $emailOn ? 'badge-success' : 'badge-neutral' }}">{{ $emailOn ? __('owner_pack.on') : __('owner_pack.off') }}</span></dt>
                <dd class="mt-1 text-ink-600 dark:text-ink-300">
                    {{ trans_choice('owner_pack.recipients', count($emails), ['count' => count($emails)]) }}
                    @if (! $mailReady)<span class="mt-1 block text-amber-soft dark:text-amber-300" data-testid="mail-not-ready"><x-icon name="alert" class="me-1 inline size-3.5 align-[-2px]" />{{ __('owner_pack.mail_not_ready') }}</span>@endif
                    @if ($enabled && $emailOn && $emails === [])<span class="mt-1 block text-ruby-soft dark:text-red-300"><x-icon name="alert" class="me-1 inline size-3.5 align-[-2px]" />{{ __('owner_pack.err_no_email') }}</span>@endif
                </dd>
            </div>
            <div class="rounded-xl bg-sand-50 p-3 dark:bg-ink-800/60" data-testid="whatsapp-state">
                <dt class="flex items-center gap-2 font-medium"><x-icon name="whatsapp" class="size-4" />{{ __('owner_pack.ch_whatsapp') }}
                    <span class="{{ $whatsappReady ? ($whatsappOn ? 'badge-success' : 'badge-neutral') : 'badge-warning' }}">{{ $whatsappReady ? ($whatsappOn ? __('owner_pack.on') : __('owner_pack.off')) : __('owner_pack.not_connected') }}</span></dt>
                <dd class="mt-1 text-ink-600 dark:text-ink-300">
                    @if ($whatsappReady){{ trans_choice('owner_pack.recipients', count($numbers), ['count' => count($numbers)]) }}@else{{ __('owner_pack.whatsapp_not_connected_hint') }}@endif
                    @if (! $whatsappReady && $whatsappOn)<span class="mt-1 block text-amber-soft dark:text-amber-300"><x-icon name="alert" class="me-1 inline size-3.5 align-[-2px]" />{{ __('owner_pack.whatsapp_chosen_not_connected') }}</span>@endif
                </dd>
            </div>
        </dl>
    </section>

    {{-- The pack, exactly as it will arrive. --}}
    <div class="mb-4 flex flex-wrap items-center gap-2" role="group" aria-label="{{ __('owner_pack.preview') }}">
        <label class="inline-flex items-center gap-2"><span class="sr-only">{{ __('owner_pack.week') }}</span>
            <select wire:model.live="week" class="input min-h-11 sm:w-72" data-testid="week">@foreach ($weeks as $w)<option value="{{ $w }}">{{ $weekLabel($w) }}</option>@endforeach</select></label>
        @foreach (['en' => 'English', 'ar' => 'العربية'] as $code => $name)
            <button type="button" wire:click="$set('lang', '{{ $code }}')" aria-pressed="{{ $lang === $code ? 'true' : 'false' }}" class="{{ $pill($lang === $code) }}" lang="{{ $code }}">{{ $name }}</button>
        @endforeach
        <span wire:loading.delay wire:target="week, lang"><x-icon name="refresh" class="size-4 animate-spin text-brass-500" /></span>
    </div>

    <article class="card overflow-hidden" dir="{{ $pack['dir'] }}" lang="{{ $pack['lang'] }}" wire:loading.class="opacity-60" wire:target="week, lang" aria-label="{{ $pack['title'] }}" data-testid="pack-preview">
        <header class="bg-ink-900 px-5 py-6 text-ivory sm:px-8">
            <p class="text-xs uppercase tracking-[0.16em] text-brass-300">{{ $pack['period'] }}</p>
            <h2 class="mt-1 font-display text-2xl font-semibold sm:text-3xl">{{ $pack['title'] }}</h2>
            <p class="mt-1 text-sm text-ink-300">{{ $pack['versus'] }}</p>
        </header>
        <div class="space-y-8 p-5 sm:p-8">
            <dl class="grid grid-cols-2 gap-3 lg:grid-cols-5 [&>*]:min-w-0">
                @foreach ($pack['headline'] as $h)
                    <div class="rounded-xl bg-sand-50 p-4 dark:bg-ink-800/60" data-metric="{{ $h['key'] }}">
                        <dt class="text-xs text-ink-500">{{ $h['label'] }}</dt>
                        <dd class="mt-1 break-words font-display text-xl font-semibold tabular-nums" dir="ltr">{{ $h['value'] }}</dd>
                        @if ($h['change'])<dd class="mt-0.5 text-xs font-semibold {{ $toneClass[$h['tone']] }}" dir="ltr">{{ $h['change'] }}</dd>@endif
                    </div>
                @endforeach
            </dl>

            <section aria-labelledby="op-b">
                <h3 id="op-b" class="mb-2 font-display text-lg font-semibold">{{ __('owner_pack.branches_title', [], $pack['lang']) }}</h3>
                <div class="table-wrap"><table class="table">
                    <thead><tr><th></th>@foreach ($pack['columns'] as $col)<th class="!text-end">{{ $col['label'] }}</th>@endforeach</tr></thead>
                    <tbody>@foreach ($pack['rows'] as $row)
                        <tr class="{{ $row['total'] ? 'font-semibold' : '' }}">
                            <td class="whitespace-nowrap">{{ $row['name'] }}</td>
                            @foreach ($row['cells'] as $cell)
                                <td class="text-end tabular-nums" dir="ltr"><span class="whitespace-nowrap">{{ $cell['value'] }}</span>@if ($cell['change'])<span class="block text-[0.7rem] font-semibold {{ $toneClass[$cell['tone']] }}">{{ $cell['change'] }}</span>@endif</td>
                            @endforeach
                        </tr>
                    @endforeach</tbody>
                </table></div>
            </section>

            <div class="grid gap-6 lg:grid-cols-2">
                @foreach ([['top_units_title', 'top_units'], ['top_revenue_title', 'top_revenue']] as [$titleKey, $listKey])
                    <section aria-labelledby="op-{{ $listKey }}">
                        <h3 id="op-{{ $listKey }}" class="mb-2 font-display text-lg font-semibold">{{ $pack[$titleKey] }}</h3>
                        @if ($pack[$listKey] === [])
                            <p class="text-sm text-ink-500">{{ $pack['top_empty'] }}</p>
                        @else
                            <ol class="divide-y divide-sand-100 text-sm dark:divide-ink-800">
                                @foreach ($pack[$listKey] as $item)
                                    <li class="flex items-baseline gap-3 py-2"><span class="w-5 shrink-0 font-semibold text-brass-600">{{ $item['rank'] }}</span><span class="min-w-0 flex-1">{{ $item['name'] }}</span>
                                        <span class="shrink-0 tabular-nums text-ink-500" dir="ltr">{{ $item['units'] }}</span><span class="shrink-0 font-medium tabular-nums" dir="ltr">{{ $item['revenue'] }}</span></li>
                                @endforeach
                            </ol>
                        @endif
                    </section>
                @endforeach
            </div>

            <section class="rounded-xl bg-sand-50 p-5 dark:bg-ink-800/60" aria-labelledby="op-a" data-testid="attention">
                <h3 id="op-a" class="mb-2 font-display text-lg font-semibold">{{ $pack['attention_title'] }}</h3>
                @if ($pack['attention'] === [])
                    <p class="flex items-center gap-2 text-sm font-medium text-emerald-soft dark:text-emerald-300"><x-icon name="check" class="size-4" />{{ $pack['attention_clear'] }}</p>
                @else
                    <ul class="space-y-1.5 text-sm">@foreach ($pack['attention'] as $line)<li class="flex items-start gap-2"><x-icon name="alert" class="mt-0.5 size-4 shrink-0 text-amber-soft" />{{ $line }}</li>@endforeach</ul>
                @endif
            </section>

            <footer class="space-y-1 text-xs text-ink-500">
                @foreach ($pack['definitions'] as $line)<p>{{ $line }}</p>@endforeach
                <p class="pt-2">{{ $pack['footer'] }}</p>
            </footer>
        </div>
    </article>

    {{-- Every send, newest first: when, which week, which channel, how many were reached, and why the rest were not. --}}
    <section class="card mt-8 overflow-hidden" aria-labelledby="op-log" data-testid="send-log">
        <header class="border-b border-sand-100 px-5 py-4 sm:px-6 dark:border-ink-800"><h2 id="op-log" class="font-display text-lg font-semibold">{{ __('owner_pack.log') }}</h2><p class="text-xs text-ink-500">{{ __('owner_pack.log_hint') }}</p></header>
        @if ($log->isEmpty())
            <x-admin.empty :title="__('owner_pack.log_empty')" :description="__('owner_pack.log_empty_hint')" icon="mail" />
        @else
            <div class="table-wrap"><table class="table">
                <thead><tr><th>{{ __('owner_pack.l_when') }}</th><th>{{ __('owner_pack.l_week') }}</th><th>{{ __('owner_pack.l_channel') }}</th><th class="!text-end">{{ __('owner_pack.l_reached') }}</th><th>{{ __('owner_pack.l_status') }}</th></tr></thead>
                <tbody>@foreach ($log as $row)
                    <tr wire:key="log-{{ $row->id }}">
                        <td class="whitespace-nowrap text-sm">{{ BusinessTime::display($row->created_at, 'd M Y, H:i') }}<span class="block text-xs text-ink-500">{{ $row->kind === 'test' ? __('owner_pack.kind_test') : __('owner_pack.kind_scheduled') }}</span></td>
                        <td class="whitespace-nowrap text-sm" dir="ltr">{{ $row->iso_week }}</td>
                        <td class="text-sm">{{ __('owner_pack.ch_'.$row->channel) }} · <span class="uppercase" dir="ltr">{{ $row->language }}</span></td>
                        <td class="text-end tabular-nums">{{ $row->sent_count }} / {{ $row->recipients_count }}</td>
                        <td><span class="{{ $statusLook[$row->status] ?? 'badge-neutral' }}">{{ __('owner_pack.status.'.$row->status) }}</span>
                            @if ($row->error)<span class="mt-1 block max-w-xs text-xs text-ink-500">{{ \Illuminate\Support\Facades\Lang::has('owner_pack.error.'.$row->error) ? __('owner_pack.error.'.$row->error) : $row->error }}</span>@endif</td>
                    </tr>
                @endforeach</tbody>
            </table></div>
        @endif
    </section>

    @if ($canEdit)
        <x-admin.dialog wire:model="showSettings" max-width="max-w-xl" :title="__('owner_pack.settings')" :description="__('owner_pack.settings_hint')">
            <form wire:submit="save" class="space-y-5" data-testid="settings-form">
                <label class="flex items-start gap-3 text-sm"><input type="checkbox" wire:model="form.enabled" class="checkbox mt-0.5" data-testid="f-enabled"><span><span class="font-medium">{{ __('owner_pack.f_enabled') }}</span><span class="block text-xs text-ink-500">{{ __('owner_pack.f_enabled_hint') }}</span></span></label>

                <fieldset class="space-y-3 rounded-xl border border-sand-200 p-4 dark:border-ink-700">
                    <legend class="px-1 text-sm font-medium"><x-icon name="mail" class="me-1 inline size-4" />{{ __('owner_pack.ch_email') }}</legend>
                    <label class="flex items-center gap-3 text-sm"><input type="checkbox" wire:model="form.email_on" class="checkbox">{{ __('owner_pack.f_channel_on') }}</label>
                    <x-admin.field :label="__('owner_pack.f_emails')" for="form.emails" :hint="__('owner_pack.f_emails_hint', ['max' => \App\Domain\Reports\Services\OwnerPackSettings::MAX_RECIPIENTS])"><textarea id="form.emails" wire:model="form.emails" rows="3" class="input" dir="ltr" autocomplete="off" spellcheck="false"></textarea></x-admin.field>
                </fieldset>

                <fieldset class="space-y-3 rounded-xl border border-sand-200 p-4 dark:border-ink-700" @disabled(! $whatsappReady)>
                    <legend class="px-1 text-sm font-medium"><x-icon name="whatsapp" class="me-1 inline size-4" />{{ __('owner_pack.ch_whatsapp') }} @unless ($whatsappReady)<span class="badge-warning ms-1">{{ __('owner_pack.not_connected') }}</span>@endunless</legend>
                    @unless ($whatsappReady)<p class="text-xs text-ink-600 dark:text-ink-300" data-testid="whatsapp-note">{{ __('owner_pack.whatsapp_not_connected_hint') }}</p>@endunless
                    <label class="flex items-center gap-3 text-sm"><input type="checkbox" wire:model="form.whatsapp_on" class="checkbox" @disabled(! $whatsappReady)>{{ __('owner_pack.f_channel_on') }}</label>
                    <x-admin.field :label="__('owner_pack.f_numbers')" for="form.numbers" :hint="__('owner_pack.f_numbers_hint')"><textarea id="form.numbers" wire:model="form.numbers" rows="2" class="input" dir="ltr" autocomplete="off" @disabled(! $whatsappReady)></textarea></x-admin.field>
                </fieldset>

                <div class="grid gap-4 sm:grid-cols-3">
                    <x-admin.field :label="__('owner_pack.f_day')" for="form.day"><select id="form.day" wire:model="form.day" class="input">@foreach ($days as $d)<option value="{{ $d }}">{{ \Carbon\CarbonImmutable::now()->startOfWeek()->addDays($d - 1)->locale(app()->getLocale())->translatedFormat('l') }}</option>@endforeach</select></x-admin.field>
                    <x-admin.field :label="__('owner_pack.f_time')" for="form.time" :hint="__('owner_pack.f_time_hint')"><input id="form.time" type="time" wire:model="form.time" class="input" dir="ltr"></x-admin.field>
                    <x-admin.field :label="__('owner_pack.f_language')" for="form.language"><select id="form.language" wire:model="form.language" class="input"><option value="en">English</option><option value="ar">العربية</option></select></x-admin.field>
                </div>
                <p class="text-xs text-ink-500">{{ __('owner_pack.f_quiet') }}</p>
                <div class="flex flex-col-reverse gap-2 border-t border-sand-100 pt-5 sm:flex-row sm:justify-end dark:border-ink-800">
                    <button type="button" class="btn-secondary min-h-11" @click="open = false">{{ __('admin.back') }}</button>
                    <button class="btn-primary min-h-11" wire:loading.attr="disabled" wire:target="save" data-testid="save-settings">{{ __('admin.save_changes') }}</button>
                </div>
            </form>
        </x-admin.dialog>
    @endif
</div>
