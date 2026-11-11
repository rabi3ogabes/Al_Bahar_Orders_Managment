<?php

namespace App\Livewire\Admin\Concerns;

use App\Domain\Branches\Models\Branch;
use App\Domain\Identity\Services\AccessControl;
use App\Domain\Identity\Support\BranchScope;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

/**
 * Admin list screens: URL-synced search/branch filter/sort, pagination reset, and server-derived branch scope
 * (the ?branch filter can only narrow it, never widen it).
 */
trait WithAdminTable
{
    use InteractsWithAdmin, WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $branch = '';

    #[Url(except: '')]
    public string $sort = '';

    public int $perPage = 20;

    public function updated($property): void
    {
        if (in_array($property, ['search', 'branch', 'sort', 'perPage'], true) || str_starts_with($property, 'filter')) {
            $this->resetPage();
        }
    }

    protected function branchScope(string $permission): BranchScope
    {
        return app(AccessControl::class)->scope(auth()->user(), $permission)->narrow($this->branch ?: null);
    }

    /** Branches the user may filter by for a permission (for branch filter dropdowns). */
    protected function scopedBranches(string $permission)
    {
        $scope = app(AccessControl::class)->scope(auth()->user(), $permission);

        return Branch::query()->when(! $scope->all, fn ($q) => $q->whereIn('id', $scope->branchIds))->orderBy('sort_order')->get(['id', 'code', 'name_en', 'name_ar']);
    }
}
