<?php

namespace App\Domain\Cms\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class BrandTheme extends Model
{
    use HasUuids;

    /** Colour slots every theme defines (hex). */
    public const COLOR_KEYS = ['primary', 'primary_soft', 'accent', 'background', 'surface', 'ink'];

    /** Layout styles a design can use (the other half of a design next to its colours). */
    public const LAYOUTS = ['classic', 'fresh'];

    /** Look tokens a design can set: allowed values per token, the first one listed is NOT the default (see TOKEN_DEFAULTS). */
    public const TOKEN_OPTIONS = [
        'radius' => ['md', 'lg', 'xl'], 'card' => ['flat', 'soft', 'outlined'], 'header' => ['standard', 'brand'],
        'nav' => ['classic', 'center_action'], 'background' => ['plain', 'gradient'], 'density' => ['comfortable', 'compact'],
    ];

    public const TOKEN_DEFAULTS = ['radius' => 'lg', 'card' => 'soft', 'header' => 'standard', 'nav' => 'classic', 'background' => 'plain', 'density' => 'comfortable'];

    /** Tokens with every missing or unknown value replaced by its default, so apps always get the full set. @return array<string, string> */
    public static function normalizeTokens(?array $tokens): array
    {
        $out = [];
        foreach (self::TOKEN_DEFAULTS as $key => $default) {
            $v = $tokens[$key] ?? null;
            $out[$key] = is_string($v) && in_array($v, self::TOKEN_OPTIONS[$key], true) ? $v : $default;
        }

        return $out;
    }

    protected $guarded = ['id'];

    protected $casts = [
        'colors' => 'array', 'tokens' => 'array', 'is_default' => 'boolean', 'is_forced' => 'boolean', 'is_enabled' => 'boolean',
        'starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime',
    ];

    public function localized(string $field, ?string $locale = null): ?string
    {
        $locale ??= app()->getLocale();

        return $this->{$field.'_'.$locale} ?: $this->{$field.'_en'};
    }
}
