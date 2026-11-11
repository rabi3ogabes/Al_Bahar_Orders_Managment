<?php

namespace App\Livewire\Admin\Concerns;

use App\Domain\Identity\Services\AccessControl;
use App\Support\DomainException;

/** Authorization and feedback helpers shared by every admin Livewire component. */
trait InteractsWithAdmin
{
    protected function authorizeBranch(string $permission, ?string $branchId): void
    {
        app(AccessControl::class)->authorize(auth()->user(), $permission, $branchId);
    }

    public function can(string $permission, ?string $branchId = null): bool
    {
        return app(AccessControl::class)->allows(auth()->user(), $permission, $branchId);
    }

    protected function canGlobally(string $permission): bool
    {
        return app(AccessControl::class)->allowsGlobally(auth()->user(), $permission);
    }

    protected function toast(string $message, string $type = 'success'): void
    {
        $this->dispatch('toast', type: $type, message: $message);
    }

    /** Run a domain action, turning DomainExceptions into a friendly toast instead of an error page. */
    protected function attempt(callable $action, ?string $success = null): mixed
    {
        try {
            $result = $action();
            if ($success) {
                $this->toast($success);
            }

            return $result ?? true;
        } catch (DomainException $e) {
            $this->toast($e->getMessage(), 'error');

            return null;
        }
    }
}
