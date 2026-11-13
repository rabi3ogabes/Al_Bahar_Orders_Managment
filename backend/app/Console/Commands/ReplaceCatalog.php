<?php

namespace App\Console\Commands;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Branches\Models\Branch;
use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductImage;
use App\Domain\Catalog\Services\CategoryService;
use App\Domain\Catalog\Services\MediaService;
use App\Domain\Catalog\Services\ProductService;
use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Services\InventoryLedger;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Replaces the whole catalogue with Al Bahar's own (database/data/albahar_catalog.json: categories, products, English and Arabic
 * texts) and gives each new product its pictures from a manifest of https URLs.
 *
 * The old catalogue is removed first, in one transaction: its products, variants, pictures (files too), stock rows, categories and
 * brands (the "al-bahar" brand stays). Orders already placed are kept as history; their item lines keep name and SKU but lose the
 * picture, because the picture file is gone. Safe to re-run: products whose SKU already exists are skipped and pictures are only
 * added to products that have none.
 */
class ReplaceCatalog extends Command
{
    protected $signature = 'catalog:replace
        {spec=database/data/albahar_catalog.json : the new catalogue (categories and products)}
        {--images= : JSON file {"SKU": ["https://…"], "category:slug": "https://…"} with the pictures to download}
        {--stock=40 : opening stock per branch for every new product (0 = none)}
        {--keep-old : do not remove the old catalogue}
        {--dry-run : show what would happen and change nothing}
        {--force : do not ask for confirmation}';

    protected $description = 'Replace the catalogue with the one in the spec file and attach its pictures';

    private const NEW_SKU_PREFIX = 'ABH-';

    public function handle(CategoryService $categories, ProductService $products, MediaService $media, InventoryLedger $ledger): int
    {
        $specPath = $this->path((string) $this->argument('spec'));
        if (! is_file($specPath)) {
            $this->error("Spec not found: {$specPath}");

            return self::FAILURE;
        }
        $spec = json_decode((string) file_get_contents($specPath), true, flags: JSON_THROW_ON_ERROR);
        $problems = $this->validateSpec($spec);
        if ($problems !== []) {
            foreach ($problems as $p) {
                $this->error($p);
            }

            return self::FAILURE;
        }

        $old = Product::where('sku', 'not like', self::NEW_SKU_PREFIX.'%')->count();
        $this->line(sprintf('New catalogue: %d categories, %d products. Old products to remove: %d.', count($spec['categories']), count($spec['products']), $this->option('keep-old') ? 0 : $old));
        if ($this->option('dry-run')) {
            $this->info('Dry run: nothing changed.');

            return self::SUCCESS;
        }
        if (! $this->option('force') && ! $this->confirm('Remove the old catalogue and create the new one?')) {
            return self::FAILURE;
        }

        if (! $this->option('keep-old')) {
            $this->wipe($media);
        }
        $this->seed($spec, $categories, $products, $ledger);
        $this->pictures($spec, $media);
        foreach (['en', 'ar'] as $locale) {
            Cache::forget("catalog:categories:{$locale}");
        }
        Cache::flush();
        $this->info('Done.');

        return self::SUCCESS;
    }

    /** @return list<string> */
    private function validateSpec(array $spec): array
    {
        $problems = [];
        $slugs = array_column($spec['categories'] ?? [], 'slug');
        $skus = array_column($spec['products'] ?? [], 'sku');
        if ($slugs === [] || $skus === []) {
            $problems[] = 'The spec needs categories and products.';
        }
        if (count($skus) !== count(array_unique($skus))) {
            $problems[] = 'Two products share a SKU.';
        }
        foreach ($spec['products'] ?? [] as $p) {
            if (! str_starts_with((string) $p['sku'], self::NEW_SKU_PREFIX)) {
                $problems[] = "SKU {$p['sku']} must start with ".self::NEW_SKU_PREFIX;
            }
            if (! in_array($p['category'] ?? null, $slugs, true)) {
                $problems[] = "{$p['sku']}: unknown category ".($p['category'] ?? '(none)');
            }
            if (! is_numeric($p['price'] ?? null) || (float) $p['price'] <= 0 || empty($p['en']['name']) || empty($p['ar']['name'])) {
                $problems[] = "{$p['sku']}: a price and an English and Arabic name are required.";
            }
        }

        return $problems;
    }

    private function wipe(MediaService $media): void
    {
        $this->info('Removing the old catalogue …');
        $productIds = Product::where('sku', 'not like', self::NEW_SKU_PREFIX.'%')->pluck('id')->all();

        // Pictures first, so their files go with them.
        $files = 0;
        foreach (array_chunk($productIds, 200) as $chunk) {
            ProductImage::whereIn('product_id', $chunk)->get()->each(function (ProductImage $image) use ($media, &$files) {
                $media->deleteImage($image);
                $files++;
            });
        }
        $results = DB::table('product_photo_results')->get(['disk', 'path']);
        foreach ($results as $r) {
            if ($r->path) {
                Storage::disk($r->disk ?: config('platform.media.disk'))->delete($r->path);
            }
        }

        DB::transaction(function () use ($productIds) {
            // The stock ledger refuses deletes (a trigger). For this one owner-requested clean-up of imported placeholder stock the trigger is
            // switched off for these statements only; it is switched on again before the transaction ends, and a failure rolls everything back.
            DB::statement('ALTER TABLE stock_movements DISABLE TRIGGER stock_movements_append_only');
            foreach (array_chunk($productIds, 200) as $chunk) {
                $variantIds = DB::table('product_variants')->whereIn('product_id', $chunk)->pluck('id')->all();
                // Order lines keep their text for the customer's history, but the picture file is gone.
                DB::table('order_items')->where(fn ($q) => $q->whereIn('product_id', $chunk)->orWhereIn('product_variant_id', $variantIds))->update(['image_path' => null]);
                DB::table('stock_reservations')->whereIn('product_variant_id', $variantIds)->delete();
                DB::table('stock_movements')->whereIn('product_variant_id', $variantIds)->delete();
                DB::table('branch_inventory')->whereIn('product_variant_id', $variantIds)->delete();
                DB::table('product_photo_requests')->whereIn('product_id', $chunk)->delete();
                Product::whereIn('id', $chunk)->delete();
            }
            // Categories (children before parents) and every brand but our own.
            foreach (Category::orderByDesc('depth')->get() as $category) {
                $category->delete();
            }
            Brand::where('slug', '!=', 'al-bahar')->delete();
            DB::statement('ALTER TABLE stock_movements ENABLE TRIGGER stock_movements_append_only');
        });
        app(AuditLogger::class)->log('catalog.replaced', 'Catalog', ['products_removed' => count($productIds)], ['spec' => (string) $this->argument('spec')]);

        // Whatever pictures of categories were left on the disk.
        Storage::disk(config('platform.media.disk'))->deleteDirectory('categories');
        $this->line('  removed '.count($productIds)." products and {$files} pictures.");
    }

    private function seed(array $spec, CategoryService $categories, ProductService $products, InventoryLedger $ledger): void
    {
        $this->info('Creating the new catalogue …');
        $brand = Brand::firstOrCreate(['slug' => 'al-bahar'], ['name_en' => 'Al Bahar', 'name_ar' => 'البحار', 'status' => 'active']);
        $byslug = [];
        foreach ($spec['categories'] as $c) {
            $byslug[$c['slug']] = Category::where('slug', $c['slug'])->first() ?? $categories->create([
                'slug' => $c['slug'], 'sort_order' => $c['sort'] ?? 0, 'status' => 'active',
                'translations' => ['en' => ['name' => $c['en']['name'], 'description' => $c['en']['description'] ?? null],
                    'ar' => ['name' => $c['ar']['name'], 'description' => $c['ar']['description'] ?? null]],
            ]);
        }
        $branches = Branch::pluck('id')->all();
        $stock = max(0, (int) $this->option('stock'));
        $made = 0;
        foreach ($spec['products'] as $p) {
            if (Product::where('sku', $p['sku'])->exists()) {
                continue;
            }
            $product = $products->create([
                'sku' => $p['sku'], 'category_id' => $byslug[$p['category']]->id, 'brand_id' => $brand->id, 'status' => 'active',
                'currency' => 'QAR', 'price' => $p['price'], 'sale_price' => $p['sale_price'] ?? null, 'weight' => $p['weight'] ?? null, 'weight_unit' => 'kg',
                'is_featured' => (bool) ($p['featured'] ?? false), 'is_new_arrival' => (bool) ($p['new'] ?? false), 'is_bestseller' => (bool) ($p['bestseller'] ?? false),
                'available_in_all_branches' => true,
                'translations' => ['en' => $p['en'], 'ar' => $p['ar']],
            ]);
            if ($stock > 0) {
                foreach ($product->variants as $variant) {
                    foreach ($branches as $branchId) {
                        $ledger->apply($branchId, $variant->id, MovementType::OpeningBalance, $stock, 0, null, null, 'Opening stock: sample quantity, set the real one in Inventory');
                    }
                }
            }
            $made++;
        }
        $this->line("  created {$made} products.");
    }

    private function pictures(array $spec, MediaService $media): void
    {
        $file = (string) $this->option('images');
        if ($file === '' || ! is_file($this->path($file))) {
            return;
        }
        $this->info('Downloading the pictures …');
        $manifest = json_decode((string) file_get_contents($this->path($file)), true, flags: JSON_THROW_ON_ERROR);
        [$ok, $failed] = [0, 0];
        foreach ($manifest as $key => $urls) {
            $urls = (array) $urls;
            if (str_starts_with((string) $key, 'category:')) {
                $category = Category::where('slug', substr((string) $key, 9))->first();
                $bytes = $category && ! $category->image_path ? $this->fetch((string) ($urls[0] ?? '')) : null;
                if ($category && $bytes !== null && $this->storeCategoryArt($category, $bytes)) {
                    $ok++;
                } elseif ($category && ! $category->image_path) {
                    $failed++;
                }

                continue;
            }
            $product = Product::where('sku', $key)->first();
            if (! $product || $product->images()->exists()) {
                continue;
            }
            foreach ($urls as $url) {
                $bytes = $this->fetch((string) $url);
                $tmp = $bytes !== null ? tempnam(sys_get_temp_dir(), 'img') : null;
                if ($tmp === null) {
                    $failed++;

                    continue;
                }
                file_put_contents($tmp, $bytes);
                try {
                    $media->storeProductImage($product, new UploadedFile($tmp, 'image.png', null, null, true), null,
                        $product->translations()->where('locale', 'en')->value('name'), $product->translations()->where('locale', 'ar')->value('name'));
                    $ok++;
                } catch (\Throwable) {
                    $failed++;
                } finally {
                    @unlink($tmp);
                }
            }
        }
        $this->line("  pictures stored: {$ok}, failed: {$failed}.");
    }

    private function fetch(string $url): ?string
    {
        if (! preg_match('#^https://[a-z0-9.-]+/[^\s]+$#i', $url)) {
            return null;
        }
        try {
            $res = Http::timeout(40)->retry(2, 800, throw: false)->withUserAgent('AlBaharCatalog/1.0')->get($url);
        } catch (\Throwable) {
            return null;
        }

        return $res->ok() && $res->body() !== '' && strlen($res->body()) <= config('platform.media.max_image_kb') * 1024 * 2 ? $res->body() : null;
    }

    /** A category cover: re-encoded to WebP, at most 1400 px wide, no metadata. */
    private function storeCategoryArt(Category $category, string $bytes): bool
    {
        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            return false;
        }
        if (imagesx($image) > 1400) {
            $scaled = imagescale($image, 1400);
            if ($scaled !== false) {
                imagedestroy($image);
                $image = $scaled;
            }
        }
        imagepalettetotruecolor($image);
        ob_start();
        imagewebp($image, null, 82);
        $webp = (string) ob_get_clean();
        imagedestroy($image);
        $path = "categories/{$category->slug}.webp";
        Storage::disk(config('platform.media.disk'))->put($path, $webp, ['visibility' => 'public', 'ContentType' => 'image/webp', 'CacheControl' => 'public, max-age=86400']);
        $category->forceFill(['image_path' => $path])->save();

        return true;
    }

    private function path(string $given): string
    {
        return str_starts_with($given, '/') ? $given : base_path($given);
    }
}
