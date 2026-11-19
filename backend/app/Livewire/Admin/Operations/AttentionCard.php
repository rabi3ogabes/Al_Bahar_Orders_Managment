<?php

namespace App\Livewire\Admin\Operations;

use App\Domain\Operations\Services\AttentionFeed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The small "Needs attention" card for the dashboard home: the worst few lines, the total and a link to the full list.
 * Drop it in with `<livewire:admin.operations.attention-card />`; it shows nothing to a person who may not see the inbox.
 */
class AttentionCard extends Component
{
    /** How many lines the card shows. Locked: a tampered request cannot ask for a long list. */
    #[Locked]
    public int $limit = 5;

    public function mount(int $limit = 5): void
    {
        $this->limit = max(1, min($limit, 10));
    }

    public function render(AttentionFeed $feed)
    {
        $user = auth()->user();
        $list = $user !== null && AttentionFeed::canSee($user) ? $feed->for($user) : null;

        return view('livewire.admin.operations.attention-card', [
            'list' => $list,
            'top' => $list?->top($this->limit) ?? collect(),
            'counts' => $list?->counts() ?? ['total' => 0, 'red' => 0, 'amber' => 0, 'ok' => 0],
        ]);
    }
}
