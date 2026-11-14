<?php

namespace App\Support;

use App\Domain\Identity\Models\User;
use App\Domain\Orders\Models\Order;

/**
 * The customer's second number, which the delivery driver may call when the main number is not answered.
 * One rule for the profile, checkout and the staff screens: a Qatar mobile number (Arabic digits accepted) that is not the account's main number.
 * Personal data: never logged, never in a customer-facing payload of another user (see docs/qa/CHECKOUT_ALT_PHONE_SLOT_SPLIT.md).
 */
final class AlternatePhone
{
    public const INVALID = 'alternate_phone_invalid';

    public const SAME_AS_MAIN = 'alternate_phone_same';

    /**
     * @return array{0: ?string, 1: ?string} [the number in E.164, or null; the error code, or null]. Empty input is "no number", not an error.
     */
    public static function check(?string $input, ?string $mainPhone): array
    {
        $input = trim((string) $input);
        if ($input === '') {
            return [null, null];
        }
        $phone = Phone::normalize($input);
        if ($phone === null) {
            return [null, self::INVALID];
        }
        if (Phone::same($phone, $mainPhone)) {
            return [null, self::SAME_AS_MAIN];
        }

        return [$phone, null];
    }

    /**
     * Forget the second number everywhere it is kept: the profile and every order snapshot of this person.
     * Call it from account deletion / anonymisation (the customer's own deletion request does) so no copy outlives the account.
     */
    public static function erase(User $user): void
    {
        Order::where('user_id', $user->id)->whereNotNull('alternate_phone')->update(['alternate_phone' => null]);
        if ($user->alternate_phone !== null) {
            $user->forceFill(['alternate_phone' => null])->save();
        }
    }
}
