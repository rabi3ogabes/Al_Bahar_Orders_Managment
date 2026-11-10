<?php

namespace App\Domain\Auctions\Models;

use App\Domain\Branches\Models\Branch;
use App\Domain\Catalog\Models\Translatable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Auction extends Model
{
    use HasFactory, HasUuids, Translatable;

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'draft', 'requires_registration' => true, 'requires_verified_phone' => true, 'deposit_amount' => '0.00',
        'winner_payment_hours' => 48, 'cod_allowed' => false, 'pickup_allowed' => true, 'delivery_allowed' => true,
        'extension_window_seconds' => 120, 'extension_seconds' => 120, 'unpaid_winner_policy' => 'offer_next_bidder', 'show_bidder_names' => false];

    protected $casts = ['starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime', 'published_at' => 'immutable_datetime', 'cancelled_at' => 'immutable_datetime',
        'requires_registration' => 'boolean', 'requires_verified_phone' => 'boolean', 'cod_allowed' => 'boolean', 'pickup_allowed' => 'boolean',
        'delivery_allowed' => 'boolean', 'show_bidder_names' => 'boolean'];

    /** @return HasMany<AuctionTranslation, $this> */
    public function translations(): HasMany
    {
        return $this->hasMany(AuctionTranslation::class);
    }

    /** @return HasMany<AuctionLot, $this> */
    public function lots(): HasMany
    {
        return $this->hasMany(AuctionLot::class)->orderBy('sort_order')->orderBy('lot_number');
    }

    /** @return HasMany<AuctionBidder, $this> */
    public function bidders(): HasMany
    {
        return $this->hasMany(AuctionBidder::class);
    }

    /** @return BelongsToMany<Branch, $this> */
    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'auction_branches');
    }

    public function isLiveType(): bool
    {
        return $this->type === 'live';
    }
}
