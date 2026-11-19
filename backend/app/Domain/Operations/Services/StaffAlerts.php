<?php

namespace App\Domain\Operations\Services;

use App\Domain\Branches\Models\BranchEmployee;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessControl;
use App\Domain\Notifications\Events\NotificationCreated;
use App\Domain\Notifications\Models\Notification;
use App\Domain\Notifications\Services\NotificationRules;
use App\Domain\Notifications\Services\PushNotifier;
use App\Support\BusinessTime;
use Closure;
use Illuminate\Support\Collection;

/**
 * Sends an alert to the branch team: who gets it, and on which channels (push to their phones and the in-app inbox).
 *
 *  - The people are the ACTIVE employees of the branch who hold one of the wanted permissions AT that branch (so a manager of another branch,
 *    a driver without order rights or a suspended account hears nothing).
 *  - Every send asks the admin's notification controls first: the event's master switch and the channel's own switch (Content > Notification
 *    controls), so an alert that was switched off sends and stores nothing.
 *  - The text carries an order number and a count, never customer data: it can sit on a lock screen.
 */
final class StaffAlerts
{
    public function __construct(private readonly NotificationRules $rules, private readonly AccessControl $acl, private readonly PushNotifier $push) {}

    /**
     * @param  list<string>  $permissions  one is enough
     * @return Collection<int, User>
     */
    public function recipients(string $branchId, array $permissions): Collection
    {
        $ids = BranchEmployee::query()->where('branch_id', $branchId)->where('is_active', true)->pluck('user_id')->unique()->values();
        if ($ids->isEmpty()) {
            return new Collection;
        }

        return User::query()->whereIn('id', $ids)->get()->filter(fn (User $u) => $u->isStaff() && $u->isActive()
            && collect($permissions)->contains(fn (string $p) => $this->acl->allows($u, $p, $branchId)))->values();
    }

    /**
     * @param  Collection<int, User>  $users
     * @param  Closure(string): array{title: string, body: string}  $text  the alert in one language (called once per supported language)
     * @param  array<string, string|null>  $data  what the app receives to open the right screen (no customer data)
     * @return int how many people the alert reached on at least one channel
     */
    public function send(Collection $users, string $event, Closure $text, array $data, ?string $deepLink): int
    {
        $locales = array_values(array_unique((array) config('platform.supported_locales', ['en'])));
        $titles = [];
        $bodies = [];
        foreach ($locales as $locale) {
            $t = $text($locale);
            $titles[$locale] = $t['title'];
            $bodies[$locale] = $t['body'];
        }
        $inApp = $this->rules->allows($event, 'in_app');
        $push = $this->rules->allows($event, 'push');
        $reached = 0;
        foreach ($users as $user) {
            $locale = isset($titles[$user->preferredLocale()]) ? $user->preferredLocale() : $locales[0];
            $inboxId = null;
            $done = false;
            if ($inApp) {
                $entry = Notification::create(['user_id' => $user->id, 'event' => $event, 'category' => 'transactional', 'title' => $titles[$locale], 'body' => $bodies[$locale],
                    'data' => array_filter(['deep_link' => $deepLink, 'reference' => $data['order_id'] ?? null])]);
                $inboxId = $entry->id;
                $done = true;
                $this->announce($user, $entry);
            }
            if ($push) {
                $done = $this->push->staffAlert($user->id, $titles, $bodies, array_filter($data + ['event' => $event, 'notification_id' => $inboxId], fn ($v) => $v !== null) + ['deep_link' => (string) $deepLink]) > 0 || $done;
            }
            $reached += $done ? 1 : 0;
        }

        return $reached;
    }

    /** Tells the person's open app that its inbox has a new entry (like NotificationService does for customers). Never throws into the caller. */
    private function announce(User $user, Notification $entry): void
    {
        rescue(fn () => event(new NotificationCreated(
            $user->id, $entry->id, $entry->event, $entry->category, (string) $entry->title, (string) $entry->body, $entry->data['deep_link'] ?? null,
            Notification::where('user_id', $user->id)->whereNull('read_at')->count(), BusinessTime::iso($entry->created_at) ?? now()->toIso8601String(),
        )), report: true);
    }
}
