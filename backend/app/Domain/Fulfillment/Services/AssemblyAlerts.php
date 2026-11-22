<?php

namespace App\Domain\Fulfillment\Services;

use App\Domain\Operations\Services\StaffAlerts;
use App\Domain\Orders\Models\OrderFulfillment;

/**
 * "The assembly was not done": the branch team that can send drivers (deliveries.assign at that branch) gets a push and an inbox entry at once, because the
 * customer paid for it and somebody has to refund the fee or book it again. The text carries the order number and the reason, never customer data. The
 * notification controls (Content > Notification controls > Staff and driver alerts) can switch it off; the line in "Needs attention" stays either way.
 */
final class AssemblyAlerts
{
    public const EVENT = 'staff.assembly_not_done';

    public function __construct(private readonly StaffAlerts $staff) {}

    /** @return int how many people were reached */
    public function notDone(string $fulfillmentId): int
    {
        $f = OrderFulfillment::query()->with('order:id,number')->find($fulfillmentId);
        if ($f === null || $f->order === null || $f->assembly_status !== AssemblyFacts::NOT_DONE || $f->assembly_reason === null) {
            return 0;
        }
        $number = $f->order->number;
        $reason = (string) $f->assembly_reason;

        return $this->staff->send($this->staff->recipients($f->branch_id, ['deliveries.assign']), self::EVENT,
            fn (string $locale) => [
                'title' => __('delivery_followup.alert.assembly_title', ['number' => $number], $locale),
                'body' => __('delivery_followup.alert.assembly_body', ['number' => $number, 'reason' => __('delivery_followup.assembly_reason.'.$reason, [], $locale)], $locale),
            ],
            ['order_id' => $f->order_id, 'number' => $number, 'branch_id' => $f->branch_id], route('admin.orders.show', $f->order_id, false), push: ! SlotOffers::quietNow());
    }
}
