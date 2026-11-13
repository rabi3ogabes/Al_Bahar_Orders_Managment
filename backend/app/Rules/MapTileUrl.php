<?php

namespace App\Rules;

use App\Support\MapConfig;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** An https tile URL template with {z}/{x}/{y} (and optionally {s}, {r}, {key}); see MapConfig. */
class MapTileUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $error = is_string($value) ? MapConfig::tileTemplateError($value) : 'empty';
        if ($error !== null) {
            $fail(__('org_admin.map_error.tile_'.$error));
        }
    }
}
