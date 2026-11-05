<div class="mx-auto max-w-7xl">
    <a href="{{ route('admin.auctions.index') }}" wire:navigate class="mb-4 inline-flex items-center gap-1.5 text-sm text-ink-500 hover:text-brass-600"><x-icon name="chevron-left" class="size-4 rtl-flip" />{{ __('nav.auctions') }}</a>
    <div class="mb-8 flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <div class="min-w-0">
            <p class="eyebrow mb-2">{{ $a ? $a->reference : __('auction_admin.eyebrow') }}</p>
            <h1 class="truncate font-display text-3xl font-semibold tracking-tight text-ink-900 sm:text-4xl dark:text-ivory">{{ $a ? ($a->trans('title') ?: $a->reference) : __('auction_admin.new_auction') }}</h1>
            @if ($a)<div class="mt-3 flex flex-wrap items-center gap-2"><x-admin.status :value="$a->status" /><span class="badge-neutral">{{ __('auction_admin.type_'.$a->type) }}</span>@if ($a->published_at)<span class="text-xs text-ink-500">{{ __('auction_admin.published_at', ['at' => \App\Support\BusinessTime::display($a->published_at)]) }}</span>@endif</div>@endif
        </div>
        @if ($a)
            <div class="flex flex-wrap gap-2">
                @if ($canCancel)<button type="button" wire:click="$set('showCancel', true)" class="btn-ghost text-ruby-soft"><x-icon name="ban" class="size-4" />{{ __('auction_admin.cancel_auction') }}</button>@endif
                @if ($canPublish)<button type="button" wire:click="publish" wire:confirm="{{ __('auction_admin.confirm_publish') }}" class="btn-brass"><x-icon name="sparkles" class="size-4" />{{ __('auction_admin.publish') }}</button>@endif
            </div>
        @endif
    </div>

    <div class="grid gap-6 lg:grid-cols-3 [&>*]:min-w-0">
        <form wire:submit="save" class="min-w-0 space-y-6 lg:col-span-2">
            <fieldset @disabled(! $editable) class="space-y-6">
                <section class="card card-pad space-y-5" x-data="{ lang: 'en' }">
                    <div class="flex items-center justify-between gap-3">
                        <h2 class="font-display text-xl font-semibold">{{ __('auction_admin.content') }}</h2>
                        <div class="flex rounded-xl border border-sand-200 p-0.5 text-xs dark:border-ink-700" role="tablist">
                            <button type="button" @click="lang = 'en'" :class="lang === 'en' ? 'bg-ink-900 text-ivory dark:bg-brass-500 dark:text-ink-950' : 'text-ink-600'" class="rounded-lg px-3 py-1 font-medium">English</button>
                            <button type="button" @click="lang = 'ar'" :class="lang === 'ar' ? 'bg-ink-900 text-ivory dark:bg-brass-500 dark:text-ink-950' : 'text-ink-600'" class="rounded-lg px-3 py-1 font-medium">العربية</button>
                        </div>
                    </div>
                    @foreach (['en' => 'ltr', 'ar' => 'rtl'] as $l => $dir)
                        <div x-show="lang === '{{ $l }}'" @if ($l === 'ar') x-cloak @endif class="space-y-4" dir="{{ $dir }}">
                            <x-admin.field :label="__('auction_admin.title_'.$l)" for="tr.{{ $l }}.title" required><input id="tr.{{ $l }}.title" wire:model="tr.{{ $l }}.title" class="input"></x-admin.field>
                            <x-admin.field :label="__('auction_admin.description')" for="tr.{{ $l }}.description"><textarea id="tr.{{ $l }}.description" wire:model="tr.{{ $l }}.description" rows="3" class="input"></textarea></x-admin.field>
                            <x-admin.field :label="__('auction_admin.terms')" for="tr.{{ $l }}.terms" :hint="__('auction_admin.terms_hint')"><textarea id="tr.{{ $l }}.terms" wire:model="tr.{{ $l }}.terms" rows="4" class="input"></textarea></x-admin.field>
                        </div>
                    @endforeach
                    @if ($errors->has('tr.ar.title') || $errors->has('tr.en.title'))<p class="field-error">{{ __('auction_admin.both_titles_required') }}</p>@endif
                </section>

                <section class="card card-pad space-y-5">
                    <h2 class="font-display text-xl font-semibold">{{ __('auction_admin.schedule') }}</h2>
                    <div class="grid grid-cols-2 gap-2">
                        @foreach (['scheduled' => 'calendar', 'live' => 'gavel'] as $t => $icon)
                            <label class="flex cursor-pointer items-start gap-3 rounded-xl border p-3 text-sm transition {{ ($form['type'] ?? '') === $t ? 'border-brass-500 bg-brass-50 dark:bg-brass-500/10' : 'border-sand-200 dark:border-ink-700' }}">
                                <input type="radio" wire:model.live="form.type" value="{{ $t }}" class="sr-only"><x-icon :name="$icon" class="mt-0.5 size-4 text-brass-600" />
                                <span><span class="block font-medium">{{ __('auction_admin.type_'.$t) }}</span><span class="block text-xs text-ink-500">{{ __('auction_admin.type_'.$t.'_hint') }}</span></span>
                            </label>
                        @endforeach
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2 [&>*]:min-w-0">
                        <x-admin.field :label="__('auction_admin.starts_at')" for="form.starts_at"><input id="form.starts_at" type="datetime-local" wire:model="form.starts_at" class="input"></x-admin.field>
                        <x-admin.field :label="__('auction_admin.ends_at')" for="form.ends_at"><input id="form.ends_at" type="datetime-local" wire:model="form.ends_at" class="input"></x-admin.field>
                    </div>
                    <p class="text-xs text-ink-500"><x-icon name="clock" class="me-1 inline size-3.5" />{{ __('auction_admin.qatar_time') }}</p>
                </section>

                <section class="card card-pad space-y-5">
                    <h2 class="font-display text-xl font-semibold">{{ __('auction_admin.rules') }}</h2>
                    <div class="grid gap-4 sm:grid-cols-3 [&>*]:min-w-0">
                        <x-admin.field :label="__('auction_admin.anti_snipe_window')" for="form.extension_window_seconds" :hint="__('auction_admin.seconds')"><input id="form.extension_window_seconds" type="number" min="0" wire:model="form.extension_window_seconds" class="input tabular-nums"></x-admin.field>
                        <x-admin.field :label="__('auction_admin.extend_by')" for="form.extension_seconds" :hint="__('auction_admin.seconds')"><input id="form.extension_seconds" type="number" min="0" wire:model="form.extension_seconds" class="input tabular-nums"></x-admin.field>
                        <x-admin.field :label="__('auction_admin.max_extensions')" for="form.max_extensions" :hint="__('auction_admin.blank_unlimited')"><input id="form.max_extensions" type="number" min="0" wire:model="form.max_extensions" class="input tabular-nums"></x-admin.field>
                        <x-admin.field :label="__('auction_admin.deposit')" for="form.deposit_amount" :hint="__('auction_admin.deposit_hint')"><input id="form.deposit_amount" type="number" step="0.01" min="0" wire:model="form.deposit_amount" class="input tabular-nums" dir="ltr"></x-admin.field>
                        <x-admin.field :label="__('auction_admin.payment_hours')" for="form.winner_payment_hours" required><input id="form.winner_payment_hours" type="number" min="1" wire:model="form.winner_payment_hours" class="input tabular-nums"></x-admin.field>
                        <x-admin.field :label="__('auction_admin.unpaid_policy')" for="form.unpaid_winner_policy">
                            <select id="form.unpaid_winner_policy" wire:model="form.unpaid_winner_policy" class="input">@foreach (['offer_next_bidder', 'relist', 'none'] as $p)<option value="{{ $p }}">{{ __('auction_admin.policy_'.$p) }}</option>@endforeach</select>
                        </x-admin.field>
                    </div>
                    <div class="grid gap-2 sm:grid-cols-2">
                        <x-admin.toggle :label="__('auction_admin.requires_registration')" wire:model="form.requires_registration" />
                        <x-admin.toggle :label="__('auction_admin.requires_verified_phone')" wire:model="form.requires_verified_phone" />
                        <x-admin.toggle :label="__('auction_admin.pickup_allowed')" wire:model="form.pickup_allowed" />
                        <x-admin.toggle :label="__('auction_admin.delivery_allowed')" wire:model="form.delivery_allowed" />
                        <x-admin.toggle :label="__('auction_admin.cod_allowed')" wire:model="form.cod_allowed" />
                        <x-admin.toggle :label="__('auction_admin.show_bidder_names')" :hint="__('auction_admin.show_bidder_names_hint')" wire:model="form.show_bidder_names" />
                    </div>
                    <div>
                        <span class="field-label">{{ __('auction_admin.eligible_branches') }}</span>
                        <div class="flex flex-wrap gap-2">
                            @foreach ($branches as $b)
                                <label class="inline-flex cursor-pointer items-center gap-2 rounded-full border px-3 py-1.5 text-sm {{ in_array($b->id, $branchIds, true) ? 'border-brass-500 bg-brass-50 dark:bg-brass-500/10' : 'border-sand-200 dark:border-ink-700' }}">
                                    <input type="checkbox" wire:model.live="branchIds" value="{{ $b->id }}" class="checkbox">{{ $b->localized('name') }}
                                </label>
                            @endforeach
                        </div>
                        <p class="field-hint">{{ __('auction_admin.eligible_branches_hint') }}</p>
                    </div>
                </section>
            </fieldset>
            @if ($editable)
                <div class="flex justify-end"><button class="btn-primary" wire:loading.attr="disabled" wire:target="save"><x-icon name="check" class="size-4" />{{ $a ? __('admin.save_changes') : __('auction_admin.create_draft') }}</button></div>
            @elseif ($a)
                <p class="text-sm text-ink-500"><x-icon name="lock" class="me-1 inline size-4" />{{ __('auction_admin.locked_hint') }}</p>
            @endif
        </form>

        <aside class="min-w-0 space-y-6">
            <section class="card overflow-hidden">
                <div class="flex items-center justify-between gap-3 p-5">
                    <h2 class="font-display text-xl font-semibold">{{ __('auction_admin.lots') }}</h2>
                    @if ($a && $editable)<button type="button" wire:click="newLot" class="btn-secondary btn-sm"><x-icon name="plus" class="size-4" />{{ __('auction_admin.add_lot') }}</button>@endif
                </div>
                @if (! $a)
                    <p class="border-t border-sand-100 p-5 text-sm text-ink-500 dark:border-ink-800">{{ __('auction_admin.save_first') }}</p>
                @elseif ($a->lots->isEmpty())
                    <x-admin.empty :title="__('auction_admin.no_lots')" :description="__('auction_admin.no_lots_hint')" icon="gavel" class="border-t border-sand-100 !py-10 dark:border-ink-800" />
                @else
                    <ul class="divide-y divide-sand-100 border-t border-sand-100 dark:divide-ink-800 dark:border-ink-800">
                        @foreach ($a->lots as $l)
                            @php $winner = $l->winners->first(); @endphp
                            <li class="flex items-center gap-3 p-4" wire:key="lot-{{ $l->id }}">
                                <span class="grid size-9 shrink-0 place-items-center rounded-lg bg-sand-100 font-display font-semibold text-ink-700 dark:bg-ink-800 dark:text-sand-100">{{ $l->lot_number }}</span>
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-sm font-medium text-ink-900 dark:text-ivory">{{ $l->localized('title') }}</p>
                                    <p class="text-xs text-ink-500"><x-admin.money :value="$l->current_price ?? $l->starting_price" /> · {{ trans_choice('auction_admin.n_bids', $l->bid_count, ['count' => $l->bid_count]) }}@if ($l->products->isNotEmpty()) · <x-icon name="boxes" class="inline size-3" />@endif</p>
                                </div>
                                <div class="flex flex-col items-end gap-1">
                                    <x-admin.status :value="$l->status" />
                                    @if ($winner)<x-admin.status :value="$winner->status" class="!text-[0.65rem]" />@endif
                                </div>
                                <div class="flex items-center">
                                    @if ($canEditLots && in_array($l->status, ['pending', 'open', 'paused'], true))
                                        <button type="button" wire:click="editLot('{{ $l->id }}')" class="btn-ghost btn-sm px-2" aria-label="{{ __('auction_admin.edit_lot') }}" title="{{ __('auction_admin.edit_lot') }}"><x-icon name="edit" class="size-4" /></button>
                                    @endif
                                    @if ($canDeleteLots && $l->status === 'pending' && $l->bid_count === 0)
                                        <button type="button" wire:click="deleteLot('{{ $l->id }}')" wire:confirm="{{ __('auction_admin.confirm_delete_lot') }}" class="btn-ghost btn-sm px-2 text-ruby-soft" aria-label="{{ __('auction_admin.delete_lot') }}" title="{{ __('auction_admin.delete_lot') }}"><x-icon name="trash" class="size-4" /></button>
                                    @endif
                                    <a href="{{ route('admin.auctions.console', $l) }}" wire:navigate class="btn-ghost btn-sm px-2" aria-label="{{ __('auction_admin.open_console') }}" title="{{ __('auction_admin.open_console') }}"><x-icon name="play" class="size-4" /></a>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
            @if ($a)
                <section class="card card-pad text-sm">
                    <h2 class="mb-3 font-display text-xl font-semibold">{{ __('auction_admin.publishing') }}</h2>
                    <ul class="space-y-2">
                        @foreach ([['has_titles', $a->translations()->count() >= 2], ['has_lots', $a->lots->isNotEmpty()], ['lot_times', $a->type === 'live' || $a->lots->every(fn ($l) => $l->starts_at && $l->ends_at)]] as [$k, $ok])
                            <li class="flex items-center gap-2 {{ $ok ? 'text-emerald-soft' : 'text-ink-500' }}"><x-icon :name="$ok ? 'check' : 'minus'" class="size-4" />{{ __('auction_admin.check_'.$k) }}</li>
                        @endforeach
                    </ul>
                </section>
            @endif
        </aside>
    </div>

    @php
        $lotFull = ! $editingLot || $editingLot->status === 'pending';
        $reserveFree = $lotFull || (int) $editingLot->bid_count === 0;
    @endphp
    <x-admin.dialog wire:model="showLot" :title="$editingLot ? __('auction_admin.edit_lot') : __('auction_admin.add_lot')" :description="$editingLot ? ($lotFull ? __('auction_admin.edit_lot_hint') : __('auction_admin.lot_live_locked_hint')) : __('auction_admin.add_lot_hint')" max-width="max-w-2xl">
        <form wire:submit="saveLot" class="space-y-5">
            <div>
                @if ($lotVariant)
                    <div class="flex items-center gap-3 rounded-xl border border-brass-300 bg-brass-50/60 p-3 dark:bg-brass-500/10">
                        <x-admin.variant :variant="$lotVariant" class="flex-1" />
                        @if ($lotFull)<button type="button" wire:click="clearVariant" class="btn-ghost btn-sm px-2" aria-label="{{ __('org_admin.remove') }}"><x-icon name="x" class="size-4" /></button>@endif
                    </div>
                    <x-admin.field :label="__('auction_admin.stock_branch')" for="lot.branch_id" required class="mt-3" :hint="__('auction_admin.stock_branch_hint')">
                        <select id="lot.branch_id" wire:model="lot.branch_id" class="input" @disabled(! $lotFull)><option value="">—</option>@foreach ($branches as $b)<option value="{{ $b->id }}">{{ $b->code }} · {{ $b->localized('name') }}</option>@endforeach</select>
                    </x-admin.field>
                @elseif ($lotFull)
                    <x-admin.variant-picker :results="$matches" :label="__('auction_admin.link_product')" />
                    <p class="field-hint">{{ __('auction_admin.link_product_hint') }}</p>
                @endif
            </div>
            <div class="grid gap-4 sm:grid-cols-2 [&>*]:min-w-0">
                <x-admin.field :label="__('auction_admin.title_en')" for="lot.title_en" required><input id="lot.title_en" wire:model="lot.title_en" class="input" dir="ltr"></x-admin.field>
                <x-admin.field :label="__('auction_admin.title_ar')" for="lot.title_ar" required><input id="lot.title_ar" wire:model="lot.title_ar" class="input" dir="rtl"></x-admin.field>
            </div>
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-4 [&>*]:min-w-0">
                <x-admin.field :label="__('auction_admin.starting_price')" for="lot.starting_price" required><input id="lot.starting_price" @disabled(! $lotFull) type="number" step="0.01" min="0" wire:model="lot.starting_price" class="input tabular-nums" dir="ltr"></x-admin.field>
                <x-admin.field :label="__('auction_admin.increment')" for="lot.min_increment" required><input id="lot.min_increment" @disabled(! $lotFull) type="number" step="0.01" min="1" wire:model="lot.min_increment" class="input tabular-nums" dir="ltr"></x-admin.field>
                <x-admin.field :label="__('auction_admin.reserve')" for="lot.reserve_price" :hint="__('auction_admin.reserve_hint')"><input id="lot.reserve_price" @disabled(! $reserveFree) type="number" step="0.01" min="0" wire:model="lot.reserve_price" class="input tabular-nums" dir="ltr"></x-admin.field>
                <x-admin.field :label="__('auction_admin.buy_now')" for="lot.buy_now_price"><input id="lot.buy_now_price" @disabled(! $lotFull) type="number" step="0.01" min="0" wire:model="lot.buy_now_price" class="input tabular-nums" dir="ltr"></x-admin.field>
            </div>
            <div class="grid gap-4 sm:grid-cols-3 [&>*]:min-w-0">
                <x-admin.field :label="__('admin.quantity')" for="lot.quantity" required><input id="lot.quantity" @disabled(! $lotFull) type="number" min="1" wire:model="lot.quantity" class="input tabular-nums"></x-admin.field>
                <x-admin.field :label="__('auction_admin.starts_at')" for="lot.starts_at" :required="($form['type'] ?? '') === 'scheduled'"><input id="lot.starts_at" @disabled(! $lotFull) type="datetime-local" wire:model="lot.starts_at" class="input"></x-admin.field>
                <x-admin.field :label="__('auction_admin.ends_at')" for="lot.ends_at" :required="($form['type'] ?? '') === 'scheduled'"><input id="lot.ends_at" @disabled(! $lotFull) type="datetime-local" wire:model="lot.ends_at" class="input"></x-admin.field>
            </div>
            <div class="flex flex-col-reverse gap-2 border-t border-sand-100 pt-5 sm:flex-row sm:justify-end dark:border-ink-800">
                <button type="button" class="btn-secondary" @click="open = false">{{ __('admin.cancel') }}</button>
                <button class="btn-primary" wire:loading.attr="disabled" wire:target="saveLot"><x-icon name="{{ $editingLot ? 'check' : 'plus' }}" class="size-4" />{{ $editingLot ? __('auction_admin.save_lot') : __('auction_admin.add_lot') }}</button>
            </div>
        </form>
    </x-admin.dialog>

    <x-admin.dialog wire:model="showCancel" :title="__('auction_admin.cancel_auction')" :description="__('auction_admin.cancel_hint')">
        <form wire:submit="cancelAuction" class="space-y-5">
            <x-admin.field :label="__('admin.reason')" for="cancelReason" required><textarea id="cancelReason" wire:model="cancelReason" rows="3" class="input"></textarea></x-admin.field>
            <div class="flex flex-col-reverse gap-2 border-t border-sand-100 pt-5 sm:flex-row sm:justify-end dark:border-ink-800">
                <button type="button" class="btn-secondary" @click="open = false">{{ __('admin.back') }}</button>
                <button class="btn-danger">{{ __('auction_admin.cancel_auction') }}</button>
            </div>
        </form>
    </x-admin.dialog>
</div>
