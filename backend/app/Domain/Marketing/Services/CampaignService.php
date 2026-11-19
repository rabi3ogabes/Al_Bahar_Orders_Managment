<?php

namespace App\Domain\Marketing\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Catalog\Models\Product;
use App\Domain\Identity\Models\User;
use App\Domain\Marketing\Jobs\DeliverCampaignJob;
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

    /** A campaign that has been "sending" without any progress for this long lost its worker (a deploy killed it): the scheduler resumes it. */
    public const STALE_MINUTES = 30;

    /** One delivery job works this long, then queues the rest as a new job (a worker is never held for an hour). */
    public const TIME_BUDGET_SECONDS = 120;

    private const CHUNK = 200;

    /** Customers handled between two progress updates (a property so a test can make it small). */
    protected int $chunk = self::CHUNK;

    public const STARTED = 'started';

    public const NOT_SENDABLE = 'not_sendable';

    public const SWITCHED_OFF = 'switched_off';

    /**
     * Claims a draft or scheduled campaign for sending: ONE atomic UPDATE ... WHERE status IN ('draft','scheduled'), so when two people press
     * *Send now*, or *Send now* meets the scheduler, exactly one of them gets it (the other's UPDATE finds the status already changed and touches
     * nothing). It never reads the status first. The campaign is only claimed while campaign messages are switched on.
     *
     * @return string STARTED | NOT_SENDABLE (already sending, sent, cancelled) | SWITCHED_OFF
     */
    public function claim(MarketingCampaign $campaign, ?User $by = null): string
    {
        if (! app(NotificationRules::class)->enabled('campaign')) {
            return self::SWITCHED_OFF;
        }
        $claimed = DB::table('marketing_campaigns')->where('id', $campaign->id)->whereIn('status', ['draft', 'scheduled'])->update([
            'status' => 'sending', 'status_note' => 'queued', 'started_by' => $by?->id, 'audience_total' => null, 'processed_count' => 0, 'failed_count' => 0,
            'recipients_count' => 0, 'updated_at' => now(),
        ]);

        return $claimed === 1 ? self::STARTED : self::NOT_SENDABLE;
    }

    /**
     * Starts a send and returns at once: claims the campaign and queues its delivery. The page shows the progress; the people it reaches are counted as
     * the job goes. Use {@see self::send()} to deliver in the current process instead.
     *
     * @return string STARTED | NOT_SENDABLE | SWITCHED_OFF
     */
    public function start(MarketingCampaign $campaign, ?User $by = null): string
    {
        $outcome = $this->claim($campaign, $by);
        if ($outcome === self::STARTED) {
            DeliverCampaignJob::dispatch($campaign->id);
        }

        return $outcome;
    }

    /**
     * Sends a draft or scheduled campaign to its audience right here, to the end, and returns how many customers it reached on at least one channel.
     * It is marked *sent* only when that is at least one: if every channel is switched off, nobody in the audience can be reached, or the audience is
     * empty, the campaign keeps its status (and 0 is returned) so nobody is told "sent" about a message that did not go out.
     * The web page and the scheduler use {@see self::start()}, which does the same work in the background.
     */
    public function send(MarketingCampaign $campaign, ?User $by = null): int
    {
        if ($this->claim($campaign, $by) !== self::STARTED) {
            return 0;
        }
        $this->deliver($campaign, PHP_INT_MAX);
        $fresh = MarketingCampaign::find($campaign->id);

        return $fresh !== null && in_array($fresh->status, ['sent', 'partly_sent'], true) ? $fresh->recipients_count : 0;
    }

    /**
     * The delivery itself, resumable and safe to run twice at once. For every customer of the audience the campaign first writes one row in
     * `marketing_campaign_recipients` (unique per campaign and customer) and only the run that wrote it sends the message; both happen in one
     * transaction, so a crash leaves either both or neither. A second worker, a retried job or a resumed campaign therefore skips everyone already
     * handled instead of messaging them again. Progress is written after every chunk of customers.
     *
     * @param  int  $budgetSeconds  stop after this long (checked between chunks) and report that more is left
     * @return bool true when the campaign has been finished (sent, partly sent or put back), false when customers are left for another job
     */
    public function deliver(MarketingCampaign $campaign, int $budgetSeconds = self::TIME_BUDGET_SECONDS): bool
    {
        $c = MarketingCampaign::find($campaign->id);
        if ($c === null || $c->status !== 'sending') {
            return true; // finished, cancelled or never claimed: nothing to do
        }
        $segment = $c->segment ?? [];
        if ($c->audience_total === null) {
            DB::table('marketing_campaigns')->where('id', $c->id)->update(['audience_total' => $this->audience($segment)->count(), 'status_note' => 'delivering', 'updated_at' => now()]);
        }
        $deadline = microtime(true) + $budgetSeconds;
        $stoppedBySwitch = false;
        $this->audience($segment)->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('marketing_campaign_recipients as r')
            ->whereColumn('r.user_id', 'users.id')->where('r.campaign_id', $c->id))
            ->chunkById($this->chunk, function ($users) use ($c, $deadline, &$stoppedBySwitch) {
                if (! app(NotificationRules::class)->enabled('campaign')) {
                    $stoppedBySwitch = true; // switched off while sending: stop where we are, nobody else is messaged

                    return false;
                }
                foreach ($users as $user) {
                    $this->deliverTo($c, $user);
                }
                $this->recordProgress($c->id);

                return microtime(true) < $deadline;
            });
        if ($stoppedBySwitch) {
            return $this->finish($c->id, 'switched_off');
        }
        $more = $this->audience($segment)->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('marketing_campaign_recipients as r')
            ->whereColumn('r.user_id', 'users.id')->where('r.campaign_id', $c->id))->exists();

        return $more ? false : $this->finish($c->id);
    }

    /** One customer, once: record the claim, send, record the result: all or nothing. A failure is recorded as such and never stops the others. */
    private function deliverTo(MarketingCampaign $campaign, User $user): void
    {
        try {
            DB::transaction(function () use ($campaign, $user) {
                $mine = DB::table('marketing_campaign_recipients')->insertOrIgnore(['campaign_id' => $campaign->id, 'user_id' => $user->id, 'state' => 'skipped', 'created_at' => now()]);
                if ($mine === 0) {
                    return; // an earlier run (or a parallel one) already handled this customer
                }
                $ar = $user->preferredLocale() === 'ar';
                $channels = $this->notify->notify($user, 'campaign', ['title' => $ar ? $campaign->title_ar : $campaign->title_en, 'body' => $ar ? $campaign->body_ar : $campaign->body_en,
                    'deep_link' => $campaign->deep_link,
                    'by_locale' => ['ar' => ['title' => $campaign->title_ar, 'body' => $campaign->body_ar], 'en' => ['title' => $campaign->title_en, 'body' => $campaign->body_en]]], $campaign->channels, $campaign->id);
                // reached on at least one channel (a customer who opted out of them all, or a channel switched off, is "skipped")
                DB::table('marketing_campaign_recipients')->where('campaign_id', $campaign->id)->where('user_id', $user->id)
                    ->update(['state' => $channels === [] ? 'skipped' : 'sent', 'channels' => json_encode($channels)]);
            });
        } catch (\Throwable $e) {
            report($e); // one customer's problem must not leave the other 999 without the campaign, nor the campaign stuck on "sending"
            // Also when the failure came after the commit (queueing the push or SMS failed): the customer was not fully reached, so it is recorded as failed.
            DB::table('marketing_campaign_recipients')->upsert([['campaign_id' => $campaign->id, 'user_id' => $user->id, 'state' => 'failed', 'error' => class_basename($e), 'created_at' => now()]],
                ['campaign_id', 'user_id'], ['state', 'error']);
        }
    }

    /** Writes the counters the page shows and refreshes `updated_at`, which is also how a stalled send is told from a working one. */
    private function recordProgress(string $campaignId): void
    {
        $r = $this->tally($campaignId);
        DB::table('marketing_campaigns')->where('id', $campaignId)->where('status', 'sending')
            ->update(['recipients_count' => $r['reached'], 'processed_count' => $r['processed'], 'failed_count' => $r['failed'], 'status_note' => 'delivering', 'updated_at' => now()]);
    }

    /** @return array{reached: int, processed: int, failed: int} */
    private function tally(string $campaignId): array
    {
        $r = DB::table('marketing_campaign_recipients')->where('campaign_id', $campaignId)
            ->selectRaw("count(*) as processed, count(*) filter (where state = 'sent') as reached, count(*) filter (where state = 'failed') as failed")->first();

        return ['reached' => (int) ($r->reached ?? 0), 'processed' => (int) ($r->processed ?? 0), 'failed' => (int) ($r->failed ?? 0)];
    }

    /**
     * Ends the delivery. Somebody reached: *sent*, or *partly sent* when some customers' messages errored (or campaign messages were switched off
     * part-way), with the counts, a `sent_at` and ONE audit record (the compare-and-set below lets exactly one finisher write it, whichever path
     * ran the campaign). Nobody reached: the campaign goes back to what it was, with a note saying why, so nobody is told "sent" about nothing.
     */
    private function finish(string $campaignId, ?string $stopped = null): bool
    {
        $c = MarketingCampaign::find($campaignId);
        if ($c === null || $c->status !== 'sending') {
            return true;
        }
        $t = $this->tally($campaignId);
        if ($t['reached'] === 0) {
            DB::table('marketing_campaign_recipients')->where('campaign_id', $campaignId)->delete(); // nobody was reached: a later try must be free to try everyone
            DB::table('marketing_campaigns')->where('id', $campaignId)->where('status', 'sending')->update([
                'status' => $c->scheduled_at !== null ? 'scheduled' : 'draft', 'status_note' => $t['failed'] > 0 ? 'all_failed' : 'nothing_reached',
                'audience_total' => null, 'processed_count' => 0, 'failed_count' => 0, 'recipients_count' => 0, 'updated_at' => now(),
            ]);

            return true;
        }
        $partly = $t['failed'] > 0 || $stopped !== null;
        $done = DB::table('marketing_campaigns')->where('id', $campaignId)->where('status', 'sending')->update([
            'status' => $partly ? 'partly_sent' : 'sent', 'status_note' => $stopped ?? ($t['failed'] > 0 ? 'some_not_sent' : null), 'sent_at' => now(),
            'recipients_count' => $t['reached'], 'processed_count' => $t['processed'], 'failed_count' => $t['failed'], 'updated_at' => now(),
        ]);
        if ($done === 1) {
            $starter = $c->started_by ? User::find($c->started_by) : null;
            app(AuditLogger::class)->log('marketing.campaign_sent', $c, ['status' => 'sending'], ['status' => $partly ? 'partly_sent' : 'sent', 'recipients' => $t['reached']],
                ['via' => $starter ? 'dashboard' : 'scheduler', 'processed' => $t['processed'], 'failed' => $t['failed'], 'audience' => $c->audience_total, 'stopped' => $stopped], actor: $starter);
        }

        return true;
    }

    /**
     * The scheduler's job (every minute): starts the scheduled campaigns whose time has come, and picks up a campaign whose sending stopped without
     * finishing. One that came due more than SCHEDULE_GRACE_HOURS ago is NOT sent (an offer that is days old; typically campaign messages were
     * switched off meanwhile): it ends as *cancelled* with the note `missed_when_due` and an audit record, and the team can create it again.
     *
     * "sent" counts the campaigns this tick started or resumed (their delivery runs in the background, or right away on a server without a queue).
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
                if ($this->start($c) === self::STARTED) {
                    $sent++;
                }
            });

        // A campaign whose delivery stopped (the worker was killed, the job was lost): take it over once, atomically, and carry on from where it stopped.
        $stale = now()->subMinutes(self::STALE_MINUTES);
        MarketingCampaign::where('status', 'sending')->where('updated_at', '<', $stale)->pluck('id')->each(function (string $id) use (&$sent, $stale) {
            $took = DB::table('marketing_campaigns')->where('id', $id)->where('status', 'sending')->where('updated_at', '<', $stale)->update(['status_note' => 'resumed', 'updated_at' => now()]);
            if ($took === 1) {
                DeliverCampaignJob::dispatch($id);
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
        $reminders = app(AbandonedCartReminders::class);
        $max = $reminders->maxPerCart();
        $gap = (int) $this->settings->get('notifications.abandoned_cart_min_interval_hours', 24);
        $user = $cart->user_id ? User::find($cart->user_id) : null;
        $out = fn (string $status, ?CarbonImmutable $next = null) => ['status' => $status, 'next_at' => $next, 'limit' => $max];
        if (! $user || ! $user->isCustomer() || ! $user->isActive()) {
            return $out('no_customer');
        }
        if (! $user->marketing_consent_at) {
            return $out('no_consent');
        }
        // The cart's counters (a customer's own change resets them) and what was really sent in the last days (durable): the larger one counts.
        $recent = $reminders->recent($cart);
        if ($recent['count'] >= $max) {
            return $out('limit_reached');
        }
        if ($recent['last'] && $recent['last']->addHours($gap)->isFuture()) {
            return $out('too_soon', $recent['last']->addHours($gap));
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
