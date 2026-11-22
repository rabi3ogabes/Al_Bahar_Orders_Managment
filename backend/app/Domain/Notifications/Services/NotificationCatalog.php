<?php

namespace App\Domain\Notifications\Services;

/**
 * Every notification event the platform sends, its category (governs consent rules) and default channels.
 * Transactional messages are always allowed on in-app; marketing requires explicit consent + preference.
 */
final class NotificationCatalog
{
    public const EVENTS = [
        'otp' => ['transactional', ['sms']],
        'order.created' => ['transactional', ['push', 'in_app', 'email']],
        'order.confirmed' => ['transactional', ['push', 'in_app']],
        'order.processing' => ['transactional', ['in_app']],
        'order.ready_for_pickup' => ['transactional', ['push', 'in_app', 'sms']],
        'order.out_for_delivery' => ['transactional', ['push', 'in_app', 'sms']],
        'order.completed' => ['transactional', ['push', 'in_app']],
        'order.cancelled' => ['transactional', ['push', 'in_app', 'email']],
        // The branch changed what is in the order (an item was short): not a live-card event, so the customer always hears it as a push too.
        'order.items_changed' => ['transactional', ['push', 'in_app']],
        // The branch replaced an item with another product (or a dearer one): the customer is asked to accept it, and told when time ran out and it was kept.
        'order.substitution_offer' => ['transactional', ['push', 'in_app']],
        'order.substitution_kept' => ['transactional', ['push', 'in_app']],
        // The driver is close, and at the door (sent once each by the driver app's steps and its position reports; never for a pickup order).
        'delivery.nearby' => ['transactional', ['push', 'in_app']],
        'delivery.arrived' => ['transactional', ['push', 'in_app']],
        // After a failed delivery the customer is offered new times to choose from (SlotOffers); held back until 08:00 when it is made at night.
        'delivery.slots_offered' => ['transactional', ['push', 'in_app']],
        'payment.link' => ['transactional', ['push', 'in_app']],
        'payment.succeeded' => ['transactional', ['push', 'in_app']],
        'payment.failed' => ['transactional', ['push', 'in_app']],
        'refund.processed' => ['transactional', ['push', 'in_app', 'email']],
        'auction.starting' => ['auction', ['push', 'in_app']],
        'auction.outbid' => ['auction', ['push', 'in_app']],
        'auction.won' => ['auction', ['push', 'in_app', 'sms', 'email']],
        'auction.lost' => ['auction', ['in_app']],
        'auction.payment_reminder' => ['auction', ['push', 'in_app', 'sms']],
        'stock.back_in_stock' => ['marketing', ['push', 'in_app']],
        'stock.price_drop' => ['marketing', ['push', 'in_app']],
        'cart.abandoned' => ['marketing', ['push', 'in_app', 'whatsapp']],
        // A return or damage claim: every step the customer can see (the customer's own submit only lands in the inbox; the refund itself is `refund.processed`).
        'return.received' => ['transactional', ['in_app']],
        'return.under_review' => ['transactional', ['push', 'in_app']],
        'return.approved' => ['transactional', ['push', 'in_app']],
        'return.rejected' => ['transactional', ['push', 'in_app']],
        'return.item_received' => ['transactional', ['push', 'in_app']],
        'support.reply' => ['transactional', ['push', 'in_app', 'email']],
        'referral.rewarded' => ['transactional', ['push', 'in_app']],
        'referral.friend_rewarded' => ['transactional', ['push', 'in_app']],
        'campaign' => ['marketing', ['push', 'in_app']],
    ];

    public const CHANNELS = ['push', 'sms', 'whatsapp', 'email', 'in_app'];

    /**
     * Messages the customer asked for by pressing a button ("Notify me when it is back"): they are filed with the offers like the other stock messages, but
     * they do not need the general consent to marketing messages, because the request is the consent. A channel the customer switched off by hand is still
     * respected (NotificationService::allowed).
     */
    public const REQUESTED = ['stock.back_in_stock'];

    public static function isRequested(string $event): bool
    {
        return in_array($event, self::REQUESTED, true);
    }

    /**
     * Alerts to the team (the `staff.*` events of NotificationRules::STAFF_EVENTS): [category, default channels]. They are not customer messages,
     * so they have no consent rules and no template rows; the new-order alert and its reminder are ON by default, on push and in the in-app inbox
     * (words in lang/*\/ops_live.php, `push.*`). The older driver and branch pushes are push only and are not listed here.
     */
    public const STAFF = [
        'staff.order_new' => ['transactional', ['push', 'in_app']],
        'staff.order_reminder' => ['transactional', ['push', 'in_app']],
        // A stock count is waiting for a second person to approve it, and a count that was sent back (the people who counted are told why).
        'staff.stocktake_waiting' => ['transactional', ['push', 'in_app']],
        'staff.stocktake_returned' => ['transactional', ['push', 'in_app']],
        // The customer chose a new delivery time after a failed delivery; the paid assembly was reported as not done.
        'staff.slot_chosen' => ['transactional', ['push', 'in_app']],
        'staff.assembly_not_done' => ['transactional', ['push', 'in_app']],
    ];

    /**
     * The order events the customer's live order card already announces on every phone (see PushNotifier / OrderProgress): a second,
     * classic push for the same change would ring twice, so while the card can reach the customer these go to the inbox, SMS and e-mail only.
     */
    public const LIVE_CARD_EVENTS = ['order.created', 'order.confirmed', 'order.ready_for_pickup', 'order.out_for_delivery', 'order.completed', 'order.cancelled'];

    /**
     * The catalog event that owns each step (`phase`) of the live order card, so the notification controls can switch a step off together
     * with its message: placed = order.created, preparing = order.confirmed ("order accepted"), on the way = order.out_for_delivery,
     * ready (pickup) = order.ready_for_pickup, delivered and collected = order.completed, cancelled = order.cancelled.
     * A failed delivery has no event on purpose: it needs the customer's attention, so the card always shows it.
     */
    public const CARD_PHASE_EVENTS = [
        'placed' => 'order.created', 'preparing' => 'order.confirmed', 'on_the_way' => 'order.out_for_delivery', 'ready' => 'order.ready_for_pickup',
        'delivered' => 'order.completed', 'collected' => 'order.completed', 'cancelled' => 'order.cancelled',
    ];

    /** The event behind a live-card step, or null for a step the controls never switch off (a failed delivery). */
    public static function cardEvent(string $phase): ?string
    {
        return self::CARD_PHASE_EVENTS[$phase] ?? null;
    }

    /**
     * Events sent outside the catalog by staff (`staff.message`) and by the customer journeys (`journey.*`) are marketing, like the
     * in-app entries they create, so the app files their banners under the offers preference and the offers Android channel.
     */
    public static function category(string $event): string
    {
        if ($event === 'staff.message' || str_starts_with($event, 'journey.')) {
            return 'marketing';
        }

        return self::EVENTS[$event][0] ?? 'transactional';
    }

    public static function defaultChannels(string $event): array
    {
        return self::EVENTS[$event][1] ?? self::STAFF[$event][1] ?? ['in_app'];
    }

    /** @return list<string> the channels a staff or driver alert is sent on by default (push only, unless the catalog says more) */
    public static function staffChannels(string $event): array
    {
        return self::STAFF[$event][1] ?? ['push'];
    }
}
