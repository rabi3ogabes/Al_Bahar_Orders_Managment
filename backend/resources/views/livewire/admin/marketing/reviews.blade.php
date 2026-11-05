@php
    $tabs = ['reviews' => ['star', __('marketing_admin.tab_reviews'), $pending['reviews'] ?: null], 'questions' => ['message', __('marketing_admin.tab_questions'), $pending['questions'] ?: null]];
    $statuses = $tab === 'questions' ? ['pending', 'published', 'rejected'] : ['pending', 'approved', 'rejected', 'hidden'];
@endphp
<div>
    <x-admin.page-header :title="__('nav.reviews')" :eyebrow="__('nav.marketing')" :description="__('marketing_admin.reviews_hint')" />
    <x-admin.tabs :tabs="$tabs" :current="$tab" />

    <div class="card">
        <x-admin.toolbar>
            <x-admin.search :placeholder="__('marketing_admin.reviews_search')" />
            <select wire:model.live="filterStatus" class="input sm:w-44" aria-label="{{ __('admin.status') }}">
                <option value="">{{ __('admin.all_statuses') }}</option>
                @foreach ($statuses as $st)<option value="{{ $st }}">{{ __('status.'.$st) }}</option>@endforeach
            </select>
            @if ($tab === 'reviews')
                <select wire:model.live="filterRating" class="input sm:w-36" aria-label="{{ __('marketing_admin.rating') }}">
                    <option value="">{{ __('marketing_admin.any_rating') }}</option>
                    @for ($i = 5; $i >= 1; $i--)<option value="{{ $i }}">{{ str_repeat('★', $i) }}</option>@endfor
                </select>
            @endif
        </x-admin.toolbar>
        @if ($rows->isEmpty())
            <x-admin.empty :title="$tab === 'questions' ? __('marketing_admin.questions_empty') : __('marketing_admin.reviews_empty')" :description="__('marketing_admin.reviews_empty_hint')" :icon="$tab === 'questions' ? 'message' : 'star'" />
        @elseif ($tab === 'reviews')
            <ul class="divide-y divide-sand-100 dark:divide-ink-800">
                @foreach ($rows as $r)
                    <li class="grid gap-4 p-5 sm:px-6 lg:grid-cols-[1fr_20rem] [&>*]:min-w-0" wire:key="rv-{{ $r->id }}">
                        <div>
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="text-brass-500" aria-label="{{ __('marketing_admin.n_stars', ['n' => $r->rating]) }}" dir="ltr">{{ str_repeat('★', $r->rating) }}<span class="text-sand-300">{{ str_repeat('★', 5 - $r->rating) }}</span></span>
                                <x-admin.status :value="$r->status" />
                                @if ($r->is_verified_purchase)<span class="badge-success"><x-icon name="check" class="size-3" />{{ __('marketing_admin.verified_purchase') }}</span>@endif
                                @if ($r->report_count)<span class="badge-danger"><x-icon name="alert" class="size-3" />{{ trans_choice('marketing_admin.n_reports', $r->report_count, ['count' => $r->report_count]) }}</span>@endif
                            </div>
                            <p class="mt-2 text-sm text-ink-500">{{ $r->product?->trans('name') }} · {{ $r->user?->name }} · <span class="tabular-nums">{{ \App\Support\BusinessTime::display($r->created_at, 'd M Y') }}</span></p>
                            @if ($r->title)<p class="mt-2 font-medium text-ink-900 dark:text-ivory" dir="auto">{{ $r->title }}</p>@endif
                            @if ($r->body)<p class="mt-1 whitespace-pre-line text-sm text-ink-700 dark:text-sand-100" dir="auto">{{ $r->body }}</p>@endif
                            @if ($r->media->isNotEmpty())
                                <div class="mt-3 flex gap-2">@foreach ($r->media as $m)<img src="{{ \Illuminate\Support\Facades\Storage::disk($m->disk)->url($m->path) }}" alt="" class="size-16 rounded-lg object-cover" loading="lazy">@endforeach</div>
                            @endif
                            @if ($r->moderation_note)<p class="mt-2 text-xs text-ink-500"><x-icon name="message" class="me-1 inline size-3" />{{ $r->moderation_note }}</p>@endif
                        </div>
                        @if ($canModerate)
                            <div class="space-y-2">
                                <input wire:model="notes.{{ $r->id }}" class="input py-2 text-sm" placeholder="{{ __('marketing_admin.moderation_note_ph') }}" aria-label="{{ __('marketing_admin.moderation_note') }}">
                                @error('notes.'.$r->id)<p class="field-error">{{ $message }}</p>@enderror
                                <div class="flex flex-wrap gap-2">
                                    @if ($r->status !== 'approved')<button type="button" wire:click="moderate('{{ $r->id }}', 'approved')" class="btn-primary btn-sm"><x-icon name="check" class="size-4" />{{ __('admin.approve') }}</button>@endif
                                    @if ($r->status === 'approved')<button type="button" wire:click="moderate('{{ $r->id }}', 'hidden')" class="btn-secondary btn-sm"><x-icon name="eye" class="size-4" />{{ __('marketing_admin.hide') }}</button>@endif
                                    @if ($r->status !== 'rejected')<button type="button" wire:click="moderate('{{ $r->id }}', 'rejected')" class="btn-ghost btn-sm text-ruby-soft">{{ __('admin.reject') }}</button>@endif
                                </div>
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
        @else
            <ul class="divide-y divide-sand-100 dark:divide-ink-800">
                @foreach ($rows as $q)
                    <li class="space-y-3 p-5 sm:px-6" wire:key="q-{{ $q->id }}">
                        <div class="flex flex-wrap items-center gap-2"><x-admin.status :value="$q->status" /><span class="text-sm text-ink-500">{{ $products[$q->product_id]?->trans('name') }} · {{ $q->user?->name }} · {{ \App\Support\BusinessTime::display($q->created_at, 'd M Y') }}</span></div>
                        <p class="font-medium text-ink-900 dark:text-ivory" dir="auto"><span class="me-1 text-brass-600">{{ __('marketing_admin.q') }}</span>{{ $q->question }}</p>
                        @if ($canModerate)
                            <div class="flex flex-col gap-2 sm:flex-row sm:items-start">
                                <div class="flex-1"><textarea wire:model="answers.{{ $q->id }}" rows="2" class="input" dir="auto" placeholder="{{ __('marketing_admin.answer_ph') }}" aria-label="{{ __('marketing_admin.answer') }}"></textarea>@error('answers.'.$q->id)<p class="field-error">{{ $message }}</p>@enderror</div>
                                <div class="flex gap-2">
                                    <button type="button" wire:click="answer('{{ $q->id }}', 'rejected')" class="btn-ghost btn-sm text-ruby-soft">{{ __('admin.reject') }}</button>
                                    <button type="button" wire:click="answer('{{ $q->id }}', 'published')" class="btn-primary btn-sm"><x-icon name="check" class="size-4" />{{ $q->status === 'published' ? __('admin.save') : __('marketing_admin.publish_answer') }}</button>
                                </div>
                            </div>
                        @elseif ($q->answer)
                            <p class="text-sm text-ink-700 dark:text-sand-100" dir="auto"><span class="me-1 text-brass-600">{{ __('marketing_admin.a') }}</span>{{ $q->answer }}</p>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
        @if ($rows->hasPages())<div class="border-t border-sand-100 p-4 dark:border-ink-800">{{ $rows->links('components.admin.pagination') }}</div>@endif
    </div>
</div>
