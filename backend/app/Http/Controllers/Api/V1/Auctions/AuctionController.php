<?php

namespace App\Http\Controllers\Api\V1\Auctions;

use App\Domain\Auctions\Models\Auction;
use App\Domain\Auctions\Models\AuctionBid;
use App\Domain\Auctions\Models\AuctionLot;
use App\Domain\Auctions\Models\AuctionWinner;
use App\Domain\Auctions\Services\AuctionPresenter;
use App\Domain\Auctions\Services\AuctionService;
use App\Domain\Auctions\Services\BidEngine;
use App\Domain\Orders\Services\OrderPresenter;
use App\Domain\Payments\Services\PaymentService;
use App\Http\Controllers\Api\V1\ApiController;
use App\Support\BusinessTime;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** @tags Auctions */
class AuctionController extends ApiController
{
    public function __construct(private readonly AuctionPresenter $presenter) {}

    public function index(Request $request): JsonResponse
    {
        $status = $request->validate(['status' => 'nullable|in:upcoming,live,completed'])['status'] ?? null;
        $q = Auction::with(['translations', 'lots'])->whereNotIn('status', ['draft'])
            ->when($status === 'upcoming', fn ($q) => $q->where('status', 'scheduled')->whereDoesntHave('lots', fn ($l) => $l->where('status', 'open')))
            ->when($status === 'live', fn ($q) => $q->where(fn ($w) => $w->whereIn('status', ['live', 'paused'])->orWhereHas('lots', fn ($l) => $l->where('status', 'open'))))
            ->when($status === 'completed', fn ($q) => $q->whereIn('status', ['closed', 'cancelled']))
            ->orderBy('starts_at');
        $page = $q->paginate($this->perPage($request));

        return response()->json(['data' => $page->getCollection()->map(fn ($a) => $this->presenter->card($a)),
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()]]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $a = Auction::with(['translations', 'lots'])->whereNotIn('status', ['draft'])->findOrFail($id);

        return response()->json(['data' => $this->presenter->detail($a, $request->user('sanctum'))]);
    }

    public function lot(Request $request, string $lotId): JsonResponse
    {
        return response()->json(['data' => $this->presenter->lot($this->publicLot($lotId), $request->user('sanctum'))]);
    }

    public function bids(Request $request, string $lotId): JsonResponse
    {
        return response()->json(['data' => $this->presenter->bids($this->publicLot($lotId), $request->user('sanctum'))]);
    }

    /** Event replay for reconnecting clients: everything after the last sequence they saw. */
    public function events(Request $request, string $lotId): JsonResponse
    {
        $after = (int) ($request->validate(['after_sequence' => 'nullable|integer|min:0'])['after_sequence'] ?? 0);
        $lot = $this->publicLot($lotId);

        return response()->json(['data' => $lot->events()->where('sequence', '>', $after)->limit(500)->get()
            ->map(fn ($e) => ['sequence' => $e->sequence, 'type' => $e->type, 'payload' => $this->presenter->publicPayload($e->payload), 'at' => BusinessTime::iso($e->created_at)]),
            'meta' => ['last_sequence' => $lot->last_event_sequence, 'server_time' => now()->toIso8601String()]]);
    }

    public function register(Request $request, string $id, AuctionService $svc, PaymentService $payments): JsonResponse
    {
        $request->validate(['accept_terms' => 'accepted']);
        $res = $svc->register($this->user(), Auction::findOrFail($id));

        return response()->json(['data' => [
            'status' => $res['bidder']->status, 'paddle_number' => $res['bidder']->paddle_number,
            'deposit_required' => $res['deposit'] ? Money::of($res['deposit']->amount)->jsonSerialize() : null,
            'deposit_payment' => $res['payment'] ? $payments->action($res['payment']) : null,
        ]], 201);
    }

    public function bid(Request $request, string $lotId, BidEngine $engine): JsonResponse
    {
        $key = $this->idempotencyKey($request);
        $data = $request->validate(['amount' => 'required|numeric|min:0']);
        $res = $engine->placeBid($this->user(), $lotId, Money::of((string) $data['amount']), $key, 'manual', $request->ip());

        return response()->json(['data' => [
            'bid' => $this->presenter->bid($res['bid'], $this->user()),
            'lot' => $this->presenter->lot($res['lot'], $this->user()),
            'replayed' => $res['replayed'],
        ]], $res['replayed'] ? 200 : 201);
    }

    public function autoBid(Request $request, string $lotId, BidEngine $engine): JsonResponse
    {
        $data = $request->validate(['max_amount' => 'required|numeric|min:0']);
        $engine->setAutoBid($this->user(), $lotId, Money::of((string) $data['max_amount']));

        return response()->json(['data' => $this->presenter->lot(AuctionLot::findOrFail($lotId), $this->user())]);
    }

    public function cancelAutoBid(string $lotId, BidEngine $engine): JsonResponse
    {
        $engine->cancelAutoBid($this->user(), $lotId);

        return response()->json(['data' => $this->presenter->lot(AuctionLot::findOrFail($lotId), $this->user())]);
    }

    public function watch(string $lotId, AuctionService $svc): JsonResponse
    {
        $svc->watch($this->user(), $this->publicLot($lotId), true);

        return response()->json(['data' => ['watching' => true]]);
    }

    public function unwatch(string $lotId, AuctionService $svc): JsonResponse
    {
        $svc->watch($this->user(), $this->publicLot($lotId), false);

        return response()->json(['data' => ['watching' => false]]);
    }

    public function myBids(): JsonResponse
    {
        $mine = AuctionBid::where('user_id', $this->user()->id)->where('status', 'accepted')->groupBy('auction_lot_id')
            ->selectRaw('auction_lot_id, max(amount) as my_highest, max(placed_at) as my_last')->toBase()->get()->keyBy('auction_lot_id');

        return response()->json(['data' => AuctionLot::whereIn('id', $mine->keys())->orderByDesc('updated_at')->get()->map(fn ($l) => $this->presenter->lot($l, $this->user()) + [
            'my_highest_bid' => Money::of((string) $mine[$l->id]->my_highest)->jsonSerialize(),
            'my_last_bid_at' => BusinessTime::iso($mine[$l->id]->my_last),
        ])]);
    }

    public function watchlist(): JsonResponse
    {
        $ids = \DB::table('auction_watchlists')->where('user_id', $this->user()->id)->pluck('auction_lot_id');

        return response()->json(['data' => AuctionLot::whereIn('id', $ids)->get()->map(fn ($l) => $this->presenter->lot($l, $this->user()))]);
    }

    public function wins(): JsonResponse
    {
        return response()->json(['data' => AuctionWinner::where('user_id', $this->user()->id)->with('lot')->latest()->get()->map(fn ($w) => [
            'id' => $w->id, 'status' => $w->status, 'amount' => Money::of($w->amount)->jsonSerialize(), 'payment_due_at' => BusinessTime::iso($w->payment_due_at),
            'order_id' => $w->order_id, 'lot' => $this->presenter->lot($w->lot, $this->user()),
        ])]);
    }

    public function checkoutWin(Request $request, string $winnerId, AuctionService $svc, PaymentService $payments, OrderPresenter $orders): JsonResponse
    {
        $data = $request->validate(['fulfillment_type' => 'required|in:delivery,pickup', 'address_id' => 'required_if:fulfillment_type,delivery|nullable|uuid',
            'branch_id' => 'nullable|uuid', 'payment_method' => 'required|in:sadad,cod,pay_at_pickup']);
        $res = $svc->checkoutWin($this->user(), AuctionWinner::findOrFail($winnerId), $data);

        return response()->json(['data' => ['order' => $orders->detail($res['order']->fresh()), 'payment' => $payments->action($res['payment'])]], 201);
    }

    private function publicLot(string $id): AuctionLot
    {
        return AuctionLot::whereHas('auction', fn ($q) => $q->where('status', '!=', 'draft'))->findOrFail($id);
    }
}
