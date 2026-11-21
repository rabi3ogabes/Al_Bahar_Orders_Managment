<?php

namespace App\Domain\Search\Services;

use App\Domain\Identity\Models\User;
use App\Domain\Search\Support\SearchGroup;
use App\Domain\Search\Support\SearchQuery;
use App\Support\AdminNavigation;
use App\Support\SearchNormalizer;
use Illuminate\Support\Facades\Lang;

/**
 * "Go to" for the global search: the pages of the dashboard the person may open, found by their name in English or in Arabic (whatever the
 * dashboard language is) or by a word people use for them ("cash", "stocktake", "till", "جرد"; the words are in lang/en/search.php and lang/ar/search.php).
 *
 * The pages come from {@see AdminNavigation}, the menu the person sees, so a page they may not open is never offered, and a new page of the
 * menu is found without anyone editing this class. Every typed word must start a word of the page's name, its words or its menu group.
 * No query: the menu is already in memory.
 */
final class CommandResults
{
    /** How well a page matches: its own name first, then the words people use for it, then the menu group it sits in. */
    private const BY_NAME = 0;

    private const BY_WORD = 1;

    private const BY_GROUP = 2;

    public function find(User $user, SearchQuery $query): ?SearchGroup
    {
        if (! $query->searchesCommands()) {
            return null;
        }
        $typed = array_values(array_filter(explode(' ', $query->text), fn (string $w) => $w !== ''));
        $hits = [];
        foreach (AdminNavigation::for($user) as $g => $group) {
            foreach ($group['items'] as $i => $item) {
                $rank = $this->rank($typed, $item, $group['label']);
                if ($rank !== null) {
                    $hits[] = ['rank' => $rank, 'at' => $g * 1000 + $i, 'item' => $item, 'group' => $group['label']];
                }
            }
        }
        if ($hits === []) {
            return null;
        }
        usort($hits, fn (array $a, array $b) => [$a['rank'], $a['at']] <=> [$b['rank'], $b['at']]);

        $items = array_map(fn (array $h) => [
            'id' => $h['item']['route'],
            'title' => __($h['item']['label']),
            'subtitle' => __($h['group']),
            'badge' => null,
            'meta' => null,
            'url' => route($h['item']['route']),
            'icon' => $h['item']['icon'],
            'ltr' => false,
        ], array_slice($hits, 0, SearchGroup::LIMIT));

        // A page whose name (or a word people use for it) starts with what was typed is almost always what the person wants: list it first.
        // A typed number is never a page.
        $lead = $hits[0]['rank'] <= self::BY_WORD && ! ctype_digit(str_replace(' ', '', $query->text));

        return new SearchGroup('commands', __('search.group.commands'), $items, lead: $lead);
    }

    /**
     * @param  list<string>  $typed  the words typed, folded
     * @param  array{label: string, route: string}  $item
     */
    private function rank(array $typed, array $item, string $groupLabel): ?int
    {
        $name = $this->words($item['label']);
        $words = $this->words('search.aliases.'.str_replace('.', '_', $item['route']));
        $group = $this->words($groupLabel);
        if ($this->allStart($typed, $name)) {
            return self::BY_NAME;
        }
        if ($this->allStart($typed, [...$name, ...$words])) {
            return self::BY_WORD;
        }

        return $this->allStart($typed, [...$name, ...$words, ...$group]) ? self::BY_GROUP : null;
    }

    /**
     * @param  list<string>  $typed
     * @param  list<string>  $words
     */
    private function allStart(array $typed, array $words): bool
    {
        foreach ($typed as $t) {
            if (! array_filter($words, fn (string $w) => str_starts_with($w, $t))) {
                return false;
            }
        }

        return $typed !== [];
    }

    /** The words of a translation key in both languages, folded; an Arabic word is also kept without its "ال" so "طلب" finds "الطلبات". @return list<string> */
    private function words(string $key): array
    {
        $out = [];
        foreach (['en', 'ar'] as $locale) {
            if (! Lang::has($key, $locale, false)) {
                continue;
            }
            foreach (explode(' ', SearchNormalizer::normalize((string) Lang::get($key, [], $locale))) as $word) {
                if ($word === '') {
                    continue;
                }
                $out[] = $word;
                if (str_starts_with($word, 'ال') && mb_strlen($word) > 4) {
                    $out[] = mb_substr($word, 2);
                }
            }
        }

        return $out;
    }
}
