<?php

namespace App\Domain\Auctions\Services;

use App\Domain\Auctions\Models\Auction;
use App\Domain\Auctions\Models\AuctionAutoBid;
use App\Domain\Auctions\Models\AuctionBidder;
use App\Domain\Auctions\Models\AuctionLot;
use App\Domain\Identity\Models\User;
use App\Support\BusinessTime;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Public auction state. Reserve prices and other bidders' maximums are never exposed. */
class AuctionPresenter
{
    public function card(Auction $a): array
    {
        $a->loadMissing(['translations', 'lots']);

        return [
            'id' => $a->id, 'reference' => $a->reference, 'type' => $a->type, 'status' => $this->publicStatus($a),
            'title' => $a->trans('title'), 'cover_image_url' => $this->url($a->cover_image_path ?? $a->lots->first()?->image_path),
            'starts_at' => BusinessTime::iso($a->starts_at ?? $a->lots->min('starts_at')), 'ends_at' => BusinessTime::iso($a->ends_at ?? $a->lots->max('ends_at')),
            'lots_count' => $a->lots->count(), 'deposit' => Money::of($a->deposit_amount)->isPositive() ? Money::of($a->deposit_amount)->jsonSerialize() : null,
        ];
    }

    public function detail(Auction $a, ?User $viewer): array
    {
        $reg = $viewer ? AuctionBidder::where('auction_id', $a->id)->where('user_id', $viewer->id)->first() : null;

        return $this->card($a) + [
            'description' => $a->trans('description'), 'terms' => $a->trans('terms'),
            'requires_registration' => $a->requires_registration, 'requires_verified_phone' => $a->requires_verified_phone,
            'winner_payment_hours' => $a->winner_payment_hours, 'cod_allowed' => $a->cod_allowed,
            'pickup_allowed' => $a->pickup_allowed, 'delivery_allowed' => $a->delivery_allowed,
            'my_registration' => $reg ? ['status' => $reg->status, 'paddle_number' => $reg->paddle_number] : null,
            'lots' => $a->lots->map(fn ($l) => $this->lot($l, $viewer))->values(),
            'server_time' => now()->toIso8601String(),
        ];
    }

    public function lot(AuctionLot $l, ?User $viewer): array
    {
        $myAuto = $viewer ? AuctionAutoBid::where('auction_lot_id', $l->id)->where('user_id', $viewer->id)->where('status', 'active')->value('max_amount') : null;
        $myStatus = null;
        if ($viewer) {
            $hasBid = DB::table('auction_bids')->where('auction_lot_id', $l->id)->where('user_id', $viewer->id)->where('status', 'accepted')->exists();
            $myStatus = match (true) {
                in_array($l->status, ['sold'], true) && $l->current_leader_id === $viewer->id => 'won',
                in_array($l->status, ['sold', 'unsold'], true) && $hasBid => 'lost',
                $l->current_leader_id === $viewer->id => 'leading',
                $hasBid => 'outbid',
                default => null,
            };
        }

        return [
            'id' => $l->id, 'auction_id' => $l->auction_id, 'lot_number' => $l->lot_number, 'title' => $l->localized('title'), 'description' => $l->localized('description'),
            'image_url' => $this->url($l->image_path), 'quantity' => $l->quantity, 'status' => $l->status,
            'starting_price' => Money::of($l->starting_price)->jsonSerialize(),
            'current_price' => $l->current_price !== null ? Money::of($l->current_price)->jsonSerialize() : null,
            'min_next_bid' => $l->minNextBid()->jsonSerialize(), 'min_increment' => Money::of($l->min_increment)->jsonSerialize(),
            'buy_now_price' => $l->buy_now_price !== null ? Money::of($l->buy_now_price)->jsonSerialize() : null,
            'reserve_met' => $l->reserveMet(), 'bid_count' => $l->bid_count, 'last_sequence' => $l->last_event_sequence,
            'starts_at' => BusinessTime::iso($l->starts_at), 'ends_at' => BusinessTime::iso($l->ends_at), 'extension_count' => $l->extension_count,
            'server_time' => now()->toIso8601String(),
            'my_status' => $myStatus, 'my_max_auto_bid' => $myAuto ? Money::of($myAuto)->jsonSerialize() : null,
            'is_watching' => $viewer ? DB::table('auction_watchlists')->where('user_id', $viewer->id)->where('auction_lot_id', $l->id)->exists() : false,
        ];
    }

    /** Bid history with bidder anonymisation ("Bidder 12" / "You"). */
    public function bids(AuctionLot $l, ?User $viewer): array
    {
        $paddles = AuctionBidder::where('auction_id', $l->auction_id)->pluck('paddle_number', 'user_id');

        return $l->bids()->where('status', 'accepted')->orderByDesc('bid_number')->limit(100)->get()->map(fn ($b) => [
            'bid_number' => $b->bid_number, 'amount' => Money::of($b->amount)->jsonSerialize(), 'type' => $b->type === 'auto' ? 'auto' : 'manual',
            'bidder' => $viewer && $b->user_id === $viewer->id ? __('auctions.you') : __('auctions.bidder', ['n' => $paddles[$b->user_id] ?? '—']),
            'placed_at' => BusinessTime::iso($b->placed_at),
        ])->all();
    }

    /** Strip identifiers from event payloads before they leave the server. */
    public function publicPayload(array $payload): array
    {
        unset($payload['previous_leader']);

        return $payload;
    }

    public function publicStatus(Auction $a): string
    {
        return match ($a->status) {
            'scheduled' => $a->lots->contains('status', 'open') ? 'live' : 'upcoming',
            'live', 'paused' => 'live',
            'closed', 'cancelled' => 'completed',
            default => $a->status,
        };
    }

    private function url(?string $path): ?string
    {
        return $path ? (str_starts_with($path, 'http') ? $path : Storage::disk(config('platform.media.disk'))->url($path)) : null;
    }
}
