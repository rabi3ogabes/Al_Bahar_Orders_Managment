<?php

namespace App\Domain\Messaging\Services;

use App\Domain\Identity\Models\User;
use App\Domain\Notifications\Models\NotificationPreference;
use App\Domain\Orders\Models\Cart;
use Illuminate\Support\Collection;

/**
 * Decides, for each customer and channel, whether a marketing message may go out right now, and if not why.
 * The consent rules are the same as NotificationService::allowed (a test keeps them identical); the checks are loaded for the whole audience
 * at once, so a bulk send of 1,000 customers costs a handful of queries, never one per customer.
 *
 * Reasons (stored on the skipped message and shown in the history): inactive_user, no_marketing_consent, opted_out, no_whatsapp_optin, no_phone,
 * recently_contacted, no_open_cart.
 */
class Eligibility
{
    public const REASONS = ['inactive_user', 'no_marketing_consent', 'opted_out', 'no_whatsapp_optin', 'no_phone', 'recently_contacted', 'no_open_cart'];

    public function __construct(private readonly ContactHistory $history) {}

    /**
     * @param  Collection<int, User>  $users
     * @param  list<string>  $channels  notification | whatsapp
     * @return array<string, array<string, ?string>> user id => channel => null (may be sent) or the reason it may not
     */
    public function evaluate(Collection $users, array $channels, string $kind, int $cooldownHours, ?string $exceptBatchId = null, bool $ignoreCooldown = false): array
    {
        $ids = $users->pluck('id')->map(fn ($id) => (string) $id)->all();
        if ($ids === []) {
            return [];
        }
        $prefs = $this->preferences($ids);
        $recent = $ignoreCooldown ? [] : $this->history->recentlyContacted($ids, $cooldownHours, $exceptBatchId);
        $carts = $kind === 'reminder' ? $this->usersWithOpenCart($ids) : [];

        $out = [];
        foreach ($users as $user) {
            $id = (string) $user->id;
            $common = match (true) {
                ! $user->isActive() => 'inactive_user',
                ! $user->marketing_consent_at => 'no_marketing_consent',
                isset($recent[$id]) => 'recently_contacted',
                $kind === 'reminder' && ! isset($carts[$id]) => 'no_open_cart',
                default => null,
            };
            foreach ($channels as $channel) {
                $out[$id][$channel] = $common ?? match ($channel) {
                    'notification' => $this->allowed($prefs, $id, 'in_app') || $this->allowed($prefs, $id, 'push') ? null : 'opted_out',
                    'whatsapp' => match (true) {
                        ! $this->allowed($prefs, $id, 'whatsapp') => 'no_whatsapp_optin',
                        ! $user->phone => 'no_phone',
                        default => null,
                    },
                    default => 'opted_out',
                };
            }
        }

        return $out;
    }

    /** Whether the customer takes marketing on that delivery channel (in_app | push | whatsapp); consent itself is checked by the caller. @param array<string, array<string, bool>> $prefs */
    public function allowed(array $prefs, string $userId, string $channel): bool
    {
        $pref = $prefs[$userId][$channel] ?? null;

        return $pref ?? in_array($channel, ['push', 'in_app'], true);
    }

    /** @param list<string> $ids @return array<string, array<string, bool>> user id => channel => explicit marketing preference */
    public function preferences(array $ids): array
    {
        $out = [];
        NotificationPreference::query()->whereIn('user_id', $ids)->where('category', 'marketing')->whereIn('channel', ['in_app', 'push', 'whatsapp'])
            ->get(['user_id', 'channel', 'is_enabled'])->each(function ($p) use (&$out) {
                $out[(string) $p->user_id][$p->channel] = (bool) $p->is_enabled;
            });

        return $out;
    }

    /** @param list<string> $ids @return array<string, true> */
    private function usersWithOpenCart(array $ids): array
    {
        return array_fill_keys(Cart::query()->whereIn('user_id', $ids)->where('status', 'active')->whereHas('items')->distinct()->pluck('user_id')->map(fn ($v) => (string) $v)->all(), true);
    }
}
