@props(['delivery'])
{{--
    Proof of delivery: the photo(s) the driver took, who received the order and when, the driver, where the driver was (with an "Open in map" link) and the cash collected.
    <x-admin.sales.delivery-proof :delivery="$delivery" /> (a \App\Domain\Fulfillment\Models\Delivery). It draws nothing for a person who may not see that delivery's branch.
    Photos come through a private, branch-checked route; they are never public files.
--}}
@php $proof = app(\App\Domain\Operations\Services\DeliveryProofs::class)->for($delivery, auth()->user()); @endphp
@if ($proof)
    <section {{ $attributes->merge(['class' => 'space-y-4']) }} aria-label="{{ __('ops_live.proof.title') }}" data-delivery-proof="{{ $proof['delivery_id'] }}">
        @if ($proof['photos'] === [] && $proof['external'] === [])
            <p class="rounded-xl border border-dashed border-sand-300 p-4 text-sm text-ink-500 dark:border-ink-700 dark:text-ink-400">{{ __('ops_live.proof.none') }}</p>
        @endif

        @if ($proof['photos'] !== [])
            <ul class="grid gap-3 {{ count($proof['photos']) > 1 ? 'grid-cols-2' : 'grid-cols-1' }}">
                @foreach ($proof['photos'] as $photo)
                    <li wire:key="proof-{{ $photo['id'] }}">
                        <a href="{{ $photo['url'] }}" target="_blank" rel="noopener" class="group relative block overflow-hidden rounded-2xl border border-sand-200 bg-sand-50 dark:border-ink-700 dark:bg-ink-800" aria-label="{{ __('ops_live.proof.open_photo', ['n' => $photo['n']]) }}">
                            <img src="{{ $photo['url'] }}" alt="{{ __('ops_live.proof.photo', ['n' => $photo['n']]) }}" loading="lazy" decoding="async" class="aspect-[4/3] w-full object-cover transition group-hover:scale-[1.02] motion-reduce:transition-none">
                            @if ($photo['at'])<span class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-ink-950/70 to-transparent px-3 pb-2 pt-6 text-xs text-white tabular-nums" dir="ltr">{{ $photo['at'] }}</span>@endif
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif

        @foreach ($proof['external'] as $link)
            <a href="{{ $link }}" target="_blank" rel="noopener noreferrer" class="btn-secondary btn-sm min-h-11"><x-icon name="external" class="size-4" />{{ __('ops_live.proof.courier_open') }}</a>
        @endforeach

        <dl class="grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
            @if ($proof['recipient'])<div><dt class="text-xs text-ink-500">{{ __('ops_live.proof.received_by') }}</dt><dd class="font-medium">{{ $proof['recipient'] }}</dd></div>@endif
            @if ($proof['at'])<div><dt class="text-xs text-ink-500">{{ __('ops_live.proof.delivered_at') }}</dt><dd class="font-medium tabular-nums">{{ $proof['at'] }}</dd></div>@endif
            @if ($proof['driver'])<div><dt class="text-xs text-ink-500">{{ __('ops_live.proof.driver') }}</dt><dd class="font-medium">{{ $proof['driver'] }}</dd></div>@endif
            <div>
                <dt class="text-xs text-ink-500">{{ __('ops_live.proof.where') }}</dt>
                <dd>
                    @if ($proof['gps'])
                        <a href="{{ $proof['gps']['map_url'] }}" target="_blank" rel="noopener noreferrer" class="inline-flex min-h-11 items-center gap-1.5 font-medium hover:text-brass-600">
                            <x-icon name="map-pin" class="size-4 text-brass-600" /><span class="tabular-nums" dir="ltr">{{ number_format($proof['gps']['lat'], 5) }}, {{ number_format($proof['gps']['lng'], 5) }}</span>
                            <span class="text-xs font-normal text-brass-700 underline dark:text-brass-300">{{ __('ops_live.proof.open_map') }}</span>
                        </a>
                    @else
                        <span class="text-ink-500">{{ __('ops_live.proof.no_gps') }}</span>
                    @endif
                </dd>
            </div>
            @if ($proof['cod'])
                <div class="col-span-2">
                    <dt class="text-xs text-ink-500">{{ __('ops_live.proof.cod') }}</dt>
                    <dd class="font-medium tabular-nums">
                        @if ($proof['cod']['collected'])
                            {{ __('ops_live.proof.cod_collected', ['amount' => $proof['cod']['collected'], 'tender' => $proof['cod']['tender'] ? __('ops_live.proof.tender_'.$proof['cod']['tender']) : '—']) }}
                        @else
                            {{ __('ops_live.proof.cod_due', ['amount' => $proof['cod']['due']]) }}
                        @endif
                    </dd>
                </div>
            @endif
        </dl>
    </section>
@endif
