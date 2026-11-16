<?php

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Models\UserDevice;
use App\Domain\Messaging\Data\AudienceSelection;
use App\Domain\Messaging\Data\SendRequest;
use App\Domain\Messaging\Models\CustomerMessage;
use App\Domain\Messaging\Models\MessageBatch;
use App\Domain\Messaging\Services\CustomerMessaging;
use App\Domain\Notifications\Models\NotificationPreference;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

// Fixtures shared by the phone notification state tests (require_once keeps them defined once per run).

function npDevice(User $user, ?string $permission, ?string $token = null, array $extra = []): UserDevice
{
    $token ??= 'np-'.bin2hex(random_bytes(8));

    return UserDevice::create(['user_id' => $user->id, 'platform' => 'android', 'push_token' => $token, 'locale' => 'en', 'notification_permission' => $permission] + $extra);
}

function npCustomer(array $attrs = []): User
{
    $u = User::factory()->create($attrs + ['marketing_consent_at' => now(), 'locale' => 'en', 'name' => 'Noor Ali', 'phone' => '+9745'.random_int(5000000, 5999999)]);
    NotificationPreference::create(['user_id' => $u->id, 'channel' => 'whatsapp', 'category' => 'marketing', 'is_enabled' => true]);

    return $u;
}

/** Google's endpoints: the OAuth token and, for each push, whatever the closure answers (default: accepted). */
function npFake(?Closure $answer = null): void
{
    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.test', 'expires_in' => 3599]),
        'fcm.googleapis.com/*' => $answer ?? fn () => Http::response(['name' => 'projects/al-bahar-home/messages/1']),
    ]);
}

/** @return array<string, mixed> */
function npContent(): array
{
    return ['title_en' => 'Hello {{name}}', 'title_ar' => 'مرحباً {{name}}', 'body_en' => 'A note for {{name}}', 'body_ar' => 'رسالة إلى {{name}}',
        'whatsapp_en' => '', 'whatsapp_ar' => '', 'deep_link' => 'albahar://home', 'coupon' => ['enabled' => false]];
}

/** Sends a notification to these customers in one send, through the real delivery path (the queue runs inline in tests). */
function npSendMany(User $staff, array $customers): MessageBatch
{
    RateLimiter::clear('customer-messages:bulk:'.$staff->id);
    RateLimiter::clear('customer-messages:single:'.$staff->id);

    return app(CustomerMessaging::class)->send($staff, new SendRequest(AudienceSelection::ofIds(array_map(fn (User $u) => $u->id, $customers)), ['notification'], 'custom', npContent(), null, null, true));
}

/** Sends a notification to one customer through the real delivery path; returns the stored result of the message. */
function npSend(User $customer, ?User $staff = null): CustomerMessage
{
    npSendMany($staff ?? staffWithRole('marketing_manager'), [$customer]);

    return CustomerMessage::where('user_id', $customer->id)->where('channel', 'notification')->latest('created_at')->firstOrFail();
}
