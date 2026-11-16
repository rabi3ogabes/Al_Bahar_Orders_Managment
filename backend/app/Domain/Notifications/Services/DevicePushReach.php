<?php

namespace App\Domain\Notifications\Services;

use App\Domain\Identity\Models\UserDevice;

/**
 * "Would a push to this customer actually be seen?" Google accepting a push only means it reached the phone: Android 13+ hides it until the
 * person allows notifications, and the phone says which it is (`user_devices.notification_permission`, see DEVICE_NOTIFICATION_STATE.md).
 *
 * For each person, over the phones that are switched on (a signed-out phone is not counted):
 *  - devices:   how many phones are registered;
 *  - can_show:  of those, phones that said notifications are on (granted);
 *  - off:       phones where the person said no (denied);
 *  - not_asked: phones where the question was never put to the person;
 *  - unknown:   phones that never reported (an older app version, a browser). Not treated as off: the push may well show there.
 * The numbers add up: devices = can_show + off + not_asked + unknown.
 *
 * A page of customers is answered with ONE grouped query ({@see forUsers()}), never one per customer.
 *
 * @phpstan-type Reach array{devices: int, can_show: int, off: int, not_asked: int, unknown: int}
 */
class DevicePushReach
{
    /** The five things the dashboard says about a customer's phones (worded in the messaging_phones language files). */
    public const STATES = ['none', 'on', 'unknown', 'off', 'not_asked'];

    /** Customers per query: keeps the id list short for a bulk send of a thousand. */
    private const CHUNK = 500;

    /** @return Reach */
    public static function empty(): array
    {
        return ['devices' => 0, 'can_show' => 0, 'off' => 0, 'not_asked' => 0, 'unknown' => 0];
    }

    /** @return Reach */
    public function forUser(string $userId): array
    {
        return $this->forUsers([$userId])[$userId];
    }

    /**
     * @param  iterable<string|int>  $userIds
     * @return array<string, Reach> every id asked for is in the answer (zeros for someone without a phone)
     */
    public function forUsers(iterable $userIds): array
    {
        $ids = [];
        foreach ($userIds as $id) {
            $ids[(string) $id] = true;
        }
        $out = array_fill_keys(array_keys($ids), self::empty());
        foreach (array_chunk(array_keys($ids), self::CHUNK) as $chunk) {
            $rows = UserDevice::query()->whereIn('user_id', $chunk)->whereNull('disabled_at')->groupBy('user_id')->toBase()
                ->selectRaw("user_id, count(*) as devices,
                    count(*) filter (where notification_permission = 'granted') as can_show,
                    count(*) filter (where notification_permission = 'denied') as off,
                    count(*) filter (where notification_permission = 'not_asked') as not_asked,
                    count(*) filter (where notification_permission is null) as unknown")
                ->get();
            foreach ($rows as $row) {
                $out[(string) $row->user_id] = [
                    'devices' => (int) $row->devices, 'can_show' => (int) $row->can_show, 'off' => (int) $row->off,
                    'not_asked' => (int) $row->not_asked, 'unknown' => (int) $row->unknown,
                ];
            }
        }

        return $out;
    }

    /**
     * The one thing to tell about a person's phones. A phone that can show a push wins (the message will be seen somewhere), then a phone
     * that never reported (it may well show it), and only when every phone said otherwise is it "off" (the person said no) or "not asked".
     *
     * @param  Reach  $reach
     * @return 'none'|'on'|'unknown'|'off'|'not_asked'
     */
    public static function state(array $reach): string
    {
        return match (true) {
            $reach['devices'] === 0 => 'none',
            $reach['can_show'] > 0 => 'on',
            $reach['unknown'] > 0 => 'unknown',
            $reach['off'] > 0 => 'off',
            default => 'not_asked',
        };
    }

    /** True when staff should be warned before relying on the push: no phone at all, or every phone is known to hide it. @param Reach $reach */
    public static function pushWillNotShow(array $reach): bool
    {
        return in_array(self::state($reach), ['none', 'off', 'not_asked'], true);
    }

    /**
     * What a finished delivery recorded (`customer_messages.meta`): how many phones it reached and on how many of them notifications are off.
     * Null when there is nothing to add: the normal case, a row that is not a notification, or a send where no push was tried at all (the
     * customer takes no push, or push is not set up: `push_tried` false) so "no phone" would be a guess. Older rows have no `push_tried`:
     * a push is assumed to have been tried, as it was whenever push was set up.
     *
     * @param  array<string, mixed>|null  $meta
     * @return array{kind: 'no_phone'|'silent', reached: int, silent: int}|null
     */
    public static function deliveryNote(?array $meta): ?array
    {
        if ($meta === null || ! array_key_exists('devices', $meta) || ($meta['push_tried'] ?? true) === false) {
            return null;
        }
        if ((int) $meta['devices'] === 0) {
            return ['kind' => 'no_phone', 'reached' => 0, 'silent' => 0];
        }
        $silent = (int) ($meta['silent'] ?? 0);

        return $silent > 0 ? ['kind' => 'silent', 'reached' => (int) ($meta['pushed'] ?? 0), 'silent' => $silent] : null;
    }
}
