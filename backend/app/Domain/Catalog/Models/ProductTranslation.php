<?php

namespace App\Domain\Catalog\Models;

use App\Support\SearchNormalizer;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductTranslation extends Model
{
    use HasUuids;

    protected $guarded = ['id', 'search_text'];

    protected static function booted(): void
    {
        // Keep the normalised search document in sync (name, keywords, material, short description).
        static::saving(function (self $t) {
            $t->search_text = SearchNormalizer::normalize(implode(' ', array_filter([
                $t->name, $t->search_keywords, $t->material, $t->short_description,
            ])));
        });
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
