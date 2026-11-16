<?php

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Models\UserDevice;
use App\Domain\Notifications\Events\NotificationCreated;
use App\Domain\Notifications\Models\Notification;
use App\Domain\Notifications\Models\PushDiagnostic;
use App\Domain\Notifications\Services\NotificationService;
use App\Domain\Notifications\Services\PushDiagnostics;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;

/*
 * What the phone apps say about their own push set-up: a public, strict, small endpoint; one row per install, stage and code per day;
 * nothing personal; the numbers the dashboard reads back; and the clean-up after 30 days.
 */

const INSTALL = '0b9c1f1e-6d1a-4a53-9d7e-3a1f5c2b8e10';

/** @return array<string, mixed> a valid report; override what the test is about */
function phoneReport(array $over = []): array
{
    return $over + [
        'app' => 'customer', 'platform' => 'android', 'stage' => 'token', 'code' => 'api_key_blocked',
        'message' => 'Requests from this Android client application are blocked.', 'app_version' => '1.0.0+12',
        'os_version' => 'Linux 6.1.75-android14', 'install_id' => INSTALL,
    ];
}

beforeEach(function () {
    $this->url = '/api/v1/push-diagnostics';
});

it('takes a report from a phone that is not signed in, and answers the same way every time', function () {
    $this->postJson($this->url, phoneReport())->assertStatus(202)->assertExactJson(['data' => ['accepted' => true]]);

    $row = PushDiagnostic::firstOrFail();
    expect($row->app)->toBe('customer')->and($row->platform)->toBe('android')->and($row->stage)->toBe('token')->and($row->code)->toBe('api_key_blocked')
        ->and($row->count)->toBe(1)->and($row->install_id)->toBe(INSTALL)->and($row->app_version)->toBe('1.0.0+12');
});

it('stores nothing about the person, the address or the token', function () {
    $this->actingAs(User::factory()->create())->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
        ->postJson($this->url, phoneReport(['push_token' => 'secret-token-value-1234567890', 'phone' => '+97455123456', 'email' => 'a@b.example', 'user_id' => 'x']))->assertStatus(202);

    $stored = json_encode(PushDiagnostic::firstOrFail()->getAttributes());
    expect($stored)->not->toContain('secret-token-value')->not->toContain('97455123456')->not->toContain('a@b.example')->not->toContain('203.0.113.9');
    expect(Schema::getColumnListing('push_diagnostics'))->not->toContain('user_id', 'ip', 'ip_address', 'push_token', 'token', 'phone', 'email');
});

it('refuses anything that is not exactly a report, with the reason', function (string $field, mixed $value) {
    $this->postJson($this->url, phoneReport([$field => $value]))->assertStatus(422)->assertJsonValidationErrors($field);
    expect(PushDiagnostic::count())->toBe(0);
})->with([
    'unknown app' => ['app', 'admin'],
    'unknown platform' => ['platform', 'web'],
    'unknown stage' => ['stage', 'banana'],
    'code in capitals' => ['code', 'API_KEY_BLOCKED'],
    'code with spaces' => ['code', 'api key blocked'],
    'code too long' => ['code', str_repeat('a', 49)],
    'empty code' => ['code', ''],
    'message over 200 characters' => ['message', str_repeat('x', 201)],
    'install id too short' => ['install_id', 'abc'],
    'install id with odd characters' => ['install_id', '../../etc/passwd/../../x'],
    'version with odd characters' => ['app_version', '1.0 <script>'],
    'os version too long' => ['os_version', str_repeat('x', 65)],
    'app as a list' => ['app', ['customer']],
]);

it('needs the five fields that say who and what', function (string $field) {
    $data = phoneReport();
    unset($data[$field]);
    $this->postJson($this->url, $data)->assertStatus(422)->assertJsonValidationErrors($field);
})->with(['app', 'platform', 'stage', 'code', 'install_id']);

it('keeps the message, version and os optional', function () {
    $this->postJson($this->url, ['app' => 'staff', 'platform' => 'ios', 'stage' => 'init', 'code' => 'init_failed', 'install_id' => INSTALL])->assertStatus(202);
    expect(PushDiagnostic::firstOrFail()->message)->toBeNull();
});

it('scrubs anything that looks like a key, a token, an e-mail address or a number out of the message, even when the app did not', function () {
    $dirty = 'API key AIzaSyB2c3D4e5F6g7H8i9J0k1L2m3N4o5P6q7R failed for a@b.example token dQw4w9WgXcQ:APA91bHun4MxP5egoKMwt2KZFBaFUH call +97455123456 at https://x.example/v1?key=abc123&token=zzz';
    $this->postJson($this->url, phoneReport(['message' => mb_substr($dirty, 0, 200)]))->assertStatus(202);

    $message = PushDiagnostic::firstOrFail()->message;
    expect($message)->not->toContain('AIzaSy')->not->toContain('a@b.example')->not->toContain('APA91b')->not->toContain('97455123456')->not->toContain('key=abc123')
        ->and($message)->toContain('[key]')->toContain('[email]')->toContain('[token]')->toContain('[number]')->and(mb_strlen($message))->toBeLessThanOrEqual(200);
});

it('counts a repeat of the same failure on the same day instead of adding a row', function () {
    $this->postJson($this->url, phoneReport())->assertStatus(202);
    $this->postJson($this->url, phoneReport(['message' => 'newer words', 'app_version' => '1.0.1+13']))->assertStatus(202);

    $row = PushDiagnostic::sole();
    expect($row->count)->toBe(2)->and($row->message)->toBe('newer words')->and($row->app_version)->toBe('1.0.1+13');
});

it('keeps a different failure, a different app, a different install and a different day apart', function () {
    $this->postJson($this->url, phoneReport())->assertStatus(202);
    $this->postJson($this->url, phoneReport(['code' => 'timeout']))->assertStatus(202);
    $this->postJson($this->url, phoneReport(['app' => 'driver']))->assertStatus(202);
    $this->postJson($this->url, phoneReport(['install_id' => '7f3e1d2c-1111-4222-8333-444455556666']))->assertStatus(202);
    $this->travel(1)->days();
    $this->postJson($this->url, phoneReport())->assertStatus(202);

    expect(PushDiagnostic::count())->toBe(5);
});

it('limits one install to 40 reports an hour: the 41st gets 429, another install is not affected', function () {
    for ($i = 0; $i < 40; $i++) {
        $this->postJson($this->url, phoneReport())->assertStatus(202);
    }
    $this->postJson($this->url, phoneReport())->assertStatus(429);
    $this->postJson($this->url, phoneReport(['install_id' => 'aaaaaaaa-1111-4222-8333-444455556666']))->assertStatus(202);
});

it('limits one address to 60 reports a minute, however many installs sit behind it', function () {
    $statuses = [];
    for ($i = 0; $i < 62; $i++) {
        $statuses[] = $this->postJson($this->url, phoneReport(['install_id' => sprintf('bbbbbbbb-%04d-4222-8333-444455556666', $i)]))->getStatusCode();
    }

    expect(array_count_values($statuses))->toBe([202 => 60, 429 => 2]);
});

// ── What the dashboard reads back ────────────────────────────────────────────────────────────────────────────────────

function seedReport(array $over): PushDiagnostic
{
    return PushDiagnostic::create($over + phoneReport() + ['report_day' => now()->toDateString(), 'count' => 1, 'first_seen_at' => now(), 'last_seen_at' => now()]);
}

it('groups what is wrong by app, step and code: phones, reports, latest words', function () {
    seedReport(['install_id' => 'inst-aaaaaaaa-1', 'count' => 3, 'message' => 'old words', 'last_seen_at' => now()->subHours(2)]);
    seedReport(['install_id' => 'inst-bbbbbbbb-2', 'count' => 2, 'message' => 'latest words', 'last_seen_at' => now()->subMinutes(5)]);
    seedReport(['install_id' => 'inst-cccccccc-3', 'app' => 'driver', 'code' => 'timeout', 'message' => 'x']);

    $problems = app(PushDiagnostics::class)->problems();

    expect($problems)->toHaveCount(2);
    $blocked = collect($problems)->firstWhere('code', 'api_key_blocked');
    expect($blocked)->toMatchArray(['app' => 'customer', 'stage' => 'token', 'phones' => 2, 'reports' => 5, 'message' => 'latest words', 'platform' => 'android']);
    expect(collect($problems)->firstWhere('code', 'timeout')['app'])->toBe('driver');
});

it('does not count a phone that failed and then registered fine, nor anything older than a week', function () {
    seedReport(['install_id' => 'inst-aaaaaaaa-1', 'last_seen_at' => now()->subHours(3)]);
    seedReport(['install_id' => 'inst-aaaaaaaa-1', 'stage' => 'registered', 'code' => 'ok', 'last_seen_at' => now()->subHour()]); // got better
    seedReport(['install_id' => 'inst-bbbbbbbb-2', 'last_seen_at' => now()->subHours(3)]);                                        // still failing
    seedReport(['install_id' => 'inst-cccccccc-3', 'code' => 'timeout', 'last_seen_at' => now()->subDays(8)]);                     // old news
    seedReport(['install_id' => 'inst-dddddddd-4', 'stage' => 'registered', 'code' => 'ok']);                                      // good news is not a problem

    $problems = app(PushDiagnostics::class)->problems();

    expect($problems)->toHaveCount(1)->and($problems[0])->toMatchArray(['code' => 'api_key_blocked', 'phones' => 1]);
});

it('says how many phones registered per app and platform, and when the latest did', function () {
    seedReport(['install_id' => 'inst-aaaaaaaa-1', 'stage' => 'registered', 'code' => 'ok', 'last_seen_at' => now()->subHours(5)]);
    seedReport(['install_id' => 'inst-bbbbbbbb-2', 'stage' => 'registered', 'code' => 'ok', 'last_seen_at' => now()->subMinutes(10)]);
    seedReport(['install_id' => 'inst-cccccccc-3', 'stage' => 'registered', 'code' => 'ok', 'platform' => 'ios']);
    seedReport(['install_id' => 'inst-dddddddd-4', 'stage' => 'registered', 'code' => 'ok', 'app' => 'staff', 'last_seen_at' => now()->subDays(40)]); // too old to count
    seedReport(['install_id' => 'inst-eeeeeeee-5']);                                                                                          // a failure is not a registration

    $registered = app(PushDiagnostics::class)->registered();

    expect($registered['customer']['android']['phones'])->toBe(2)->and($registered['customer']['ios']['phones'])->toBe(1)->and($registered)->not->toHaveKey('staff');
});

it('removes reports older than 30 days with push:prune-devices and keeps the rest', function () {
    seedReport(['install_id' => 'inst-aaaaaaaa-1', 'last_seen_at' => now()->subDays(31)]);
    seedReport(['install_id' => 'inst-bbbbbbbb-2', 'last_seen_at' => now()->subDays(29)]);

    $this->artisan('push:prune-devices')->expectsOutputToContain('Removed 1 old phone report')->assertSuccessful();

    expect(PushDiagnostic::pluck('install_id')->all())->toBe(['inst-bbbbbbbb-2']);
});

it('records which of the three apps a phone token comes from, from the X-App header the apps send', function () {
    actingAsCustomer();
    $this->withHeader('X-App', 'customer')->postJson('/api/v1/me/devices', ['platform' => 'android', 'push_token' => 'tok-customer-1234', 'device_id' => 'dev-1'])->assertCreated();
    $this->withHeader('X-App', 'driver')->postJson('/api/v1/me/devices', ['platform' => 'android', 'push_token' => 'tok-driver-1234', 'device_id' => 'dev-2'])->assertCreated();
    $this->withHeader('X-App', 'banana')->postJson('/api/v1/me/devices', ['platform' => 'ios', 'push_token' => 'tok-odd-1234', 'device_id' => 'dev-3'])->assertCreated();
    $this->postJson('/api/v1/me/devices', ['platform' => 'web', 'push_token' => 'tok-web-1234', 'app' => 'store'])->assertCreated();

    expect(UserDevice::where('push_token', 'tok-customer-1234')->value('app'))->toBe('customer')
        ->and(UserDevice::where('push_token', 'tok-driver-1234')->value('app'))->toBe('driver')
        ->and(UserDevice::where('push_token', 'tok-odd-1234')->value('app'))->toBeNull()
        ->and(UserDevice::where('push_token', 'tok-web-1234')->value('app'))->toBe('store');
});

// ── The inbox follows in real time, without push ──────────────────────────────────────────────────────────────────────

it('tells the customer\'s open apps about a new inbox entry on their private channel, with the new unread count', function () {
    Event::fake([NotificationCreated::class]);
    $user = User::factory()->create();

    app(NotificationService::class)->notify($user, 'order.confirmed', ['order_number' => 'AB100001', 'deep_link' => 'albahar://orders/1'], ['in_app']);
    app(NotificationService::class)->notify($user, 'order.confirmed', ['order_number' => 'AB100002'], ['in_app']);

    Event::assertDispatchedTimes(NotificationCreated::class, 2);
    $last = Event::dispatched(NotificationCreated::class)->last()[0];
    $first = Event::dispatched(NotificationCreated::class)->first()[0];
    expect($first->broadcastOn()[0]->name)->toBe('private-users.'.$user->id)->and($first->broadcastAs())->toBe('notification.created')
        ->and($first->broadcastWith())->toMatchArray(['event' => 'order.confirmed', 'category' => 'transactional', 'deep_link' => 'albahar://orders/1', 'unread_count' => 1])
        ->and($first->broadcastWith()['notification_id'])->toBe(Notification::where('user_id', $user->id)->oldest()->value('id'))
        ->and($last->broadcastWith()['unread_count'])->toBe(2)->and($first->broadcastQueue())->toBe('notifications');
    expect(is_subclass_of(NotificationCreated::class, ShouldBroadcast::class))->toBeTrue()->and(is_subclass_of(NotificationCreated::class, ShouldDispatchAfterCommit::class))->toBeTrue();
});

it('says nothing for a channel that is not the inbox, a person who is switched off, or a notification the person turned off', function () {
    Event::fake([NotificationCreated::class]);
    $user = User::factory()->create();

    app(NotificationService::class)->notify($user, 'order.confirmed', ['order_number' => 'AB100001'], ['push']);
    app(NotificationService::class)->notify(User::factory()->create(['status' => 'suspended']), 'order.confirmed', ['order_number' => 'AB100001'], ['in_app']);

    Event::assertNotDispatched(NotificationCreated::class);
});

it('never lets a socket server that is down stop a notification', function () {
    Event::listen(NotificationCreated::class, fn () => throw new RuntimeException('Reverb is down'));
    $user = User::factory()->create();

    $sent = app(NotificationService::class)->notify($user, 'order.confirmed', ['order_number' => 'AB100001'], ['in_app']);

    expect($sent)->toBe(['in_app'])->and(Notification::where('user_id', $user->id)->count())->toBe(1);
});
