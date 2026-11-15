<?php

use App\Domain\Identity\Models\User;
use App\Domain\Notifications\Models\LiveActivityToken;
use App\Domain\Orders\Models\Order;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

require_once __DIR__.'/ApnsSupport.php';
require_once __DIR__.'/../Commerce/LiveOrderHelpers.php';

/*
 * The iPhone app reports its Live Activity tokens: the push token of a running activity (one order) and the phone's push-to-start
 * token. Only the customer's own orders are accepted and a token never touches anybody else's.
 */

beforeEach(function () {
    lopFreeze();
    $this->w = commerceWorld();
    $this->order = lopPlace($this->w);
    $this->payload = fn (array $extra = []) => array_merge(['order_id' => $this->order->id, 'push_token' => hexToken('activity-1'), 'environment' => 'sandbox',
        'device_id' => 'install-1', 'locale' => 'ar', 'app_version' => '1.4.0+7'], $extra);
});

it('stores the token of a running activity for the customer\'s own order, with its phone, language and environment', function () {
    $this->postJson('/api/v1/me/live-activities', ($this->payload)())->assertCreated()->assertJsonPath('data.registered', true)->assertJsonStructure(['data' => ['id']]);

    $t = LiveActivityToken::firstOrFail();
    expect($t->user_id)->toBe($this->w->customer->id)->and($t->order_id)->toBe($this->order->id)->and($t->kind)->toBe('activity')
        ->and($t->token)->toBe(hexToken('activity-1'))->and($t->environment)->toBe('sandbox')->and($t->device_id)->toBe('install-1')
        ->and($t->locale)->toBe('ar')->and($t->app_version)->toBe('1.4.0+7')->and($t->disabled_at)->toBeNull()->and($t->last_seen_at)->not->toBeNull();
});

it('never shows a token back: not in the answer and not in a model dump', function () {
    $json = $this->postJson('/api/v1/me/live-activities', ($this->payload)())->assertCreated()->getContent();

    expect($json)->not->toContain(hexToken('activity-1'))->and(LiveActivityToken::firstOrFail()->toArray())->not->toHaveKey('token');
});

it('defaults to production and to the request language', function () {
    $this->withHeader('Accept-Language', 'ar')->postJson('/api/v1/me/live-activities', ['order_id' => $this->order->id, 'push_token' => hexToken('t')])->assertCreated();

    expect(LiveActivityToken::firstOrFail())->environment->toBe('production')->and(LiveActivityToken::firstOrFail()->locale)->toBe('ar');
});

it('is idempotent: the same token again refreshes the row and switches a disabled one back on', function () {
    $this->postJson('/api/v1/me/live-activities', ($this->payload)())->assertCreated();
    LiveActivityToken::query()->update(['disabled_at' => now(), 'last_seen_at' => now()->subDays(1)]);

    $this->postJson('/api/v1/me/live-activities', ($this->payload)(['locale' => 'en', 'push_token' => strtoupper(hexToken('activity-1'))]))->assertCreated();

    expect(LiveActivityToken::count())->toBe(1);
    $t = LiveActivityToken::first();
    expect($t->disabled_at)->toBeNull()->and($t->locale)->toBe('en')->and($t->last_seen_at->isToday())->toBeTrue()->and($t->token)->toBe(hexToken('activity-1'));
});

it('keeps only the newest token of one activity on one phone (Apple rotates it)', function () {
    $this->postJson('/api/v1/me/live-activities', ($this->payload)())->assertCreated();
    $this->postJson('/api/v1/me/live-activities', ($this->payload)(['push_token' => hexToken('activity-2')]))->assertCreated();
    // Another phone of the same customer, and another order, are left alone.
    $this->postJson('/api/v1/me/live-activities', ($this->payload)(['push_token' => hexToken('activity-3'), 'device_id' => 'install-2']))->assertCreated();

    $live = LiveActivityToken::whereNull('disabled_at')->pluck('token')->sort()->values()->all();
    expect($live)->toBe(collect([hexToken('activity-2'), hexToken('activity-3')])->sort()->values()->all())
        ->and(LiveActivityToken::where('token', hexToken('activity-1'))->firstOrFail()->disabled_at)->not->toBeNull();
});

it('refuses an order that is not the caller\'s, and tells nothing about it', function () {
    $other = User::factory()->create();
    $theirs = Order::findOrFail($this->order->id);
    $theirs->forceFill(['user_id' => $other->id])->save();

    $this->postJson('/api/v1/me/live-activities', ($this->payload)())->assertNotFound();
    $this->postJson('/api/v1/me/live-activities', ($this->payload)(['order_id' => (string) Str::uuid()]))->assertNotFound();

    expect(LiveActivityToken::count())->toBe(0);
});

it('never moves a token of an order the caller does not own, even when the token is already stored for somebody else', function () {
    $other = User::factory()->create();
    $mine = LiveActivityToken::create(['user_id' => $other->id, 'order_id' => $this->order->id, 'kind' => 'activity', 'token' => hexToken('activity-1'), 'environment' => 'production']);
    Order::whereKey($this->order->id)->update(['user_id' => $other->id]);

    $this->postJson('/api/v1/me/live-activities', ($this->payload)())->assertNotFound();

    expect($mine->fresh()->user_id)->toBe($other->id)->and($mine->fresh()->disabled_at)->toBeNull();
});

it('rejects what the contract does not allow', function (array $change) {
    $this->postJson('/api/v1/me/live-activities', ($this->payload)($change))->assertUnprocessable();

    expect(LiveActivityToken::count())->toBe(0);
})->with([
    'no order' => [['order_id' => null]],
    'order that is not a uuid' => [['order_id' => '12']],
    'no token' => [['push_token' => null]],
    'token that is not hex' => [['push_token' => str_repeat('zz', 20)]],
    'token too short' => [['push_token' => 'abcdef']],
    'token too long' => [['push_token' => str_repeat('a', 251)]],
    'unknown environment' => [['environment' => 'staging']],
    'unknown language' => [['locale' => 'fr']],
    'device id too long' => [['device_id' => str_repeat('d', 192)]],
]);

it('accepts the phone\'s push-to-start token and keeps the newest one per phone', function () {
    $this->postJson('/api/v1/me/live-activities/start-token', ['push_token' => hexToken('start-1'), 'environment' => 'production', 'device_id' => 'install-1', 'locale' => 'en'])
        ->assertCreated()->assertJsonPath('data.registered', true);
    $this->postJson('/api/v1/me/live-activities/start-token', ['push_token' => hexToken('start-2'), 'environment' => 'production', 'device_id' => 'install-1'])->assertCreated();

    $first = LiveActivityToken::where('token', hexToken('start-1'))->firstOrFail();
    $second = LiveActivityToken::where('token', hexToken('start-2'))->firstOrFail();
    expect($first->kind)->toBe('start')->and($first->order_id)->toBeNull()->and($first->user_id)->toBe($this->w->customer->id)
        ->and($first->disabled_at)->not->toBeNull()->and($second->disabled_at)->toBeNull();
});

it('rejects a malformed push-to-start token', function () {
    $this->postJson('/api/v1/me/live-activities/start-token', ['push_token' => 'nope'])->assertUnprocessable();
    $this->postJson('/api/v1/me/live-activities/start-token', [])->assertUnprocessable();
});

it('switches off one token, the activities of one order, or everything one phone reported', function () {
    $mk = fn (string $seed, string $device, ?Order $order = null) => activityToken($this->w->customer->id, $order ?? $this->order, hexToken($seed), ['device_id' => $device]);
    $a = $mk('a', 'p1');
    $b = $mk('b', 'p2');
    $s = activityToken($this->w->customer->id, null, hexToken('s'), ['device_id' => 'p1']);

    $this->deleteJson('/api/v1/me/live-activities', ['push_token' => hexToken('a')])->assertOk()->assertJsonPath('data.unregistered', true);
    expect($a->fresh()->disabled_at)->not->toBeNull()->and($b->fresh()->disabled_at)->toBeNull()->and($s->fresh()->disabled_at)->toBeNull();
});

it('switches off everything of one phone at sign-out, and an order\'s activities by order', function () {
    $mk = fn (string $seed, string $device) => activityToken($this->w->customer->id, $this->order, hexToken($seed), ['device_id' => $device]);
    $a = $mk('a', 'p1');
    $b = $mk('b', 'p2');
    $s = activityToken($this->w->customer->id, null, hexToken('s'), ['device_id' => 'p1']);

    $this->deleteJson('/api/v1/me/live-activities', ['device_id' => 'p1'])->assertOk();
    expect($a->fresh()->disabled_at)->not->toBeNull()->and($s->fresh()->disabled_at)->not->toBeNull()->and($b->fresh()->disabled_at)->toBeNull();

    $this->deleteJson('/api/v1/me/live-activities', ['order_id' => $this->order->id])->assertOk();
    expect($b->fresh()->disabled_at)->not->toBeNull();
});

it('needs at least one thing to switch off, and never touches somebody else\'s tokens', function () {
    $other = User::factory()->create();
    $theirs = activityToken($other->id, null, hexToken('theirs'), ['device_id' => 'p1']);
    $mine = activityToken($this->w->customer->id, $this->order, hexToken('mine'), ['device_id' => 'p1']);

    $this->deleteJson('/api/v1/me/live-activities', [])->assertUnprocessable();
    $this->deleteJson('/api/v1/me/live-activities', ['push_token' => hexToken('theirs')])->assertOk();
    $this->deleteJson('/api/v1/me/live-activities', ['device_id' => 'p1'])->assertOk();

    expect($theirs->fresh()->disabled_at)->toBeNull()->and($mine->fresh()->disabled_at)->not->toBeNull();
});

it('is for signed-in customers only', function () {
    app('auth')->forgetGuards();
    $this->withHeader('Authorization', '')->postJson('/api/v1/me/live-activities', ($this->payload)())->assertUnauthorized();
    $this->postJson('/api/v1/me/live-activities/start-token', ['push_token' => hexToken('s')])->assertUnauthorized();
    $this->deleteJson('/api/v1/me/live-activities', ['device_id' => 'p'])->assertUnauthorized();

    actingAsStaff(User::factory()->staff()->create());
    $this->postJson('/api/v1/me/live-activities', ($this->payload)())->assertForbidden();
    expect(LiveActivityToken::count())->toBe(0);
});

it('is rate limited per person', function () {
    RateLimiter::clear('live-activities');
    $codes = [];
    foreach (range(1, 31) as $i) {
        $codes[] = $this->postJson('/api/v1/me/live-activities', ($this->payload)(['push_token' => hexToken("burst-$i")]))->status();
    }

    expect(array_count_values($codes))->toBe([201 => 30, 429 => 1]);
});

it('the daily clean-up removes old activity tokens and switched-off ones, and switches off stale push-to-start tokens', function () {
    $old = activityToken($this->w->customer->id, $this->order, hexToken('old'));
    $old->forceFill(['created_at' => now()->subDays(3)])->save();
    $off = activityToken($this->w->customer->id, null, hexToken('off'), ['disabled_at' => now()->subDays(3)]);
    $fresh = activityToken($this->w->customer->id, $this->order, hexToken('fresh'));
    $stale = activityToken($this->w->customer->id, null, hexToken('stale'), ['last_seen_at' => now()->subDays(90)]);
    $stale->forceFill(['created_at' => now()->subDays(90)])->save();

    $this->artisan('push:prune-devices')->assertSuccessful();

    expect(LiveActivityToken::whereKey($old->id)->exists())->toBeFalse()->and(LiveActivityToken::whereKey($off->id)->exists())->toBeFalse()
        ->and(LiveActivityToken::whereKey($fresh->id)->first()->disabled_at)->toBeNull()
        ->and(LiveActivityToken::whereKey($stale->id)->first()->disabled_at)->not->toBeNull();
});
