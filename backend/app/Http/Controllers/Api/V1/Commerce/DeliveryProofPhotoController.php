<?php

namespace App\Http\Controllers\Api\V1\Commerce;

use App\Domain\Fulfillment\Enums\DeliveryStatus;
use App\Domain\Fulfillment\Models\Delivery;
use App\Domain\Fulfillment\Models\DeliveryProof;
use App\Domain\Fulfillment\Services\DeliveryFollowUpSettings;
use App\Domain\Operations\Services\ProofFiles;
use App\Http\Controllers\Controller;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * The photo of a delivery, for the customer who received it (docs/guides/DELIVERY_FOLLOW_UPS.md). Reached only through the short-lived SIGNED link the
 * order's own page carries (ten minutes, made for the order's owner), so there is no public file and no listing; the link stops working when the dashboard
 * switch "show the proof of delivery to customers" is turned off. The photo of a failed attempt, of an assembly or a courier's own proof is never served here,
 * and the answer is never cached by a shared cache. Every id that does not belong together answers the same 404.
 *
 * @tags Orders
 */
class DeliveryProofPhotoController extends Controller
{
    public function show(DeliveryFollowUpSettings $config, ProofFiles $files, string $id, string $proof): Response
    {
        abort_unless($config->proofForCustomer() && Str::isUuid($id) && Str::isUuid($proof), 404);
        $p = DeliveryProof::query()->find($proof);
        $delivery = $p !== null ? Delivery::query()->with('fulfillment:id,order_id')->find($p->delivery_id, ['id', 'order_fulfillment_id', 'status']) : null;
        abort_unless($p !== null && $delivery !== null && $delivery->fulfillment?->order_id === $id && $delivery->status === DeliveryStatus::Delivered
            && ! in_array($p->type, ['failed', 'location', 'assembly', 'provider'], true), 404);

        return $files->response($p, 'private, no-store, max-age=0');
    }
}
