<?php

namespace App\Domain\Identity\Support;

use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;

/**
 * The set of branches an authenticated principal may act on for a given permission.
 * Always derived server-side from role assignments — never from client input.
 */
final class BranchScope
{
    /** @param list<string> $branchIds */
    private function __construct(public readonly bool $all, public readonly array $branchIds) {}

    public static function all(): self
    {
        return new self(true, []);
    }

    public static function none(): self
    {
        return new self(false, []);
    }

    public static function of(array $ids): self
    {
        return new self(false, array_values(array_unique($ids)));
    }

    public function isEmpty(): bool
    {
        return ! $this->all && $this->branchIds === [];
    }

    public function contains(?string $branchId): bool
    {
        if ($branchId === null) {
            return $this->all;
        }

        return $this->all || in_array($branchId, $this->branchIds, true);
    }

    /** Constrain a query to the scope. An empty scope yields no rows (fail closed). */
    public function apply(QueryBuilder $query, string $column = 'branch_id'): QueryBuilder
    {
        if ($this->all) {
            return $query;
        }
        if ($this->branchIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn($column, $this->branchIds);
    }

    /** Intersect with a client-requested branch filter (the filter can only narrow, never widen). */
    public function narrow(?string $requestedBranchId): self
    {
        if ($requestedBranchId === null || $requestedBranchId === '') {
            return $this;
        }

        return $this->contains($requestedBranchId) ? self::of([$requestedBranchId]) : self::none();
    }
}
