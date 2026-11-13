@php
    $items = $cart ? $cart['items'] : collect();
    $units = $cart ? (int) $cart['items_count'] : 0;
    $hasPlans = $quote && count($quote->plans) > 0;
    $so = $draft?->staff_options ?? [];
    $cartErrorCodes = ['insufficient_stock', 'item_unavailable', 'below_min_order', 'above_max_order', 'branch_not_allowed', 'delivery_unavailable_area'];
    $errors_ = $quote ? collect($quote->errors)->when($step === 'cart', fn ($c) => $c->whereIn('code', $cartErrorCodes)) : collect();
    $warnings_ = $quote ? collect($quote->warnings)->reject(fn ($w) => $w['code'] === 'payment_method_required') : collect();
    $title = match ($step) { 'checkout' => __('neworder_admin.checkout'), 'drafts' => __('neworder_admin.held_orders'), 'done' => __('neworder_admin.order_placed_title'), default => __('neworder_admin.panel_title') };
    $money = fn ($m) => \App\Support\Money::of($m)->format();
@endphp
{{-- Header --}}
<header class="flex items-center gap-2 border-b border-sand-200/70 px-4 py-3 dark:border-ink-800 sm:px-5">
    @if (in_array($step, ['checkout', 'drafts'], true))
        <button type="button" wire:click="go('cart')" class="btn-ghost -ms-2 size-11 !p-0" aria-label="{{ __('neworder_admin.back_to_cart') }}"><x-icon name="chevron-left" class="size-5 rtl-flip" /></button>
    @endif
    <div class="min-w-0 flex-1">
        <h2 class="truncate font-display text-xl font-semibold text-ink-900 dark:text-ivory">{{ $title }}</h2>
        @if ($step === 'cart' && $cart)<p class="text-xs text-ink-500">{{ trans_choice('neworder_admin.units', $units, ['count' => $units]) }}</p>@endif
    </div>
    @if ($step !== 'drafts' && $step !== 'done')
        <button type="button" wire:click="go('drafts')" class="btn-ghost relative min-h-11 gap-1.5 px-3 text-xs">
            <x-icon name="clipboard" class="size-4" /><span class="hidden sm:inline">{{ __('neworder_admin.held_orders') }}</span>
            @if ($draftCount > 1)<span class="rounded-full bg-brass-500 px-1.5 text-[0.65rem] font-semibold text-ink-950 tabular-nums">{{ $draftCount }}</span>@endif
        </button>
    @endif
    @if ($mode === 'overlay')
        <button type="button" wire:click="closePanel" class="btn-ghost -me-2 size-11 !p-0" aria-label="{{ __('admin.close') }}"><x-icon name="x" class="size-5" /></button>
    @else
        <button type="button" @click="sheet = false" class="btn-ghost -me-2 size-11 !p-0 lg:hidden" aria-label="{{ __('admin.close') }}"><x-icon name="x" class="size-5" /></button>
    @endif
</header>

<div class="relative min-h-0 flex-1 overflow-y-auto overscroll-contain" wire:loading.class="opacity-70" wire:target="pickCustomer,setBranch,bump,removeLine,readd,addSuggestion,setFulfillment,setAddress,setPayment,pickSlot,pickDate,applyCoupon,removeCoupon,resume,discard,clearCustomer,startDraft">

{{-- ───────────────────────────── Done ───────────────────────────── --}}
@if ($step === 'done' && $placed)
    <div class="space-y-5 p-5 text-center">
        <div class="mx-auto grid size-14 place-items-center rounded-full bg-emerald-soft/10 text-emerald-soft"><x-icon name="check" class="size-7" /></div>
        <div>
            <p class="text-sm text-ink-500">{{ __('neworder_admin.order_number') }}</p>
            <p class="font-mono text-2xl font-semibold text-ink-900 dark:text-ivory" dir="ltr">{{ $placed['number'] }}</p>
            <p class="mt-1 text-sm text-ink-600 dark:text-sand-200">{{ $placed['customer'] }} · {{ $placed['total'] }} · {{ __('status.'.$placed['method']) }}</p>
        </div>
        @if ($placed['method'] === 'sadad')
            <div class="rounded-2xl border border-brass-200 bg-brass-50/60 p-4 text-start dark:border-brass-500/30 dark:bg-brass-500/10" x-data="{ copied: false }">
                <p class="text-sm font-semibold text-ink-900 dark:text-ivory">{{ __('neworder_admin.pay_link_title') }}</p>
                <p class="mt-1 text-xs text-ink-600 dark:text-sand-200">{{ __('neworder_admin.pay_link_hint') }}</p>
                @if ($placed['link'])
                    <input type="text" readonly dir="ltr" value="{{ $placed['link'] }}" class="input mt-3 font-mono text-xs" aria-label="{{ __('neworder_admin.pay_link_title') }}" @focus="$event.target.select()">
                    <div class="mt-3 flex flex-wrap gap-2">
                        <button type="button" class="btn-secondary min-h-11" @click="navigator.clipboard.writeText(@js($placed['link'])).then(() => { copied = true; setTimeout(() => copied = false, 2000) })">
                            <x-icon name="copy" class="size-4" /><span x-text="copied ? @js(__('neworder_admin.copied')) : @js(__('neworder_admin.copy_link'))"></span>
                        </button>
                        <button type="button" wire:click="sendLink" wire:loading.attr="disabled" wire:target="sendLink" class="btn-brass min-h-11"><x-icon name="message" class="size-4" />{{ __('neworder_admin.send_link') }}</button>
                    </div>
                    @if (! empty($placed['sent']))<p class="mt-2 text-xs text-emerald-soft">{{ __('neworder_admin.link_sent_via', ['channels' => implode(', ', array_map(fn ($c) => __('neworder_admin.channel_'.$c), $placed['sent']))]) }}</p>@endif
                @else
                    <p class="mt-3 text-sm text-amber-soft">{{ __('neworder_admin.pay_link_unavailable') }}</p>
                @endif
            </div>
        @endif
        <div class="grid gap-2">
            <a href="{{ route('admin.orders.show', $placed['id']) }}" wire:navigate class="btn-primary min-h-11">{{ __('neworder_admin.view_order') }}</a>
            <a href="{{ route('admin.orders.invoice', $placed['id']) }}" target="_blank" rel="noopener" class="btn-secondary min-h-11"><x-icon name="printer" class="size-4" />{{ __('neworder_admin.print_invoice') }}</a>
            <button type="button" wire:click="newOrder" class="btn-ghost min-h-11"><x-icon name="plus" class="size-4" />{{ __('neworder_admin.new_order') }}</button>
        </div>
    </div>

{{-- ───────────────────────────── Held orders ───────────────────────────── --}}
@elseif ($step === 'drafts')
    <div class="space-y-3 p-4 sm:p-5">
        <p class="text-sm text-ink-500">{{ __('neworder_admin.held_hint') }}</p>
        @forelse ($drafts as $d)
            @php $mine = $d->staff_id === auth()->id(); $isCurrent = $draft && $draft->id === $d->id; @endphp
            <div wire:key="held-{{ $d->id }}" class="rounded-2xl border p-4 {{ $isCurrent ? 'border-brass-400 bg-brass-50/50 dark:bg-brass-500/10' : 'border-sand-200 dark:border-ink-700' }}">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="truncate font-medium text-ink-900 dark:text-ivory">{{ $d->user?->name ?? __('neworder_admin.no_customer_yet') }}</p>
                        <p class="mt-0.5 text-xs text-ink-500">
                            {{ trans_choice('neworder_admin.units', (int) $d->units, ['count' => (int) $d->units]) }}
                            @if ($d->branch) · {{ $d->branch->code }}@endif
                            · {{ $d->updated_at->diffForHumans() }}
                            @if (! $mine && $d->staff) · {{ __('neworder_admin.by_staff', ['name' => $d->staff->name]) }}@endif
                        </p>
                    </div>
                    @if ($isCurrent)<span class="badge-brass">{{ __('neworder_admin.current') }}</span>@endif
                </div>
                <div class="mt-3 flex gap-2">
                    @if ($mine && ! $isCurrent)<button type="button" wire:click="resume('{{ $d->id }}')" class="btn-secondary min-h-11 flex-1"><x-icon name="arrow-right" class="size-4 rtl-flip" />{{ __('neworder_admin.resume') }}</button>@endif
                    @if ($mine || auth()->user()->isSuperAdmin())<button type="button" wire:click="askDiscard('{{ $d->id }}')" class="btn-ghost min-h-11 text-ruby-soft"><x-icon name="trash" class="size-4" />{{ __('neworder_admin.discard') }}</button>@endif
                </div>
            </div>
        @empty
            <x-admin.empty :title="__('neworder_admin.held_empty')" :description="__('neworder_admin.held_empty_hint')" icon="clipboard" class="!py-10" />
        @endforelse
        <button type="button" wire:click="startDraft" class="btn-primary min-h-11 w-full"><x-icon name="plus" class="size-4" />{{ __('neworder_admin.start_new_draft') }}</button>
    </div>

{{-- ───────────────────────────── Empty (no draft) ───────────────────────────── --}}
@elseif (! $draft)
    <div class="space-y-5 p-4 sm:p-5">
        @include('livewire.admin.sales.order-cart-customer')
        <x-admin.empty :title="__('neworder_admin.cart_empty')" :description="__('neworder_admin.cart_empty_hint')" icon="cart" class="!py-8">
            @if ($mode === 'overlay')<a href="{{ route('admin.orders.create') }}" wire:navigate @click="navigating = true" class="btn-brass min-h-11"><x-icon name="search" class="size-4" />{{ __('neworder_admin.browse_products') }}</a>@endif
        </x-admin.empty>
    </div>

{{-- ───────────────────────────── Cart ───────────────────────────── --}}
@elseif ($step === 'cart')
    <div class="space-y-5 p-4 sm:p-5">
        @include('livewire.admin.sales.order-cart-customer')

        @if ($branches->count() > 1 || auth()->user()->isSuperAdmin() || $branches->count() === 0)
            <label class="block">
                <span class="field-label">{{ __('neworder_admin.branch') }}</span>
                <select class="input" wire:change="setBranch($event.target.value)" aria-label="{{ __('neworder_admin.branch') }}">
                    @if ($branches->count() !== 1 && app(\App\Domain\Identity\Services\AccessControl::class)->allowsGlobally(auth()->user(), 'orders.create'))<option value="">{{ __('neworder_admin.branch_auto') }}</option>@endif
                    @foreach ($branches as $b)<option value="{{ $b->id }}" @selected($draft->branch_id === $b->id)>{{ $b->code }} · {{ $b->localized('name') }}</option>@endforeach
                </select>
            </label>
        @endif

        @if ($items->isEmpty())
            <x-admin.empty :title="__('neworder_admin.cart_empty')" :description="__('neworder_admin.cart_empty_hint')" icon="cart" class="!py-8">
                @if ($mode === 'overlay')<a href="{{ route('admin.orders.create') }}" wire:navigate @click="navigating = true" class="btn-brass min-h-11"><x-icon name="search" class="size-4" />{{ __('neworder_admin.browse_products') }}</a>@endif
            </x-admin.empty>
        @else
            <ul class="divide-y divide-sand-100 dark:divide-ink-800" aria-label="{{ __('neworder_admin.lines') }}">
                @foreach ($items as $line)
                    <li wire:key="line-{{ $line['id'] }}" class="flex gap-3 py-3">
                        <span class="grid size-16 shrink-0 place-items-center overflow-hidden rounded-xl bg-sand-100 text-ink-400 dark:bg-ink-800">
                            @if ($line['product']['image'])<img src="{{ $line['product']['image']['thumb_url'] }}" alt="" class="size-full object-cover" loading="lazy">@else<x-icon name="sofa" class="size-6" />@endif
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium text-ink-900 dark:text-ivory">{{ $line['product']['name'] }}</p>
                            @if ($line['variant_name'] && $line['variant_name'] !== $line['product']['name'])<p class="truncate text-xs text-ink-500">{{ $line['variant_name'] }}</p>@endif
                            <p class="mt-0.5 text-xs text-ink-500">
                                @if ($line['unit_price'])<x-admin.money :value="$line['unit_price']['final']['amount']" /> {{ __('neworder_admin.each') }}@endif
                                @if ($line['price_changed'])<span class="badge-warning ms-1 !text-[0.65rem]">{{ __('neworder_admin.price_changed') }}</span>@endif
                            </p>
                            @if ($line['issue'])<p class="mt-1 text-xs font-medium text-ruby-soft">{{ __('neworder_admin.issue_'.$line['issue']) }}</p>@endif
                            <div class="mt-2 flex items-center justify-between gap-2">
                                <div class="inline-flex items-center rounded-xl border border-sand-300 dark:border-ink-600" role="group" aria-label="{{ __('neworder_admin.quantity') }}">
                                    <button type="button" wire:click="bump('{{ $line['id'] }}', -1)" class="grid size-11 place-items-center rounded-s-xl hover:bg-sand-100 dark:hover:bg-ink-800" aria-label="{{ __('neworder_admin.decrease') }}"><x-icon name="minus" class="size-4" /></button>
                                    <input type="number" min="1" max="{{ config('platform.checkout.max_quantity_per_line') }}" value="{{ $line['quantity'] }}" wire:change="setQuantity('{{ $line['id'] }}', $event.target.value)"
                                           class="h-11 w-12 border-0 bg-transparent p-0 text-center text-sm tabular-nums focus:ring-0 [appearance:textfield] [&::-webkit-inner-spin-button]:appearance-none" aria-label="{{ __('neworder_admin.quantity') }}">
                                    <button type="button" wire:click="bump('{{ $line['id'] }}', 1)" class="grid size-11 place-items-center rounded-e-xl hover:bg-sand-100 dark:hover:bg-ink-800" aria-label="{{ __('neworder_admin.increase') }}"><x-icon name="plus" class="size-4" /></button>
                                </div>
                                <div class="flex items-center gap-1">
                                    <x-admin.money :value="$line['line_total']['amount']" class="text-sm font-semibold" />
                                    <button type="button" wire:click="removeLine('{{ $line['id'] }}')" class="grid size-11 place-items-center rounded-xl text-ink-500 hover:bg-sand-100 hover:text-ruby-soft dark:hover:bg-ink-800" aria-label="{{ __('neworder_admin.remove') }}"><x-icon name="trash" class="size-4" /></button>
                                </div>
                            </div>
                        </div>
                    </li>
                @endforeach
            </ul>

            @if (count($suggestions))
                <section aria-label="{{ __('neworder_admin.suggestions') }}">
                    <h3 class="mb-2 text-xs font-semibold uppercase tracking-wider text-ink-500">{{ __('neworder_admin.suggestions') }}</h3>
                    <ul class="space-y-2">
                        @foreach ($suggestions as $s)
                            <li wire:key="sg-{{ $s['id'] }}" class="flex items-center gap-3 rounded-xl border border-sand-200 p-2 dark:border-ink-700">
                                <span class="grid size-11 shrink-0 place-items-center overflow-hidden rounded-lg bg-sand-100 dark:bg-ink-800">@if ($s['image'])<img src="{{ $s['image']['thumb_url'] }}" alt="" class="size-full object-cover" loading="lazy">@endif</span>
                                <span class="min-w-0 flex-1"><span class="block truncate text-sm">{{ $s['name'] }}</span><span class="text-xs text-ink-500">{{ $s['price']['final']['formatted'] ?? '' }}</span></span>
                                <button type="button" wire:click="addSuggestion('{{ $s['variant_id'] }}')" class="btn-secondary size-11 !p-0" aria-label="{{ __('neworder_admin.add_named', ['name' => $s['name']]) }}"><x-icon name="plus" class="size-4" /></button>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif
        @endif

        @include('livewire.admin.sales.order-cart-notices')
    </div>

{{-- ───────────────────────────── Checkout ───────────────────────────── --}}
@else
    <div class="space-y-6 p-4 sm:p-5">
        @include('livewire.admin.sales.order-cart-customer')

        @if ($customer)
            {{-- Fulfilment --}}
            <section class="space-y-3" aria-labelledby="oc-ful">
                <h3 id="oc-ful" class="font-display text-lg font-semibold">{{ __('neworder_admin.fulfilment') }}</h3>
                <div class="grid grid-cols-2 gap-2" role="radiogroup" aria-labelledby="oc-ful">
                    @foreach (['delivery' => 'truck', 'pickup' => 'store'] as $t => $icon)
                        <button type="button" role="radio" aria-checked="{{ $draft->fulfillment_type === $t ? 'true' : 'false' }}" wire:click="setFulfillment('{{ $t }}')"
                                class="flex min-h-11 items-center justify-center gap-2 rounded-xl border px-3 py-2.5 text-sm font-medium transition {{ $draft->fulfillment_type === $t ? 'border-ink-900 bg-ink-900 text-ivory dark:border-brass-500 dark:bg-brass-500 dark:text-ink-950' : 'border-sand-300 bg-white hover:border-brass-400 dark:border-ink-600 dark:bg-ink-800' }}">
                            <x-icon :name="$icon" class="size-4" />{{ __('sales_admin.type_'.$t) }}
                        </button>
                    @endforeach
                </div>

                @if ($draft->fulfillment_type === 'pickup' || $branches->count() > 1)
                    <label class="block">
                        <span class="field-label">{{ $draft->fulfillment_type === 'pickup' ? __('neworder_admin.pickup_branch') : __('neworder_admin.branch') }}</span>
                        <select class="input" wire:change="setBranch($event.target.value)">
                            @if (! ($draft->fulfillment_type === 'pickup') || $branches->count() !== 1)<option value="">{{ $draft->fulfillment_type === 'pickup' ? __('neworder_admin.choose_branch') : __('neworder_admin.branch_auto') }}</option>@endif
                            @foreach ($branches as $b)<option value="{{ $b->id }}" @selected($draft->branch_id === $b->id)>{{ $b->code }} · {{ $b->localized('name') }}</option>@endforeach
                        </select>
                    </label>
                @endif

                @if ($draft->fulfillment_type === 'delivery')
                    <div class="space-y-2" role="radiogroup" aria-label="{{ __('neworder_admin.address') }}">
                        @foreach ($addresses as $a)
                            <button type="button" role="radio" aria-checked="{{ $draft->address_id === $a->id ? 'true' : 'false' }}" wire:key="addr-{{ $a->id }}" wire:click="setAddress('{{ $a->id }}')"
                                    class="flex w-full min-h-11 items-start gap-3 rounded-xl border p-3 text-start text-sm transition {{ $draft->address_id === $a->id ? 'border-brass-500 bg-brass-50/60 dark:bg-brass-500/10' : 'border-sand-300 hover:border-brass-400 dark:border-ink-600' }}">
                                <span class="mt-0.5 grid size-4 shrink-0 place-items-center rounded-full border {{ $draft->address_id === $a->id ? 'border-brass-600 bg-brass-500' : 'border-sand-400' }}"></span>
                                <span class="min-w-0"><span class="block font-medium">{{ $a->label ?: $a->recipient_name }}</span><span class="block text-xs text-ink-500">{{ $a->oneLine() }}</span>@if ($a->zone)<span class="text-xs text-ink-500">{{ $a->zone->localized('name') }}</span>@endif</span>
                            </button>
                        @endforeach
                        <button type="button" wire:click="$toggle('showAddress')" class="btn-ghost min-h-11 w-full justify-start" aria-expanded="{{ $showAddress ? 'true' : 'false' }}"><x-icon name="plus" class="size-4" />{{ __('neworder_admin.add_address') }}</button>
                        @if ($showAddress)
                            <form wire:submit="saveAddress" class="grid gap-3 rounded-2xl border border-sand-200 p-3 dark:border-ink-700 sm:grid-cols-2">
                                @foreach ([['recipient_name', 'text', 'recipient_name'], ['recipient_phone', 'tel', 'recipient_phone'], ['zone_number', 'text', 'zone_number'], ['street_number', 'text', 'street_number'], ['building_number', 'text', 'building_number'], ['unit_number', 'text', 'unit_number']] as [$f, $type, $lbl])
                                    <label class="block"><span class="field-label">{{ __('neworder_admin.f_'.$lbl) }}</span>
                                        <input type="{{ $type }}" wire:model="address.{{ $f }}" class="input @error('address.'.$f) input-error @enderror" @if ($type === 'tel') dir="ltr" inputmode="tel" @endif @if ($f === 'zone_number') inputmode="numeric" @endif>
                                        @error($f)<span class="mt-1 block text-xs text-ruby-soft">{{ $message }}</span>@enderror
                                    </label>
                                @endforeach
                                <label class="block sm:col-span-2"><span class="field-label">{{ __('neworder_admin.f_landmark') }}</span><input type="text" wire:model="address.landmark" class="input"></label>
                                <div class="flex gap-2 sm:col-span-2">
                                    <button type="button" wire:click="$set('showAddress', false)" class="btn-ghost min-h-11">{{ __('admin.cancel') }}</button>
                                    <button type="submit" class="btn-primary min-h-11 flex-1" wire:loading.attr="disabled" wire:target="saveAddress">{{ __('neworder_admin.save_address') }}</button>
                                </div>
                            </form>
                        @endif
                    </div>
                @endif

                @if (count($slotDays))
                    @php $day = collect($slotDays)->firstWhere('date', $selectedDate) ?? $slotDays[0]; $chosenSlot = $so[$slotKey.'_slot_id'] ?? null; @endphp
                    <div class="space-y-2">
                        <span class="field-label">{{ $draft->fulfillment_type === 'pickup' ? __('neworder_admin.pickup_slot') : __('neworder_admin.delivery_slot') }}</span>
                        <select class="input" wire:change="pickDate($event.target.value)" aria-label="{{ __('neworder_admin.date') }}">
                            @foreach ($slotDays as $sd)<option value="{{ $sd['date'] }}" @selected($sd['date'] === $day['date'])>{{ \Carbon\CarbonImmutable::parse($sd['date'])->locale(app()->getLocale())->translatedFormat('D j M') }}</option>@endforeach
                        </select>
                        <div class="flex flex-wrap gap-2" role="radiogroup" aria-label="{{ __('neworder_admin.time') }}">
                            @foreach ($day['slots'] as $slot)
                                @php $on = $chosenSlot === $slot['id'] && ($so[$slotKey.'_date'] ?? null) === $day['date']; @endphp
                                <button type="button" role="radio" aria-checked="{{ $on ? 'true' : 'false' }}" @disabled($slot['is_full']) wire:key="slot-{{ $slot['id'] }}-{{ $day['date'] }}" wire:click="pickSlot('{{ $slot['id'] }}', '{{ $day['date'] }}')"
                                        class="min-h-11 rounded-full border px-3 text-sm tabular-nums transition disabled:cursor-not-allowed disabled:opacity-40 {{ $on ? 'border-ink-900 bg-ink-900 text-ivory dark:border-brass-500 dark:bg-brass-500 dark:text-ink-950' : 'border-sand-300 bg-white hover:border-brass-400 dark:border-ink-600 dark:bg-ink-800' }}" dir="ltr">{{ substr($slot['starts_at'], 0, 5) }}–{{ substr($slot['ends_at'], 0, 5) }}</button>
                            @endforeach
                        </div>
                    </div>
                @endif
            </section>

            {{-- Payment --}}
            <section class="space-y-3" aria-labelledby="oc-pay">
                <h3 id="oc-pay" class="font-display text-lg font-semibold">{{ __('neworder_admin.payment') }}</h3>
                @if ($quote && $hasPlans)
                    <div class="space-y-2" role="radiogroup" aria-labelledby="oc-pay">
                        @foreach (collect($quote->paymentMethods)->reject(fn ($m) => in_array($m['reason'], ['delivery_only', 'pickup_only'], true)) as $m)
                            @php $on = ($so['payment_method'] ?? null) === $m['code']; @endphp
                            <button type="button" role="radio" aria-checked="{{ $on ? 'true' : 'false' }}" @disabled(! $m['available']) wire:key="pm-{{ $m['code'] }}" wire:click="setPayment('{{ $m['code'] }}')"
                                    class="flex w-full min-h-11 items-start gap-3 rounded-xl border p-3 text-start text-sm transition disabled:cursor-not-allowed disabled:opacity-50 {{ $on ? 'border-brass-500 bg-brass-50/60 dark:bg-brass-500/10' : 'border-sand-300 hover:border-brass-400 dark:border-ink-600' }}">
                                <span class="mt-0.5 grid size-4 shrink-0 place-items-center rounded-full border {{ $on ? 'border-brass-600 bg-brass-500' : 'border-sand-400' }}"></span>
                                <span><span class="block font-medium">{{ __('status.'.$m['code']) }}</span>
                                    <span class="block text-xs text-ink-500">{{ $m['available'] ? __('neworder_admin.pay_hint_'.$m['code']) : __('neworder_admin.pay_reason_'.$m['reason']) }}</span></span>
                            </button>
                        @endforeach
                    </div>
                @else
                    <p class="text-sm text-ink-500">{{ __('neworder_admin.payment_after_fulfilment') }}</p>
                @endif
            </section>

            {{-- Coupon & notes --}}
            <section class="space-y-3">
                <div>
                    <span class="field-label">{{ __('neworder_admin.coupon') }}</span>
                    @if ($draft->coupon_code)
                        <div class="flex items-center justify-between rounded-xl border border-emerald-soft/30 bg-emerald-soft/5 px-3 py-2 text-sm"><span class="font-mono" dir="ltr">{{ $draft->coupon_code }}</span>
                            <button type="button" wire:click="removeCoupon" class="btn-ghost min-h-11 text-xs">{{ __('neworder_admin.remove') }}</button></div>
                    @else
                        <form wire:submit="applyCoupon" class="flex gap-2"><input type="text" wire:model="coupon" class="input uppercase" dir="ltr" maxlength="48" autocomplete="off" placeholder="{{ __('neworder_admin.coupon_placeholder') }}">
                            <button type="submit" class="btn-secondary min-h-11 shrink-0">{{ __('neworder_admin.apply') }}</button></form>
                        @error('coupon')<span class="mt-1 block text-xs text-ruby-soft">{{ $message }}</span>@enderror
                    @endif
                </div>
                <label class="block"><span class="field-label">{{ __('neworder_admin.customer_note') }}</span><textarea wire:model.blur="notes" rows="2" maxlength="1000" class="input"></textarea></label>
                <label class="block"><span class="field-label">{{ __('neworder_admin.internal_note') }}</span><textarea wire:model.blur="internalNote" rows="2" maxlength="1000" class="input" placeholder="{{ __('neworder_admin.internal_note_hint') }}"></textarea></label>
            </section>
        @endif

        @include('livewire.admin.sales.order-cart-notices')
    </div>
@endif
</div>

{{-- ───────────────────────────── Footer: totals + primary action ───────────────────────────── --}}
@if (in_array($step, ['cart', 'checkout'], true) && $draft && $items->isNotEmpty())
    <footer class="border-t border-sand-200/70 bg-white/80 p-4 pb-[calc(1rem+env(safe-area-inset-bottom))] dark:border-ink-800 dark:bg-ink-900/80 sm:p-5">
        <dl class="mb-3 space-y-1 text-sm">
            @if ($hasPlans)
                <div class="flex justify-between"><dt class="text-ink-500">{{ __('neworder_admin.subtotal') }}</dt><dd><x-admin.money :value="$quote->subtotal()->toDecimal()" /></dd></div>
                @if (! $quote->discountTotal()->isZero())<div class="flex justify-between text-emerald-soft"><dt>{{ __('neworder_admin.discount') }}</dt><dd>−<x-admin.money :value="$quote->discountTotal()->toDecimal()" /></dd></div>@endif
                @if (! $quote->loyaltyDiscount->isZero())<div class="flex justify-between text-emerald-soft"><dt>{{ __('neworder_admin.loyalty') }}</dt><dd>−<x-admin.money :value="$quote->loyaltyDiscount->toDecimal()" /></dd></div>@endif
                <div class="flex justify-between"><dt class="text-ink-500">{{ __('neworder_admin.delivery') }}</dt><dd><x-admin.money :value="$quote->deliveryFeeTotal()->toDecimal()" /></dd></div>
                @if (! $quote->serviceFee->isZero())<div class="flex justify-between"><dt class="text-ink-500">{{ __('neworder_admin.service_fee') }}</dt><dd><x-admin.money :value="$quote->serviceFee->toDecimal()" /></dd></div>@endif
                @if (! $quote->codFee->isZero())<div class="flex justify-between"><dt class="text-ink-500">{{ __('neworder_admin.cod_fee') }}</dt><dd><x-admin.money :value="$quote->codFee->toDecimal()" /></dd></div>@endif
                @if (! $quote->taxTotal()->isZero())<div class="flex justify-between"><dt class="text-ink-500">{{ __('neworder_admin.tax') }}</dt><dd><x-admin.money :value="$quote->taxTotal()->toDecimal()" /></dd></div>@endif
                <div class="flex justify-between border-t border-sand-200 pt-2 text-base font-semibold dark:border-ink-700"><dt>{{ __('neworder_admin.total') }}</dt><dd><x-admin.money :value="$quote->grandTotal()->toDecimal()" /></dd></div>
            @else
                <div class="flex justify-between text-base font-semibold"><dt>{{ __('neworder_admin.subtotal') }}</dt><dd><x-admin.money :value="$cart['subtotal']['amount']" /></dd></div>
                <p class="text-xs text-ink-500">{{ __('neworder_admin.totals_pending') }}</p>
            @endif
        </dl>
        @if ($step === 'cart')
            <button type="button" wire:click="go('checkout')" @disabled(! $customer) class="btn-primary min-h-12 w-full"><x-icon name="arrow-right" class="size-4 rtl-flip" />{{ __('neworder_admin.checkout') }}</button>
            @if (! $customer)<p class="mt-2 text-center text-xs text-ink-500">{{ __('neworder_admin.choose_customer_first') }}</p>@endif
        @else
            @php $ready = $quote && $quote->canPlace(); @endphp
            <button type="button" x-data="{ busy: false }" :disabled="busy || {{ ($quote && $quote->canPlace()) ? 'false' : 'true' }}" @click="if (! busy) { busy = true; $wire.placeOrder(@js($quote?->hash)).finally(() => busy = false) }" 
                    class="btn-brass min-h-12 w-full" wire:loading.attr="disabled" wire:target="placeOrder">
                <x-icon name="check" class="size-4" /><span wire:loading.remove wire:target="placeOrder">{{ $ready ? __('neworder_admin.place_order', ['total' => $quote->grandTotal()->format()]) : __('neworder_admin.place_order_short') }}</span><span wire:loading wire:target="placeOrder">{{ __('neworder_admin.placing') }}</span>
            </button>
            @if (! $ready && $quote)<p class="mt-2 text-center text-xs text-ink-500">{{ __('neworder_admin.complete_checkout') }}</p>@endif
        @endif
    </footer>
@endif

{{-- Discard confirmation: question title, verb buttons, Cancel is the safe default --}}
@if ($discardId)
    <div class="absolute inset-0 z-10 grid place-items-center bg-ink-950/50 p-4" role="alertdialog" aria-modal="true" aria-labelledby="oc-discard-t" x-trap="true">
        <div class="card w-full max-w-sm p-5">
            <h3 id="oc-discard-t" class="font-display text-xl font-semibold">{{ __('neworder_admin.discard_title') }}</h3>
            <p class="mt-2 text-sm text-ink-600 dark:text-sand-200">{{ __('neworder_admin.discard_body') }}</p>
            <div class="mt-5 grid grid-cols-2 gap-2">
                <button type="button" wire:click="cancelDiscard" x-init="$nextTick(() => $el.focus())" class="btn-secondary min-h-11">{{ __('neworder_admin.keep_draft') }}</button>
                <button type="button" wire:click="discard" class="btn-danger min-h-11">{{ __('neworder_admin.discard_draft') }}</button>
            </div>
        </div>
    </div>
@endif
