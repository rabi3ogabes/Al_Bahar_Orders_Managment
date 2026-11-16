{{-- "Cart of <customer>": the body of the side window. Needs $window (see AbandonedCartsIndex::windowData), $canChange, $cartId, $addOpen, $addSearch. --}}
@if (! $window)
    {{-- window closed --}}
@elseif (isset($window['gone']))
    <div class="space-y-4 py-6 text-center" role="status">
        <div class="mx-auto grid size-14 place-items-center rounded-2xl bg-sand-100 text-brass-600 dark:bg-ink-800 dark:text-brass-300"><x-icon name="info" class="size-6" /></div>
        <p class="mx-auto max-w-sm text-sm text-ink-600 dark:text-sand-200">{{ $window['gone'] }}</p>
        <button type="button" wire:click="closeCart" class="btn-primary min-h-11">{{ __('admin.close') }}</button>
    </div>
@else
    @php
        $cart = $window['cart'];
        $customer = $window['customer'];
        $quote = $window['quote'];
        $lines = $window['lines'];
        $reminder = $window['reminder'];
        $hasPlans = count($quote->plans) > 0;
        $cartErrors = ['insufficient_stock', 'item_unavailable', 'below_min_order', 'above_max_order', 'delivery_unavailable_area', 'address_required', 'pickup_branch_required', 'branch_not_allowed'];
        $notices = collect($quote->errors)->whereIn('code', $cartErrors)->map(fn ($e) => $e + ['tone' => 'error'])
            ->concat(collect($quote->warnings)->reject(fn ($w) => in_array($w['code'], ['payment_method_required', 'slot_ignored_for_split', 'express_unavailable'], true))->map(fn ($w) => $w + ['tone' => 'warning']));
        $free = ! $quote->deliveryDiscountTotal()->isZero();
        $busy = 'bump,setQuantity,removeLine,addVariant,applyCoupon,removeCoupon';
        $box = 'rounded-2xl border border-sand-200 p-4 dark:border-ink-700';
    @endphp
    <div class="space-y-5">
        {{-- Who and since when --}}
        <section class="{{ $box }}" aria-label="{{ __('abandoned_admin.customer') }}">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="truncate font-display text-lg font-semibold text-ink-900 dark:text-ivory">{{ $customer->name }}</p>
                    <a href="tel:{{ $customer->phone }}" dir="ltr" class="inline-flex min-h-11 items-center gap-1.5 text-sm text-brass-700 hover:underline dark:text-brass-300"><x-icon name="phone" class="size-4" />{{ $customer->phone }}</a>
                </div>
                <span class="badge-neutral shrink-0">{{ $customer->locale === 'ar' ? 'العربية' : 'English' }}</span>
            </div>
            <dl class="mt-2 grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
                <div><dt class="text-xs text-ink-500">{{ __('abandoned_admin.idle_for') }}</dt><dd class="font-medium" title="{{ \App\Support\BusinessTime::display($window['idle_since']) }}">{{ $window['idle_since']->diffForHumans(null, \Carbon\CarbonInterface::DIFF_ABSOLUTE) }}</dd></div>
                <div><dt class="text-xs text-ink-500">{{ __('abandoned_admin.reminders') }}</dt>
                    <dd class="font-medium">{{ $cart->abandoned_notifications > 0 ? __('abandoned_admin.reminders_of', ['sent' => $cart->abandoned_notifications, 'max' => $reminder['limit']]) : __('abandoned_admin.not_reminded') }}
                        @if ($cart->abandoned_notified_at)<span class="block text-xs font-normal text-ink-500">{{ $cart->abandoned_notified_at->diffForHumans() }}</span>@endif</dd></div>
                <div><dt class="text-xs text-ink-500">{{ __('abandoned_admin.branch') }}</dt><dd class="font-medium">{{ $window['branch'] ? $window['branch']->code.' · '.$window['branch']->localized('name') : __('abandoned_admin.branch_auto') }}</dd></div>
                <div><dt class="text-xs text-ink-500">{{ $cart->fulfillment_type === 'pickup' ? __('abandoned_admin.pickup') : __('abandoned_admin.delivery_to') }}</dt>
                    <dd class="font-medium">
                        @if ($cart->fulfillment_type === 'pickup'){{ __('abandoned_admin.pickup_at_branch') }}
                        @elseif ($window['address']){{ $window['address']->label ?: $window['address']->recipient_name }}<span class="block text-xs font-normal text-ink-500">{{ collect([$window['address']->zone?->localized('name'), $window['address']->zone_number ? __('abandoned_admin.zone', ['number' => $window['address']->zone_number]) : null])->filter()->implode(' · ') }}</span>
                        @else<span class="text-ink-400">{{ __('abandoned_admin.no_address') }}</span>@endif
                    </dd></div>
            </dl>
            @if ($reminder['status'] !== 'ok')
                <p class="mt-3 flex items-start gap-2 rounded-xl bg-sand-50 p-3 text-xs text-ink-600 dark:bg-ink-800 dark:text-sand-200"><x-icon name="info" class="mt-0.5 size-4 shrink-0 text-ink-400" />
                    <span>{{ __('abandoned_admin.reminder_'.$reminder['status'], ['limit' => $reminder['limit'], 'time' => $reminder['next_at'] ? \App\Support\BusinessTime::display($reminder['next_at'], 'd M, H:i') : '']) }}</span></p>
            @endif
        </section>

        {{-- Plain-language notices from the real quote --}}
        @if ($notices->isNotEmpty())
            <ul class="space-y-2" aria-label="{{ __('abandoned_admin.notices') }}">
                @foreach ($notices as $n)
                    <li wire:key="n-{{ $loop->index }}-{{ $n['code'] }}" class="flex items-start gap-2 rounded-xl border px-3 py-2 text-sm {{ $n['tone'] === 'error' ? 'border-ruby-soft/30 bg-ruby-soft/5 text-ruby-soft' : 'border-amber-soft/30 bg-amber-soft/5 text-amber-soft' }}" role="status">
                        <x-icon name="alert" class="mt-0.5 size-4 shrink-0" /><span>{{ $n['message'] }}</span>
                    </li>
                @endforeach
            </ul>
        @endif

        {{-- Lines --}}
        <section aria-label="{{ __('abandoned_admin.lines') }}">
            <div class="mb-1 flex items-center justify-between gap-2">
                <h3 class="text-xs font-semibold uppercase tracking-wider text-ink-500">{{ __('abandoned_admin.lines') }} · {{ trans_choice('abandoned_admin.items', $window['units'], ['count' => $window['units']]) }}</h3>
                @if ($canChange)
                    <button type="button" wire:click="toggleAdd" aria-expanded="{{ $addOpen ? 'true' : 'false' }}" class="btn-secondary btn-sm min-h-11"><x-icon name="plus" class="size-4" />{{ __('abandoned_admin.add_item') }}</button>
                @endif
            </div>

            @if ($addOpen && $canChange)
                <div class="mb-3 space-y-2 rounded-2xl border border-brass-200 bg-brass-50/40 p-3 dark:border-brass-500/30 dark:bg-brass-500/5">
                    <label class="relative block"><span class="sr-only">{{ __('abandoned_admin.add_search') }}</span>
                        <x-icon name="search" class="pointer-events-none absolute top-1/2 start-3.5 size-4 -translate-y-1/2 text-ink-400" />
                        <input type="search" wire:model.live.debounce.300ms="addSearch" autofocus placeholder="{{ __('abandoned_admin.add_search') }}" class="input ps-10" autocomplete="off">
                    </label>
                    @if (mb_strlen(trim($addSearch)) < 2)
                        <p class="px-1 text-xs text-ink-500">{{ __('abandoned_admin.add_hint') }}</p>
                    @elseif ($window['results'] === [])
                        <p class="px-1 text-sm text-ink-500">{{ __('abandoned_admin.add_none') }}</p>
                    @else
                        <ul class="max-h-72 space-y-2 overflow-y-auto overscroll-contain">
                            @foreach ($window['results'] as $p)
                                <li wire:key="ap-{{ $p['id'] }}" class="rounded-xl border border-sand-200 bg-white p-2 dark:border-ink-700 dark:bg-ink-900">
                                    <div class="flex items-center gap-3">
                                        <span class="grid size-11 shrink-0 place-items-center overflow-hidden rounded-lg bg-sand-100 text-ink-400 dark:bg-ink-800">@if ($p['image'])<img src="{{ $p['image'] }}" alt="" class="size-full object-cover" loading="lazy">@else<x-icon name="sofa" class="size-5" />@endif</span>
                                        <span class="min-w-0 flex-1"><span class="block truncate text-sm font-medium">{{ $p['name'] }}</span><span class="block font-mono text-[0.7rem] text-ink-500" dir="ltr">{{ $p['sku'] }}</span></span>
                                    </div>
                                    <ul class="mt-2 space-y-1.5">
                                        @foreach ($p['variants'] as $v)
                                            <li wire:key="av-{{ $v['id'] }}" class="flex items-center gap-2">
                                                <span class="min-w-0 flex-1 text-xs">
                                                    @if ($p['multi'])<span class="block truncate text-sm">{{ $v['name'] }}</span>@endif
                                                    <span class="text-ink-500">@if ($v['price'])<x-admin.money :value="$v['price']" /> · @endif{{ $v['ok'] ? __('abandoned_admin.in_stock', ['count' => min($v['stock'], 99)]) : ($v['price'] ? __('abandoned_admin.out_of_stock') : __('abandoned_admin.not_at_branch')) }}@if ($v['in_cart']) · {{ __('abandoned_admin.in_cart', ['count' => $v['in_cart']]) }}@endif</span>
                                                </span>
                                                <button type="button" wire:click="addVariant('{{ $v['id'] }}')" wire:loading.attr="disabled" wire:target="{{ $busy }}" @disabled(! $v['ok']) class="btn-secondary size-11 shrink-0 !p-0" aria-label="{{ __('abandoned_admin.add_named', ['name' => $p['multi'] ? $p['name'].' · '.$v['name'] : $p['name']]) }}"><x-icon name="plus" class="size-4" /></button>
                                            </li>
                                        @endforeach
                                    </ul>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            @endif

            @if ($lines->isEmpty())
                <x-admin.empty :title="__('abandoned_admin.cart_empty')" :description="__('abandoned_admin.cart_empty_hint')" icon="cart" class="!py-8" />
            @else
                <ul class="divide-y divide-sand-100 dark:divide-ink-800" wire:loading.class="opacity-60" wire:target="{{ $busy }}">
                    @foreach ($lines as $line)
                        <li wire:key="al-{{ $line['id'] }}" class="flex gap-3 py-3">
                            <span class="grid size-16 shrink-0 place-items-center overflow-hidden rounded-xl bg-sand-100 text-ink-400 dark:bg-ink-800">
                                @if ($line['product']['image'])<img src="{{ $line['product']['image']['thumb_url'] }}" alt="" class="size-full object-cover" loading="lazy">@else<x-icon name="sofa" class="size-6" />@endif
                            </span>
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-medium text-ink-900 dark:text-ivory">{{ $line['product']['name'] }}</p>
                                @if ($line['variant_name'] && $line['variant_name'] !== $line['product']['name'])<p class="truncate text-xs text-ink-500">{{ $line['variant_name'] }}</p>@endif
                                <p class="mt-0.5 text-xs text-ink-500">
                                    @if ($line['unit_price'])<x-admin.money :value="$line['unit_price']['final']['amount']" /> {{ __('abandoned_admin.each') }}@endif
                                    @if ($line['price_changed'])<span class="badge-warning ms-1 !text-[0.65rem]">{{ __('abandoned_admin.price_changed') }}</span>@endif
                                </p>
                                @if ($line['issue'])
                                    <p class="mt-1 text-xs font-medium text-ruby-soft">{{ __('abandoned_admin.issue_'.$line['issue'], ['count' => $line['stock']]) }}</p>
                                @elseif ($line['stock'] <= 5)
                                    <p class="mt-1 text-xs text-amber-soft">{{ __('abandoned_admin.low_stock', ['count' => $line['stock']]) }}</p>
                                @endif
                                <div class="mt-2 flex items-center justify-between gap-2">
                                    @if ($canChange)
                                        <div class="inline-flex items-center rounded-xl border border-sand-300 dark:border-ink-600" role="group" aria-label="{{ __('abandoned_admin.quantity') }}">
                                            <button type="button" wire:click="bump('{{ $line['id'] }}', -1)" wire:loading.attr="disabled" wire:target="{{ $busy }}" @disabled($line['quantity'] <= 1) class="grid size-11 place-items-center rounded-s-xl hover:bg-sand-100 disabled:opacity-40 dark:hover:bg-ink-800" aria-label="{{ __('abandoned_admin.decrease') }}"><x-icon name="minus" class="size-4" /></button>
                                            <input type="number" min="1" max="{{ $window['max_quantity'] }}" value="{{ $line['quantity'] }}" data-no-dirty wire:change="setQuantity('{{ $line['id'] }}', parseInt($event.target.value) || 1)"
                                                   class="h-11 w-12 border-0 bg-transparent p-0 text-center text-sm tabular-nums focus:ring-0 [appearance:textfield] [&::-webkit-inner-spin-button]:appearance-none" aria-label="{{ __('abandoned_admin.quantity') }}">
                                            <button type="button" wire:click="bump('{{ $line['id'] }}', 1)" wire:loading.attr="disabled" wire:target="{{ $busy }}" @disabled($line['quantity'] >= $window['max_quantity']) class="grid size-11 place-items-center rounded-e-xl hover:bg-sand-100 disabled:opacity-40 dark:hover:bg-ink-800" aria-label="{{ __('abandoned_admin.increase') }}"><x-icon name="plus" class="size-4" /></button>
                                        </div>
                                    @else
                                        <span class="text-sm tabular-nums">× {{ $line['quantity'] }}</span>
                                    @endif
                                    <div class="flex items-center gap-1">
                                        <x-admin.money :value="$line['line_total']['amount']" class="text-sm font-semibold" />
                                        @if ($canChange)
                                            <button type="button" wire:click="removeLine('{{ $line['id'] }}')" wire:loading.attr="disabled" wire:target="{{ $busy }}" class="grid size-11 place-items-center rounded-xl text-ink-500 hover:bg-sand-100 hover:text-ruby-soft dark:hover:bg-ink-800" aria-label="{{ __('abandoned_admin.remove_named', ['name' => $line['product']['name']]) }}"><x-icon name="trash" class="size-4" /></button>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        {{-- Coupon --}}
        <section class="{{ $box }}" aria-labelledby="ab-coupon">
            <h3 id="ab-coupon" class="mb-2 text-xs font-semibold uppercase tracking-wider text-ink-500">{{ __('abandoned_admin.coupon') }}</h3>
            @if ($cart->coupon_code)
                <div class="flex items-center justify-between gap-2 rounded-xl border border-emerald-soft/30 bg-emerald-soft/5 px-3 py-1.5 text-sm">
                    <span class="inline-flex min-w-0 items-center gap-2"><x-icon name="percent" class="size-4 shrink-0 text-emerald-soft" /><span class="truncate font-mono" dir="ltr">{{ $cart->coupon_code }}</span>
                        @if ($quote->discounts === [] && $hasPlans)<span class="text-xs text-amber-soft">{{ __('abandoned_admin.coupon_no_effect') }}</span>@endif</span>
                    @if ($canChange)<button type="button" wire:click="removeCoupon" wire:loading.attr="disabled" wire:target="{{ $busy }}" class="btn-ghost min-h-11 text-xs">{{ __('abandoned_admin.remove') }}</button>@endif
                </div>
            @elseif ($canChange)
                <form wire:submit="applyCoupon" class="flex gap-2">
                    <label class="sr-only" for="ab-coupon-in">{{ __('abandoned_admin.coupon') }}</label>
                    <input id="ab-coupon-in" type="text" wire:model="couponInput" data-no-dirty class="input uppercase @error('couponInput') input-error @enderror" dir="ltr" maxlength="48" autocomplete="off" placeholder="{{ __('abandoned_admin.coupon_placeholder') }}" aria-describedby="ab-coupon-err">
                    <button type="submit" wire:loading.attr="disabled" wire:target="applyCoupon" class="btn-secondary min-h-11 shrink-0">{{ __('abandoned_admin.apply') }}</button>
                </form>
                @error('couponInput')<p id="ab-coupon-err" role="alert" class="mt-1.5 text-xs text-ruby-soft">{{ $message }}</p>@enderror
            @else
                <p class="text-sm text-ink-400">{{ __('abandoned_admin.coupon_none') }}</p>
            @endif
        </section>

        {{-- Totals: the real quote, the same numbers the customer sees --}}
        <section class="{{ $box }}" aria-label="{{ __('abandoned_admin.totals') }}">
            <dl class="space-y-1.5 text-sm">
                @if ($hasPlans)
                    <div class="flex justify-between"><dt class="text-ink-500">{{ __('abandoned_admin.subtotal') }}</dt><dd><x-admin.money :value="$quote->subtotal()->toDecimal()" /></dd></div>
                    @if (! $quote->itemDiscountTotal()->isZero())<div class="flex justify-between text-emerald-soft"><dt>{{ __('abandoned_admin.discount') }}</dt><dd>−<x-admin.money :value="$quote->itemDiscountTotal()->toDecimal()" /></dd></div>@endif
                    <div class="flex items-center justify-between gap-2"><dt class="text-ink-500">{{ __('abandoned_admin.delivery_fee') }}</dt>
                        <dd>@if ($free)<s class="me-1 text-ink-400"><x-admin.money :value="$quote->deliveryFeeTotal()->toDecimal()" /></s><span class="badge-success">{{ __('abandoned_admin.free_with', ['code' => $quote->couponCode]) }}</span>@else<x-admin.money :value="$quote->deliveryFeeTotal()->toDecimal()" />@endif</dd></div>
                    @if (! $quote->serviceFee->isZero())<div class="flex justify-between"><dt class="text-ink-500">{{ __('abandoned_admin.service_fee') }}</dt><dd><x-admin.money :value="$quote->serviceFee->toDecimal()" /></dd></div>@endif
                    @if (! $quote->taxTotal()->isZero())<div class="flex justify-between"><dt class="text-ink-500">{{ __('abandoned_admin.tax') }}</dt><dd><x-admin.money :value="$quote->taxTotal()->toDecimal()" /></dd></div>@endif
                    @if (! $quote->loyaltyDiscount->isZero())<div class="flex justify-between text-emerald-soft"><dt>{{ __('abandoned_admin.loyalty') }}</dt><dd>−<x-admin.money :value="$quote->loyaltyDiscount->toDecimal()" /></dd></div>@endif
                    <div class="flex justify-between border-t border-sand-200 pt-2 text-base font-semibold dark:border-ink-700"><dt>{{ __('abandoned_admin.total') }}</dt><dd><x-admin.money :value="$quote->grandTotal()->toDecimal()" /></dd></div>
                @else
                    <div class="flex justify-between text-base font-semibold"><dt>{{ __('abandoned_admin.subtotal') }}</dt><dd><x-admin.money :value="$window['subtotal']['amount']" /></dd></div>
                    <p class="text-xs text-ink-500">{{ __('abandoned_admin.totals_pending') }}</p>
                @endif
            </dl>
            <p class="mt-3 text-xs text-ink-500">{{ __('abandoned_admin.totals_note') }}</p>
        </section>
    </div>
@endif
