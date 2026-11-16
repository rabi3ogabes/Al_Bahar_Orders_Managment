@props(['image' => null, 'sky' => 'w-full', 'tile' => '40px'])
{{--
    The picture layer of a page-background preview (docs/api/PAGE_BACKDROP.md): the chosen built-in Qatar artwork (a CSS mask of its brand
    SVG, tinted) or the admin's picture, at the chosen strength. It sits under the page content, takes no pointer input and is hidden from
    screen readers. It reads two things from the surrounding Alpine scope: op (strength in percent) and skyColor, and the style, scene and fit
    from the Livewire form, so a click on a model in the gallery changes the preview at once (every drawing is rendered here and only the
    chosen one is shown).
    $sky is the width class of a scene that stands on the bottom edge: 195% on a phone (never narrower than 760 logical px, so the centre
    shows) and 100% on the web. $tile is one cell of the repeating pattern, scaled from the real 168 px in the same proportion.
--}}
@php $scenes = \App\Domain\Cms\Support\BackdropScenes::all(); @endphp
<div class="pointer-events-none absolute inset-0 overflow-hidden" aria-hidden="true">
    @foreach ($scenes as $key => $scene)
        @if ($scene['tile'])
            <div x-cloak x-show="$wire.form.style === 'skyline' && $wire.form.scene === '{{ $key }}'" class="absolute inset-0"
                 style="-webkit-mask: url('{{ $scene['url'] }}') 0 0 / {{ $tile }} {{ $tile }} repeat; mask: url('{{ $scene['url'] }}') 0 0 / {{ $tile }} {{ $tile }} repeat;"
                 :style="{ backgroundColor: skyColor, opacity: op / 100 }"></div>
        @else
            <div x-cloak x-show="$wire.form.style === 'skyline' && $wire.form.scene === '{{ $key }}'"
                 class="absolute bottom-0 left-1/2 -translate-x-1/2 {{ $sky }}" style="aspect-ratio: {{ $scene['width'] }} / {{ $scene['height'] }}; -webkit-mask: url('{{ $scene['url'] }}') center / 100% 100% no-repeat; mask: url('{{ $scene['url'] }}') center / 100% 100% no-repeat;"
                 :style="{ backgroundColor: skyColor, opacity: op / 100 }"></div>
        @endif
    @endforeach
    @if ($image)
        <div x-cloak x-show="$wire.form.style === 'image'" class="absolute inset-0 bg-no-repeat" style="background-image: url('{{ $image }}')"
             :style="{ opacity: op / 100, backgroundSize: $wire.form.fit === 'cover' ? 'cover' : '100% auto', backgroundPosition: $wire.form.fit === 'cover' ? 'center' : 'center bottom' }"></div>
    @endif
</div>
