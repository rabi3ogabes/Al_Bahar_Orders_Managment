<?php

namespace App\Domain\Reports\Services;

/**
 * Who a failed delivery is the fault of, so the scorecard never counts a customer who was not home against the team that came on time.
 *
 *  - customer side: the person receiving it was not reachable or not home, gave an address that could not be found, refused the goods, or the
 *    building would not let the driver in. None of these is something the branch or the driver could have done differently on the day;
 *  - team side: the goods were damaged on the way (packing, loading, driving);
 *  - other: "Other" is what a driver picks when nothing fits, so it is shown on its own and counted on neither side (a reason that is not known is
 *    never blamed on anyone).
 *
 * "Wrong address" sits on the customer side because the address is what the customer gave; when a staff member typed it in for the customer the branch
 * is the one who could have caught it, which the data cannot tell apart, so the guide says so.
 */
final class ScorecardCauses
{
    public const CUSTOMER = ['customer_unreachable', 'customer_absent', 'wrong_address', 'refused', 'access_denied'];

    public const TEAM = ['damaged'];

    /** Every reason a driver or a manager can record on a failed delivery (see DeliveryIndex::FAIL_REASONS), in the order the page lists them. */
    public const FAIL_REASONS = ['customer_absent', 'customer_unreachable', 'wrong_address', 'refused', 'access_denied', 'damaged', 'other'];

    /** Why an order or a part can be cancelled, from the customer's list and from the manager's list. */
    public const CANCEL_REASONS = ['changed_mind', 'ordered_by_mistake', 'found_better_price', 'delivery_too_long', 'customer_request', 'out_of_stock', 'payment_issue', 'fraud_suspected', 'duplicate_order', 'damaged', 'other'];

    /** Why a driver gave a job back (the two that the system writes, `timeout` and `off_duty`, are kept apart). */
    public const DECLINE_REASONS = ['too_far', 'vehicle', 'shift_ending', 'other'];

    /** @return 'customer'|'team'|'other' */
    public static function sideOf(?string $reason): string
    {
        return match (true) {
            in_array($reason, self::CUSTOMER, true) => 'customer',
            in_array($reason, self::TEAM, true) => 'team',
            default => 'other',
        };
    }

    /** A recorded failure reason as one of the known codes (a free-typed one reads as "other"). */
    public static function failReason(?string $reason): string
    {
        return in_array($reason, self::FAIL_REASONS, true) ? (string) $reason : 'other';
    }

    public static function cancelReason(?string $reason): string
    {
        return in_array($reason, self::CANCEL_REASONS, true) ? (string) $reason : 'other';
    }

    /**
     * Failures by reason, counted and split by side.
     *
     * @param  array<string, int>  $byReason  reason code => failures (any code; unknown ones are folded into "other")
     * @return array{reasons: array<string, int>, customer: int, team: int, other: int}
     */
    public static function split(array $byReason): array
    {
        $reasons = array_fill_keys(self::FAIL_REASONS, 0);
        $sides = ['customer' => 0, 'team' => 0, 'other' => 0];
        foreach ($byReason as $reason => $n) {
            $code = self::failReason((string) $reason);
            $reasons[$code] += $n;
            $sides[self::sideOf($code)] += $n;
        }

        return ['reasons' => array_filter($reasons), ...$sides];
    }
}
