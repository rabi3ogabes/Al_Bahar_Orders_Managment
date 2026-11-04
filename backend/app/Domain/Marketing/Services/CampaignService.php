<?php

namespace App\Domain\Marketing\Services;

use App\Domain\Catalog\Models\Product;
use App\Domain\Identity\Models\User;
use App\Domain\Notifications\Models\MarketingCampaign;
use App\Domain\Notifications\Services\NotificationService;
use App\Domain\Orders\Models\Cart;
use App\Domain\Settings\Services\Settings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Segmented, consent-aware campaigns and abandoned-cart recovery.
 * Segment keys: branch_ids, category_ids, customer_group_ids, purchased_since (date), auction_participants (bool), min_orders.
 */
class CampaignService
{
    public function __construct(private readonly NotificationService $notify, private readonly Settings $settings) {}

    public function audience(array $segment): Builder
    {
        return User::query()->where('type', 'customer')->where('status', 'active')->whereNotNull('marketing_consent_at')
            ->when($segment['branch_ids'] ?? null, fn ($q, $b) => $q->whereHas('orders.fulfillments', fn ($f) => $f->whereIn('branch_id', $b)))
            ->when($segment['category_ids'] ?? null, fn ($q, $c) => $q->whereHas('orders.items', fn ($i) => $i->whereIn('product_id', Product::whereIn('category_id', $c)->select('id'))))
            ->when($segment['customer_group_ids'] ?? null, fn ($q, $g) => $q->whereIn('id', DB::table('customer_group_members')->whereIn('customer_group_id', $g)->select('user_id')))
            ->when($segment['purchased_since'] ?? null, fn ($q, $d) => $q->whereHas('orders', fn ($o) => $o->where('placed_at', '>=', $d)->where('status', '!=', 'cancelled')))
            ->when($segment['auction_participants'] ?? false, fn ($q) => $q->whereIn('id', DB::table('auction_bids')->where('status', 'accepted')->select('user_id')))
            ->when($segment['min_orders'] ?? null, fn ($q, $n) => $q->has('orders', '>=', (int) $n));
    }

    public function send(MarketingCampaign $campaign): int
    {
        if (! in_array($campaign->status, ['draft', 'scheduled'], true)) {
            return 0;
        }
        $campaign->update(['status' => 'sending']);
        $n = 0;
        $this->audience($campaign->segment ?? [])->chunkById(500, function ($users) use ($campaign, &$n) {
            foreach ($users as $user) {
                $ar = $user->preferredLocale() === 'ar';
                $this->notify->notify($user, 'campaign', ['title' => $ar ? $campaign->title_ar : $campaign->title_en, 'body' => $ar ? $campaign->body_ar : $campaign->body_en,
                    'deep_link' => $campaign->deep_link], $campaign->channels, $campaign->id);
                $n++;
            }
        });
        $campaign->update(['status' => 'sent', 'sent_at' => now(), 'recipients_count' => $n]);

        return $n;
    }

    /** Remind consenting customers about inactive carts, within frequency limits. Prices/stock are re-validated on return. */
    public function remindAbandonedCarts(): int
    {
        $after = (int) $this->settings->get('notifications.abandoned_cart_after_minutes', 120);
        $max = (int) $this->settings->get('notifications.abandoned_cart_max_messages', 2);
        $gapHours = (int) $this->settings->get('notifications.abandoned_cart_min_interval_hours', 24);
        $n = 0;
        Cart::with('user')->where('status', 'active')->whereNotNull('user_id')->has('items')
            ->where('last_activity_at', '<', now()->subMinutes($after))->where('abandoned_notifications', '<', $max)
            ->where(fn ($q) => $q->whereNull('abandoned_notified_at')->orWhere('abandoned_notified_at', '<', now()->subHours($gapHours)))
            ->limit(500)->get()->each(function (Cart $cart) use (&$n) {
                if ($cart->user && $this->notify->notify($cart->user, 'cart.abandoned', ['deep_link' => 'albahar://cart'])) {
                    // Do not use touch(): that resets the counters. Only the reminder bookkeeping changes.
                    Cart::whereKey($cart->id)->update(['abandoned_notified_at' => now(), 'abandoned_notifications' => $cart->abandoned_notifications + 1]);
                    $n++;
                }
            });

        return $n;
    }

    /** Conversion: share of reminded carts later converted to orders. */
    public function abandonedCartConversion(\DateTimeInterface $since): array
    {
        $reminded = Cart::where('abandoned_notifications', '>', 0)->where('abandoned_notified_at', '>=', $since);
        $total = (clone $reminded)->count();
        $converted = (clone $reminded)->where('status', 'converted')->count();

        return ['reminded' => $total, 'converted' => $converted, 'rate' => $total ? round($converted * 100 / $total, 1) : 0];
    }
}
