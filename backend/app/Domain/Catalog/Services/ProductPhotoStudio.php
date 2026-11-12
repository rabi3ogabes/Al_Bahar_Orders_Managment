<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductImage;
use App\Domain\Catalog\Models\ProductPhotoRequest;
use App\Domain\Catalog\Models\ProductPhotoResult;
use App\Domain\Identity\Models\User;
use App\Support\DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Photo studio queue. Staff ask for a clean background / studio look / new designs of a product photo; an editor (Claude with
 * the Higgsfield tools) picks requests up, uploads the finished pictures, and staff approve the ones to publish.
 * Edited pictures never reach the storefront until a person presses "Use this photo".
 */
class ProductPhotoStudio
{
    public function __construct(private readonly MediaService $media, private readonly AuditLogger $audit) {}

    public function request(Product $product, ?ProductImage $source, string $kind, int $count, ?string $notes, User $by): ProductPhotoRequest
    {
        if (! in_array($kind, ProductPhotoRequest::KINDS, true)) {
            throw DomainException::make('photo_kind_invalid');
        }
        if ($source && $source->product_id !== $product->id) {
            throw DomainException::make('photo_source_invalid');
        }
        if (! $source && $kind !== 'new_design') {
            throw DomainException::make('photo_source_required');
        }
        $open = ProductPhotoRequest::where('product_id', $product->id)->where('source_image_id', $source?->id)->where('kind', $kind)->whereIn('status', ['requested', 'processing'])->exists();
        if ($open) {
            throw DomainException::make('photo_request_open');
        }
        $r = ProductPhotoRequest::create(['product_id' => $product->id, 'source_image_id' => $source?->id, 'kind' => $kind, 'count' => max(1, min(6, $count)),
            'notes' => $notes ? Str::limit(trim($notes), 1000, '') : null, 'requested_by' => $by->id]);
        $this->audit->log('catalog.photo_requested', $product, [], ['request_id' => $r->id, 'kind' => $kind]);

        return $r;
    }

    public function claim(ProductPhotoRequest $r): ProductPhotoRequest
    {
        if ($r->status !== 'requested') {
            throw DomainException::make('photo_request_taken');
        }
        $r->update(['status' => 'processing', 'claimed_at' => now()]);

        return $r;
    }

    /** Stores one edited picture (re-encoded to WebP, metadata stripped) as a pending result. */
    public function addResult(ProductPhotoRequest $r, UploadedFile $file): ProductPhotoResult
    {
        if (! in_array($r->status, ['requested', 'processing', 'ready'], true)) {
            throw DomainException::make('photo_request_closed');
        }
        $cfg = config('platform.media');
        if (! in_array($file->getMimeType(), $cfg['image_mimes'], true) || $file->getSize() > $cfg['max_image_kb'] * 1024) {
            throw DomainException::make('media_invalid');
        }
        $img = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));
        if ($img === false) {
            throw DomainException::make('media_invalid');
        }
        [$w, $h] = [imagesx($img), imagesy($img)];
        imagepalettetotruecolor($img);
        imagealphablending($img, true);
        imagesavealpha($img, true);
        ob_start();
        imagewebp($img, null, 86);
        $bytes = (string) ob_get_clean();
        imagedestroy($img);
        $path = 'products/'.$r->product_id.'/studio/'.Str::uuid().'.webp';
        Storage::disk($cfg['disk'])->put($path, $bytes, ['visibility' => 'public', 'ContentType' => 'image/webp']);
        $res = $r->results()->create(['disk' => $cfg['disk'], 'path' => $path, 'width' => $w, 'height' => $h]);
        $r->update(['status' => 'ready', 'completed_at' => now()]);

        return $res;
    }

    public function fail(ProductPhotoRequest $r, ?string $note): ProductPhotoRequest
    {
        $r->update(['status' => 'failed', 'result_note' => $note ? Str::limit($note, 500, '') : null, 'completed_at' => now()]);

        return $r;
    }

    public function cancel(ProductPhotoRequest $r): ProductPhotoRequest
    {
        if (! in_array($r->status, ['requested', 'processing'], true)) {
            throw DomainException::make('photo_request_closed');
        }
        $r->update(['status' => 'cancelled', 'completed_at' => now()]);

        return $r;
    }

    /** Publishes an approved picture as a normal product image (same pipeline as an upload: sizes, alt text). */
    public function use(ProductPhotoResult $res, User $by, bool $asCover = false): ProductImage
    {
        if ($res->decision !== 'pending') {
            throw DomainException::make('photo_result_decided');
        }
        $r = $res->request()->with('product', 'source')->firstOrFail();
        $tmp = tempnam(sys_get_temp_dir(), 'studio');
        file_put_contents($tmp, Storage::disk($res->disk)->get($res->path));
        try {
            $file = new UploadedFile($tmp, 'studio.webp', 'image/webp', null, true);
            $image = $this->media->storeProductImage($r->product, $file, $r->source?->product_variant_id, $r->product->trans('name', 'en'), $r->product->trans('name', 'ar'));
        } finally {
            @unlink($tmp);
        }
        if ($asCover) {
            $ids = $r->product->images()->where('id', '!=', $image->id)->orderBy('sort_order')->pluck('id')->all();
            $this->media->reorder($r->product, array_merge([$image->id], $ids));
        }
        Storage::disk($res->disk)->delete($res->path);
        $res->update(['decision' => 'used', 'product_image_id' => $image->id]);
        $this->audit->log('catalog.photo_used', $r->product, [], ['result_id' => $res->id, 'image_id' => $image->id], ['by' => $by->id]);

        return $image;
    }

    public function discard(ProductPhotoResult $res): void
    {
        if ($res->decision !== 'pending') {
            throw DomainException::make('photo_result_decided');
        }
        Storage::disk($res->disk)->delete($res->path);
        $res->update(['decision' => 'discarded']);
    }
}
