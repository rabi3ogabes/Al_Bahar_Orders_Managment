<div>
    @if ($allowed)
        <button type="button" wire:click="open" wire:loading.attr="disabled" wire:target="open" class="btn-secondary min-h-11" data-testid="print-window-button"><x-icon name="printer" class="size-4" />{{ __('print_docs.ui.print_menu') }}</button>

        <x-admin.dialog wire:model="show" :title="__('print_docs.ui.window_title')" :description="__('print_docs.ui.window_hint')" max-width="max-w-lg">
            @if ($show)
                <div class="space-y-6 pb-6">
                    @if (count($kinds) > 1)
                        <fieldset>
                            <legend class="field-label">{{ __('print_docs.ui.what') }}</legend>
                            <div class="mt-1.5 grid grid-cols-2 gap-2">
                                @foreach ($kinds as $k)
                                    <label @class(['flex min-h-11 cursor-pointer items-center justify-center gap-2 rounded-xl border px-3 text-sm font-medium transition focus-within:ring-2 focus-within:ring-brass-500',
                                        'border-brass-500 bg-brass-50 text-ink-900 dark:bg-brass-500/15 dark:text-ivory' => $kind === $k,
                                        'border-sand-200 text-ink-600 hover:border-brass-400 dark:border-ink-700 dark:text-sand-200' => $kind !== $k])>
                                        <input type="radio" wire:model.live="kind" value="{{ $k }}" class="sr-only">
                                        <x-icon :name="$k === 'note' ? 'truck' : 'clipboard'" class="size-4" />{{ $k === 'note' ? __('print_docs.ui.delivery_note') : __('print_docs.ui.picking_list') }}
                                    </label>
                                @endforeach
                            </div>
                        </fieldset>
                    @endif

                    <div class="space-y-4">
                        <h3 class="field-label">{{ __('print_docs.ui.which') }}</h3>
                        @if ($kind === 'note')
                            <p class="text-sm text-ink-500">{{ __('print_docs.ui.for_day_hint') }}</p>
                            <x-admin.field :label="__('print_docs.ui.date')" for="print-date"><input id="print-date" type="date" wire:model.live="date" class="input" dir="ltr"></x-admin.field>
                            <x-admin.field :label="__('print_docs.ui.driver')" for="print-driver">
                                <select id="print-driver" wire:model.live="driver" class="input">
                                    <option value="">{{ __('print_docs.ui.all_drivers') }}</option>
                                    @foreach ($drivers as $d)
                                        <option value="{{ $d['id'] }}">{{ __('print_docs.ui.driver_count', ['name' => $d['name'] ?? __('print_docs.ui.unassigned'), 'n' => $d['count']]) }}</option>
                                    @endforeach
                                </select>
                            </x-admin.field>
                        @else
                            <p class="text-sm text-ink-600 dark:text-sand-200"><strong class="font-medium">{{ __('print_docs.ui.for_prepare') }}</strong><span class="mt-0.5 block text-xs text-ink-500">{{ __('print_docs.ui.for_prepare_hint') }}</span></p>
                        @endif
                        @if ($branches->count() > 1)
                            <x-admin.field :label="__('print_docs.ui.branch')" for="print-branch">
                                <select id="print-branch" wire:model.live="branch" class="input">
                                    <option value="">{{ __('print_docs.ui.all_branches') }}</option>
                                    @foreach ($branches as $b)<option value="{{ $b->id }}">{{ $b->code }} · {{ $b->localized('name') }}</option>@endforeach
                                </select>
                            </x-admin.field>
                        @endif
                    </div>

                    <div class="rounded-2xl border border-sand-200 bg-white p-4 dark:border-ink-700 dark:bg-ink-900" aria-live="polite" data-testid="print-summary">
                        <p class="font-display text-2xl font-semibold tabular-nums">{{ trans_choice('print_docs.ui.parts_n', $total, ['count' => $total]) }}</p>
                        @if ($total === 0)
                            <p class="mt-1 text-sm text-ink-500">{{ __('print_docs.ui.nothing') }}</p>
                        @elseif ($capped)
                            <p class="mt-1 flex items-start gap-2 text-xs text-amber-soft"><x-icon name="info" class="mt-px size-3.5 shrink-0" />{{ __('print_docs.ui.capped', ['max' => $max, 'total' => $total]) }}</p>
                        @endif
                    </div>
                </div>

                <x-slot:footer>
                    <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                        <button type="button" class="btn-secondary min-h-11" @click="requestClose()">{{ __('print_docs.ui.close') }}</button>
                        @if ($url)
                            <a href="{{ $url }}" target="_blank" rel="noopener" class="btn-primary min-h-11" data-testid="print-open-pdf"><x-icon name="printer" class="size-4" />{{ __('print_docs.ui.open_pdf') }}</a>
                        @else
                            <span class="btn-primary min-h-11 cursor-not-allowed opacity-50" aria-disabled="true"><x-icon name="printer" class="size-4" />{{ __('print_docs.ui.open_pdf') }}</span>
                        @endif
                    </div>
                </x-slot:footer>
            @endif
        </x-admin.dialog>
    @endif
</div>
