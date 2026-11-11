<?php

namespace App\Livewire\Admin\Concerns;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Small helpers for category/brand artwork stored on the media disk (never in the database). */
trait StoresCatalogMedia
{
    protected static function mediaDisk(): string
    {
        return config('platform.media.disk');
    }

    /** Validation rules for a raster image upload (SVG deliberately excluded — it can carry script). */
    protected static function imageRule(int $maxKb = 4096): array
    {
        return ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.$maxKb];
    }

    protected function storeMedia(UploadedFile $file, string $folder): string
    {
        return $file->storePubliclyAs($folder, Str::uuid().'.'.strtolower($file->guessExtension() ?: 'jpg'), self::mediaDisk());
    }

    protected function deleteMedia(?string $path): void
    {
        if ($path && ! str_starts_with($path, 'http')) {
            Storage::disk(self::mediaDisk())->delete($path);
        }
    }

    public static function mediaUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        return str_starts_with($path, 'http') ? $path : Storage::disk(self::mediaDisk())->url($path);
    }
}
