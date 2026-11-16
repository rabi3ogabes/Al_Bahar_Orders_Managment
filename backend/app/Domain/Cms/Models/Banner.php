<?php

namespace App\Domain\Cms\Models;

use App\Domain\Cms\Services\HomepageService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One slide. `homepage_section_id` set = a slide of that home slider; null = a banner of the "other places" (category top, auctions).
 * Every change drops the cached home feed, so an edit in the dashboard is live at once.
 */
class Banner extends Model
{
    use HasUuids, SoftDeletes;

    /** Placements outside the home slider, still managed on the same page. */
    public const OTHER_PLACEMENTS = ['category_top', 'auctions'];

    public const LINK_TYPES = ['category', 'page', 'product', 'brand', 'auction', 'url', 'search'];

    protected $table = 'banners';

    protected $guarded = ['id'];

    protected $casts = ['starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime', 'sort_order' => 'integer'];

    protected static function booted(): void
    {
        $flush = static fn () => HomepageService::flush();
        static::saved($flush);
        static::deleted($flush);
        static::restored($flush);
    }

    /** @return BelongsTo<HomepageSection, $this> */
    public function slider(): BelongsTo
    {
        return $this->belongsTo(HomepageSection::class, 'homepage_section_id');
    }

    /** Published and inside its schedule window right now. */
    public function scopeLive(Builder $q): Builder
    {
        $now = now();

        return $q->where('status', 'published')
            ->where(fn ($w) => $w->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn ($w) => $w->whereNull('ends_at')->orWhere('ends_at', '>', $now));
    }

    public function isLive(): bool
    {
        $now = now();

        return $this->status === 'published' && (! $this->starts_at || $this->starts_at->lte($now)) && (! $this->ends_at || $this->ends_at->gt($now));
    }
}
