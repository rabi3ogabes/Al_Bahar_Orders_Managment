@php
    use Illuminate\Support\Facades\Lang;
    $channelName = fn (string $ch) => in_array($ch, ['notification', 'whatsapp'], true) ? __('messaging_admin.channel.'.$ch)
        : (Lang::has('content_admin.ch_'.$ch) ? __('content_admin.ch_'.$ch) : \Illuminate\Support\Str::headline($ch));
@endphp
<section class="card overflow-hidden" aria-label="{{ __('messaging_history.tl_title') }}">
    <div class="border-b border-sand-100 p-5 sm:px-6 dark:border-ink-800">
        <h2 class="font-display text-xl font-semibold text-ink-900 dark:text-ivory">{{ __('messaging_history.tl_title') }}</h2>
        <p class="mt-1 text-sm text-ink-500 dark:text-ink-400">{{ __('messaging_history.tl_hint') }}</p>
        @if ($entries->isNotEmpty())
            <p class="mt-3 inline-flex flex-wrap items-center gap-2 rounded-xl bg-sand-50 px-3 py-2 text-sm text-ink-700 dark:bg-ink-800/60 dark:text-sand-200">
                @if ($last)
                    <x-icon name="check" class="size-4 text-emerald-soft" />
                    <span>{{ __('messaging_history.tl_last', ['when' => $last['at']->locale(app()->getLocale())->diffForHumans(), 'channel' => $channelName($last['channel'])]) }}</span>
                @elseif (! $more)
                    <x-icon name="info" class="size-4 text-ink-400" />
                    <span>{{ __('messaging_history.tl_none_reached') }}</span>
                @endif
            </p>
        @endif
    </div>

    @if ($entries->isEmpty())
        <x-admin.empty :title="__('messaging_history.tl_empty_title')" :description="__('messaging_history.tl_empty_hint')" icon="message" class="!py-12" />
    @else
        <ol class="divide-y divide-sand-100 dark:divide-ink-800">
            @foreach ($entries as $e)
                @php
                    $manual = $e['source'] === 'manual';
                    $title = $manual ? ($e['template'] ?: __('messaging_admin.custom_template')) : (Lang::has('journeys_admin.name_'.($e['journey'] ?? '')) ? __('journeys_admin.name_'.$e['journey']) : __('messaging_history.tl_automatic'));
                    $long = $e['body'] && (mb_strlen($e['body']) > 110 || substr_count($e['body'], "\n") > 1);
                @endphp
                <li wire:key="tl-{{ $e['key'] }}" x-data="{ open: false }" class="flex gap-3 px-5 py-4 sm:px-6">
                    <span class="mt-0.5 grid size-9 shrink-0 place-items-center rounded-full bg-sand-100 dark:bg-ink-800"><x-admin.messaging.channel :channel="$e['channel']" :label="false" size="size-4" /></span>
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                            <span class="min-w-0 break-words font-medium text-ink-900 dark:text-ivory">{{ $title }}</span>
                            @if ($e['kind'] && Lang::has('messaging_admin.kind.'.$e['kind']))<span class="badge-brass">{{ __('messaging_admin.kind.'.$e['kind']) }}</span>@endif
                            <x-admin.messaging.status :value="$e['status']" />
                        </div>
                        <p class="mt-1 flex flex-wrap items-center gap-x-1.5 text-xs text-ink-500 dark:text-ink-400">
                            <x-admin.messaging.ago :at="$e['at']" class="font-medium text-ink-700 dark:text-sand-200" />
                            <span aria-hidden="true">·</span><span class="tabular-nums">{{ \App\Support\BusinessTime::display($e['at']) }}</span>
                            <span aria-hidden="true">·</span><span>{{ $channelName($e['channel']) }}</span>
                            <span aria-hidden="true">·</span><span>{{ $manual ? ($e['staff'] ? __('messaging_history.tl_by', ['name' => $e['staff']]) : __('messaging_history.former_staff')) : __('messaging_history.tl_automatic') }}</span>
                        </p>
                        @if ($e['reason'])
                            <p @class(['mt-1.5 text-xs font-medium', 'text-ruby-soft' => $e['status'] === 'failed', 'text-ink-500 dark:text-ink-400' => $e['status'] !== 'failed'])><x-admin.messaging.reason :reason="$e['reason']" /></p>
                        @endif
                        @if ($e['body'])
                            <div class="mt-2 rounded-xl bg-sand-50 p-3 text-sm text-ink-700 dark:bg-ink-800/60 dark:text-sand-200" dir="auto">
                                @if ($e['title'])<p class="font-semibold text-ink-900 dark:text-ivory">{{ $e['title'] }}</p>@endif
                                <p class="whitespace-pre-line break-words" :class="open || 'line-clamp-2'">{{ $e['body'] }}</p>
                                @if ($long)<button type="button" @click="open = ! open" :aria-expanded="open" class="mt-1.5 text-xs font-medium text-brass-600 underline-offset-2 hover:underline dark:text-brass-300"><span x-show="! open">{{ __('messaging_history.tl_expand') }}</span><span x-show="open" x-cloak>{{ __('messaging_history.tl_collapse') }}</span></button>@endif
                            </div>
                        @elseif (! $manual)
                            <p class="mt-1.5 text-xs text-ink-500 dark:text-ink-400">{{ __('messaging_history.tl_no_text') }}</p>
                        @endif
                        <div class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1">
                            @if ($e['coupon'])<span class="badge-brass font-mono" dir="ltr" title="{{ __('messaging_history.tl_coupon') }}">{{ $e['coupon'] }}</span>@endif
                            @if ($e['batch_id'] && isset($openable[$e['batch_id']]))
                                <a href="{{ route('admin.messages.index', ['view' => $e['batch_id']]) }}" wire:navigate class="inline-flex min-h-8 items-center gap-1 text-xs font-medium text-brass-600 hover:underline dark:text-brass-300">{{ __('messaging_history.tl_open') }}<x-icon name="arrow-right" class="size-3.5 rtl-flip" /></a>
                            @endif
                        </div>
                    </div>
                </li>
            @endforeach
        </ol>
        @if ($more)
            <div class="border-t border-sand-100 p-4 text-center dark:border-ink-800">
                <button type="button" wire:click="showMore" wire:loading.attr="disabled" wire:target="showMore" class="btn-secondary min-h-11"><x-icon name="chevron-down" class="size-4" wire:loading.remove wire:target="showMore" /><x-icon name="refresh" class="size-4 animate-spin" wire:loading wire:target="showMore" />{{ __('messaging_history.tl_more') }}</button>
            </div>
        @endif
    @endif
</section>
