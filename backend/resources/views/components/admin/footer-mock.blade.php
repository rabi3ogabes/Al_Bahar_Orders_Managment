@props(['design' => 'classic', 'items' => [], 'active' => 0, 'labels' => true, 'accent' => '#8A1538', 'badge' => true, 'large' => false])
{{-- A small HTML/CSS stand-in for the customer app's bottom menu. $items: list of ['key', 'icon', 'label']. Logical properties only, so it mirrors in Arabic. --}}
@php
    $iconFor = ['home' => 'home', 'grid' => 'layers', 'gavel' => 'gavel', 'bag' => 'bag', 'person' => 'user', 'receipt' => 'receipt', 'heart' => 'heart', 'search' => 'search', 'headset' => 'life-buoy', 'bell' => 'bell', 'tag' => 'tag', 'star' => 'star', 'store' => 'store', 'gift' => 'gift'];
    $sz = $large ? 'size-5' : 'size-4';
    $text = $large ? 'text-[10px]' : 'text-[8px]';
    $bar = match ($design) {
        'floating' => 'mx-3 mb-2 rounded-full bg-white shadow-lg ring-1 ring-black/5 dark:bg-ink-800',
        'glass' => 'mx-2 mb-2 rounded-2xl border border-white/60 bg-white/40 shadow backdrop-blur-md dark:border-white/10 dark:bg-white/10',
        'minimal' => 'border-t border-sand-200 bg-white dark:border-ink-700 dark:bg-ink-800',
        'bubble' => 'border-t border-sand-200 bg-white px-1 dark:border-ink-700 dark:bg-ink-800',
        default => 'border-t border-sand-200 bg-white dark:border-ink-700 dark:bg-ink-800',
    };
@endphp
<div dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}" class="pointer-events-none flex items-center justify-around gap-0.5 {{ $large ? 'h-14 px-2' : 'h-10 px-1' }} {{ $bar }}" aria-hidden="true">
    @foreach ($items as $i => $it)
        @php $on = $i === $active; @endphp
        @if ($design === 'bubble')
            <span class="flex items-center gap-1 rounded-full {{ $large ? 'px-3 py-1.5' : 'px-1.5 py-1' }}" style="{{ $on ? 'background: '.$accent.'; color:#fff' : 'color: #8a7f83' }}">
                <span class="relative"><x-icon :name="$iconFor[$it['icon']] ?? 'star'" :class="$sz" />@if ($badge && $it['key'] === 'cart')<i class="absolute -top-0.5 -end-0.5 size-1.5 rounded-full" style="background: {{ $accent }}"></i>@endif</span>
                @if ($on && $labels)<span class="{{ $text }} font-semibold">{{ $it['label'] }}</span>@endif
            </span>
        @else
            <span class="flex min-w-0 flex-col items-center gap-0.5 {{ $design === 'classic' && $on ? 'rounded-full px-2 py-0.5' : '' }}" style="color: {{ $on ? $accent : '#8a7f83' }}">
                <span class="relative"><x-icon :name="$iconFor[$it['icon']] ?? 'star'" :class="$sz" />@if ($badge && $it['key'] === 'cart')<i class="absolute -top-0.5 -end-0.5 size-1.5 rounded-full bg-brass-500"></i>@endif</span>
                @if ($design === 'minimal')
                    <i class="size-1 rounded-full" style="background: {{ $on ? $accent : 'transparent' }}"></i>
                @elseif ($labels)
                    <span class="{{ $text }} max-w-full truncate {{ $on ? 'font-semibold' : '' }}">{{ $it['label'] }}</span>
                @endif
            </span>
        @endif
    @endforeach
</div>
