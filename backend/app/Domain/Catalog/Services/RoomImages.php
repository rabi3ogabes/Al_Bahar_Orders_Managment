<?php

namespace App\Domain\Catalog\Services;

use App\Support\DomainException;
use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The picture of a room: stored as two WebP files (1000 px and 480 px wide, never enlarged), re-encoded so no camera or location data travels with it, so
 * the home tiles are small and fast: the phone takes the 480 px one, a wide screen the 1000 px one (the API serves both, see RoomService).
 * Files live on the media disk, never in the database.
 */
final class RoomImages
{
    public const FULL = 1000;

    public const THUMB = 480;

    /** The path of the 480 px copy of a stored room picture. */
    public static function thumbPath(string $path): string
    {
        return preg_replace('/\.webp$/', '-'.self::THUMB.'.webp', $path) ?? $path;
    }

    /** @return string the path of the 1000 px picture (the small one sits beside it, see [thumbPath]) */
    public function store(UploadedFile $file): string
    {
        $cfg = config('platform.media');
        if (! in_array($file->getMimeType(), $cfg['image_mimes'], true) || $file->getSize() > $cfg['max_image_kb'] * 1024) {
            throw DomainException::make('media_invalid');
        }
        $size = @getimagesize($file->getRealPath());
        if ($size === false || $size[0] > 8000 || $size[1] > 8000 || $size[0] * $size[1] > 40_000_000) { // a tiny file can declare a huge canvas and exhaust memory when decoded
            throw DomainException::make('media_invalid');
        }
        $image = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));
        if ($image === false) {
            throw DomainException::make('media_invalid');
        }
        $base = 'rooms/'.Str::uuid();
        foreach ([self::FULL => $base.'.webp', self::THUMB => self::thumbPath($base.'.webp')] as $width => $path) {
            $copy = $this->scaled($image, $width);
            Storage::disk($cfg['disk'])->put($path, $this->encode($copy), ['visibility' => 'public', 'ContentType' => 'image/webp', 'CacheControl' => 'public, max-age=31536000, immutable']);
            if ($copy !== $image) {
                imagedestroy($copy);
            }
        }
        imagedestroy($image);

        return $base.'.webp';
    }

    public function delete(?string $path): void
    {
        if ($path && ! str_starts_with($path, 'http')) {
            Storage::disk(config('platform.media.disk'))->delete([$path, self::thumbPath($path)]);
        }
    }

    private function scaled(GdImage $image, int $width): GdImage
    {
        [$w, $h] = [imagesx($image), imagesy($image)];

        return $w <= $width ? $image : (imagescale($image, $width, (int) round($h * $width / $w), IMG_BICUBIC) ?: $image);
    }

    private function encode(GdImage $image): string
    {
        imagepalettetotruecolor($image);
        imagealphablending($image, true);
        imagesavealpha($image, true);
        ob_start();
        imagewebp($image, null, 82);

        return (string) ob_get_clean();
    }
}
