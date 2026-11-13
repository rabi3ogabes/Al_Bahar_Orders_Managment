@props(['theme'])
{{-- CSS-only stand-ins for the web store and the phone app in a design's colours and layout. Decorative, so hidden from screen readers.
     Fresh = brand-colour header with search, round category tiles, pill button, centre-button menu. Classic = light header, hero banner, plain menu. --}}
@php
    $c = $theme->colors;
    $t = \App\Domain\Cms\Models\BrandTheme::normalizeTokens($theme->tokens);
    $fresh = $theme->layout === 'fresh';
    $r = ['md' => '4px', 'lg' => '8px', 'xl' => '14px'][$t['radius']];
    $card = match ($t['card']) { 'outlined' => 'border:1px solid '.$c['primary'].'33', 'flat' => 'background:'.$c['primary_soft'], default => 'box-shadow:0 1px 4px '.$c['ink'].'22' };
    $bg = $t['background'] === 'gradient' ? 'linear-gradient(180deg,'.$c['primary_soft'].','.$c['background'].' 55%)' : $c['background'];
    $bar = fn (string $w, string $col, string $h = '4px') => '<i style="display:block;width:'.$w.';height:'.$h.';border-radius:99px;background:'.$col.'"></i>';
@endphp
<div class="grid grid-cols-[minmax(0,1.6fr)_minmax(0,1fr)] items-end gap-3" aria-hidden="true">
    {{-- Web store --}}
    <div class="overflow-hidden rounded-lg ring-1 ring-black/10 dark:ring-white/15" style="background: {{ $bg }}">
        <div class="flex items-center gap-2 px-2 py-1.5" style="{{ $t['header'] === 'brand' ? 'background:'.$c['primary'] : 'background:'.$c['surface'].';border-bottom:1px solid '.$c['primary'].'22' }}">
            <i class="size-2.5 rounded-full" style="background: {{ $t['header'] === 'brand' ? $c['accent'] : $c['primary'] }}"></i>
            @if ($fresh)
                <i class="mx-1 h-2.5 flex-1 rounded-full" style="background: {{ $c['surface'] }}"></i>
            @else
                <span class="flex flex-1 justify-center gap-2">{!! $bar('14px', $c['ink'].'55') !!}{!! $bar('14px', $c['ink'].'55') !!}{!! $bar('14px', $c['ink'].'55') !!}</span>
            @endif
            <i class="size-2.5 rounded-full" style="background: {{ $t['header'] === 'brand' ? $c['surface'] : $c['ink'].'55' }}"></i>
        </div>
        <div class="space-y-2 p-2">
            <div class="flex items-center justify-between gap-2 p-2.5" style="border-radius: {{ $r }}; background: linear-gradient(135deg, {{ $c['primary'] }}, {{ $c['primary_soft'] }}); {{ $fresh ? '' : 'background: '.$c['primary'] }}">
                <span class="space-y-1">{!! $bar('46px', '#FFFFFF', '5px') !!}{!! $bar('30px', '#FFFFFF99') !!}</span>
                <i class="block h-3.5 w-9" style="background: {{ $c['accent'] }}; border-radius: {{ $fresh ? '99px' : $r }}"></i>
            </div>
            @if ($fresh)
                <div class="flex justify-between px-1">
                    @foreach (range(1, 4) as $i)<span class="flex flex-col items-center gap-0.5"><i class="size-5 rounded-full" style="background: {{ $i % 2 ? $c['primary_soft'] : $c['accent'].'55' }}; box-shadow: inset 0 0 0 1.5px {{ $c['primary'] }}33"></i>{!! $bar('14px', $c['ink'].'44', '3px') !!}</span>@endforeach
                </div>
            @endif
            <div class="grid grid-cols-4 gap-1.5">
                @foreach (range(1, 4) as $i)
                    <span class="p-1" style="border-radius: {{ $r }}; background: {{ $c['surface'] }}; {{ $card }}">
                        <i class="mb-1 block h-6" style="border-radius: calc({{ $r }} - 2px); background: {{ $c['primary_soft'] }}"></i>
                        {!! $bar('80%', $c['ink'].'66', '3px') !!}<span class="mt-1 block">{!! $bar('45%', $c['primary'], '3px') !!}</span>
                    </span>
                @endforeach
            </div>
        </div>
    </div>

    {{-- Phone app --}}
    <div class="mx-auto w-full max-w-[96px] overflow-hidden rounded-[14px] ring-2 ring-ink-800/80 dark:ring-white/30" style="background: {{ $bg }}">
        <div class="px-1.5 pb-1.5 pt-2" style="{{ $t['header'] === 'brand' ? 'background:'.$c['primary'] : 'background:'.$c['surface'] }}">
            @if ($fresh)
                <span class="mb-1 block">{!! $bar('28px', '#FFFFFF', '4px') !!}</span>
                <i class="block h-3 rounded-full" style="background: {{ $c['surface'] }}"></i>
            @else
                <span class="flex items-center justify-between"><i class="size-2 rounded-full" style="background: {{ $c['primary'] }}"></i>{!! $bar('22px', $c['ink'].'66') !!}<i class="size-2 rounded-full" style="background: {{ $c['ink'] }}44"></i></span>
            @endif
        </div>
        <div class="space-y-1.5 p-1.5">
            <i class="block h-9" style="border-radius: {{ $r }}; background: linear-gradient(135deg, {{ $c['primary'] }}, {{ $c['primary_soft'] }}); {{ $fresh ? '' : 'background: '.$c['primary'] }}"></i>
            @if ($fresh)
                <div class="flex justify-between">@foreach (range(1, 4) as $i)<i class="size-4 rounded-full" style="background: {{ $i % 2 ? $c['primary_soft'] : $c['accent'].'66' }}; box-shadow: inset 0 0 0 1px {{ $c['primary'] }}33"></i>@endforeach</div>
            @endif
            <div class="grid grid-cols-2 gap-1">
                @foreach (range(1, 2) as $i)
                    <span class="p-1" style="border-radius: {{ $r }}; background: {{ $c['surface'] }}; {{ $card }}"><i class="mb-1 block h-6" style="border-radius: calc({{ $r }} - 2px); background: {{ $c['primary_soft'] }}"></i>{!! $bar('70%', $c['ink'].'66', '3px') !!}<span class="mt-1 block">{!! $bar('40%', $c['primary'], '3px') !!}</span></span>
                @endforeach
            </div>
            <i class="mx-auto block h-3 w-12" style="background: {{ $c['accent'] }}; border-radius: {{ $fresh ? '99px' : $r }}"></i>
        </div>
        <div class="relative flex items-center justify-around px-1 py-1.5" style="background: {{ $c['surface'] }}; border-top: 1px solid {{ $c['primary'] }}22">
            @foreach ([1, 2, 3, 4] as $i)
                @if ($t['nav'] === 'center_action' && $i === 3)<i class="-mt-3 block size-4 rounded-full ring-2 ring-white" style="background: {{ $c['accent'] }}"></i>
                @else<i class="size-2 rounded-sm" style="background: {{ $i === 1 ? $c['primary'] : $c['ink'].'44' }}"></i>@endif
            @endforeach
        </div>
    </div>
</div>
