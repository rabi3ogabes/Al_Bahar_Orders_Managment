<?php

namespace App\Domain\Operations\Support;

/**
 * One line of the "Needs attention" inbox: what waits for a person, where, for how long, and the one thing to tap.
 *
 * `action` is one of
 *   ['type' => 'peek',    'label' => string, 'order' => string]        opens the order in the side window of the page
 *   ['type' => 'link',    'label' => string, 'url' => string]          goes to the screen that holds the action (filtered to this order)
 *   ['type' => 'reissue', 'label' => string, 'fulfillment' => string]  makes a fresh pickup pass in place
 * and `call` is an optional second tap, a phone number to ring (the customer, or the driver who has not answered).
 */
final class AttentionItem
{
    /** @var list<string> */
    public const CATEGORIES = ['unaccepted', 'stuck', 'undispatched', 'driver_silent', 'delivery_failed', 'pickup_waiting', 'cancellation', 'refund', 'claim', 'substitution', 'cash', 'queue', 'campaign'];

    /**
     * @param  array{type: string, label: string, order?: string, url?: string, fulfillment?: string}  $action
     * @param  array{label: string, phone: string}|null  $call
     */
    public function __construct(
        public readonly string $id,
        public readonly string $category,
        public readonly string $icon,
        public readonly string $subject,
        public readonly string $label,
        public readonly string $detail,
        public readonly string $state,
        public readonly ?OpsAge $age,
        public readonly ?string $branchId,
        public readonly ?string $branchCode,
        public readonly array $action,
        public readonly ?array $call = null,
        public readonly ?string $assignedTo = null,
        public readonly bool $subjectIsNumber = true,
        public readonly ?string $note = null,
    ) {}

    public function rank(): int
    {
        return OpsAge::rankOf($this->state);
    }
}
