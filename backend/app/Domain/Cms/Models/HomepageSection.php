<?php

namespace App\Domain\Cms\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HomepageSection extends Model
{
    use HasUuids;

    protected $table = 'homepage_sections';

    protected $guarded = ['id'];

    protected $casts = ['settings' => 'array', 'starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime'];

    /** @return HasMany<HomepageSectionItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(HomepageSectionItem::class)->orderBy('sort_order');
    }
}
