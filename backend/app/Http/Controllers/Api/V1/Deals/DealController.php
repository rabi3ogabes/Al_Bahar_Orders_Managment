<?php

namespace App\Http\Controllers\Api\V1\Deals;

use App\Domain\Branches\Models\Branch;
use App\Domain\Deals\Models\Deal;
use App\Domain\Deals\Services\DealPresenter;
use App\Http\Controllers\Api\V1\ApiController;
use App\Support\DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Countdown deals for the store and the customer app (docs/api/DEALS.md). Public; a signed-in customer also gets `customer_remaining` on every deal block.
 *
 * @tags Deals
 */
class DealController extends ApiController
{
    public function __construct(private readonly DealPresenter $presenter) {}

    /** Live deals (the one ending soonest first) and upcoming ones, each with a few of its products and the server clock to count down from. */
    public function index(Request $request): JsonResponse
    {
        $f = $request->validate(['home' => 'nullable|boolean', 'limit_products' => 'nullable|integer|min:1|max:12', 'branch_id' => 'nullable|uuid']);

        return response()->json(['data' => $this->presenter->index($request->user('sanctum'), $this->branch($f['branch_id'] ?? null), (bool) ($f['home'] ?? false), (int) ($f['limit_products'] ?? 8))]);
    }

    /** One deal with a page (24) of its products as catalog cards. 404 `deal_not_found` when it is ended or paused (a sold-out deal still answers). */
    public function show(Request $request, string $id): JsonResponse
    {
        $f = $request->validate(['page' => 'nullable|integer|min:1', 'branch_id' => 'nullable|uuid']);
        $deal = Deal::query()->find($id);
        if ($deal === null || in_array($deal->phase(), [Deal::ENDED, Deal::PAUSED], true)) {
            throw DomainException::make('deal_not_found', 404);
        }

        return response()->json(['data' => $this->presenter->show($deal, $request->user('sanctum'), $this->branch($f['branch_id'] ?? null), (int) ($f['page'] ?? 1))]);
    }

    private function branch(?string $id): ?Branch
    {
        return $id ? Branch::active()->find($id) : null;
    }
}
