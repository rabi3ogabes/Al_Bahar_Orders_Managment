<?php

namespace App\Console\Commands;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Services\MediaService;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;

/**
 * Downloads product pictures listed in a manifest ({"SKU": ["https://…jpg", …]}) and stores them through
 * MediaService (type/size checked, re-encoded to WebP, thumbnails). Safe to re-run: products that already have
 * pictures are skipped, and a failed download only skips that picture.
 */
class ImportCatalogImages extends Command
{
    protected $signature = 'catalog:import-images
        {manifest=database/data/market_images.json : JSON file mapping product SKU to a list of image URLs}
        {--limit=0 : Stop after this many products (0 = all)}
        {--delay=300 : Pause between downloads, in milliseconds}
        {--max-images=3 : Pictures to keep per product}';

    protected $description = 'Import product pictures from the URLs in a manifest';

    public function handle(MediaService $media): int
    {
        $path = base_path($this->argument('manifest'));
        if (! is_file($path)) {
            $this->error("Manifest not found: {$path}");

            return self::FAILURE;
        }
        $manifest = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $limit = (int) $this->option('limit');
        $delay = max(0, (int) $this->option('delay')) * 1000;
        $max = max(1, (int) $this->option('max-images'));
        [$done, $skipped, $failed, $pictures] = [0, 0, 0, 0];

        foreach ($manifest as $sku => $urls) {
            $product = Product::where('sku', $sku)->first();
            if (! $product || $urls === [] || $product->images()->exists()) {
                $skipped++;

                continue;
            }
            foreach (array_slice($urls, 0, $max) as $i => $url) {
                if (! $this->download($media, $product, (string) $url, $i)) {
                    $failed++;
                } else {
                    $pictures++;
                }
                usleep($delay);
            }
            $done++;
            if ($done % 25 === 0) {
                $this->line("… {$done} products, {$pictures} pictures");
            }
            if ($limit > 0 && $done >= $limit) {
                break;
            }
        }
        $this->info("Imported {$pictures} pictures for {$done} products ({$skipped} skipped, {$failed} pictures failed).");

        return self::SUCCESS;
    }

    private function download(MediaService $media, Product $product, string $url, int $index): bool
    {
        // Only plain https image URLs; the manifest is trusted, but never fetch anything else.
        if (! preg_match('#^https://[a-z0-9.-]+/[^\s]+$#i', $url)) {
            return false;
        }
        try {
            $res = Http::timeout(20)->retry(2, 500, throw: false)->withUserAgent('AlBaharCatalogImport/1.0')->get($url);
        } catch (\Throwable) {
            return false;
        }
        if (! $res->ok() || $res->body() === '' || strlen($res->body()) > config('platform.media.max_image_kb') * 1024) {
            return false;
        }
        $tmp = tempnam(sys_get_temp_dir(), 'img');
        file_put_contents($tmp, $res->body());
        try {
            $file = new UploadedFile($tmp, basename(parse_url($url, PHP_URL_PATH) ?: 'image'), null, null, true);
            $media->storeProductImage($product, $file, null, $product->translations()->where('locale', 'en')->value('name'), $product->translations()->where('locale', 'ar')->value('name'));

            return true;
        } catch (\Throwable) {
            return false;
        } finally {
            @unlink($tmp);
        }
    }
}
