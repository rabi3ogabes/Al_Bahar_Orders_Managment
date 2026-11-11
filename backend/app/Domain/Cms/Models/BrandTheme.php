<?php

namespace App\Domain\Cms\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class BrandTheme extends Model
{
    use HasUuids;

    /** Colour slots every theme defines (hex). */
    public const COLOR_KEYS = ['primary', 'primary_soft', 'accent', 'background', 'surface', 'ink'];

    protected $guarded = ['id'];

    protected $casts = [
        'colors' => 'array', 'is_default' => 'boolean', 'is_forced' => 'boolean', 'is_enabled' => 'boolean',
        'starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime',
    ];

    public function localized(string $field, ?string $locale = null): ?string
    {
        $locale ??= app()->getLocale();

        return $this->{$field.'_'.$locale} ?: $this->{$field.'_en'};
    }
}
