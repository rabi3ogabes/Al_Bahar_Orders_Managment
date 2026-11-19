<?php

namespace App\Livewire\Admin\Operations;

use App\Domain\Operations\Services\AttentionFeed;
use Livewire\Component;

/**
 * The live count beside "Needs attention" in the sidebar: red when something is late, amber when something is getting late, brass otherwise.
 * It refreshes every 30 seconds and at once when a new order arrives, and the count is remembered for a few seconds per person
 * ({@see AttentionFeed::summary}), so showing it on every page costs almost nothing.
 */
class AttentionBadge extends Component
{
    public function placeholder(): string
    {
        return '<span class="ms-auto"></span>';
    }

    public function render(AttentionFeed $feed)
    {
        $user = auth()->user();

        return view('livewire.admin.operations.attention-badge', [
            'summary' => $user !== null && AttentionFeed::canSee($user) ? $feed->summary($user) : ['total' => 0, 'red' => 0, 'amber' => 0],
        ]);
    }
}
