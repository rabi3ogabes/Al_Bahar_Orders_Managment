<?php

namespace App\Http\Controllers\Api\V1\Commerce;

use App\Domain\Orders\Models\ReturnRequest;
use App\Domain\Orders\Models\ReturnRequestPhoto;
use App\Domain\Orders\Services\ClaimPhotoStore;
use App\Domain\Orders\Services\ReturnClaimPresenter;
use App\Domain\Orders\Services\ReturnClaims;
use App\Http\Controllers\Api\V1\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A customer's own return or damage claim: read it, add photos or a note while it waits for the team, cancel it, and see its photos.
 * Always the signed-in customer's own claim; any other id answers 404, so nothing is learned by guessing.
 *
 * @tags Orders
 */
class ReturnClaimController extends ApiController
{
    public function __construct(private readonly ReturnClaimPresenter $presenter) {}

    public function show(string $id): JsonResponse
    {
        return response()->json(['data' => $this->presenter->detail($this->own($id))]);
    }

    /** More photos and/or a note, while the claim is still waiting for the team (multipart: photos[], reason). */
    public function evidence(Request $request, ReturnClaims $claims, string $id): JsonResponse
    {
        $data = $request->validate(['photos' => 'nullable|array|max:'.ReturnRequest::MAX_PHOTOS, 'photos.*' => 'file|mimetypes:image/jpeg,image/png,image/webp|max:8192', 'reason' => 'nullable|string|max:500']);
        $claim = $claims->addEvidence($this->own($id), $this->user(), array_values($request->file('photos', [])), $data['reason'] ?? null);

        return response()->json(['data' => $this->presenter->detail($claim->fresh())]);
    }

    public function cancel(ReturnClaims $claims, string $id): JsonResponse
    {
        return response()->json(['data' => $this->presenter->detail($claims->cancel($this->own($id), $this->user())->fresh())]);
    }

    /** One photo of the customer's own claim (private file, streamed here only). */
    public function photo(ClaimPhotoStore $store, string $id, string $photoId): Response
    {
        $claim = $this->own($id);
        $photo = ReturnRequestPhoto::where('return_request_id', $claim->id)->find($photoId);
        abort_if($photo === null, 404);

        return $store->response($photo);
    }

    private function own(string $id): ReturnRequest
    {
        return ReturnRequest::where('user_id', $this->user()->id)->findOrFail($id);
    }
}
