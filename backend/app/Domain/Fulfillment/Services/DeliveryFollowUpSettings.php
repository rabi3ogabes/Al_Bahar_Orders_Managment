<?php

namespace App\Domain\Fulfillment\Services;

use App\Domain\Settings\Services\Settings;

/**
 * The switches of the delivery follow-ups (Settings > Checkout & fees, docs/guides/DELIVERY_FOLLOW_UPS.md). Each has a plain default, so the owner
 * changes them without a release; a value that is out of range falls back to the default.
 *
 *  - delivery.slot_offers_enabled     after a failed delivery the customer is offered new times to choose from (default on: it only replaces a phone call)
 *  - delivery.slot_offer_count        how many times are offered, 1 to 5 (default 3)
 *  - delivery.slot_offer_hours        how long an offer stays open, 1 to 336 hours (default 48)
 *  - delivery.proof_for_customer      the customer sees the photo of the delivery (default on; off keeps the time and the name of who received it)
 *  - delivery.assembly_answer_required a delivery with paid assembly cannot be marked delivered before the driver says whether it was done (default on)
 */
final class DeliveryFollowUpSettings
{
    public const OFFERS_ENABLED = 'delivery.slot_offers_enabled';

    public const OFFER_COUNT = 'delivery.slot_offer_count';

    public const OFFER_HOURS = 'delivery.slot_offer_hours';

    public const PROOF_FOR_CUSTOMER = 'delivery.proof_for_customer';

    public const ASSEMBLY_ANSWER_REQUIRED = 'delivery.assembly_answer_required';

    public const DEFAULT_COUNT = 3;

    public const DEFAULT_HOURS = 48;

    public function __construct(private readonly Settings $settings) {}

    public function offersEnabled(): bool
    {
        return (bool) $this->settings->get(self::OFFERS_ENABLED, true);
    }

    public function offerCount(): int
    {
        return $this->within(self::OFFER_COUNT, self::DEFAULT_COUNT, 1, 5);
    }

    public function offerHours(): int
    {
        return $this->within(self::OFFER_HOURS, self::DEFAULT_HOURS, 1, 336);
    }

    public function proofForCustomer(): bool
    {
        return (bool) $this->settings->get(self::PROOF_FOR_CUSTOMER, true);
    }

    public function assemblyAnswerRequired(): bool
    {
        return (bool) $this->settings->get(self::ASSEMBLY_ANSWER_REQUIRED, true);
    }

    private function within(string $key, int $default, int $min, int $max): int
    {
        $raw = $this->settings->get($key, $default);

        return is_numeric($raw) && (int) $raw >= $min && (int) $raw <= $max ? (int) $raw : $default;
    }
}
