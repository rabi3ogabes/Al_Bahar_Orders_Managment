<?php

namespace App\Domain\Marketing\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Catalog\Models\Product;
use App\Domain\Identity\Models\User;
use App\Domain\Marketing\Models\Journey;
use App\Domain\Messaging\Services\ContactHistory;
use App\Domain\Notifications\Models\MarketingCampaign;
use App\Domain\Notifications\Models\Notification;
use App\Domain\Notifications\Services\NotificationService;
use App\Domain\Notifications\Services\TemplateRenderer;
use App\Domain\Orders\Models\Cart;
use App\Domain\Settings\Services\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Segmented, consent-aware campaigns and abandoned-cart recovery.
 * Segment keys: branch_ids, category_ids, customer_group_ids, purchased_since (date), auction_participants (bool), min_orders.
 */
class CampaignService
{
    public function __construct(private readonly NotificationService $notify, private readonly Settings $settings) {}

    /** @return Builder<User> */
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
                try {
                    $ar = $user->preferredLocale() === 'ar';
                    $this->notify->notify($user, 'campaign', ['title' => $ar ? $campaign->title_ar : $campaign->title_en, 'body' => $ar ? $campaign->body_ar : $campaign->body_en,
                        'deep_link' => $campaign->deep_link,
                        'by_locale' => ['ar' => ['title' => $campaign->title_ar, 'body' => $campaign->body_ar], 'en' => ['title' => $campaign->title_en, 'body' => $campaign->body_en]]], $campaign->channels, $campaign->id);
                    $n++;
                } catch (\Throwable $e) {
                    report($e); // one customer's problem must not leave the other 999 without the campaign and the campaign stuck on "sending"
                }
            }
        });
        $campaign->update(['status' => 'sent', 'sent_at' => now(), 'recipients_count' => $n]);

        return $n;
    }

    /**
     * Send a campaign preview to one staff member as an in-app notification. Bypasses the audience and consent
     * rules (internal recipient) and never changes the campaign's status.
     */
    public function sendTest(MarketingCampaign $campaign, User $recipient, string $locale = 'en'): Notification
    {
        $ar = $locale === 'ar';
        $msg = app(TemplateRenderer::class)->render('campaign', 'in_app', $locale, ['title' => $ar ? $campaign->title_ar : $campaign->title_en,
            'body' => $ar ? $campaign->body_ar : $campaign->body_en, 'deep_link' => $campaign->deep_link]);
        $n = Notification::create(['user_id' => $recipient->id, 'event' => 'campaign', 'category' => 'marketing', 'title' => '[TEST] '.$msg['subject'],
            'body' => $msg['body'], 'data' => array_filter(['deep_link' => $campaign->deep_link, 'campaign_test' => $campaign->id])]);
        app(AuditLogger::class)->log('marketing.campaign_test_sent', $campaign, [], ['recipient' => $recipient->id, 'locale' => $locale]);

        return $n;
    }

    /** Remind consenting customers about inactive carts, within frequency limits. Prices/stock are re-validated on return. */
    public function remindAbandonedCarts(): int
    {
        if (Journey::where('key', 'abandoned_cart')->where('is_enabled', true)->exists()) {
            return 0; // the customer journey owns abandoned-cart follow-ups now
        }
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

    /**
     * May this customer's cart be reminded right now? Same rules as the automatic reminders: the customer agreed to marketing messages, at most
     * `abandoned_cart_max_messages` reminders per cart, and nothing within `abandoned_cart_min_interval_hours` of the last message to them (a
     * reminder, a journey message or a message staff sent by hand).
     *
     * @return array{status: 'ok'|'no_customer'|'no_consent'|'limit_reached'|'too_soon', next_at: ?CarbonImmutable, limit: int}
     */
    public function reminderStatus(Cart $cart): array
    {
        $max = (int) $this->settings->get('notifications.abandoned_cart_max_messages', 2);
        $gap = (int) $this->settings->get('notifications.abandoned_cart_min_interval_hours', 24);
        $user = $cart->user_id ? User::find($cart->user_id) : null;
        $out = fn (string $status, ?CarbonImmutable $next = null) => ['status' => $status, 'next_at' => $next, 'limit' => $max];
        if (! $user || ! $user->isCustomer() || ! $user->isActive()) {
            return $out('no_customer');
        }
        if (! $user->marketing_consent_at) {
            return $out('no_consent');
        }
        if ($cart->abandoned_notifications >= $max) {
            return $out('limit_reached');
        }
        if ($cart->abandoned_notified_at && $cart->abandoned_notified_at->addHours($gap)->isFuture()) {
            return $out('too_soon', $cart->abandoned_notified_at->addHours($gap));
        }
        if ($gap > 0 && app(ContactHistory::class)->recentlyContacted([(string) $user->id], $gap) !== []) {
            return $out('too_soon');
        }

        return $out('ok');
    }

    /**
     * Staff sent the customer a reminder about this cart (push, in-app inbox and WhatsApp where they agreed). The reminder bookkeeping moves
     * (so the automatic reminders do not repeat it) but the cart itself is not touched: it stays abandoned until the customer acts.
     *
     * @return array{status: 'sent'|'no_channel'|'no_customer'|'no_consent'|'limit_reached'|'too_soon', channels: list<string>, next_at: ?CarbonImmutable, limit: int}
     */
    public function remindCart(Cart $cart): array
    {
        $state = $this->reminderStatus($cart);
        if ($state['status'] !== 'ok') {
            return $state + ['channels' => []];
        }
        $user = User::findOrFail($cart->user_id);
        $channels = $this->notify->notify($user, 'cart.abandoned', ['deep_link' => 'albahar://cart'], ['push', 'in_app', 'whatsapp']);
        if ($channels === []) {
            return ['status' => 'no_channel', 'channels' => [], 'next_at' => null, 'limit' => $state['limit']];
        }
        Cart::whereKey($cart->id)->update(['abandoned_notified_at' => now(), 'abandoned_notifications' => $cart->abandoned_notifications + 1]);

        return ['status' => 'sent', 'channels' => $channels, 'next_at' => null, 'limit' => $state['limit']];
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
