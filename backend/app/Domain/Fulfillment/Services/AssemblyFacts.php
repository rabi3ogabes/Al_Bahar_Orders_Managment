<?php

namespace App\Domain\Fulfillment\Services;

use App\Domain\Checkout\Support\Assembly;
use App\Domain\Orders\Models\OrderFulfillment;
use App\Support\BusinessTime;

/**
 * What is known about the paid assembly of one branch part, in one place for the driver's job card, the order window and the customer's order page
 * (docs/guides/DELIVERY_FOLLOW_UPS.md). "Ordered" means the customer paid for assembly on at least one line that still has units on it; the driver answers
 * once per part: `done`, or `not_done` with a reason from the short list below.
 */
final class AssemblyFacts
{
    public const DONE = 'done';

    public const NOT_DONE = 'not_done';

    /** Why assembly was not done: a short fixed list, so the branch can act on it (the labels are in lang/{en,ar}/delivery_followup.php and the driver app). `other` needs a note. */
    public const REASONS = ['customer_not_ready', 'missing_parts', 'not_possible', 'no_time', 'other'];

    /** Did the customer pay for assembly on this part (a line with units left that carries an assembly fee)? */
    public static function ordered(OrderFulfillment $f): bool
    {
        $f->loadMissing('items');

        return $f->items->contains(fn ($i) => Assembly::placedFee($i) !== null && (int) $i->quantity - (int) $i->quantity_cancelled > 0);
    }

    /**
     * The answer so far, or null for a part with no paid assembly. `status` is `pending` until the driver answers.
     *
     * @return array{ordered: true, status: string, answered_at: ?string, done_at: ?string, reason: ?string, reason_label: ?string, note: ?string, handled_at: ?string}|null
     */
    public static function state(OrderFulfillment $f): ?array
    {
        if (! self::ordered($f)) {
            return null;
        }

        return [
            'ordered' => true,
            'status' => $f->assembly_status ?? 'pending',
            'answered_at' => BusinessTime::iso($f->assembly_answered_at),
            'done_at' => BusinessTime::iso($f->assembly_done_at),
            'reason' => $f->assembly_reason,
            'reason_label' => $f->assembly_reason !== null ? __('delivery_followup.assembly_reason.'.$f->assembly_reason) : null,
            'note' => $f->assembly_note,
            'handled_at' => BusinessTime::iso($f->assembly_handled_at),
        ];
    }
}
