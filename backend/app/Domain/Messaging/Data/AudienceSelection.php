<?php

namespace App\Domain\Messaging\Data;

/**
 * Who a send is for: customers ticked one by one (`ids`), or "everyone matching these filters" (`all`, minus the ones un-ticked since).
 * Either way the server re-resolves it against what the signed-in staff member may see, so a tampered list can never reach beyond that.
 */
final class AudienceSelection
{
    /**
     * @param  list<string>  $ids  customer ids (ticked) or, with $all, the ids left out
     * @param  array<string, mixed>  $filters  the customers list filters (only used with $all)
     */
    private function __construct(public readonly array $ids, public readonly bool $all, public readonly array $filters) {}

    /** @param list<string> $ids */
    public static function ofIds(array $ids): self
    {
        return new self(array_values(array_unique(array_map('strval', $ids))), false, []);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  list<string>  $except
     */
    public static function matching(array $filters, array $except = []): self
    {
        return new self(array_values(array_unique(array_map('strval', $except))), true, $filters);
    }

    /** What is kept in the batch record. @return array<string, mixed> */
    public function describe(int $count): array
    {
        return $this->all ? ['mode' => 'filters', 'filters' => $this->filters, 'except' => count($this->ids), 'count' => $count] : ['mode' => 'ids', 'count' => $count];
    }
}
