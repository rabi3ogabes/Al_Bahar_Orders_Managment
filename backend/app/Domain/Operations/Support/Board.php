<?php

namespace App\Domain\Operations\Support;

use Illuminate\Support\Collection;

/**
 * What the live board shows: the lanes left to right (each with its cards, its counts and how many more there are), the branches the person may
 * filter by, and whether the list of open parts was cut because there are more than the board reads at once.
 *
 * `lanes` is keyed by lane key and every key of LiveBoard::LANES is there, even when empty, so the screen always draws the same six columns.
 * A lane is ['key', 'cards' => Collection<BoardCard> (the ones shown), 'total', 'more', 'red', 'amber'].
 */
final class Board
{
    /**
     * @param  array<string, array{key: string, cards: Collection<int, BoardCard>, total: int, more: int, red: int, amber: int}>  $lanes
     * @param  list<array{id: string, code: string, name: string}>  $branches
     */
    public function __construct(
        public readonly array $lanes,
        public readonly array $branches,
        public readonly bool $truncated,
        public readonly int $open,
    ) {}

    /** @return array{red: int, amber: int, ok: int} how many of the open parts are late, getting late and on time */
    public function counts(): array
    {
        $red = $amber = 0;
        foreach ($this->lanes as $lane) {
            $red += $lane['red'];
            $amber += $lane['amber'];
        }

        return ['red' => $red, 'amber' => $amber, 'ok' => max(0, $this->open - $red - $amber)];
    }
}
