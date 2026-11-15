<?php

namespace App\Domain\Notifications\Services;

use App\Domain\Identity\Models\User;
use App\Domain\Notifications\Models\LiveActivityToken;
use App\Domain\Orders\Models\Order;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Stores the Live Activity tokens the customer's iPhone app reports (see {@see LiveActivityToken}). A token belongs to exactly one
 * customer: reporting it again refreshes the row, and an activity token can only be reported for an order the customer placed.
 */
class LiveActivityRegistry
{
    /**
     * The push token of a running Live Activity for one of the customer's own orders.
     *
     * @param  array{order_id: string, push_token: string, environment?: ?string, device_id?: ?string, locale?: ?string, app_version?: ?string}  $data
     *
     * @throws ModelNotFoundException when the order is not the customer's
     */
    public function registerActivity(User $user, array $data): LiveActivityToken
    {
        $order = Order::where('user_id', $user->id)->findOrFail($data['order_id']);
        $token = LiveActivityToken::updateOrCreate(['token' => $this->normalise($data['push_token'])], [
            'user_id' => $user->id,
            'order_id' => $order->id,
            'kind' => LiveActivityToken::KIND_ACTIVITY,
        ] + $this->common($data));
        // Apple rotates an activity's token now and then and reports the new one: the old token of the same activity on the same phone stops.
        if ($token->device_id !== null) {
            LiveActivityToken::where('user_id', $user->id)->where('order_id', $order->id)->where('kind', LiveActivityToken::KIND_ACTIVITY)
                ->where('device_id', $token->device_id)->whereKeyNot($token->id)->whereNull('disabled_at')->update(['disabled_at' => now()]);
        }

        return $token;
    }

    /**
     * The phone's push-to-start token (iOS 17.2+): the newest one of a phone is the only one that stays on.
     *
     * @param  array{push_token: string, environment?: ?string, device_id?: ?string, locale?: ?string, app_version?: ?string}  $data
     */
    public function registerStart(User $user, array $data): LiveActivityToken
    {
        $token = LiveActivityToken::updateOrCreate(['token' => $this->normalise($data['push_token'])], [
            'user_id' => $user->id,
            'order_id' => null,
            'kind' => LiveActivityToken::KIND_START,
        ] + $this->common($data));
        if ($token->device_id !== null) {
            LiveActivityToken::where('user_id', $user->id)->where('kind', LiveActivityToken::KIND_START)
                ->where('device_id', $token->device_id)->whereKeyNot($token->id)->whereNull('disabled_at')->update(['disabled_at' => now()]);
        }

        return $token;
    }

    /**
     * Switches off the caller's own tokens that match: one token, every activity of one order, or everything one phone reported (sign-out).
     * A token that belongs to somebody else is never touched.
     *
     * @param  array{push_token?: ?string, order_id?: ?string, device_id?: ?string}  $filter  at least one key
     */
    public function disable(User $user, array $filter): int
    {
        $query = LiveActivityToken::where('user_id', $user->id)->whereNull('disabled_at');
        $any = false;
        if (! empty($filter['push_token'])) {
            $query->where('token', $this->normalise($filter['push_token']));
            $any = true;
        }
        if (! empty($filter['order_id'])) {
            $query->where('order_id', $filter['order_id']);
            $any = true;
        }
        if (! empty($filter['device_id'])) {
            $query->where('device_id', $filter['device_id']);
            $any = true;
        }

        return $any ? $query->update(['disabled_at' => now()]) : 0;
    }

    /** @param  array<string, mixed>  $data */
    private function common(array $data): array
    {
        return [
            'environment' => $data['environment'] ?? 'production',
            'device_id' => $data['device_id'] ?? null,
            'locale' => $data['locale'] ?? app()->getLocale(),
            'app_version' => $data['app_version'] ?? null,
            'last_seen_at' => now(),
            'disabled_at' => null,
        ];
    }

    /** Apple's tokens are hexadecimal: one spelling per token, so the same token is never stored twice. */
    private function normalise(string $token): string
    {
        return strtolower(trim($token));
    }
}
