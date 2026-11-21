<?php

namespace App\Domain\Search\Services;

use App\Domain\Identity\Models\User;
use App\Domain\Search\Support\SearchGroup;
use App\Domain\Search\Support\SearchQuery;
use Illuminate\Support\Facades\Blade;

/**
 * The dashboard's global search (Ctrl/Cmd+K): what a staff member typed, answered in groups (orders, customers, products and variants,
 * "Go to" pages), each group checked against that person's permission and branches by the class that builds it. A group the person may not see
 * is simply not there, with no sign that it exists.
 *
 * Nothing about the query is stored or logged. A lookup in a table needs three characters (two are enough for a menu page), a long query is cut
 * to SearchQuery::MAX_LENGTH, and every group is one bounded query on an index (see the migration 2026_11_22_120000 for the indexes and
 * docs/guides/SEARCH_AND_LIVE_BOARD.md for the plans measured on 300,000 orders).
 */
final class GlobalSearch
{
    public function __construct(
        private readonly OrderResults $orders,
        private readonly CustomerResults $customers,
        private readonly ProductResults $products,
        private readonly CommandResults $commands,
    ) {}

    /**
     * `state` is "short" (nothing to look for yet), "pages" (only the menu is searched; three characters search the tables too) or "ready".
     *
     * @return array{q: string, state: string, groups: list<array<string, mixed>>}
     */
    public function run(User $user, mixed $input): array
    {
        $query = SearchQuery::from($input);
        $state = match (true) {
            $query->searchesTables() => 'ready',
            $query->searchesCommands() => 'pages',
            default => 'short',
        };

        $pages = $this->commands->find($user, $query);
        $found = array_values(array_filter([
            $this->orders->find($user, $query),
            $this->customers->find($user, $query),
            $this->products->find($user, $query),
        ]));
        $groups = $pages?->lead ? [$pages, ...$found] : [...$found, ...($pages !== null ? [$pages] : [])];

        return ['q' => $query->raw, 'state' => $state, 'groups' => array_map($this->withIcons(...), $groups)];
    }

    /**
     * The group as the screen reads it. Each result gets the markup of its icon, drawn here from the dashboard's own icon table (nothing a customer or a
     * staff member typed goes into it), so the screen shows the same icons as the menu without carrying the whole table.
     *
     * @return array<string, mixed>
     */
    private function withIcons(SearchGroup $group): array
    {
        $out = $group->toArray();
        $out['items'] = array_map(fn (array $item) => $item + ['svg' => $this->icon((string) $item['icon'])], $group->items);

        return $out;
    }

    private function icon(string $name): string
    {
        static $drawn = [];

        return $drawn[$name] ??= trim(Blade::render('<x-icon :name="$name" class="size-4" />', ['name' => $name]));
    }
}
