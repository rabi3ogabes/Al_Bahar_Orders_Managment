<?php

namespace App\Domain\Notifications\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Identity\Models\User;
use App\Domain\Notifications\Models\NotificationLog;
use App\Domain\Notifications\Models\NotificationRule;
use App\Support\DomainException;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * Notification controls: which notifications the platform sends, and on which channels, as the admin decided in the dashboard
 * (Content > Notification controls). The ONE place that answers "may this be sent?" for every send path.
 *
 *  - An event has a master switch (`enabled`) and, per channel (push, in_app, sms, whatsapp, email and the live order card `live_card`),
 *    an override. A channel without an override follows the platform default of that event (NotificationCatalog).
 *  - No row means "the platform default", so nothing changes until the admin changes something. Only deviations are stored: a switch put
 *    back to its default is removed, and an event with no deviation left loses its row.
 *  - A rule can only REMOVE a send the caller asked for, or ADD a channel to an event that is sent with its default channels. It never
 *    overrides the customer: consent and per-channel preferences (NotificationService::allowed) are applied on top.
 *  - `otp` (sign-in codes) is locked: it can never be switched off, or nobody could sign in.
 *  - Staff and driver alerts (the `staff.*` events) are not in the catalog (they have no message templates) but are switched here too.
 *
 * Read from the cache (one entry for all rules, flushed on every write), so the hot path costs one cache read.
 */
class NotificationRules
{
    /** The sixth "channel": the customer's live order card (Android promoted notification, iPhone Live Activity). */
    public const LIVE_CARD = 'live_card';

    /** Every channel a rule can switch, in the order the page shows them. */
    public const CHANNELS = ['push', 'in_app', 'sms', 'whatsapp', 'email', self::LIVE_CARD];

    /** Events that can never be switched off. */
    public const LOCKED = ['otp'];

    /** Pushes to drivers and branch staff (PushNotifier). Push is their only channel. */
    public const STAFF_EVENTS = ['staff.driver_assigned', 'staff.order_ready', 'staff.delivery_failed'];

    /** Events the catalog lists but no code sends yet; the page says so, the switches already work. */
    public const NOT_SENT_YET = ['auction.starting', 'auction.payment_reminder'];

    /** The page's groups, in order, and the events of each. Every catalog event is in exactly one group (a test keeps it so). */
    public const GROUPS = [
        'orders' => ['order.created', 'order.confirmed', 'order.processing', 'order.ready_for_pickup', 'order.out_for_delivery', 'order.completed', 'order.cancelled', 'order.items_changed'],
        'payments' => ['payment.link', 'payment.succeeded', 'payment.failed', 'refund.processed'],
        'auctions' => ['auction.starting', 'auction.outbid', 'auction.won', 'auction.lost', 'auction.payment_reminder'],
        'stock' => ['stock.back_in_stock', 'stock.price_drop'],
        'support' => ['support.reply'],
        'referrals' => ['referral.rewarded', 'referral.friend_rewarded'],
        'campaigns' => ['campaign', 'cart.abandoned'],
        'account' => ['otp'],
        'staff' => self::STAFF_EVENTS,
    ];

    private const CACHE_KEY = 'notification_rules:v1';

    private const COUNTS_KEY = 'notification_rules:sent:';

    private const TTL = 300;

    // ── Questions the send paths ask ─────────────────────────────────────────────────────────────────────────────────

    /** Is this notification switched on at all? Events the controls do not know (journeys, staff messages) are always on. */
    public function enabled(string $event): bool
    {
        return $this->isLocked($event) || ($this->rows()[$event]['enabled'] ?? true);
    }

    /**
     * Is this channel ON for the event, as the page shows it: the master switch is on and the channel's override, or else its platform default,
     * says yes.
     */
    public function channelEnabled(string $event, string $channel): bool
    {
        if (! in_array($channel, $this->supportedChannels($event), true)) {
            return false;
        }

        return $this->enabled($event) && ($this->isLocked($event) || $this->ownState($event, $channel));
    }

    /**
     * May a send the caller has already decided to make go out? True unless the admin switched the event or this channel off. Used by the
     * paths that do not go through NotificationService (the live order card, staff and driver pushes, queued jobs). A channel that is
     * simply not in the event's defaults is allowed: that is the caller's own choice.
     */
    public function allows(string $event, string $channel): bool
    {
        if ($this->isLocked($event)) {
            return true;
        }
        $row = $this->rows()[$event] ?? null;

        return $row === null || ($row['enabled'] && ($row['channels'][$channel] ?? true) !== false);
    }

    /**
     * The channels to send an event on, from the ones the caller wants: nothing when the event is off, the caller's list minus the channels
     * switched off otherwise. With $allowAdditions (the caller used the event's default channels, not a list of its own choosing) a channel
     * the admin switched ON that the defaults do not have is added. The live card is not a send channel, so it never appears here.
     *
     * @param  list<string>  $defaults
     * @return list<string>
     */
    public function effectiveChannels(string $event, array $defaults, bool $allowAdditions = true): array
    {
        $row = $this->isLocked($event) ? null : ($this->rows()[$event] ?? null);
        if ($row === null) {
            return $defaults;
        }
        if (! $row['enabled']) {
            return [];
        }
        $out = array_values(array_filter($defaults, fn (string $c) => ($row['channels'][$c] ?? true) !== false));
        if ($allowAdditions) {
            foreach ($row['channels'] as $channel => $on) {
                if ($on && $channel !== self::LIVE_CARD && ! in_array($channel, $defaults, true) && in_array($channel, $this->supportedChannels($event), true)) {
                    $out[] = $channel;
                }
            }
        }

        return array_values(array_unique($out));
    }

    public function isLocked(string $event): bool
    {
        return in_array($event, self::LOCKED, true);
    }

    public function known(string $event): bool
    {
        return isset(NotificationCatalog::EVENTS[$event]) || in_array($event, self::STAFF_EVENTS, true);
    }

    /** @return list<string> the channels a switch exists for on this event, in page order */
    public function supportedChannels(string $event): array
    {
        if (in_array($event, self::STAFF_EVENTS, true)) {
            return ['push'];
        }
        if ($this->isLocked($event)) {
            return NotificationCatalog::defaultChannels($event);
        }
        $channels = ['push', 'in_app', 'sms', 'whatsapp', 'email'];
        if (in_array($event, NotificationCatalog::LIVE_CARD_EVENTS, true)) {
            $channels[] = self::LIVE_CARD;
        }

        return $channels;
    }

    /** @return list<string> the channels an event uses when nobody changed anything (the live card counts for the six card events) */
    public function defaultChannels(string $event): array
    {
        if (in_array($event, self::STAFF_EVENTS, true)) {
            return ['push'];
        }

        return [...NotificationCatalog::defaultChannels($event), ...(in_array($event, NotificationCatalog::LIVE_CARD_EVENTS, true) ? [self::LIVE_CARD] : [])];
    }

    // ── What the admin changed ───────────────────────────────────────────────────────────────────────────────────────

    /**
     * What is saved for an event.
     *
     * @return array{enabled: bool, channels: array<string, bool>, customized: bool}
     */
    public function state(string $event): array
    {
        $row = $this->rows()[$event] ?? null;

        return ['enabled' => $row['enabled'] ?? true, 'channels' => $row['channels'] ?? [], 'customized' => $row !== null];
    }

    /**
     * Saves the master switch and the channel overrides of an event (the whole state, not a patch). A value equal to the platform default
     * is dropped, and an event that ends up equal to the default loses its row. Audited as `notifications.rule_changed`; a save that changes
     * nothing writes and logs nothing.
     *
     * @param  array<string, mixed>  $channels  channel => on/off, for the channels this event has a switch for; others are ignored
     *
     * @throws DomainException when the event is locked and the change would switch something of it off
     * @throws InvalidArgumentException for an event the controls do not know
     */
    public function set(string $event, bool $enabled, array $channels = [], ?User $actor = null): void
    {
        $this->guardKnown($event);
        $clean = $this->normalise($event, $channels);
        if ($this->isLocked($event)) {
            if (! $enabled || in_array(false, $clean, true)) {
                throw new DomainException('notification_locked', __('notification_rules_admin.err_locked'), 422);
            }

            return;
        }
        $row = NotificationRule::where('event', $event)->first();
        $old = ['is_enabled' => $row === null || $row->is_enabled, 'channels' => $this->clean((array) ($row?->channels ?? []))];
        $new = ['is_enabled' => $enabled, 'channels' => $clean];
        if ($old == $new) {
            return;
        }
        if ($enabled && $clean === []) {
            NotificationRule::where('event', $event)->delete(); // back to the platform default
        } else {
            NotificationRule::updateOrCreate(['event' => $event], ['is_enabled' => $enabled, 'channels' => $clean, 'updated_by' => ($actor ?? auth()->user())?->id]);
        }
        $this->flush();
        app(AuditLogger::class)->log('notifications.rule_changed', 'NotificationRule', $old, $new, ['event' => $event], actor: $actor);
    }

    /** Puts one event back to the platform default. @return bool whether there was anything to put back */
    public function reset(string $event, ?User $actor = null): bool
    {
        $this->guardKnown($event);
        $row = NotificationRule::where('event', $event)->first();
        if ($row === null) {
            return false;
        }
        $row->delete();
        $this->flush();
        app(AuditLogger::class)->log('notifications.rule_changed', 'NotificationRule', ['is_enabled' => $row->is_enabled, 'channels' => $this->clean((array) $row->channels)],
            ['is_enabled' => true, 'channels' => []], ['event' => $event, 'reset' => true], actor: $actor);

        return true;
    }

    /** Puts every event back to the platform default. @return int how many events had been changed */
    public function resetAll(?User $actor = null): int
    {
        $n = 0;
        foreach (NotificationRule::query()->pluck('event') as $event) {
            $n += $this->known($event) && $this->reset($event, $actor) ? 1 : 0;
        }
        NotificationRule::query()->delete(); // rows of an event that no longer exists
        $this->flush();

        return $n;
    }

    // ── For the page ─────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Every event by group, with its master switch and, per channel, whether a switch exists, its own state (what it shows when the master
     * switch is on) and the platform default.
     *
     * @return list<array{group: string, events: list<array<string, mixed>>}>
     */
    public function matrix(): array
    {
        $out = [];
        foreach (self::GROUPS as $group => $events) {
            $rows = [];
            foreach ($events as $event) {
                $state = $this->state($event);
                $supported = $this->supportedChannels($event);
                $default = $this->defaultChannels($event);
                $channels = [];
                foreach (self::CHANNELS as $channel) {
                    $has = in_array($channel, $supported, true);
                    $channels[$channel] = ['supported' => $has, 'on' => $has && ($this->isLocked($event) || $this->ownState($event, $channel)), 'default' => $has && in_array($channel, $default, true)];
                }
                $rows[] = ['event' => $event, 'locked' => $this->isLocked($event), 'enabled' => $this->enabled($event), 'customized' => $state['customized'], 'channels' => $channels];
            }
            $out[] = ['group' => $group, 'events' => $rows];
        }

        return $out;
    }

    /**
     * Messages delivered per event in the last N days (push, SMS, WhatsApp and e-mail from the delivery log; the inbox has no delivery
     * log). One grouped query, cached for five minutes.
     *
     * @return array<string, int>
     */
    public function sentCounts(int $days = 30): array
    {
        return Cache::remember(self::COUNTS_KEY.$days, self::TTL, fn () => NotificationLog::query()->where('created_at', '>=', now()->subDays($days))
            ->whereIn('status', ['sent', 'delivered'])->selectRaw('event, count(*) as n')->groupBy('event')->pluck('n', 'event')
            ->map(fn (mixed $n) => (int) $n)->all());
    }

    // ── Internals ────────────────────────────────────────────────────────────────────────────────────────────────────

    /** The channel's own state: its override, or else its platform default (ignores the master switch). */
    private function ownState(string $event, string $channel): bool
    {
        return $this->rows()[$event]['channels'][$channel] ?? in_array($channel, $this->defaultChannels($event), true);
    }

    /**
     * Only the channels this event has a switch for, as booleans, and only the ones that differ from the platform default.
     *
     * @param  array<string, mixed>  $channels
     * @return array<string, bool>
     */
    private function normalise(string $event, array $channels): array
    {
        $supported = $this->supportedChannels($event);
        $default = $this->defaultChannels($event);
        $out = [];
        foreach (self::CHANNELS as $channel) {
            $on = array_key_exists($channel, $channels) ? filter_var($channels[$channel], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : null;
            if ($on !== null && in_array($channel, $supported, true) && $on !== in_array($channel, $default, true)) {
                $out[$channel] = $on;
            }
        }

        return $out;
    }

    /**
     * @param  array<mixed>  $raw
     * @return array<string, bool>
     */
    private function clean(array $raw): array
    {
        $out = [];
        foreach (self::CHANNELS as $channel) {
            if (array_key_exists($channel, $raw) && is_bool($raw[$channel])) {
                $out[$channel] = $raw[$channel];
            }
        }

        return $out;
    }

    /** @return array<string, array{enabled: bool, channels: array<string, bool>}> every saved rule, by event */
    private function rows(): array
    {
        return Cache::remember(self::CACHE_KEY, self::TTL, function () {
            $out = [];
            foreach (NotificationRule::query()->get(['event', 'is_enabled', 'channels']) as $rule) {
                $out[$rule->event] = ['enabled' => (bool) $rule->is_enabled, 'channels' => $this->clean((array) $rule->channels)];
            }

            return $out;
        });
    }

    private function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    private function guardKnown(string $event): void
    {
        if (! $this->known($event)) {
            throw new InvalidArgumentException('Unknown notification event: '.$event);
        }
    }
}
