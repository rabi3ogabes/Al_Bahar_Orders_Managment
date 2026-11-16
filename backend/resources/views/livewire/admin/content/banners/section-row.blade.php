@php
    $icons = ['categories' => 'layers', 'featured_products' => 'star', 'new_arrivals' => 'sparkles', 'best_sellers' => 'chart', 'recommended' => 'gift', 'product_collection' => 'sofa',
        'brands' => 'crown', 'promotions' => 'percent', 'upcoming_auctions' => 'calendar', 'live_auctions' => 'gavel'];
    $s = $b->section;
    $visible = $s->status === 'published' && (! $s->starts_at || $s->starts_at->lte($now)) && (! $s->ends_at || $s->ends_at->gt($now));
@endphp
<li data-move-item wire:key="blk-{{ $s->id }}" class="relative ps-11 sm:ps-14">
    <span class="absolute start-0 top-1/2 grid size-8 -translate-y-1/2 place-items-center rounded-full border border-sand-300 bg-ivory font-mono text-xs text-ink-400 sm:size-9 dark:border-ink-600 dark:bg-ink-900" aria-hidden="true">{{ $b->position }}</span>
    <div class="flex items-center gap-3 rounded-2xl border border-dashed border-sand-300 bg-sand-50/60 px-4 py-2.5 text-sm text-ink-500 dark:border-ink-700 dark:bg-ink-800/30">
        <x-icon :name="$icons[$s->type] ?? 'layers'" class="size-4 shrink-0 text-ink-400" />
        <span class="min-w-0 flex-1 truncate"><span class="font-medium text-ink-700 dark:text-sand-200">{{ $b->name }}</span> <span class="text-xs">· {{ __('content_admin.type_'.$s->type) }}</span></span>
        @unless ($visible)<span class="badge-neutral">{{ $s->status === 'published' ? __('status.scheduled') : __('content_admin.hidden') }}</span>@endunless
        <a href="{{ route('admin.homepage.index') }}" wire:navigate class="shrink-0 text-xs font-medium text-brass-700 hover:underline dark:text-brass-300" title="{{ __('banners_admin.edit_in_homepage') }}" aria-label="{{ __('banners_admin.edit_in_homepage') }}: {{ $b->name }}"><span class="hidden sm:inline">{{ __('banners_admin.edit_in_homepage') }}</span><x-icon name="external" class="size-4 sm:hidden" /></a>
    </div>
</li>
