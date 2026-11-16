{{-- Send a real test push to one registered phone, with Firebase's answer in plain words. --}}
<section class="card card-pad" aria-labelledby="push-phone-test" wire:key="push-phone-test">
    <h2 id="push-phone-test" class="font-display text-xl font-semibold">{{ __('push_health.test.section') }}</h2>
    <p class="mt-1 text-sm text-ink-500">{{ __('push_health.test.hint') }}</p>

    @if ($phoneTargets->isEmpty())
        <p class="mt-4 flex items-start gap-2 rounded-xl bg-sand-50 p-3 text-sm text-ink-600 dark:bg-ink-800/50 dark:text-ink-300"><x-icon name="info" class="mt-0.5 size-4 shrink-0" />{{ __('push_health.test.no_devices') }}</p>
    @elseif ($canEdit)
        @php
            $mineCount = $phoneTargets->where('user_id', auth()->id())->count();
            $others = $phoneTargets->where('user_id', '!=', auth()->id());
            $picked = $phoneTargets->firstWhere('id', $phoneTarget);
            $pickedHasCard = $phoneTarget === 'mine' ? $phoneTargets->where('user_id', auth()->id())->contains(fn ($d) => \App\Domain\Notifications\Services\PushTester::hasCard($d)) : ($picked && \App\Domain\Notifications\Services\PushTester::hasCard($picked));
        @endphp
        <div class="mt-4 grid gap-4 sm:grid-cols-[1fr_auto] sm:items-end">
            <x-admin.field :label="__('push_health.test.target')" for="phoneTarget">
                <select id="phoneTarget" wire:model.live="phoneTarget" class="input">
                    <option value="mine">{{ $mineCount > 0 ? __('push_health.test.mine', ['n' => $mineCount]) : __('push_health.test.mine_none') }}</option>
                    @if ($others->isNotEmpty())
                        <optgroup label="{{ __('push_health.test.others') }}">
                            @foreach ($others as $d)<option value="{{ $d->id }}">{{ \App\Domain\Notifications\Services\PushTester::label($d) }}</option>@endforeach
                        </optgroup>
                    @endif
                </select>
            </x-admin.field>
            <button type="button" class="btn-primary min-h-11" wire:click="sendPhoneTest" wire:loading.attr="disabled" wire:target="sendPhoneTest">
                <x-icon name="zap" class="size-4" /><span wire:loading.remove wire:target="sendPhoneTest">{{ __('push_health.test.send') }}</span><span wire:loading wire:target="sendPhoneTest">{{ __('push_health.test.sending') }}</span>
            </button>
        </div>
        @if ($pickedHasCard)
            <label class="mt-3 flex min-h-11 cursor-pointer items-center gap-3 text-sm"><input type="checkbox" wire:model.live="phoneCard" class="size-4 rounded border-sand-300 text-brass-600 focus:ring-brass-500/30"><span>{{ __('push_health.test.also_card') }}</span></label>
        @endif

    @else
        <p class="mt-4 text-sm text-ink-500">{{ __('push_admin.edit_denied') }}</p>
    @endif

    @if ($phoneResults)
        <div class="mt-5" role="status" aria-live="polite">
            <h3 class="text-sm font-semibold">{{ __('push_health.test.results') }}</h3>
            <ul class="mt-2 divide-y divide-sand-100 rounded-xl border border-sand-100 dark:divide-ink-800 dark:border-ink-800">
                @foreach ($phoneResults as $r)
                    <li class="space-y-1.5 px-3 py-3 text-sm" wire:key="pt-{{ $loop->index }}">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <span class="min-w-0 break-words font-medium">{{ $r['label'] }}</span>
                            <span class="shrink-0 {{ $r['outcome'] === 'sent' ? 'badge-success' : ($r['outcome'] === 'removed' ? 'badge-warning' : 'badge-danger') }}">{{ __('push_health.test.outcome.'.$r['outcome']) }}</span>
                        </div>
                        <p class="leading-relaxed text-ink-600 dark:text-ink-300">{{ __('push_health.fcm.'.$r['explain']) }}</p>
                        @if ($r['status'] || $r['code'])<p class="font-mono text-xs text-ink-500" dir="ltr">{{ __('push_health.test.technical', ['status' => $r['status'], 'code' => $r['code'] ?? '']) }}</p>@endif
                        @if ($r['card'])
                            <p class="text-xs {{ $r['card']['ok'] ? 'text-emerald-soft dark:text-emerald-300' : 'text-ruby-soft' }}">{{ $r['card']['ok'] ? __('push_health.test.card_ok') : __('push_health.test.card_failed', ['status' => $r['card']['status'], 'code' => $r['card']['code'] ?? '']) }}</p>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</section>
