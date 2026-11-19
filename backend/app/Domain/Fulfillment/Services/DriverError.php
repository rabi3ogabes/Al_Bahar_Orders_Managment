<?php

namespace App\Domain\Fulfillment\Services;

use App\Support\DomainException;

/**
 * The business-rule errors of the driver's own screens (lang/{en,ar}/driver_day.php, "errors" group): a stable `code` the app maps to its own
 * plain sentence, and the same sentence in the request's language as the message, for any client that shows it as it is.
 */
final class DriverError
{
    /** @param array<string, scalar|null> $context values for the message's placeholders (also sent back as the error's context) */
    public static function make(string $code, int $status = 409, array $context = []): DomainException
    {
        return new DomainException($code, trans_choice('driver_day.errors.'.$code, (int) ($context['count'] ?? 1), $context), $status, $context);
    }
}
