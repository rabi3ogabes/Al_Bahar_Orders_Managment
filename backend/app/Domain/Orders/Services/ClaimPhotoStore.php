<?php

namespace App\Domain\Orders\Services;

use App\Domain\Identity\Models\User;
use App\Domain\Orders\Models\ReturnRequest;
use App\Domain\Orders\Models\ReturnRequestPhoto;
use App\Support\DomainException;
use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * The evidence photos of a return or damage claim.
 *
 *  - What arrives is checked by its real content (not the name or the header the phone sent): jpeg, png or webp, at most 8 MB and a sane size.
 *  - It is DECODED and written again as a plain JPEG, so every trace of the original (location, camera, owner, embedded scripts) is gone, the phone's
 *    rotation is applied, and a very large picture is scaled down.
 *  - It is stored on the private disk under return-claims/{claim}/{random}.jpg and is reached only through an authorised route (never a public URL).
 *  - The same picture sent twice is kept once (the unique hash of the stored file), which makes a retried upload harmless.
 */
final class ClaimPhotoStore
{
    public const MAX_BYTES = 8 * 1024 * 1024;

    /** The longest side of a stored photo, in pixels. */
    public const MAX_EDGE = 1600;

    private const MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    /** The private disk: the same rule as the proofs of delivery (the public media disk is never used for evidence). */
    public function disk(): string
    {
        $disk = (string) config('platform.media.disk');

        return $disk === 'public' ? 'local' : $disk;
    }

    /**
     * @throws DomainException media_invalid when the file is not an acceptable picture
     */
    public function store(ReturnRequest $claim, UploadedFile $file, ?User $by): ReturnRequestPhoto
    {
        if (! $file->isValid() || ! in_array($file->getMimeType(), self::MIMES, true) || $file->getSize() > self::MAX_BYTES) {
            throw DomainException::make('media_invalid');
        }
        $bytes = $this->reencode((string) $file->getRealPath());
        $hash = hash('sha256', $bytes['data']);
        $existing = ReturnRequestPhoto::where('return_request_id', $claim->id)->where('sha256', $hash)->first();
        if ($existing !== null) {
            return $existing;
        }
        $path = 'return-claims/'.$claim->id.'/'.Str::uuid().'.jpg';
        Storage::disk($this->disk())->put($path, $bytes['data'], ['visibility' => 'private', 'ContentType' => 'image/jpeg']);

        return ReturnRequestPhoto::create([
            'return_request_id' => $claim->id, 'user_id' => $by?->id, 'disk' => $this->disk(), 'path' => $path, 'mime' => 'image/jpeg',
            'bytes' => strlen($bytes['data']), 'width' => $bytes['width'], 'height' => $bytes['height'], 'sha256' => $hash,
        ]);
    }

    /** Streams one stored photo. The caller has already decided the viewer may see it; a path that is not one the system wrote is refused. */
    public function response(ReturnRequestPhoto $photo): Response
    {
        $disk = $photo->disk;
        $path = $photo->path;
        abort_unless(array_key_exists($disk, (array) config('filesystems.disks')), 404);
        abort_unless(preg_match('#^return-claims/'.preg_quote($photo->return_request_id, '#').'/[A-Za-z0-9._-]+$#', $path) === 1 && ! str_contains($path, '..'), 404);
        $storage = Storage::disk($disk);
        abort_unless($storage->exists($path), 404);

        return $storage->response($path, 'claim-'.$photo->id.'.jpg', [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** @return array{data: string, width: int, height: int} */
    private function reencode(string $file): array
    {
        $size = @getimagesize($file);
        // A tiny file can declare a gigantic canvas and exhaust memory when decoded.
        if ($size === false || $size[0] < 1 || $size[1] < 1 || $size[0] > 10000 || $size[1] > 10000 || $size[0] * $size[1] > 50_000_000) {
            throw DomainException::make('media_invalid');
        }
        $image = @imagecreatefromstring((string) file_get_contents($file));
        if (! $image instanceof GdImage) {
            throw DomainException::make('media_invalid');
        }
        $image = $this->upright($image, $file, (string) $size['mime']);
        [$w, $h] = [imagesx($image), imagesy($image)];
        if (max($w, $h) > self::MAX_EDGE) {
            $scale = self::MAX_EDGE / max($w, $h);
            $scaled = imagescale($image, max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale)), IMG_BICUBIC);
            if ($scaled instanceof GdImage) {
                $image = $scaled;
                [$w, $h] = [imagesx($image), imagesy($image)];
            }
        }
        // A picture with transparency becomes JPEG on a white background.
        $flat = imagecreatetruecolor($w, $h);
        imagefill($flat, 0, 0, (int) imagecolorallocate($flat, 255, 255, 255));
        imagecopy($flat, $image, 0, 0, 0, 0, $w, $h);
        ob_start();
        imagejpeg($flat, null, 84);
        $data = (string) ob_get_clean();

        return ['data' => $data, 'width' => $w, 'height' => $h];
    }

    /** Applies the rotation a phone writes in the file (EXIF), because the metadata is dropped and the picture must still look the right way up. */
    private function upright(GdImage $image, string $file, string $mime): GdImage
    {
        if ($mime !== 'image/jpeg' || ! function_exists('exif_read_data')) {
            return $image;
        }
        $exif = @exif_read_data($file);
        $degrees = match ((int) ($exif['Orientation'] ?? 1)) {
            3 => 180, 6 => -90, 8 => 90, default => 0,
        };
        if ($degrees === 0) {
            return $image;
        }
        $rotated = imagerotate($image, $degrees, 0);

        return $rotated instanceof GdImage ? $rotated : $image;
    }
}
