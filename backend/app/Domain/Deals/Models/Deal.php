<?php

namespace App\Domain\Deals\Models;

use App\Domain\Shared\HasTranslations;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * A countdown offer (docs/api/DEALS.md). The phase is computed from the window, the switch and the product quantities, never stored.
 *
 * @property string $id
 * @property bool $is_active
 */
class Deal extends Model
{
    use HasTranslations, HasUuids;

    public const PERCENT = 'percent';

    public const AMOUNT_OFF = 'amount_off';

    public const FIXED_PRICE = 'fixed_price';

    public const DISCOUNT_TYPES = [self::PERCENT, self::AMOUNT_OFF, self::FIXED_PRICE];

    public const SCHEDULED = 'scheduled';

    public const LIVE = 'live';

    public const SOLD_OUT = 'sold_out';

    public const ENDED = 'ended';

    public const PAUSED = 'paused';

    /** A sold-out deal that is still inside its window stays on the public list this long, so the person who tapped it sees why it is gone. */
    public const SOLD_OUT_VISIBLE_MINUTES = 15;

    protected $guarded = ['id'];

    protected $attributes = ['is_active' => false, 'show_on_home' => false, 'sort_order' => 0, 'discount_value' => '0.00'];

    protected $casts = [
        'starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime', 'ended_at' => 'immutable_datetime', 'sold_out_at' => 'immutable_datetime',
        'is_active' => 'boolean', 'show_on_home' => 'boolean', 'design' => 'array', 'discount_value' => 'decimal:2',
        'limit_per_order' => 'integer', 'limit_per_customer' => 'integer', 'sort_order' => 'integer',
    ];

    /** @return HasMany<DealProduct, $this> */
    public function products(): HasMany
    {
        return $this->hasMany(DealProduct::class)->orderBy('sort_order')->orderBy('created_at')->orderBy('id');
    }

    /** @return HasMany<DealRedemption, $this> */
    public function redemptions(): HasMany
    {
        return $this->hasMany(DealRedemption::class);
    }

    /**
     * Deals a customer can buy at the deal price right now: switched on, not ended, inside the window. (Sold-out is decided per product.)
     *
     * @param  Builder<Deal>  $q
     * @return Builder<Deal>
     */
    public function scopeRunning(Builder $q, ?CarbonInterface $now = null): Builder
    {
        $now ??= now();

        return $q->where('is_active', true)->whereNull('ended_at')->where('ends_at', '>', $now)
            ->where(fn ($w) => $w->whereNull('starts_at')->orWhere('starts_at', '<=', $now));
    }

    /**
     * Switched on and not ended, whether it started or not: the deals that count for the "one product, one deal at a time" rule and that the shop shows.
     *
     * @param  Builder<Deal>  $q
     * @return Builder<Deal>
     */
    public function scopeOpen(Builder $q, ?CarbonInterface $now = null): Builder
    {
        $now ??= now();

        return $q->where('is_active', true)->whereNull('ended_at')->where('ends_at', '>', $now);
    }

    /**
     * Every product a shopper could buy has a quantity and all of it is gone (an unlimited product is never sold out, an empty deal is not sold out either).
     * A product that is archived or switched off in the catalog cannot be bought, so it neither keeps a deal "live" nor counts as "sold out"; pass `$rows`
     * to judge a smaller set (the products sold at the shopper's branch).
     *
     * @param  Collection<int, DealProduct>|null  $rows  the deal's products to judge; null = all of them that are active in the catalog
     */
    public function isSoldOut(?Collection $rows = null): bool
    {
        if ($rows === null) {
            $this->loadMissing('products.product:id,status');
            $rows = $this->products->filter(fn (DealProduct $p) => $p->product?->status === 'active');
        }

        return $rows->isNotEmpty() && $rows->every(fn (DealProduct $p) => $p->quantity_total !== null && $p->quantity_sold >= $p->quantity_total);
    }

    /**
     * True when some product has a quantity but nothing limits how much one customer can take of it (no limit per order and none per account, on the product or
     * on the deal): one account could then take every unit, cancel and do it again. The dashboard warns about it.
     */
    public function lacksBuyerLimit(): bool
    {
        $this->loadMissing('products');

        return $this->products->contains(fn (DealProduct $p) => $p->quantity_total !== null && $p->perOrderLimit($this) === null && $p->perCustomerLimit($this) === null);
    }

    /** @param  Collection<int, DealProduct>|null  $rows  see isSoldOut() */
    public function phase(?CarbonInterface $now = null, ?Collection $rows = null): string
    {
        $now ??= now();
        if ($this->ended_at !== null || $this->ends_at->lessThanOrEqualTo($now)) {
            return self::ENDED;
        }
        if (! $this->is_active) {
            return self::PAUSED;
        }
        if ($this->starts_at !== null && $this->starts_at->greaterThan($now)) {
            return self::SCHEDULED;
        }

        return $this->isSoldOut($rows) ? self::SOLD_OUT : self::LIVE;
    }
}
