<?php

namespace App\Rules;

use App\Support\MapConfig;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** The base URL of an OSRM-compatible routing service: https, no credentials, no query, not a loopback or metadata address. */
class MapServiceUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $error = is_string($value) ? MapConfig::serviceUrlError($value) : 'https';
        if ($error !== null) {
            $fail(__('org_admin.map_error.url_'.$error));
        }
    }
}
