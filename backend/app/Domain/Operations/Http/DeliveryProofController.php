<?php

namespace App\Domain\Operations\Http;

use App\Domain\Fulfillment\Models\Delivery;
use App\Domain\Fulfillment\Models\DeliveryProof;
use App\Domain\Identity\Services\AccessControl;
use App\Domain\Operations\Services\ProofFiles;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Streams the photo a driver took as proof of delivery, to the dashboard only.
 *
 *  - Private: the file lives on the private disk and is reached only through this route (no public URL, no directory listing: one proof id, one file).
 *  - Branch-scoped: the person needs deliveries.view for the branch of THAT delivery; anyone else, and every id that does not belong together
 *    (a proof of another delivery, an unknown id, a courier proof that is only a link), gets the same 404, so nothing is learned by guessing.
 *  - Safe path: only a file the system itself stored (delivery-proofs/{delivery id}/{file name}) is read, never a path with "..", a drive or a
 *    directory, and only an image type is sent, with the type taken from the stored file itself and `nosniff`.
 */
class DeliveryProofController extends Controller
{
    public function show(Request $request, AccessControl $access, ProofFiles $files, string $delivery, string $proof): Response
    {
        abort_unless(Str::isUuid($delivery) && Str::isUuid($proof), 404);
        $d = Delivery::query()->find($delivery, ['id', 'branch_id']);
        abort_unless($d !== null && $access->allows($request->user(), 'deliveries.view', $d->branch_id), 404);

        $p = DeliveryProof::query()->where('delivery_id', $d->id)->find($proof);
        abort_unless($p !== null, 404);

        return $files->response($p, 'private, max-age=300');
    }
}
