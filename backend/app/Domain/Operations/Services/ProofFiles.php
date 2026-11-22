<?php

namespace App\Domain\Operations\Services;

use App\Domain\Fulfillment\Models\DeliveryProof;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Streams the stored photo of a delivery proof, for whoever the caller has already decided may see it (the dashboard's staff route, or the customer's
 * signed link). The file is private: only a file the system itself stored (delivery-proofs/{delivery id}/{file name}) is read, never a path with "..",
 * a drive or a directory, and only an image type is sent, with the type taken from the stored file itself and `nosniff`. Everything else is a plain 404,
 * so nothing is learned by guessing.
 */
final class ProofFiles
{
    private const TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    public function response(DeliveryProof $proof, string $cacheControl): Response
    {
        $path = (string) $proof->path;
        $disk = (string) $proof->disk;
        abort_unless($disk !== '' && array_key_exists($disk, (array) config('filesystems.disks')), 404);
        abort_unless(preg_match('#^delivery-proofs/'.preg_quote((string) $proof->delivery_id, '#').'/[A-Za-z0-9._-]+$#', $path) === 1 && ! str_contains($path, '..'), 404);

        $storage = Storage::disk($disk);
        abort_unless($storage->exists($path), 404);
        $mime = (string) $storage->mimeType($path);
        abort_unless(isset(self::TYPES[$mime]), 404);

        return $storage->response($path, 'proof-'.$proof->id.'.'.self::TYPES[$mime], [
            'Content-Type' => $mime,
            'Cache-Control' => $cacheControl,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
