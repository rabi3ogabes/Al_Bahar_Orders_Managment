<?php

namespace App\Domain\Fulfillment\Services;

use App\Support\DomainException;

/**
 * The business-rule errors of the delivery follow-ups: a new time after a failed delivery, the assembly answer and the proof of delivery
 * (lang/{en,ar}/delivery_followup.php, "errors" group). Same envelope as every other DomainException: a stable top-level `code`, a plain translated
 * message in the customer's or the driver's language, an HTTP status.
 */
final class FollowUpError
{
    /**
     * @param  array<string, scalar|null>  $context  values for the message's placeholders (also sent back as the error's context)
     */
    public static function make(string $code, int $status = 409, array $context = []): DomainException
    {
        return new DomainException($code, __('delivery_followup.errors.'.$code, $context), $status, $context);
    }
}
