@php
    use App\Domain\Messaging\Services\MessageContent;
    use App\Domain\Messaging\Services\MessageVariables;
    use App\Livewire\Admin\Messaging\MessageComposer as Composer;

    $rtl = app()->getLocale() === 'ar';
    $kindIcon = ['reminder' => 'cart', 'offer' => 'gift', 'custom' => 'sparkles'];
    $waOn = $available['whatsapp']['available'];
    $canSettings = auth()->user() && app(\App\Domain\Identity\Services\AccessControl::class)->allows(auth()->user(), 'settings.view');
    $single = $singleId !== null;
    $links = collect(Composer::LINKS)->mapWithKeys(fn ($k) => ['albahar://'.$k => __('messaging_composer.link.'.$k)])->all();
    $currentLink = (string) ($content['deep_link'] ?? '');
    $boot = [
        'limits' => ['title' => MessageContent::TITLE_MAX, 'body' => MessageContent::BODY_MAX, 'whatsapp' => MessageContent::WHATSAPP_MAX],
        'placeholders' => MessageVariables::PLACEHOLDERS,
        'text' => __('messaging_composer.js'),
        'locale' => app()->getLocale(),
        'zone' => \App\Support\BusinessTime::zone(),
    ];
    $tok = fn (string $k) => '{'.'{'.$k.'}'.'}';
@endphp
<div x-data="messageComposer" class="contents">
    {{-- Start-up data for the Alpine part (static x-data: a changing value would make Livewire rebuild the component). --}}
    <span class="hidden" x-ref="boot" data-boot="{{ json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) }}"></span>

    <x-admin.dialog wire:model="open" :title="__('messaging_composer.title')" max-width="max-w-4xl">
        <div x-effect="if ($wire.step === 'sent') { dirty = false }" class="@container space-y-7">

            {{-- Who this is for --}}
            <div class="flex items-center gap-3 rounded-2xl border border-sand-200/70 bg-white p-3 dark:border-ink-700 dark:bg-ink-800/50" data-no-dirty>
                <span class="grid size-11 shrink-0 place-items-center rounded-full bg-gradient-to-br from-brass-100 to-brass-300 font-display text-lg font-semibold text-ink-900">
                    @if ($single){{ mb_substr($singleName, 0, 1) }}@else<x-icon name="users" class="size-5" />@endif
                </span>
                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-semibold text-ink-900 dark:text-ivory" data-testid="audience">
                        @if ($single){{ $singleName }}
                        @elseif ($audience['mode'] === 'all'){{ __('messaging_composer.to_all', ['count' => number_format($count)]) }}
                        @else{{ trans_choice('messaging_composer.to_many', $count, ['count' => number_format($count)]) }}@endif
                    </p>
                    <p class="text-xs text-ink-500 dark:text-ink-400">
                        @if ($single){{ __('messaging_composer.speaks', ['language' => __('messaging_composer.lang_'.$defaultLang.'_name')]) }}
                        @else{{ __('messaging_composer.own_language') }}@endif
                    </p>
                    @if ($single && $reach)<x-admin.messaging.phones :reach="$reach" class="mt-1" />@endif
                </div>
            </div>

            @if ($step === 'sent')
                {{-- ───────── Result ───────── --}}
                <div class="flex flex-col items-center px-2 py-6 text-center" role="status" data-testid="result">
                    <span class="grid size-16 place-items-center rounded-full {{ ($result['failed'] ?? 0) > 0 && ($result['reached'] ?? 0) === 0 ? 'bg-ruby-soft/10 text-ruby-soft' : 'bg-emerald-soft/10 text-emerald-soft dark:text-emerald-300' }}">
                        <x-icon :name="($result['scheduled'] ?? false) ? 'clock' : 'check'" class="size-8" />
                    </span>
                    <h3 class="mt-5 font-display text-2xl font-semibold text-ink-900 dark:text-ivory">
                        @if ($result['scheduled'] ?? false){{ __('messaging_composer.done_scheduled') }}@elseif ($result['pending'] ?? false){{ __('messaging_composer.done_sending') }}@else{{ __('messaging_composer.done_sent') }}@endif
                    </h3>
                    <p class="mt-2 max-w-md text-sm text-ink-600 dark:text-ink-300">
                        @if ($result['scheduled'] ?? false)
                            {{ __('messaging_composer.done_scheduled_for', ['time' => $result['at']]) }}
                            {{ trans_choice('messaging_composer.done_checked_again', (int) $result['customers']) }}
                        @elseif ($result['pending'] ?? false)
                            {{ __('messaging_composer.done_pending_hint') }}
                        @else
                            {{ __('messaging_composer.done_hint') }}
                        @endif
                    </p>
                    <dl class="mt-6 grid w-full max-w-md grid-cols-3 gap-3 text-center [&>*]:min-w-0">
                        <div class="card p-3"><dt class="text-xs text-ink-500">{{ ($result['scheduled'] ?? false) ? __('messaging_composer.res_customers') : __('messaging_composer.res_sent') }}</dt>
                            <dd class="mt-1 font-display text-2xl font-semibold tabular-nums text-emerald-soft dark:text-emerald-300">{{ number_format(($result['scheduled'] ?? false) ? (int) $result['customers'] : (int) $result['reached']) }}</dd></div>
                        <div class="card p-3"><dt class="text-xs text-ink-500">{{ __('messaging_composer.res_skipped') }}</dt>
                            <dd class="mt-1 font-display text-2xl font-semibold tabular-nums text-amber-soft dark:text-amber-300">{{ number_format((int) $result['skipped']) }}</dd></div>
                        <div class="card p-3"><dt class="text-xs text-ink-500">{{ __('messaging_composer.res_failed') }}</dt>
                            <dd class="mt-1 font-display text-2xl font-semibold tabular-nums {{ (int) $result['failed'] > 0 ? 'text-ruby-soft' : 'text-ink-400' }}">{{ number_format((int) $result['failed']) }}</dd></div>
                    </dl>
                    @if (! empty($result['reasons']))
                        <div class="mt-5 w-full max-w-md rounded-2xl border border-sand-200/70 p-4 text-start dark:border-ink-700">
                            <p class="text-xs font-semibold uppercase tracking-wider text-ink-500">{{ __('messaging_composer.res_why') }}</p>
                            <ul class="mt-2 space-y-1.5 text-sm">
                                @foreach ($result['reasons'] as $reason => $n)
                                    <li class="flex items-start justify-between gap-3"><span class="text-ink-700 dark:text-sand-200">{{ Composer::reasonText($reason) }}</span><span class="font-medium tabular-nums">{{ number_format($n) }}</span></li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </div>
            @else
                {{-- ───────── 1. What to send ───────── --}}
                <section aria-labelledby="mc-what" data-no-dirty>
                    <h3 id="mc-what" class="flex items-center gap-2.5 font-display text-lg font-semibold text-ink-900 dark:text-ivory">
                        <span class="grid size-6 place-items-center rounded-full bg-ink-900 text-xs text-ivory dark:bg-brass-500 dark:text-ink-950" aria-hidden="true">1</span>{{ __('messaging_composer.what') }}
                    </h3>
                    <p class="mt-1 text-sm text-ink-500 dark:text-ink-400">{{ __('messaging_composer.what_hint') }}</p>
                    <div class="mt-3 grid gap-2.5 @md:grid-cols-2" role="group" aria-labelledby="mc-what">
                        @foreach ($templates as $t)
                            @php
                                $usesCoupon = $t->promotion_id !== null || collect([$t->title_en, $t->title_ar, $t->body_en, $t->body_ar, $t->whatsapp_en, $t->whatsapp_ar])->contains(fn ($s) => app(MessageVariables::class)->uses((string) $s, 'coupon'));
                                $blocked = $usesCoupon && ! $canCoupon;
                                $on = $templateId === (string) $t->id;
                            @endphp
                            <button type="button" wire:key="tpl-{{ $t->id }}" @click="pick('{{ $t->id }}')" @disabled($blocked) aria-pressed="{{ $on ? 'true' : 'false' }}" @if ($loop->first) autofocus @endif
                                    class="group relative flex min-h-11 w-full items-start gap-3 rounded-2xl border p-3.5 text-start transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brass-500 disabled:cursor-not-allowed disabled:opacity-55 {{ $on ? 'border-brass-500 bg-brass-50 ring-2 ring-brass-500/30 dark:bg-brass-500/10' : 'border-sand-200 bg-white hover:border-brass-400 dark:border-ink-700 dark:bg-ink-800/50 dark:hover:border-brass-500/60' }}">
                                <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-sand-100 text-brass-600 dark:bg-ink-800 dark:text-brass-300"><x-icon :name="$kindIcon[$t->kind] ?? 'sparkles'" class="size-5" /></span>
                                <span class="min-w-0 flex-1">
                                    <span class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                        <span class="text-sm font-semibold text-ink-900 dark:text-ivory">{{ $t->name }}</span>
                                        <span class="{{ $t->kind === 'offer' ? 'badge-brass' : 'badge-neutral' }}">{{ __('messaging_admin.kind.'.$t->kind) }}</span>
                                    </span>
                                    <span class="mt-1 line-clamp-2 block text-xs leading-relaxed text-ink-500 dark:text-ink-400">{{ $rtl ? $t->title_ar : $t->title_en }} — {{ $rtl ? $t->body_ar : $t->body_en }}</span>
                                    @if ($usesCoupon)
                                        <span class="mt-1.5 inline-flex items-center gap-1 text-xs {{ $blocked ? 'text-amber-soft dark:text-amber-300' : 'text-ink-500 dark:text-ink-400' }}"><x-icon name="tag" class="size-3.5" />{{ $blocked ? __('messaging_composer.needs_marketing') : __('messaging_composer.has_coupon') }}</span>
                                    @endif
                                </span>
                                @if ($on)<span class="absolute end-3 top-3 grid size-5 place-items-center rounded-full bg-brass-500 text-ink-950"><x-icon name="check" class="size-3.5" /></span>@endif
                            </button>
                        @endforeach
                        <button type="button" @click="pick('custom')" aria-pressed="{{ $templateId === 'custom' ? 'true' : 'false' }}" @if ($templates->isEmpty()) autofocus @endif data-testid="own"
                                class="relative flex min-h-11 w-full items-start gap-3 rounded-2xl border border-dashed p-3.5 text-start transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brass-500 {{ $templateId === 'custom' ? 'border-brass-500 bg-brass-50 ring-2 ring-brass-500/30 dark:bg-brass-500/10' : 'border-sand-300 hover:border-brass-400 dark:border-ink-600 dark:hover:border-brass-500/60' }}">
                            <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-sand-100 text-brass-600 dark:bg-ink-800 dark:text-brass-300"><x-icon name="edit" class="size-5" /></span>
                            <span class="min-w-0 flex-1"><span class="block text-sm font-semibold text-ink-900 dark:text-ivory">{{ __('messaging_composer.own') }}</span><span class="mt-1 block text-xs leading-relaxed text-ink-500 dark:text-ink-400">{{ __('messaging_composer.own_hint') }}</span></span>
                            @if ($templateId === 'custom')<span class="absolute end-3 top-3 grid size-5 place-items-center rounded-full bg-brass-500 text-ink-950"><x-icon name="check" class="size-3.5" /></span>@endif
                        </button>
                    </div>
                    @if ($templates->isEmpty())<p class="mt-2 text-xs text-ink-500">{{ __('messaging_composer.no_templates') }}</p>@endif
                </section>

                @if ($templateId !== '')
                    {{-- ───────── 2. How to send ───────── --}}
                    <section aria-labelledby="mc-how" data-no-dirty>
                        <h3 id="mc-how" class="flex items-center gap-2.5 font-display text-lg font-semibold text-ink-900 dark:text-ivory">
                            <span class="grid size-6 place-items-center rounded-full bg-ink-900 text-xs text-ivory dark:bg-brass-500 dark:text-ink-950" aria-hidden="true">2</span>{{ __('messaging_composer.how') }}
                        </h3>
                        <p class="mt-1 text-sm text-ink-500 dark:text-ink-400">{{ __('messaging_composer.how_hint') }}</p>
                        <div class="mt-3 grid gap-2.5 @md:grid-cols-2">
                            @foreach ([['notification', 'bell', true], ['whatsapp', 'whatsapp', $waOn]] as [$ch, $icon, $usable])
                                <label class="group relative flex min-h-11 items-start gap-3 rounded-2xl border border-sand-200 bg-white p-3.5 transition has-[:checked]:border-brass-500 has-[:checked]:bg-brass-50 has-[:checked]:ring-2 has-[:checked]:ring-brass-500/30 has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-brass-500 has-[:disabled]:cursor-not-allowed has-[:disabled]:opacity-55 dark:border-ink-700 dark:bg-ink-800/50 dark:has-[:checked]:bg-brass-500/10 {{ $usable ? 'cursor-pointer hover:border-brass-400' : '' }}">
                                    <input type="checkbox" value="{{ $ch }}" wire:model.live.debounce.250ms="channels" @disabled(! $usable) class="sr-only" data-testid="channel-{{ $ch }}">
                                    <span class="grid size-10 shrink-0 place-items-center rounded-xl {{ $ch === 'whatsapp' ? 'bg-emerald-soft/10 text-emerald-soft dark:text-emerald-300' : 'bg-sand-100 text-brass-600 dark:bg-ink-800 dark:text-brass-300' }}"><x-icon :name="$icon" class="size-5" /></span>
                                    <span class="min-w-0 flex-1"><span class="block text-sm font-semibold text-ink-900 dark:text-ivory">{{ __('messaging_admin.channel.'.$ch) }}</span><span class="mt-1 block text-xs leading-relaxed text-ink-500 dark:text-ink-400">{{ __('messaging_composer.'.$ch.'_hint') }}</span></span>
                                    <span class="mt-0.5 grid size-5 shrink-0 place-items-center rounded-md border border-sand-300 text-transparent transition group-has-[:checked]:border-brass-500 group-has-[:checked]:bg-brass-500 group-has-[:checked]:text-ink-950 dark:border-ink-600" aria-hidden="true"><x-icon name="check" class="size-3.5" /></span>
                                </label>
                            @endforeach
                        </div>
                        @error('channels')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                        @unless ($waOn)
                            <div class="mt-2.5 flex items-start gap-2.5 rounded-xl border border-amber-soft/30 bg-amber-soft/10 p-3 text-sm text-ink-700 dark:text-sand-200" role="note" data-testid="whatsapp-off">
                                <x-icon name="info" class="mt-0.5 size-4 shrink-0 text-amber-soft" />
                                <p>{{ __('messaging_composer.whatsapp_off') }}
                                    @if ($canSettings)<a href="{{ route('admin.settings.index', ['tab' => 'integrations']) }}" wire:navigate class="font-medium underline underline-offset-2 hover:text-brass-600">{{ __('messaging_composer.whatsapp_off_link') }}</a>@else{{ __('messaging_composer.whatsapp_off_ask') }}@endif</p>
                            </div>
                        @endunless
                        @if (in_array('notification', $channels, true) && ! $available['notification']['push'])
                            <p class="mt-2.5 flex items-start gap-2 text-xs text-ink-500 dark:text-ink-400"><x-icon name="info" class="mt-0.5 size-3.5 shrink-0" />{{ __('messaging_composer.push_off') }}</p>
                        @elseif ($single && $reach && in_array('notification', $channels, true) && \App\Domain\Notifications\Services\DevicePushReach::pushWillNotShow($reach))
                            @php $phoneState = \App\Domain\Notifications\Services\DevicePushReach::state($reach); @endphp
                            {{-- It still sends: the inbox message arrives. This only tells the team that a push will not show, and how to fix it. --}}
                            <div class="mt-2.5 flex items-start gap-2.5 rounded-xl border border-amber-soft/30 bg-amber-soft/10 p-3 text-sm text-ink-700 dark:text-sand-200" role="note" data-testid="phones-warning" data-state="{{ $phoneState }}">
                                <x-icon name="alert" class="mt-0.5 size-4 shrink-0 text-amber-soft" />
                                <p><span class="font-semibold text-ink-900 dark:text-ivory">{{ __('messaging_phones.warn.'.$phoneState.'_title') }}.</span> {{ __('messaging_phones.warn.'.$phoneState, ['name' => \Illuminate\Support\Str::before(trim($singleName), ' ')]) }}</p>
                            </div>
                        @endif
                    </section>

                    {{-- ───────── 3. Customise ───────── --}}
                    <section aria-labelledby="mc-custom" id="mc-customise">
                        <h3 id="mc-custom" class="flex items-center gap-2.5 font-display text-lg font-semibold text-ink-900 dark:text-ivory">
                            <span class="grid size-6 place-items-center rounded-full bg-ink-900 text-xs text-ivory dark:bg-brass-500 dark:text-ink-950" aria-hidden="true">3</span>{{ __('messaging_composer.customise') }}
                        </h3>
                        <p class="mt-1 text-sm text-ink-500 dark:text-ink-400">{{ __('messaging_composer.customise_hint') }}</p>

                        <div class="mt-4 grid gap-6 @3xl:grid-cols-[minmax(0,1fr)_17.5rem] [&>*]:min-w-0">
                            <div class="space-y-5">
                                {{-- Language tabs --}}
                                <div class="flex items-center justify-between gap-3" data-no-dirty>
                                    <div role="tablist" aria-label="{{ __('messaging_composer.language') }}" class="inline-flex rounded-xl border border-sand-200 bg-sand-50 p-1 dark:border-ink-700 dark:bg-ink-800/60" @keydown.arrow-right.prevent="lang = 'ar'; $nextTick(() => $refs.tabAr.focus())" @keydown.arrow-left.prevent="lang = 'en'; $nextTick(() => $refs.tabEn.focus())">
                                        @foreach (['en' => 'tabEn', 'ar' => 'tabAr'] as $l => $ref)
                                            <button type="button" role="tab" x-ref="{{ $ref }}" id="mc-tab-{{ $l }}" aria-controls="mc-panel-{{ $l }}" :aria-selected="lang === '{{ $l }}'" :tabindex="lang === '{{ $l }}' ? 0 : -1" @click="lang = '{{ $l }}'"
                                                    :class="lang === '{{ $l }}' ? 'bg-white text-ink-900 shadow-sm dark:bg-brass-500 dark:text-ink-950' : 'text-ink-600 hover:text-ink-900 dark:text-ink-300'"
                                                    class="relative min-h-11 min-w-20 rounded-lg px-4 text-sm font-medium transition focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-brass-500">
                                                {{ __('messaging_composer.lang_'.$l) }}
                                                <span x-show="langHasIssue('{{ $l }}')" x-cloak class="absolute end-1.5 top-1.5 size-2 rounded-full bg-ruby-soft" aria-hidden="true"></span>
                                                <span x-show="langHasIssue('{{ $l }}')" x-cloak class="sr-only">{{ __('messaging_composer.needs_attention') }}</span>
                                            </button>
                                        @endforeach
                                    </div>
                                    <p class="hidden text-xs text-ink-500 sm:block">{{ __('messaging_composer.lang_hint') }}</p>
                                </div>

                                {{-- Placeholder chips --}}
                                <div data-no-dirty>
                                    <p class="mb-1.5 text-xs font-medium text-ink-600 dark:text-ink-300">{{ __('messaging_composer.insert') }} <span class="font-normal text-ink-500">· {{ __('messaging_composer.insert_hint') }}</span></p>
                                    <div class="flex flex-wrap gap-1.5">
                                        @foreach (MessageVariables::PLACEHOLDERS as $ph)
                                            <button type="button" @mousedown.prevent @click="insert('{{ $tok($ph) }}')" aria-label="{{ __('messaging_composer.insert_aria', ['name' => __('messaging_admin.placeholder.'.$ph)]) }}"
                                                    class="inline-flex min-h-9 items-center gap-1.5 rounded-full border border-sand-200 bg-white px-3 text-xs text-ink-700 transition hover:border-brass-400 hover:text-brass-700 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-brass-500 dark:border-ink-700 dark:bg-ink-800 dark:text-sand-200">
                                                <x-icon name="plus" class="size-3" />{{ __('messaging_admin.placeholder.'.$ph) }}
                                            </button>
                                        @endforeach
                                    </div>
                                </div>

                                {{-- Texts, one panel per language (both stay in the page so both are always sent) --}}
                                @foreach (['en' => 'ltr', 'ar' => 'rtl'] as $l => $dir)
                                    <div id="mc-panel-{{ $l }}" role="tabpanel" aria-labelledby="mc-tab-{{ $l }}" x-show="lang === '{{ $l }}'" @if ($l !== $defaultLang) x-cloak @endif class="space-y-4" @input="edited = true">
                                        <div>
                                            <div class="mb-1.5 flex items-end justify-between gap-3">
                                                <label class="field-label !mb-0" for="mc-title_{{ $l }}">{{ __('messaging_composer.title_label') }}@if (in_array('notification', $channels, true))<span class="ms-0.5 text-ruby-soft" aria-hidden="true">*</span>@endif</label>
                                                <span class="text-xs tabular-nums" :class="over('title', '{{ $l }}') ? 'font-semibold text-ruby-soft' : (near('title', '{{ $l }}') ? 'text-amber-soft' : 'text-ink-400')" dir="ltr" x-text="counter('title', '{{ $l }}')" id="mc-title_{{ $l }}-count"></span>
                                            </div>
                                            <input id="mc-title_{{ $l }}" type="text" wire:model="content.title_{{ $l }}" dir="{{ $dir }}" lang="{{ $l }}" autocomplete="off" spellcheck="true" enterkeyhint="next"
                                                   @focus="active = 'title_{{ $l }}'" aria-describedby="mc-title_{{ $l }}-count" placeholder="{{ __('messaging_composer.title_ph_'.$l) }}"
                                                   :class="over('title', '{{ $l }}') && 'input-error'" class="input @error('content.title_'.$l) input-error @enderror">
                                            @error('content.title_'.$l)<p class="field-error" role="alert">{{ $message }}</p>@enderror
                                            <p x-show="unknownIn('title_{{ $l }}')" x-cloak class="field-error" x-text="unknownText('title_{{ $l }}')"></p>
                                        </div>
                                        <div>
                                            <div class="mb-1.5 flex items-end justify-between gap-3">
                                                <label class="field-label !mb-0" for="mc-body_{{ $l }}">{{ __('messaging_composer.message_label') }}<span class="ms-0.5 text-ruby-soft" aria-hidden="true">*</span></label>
                                                <span class="text-xs tabular-nums" :class="over('body', '{{ $l }}') ? 'font-semibold text-ruby-soft' : (near('body', '{{ $l }}') ? 'text-amber-soft' : 'text-ink-400')" dir="ltr" x-text="counter('body', '{{ $l }}')" id="mc-body_{{ $l }}-count"></span>
                                            </div>
                                            <textarea id="mc-body_{{ $l }}" wire:model="content.body_{{ $l }}" rows="4" dir="{{ $dir }}" lang="{{ $l }}" spellcheck="true" @focus="active = 'body_{{ $l }}'" aria-describedby="mc-body_{{ $l }}-count" placeholder="{{ __('messaging_composer.message_ph_'.$l) }}"
                                                      :class="over('body', '{{ $l }}') && 'input-error'" class="input resize-y @error('content.body_'.$l) input-error @enderror"></textarea>
                                            @error('content.body_'.$l)<p class="field-error" role="alert">{{ $message }}</p>@enderror
                                            <p x-show="unknownIn('body_{{ $l }}')" x-cloak class="field-error" x-text="unknownText('body_{{ $l }}')"></p>
                                        </div>
                                        @if (in_array('whatsapp', $channels, true))
                                            <div>
                                                <button type="button" x-show="! waShown()" @click="waOpen = true; $nextTick(() => document.getElementById('mc-whatsapp_{{ $l }}')?.focus())" data-no-dirty
                                                        class="inline-flex min-h-11 items-center gap-2 rounded-xl px-1 text-sm font-medium text-brass-700 hover:text-brass-600 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-brass-500 dark:text-brass-300">
                                                    <x-icon name="plus" class="size-4" />{{ __('messaging_composer.wa_add') }}
                                                </button>
                                                <div x-show="waShown()" x-cloak>
                                                    <div class="mb-1.5 flex items-end justify-between gap-3">
                                                        <label class="field-label !mb-0" for="mc-whatsapp_{{ $l }}">{{ __('messaging_composer.wa_label') }}</label>
                                                        <span class="text-xs tabular-nums" :class="over('whatsapp', '{{ $l }}') ? 'font-semibold text-ruby-soft' : (near('whatsapp', '{{ $l }}') ? 'text-amber-soft' : 'text-ink-400')" dir="ltr" x-text="counter('whatsapp', '{{ $l }}')" id="mc-whatsapp_{{ $l }}-count"></span>
                                                    </div>
                                                    <textarea id="mc-whatsapp_{{ $l }}" wire:model="content.whatsapp_{{ $l }}" rows="5" dir="{{ $dir }}" lang="{{ $l }}" spellcheck="true" @focus="active = 'whatsapp_{{ $l }}'" aria-describedby="mc-whatsapp_{{ $l }}-count mc-whatsapp_{{ $l }}-hint"
                                                              :class="over('whatsapp', '{{ $l }}') && 'input-error'" class="input resize-y @error('content.whatsapp_'.$l) input-error @enderror"></textarea>
                                                    <p class="field-hint" id="mc-whatsapp_{{ $l }}-hint">{{ __('messaging_composer.wa_hint') }}</p>
                                                    @error('content.whatsapp_'.$l)<p class="field-error" role="alert">{{ $message }}</p>@enderror
                                                    <p x-show="unknownIn('whatsapp_{{ $l }}')" x-cloak class="field-error" x-text="unknownText('whatsapp_{{ $l }}')"></p>
                                                </div>
                                            </div>
                                        @endif
                                    </div>
                                @endforeach

                                {{-- Where it opens --}}
                                <div data-no-dirty>
                                    <label class="field-label" for="mc-link">{{ __('messaging_composer.opens') }}</label>
                                    <select id="mc-link" wire:model="content.deep_link" class="input @error('content.deep_link') input-error @enderror" dir="ltr">
                                        <option value="">{{ __('messaging_composer.link_default', ['target' => __('messaging_composer.link.'.($kind === 'reminder' ? 'cart' : 'home'))]) }}</option>
                                        @foreach ($links as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                                        @if ($currentLink !== '' && ! isset($links[$currentLink]))<option value="{{ $currentLink }}">{{ __('messaging_composer.link_other', ['link' => $currentLink]) }}</option>@endif
                                    </select>
                                    <p class="field-hint">{{ __('messaging_composer.opens_hint') }}</p>
                                    @error('content.deep_link')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                                </div>

                                {{-- Personal coupon --}}
                                @if ($canCoupon)
                                    <fieldset class="rounded-2xl border border-sand-200 p-4 dark:border-ink-700" data-no-dirty>
                                        <legend class="sr-only">{{ __('messaging_composer.coupon') }}</legend>
                                        <label class="flex min-h-11 cursor-pointer items-start gap-3">
                                            <input type="checkbox" wire:model.live="content.coupon.enabled" class="checkbox mt-1 size-5" data-testid="coupon-toggle">
                                            <span class="min-w-0"><span class="block text-sm font-semibold text-ink-900 dark:text-ivory">{{ __('messaging_composer.coupon') }}</span>
                                                <span class="mt-0.5 block text-xs leading-relaxed text-ink-500 dark:text-ink-400">{{ __('messaging_composer.coupon_hint', ['coupon' => "\u{2066}".$tok('coupon')."\u{2069}", 'expires' => "\u{2066}".$tok('expires')."\u{2069}"]) }}</span></span>
                                        </label>
                                        @error('content.coupon.enabled')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                                        <p x-show="couponIssue" x-cloak class="field-error" x-text="couponIssue"></p>
                                        @if ($content['coupon']['enabled'] ?? false)
                                            <div class="mt-4 grid gap-4 @md:grid-cols-[minmax(0,1fr)_7.5rem_7.5rem] [&>*]:min-w-0">
                                                <div class="@md:col-span-3">
                                                    <label class="field-label" for="mc-promotion">{{ __('messaging_composer.coupon_offer') }}<span class="ms-0.5 text-ruby-soft" aria-hidden="true">*</span></label>
                                                    <select id="mc-promotion" wire:model="content.coupon.promotion_id" class="input @error('content.coupon.promotion_id') input-error @enderror">
                                                        <option value="">{{ __('messaging_composer.coupon_offer_pick') }}</option>
                                                        @foreach ($promotions as $p)<option value="{{ $p->id }}">{{ $rtl ? $p->name_ar : $p->name_en }} ({{ rtrim(rtrim((string) $p->value, '0'), '.') }}{{ in_array($p->type, ['percentage', 'flash_sale'], true) ? '%' : '' }})</option>@endforeach
                                                    </select>
                                                    @error('content.coupon.promotion_id')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                                                    @if ($promotions->isEmpty())<p class="field-hint">{{ __('messaging_composer.coupon_offer_none') }}</p>@endif
                                                </div>
                                                <div>
                                                    <label class="field-label" for="mc-prefix">{{ __('messaging_admin.field.coupon_prefix') }}</label>
                                                    <input id="mc-prefix" type="text" wire:model="content.coupon.prefix" maxlength="8" dir="ltr" autocomplete="off" autocapitalize="characters" class="input font-mono uppercase @error('content.coupon.prefix') input-error @enderror">
                                                    @error('content.coupon.prefix')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                                                </div>
                                                <div>
                                                    <label class="field-label" for="mc-hours">{{ __('messaging_composer.coupon_hours') }}</label>
                                                    <input id="mc-hours" type="number" inputmode="numeric" min="1" max="720" wire:model="content.coupon.valid_hours" class="input tabular-nums @error('content.coupon.valid_hours') input-error @enderror">
                                                    @error('content.coupon.valid_hours')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                                                </div>
                                                <p class="self-end pb-2 text-xs text-ink-500 dark:text-ink-400" x-text="t.coupon_example.replace(':code', '\u2066' + couponCode() + '\u2069')"></p>
                                            </div>
                                        @endif
                                    </fieldset>
                                @endif
                            </div>

                            {{-- Live preview --}}
                            <aside class="@3xl:sticky @3xl:top-0 @3xl:self-start" aria-label="{{ __('messaging_composer.preview') }}" data-no-dirty>
                                <div class="mb-2 flex items-center justify-between">
                                    <span class="text-xs font-semibold uppercase tracking-wider text-ink-500">{{ __('messaging_composer.preview') }}</span>
                                    <span class="text-xs text-ink-400" x-text="t.lang_names[lang]"></span>
                                </div>
                                <div class="rounded-[2rem] border-4 border-ink-900 bg-gradient-to-b from-sand-100 to-sand-200 p-3 pt-7 dark:border-ink-700 dark:from-ink-800 dark:to-ink-900" aria-hidden="true">
                                    <div x-show="push" :dir="lang === 'ar' ? 'rtl' : 'ltr'" class="rounded-2xl bg-white/90 p-3 shadow-lg backdrop-blur dark:bg-ink-950/90" data-testid="preview-push">
                                        <div class="flex items-center gap-2 text-[0.65rem] text-ink-500"><span class="grid size-4 place-items-center rounded bg-brass-500 text-[0.5rem] font-bold text-ink-950">{{ $rtl ? 'ب' : 'A' }}</span>{{ $rtl ? config('platform.brand.name_ar') : config('platform.brand.name_en') }} · <span x-text="t.now"></span></div>
                                        <p class="mt-1.5 break-words text-sm font-semibold" :class="pv.title ? 'text-ink-900 dark:text-ivory' : 'text-ink-400'" x-text="pv.title || t.title_empty"></p>
                                        <p class="mt-0.5 line-clamp-4 break-words text-xs" :class="pv.body ? 'text-ink-600 dark:text-sand-200' : 'text-ink-400'" x-text="pv.body || t.body_empty"></p>
                                    </div>
                                    <div x-show="wa" :dir="lang === 'ar' ? 'rtl' : 'ltr'" class="mt-3 rounded-2xl rounded-ss-sm bg-[#d9fdd3] p-3 text-xs text-ink-900 shadow dark:bg-emerald-900/40 dark:text-ivory" data-testid="preview-wa">
                                        <p class="whitespace-pre-line break-words" x-text="pv.wa || t.body_empty"></p>
                                        <p class="mt-1 text-end text-[0.6rem] text-ink-500"><span x-text="t.now"></span> ✓✓</p>
                                    </div>
                                    <p x-show="! push && ! wa" x-cloak class="rounded-2xl bg-white/70 p-3 text-center text-xs text-ink-500 dark:bg-ink-950/60" x-text="t.preview_none"></p>
                                    <div class="h-6"></div>
                                </div>
                                <p class="mt-2 text-xs leading-relaxed text-ink-500 dark:text-ink-400">{{ $single ? __('messaging_composer.preview_single', ['name' => \Illuminate\Support\Str::before(trim($singleName), ' ')]) : __('messaging_composer.preview_note') }}</p>
                            </aside>
                        </div>
                    </section>

                    {{-- ───────── 4. Who will get it ───────── --}}
                    <section aria-labelledby="mc-plan" aria-live="polite" data-no-dirty wire:loading.class="opacity-60" wire:target="channels,ignoreCooldown,choose" data-testid="plan">
                        <h3 id="mc-plan" class="flex items-center gap-2.5 font-display text-lg font-semibold text-ink-900 dark:text-ivory">
                            <span class="grid size-6 place-items-center rounded-full bg-ink-900 text-xs text-ivory dark:bg-brass-500 dark:text-ink-950" aria-hidden="true">4</span>{{ __('messaging_composer.plan') }}
                        </h3>
                        @php $plan = $this->plan; $channelsOn = array_values(array_intersect(['notification', 'whatsapp'], $channels)); @endphp
                        @if ($plan === [])
                            <p class="mt-2 text-sm text-ink-500">{{ __('messaging_composer.plan_pending') }}</p>
                        @elseif ($plan['overCap'])
                            <div class="mt-3 flex items-start gap-3 rounded-2xl border border-ruby-soft/30 bg-ruby-soft/10 p-4 text-sm text-ink-800 dark:text-sand-100" role="alert" data-testid="over-cap">
                                <x-icon name="alert" class="mt-0.5 size-5 shrink-0 text-ruby-soft" />
                                <p>{{ __('messaging_admin.err.too_many', ['max' => number_format($plan['max']), 'count' => number_format($plan['total'])]) }}</p>
                            </div>
                        @elseif ($single && is_array($plan['single'] ?? null))
                            <ul class="mt-3 space-y-2" data-testid="plan-single">
                                @foreach ($plan['single'] as $ch => $reason)
                                    <li class="flex items-start gap-3 rounded-2xl border p-3 text-sm {{ $reason ? 'border-amber-soft/30 bg-amber-soft/10' : 'border-emerald-soft/25 bg-emerald-soft/10' }}">
                                        <x-icon :name="$reason ? 'alert' : 'check'" class="mt-0.5 size-5 shrink-0 {{ $reason ? 'text-amber-soft' : 'text-emerald-soft dark:text-emerald-300' }}" />
                                        <span class="min-w-0"><span class="font-semibold text-ink-900 dark:text-ivory">{{ __('messaging_admin.channel.'.$ch) }}</span>
                                            <span class="block text-ink-600 dark:text-sand-200">{{ $reason ? Composer::reasonText($reason).($reason === 'recently_contacted' ? ' '.__('messaging_composer.within', ['hours' => $limits['cooldown_hours']]) : '') : __('messaging_composer.will_get', ['name' => \Illuminate\Support\Str::before(trim($singleName), ' ')]) }}</span></span>
                                    </li>
                                @endforeach
                            </ul>
                            @if (in_array('recently_contacted', $plan['single'], true) || $ignoreCooldown)
                                <label class="mt-3 flex min-h-11 cursor-pointer items-start gap-3 rounded-xl border border-sand-200 p-3 text-sm dark:border-ink-700">
                                    <input type="checkbox" wire:model.live="ignoreCooldown" class="checkbox mt-0.5 size-5" data-testid="send-anyway">
                                    <span><span class="block font-medium text-ink-900 dark:text-ivory">{{ __('messaging_composer.send_anyway') }}</span><span class="text-xs text-ink-500">{{ __('messaging_composer.send_anyway_hint') }}</span></span>
                                </label>
                            @endif
                        @else
                            <div class="mt-3 flex flex-wrap items-center gap-2" data-testid="plan-bulk">
                                <span class="badge-success !px-3 !py-1 !text-sm"><x-icon name="check" class="size-4" />{{ trans_choice('messaging_composer.will_receive', $plan['reachable'], ['count' => number_format($plan['reachable'])]) }}</span>
                                @if ($plan['skipped'] > 0)<span class="badge-warning !px-3 !py-1 !text-sm">{{ trans_choice('messaging_composer.skipped_n', $plan['skipped'], ['count' => number_format($plan['skipped'])]) }}</span>@endif
                            </div>
                            @if (count($channelsOn) > 1)
                                <p class="mt-2 text-xs text-ink-500 dark:text-ink-400">
                                    @foreach ($channelsOn as $ch){{ __('messaging_admin.channel.'.$ch) }}: {{ number_format($plan['channels'][$ch]['ok'] ?? 0) }}/{{ number_format($plan['total']) }}@unless ($loop->last) · @endunless @endforeach
                                </p>
                            @endif
                            @if (is_array($plan['phones'] ?? null) && array_sum($plan['phones']) > 0)
                                <div class="mt-3 rounded-2xl border border-sand-200/70 p-4 dark:border-ink-700" data-testid="plan-phones">
                                    <p class="text-xs font-semibold uppercase tracking-wider text-ink-500">{{ __('messaging_phones.bulk.title') }}</p>
                                    <ul class="mt-2 space-y-1.5 text-sm">
                                        @foreach (['can_show' => 'check', 'silent' => 'alert', 'none' => 'alert', 'unknown' => 'info'] as $key => $icon)
                                            @if (($plan['phones'][$key] ?? 0) > 0)
                                                <li class="flex items-start gap-2 text-ink-700 dark:text-sand-200" data-testid="plan-phones-{{ $key }}">
                                                    <x-icon :name="$icon" class="mt-0.5 size-4 shrink-0 {{ $key === 'can_show' ? 'text-emerald-soft dark:text-emerald-300' : ($key === 'unknown' ? 'text-ink-400' : 'text-amber-soft') }}" />
                                                    <span>{{ trans_choice('messaging_phones.bulk.'.$key, $plan['phones'][$key], ['count' => number_format($plan['phones'][$key])]) }}</span>
                                                </li>
                                            @endif
                                        @endforeach
                                    </ul>
                                    <p class="mt-2 text-xs text-ink-500 dark:text-ink-400">{{ __('messaging_phones.bulk.hint') }}</p>
                                </div>
                            @endif
                            @if (! empty($plan['reasons']))
                                <div class="mt-3 rounded-2xl border border-sand-200/70 p-4 dark:border-ink-700">
                                    <p class="text-xs font-semibold uppercase tracking-wider text-ink-500">{{ __('messaging_composer.why_skipped') }}</p>
                                    <ul class="mt-2 space-y-1.5 text-sm">
                                        @foreach ($plan['reasons'] as $reason => $n)
                                            <li class="flex items-start justify-between gap-3"><span class="text-ink-700 dark:text-sand-200">{{ Composer::reasonText($reason) }}@if ($reason === 'recently_contacted') <span class="text-ink-500">{{ __('messaging_composer.within', ['hours' => $limits['cooldown_hours']]) }}</span>@endif</span><span class="font-medium tabular-nums">{{ number_format($n) }}</span></li>
                                        @endforeach
                                    </ul>
                                    @if (! empty($plan['examples']))
                                        <p class="mt-3 text-xs text-ink-500">{{ __('messaging_composer.such_as', ['names' => collect($plan['examples'])->pluck('name')->take(4)->implode($rtl ? '، ' : ', ')]) }}</p>
                                    @endif
                                </div>
                            @endif
                        @endif
                        @if ($kind === 'reminder' && $plan !== [] && ! $plan['overCap'])
                            <p class="mt-3 flex items-start gap-2 text-xs text-ink-500 dark:text-ink-400"><x-icon name="info" class="mt-0.5 size-3.5 shrink-0" />{{ __('messaging_composer.reminder_note') }}</p>
                        @endif
                    </section>

                    {{-- ───────── When ───────── --}}
                    @if ($scheduling)
                        <section aria-labelledby="mc-when" data-no-dirty x-init="$nextTick(() => reveal($el, 'nearest'))" data-testid="schedule">
                            <h3 id="mc-when" class="flex items-center gap-2.5 font-display text-lg font-semibold text-ink-900 dark:text-ivory">
                                <span class="grid size-6 place-items-center rounded-full bg-ink-900 text-xs text-ivory dark:bg-brass-500 dark:text-ink-950" aria-hidden="true"><x-icon name="clock" class="size-3.5" /></span>{{ __('messaging_composer.when') }}
                            </h3>
                            <p class="mt-1 text-sm text-ink-500 dark:text-ink-400">{{ __('messaging_composer.when_hint', ['zone' => __('messaging_composer.zone')]) }}</p>
                            <div class="mt-3 flex flex-wrap gap-2">
                                <button type="button" wire:click="pickSchedule('hour')" class="btn-secondary min-h-11 !rounded-full">{{ __('messaging_composer.pick_hour') }}</button>
                                @if ($tonightOk)<button type="button" wire:click="pickSchedule('tonight')" class="btn-secondary min-h-11 !rounded-full">{{ __('messaging_composer.pick_tonight') }}</button>@endif
                                <button type="button" wire:click="pickSchedule('tomorrow')" class="btn-secondary min-h-11 !rounded-full">{{ __('messaging_composer.pick_tomorrow') }}</button>
                            </div>
                            <div class="mt-3 grid gap-3 sm:grid-cols-[minmax(0,16rem)_1fr] sm:items-end">
                                <div>
                                    <label class="field-label" for="mc-at">{{ __('messaging_composer.pick_custom') }}</label>
                                    <input id="mc-at" type="datetime-local" wire:model.live="scheduleAt" min="{{ $minAt }}" max="{{ $maxAt }}" class="input @error('scheduleAt') input-error @enderror" dir="ltr" data-testid="schedule-at">
                                </div>
                                @if ($scheduleHuman !== '')<p class="flex items-center gap-2 pb-2.5 text-sm font-medium text-ink-800 dark:text-sand-100"><x-icon name="calendar" class="size-4 text-brass-600" />{{ $scheduleHuman }} <span class="font-normal text-ink-500">· {{ __('messaging_composer.zone') }}</span></p>@endif
                            </div>
                            @error('scheduleAt')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                            <button type="button" wire:click="cancelSchedule" class="mt-2 inline-flex min-h-11 items-center text-sm font-medium text-brass-700 underline-offset-2 hover:underline dark:text-brass-300">{{ __('messaging_composer.send_now_instead') }}</button>
                        </section>
                    @endif

                    @if ($error !== '')
                        <div class="flex items-start gap-3 rounded-2xl border border-ruby-soft/30 bg-ruby-soft/10 p-4 text-sm text-ink-800 dark:text-sand-100" role="alert" data-testid="error"><x-icon name="alert" class="mt-0.5 size-5 shrink-0 text-ruby-soft" /><p>{{ $error }}</p></div>
                    @endif
                @endif
            @endif
        </div>

        <x-slot:footer>
            @if ($step === 'sent')
                <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                    @if ($historyUrl)<a href="{{ $historyUrl }}" wire:navigate class="btn-secondary min-h-11" data-testid="see-history"><x-icon name="history" class="size-4" />{{ __('messaging_composer.see_history') }}</a>@endif
                    <button type="button" class="btn-primary min-h-11" @click="requestClose()" x-init="$nextTick(() => $el.focus({ preventScroll: true }))" data-testid="done"><x-icon name="check" class="size-4" />{{ __('messaging_composer.done') }}</button>
                </div>
            @else
                <p x-show="note || summary" x-cloak class="mb-2.5 flex items-start gap-2 text-xs text-ink-500 dark:text-ink-400" role="status" data-testid="footer-note"><x-icon name="info" class="mt-0.5 size-3.5 shrink-0" /><span x-text="note || summary"></span></p>
                <div class="flex flex-wrap items-center gap-2">
                    <button type="button" wire:click="requestSend" wire:loading.attr="disabled" wire:target="requestSend,send" :disabled="blocked"
                            class="btn-primary min-h-11 basis-full justify-center sm:order-3 sm:basis-auto sm:min-w-44" data-testid="send">
                        <span wire:loading.remove wire:target="requestSend,send" class="inline-flex items-center gap-2"><x-icon :name="$scheduling ? 'clock' : 'send'" class="size-4 rtl-flip" /><span x-text="sendLabel"></span></span>
                        <span wire:loading wire:target="requestSend,send" class="inline-flex items-center gap-2"><x-icon name="refresh" class="size-4 animate-spin" />{{ __('messaging_composer.sending') }}</span>
                    </button>
                    @if (! $scheduling)
                        <button type="button" wire:click="startSchedule" :disabled="! $wire.templateId" class="btn-secondary min-h-11 flex-1 sm:order-2 sm:flex-none" data-testid="schedule-btn"><x-icon name="clock" class="size-4" />{{ __('messaging_composer.schedule') }}</button>
                    @endif
                    <button type="button" class="btn-ghost min-h-11 flex-1 sm:order-1 sm:me-auto sm:flex-none" @click="requestClose()">{{ __('admin.cancel') }}</button>
                </div>
            @endif
        </x-slot:footer>
    </x-admin.dialog>

    {{-- Asked before a send to several customers goes out --}}
    <x-admin.dialog wire:model="confirming" variant="modal" :title="__('messaging_composer.confirm_title')" max-width="max-w-md">
        @php $p = $this->plan; @endphp
        @if ($p !== [] && $step === 'compose')
            <div class="space-y-4 text-sm" data-testid="confirm">
                <p class="text-ink-700 dark:text-sand-200">
                    @if ($scheduling){{ __('messaging_composer.confirm_scheduled', ['count' => number_format($p['total']), 'time' => $scheduleHuman]) }}
                    @else{{ trans_choice('messaging_composer.confirm_now', $p['reachable'], ['count' => number_format($p['reachable'])]) }}@endif
                </p>
                <ul class="space-y-1.5 rounded-2xl bg-sand-50 p-4 dark:bg-ink-800/60">
                    <li class="flex justify-between gap-3"><span class="text-ink-500">{{ __('messaging_composer.confirm_how') }}</span><span class="font-medium">{{ collect($channels)->map(fn ($c) => __('messaging_admin.channel.'.$c))->implode(' + ') }}</span></li>
                    <li class="flex justify-between gap-3"><span class="text-ink-500">{{ __('messaging_composer.confirm_what') }}</span><span class="font-medium">{{ $templateId === 'custom' || $templateId === '' ? __('messaging_admin.custom_template') : ($templates->firstWhere('id', $templateId)?->name ?? __('messaging_admin.kind.'.$kind)) }}</span></li>
                    @if ($p['skipped'] > 0 && ! $scheduling)<li class="flex justify-between gap-3"><span class="text-ink-500">{{ __('messaging_composer.confirm_skipped') }}</span><span class="font-medium tabular-nums">{{ number_format($p['skipped']) }}</span></li>@endif
                </ul>
                <p class="text-xs text-ink-500">{{ $scheduling ? __('messaging_composer.confirm_scheduled_note') : __('messaging_composer.confirm_note') }}@if (in_array('whatsapp', $channels, true)) {{ __('messaging_composer.confirm_whatsapp', ['n' => $limits['whatsapp_per_minute']]) }}@endif</p>
            </div>
            <div class="mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <button type="button" class="btn-secondary min-h-11" data-cancel @click="requestClose()">{{ __('admin.cancel') }}</button>
                <button type="button" class="btn-primary min-h-11" wire:click="send" wire:loading.attr="disabled" wire:target="send" data-testid="confirm-send">
                    <span wire:loading.remove wire:target="send" class="inline-flex items-center gap-2"><x-icon :name="$scheduling ? 'clock' : 'send'" class="size-4 rtl-flip" />{{ $scheduling ? __('messaging_composer.confirm_schedule') : __('messaging_composer.confirm_send') }}</span>
                    <span wire:loading wire:target="send" class="inline-flex items-center gap-2"><x-icon name="refresh" class="size-4 animate-spin" />{{ __('messaging_composer.sending') }}</span>
                </button>
            </div>
        @endif
    </x-admin.dialog>
</div>

@script
<script>
    // Everything that must react at once as the person types lives here (no server round trip per keystroke): counters, the placeholder chips,
    // the live preview and the reasons the send button is not ready yet. The server re-checks every rule when the message is sent.
    Alpine.data('messageComposer', () => ({
        lang: 'en', active: '', edited: false, waOpen: false, t: {}, limits: {}, placeholders: [], locale: 'en', zone: 'Asia/Qatar',
        init() {
            const b = JSON.parse(this.$refs.boot.dataset.boot);
            Object.assign(this, { t: b.text, limits: b.limits, placeholders: b.placeholders, locale: b.locale, zone: b.zone });
            this.lang = this.$wire.defaultLang;
            this.$watch('$wire.open', (on) => { if (on) { this.lang = this.$wire.defaultLang; this.edited = false; this.waOpen = false; this.active = ''; } });
        },
        get c() { return this.$wire.content; },
        get push() { return this.$wire.channels.includes('notification'); },
        get wa() { return this.$wire.channels.includes('whatsapp'); },

        /* counters */
        len(s) { return [...(s || '')].length; },
        field(name, l) { return this.c ? this.c[name + '_' + l] : ''; },
        over(name, l) { return this.len(this.field(name, l)) > this.limits[name]; },
        near(name, l) { return this.len(this.field(name, l)) > this.limits[name] * 0.9; },
        counter(name, l) { return this.len(this.field(name, l)) + ' / ' + this.limits[name]; },
        waShown() { return this.waOpen || !!(this.field('whatsapp', 'en') || this.field('whatsapp', 'ar')); },

        /* placeholders */
        found(text) { return [...new Set([...(text || '').matchAll(/\{\{\s*([a-z0-9_]+)\s*\}\}/gi)].map((m) => m[1].toLowerCase()))]; },
        unknownList(text) { return this.found(text).filter((k) => !this.placeholders.includes(k)); },
        unknownIn(key) { return this.unknownList(this.c[key]).length > 0; },
        unknownText(key) { return this.t.unknown.replace(':list', this.unknownList(this.c[key]).map((k) => '{' + '{' + k + '}' + '}').join(', ')); },
        usesCoupon() { return ['title', 'body', 'whatsapp'].some((n) => ['en', 'ar'].some((l) => this.found(this.field(n, l)).includes('coupon'))); },
        insert(token) {
            const key = this.active && this.c[this.active] !== undefined && this.active.endsWith('_' + this.lang) ? this.active : 'body_' + this.lang;
            const el = document.getElementById('mc-' + key);
            if (!el) return;
            const value = this.c[key] || '';
            const start = el.selectionStart ?? value.length;
            const end = el.selectionEnd ?? start;
            this.$wire.$set('content.' + key, value.slice(0, start) + token + value.slice(end), false);
            this.$nextTick(() => {
                el.focus({ preventScroll: true });
                el.setSelectionRange(start + token.length, start + token.length);
                el.dispatchEvent(new Event('input', { bubbles: true })); // marks the window as having typed text
            });
        },

        /* preview */
        couponCode() { return ((this.c.coupon.prefix || 'BAHAR').toUpperCase().replace(/[^A-Z0-9]/g, '') || 'BAHAR') + '-7K2QXM'; },
        expires(l) {
            const hours = Math.max(1, Math.min(720, parseInt(this.c.coupon.valid_hours, 10) || 72));
            return new Intl.DateTimeFormat(l === 'ar' ? 'ar' : 'en-GB', { timeZone: this.zone, day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit', hour12: false }).format(new Date(Date.now() + hours * 3600000));
        },
        fill(text, l) {
            const v = { ...(this.$wire.vars[l] || {}), coupon: this.couponCode(), expires: this.expires(l) };
            return (text || '').replace(/\{\{\s*([a-z0-9_]+)\s*\}\}/gi, (m, k) => v[k.toLowerCase()] ?? '').trim();
        },
        get pv() {
            const l = this.lang;
            const title = this.fill(this.field('title', l), l);
            const body = this.fill(this.field('body', l), l);
            const own = this.fill(this.field('whatsapp', l), l);
            return { title, body, wa: own || [title, body].filter(Boolean).join('\n') };
        },

        /* what is missing before it can be sent */
        langName(l) { return this.t.lang_names[l]; },
        get couponIssue() {
            const k = this.c.coupon;
            if (this.usesCoupon() && !k.enabled) return this.t.coupon_unused;
            if (k.enabled && this.$wire.kind === 'offer' && !this.usesCoupon()) return this.t.coupon_not_shown;
            return '';
        },
        get issues() {
            const w = this.$wire, c = this.c, t = this.t, out = [];
            if (!w.templateId) return [{ text: t.pick_first }];
            if (!this.push && !this.wa) out.push({ text: t.pick_channel });
            for (const l of ['en', 'ar']) {
                const n = this.langName(l);
                if (this.push && !(c['title_' + l] || '').trim()) out.push({ lang: l, text: t.need_title.replace(':lang', n) });
                if ((this.push || this.wa) && !(c['body_' + l] || '').trim()) out.push({ lang: l, text: t.need_body.replace(':lang', n) });
                for (const f of ['title', 'body', 'whatsapp']) {
                    if (this.over(f, l)) out.push({ lang: l, text: t['too_long_' + f].replace(':lang', n) });
                    const bad = this.unknownList(c[f + '_' + l]);
                    if (bad.length) out.push({ lang: l, text: t.unknown.replace(':list', bad.map((k) => '{' + '{' + k + '}' + '}').join(', ')) });
                }
            }
            if (this.couponIssue) out.push({ text: this.couponIssue });
            if (c.coupon.enabled) {
                if (!c.coupon.promotion_id) out.push({ text: t.coupon_pick });
                if (!/^[A-Za-z0-9]{2,8}$/.test(c.coupon.prefix || '')) out.push({ text: t.coupon_prefix });
                const h = parseInt(c.coupon.valid_hours, 10);
                if (!(h >= 1 && h <= 720)) out.push({ text: t.coupon_hours });
            }
            if (w.scheduling && !w.scheduleAt) out.push({ text: t.pick_time });
            return out;
        },
        langHasIssue(l) { return this.issues.some((i) => i.lang === l); },
        get planBlock() {
            const p = this.$wire.plan;
            if (!p || !p.total) return '';
            if (p.overCap) return this.t.over_cap;
            if (!this.$wire.scheduling && p.reachable === 0) return this.t.nobody;
            return '';
        },
        get summary() {
            const p = this.$wire.plan;
            if (!p || !p.total || this.$wire.scheduling) return '';
            return p.total > 1 || p.reachable === 0 ? [this.t.sum_receive.replace(':count', p.reachable.toLocaleString('en')), p.skipped > 0 ? this.t.sum_skipped.replace(':count', p.skipped.toLocaleString('en')) : ''].filter(Boolean).join(' · ') : '';
        },
        get blocked() { return this.issues.length > 0 || this.planBlock !== ''; },
        get note() { return this.issues[0]?.text || this.planBlock || ''; },
        get sendLabel() {
            const p = this.$wire.plan, t = this.t, many = !!p && p.total > 1;
            if (this.$wire.scheduling) return many ? t.schedule_n.replace(':count', p.total.toLocaleString('en')) : t.schedule_one;
            return many ? t.send_n.replace(':count', Math.max(p.reachable, 0).toLocaleString('en')) : t.send_one;
        },

        /* a template card: asks first when typed text would be replaced */
        async pick(id) {
            if (this.$wire.templateId === id) return;
            if (this.edited && !(await window.Overlays.confirm({ question: this.t.replace_q, ok: this.t.replace_ok, cancel: this.t.replace_keep }))) return;
            await this.$wire.choose(id);
            this.edited = false;
            this.waOpen = false;
            setTimeout(() => {
                this.reveal(document.getElementById('mc-customise'), 'start');
                if (!window.matchMedia('(pointer: coarse)').matches) document.getElementById('mc-title_' + this.lang)?.focus({ preventScroll: true });
            }, 80);
        },
        /** Scrolls the window's own body (never the page or the window frame, which scrollIntoView would also move) so the section comes into view. */
        reveal(el, where = 'start') {
            const body = el?.closest('.drawer-body');
            if (!body) return;
            const box = body.getBoundingClientRect(), r = el.getBoundingClientRect();
            const top = where === 'start' ? r.top - box.top + body.scrollTop - 4 : r.bottom > box.bottom ? body.scrollTop + (r.bottom - box.bottom) + 16 : body.scrollTop;
            body.scrollTo({ top, behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
        },
    }));
</script>
@endscript
