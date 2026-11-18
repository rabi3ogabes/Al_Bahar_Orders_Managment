<?php

namespace App\Rules;

use App\Support\IntroSlides;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** A text of the customer app's intro slides: plain characters only (see IntroSlides). Length is checked by the `max` rule next to it. */
class IntroText implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && IntroSlides::hasControlCharacters($value)) {
            $fail(__('intro_admin.control_characters'));
        }
    }
}
