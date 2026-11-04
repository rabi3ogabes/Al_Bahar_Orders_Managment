<?php

namespace App\Domain\Shared;

/**
 * For models with paired *_en / *_ar columns. $model->localized('name') returns the current-locale value
 * with English fallback.
 */
trait HasTranslations
{
    public function localized(string $field, ?string $locale = null): ?string
    {
        $locale ??= app()->getLocale();
        $value = $this->getAttribute($field.'_'.$locale);

        return ($value === null || $value === '') ? $this->getAttribute($field.'_en') : $value;
    }
}
