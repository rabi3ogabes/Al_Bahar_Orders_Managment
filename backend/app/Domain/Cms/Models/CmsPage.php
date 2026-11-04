<?php

namespace App\Domain\Cms\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CmsPage extends Model
{
    use HasUuids;

    protected $table = 'cms_pages';

    protected $guarded = ['id'];

    protected $casts = ['published_at' => 'immutable_datetime'];

    public function translations(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(CmsPageTranslation::class);
    }
}
