<?php

namespace App\Domain\Notifications\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * "notification.created" on private-users.{id}: a new entry reached the customer's inbox. The apps and the web store move the unread badge
 * at once and read the inbox again, so a notification shows up while the app is open whether or not Firebase push works on that phone
 * (the socket and the 15 second poll are the app's own way of being told; push is the way to reach it when it is closed).
 *
 * Sent once the change is committed, through the `notifications` queue (not straight to the socket server like an order message: a campaign
 * writes thousands of inbox rows in one go and must not wait for a socket call per row). Carries what the inbox row shows (the person's own
 * text) and the new unread count; nothing the customer may not see.
 */
class NotificationCreated implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly string $userId,
        public readonly string $notificationId,
        public readonly string $event,
        public readonly string $category,
        public readonly string $title,
        public readonly string $body,
        public readonly ?string $deepLink,
        public readonly int $unreadCount,
        public readonly string $createdAt,
    ) {}

    public function broadcastQueue(): string
    {
        return 'notifications';
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('users.'.$this->userId)];
    }

    public function broadcastAs(): string
    {
        return 'notification.created';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'notification_id' => $this->notificationId, 'event' => $this->event, 'category' => $this->category,
            'title' => $this->title, 'body' => $this->body, 'deep_link' => $this->deepLink,
            'unread_count' => $this->unreadCount, 'created_at' => $this->createdAt,
        ];
    }
}
