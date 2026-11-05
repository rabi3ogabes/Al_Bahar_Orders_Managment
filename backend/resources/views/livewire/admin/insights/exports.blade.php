<div @if ($pending) wire:poll.4s @endif>
    <x-admin.page-header :title="__('nav.exports')" :eyebrow="__('nav.insights')" :description="__('content_admin.exports_hint')">
        <x-slot:actions><a href="{{ route('admin.reports.index') }}" wire:navigate class="btn-secondary"><x-icon name="chart" class="size-4" />{{ __('nav.reports') }}</a></x-slot:actions>
    </x-admin.page-header>

    <div class="card">
        @if ($exports->isEmpty())
            <x-admin.empty :title="__('content_admin.exports_empty')" :description="__('content_admin.exports_empty_hint')" icon="download">
                <a href="{{ route('admin.reports.index') }}" wire:navigate class="btn-primary btn-sm">{{ __('content_admin.go_to_reports') }}</a>
            </x-admin.empty>
        @else
            <ul class="divide-y divide-sand-100 dark:divide-ink-800">
                @foreach ($exports as $e)
                    <li class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:px-6" wire:key="ex-{{ $e->id }}">
                        <span class="grid size-10 shrink-0 place-items-center rounded-xl {{ $e->status === 'completed' ? 'bg-emerald-soft/10 text-emerald-soft' : ($e->status === 'failed' ? 'bg-ruby-soft/10 text-ruby-soft' : 'bg-sand-100 text-ink-500 dark:bg-ink-800') }}">
                            <x-icon :name="$e->status === 'completed' ? 'file-text' : ($e->status === 'failed' ? 'alert' : 'refresh')" class="size-5 {{ in_array($e->status, ['queued', 'processing'], true) ? 'animate-spin' : '' }}" />
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="font-medium text-ink-900 dark:text-ivory">{{ \Illuminate\Support\Facades\Lang::has('content_admin.r_'.$e->type) ? __('content_admin.r_'.$e->type) : $e->type }}</p>
                            <p class="text-xs text-ink-500 tabular-nums">{{ ($e->filters['from'] ?? '') }} – {{ ($e->filters['to'] ?? '') }} · {{ __('content_admin.requested_at', ['at' => \App\Support\BusinessTime::display($e->created_at)]) }}
                                @if ($e->status === 'completed') · {{ trans_choice('content_admin.n_rows', $e->row_count, ['count' => number_format($e->row_count)]) }}@endif
                                @if ($e->status === 'failed' && $e->error) · <span class="text-ruby-soft">{{ \Illuminate\Support\Str::limit($e->error, 80) }}</span>@endif</p>
                        </div>
                        <x-admin.status :value="$e->status" />
                        @if ($links[$e->id] ?? null)
                            <a href="{{ $links[$e->id] }}" class="btn-primary btn-sm"><x-icon name="download" class="size-4" />{{ __('content_admin.download') }}</a>
                        @elseif ($e->status === 'completed')
                            <span class="text-xs text-ink-500">{{ __('content_admin.link_expired') }}</span>
                        @endif
                    </li>
                @endforeach
            </ul>
            @if ($exports->hasPages())<div class="border-t border-sand-100 p-4 dark:border-ink-800">{{ $exports->links('components.admin.pagination') }}</div>@endif
        @endif
    </div>
    <p class="mt-4 text-xs text-ink-500"><x-icon name="lock" class="me-1 inline size-3.5" />{{ __('content_admin.exports_security') }}</p>
</div>
