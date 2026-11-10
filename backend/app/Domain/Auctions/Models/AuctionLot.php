<?php

namespace App\Domain\Auctions\Models;

use App\Domain\Shared\HasTranslations;
use App\Support\Money;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AuctionLot extends Model
{
    use HasFactory, HasTranslations, HasUuids;

    protected $guarded = ['id'];

    protected $hidden = ['reserve_price'];

    protected $attributes = ['status' => 'pending', 'bid_count' => 0, 'last_bid_number' => 0, 'last_event_sequence' => 0, 'extension_count' => 0, 'quantity' => 1, 'sort_order' => 0];

    protected $casts = ['starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime', 'original_ends_at' => 'immutable_datetime',
        'paused_at' => 'immutable_datetime', 'closed_at' => 'immutable_datetime', 'bid_count' => 'integer', 'last_bid_number' => 'integer',
        'last_event_sequence' => 'integer', 'extension_count' => 'integer'];

    /** @return BelongsTo<Auction, $this> */
    public function auction(): BelongsTo
    {
        return $this->belongsTo(Auction::class);
    }

    /** @return HasMany<AuctionBid, $this> */
    public function bids(): HasMany
    {
        return $this->hasMany(AuctionBid::class);
    }

    /** @return HasMany<AuctionProduct, $this> */
    public function products(): HasMany
    {
        return $this->hasMany(AuctionProduct::class);
    }

    /** @return HasMany<AuctionAutoBid, $this> */
    public function autoBids(): HasMany
    {
        return $this->hasMany(AuctionAutoBid::class);
    }

    /** @return HasMany<AuctionLotEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(AuctionLotEvent::class)->orderBy('sequence');
    }

    /** @return HasMany<AuctionWinner, $this> */
    public function winners(): HasMany
    {
        return $this->hasMany(AuctionWinner::class)->orderBy('rank');
    }

    /** Smallest acceptable next bid: the starting price for the first bid, then current + increment. */
    public function minNextBid(): Money
    {
        return $this->current_price === null ? Money::of($this->starting_price) : Money::of($this->current_price)->plus(Money::of($this->min_increment));
    }

    public function reserveMet(): ?bool
    {
        if ($this->reserve_price === null) {
            return null;
        }

        return $this->current_price !== null && Money::of($this->current_price)->greaterThanOrEqual(Money::of($this->reserve_price));
    }

    public function isOpenAt(\DateTimeInterface $at): bool
    {
        return $this->status === 'open' && $this->ends_at && $at < $this->ends_at && (! $this->starts_at || $at >= $this->starts_at);
    }
}
