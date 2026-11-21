<?php

namespace App\Domain\Orders\Http;

use App\Domain\Identity\Services\AccessControl;
use App\Domain\Orders\Models\ReturnRequest;
use App\Domain\Orders\Models\ReturnRequestPhoto;
use App\Domain\Orders\Services\ClaimPhotoStore;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Streams a claim photo to the dashboard only. Private: the file is on the private disk and is reached only through this route. Branch-scoped:
 * the person needs orders.view at the claim's branch; anyone else, and every id that does not belong together, gets the same 404, so nothing is
 * learned by guessing (the same rule as the proofs of delivery).
 */
class ReturnClaimPhotoController extends Controller
{
    public function show(Request $request, AccessControl $access, ClaimPhotoStore $store, string $claim, string $photo): Response
    {
        abort_unless(Str::isUuid($claim) && Str::isUuid($photo), 404);
        $c = ReturnRequest::query()->find($claim, ['id', 'branch_id']);
        abort_unless($c !== null && $access->allows($request->user(), 'orders.view', $c->branch_id), 404);
        $p = ReturnRequestPhoto::where('return_request_id', $c->id)->find($photo);
        abort_if($p === null, 404);

        return $store->response($p);
    }
}
