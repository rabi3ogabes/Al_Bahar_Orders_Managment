<div>
    @if ($note || $assembly || $canOffer)
        <div class="space-y-3 border-t border-sand-100 px-5 py-4 text-sm sm:px-6 dark:border-ink-800" data-follow-up="{{ $f->id }}">
            @if ($note || $canOffer)
                <div class="flex flex-wrap items-center gap-3" data-offer-state="{{ $note['state'] ?? 'none' }}">
                    <span @class(['grid size-9 shrink-0 place-items-center rounded-xl', 'bg-emerald-soft/10 text-emerald-soft' => ($note['state'] ?? '') === 'chosen', 'bg-amber-soft/10 text-amber-soft' => in_array($note['state'] ?? '', ['waiting', 'expired', 'none'], true), 'bg-sand-100 text-ink-500 dark:bg-ink-800' => $note === null])>
                        <x-icon :name="($note['state'] ?? '') === 'chosen' ? 'check' : (($note['state'] ?? '') === 'waiting' ? 'clock' : 'calendar')" class="size-4" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="font-medium">{{ __('delivery_followup.ui.times_title') }}</p>
                        <p class="text-xs text-ink-500" role="status">{{ $note['text'] ?? ($enabled ? __('delivery_followup.ui.no_offer_yet') : __('delivery_followup.ui.offers_off')) }}</p>
                    </div>
                    @if ($canOffer)
                        <button type="button" wire:click="openOffer" wire:loading.attr="disabled" wire:target="openOffer" class="btn-secondary btn-sm min-h-11"><x-icon name="calendar" class="size-4" />{{ __('delivery_followup.ui.offer_button') }}</button>
                    @endif
                </div>
            @endif

            @if ($assembly)
                <div class="flex flex-wrap items-center gap-3" data-assembly-state="{{ $assembly['status'] }}">
                    <span @class(['grid size-9 shrink-0 place-items-center rounded-xl', 'bg-emerald-soft/10 text-emerald-soft' => $assembly['status'] === 'done', 'bg-ruby-soft/10 text-ruby-soft' => $assembly['status'] === 'not_done', 'bg-sand-100 text-ink-500 dark:bg-ink-800' => $assembly['status'] === 'pending'])>
                        <x-icon :name="$assembly['status'] === 'done' ? 'check' : ($assembly['status'] === 'not_done' ? 'alert' : 'package')" class="size-4" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="font-medium">{{ __('delivery_followup.ui.assembly_title') }}</p>
                        <p class="text-xs text-ink-500" role="status">
                            @if ($assembly['status'] === 'done')
                                {{ __('delivery_followup.ui.assembly_done', ['at' => \App\Support\BusinessTime::display($assembly['done_at'], 'D j M, H:i')]) }}
                            @elseif ($assembly['status'] === 'not_done')
                                {{ __('delivery_followup.ui.assembly_not_done', ['reason' => $assembly['reason_label']]) }}@if ($assembly['note']) · <span dir="auto">{{ $assembly['note'] }}</span>@endif
                                @if ($assembly['handled_at']) · {{ __('delivery_followup.ui.assembly_handled_at', ['at' => \App\Support\BusinessTime::display($assembly['handled_at'], 'D j M, H:i')]) }}@else · {{ __('delivery_followup.ui.assembly_refund_hint') }}@endif
                            @else
                                {{ __('delivery_followup.ui.assembly_pending') }}
                            @endif
                        </p>
                    </div>
                    @if ($assembly['status'] === 'not_done' && ! $assembly['handled_at'] && $canAssign)
                        <button type="button" wire:click="handleAssembly" wire:loading.attr="disabled" wire:target="handleAssembly" class="btn-secondary btn-sm min-h-11"><x-icon name="check" class="size-4" />{{ __('delivery_followup.ui.assembly_handle') }}</button>
                    @endif
                </div>
            @endif
        </div>

        @if ($canOffer)
            <x-admin.dialog wire:model="showOffer" :variant="$drawer ? 'modal' : 'drawer'" max-width="max-w-lg" :title="__('delivery_followup.ui.offer_title')" :description="__('delivery_followup.ui.offer_hint', ['hours' => $hours])">
                <form wire:submit="sendOffer" class="space-y-5" novalidate>
                    @forelse ($days as $day)
                        <fieldset wire:key="offer-day-{{ $day['date'] }}" class="space-y-2">
                            <legend class="text-sm font-medium">{{ $day['label'] }}</legend>
                            <div class="flex flex-wrap gap-2">
                                @foreach ($day['slots'] as $slot)
                                    @php $key = $slot['id'].'|'.$day['date']; $on = in_array($key, $picked, true); @endphp
                                    <button type="button" wire:click="toggle('{{ $key }}')" wire:key="offer-slot-{{ $key }}" aria-pressed="{{ $on ? 'true' : 'false' }}"
                                            @class(['min-h-11 rounded-xl border px-3 py-2 text-sm tabular-nums transition focus-visible:outline-2 focus-visible:outline-brass-500', 'border-brass-500 bg-brass-50 font-medium dark:bg-brass-500/10' => $on, 'border-sand-200 dark:border-ink-700' => ! $on])>
                                        <span dir="ltr">{{ $slot['starts_at'] }}–{{ $slot['ends_at'] }}</span><span class="ms-2 text-xs text-ink-500">{{ __('delivery_followup.ui.places_left', ['n' => $slot['available']]) }}</span>
                                    </button>
                                @endforeach
                            </div>
                        </fieldset>
                    @empty
                        <p class="rounded-xl border border-dashed border-sand-300 p-4 text-sm text-ink-500 dark:border-ink-700">{{ __('delivery_followup.ui.no_free_times') }}</p>
                    @endforelse
                    <p class="text-xs text-ink-500" role="status">{{ trans_choice('delivery_followup.ui.picked_n', count($picked), ['count' => count($picked), 'max' => \App\Domain\Fulfillment\Services\SlotOffers::MAX_MANUAL]) }}</p>
                    <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                        <button type="button" class="btn-secondary min-h-11" @click="open = false">{{ __('admin.back') }}</button>
                        <button class="btn-primary min-h-11" wire:loading.attr="disabled" wire:target="sendOffer" @disabled($picked === [])><x-icon name="send" class="size-4" />{{ __('delivery_followup.ui.offer_send') }}</button>
                    </div>
                </form>
            </x-admin.dialog>
        @endif
    @endif
</div>
