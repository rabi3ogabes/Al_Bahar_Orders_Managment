<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Replaces the source store's name ("Al Marwa", "Almarwah", "المروة") with Al Bahar / البحار in catalogue text,
 * including slugs and the normalised search text. Safe to re-run: once nothing matches, it changes nothing.
 */
class RebrandCatalog extends Command
{
    protected $signature = 'catalog:rebrand {--dry-run : Only count the rows that would change}';

    protected $description = 'Rename the source store brand to Al Bahar in categories, products and brands';

    /** @var array<string, list<string>> table => text columns */
    private const COLUMNS = [
        'product_translations' => ['name', 'slug', 'short_description', 'description', 'material', 'seo_title', 'seo_description', 'search_keywords', 'search_text'],
        'category_translations' => ['name', 'description', 'seo_title', 'seo_description'],
        'categories' => ['slug'],
        'brands' => ['slug', 'name_en', 'name_ar', 'description_en', 'description_ar'],
    ];

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $total = 0;
        foreach (self::COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                $pattern = '(al[ -]?marwah?|almarwah?|المروة|المروه)';
                $matches = DB::table($table)->whereRaw("{$column} ~* ?", [$pattern])->count();
                if ($matches === 0) {
                    continue;
                }
                $total += $matches;
                $this->line("{$table}.{$column}: {$matches}");
                if ($dry) {
                    continue;
                }
                $isSlug = $column === 'slug';
                $en = $isSlug ? 'al-bahar' : 'Al Bahar';
                DB::statement(
                    "UPDATE {$table} SET {$column} = regexp_replace(regexp_replace(regexp_replace({$column}, 'al[ -]?marwah?', ?, 'gi'), 'almarwah?', ?, 'gi'), '(المروة|المروه)', ?, 'g') WHERE {$column} ~* ?",
                    [$en, $isSlug ? 'albahar' : 'Albahar', 'البحار', $pattern],
                );
            }
        }
        $this->info(($dry ? 'Would change ' : 'Changed ').$total.' field(s).');

        return self::SUCCESS;
    }
}
