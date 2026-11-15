<?php

namespace App\Domain\Messaging\Data;

/** What a send would do right now, shown before the staff member confirms. */
final class SendPlan
{
    /**
     * @param  array<string, array{ok: int, skipped: int}>  $channels
     * @param  array<string, int>  $reasons  skip reason => customers (any channel)
     * @param  list<array{id: string, name: string, reason: string}>  $examples  a few skipped customers, by name
     */
    public function __construct(
        public readonly int $total,
        public readonly int $max,
        public readonly int $reachable,
        public readonly array $channels,
        public readonly array $reasons,
        public readonly array $examples,
    ) {}

    public function overCap(): bool
    {
        return $this->total > $this->max;
    }

    public function skipped(): int
    {
        return min($this->total, $this->max) - $this->reachable;
    }
}
