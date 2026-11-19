<?php

namespace App\Domain\Operations\Support;

use Illuminate\Support\Collection;

/** What the inbox shows: the items (worst and oldest first), whether a long list was cut, and the branches the person can filter by. */
final class AttentionList
{
    /**
     * @param  Collection<int, AttentionItem>  $items
     * @param  list<array{id: string, code: string, name: string}>  $branches
     */
    public function __construct(public readonly Collection $items, public readonly bool $truncated, public readonly array $branches) {}

    /** @return array{total: int, red: int, amber: int, ok: int} */
    public function counts(): array
    {
        return [
            'total' => $this->items->count(),
            'red' => $this->items->where('state', OpsAge::RED)->count(),
            'amber' => $this->items->where('state', OpsAge::AMBER)->count(),
            'ok' => $this->items->where('state', OpsAge::OK)->count(),
        ];
    }

    /** The worst `$n` items, for a small card. @return Collection<int, AttentionItem> */
    public function top(int $n): Collection
    {
        return $this->items->take($n)->values();
    }
}
