@props(['part'])
{{--
    The print row of one branch part on the order page and in the order window: the picking list (every part) and the delivery note (delivery parts), each a link
    that opens the PDF in a new tab, and under them how often each was printed ("printed 2 times, last by Noor at 14:05"), so a second delivery note is never a surprise.
    <x-admin.sales.part-print :part="$f" /> (an \App\Domain\Orders\Models\OrderFulfillment). It draws nothing for a part that was cancelled or a branch the viewer may not see.
    The counter is read from the audit log (App\Domain\Orders\Printing\PrintHistory).
--}}
@php
    $may = $part->status->value !== 'cancelled' && app(\App\Domain\Identity\Services\AccessControl::class)->allows(auth()->user(), 'orders.view', $part->branch_id);
    $history = $may ? (app(\App\Domain\Orders\Printing\PrintHistory::class)->summaries([$part->id])[$part->id] ?? []) : [];
    $docs = [\App\Domain\Orders\Printing\PrintKind::Picking, ...($part->type === 'delivery' ? [\App\Domain\Orders\Printing\PrintKind::Note] : [])];
    $when = fn ($t) => \App\Support\BusinessTime::local($t)->isSameDay(\App\Support\BusinessTime::now()) ? \App\Support\BusinessTime::display($t, 'H:i') : \App\Support\BusinessTime::display($t, 'd M, H:i');
@endphp
@if ($may)
    <div {{ $attributes->merge(['class' => 'flex flex-col gap-3 border-t border-sand-100 px-5 py-3 sm:flex-row sm:flex-wrap sm:items-start sm:px-6 dark:border-ink-800']) }} data-part-print="{{ $part->id }}">
        @foreach ($docs as $doc)
            @php
                $isNote = $doc === \App\Domain\Orders\Printing\PrintKind::Note;
                $h = $history[$doc->value] ?? null;
                $route = $isNote ? 'admin.print.note.part' : 'admin.print.picking.part';
            @endphp
            <div class="min-w-0" wire:key="print-{{ $part->id }}-{{ $doc->value }}">
                <a href="{{ route($route, $part) }}" target="_blank" rel="noopener" class="btn-secondary btn-sm min-h-11" x-on:click="setTimeout(() => $wire.$refresh(), 2500)"
                   title="{{ $isNote ? __('print_docs.ui.delivery_note_hint') : __('print_docs.ui.picking_list_hint') }}">
                    <x-icon :name="$isNote ? 'truck' : 'clipboard'" class="size-4" />{{ $isNote ? __('print_docs.ui.delivery_note') : __('print_docs.ui.picking_list') }}
                </a>
                <p @class(['mt-1 text-xs', 'text-ink-500' => ! $h || ! $isNote || $h['count'] < 2, 'font-medium text-amber-soft' => $h && $isNote && $h['count'] >= 2]) data-print-count="{{ $doc->value }}">
                    @if ($h)
                        @if ($isNote && $h['count'] >= 2)<x-icon name="alert" class="me-1 inline size-3.5" />@endif{{ trans_choice('print_docs.ui.printed_n', $h['count'], ['count' => $h['count'], 'name' => $h['last_by'] ?? __('print_docs.ui.printed_someone'), 'time' => $when($h['last_at'])]) }}
                        @if ($isNote && $h['count'] >= 2)<span class="mt-0.5 block font-normal">{{ __('print_docs.ui.reprint_warning') }}</span>@endif
                    @else
                        {{ __('print_docs.ui.printed_never') }}
                    @endif
                </p>
            </div>
        @endforeach
    </div>
@endif
