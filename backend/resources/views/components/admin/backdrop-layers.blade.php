@props(['image' => null, 'sky' => 'w-full'])
{{--
    The picture layer of a page-background preview (docs/api/PAGE_BACKDROP.md): the built-in skyline (a CSS mask of the brand SVG, tinted)
    or the admin's picture, at the chosen strength. It sits under the page content, takes no pointer input and is hidden from screen readers.
    It reads two things from the surrounding Alpine scope: op (strength in percent) and skyColor, and the style and fit from the Livewire form.
    $sky is the width class of the skyline: 195% on a phone (never narrower than 760 logical px, so the centre shows) and 100% on the web.
--}}
@php $skyline = asset('brand/qatar-skyline.svg'); @endphp
<div class="pointer-events-none absolute inset-0 overflow-hidden" aria-hidden="true">
    <div x-cloak x-show="$wire.form.style === 'skyline'" class="absolute bottom-0 left-1/2 aspect-[1600/340] -translate-x-1/2 {{ $sky }}"
         style="-webkit-mask: url('{{ $skyline }}') center / 100% 100% no-repeat; mask: url('{{ $skyline }}') center / 100% 100% no-repeat;"
         :style="{ backgroundColor: skyColor, opacity: op / 100 }"></div>
    @if ($image)
        <div x-cloak x-show="$wire.form.style === 'image'" class="absolute inset-0 bg-no-repeat" style="background-image: url('{{ $image }}')"
             :style="{ opacity: op / 100, backgroundSize: $wire.form.fit === 'cover' ? 'cover' : '100% auto', backgroundPosition: $wire.form.fit === 'cover' ? 'center' : 'center bottom' }"></div>
    @endif
</div>
