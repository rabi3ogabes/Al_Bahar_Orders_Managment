<?php

namespace App\Domain\Catalog\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Models with a `translations` relation (one row per locale). Reads fall back to English.
 *
 * @mixin Model
 */
trait Translatable
{
    public function translation(?string $locale = null): ?Model
    {
        $locale ??= app()->getLocale();
        $translations = $this->relationLoaded('translations') ? $this->translations : $this->translations()->get();

        return $translations->firstWhere('locale', $locale) ?? $translations->firstWhere('locale', 'en') ?? $translations->first();
    }

    public function trans(string $field, ?string $locale = null): ?string
    {
        $t = $this->translation($locale);
        $value = $t?->getAttribute($field);
        if (($value === null || $value === '') && $locale !== 'en') {
            $value = $this->translation('en')?->getAttribute($field);
        }

        return $value;
    }
}
