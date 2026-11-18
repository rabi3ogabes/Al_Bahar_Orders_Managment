<?php

namespace App\Domain\Marketing\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Catalog\Models\Product;
use App\Domain\Identity\Models\User;
use App\Domain\Messaging\Services\ContactHistory;
use App\Domain\Notifications\Models\MarketingCampaign;
use App\Domain\Notifications\Models\Notification;
use App\Domain\Notifications\Services\NotificationRules;
use App\Domain\Notifications\Services\NotificationService;
use App\Domain\Notifications\Services\TemplateRenderer;
use App\Domain\Orders\Models\Cart;
use App\Domain\Settings\Services\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Segmented, consent-aware campaigns, and the staff "Send reminder" for one abandoned cart (the automatic reminders are the abandoned-cart journey, see AbandonedCartReminders).
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

    /** A scheduled campaign that comes due more than this long before it can go out (campaign messages were off, the scheduler was down) is not sent. */
    public const SCHEDULE_GRACE_HOURS = 24;

    /**
     * Sends a draft or scheduled campaign to its audience. Returns how many customers it reached on at least one channel. It is marked *sent*
     * only when that is at least one: if every channel is switched off, nobody in the audience can be reached, or the audience is empty, the
     * campaign keeps its status (and 0 is returned) so nobody is told "sent" about a message that did not go out.
     */
    public function send(MarketingCampaign $campaign): int
    {
        $before = $campaign->status;
        if (! in_array($before, ['draft', 'scheduled'], true)) {
            return 0;
        }
        // Campaign messages switched off in the notification controls: the campaign stays as it is (a scheduled one waits) and nobody is told "sent".
        if (! app(NotificationRules::class)->enabled('campaign')) {
            return 0;
        }
        $campaign->update(['status' => 'sending']);
        $n = 0;
        $this->audience($campaign->segment ?? [])->chunkById(500, function ($users) use ($campaign, &$n) {
            foreach ($users as $user) {
                try {
                    $ar = $user->preferredLocale() === 'ar';
                    $channels = $this->notify->notify($user, 'campaign', ['title' => $ar ? $campaign->title_ar : $campaign->title_en, 'body' => $ar ? $campaign->body_ar : $campaign->body_en,
                        'deep_link' => $campaign->deep_link,
                        'by_locale' => ['ar' => ['title' => $campaign->title_ar, 'body' => $campaign->body_ar], 'en' => ['title' => $campaign->title_en, 'body' => $campaign->body_en]]], $campaign->channels, $campaign->id);
                    if ($channels !== []) {
                        $n++; // reached on at least one channel (a customer who opted out of them all, or a channel switched off, does not count)
                    }
                } catch (\Throwable $e) {
                    report($e); // one customer's problem must not leave the other 999 without the campaign and the campaign stuck on "sending"
                }
            }
        });
        if ($n === 0) {
            $campaign->update(['status' => $before]);

            return 0;
        }
        $campaign->update(['status' => 'sent', 'sent_at' => now(), 'recipients_count' => $n]);

        return $n;
    }

    /**
     * The scheduler's job (every minute): sends the scheduled campaigns whose time has come. One that came due more than
     * SCHEDULE_GRACE_HOURS ago is NOT sent (an offer that is days old; typically campaign messages were switched off meanwhile): it ends as
     * *cancelled* with the note `missed_when_due` and an audit record, and the team can create it again.
     *
     * @return array{sent: int, missed: int}
     */
    public function sendDue(): array
    {
        $sent = 0;
        $missed = 0;
        $cutoff = now()->subHours(self::SCHEDULE_GRACE_HOURS);
        MarketingCampaign::where('status', 'scheduled')->where('scheduled_at', '<=', now())->orderBy('scheduled_at')->get()
            ->each(function (MarketingCampaign $c) use (&$sent, &$missed, $cutoff) {
                if ($c->scheduled_at !== null && $c->scheduled_at->lt($cutoff)) {
                    $c->update(['status' => 'cancelled', 'status_note' => 'missed_when_due']);
                    app(AuditLogger::class)->log('marketing.campaign_missed', $c, ['status' => 'scheduled'], ['status' => 'cancelled', 'status_note' => 'missed_when_due'],
                        ['scheduled_at' => $c->scheduled_at->toIso8601String(), 'grace_hours' => self::SCHEDULE_GRACE_HOURS]);
                    $missed++;

                    return;
                }
                if ($this->send($c) > 0) {
                    $sent++;
                }
            });

        return ['sent' => $sent, 'missed' => $missed];
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

    /**
     * May this customer's cart be reminded right now by staff? The customer agreed to marketing messages, at most the "maximum reminders per cart" of the
     * automatic reminders (their counters are the same ones: a reminder sent by staff uses one up, and so does an automatic one), and nothing within
     * `abandoned_cart_min_interval_hours` of the last message to them (a reminder, a journey message or a message staff sent by hand).
     *
     * @return array{status: string, next_at: ?CarbonImmutable, limit: int} status: ok | no_customer | no_consent | limit_reached | too_soon
     */
    public function reminderStatus(Cart $cart): array
    {
        $max = app(AbandonedCartReminders::class)->maxPerCart();
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
     * @return array{status: string, channels: list<string>, next_at: ?CarbonImmutable, limit: int} status: sent | no_channel, or one of reminderStatus()
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
        Cart::whereKey($cart->id)->update(['abandoned_notified_at' => now(), 'abandoned_notifications' => DB::raw('abandoned_notifications + 1')]); // atomic: the automatic reminders count on the same cart

        return ['status' => 'sent', 'channels' => $channels, 'next_at' => null, 'limit' => $state['limit']];
    }
}
