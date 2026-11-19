<?php

namespace App\Livewire\Admin;

use App\Domain\Identity\Services\AccessControl;
use App\Domain\Reports\Services\SystemHealth;
use Livewire\Component;

/**
 * The dashboard's "System health" card: is the scheduler running every task on time, is the queue draining, have jobs failed. Read-only, counts and
 * times only (nothing that could hold a secret), refreshed every minute by the page itself. Shown to the people who manage settings, checked again
 * here so the component cannot be mounted by anyone else.
 */
class SystemHealthCard extends Component
{
    public function mount(): void
    {
        abort_unless($this->allowed(), 403);
    }

    private function allowed(): bool
    {
        return app(AccessControl::class)->allowsGlobally(auth()->user(), 'settings.edit');
    }

    public function placeholder(): string
    {
        return '<div class="card card-pad mt-6 h-40 animate-pulse" aria-hidden="true"></div>';
    }

    public function render(SystemHealth $health)
    {
        abort_unless($this->allowed(), 403);

        return view('livewire.admin.system-health-card', ['h' => $health->snapshot()]);
    }
}
