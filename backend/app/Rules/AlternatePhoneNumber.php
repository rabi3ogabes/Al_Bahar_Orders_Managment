<?php

namespace App\Rules;

use App\Support\AlternatePhone;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** A Qatar mobile number that is not the account's main number (empty is allowed: it clears the saved number). */
class AlternatePhoneNumber implements ValidationRule
{
    public function __construct(private readonly ?string $mainPhone) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail(__('errors.'.AlternatePhone::INVALID));

            return;
        }
        [, $error] = AlternatePhone::check($value, $this->mainPhone);
        if ($error !== null) {
            $fail(__('errors.'.$error));
        }
    }
}
