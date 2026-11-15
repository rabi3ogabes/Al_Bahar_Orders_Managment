@props(['reason'])
{{-- Plain-words reason a message was skipped or failed. n8n_http_NNN is the automation's own answer code. --}}
@php
    use Illuminate\Support\Facades\Lang;
    $text = match (true) {
        ! $reason => null,
        (bool) preg_match('/^n8n_http_(\d{3})$/', (string) $reason, $m) => __('messaging_admin.reason.n8n_http', ['code' => $m[1]]),
        Lang::has('messaging_admin.reason.'.$reason) => __('messaging_admin.reason.'.$reason),
        default => \Illuminate\Support\Str::headline((string) $reason),
    };
@endphp
@if ($text){{ $text }}@endif
