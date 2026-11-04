<?php

namespace App\Domain\Inventory\Exceptions;

use App\Support\DomainException;

class InsufficientStock extends DomainException
{
    /** @param list<array{branch_id: string, variant_id: string, requested: int, available: int}> $shortages */
    public static function for(array $shortages, string $branchName = ''): self
    {
        $first = $shortages[0];

        return new self('insufficient_stock', __('errors.insufficient_stock', ['available' => max(0, $first['available']), 'branch' => $branchName]), 422, ['shortages' => $shortages]);
    }
}
