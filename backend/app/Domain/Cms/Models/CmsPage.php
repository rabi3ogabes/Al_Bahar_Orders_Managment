<?php

namespace App\Domain\Cms\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CmsPage extends Model
{
    use HasUuids;

    protected $table = 'cms_pages';

    protected $guarded = ['id'];

    protected $casts = ['published_at' => 'immutable_datetime'];

    /** @return HasMany<CmsPageTranslation, $this> */
    public function translations(): HasMany
    {
        return $this->hasMany(CmsPageTranslation::class);
    }
}
