<?php

namespace App\Domain\Catalog\Models;

use App\Domain\Catalog\Services\RoomService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A room of the "Shop by room" section (Content > Rooms): a title in both languages, a picture, an order, and what belongs to it, the categories
 * (with their sub-categories) and the product tags. A room with no product the shopper can see is not shown.
 */
class Room extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $casts = ['tags' => 'array', 'is_active' => 'boolean', 'sort_order' => 'integer'];

    protected static function booted(): void
    {
        $flush = static fn () => RoomService::flush();
        static::saved($flush);
        static::deleted($flush);
    }

    /** @param  Builder<Room>  $q */
    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    /** @return BelongsToMany<Category, $this> */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'room_categories');
    }

    public function title(?string $locale = null): string
    {
        return (string) (($locale ?? app()->getLocale()) === 'ar' ? ($this->title_ar ?: $this->title_en) : $this->title_en);
    }

    public function description(?string $locale = null): ?string
    {
        $text = ($locale ?? app()->getLocale()) === 'ar' ? ($this->description_ar ?: $this->description_en) : $this->description_en;

        return $text !== null && $text !== '' ? $text : null;
    }
}
