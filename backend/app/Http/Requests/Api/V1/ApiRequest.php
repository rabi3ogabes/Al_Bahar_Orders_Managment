<?php

namespace App\Http\Requests\Api\V1;

use App\Support\Phone;
use Illuminate\Foundation\Http\FormRequest;

abstract class ApiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // authorization is enforced by route middleware, policies and AccessControl
    }

    /** Normalise phone fields to E.164 before validation so rules and lookups see one canonical form. */
    protected function prepareForValidation(): void
    {
        foreach (['phone', 'recipient_phone', 'alternate_collector_phone'] as $field) {
            if ($this->has($field) && is_string($this->input($field))) {
                $this->merge([$field => Phone::normalize($this->input($field)) ?? $this->input($field)]);
            }
        }
    }
}
