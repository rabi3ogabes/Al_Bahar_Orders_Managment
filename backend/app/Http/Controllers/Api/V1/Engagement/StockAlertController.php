<?php

namespace App\Http\Controllers\Api\V1\Engagement;

use App\Domain\Branches\Models\Branch;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Models\StockAlert;
use App\Domain\Catalog\Services\StockAlertPricing;
use App\Domain\Catalog\Services\StockAlertService;
use App\Domain\Catalog\Services\StockAlertSettings;
use App\Http\Controllers\Api\V1\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Tell me when it is back" and "tell me if the price drops": ask for an alert, see them all, cancel one (docs/api/API_CONTRACT.md section 6b).
 * The customer only ever sees and changes their own alerts.
 *
 * @tags Engagement
 */
class StockAlertController extends ApiController
{
    public function __construct(private readonly StockAlertService $alerts, private readonly StockAlertSettings $settings) {}

    /** Public: which alerts the store offers and how big a price drop has to be (so the apps only show a choice that can fire). */
    public function config(): JsonResponse
    {
        return response()->json(['data' => $this->settings->forApi()])->header('Cache-Control', 'public, max-age=60');
    }

    /** The customer's active alerts, newest first, with what each costs now. */
    public function index(): JsonResponse
    {
        $rows = $this->alerts->listFor($this->user());

        return response()->json(['data' => $rows, 'meta' => ['count' => count($rows), 'limit' => StockAlertSettings::MAX_PER_CUSTOMER] + $this->settings->forApi()])
            ->header('Cache-Control', 'private, no-store');
    }

    /** Asks for an alert. 201 when it is new, 200 when the customer already had it (nothing changes). */
    public function store(Request $request, StockAlertPricing $pricing): JsonResponse
    {
        $data = $request->validate([
            'variant_id' => 'required|uuid|exists:product_variants,id', 'type' => 'required|in:'.implode(',', StockAlert::TYPES),
            'branch_id' => 'nullable|uuid|exists:branches,id',
        ]);
        $branch = isset($data['branch_id']) ? Branch::active()->find($data['branch_id']) : null;
        ['alert' => $alert, 'created' => $created] = $this->alerts->subscribe($this->user(), ProductVariant::with('product')->findOrFail($data['variant_id']), $data['type'], $branch);
        $alert->load(['product.translations', 'product.images', 'variant']);

        return response()->json(['data' => ['subscribed' => true, 'alert' => $this->alerts->present($alert, $pricing->forAlerts(collect([$alert]))[$alert->id])]], $created ? 201 : 200);
    }

    /** Cancels the alert for a variant and type (the product page toggle). Cancelling what is not there is fine. */
    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate(['variant_id' => 'required|uuid', 'type' => 'required|in:'.implode(',', StockAlert::TYPES)]);
        $this->alerts->cancelFor($this->user(), $data['variant_id'], $data['type']);

        return response()->json(['data' => ['subscribed' => false]]);
    }

    /** Cancels one alert by its id (the "My alerts" list). Another customer's alert is untouched and answers the same as one that is gone. */
    public function remove(string $id): JsonResponse
    {
        $this->alerts->cancel($this->user(), $id);

        return response()->json(['data' => ['subscribed' => false]]);
    }
}
