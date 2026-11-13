{{-- Errors and warnings from the real quote engine and the cart --}}
@if ($errors_->isNotEmpty() || $warnings_->isNotEmpty())
    <div class="space-y-2" aria-live="polite">
        @foreach ($errors_ as $e)<p class="flex gap-2 rounded-xl bg-ruby-soft/10 px-3 py-2 text-sm text-ruby-soft"><x-icon name="alert" class="mt-0.5 size-4 shrink-0" />{{ $e['message'] }}</p>@endforeach
        @foreach ($warnings_ as $w)<p class="flex gap-2 rounded-xl bg-amber-soft/10 px-3 py-2 text-sm text-amber-soft"><x-icon name="info" class="mt-0.5 size-4 shrink-0" />{{ $w['message'] }}</p>@endforeach
    </div>
@endif
