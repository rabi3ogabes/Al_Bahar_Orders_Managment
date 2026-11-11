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
        'campaign' => ['marketing', ['push', 'in_app']],
    ];

    public const CHANNELS = ['push', 'sms', 'whatsapp', 'email', 'in_app'];

    public static function category(string $event): string
    {
        return self::EVENTS[$event][0] ?? 'transactional';
    }

    public static function defaultChannels(string $event): array
    {
        return self::EVENTS[$event][1] ?? ['in_app'];
    }
}
