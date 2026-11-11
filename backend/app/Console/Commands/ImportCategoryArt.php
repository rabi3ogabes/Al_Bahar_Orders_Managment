<?php

namespace App\Console\Commands;

use App\Domain\Catalog\Models\Category;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * Gives every category its Qatari-style cover (database/data/category_art/{slug}.webp, one shared design).
 * Safe to re-run: it overwrites the same files and sets the same paths.
 */
class ImportCategoryArt extends Command
{
    protected $signature = 'catalog:category-art {--dir=database/data/category_art : Folder with one {slug}.webp per category}';

    protected $description = 'Attach the Qatari-style cover image to each category';

    public function handle(): int
    {
        $given = (string) $this->option('dir');
        $dir = str_starts_with($given, '/') ? $given : base_path($given);
        $disk = Storage::disk(config('platform.media.disk'));
        $done = 0;
        foreach (Category::all() as $category) {
            $file = "{$dir}/{$category->slug}.webp";
            if (! is_file($file)) {
                continue;
            }
            $path = "categories/{$category->slug}.webp";
            $disk->put($path, (string) file_get_contents($file), 'public');
            $category->forceFill(['image_path' => $path])->save();
            $done++;
        }
        foreach (['en', 'ar'] as $locale) {
            Cache::forget("catalog:categories:{$locale}");
        }
        $this->info("Cover set on {$done} categor".($done === 1 ? 'y' : 'ies').'.');

        return self::SUCCESS;
    }
}
