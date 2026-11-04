<?php

namespace App\Domain\Cms\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class HomepageSection extends Model
{
    use HasUuids;

    protected $table = 'homepage_sections';

    protected $guarded = ['id'];

    protected $casts = ['settings' => 'array', 'starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime'];

    public function items(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(HomepageSectionItem::class)->orderBy('sort_order');
    }
}
