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
        'cart.abandoned' => ['marketing', ['push', 'whatsapp']],
        'support.reply' => ['transactional', ['push', 'in_app', 'email']],
        'referral.rewarded' => ['transactional', ['push', 'in_app']],
        'referral.friend_rewarded' => ['transactional', ['push', 'in_app']],
        'campaign' => ['marketing', ['push', 'in_app']],
    ];

    public const CHANNELS = ['push', 'sms', 'whatsapp', 'email', 'in_app'];

    /**
     * The order events the customer's live order card already announces on every phone (see PushNotifier / OrderProgress): a second,
     * classic push for the same change would ring twice, so while the card can reach the customer these go to the inbox, SMS and e-mail only.
     */
    public const LIVE_CARD_EVENTS = ['order.created', 'order.confirmed', 'order.ready_for_pickup', 'order.out_for_delivery', 'order.completed', 'order.cancelled'];

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
        return self::EVENTS[$event][1] ?? ['in_app'];
    }
}
