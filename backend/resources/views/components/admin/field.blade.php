@props(['label' => null, 'for' => null, 'error' => null, 'hint' => null, 'required' => false])
@php $error ??= $for; @endphp
<div {{ $attributes->merge(['class' => '']) }}>
    @if ($label)
        <label @if($for) for="{{ $for }}" @endif class="field-label">{{ $label }}@if($required)<span class="text-ruby-soft ms-0.5" aria-hidden="true">*</span>@endif</label>
    @endif
    {{ $slot }}
    @if ($error)
        @error($error)<p class="field-error" role="alert">{{ $message }}</p>@enderror
    @endif
    @if ($hint)<p class="field-hint">{{ $hint }}</p>@endif
</div>
