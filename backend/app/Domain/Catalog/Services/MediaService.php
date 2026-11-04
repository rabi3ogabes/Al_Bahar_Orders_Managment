<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductImage;
use App\Support\DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Stores media on the configured (S3-compatible in production) disk — never in PostgreSQL.
 * Validates real MIME type (magic bytes) and size, strips EXIF by re-encoding, and generates WebP width variants.
 */
class MediaService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function storeProductImage(Product $product, UploadedFile $file, ?string $variantId = null, ?string $altEn = null, ?string $altAr = null): ProductImage
    {
        $cfg = config('platform.media');
        $mime = $file->getMimeType(); // finfo-based, not the client header
        if (! in_array($mime, $cfg['image_mimes'], true) || $file->getSize() > $cfg['max_image_kb'] * 1024) {
            throw DomainException::make('media_invalid');
        }
        $image = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));
        if ($image === false) {
            throw DomainException::make('media_invalid');
        }
        $disk = $cfg['disk'];
        $base = 'products/'.$product->id.'/'.Str::uuid();
        [$w, $h] = [imagesx($image), imagesy($image)];

        // Original, re-encoded (drops EXIF/GPS metadata).
        $originalPath = $base.'.webp';
        Storage::disk($disk)->put($originalPath, $this->encode($image), ['visibility' => 'public', 'ContentType' => 'image/webp', 'CacheControl' => 'public, max-age=31536000, immutable']);

        $variants = [];
        foreach ($cfg['thumbnail_widths'] as $width) {
            if ($width >= $w) {
                continue;
            }
            $resized = imagescale($image, $width, (int) round($h * $width / $w), IMG_BICUBIC);
            $path = $base.'-'.$width.'.webp';
            Storage::disk($disk)->put($path, $this->encode($resized), ['visibility' => 'public', 'ContentType' => 'image/webp', 'CacheControl' => 'public, max-age=31536000, immutable']);
            imagedestroy($resized);
            $variants[(string) $width] = $path;
        }
        imagedestroy($image);

        $img = $product->images()->create([
            'product_variant_id' => $variantId, 'disk' => $disk, 'path' => $originalPath, 'variants' => $variants,
            'alt_en' => $altEn, 'alt_ar' => $altAr, 'width' => $w, 'height' => $h,
            'sort_order' => (int) $product->images()->max('sort_order') + 1,
        ]);
        $this->audit->log('catalog.image_added', $product, [], ['image_id' => $img->id]);

        return $img;
    }

    /** @param list<string> $orderedIds */
    public function reorder(Product $product, array $orderedIds): void
    {
        foreach (array_values($orderedIds) as $i => $id) {
            $product->images()->whereKey($id)->update(['sort_order' => $i]);
        }
    }

    /** Deletes the file only if no other image row (e.g. a duplicated product) still references it. */
    public function deleteImage(ProductImage $image): void
    {
        $shared = ProductImage::where('path', $image->path)->whereKeyNot($image->id)->exists();
        $image->delete();
        if (! $shared) {
            Storage::disk($image->disk)->delete(array_merge([$image->path], array_values($image->variants ?? [])));
        }
        $this->audit->log('catalog.image_deleted', 'ProductImage', ['id' => $image->id, 'product_id' => $image->product_id]);
    }

    private function encode(\GdImage $image): string
    {
        imagepalettetotruecolor($image);
        imagealphablending($image, true);
        imagesavealpha($image, true);
        ob_start();
        imagewebp($image, null, 82);

        return (string) ob_get_clean();
    }
}
