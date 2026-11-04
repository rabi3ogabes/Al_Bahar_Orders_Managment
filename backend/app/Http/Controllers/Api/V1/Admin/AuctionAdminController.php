<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Auctions\Models\Auction;
use App\Domain\Auctions\Models\AuctionBid;
use App\Domain\Auctions\Models\AuctionBidder;
use App\Domain\Auctions\Models\AuctionLot;
use App\Domain\Auctions\Services\AuctionService;
use App\Domain\Auctions\Services\LotService;
use App\Domain\Audit\Services\AuditLogger;
use App\Http\Controllers\Api\V1\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** @tags Admin · Auctions */
class AuctionAdminController extends ApiController
{
    public function store(Request $request, AuctionService $svc): JsonResponse
    {
        return response()->json(['data' => $svc->save($this->user(), $this->rules($request))], 201);
    }

    public function update(Request $request, Auction $auction, AuctionService $svc): JsonResponse
    {
        return response()->json(['data' => $svc->save($this->user(), $this->rules($request, true), $auction)]);
    }

    public function addLot(Request $request, Auction $auction, AuctionService $svc): JsonResponse
    {
        $data = $request->validate(['title_en' => 'required|string|max:255', 'title_ar' => 'required|string|max:255', 'description_en' => 'nullable|string', 'description_ar' => 'nullable|string',
            'starting_price' => 'required|numeric|min:0', 'reserve_price' => 'nullable|numeric|min:0', 'min_increment' => 'required|numeric|min:1',
            'buy_now_price' => 'nullable|numeric|gt:starting_price', 'quantity' => 'nullable|integer|min:1', 'lot_number' => 'nullable|integer|min:1',
            'starts_at' => 'nullable|date', 'ends_at' => 'nullable|date|after:starts_at', 'product_variant_id' => 'nullable|uuid|exists:product_variants,id',
            'branch_id' => 'required_with:product_variant_id|nullable|uuid|exists:branches,id', 'image_path' => 'nullable|string|max:255']);

        return response()->json(['data' => $svc->addLot($this->user(), $auction, $data)], 201);
    }

    public function publish(Auction $auction, AuctionService $svc): JsonResponse
    {
        return response()->json(['data' => $svc->publish($this->user(), $auction)]);
    }

    public function cancel(Request $request, Auction $auction, AuctionService $svc): JsonResponse
    {
        return response()->json(['data' => $svc->cancel($this->user(), $auction, $request->validate(['reason' => 'required|string|max:500'])['reason'])]);
    }

    /** Auctioneer controls for live events. */
    public function lotAction(Request $request, AuctionLot $lot, string $action, LotService $lots): JsonResponse
    {
        $u = $this->user();
        $res = match ($action) {
            'open' => $lots->open($lot, $u, ((int) ($request->validate(['duration_seconds' => 'nullable|integer|min:30|max:604800'])['duration_seconds'] ?? 0)) ?: null),
            'pause' => $lots->pause($lot, $u, $request->validate(['reason' => 'required|string|max:255'])['reason']),
            'resume' => $lots->resume($lot, $u),
            'close' => $lots->close($lot, 'hammer', actor: $u),
            'cancel' => $lots->cancel($lot, $u, $request->validate(['reason' => 'required|string|max:255'])['reason']),
            default => abort(404),
        };

        return response()->json(['data' => $res->fresh()]);
    }

    public function retractBid(Request $request, AuctionBid $bid, LotService $lots): JsonResponse
    {
        return response()->json(['data' => $lots->retractBid($bid, $this->user(), $request->validate(['reason' => 'required|string|max:500'])['reason'])]);
    }

    public function blockBidder(Request $request, AuctionBidder $bidder): JsonResponse
    {
        $reason = $request->validate(['reason' => 'required|string|max:500'])['reason'];
        $bidder->update(['status' => 'blocked', 'blocked_reason' => $reason, 'blocked_by' => $this->user()->id]);
        app(AuditLogger::class)->log('auction.bidder_blocked', $bidder, [], ['reason' => $reason]);

        return response()->json(['data' => $bidder]);
    }

    /** Live feed for the auctioneer dashboard (full detail incl. reserve and bidder identity). */
    public function feed(AuctionLot $lot): JsonResponse
    {
        return response()->json(['data' => ['lot' => $lot->makeVisible('reserve_price'), 'bids' => $lot->bids()->with('user:id,name,phone')->orderByDesc('created_at')->limit(200)->get(),
            'auto_bids' => $lot->autoBids()->with('user:id,name')->get(), 'events' => $lot->events()->orderByDesc('sequence')->limit(100)->get()]]);
    }

    private function rules(Request $request, bool $partial = false): array
    {
        $r = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'type' => "$r|in:scheduled,live", 'starts_at' => 'nullable|date', 'ends_at' => 'nullable|date|after:starts_at',
            'requires_registration' => 'boolean', 'requires_verified_phone' => 'boolean', 'deposit_amount' => 'nullable|numeric|min:0',
            'winner_payment_hours' => 'nullable|integer|min:1|max:720', 'cod_allowed' => 'boolean', 'pickup_allowed' => 'boolean', 'delivery_allowed' => 'boolean',
            'extension_window_seconds' => 'nullable|integer|min:0|max:3600', 'extension_seconds' => 'nullable|integer|min:0|max:3600',
            'max_extensions' => 'nullable|integer|min:0', 'unpaid_winner_policy' => 'nullable|in:offer_next_bidder,relist,none', 'show_bidder_names' => 'boolean',
            'translations' => "$r|array", 'translations.en.title' => "$r|string|max:255", 'translations.ar.title' => "$r|string|max:255",
            'translations.*.description' => 'nullable|string', 'translations.*.terms' => 'nullable|string', 'branch_ids' => 'nullable|array', 'branch_ids.*' => 'uuid|exists:branches,id',
        ]);
    }
}
