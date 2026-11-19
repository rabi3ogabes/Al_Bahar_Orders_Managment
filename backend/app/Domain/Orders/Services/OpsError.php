<?php

namespace App\Domain\Orders\Services;

use App\Support\DomainException;

/**
 * The business-rule errors of the order and delivery integrity rules (lang/{en,ar}/ops_integrity.php, "errors" group).
 * Same envelope as every other DomainException: a stable top-level `code`, a plain translated message, an HTTP status.
 */
final class OpsError
{
    /**
     * @param  array<string, scalar|null>  $context  values for the message's placeholders (also sent back as the error's context)
     * @param  string|null  $messageKey  when the message is not the one named after the code
     */
    public static function make(string $code, int $status = 409, array $context = [], ?string $messageKey = null): DomainException
    {
        return new DomainException($code, __('ops_integrity.errors.'.($messageKey ?? $code), $context), $status, $context);
    }
}
