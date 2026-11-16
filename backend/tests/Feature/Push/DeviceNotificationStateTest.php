<?php

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Models\UserDevice;
use App\Domain\Notifications\Models\NotificationPreference;
use App\Domain\Notifications\Services\DevicePushReach;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/PushSupport.php';
require_once __DIR__.'/PhoneStateSupport.php';

/*
 * What the phone says about showing notifications (docs/api/DEVICE_NOTIFICATION_STATE.md): the column, the devices endpoints that store it,
 * the delivery numbers that use it (`silent`) and the helper the dashboard reads. Android 13+ hides a push until the person allows it, so
 * "Google accepted it" is not "the person saw it".
 */

const NP_MIGRATION = __DIR__.'/../../../database/migrations/2026_11_16_300000_add_notification_permission_to_user_devices.php';

// ── The column ───────────────────────────────────────────────────────────────────────────────────────────────────────

describe('the column', function () {
    it('has a short nullable permission column and a time, and changes no data', function () {
        expect(Schema::hasColumns('user_devices', ['notification_permission', 'notification_permission_at']))->toBeTrue();
        $col = collect(Schema::getColumns('user_devices'))->keyBy('name');
        expect($col['notification_permission']['nullable'])->toBeTrue()->and($col['notification_permission']['type'])->toBe('character varying(12)')
            ->and($col['notification_permission_at']['nullable'])->toBeTrue()->and($col['notification_permission_at']['type_name'])->toBe('timestamptz');

        $device = npDevice(User::factory()->create(), null);
        expect($device->fresh()->notification_permission)->toBeNull()->and($device->fresh()->notification_permission_at)->toBeNull();
    });

    it('accepts granted, denied, not_asked and null, and refuses anything else', function () {
        $user = User::factory()->create();
        foreach (['granted', 'denied', 'not_asked', null] as $value) {
            $device = npDevice($user, $value);
            expect($device->fresh()->notification_permission)->toBe($value);
        }
        foreach (['maybe', 'GRANTED', '', 'unknown', 'provisional'] as $bad) {
            expect(fn () => DB::transaction(fn () => npDevice($user, $bad)))->toThrow(QueryException::class);
        }
        expect(UserDevice::count())->toBe(4);
    });

    it('goes back and forth without losing the table (reversible)', function () {
        $migration = require NP_MIGRATION;
        $migration->down();
        expect(Schema::hasColumn('user_devices', 'notification_permission'))->toBeFalse()->and(Schema::hasColumn('user_devices', 'notification_permission_at'))->toBeFalse()
            ->and(Schema::hasColumns('user_devices', ['push_token', 'platform', 'disabled_at']))->toBeTrue()
            ->and(DB::selectOne("select count(*) as n from pg_constraint where conname = 'user_devices_notification_permission_check'")->n)->toBe(0);

        $migration->up();
        expect(Schema::hasColumns('user_devices', ['notification_permission', 'notification_permission_at']))->toBeTrue()
            ->and(DB::selectOne("select count(*) as n from pg_constraint where conname = 'user_devices_notification_permission_check'")->n)->toBe(1);
    });
});

// ── The devices endpoints ────────────────────────────────────────────────────────────────────────────────────────────

describe('the devices endpoint', function () {
    $post = fn (array $extra = [], string $token = 'phone-token-aaaaaaaaaaaaaaaaaaaa', string $url = '/api/v1/me/devices') => test()
        ->postJson($url, ['platform' => 'android', 'push_token' => $token, 'app_version' => '2.0.0', 'device_id' => 'install-1'] + $extra);

    it('keeps working for an older app that does not send the field, and leaves the answer unknown', function () use ($post) {
        $user = actingAsCustomer();

        $post()->assertCreated()->assertExactJson(['data' => ['id' => UserDevice::firstOrFail()->id, 'registered' => true]]);

        $d = UserDevice::firstOrFail();
        expect($d->user_id)->toBe($user->id)->and($d->notification_permission)->toBeNull()->and($d->notification_permission_at)->toBeNull();
    });

    it('stores each allowed value with the time, on the same write as the token', function (string $value) use ($post) {
        actingAsCustomer();
        $this->travelTo(now()->startOfSecond());

        $post(['notification_permission' => $value])->assertCreated()->assertJsonPath('data.registered', true);

        $d = UserDevice::firstOrFail();
        expect($d->notification_permission)->toBe($value)->and($d->notification_permission_at?->equalTo(now()))->toBeTrue()->and($d->last_seen_at?->equalTo(now()))->toBeTrue()
            ->and(UserDevice::count())->toBe(1);
    })->with(['granted', 'denied', 'not_asked']);

    it('updates the answer and its time when the phone says something new', function () use ($post) {
        actingAsCustomer();
        $this->travelTo(now()->startOfSecond());
        $post(['notification_permission' => 'not_asked'])->assertCreated();
        $this->travel(3)->hours();

        $post(['notification_permission' => 'granted'])->assertCreated();

        $d = UserDevice::firstOrFail();
        expect($d->notification_permission)->toBe('granted')->and($d->notification_permission_at?->equalTo(now()))->toBeTrue()->and(UserDevice::count())->toBe(1);
    });

    it('leaves the stored answer and its time alone when a later registration does not carry it (old app, or null)', function (array $extra) use ($post) {
        actingAsCustomer();
        $this->travelTo(now()->startOfSecond());
        $post(['notification_permission' => 'denied'])->assertCreated();
        $said = UserDevice::firstOrFail()->notification_permission_at;
        $this->travel(2)->days();

        $post($extra)->assertCreated();

        $d = UserDevice::firstOrFail();
        expect($d->notification_permission)->toBe('denied')->and($d->notification_permission_at?->equalTo($said))->toBeTrue()
            ->and($d->last_seen_at?->equalTo(now()))->toBeTrue();
    })->with([
        'field left out' => [[]],
        'explicit null' => [['notification_permission' => null]],
        'empty string' => [['notification_permission' => '']],
    ]);

    it('refuses a value outside the list with a 422, like any other unknown value of this endpoint, and stores nothing', function (mixed $value) use ($post) {
        actingAsCustomer();

        $post(['notification_permission' => $value])->assertUnprocessable()->assertJsonValidationErrors('notification_permission');

        expect(UserDevice::count())->toBe(0);
    })->with([
        'unknown word' => ['maybe'],
        'wrong case' => ['GRANTED'],
        'unknown' => ['unknown'],
        'on' => ['on'],
        'true' => [true],
        'a number' => [1],
        'a list' => [['granted']],
        'too long' => [str_repeat('x', 40)],
    ]);

    it('does not let a refused value change what is already stored', function () use ($post) {
        actingAsCustomer();
        $post(['notification_permission' => 'granted'])->assertCreated();

        $post(['notification_permission' => 'banana'])->assertUnprocessable();

        expect(UserDevice::firstOrFail()->notification_permission)->toBe('granted');
    });

    it('is the same on the driver and staff path', function () use ($post) {
        $driver = driverFor(commerceWorld()->a);
        actingAsStaff($driver);

        $post(['notification_permission' => 'not_asked'], 'staff-phone-bbbbbbbbbbbbbbbbbbbb', '/api/v1/admin/devices')->assertCreated()->assertJsonPath('data.registered', true);
        $post([], 'staff-old-app-cccccccccccccccccccc', '/api/v1/admin/devices')->assertCreated();
        $post(['notification_permission' => 'nope'], 'staff-bad-dddddddddddddddddddd', '/api/v1/admin/devices')->assertUnprocessable()->assertJsonValidationErrors('notification_permission');

        expect(UserDevice::where('push_token', 'staff-phone-bbbbbbbbbbbbbbbbbbbb')->first())->notification_permission->toBe('not_asked')->user_id->toBe($driver->id)
            ->and(UserDevice::where('push_token', 'staff-old-app-cccccccccccccccccccc')->value('notification_permission'))->toBeNull()
            ->and(UserDevice::where('push_token', 'staff-bad-dddddddddddddddddddd')->exists())->toBeFalse();
    });

    it('needs a signed-in person, as before', function () use ($post) {
        $post(['notification_permission' => 'granted'])->assertUnauthorized();
        expect(UserDevice::count())->toBe(0);
    });

    it('never shows the answer or the token to the app: the response is the same two fields', function () use ($post) {
        actingAsCustomer();

        $json = $post(['notification_permission' => 'denied'])->assertCreated()->json();

        expect(array_keys($json))->toBe(['data'])->and(array_keys($json['data']))->toBe(['id', 'registered']);
    });
});

// ── The delivery numbers ─────────────────────────────────────────────────────────────────────────────────────────────

describe('the delivery numbers', function () {
    beforeEach(function () {
        configureFirebase();
    });

    it('counts the phones that were pushed and have notifications off or never asked as silent', function (array $permissions, int $silent) {
        npFake();
        $customer = npCustomer();
        foreach ($permissions as $permission) {
            npDevice($customer, $permission);
        }

        $meta = npSend($customer)->meta;

        expect($meta)->toMatchArray(['in_app' => true, 'devices' => count($permissions), 'pushed' => count($permissions), 'silent' => $silent, 'push_tried' => true]);
    })->with([
        'one denied' => [['denied'], 1],
        'one not asked' => [['not_asked'], 1],
        'one granted' => [['granted'], 0],
        'one unknown (an older app)' => [[null], 0],
        'denied, not asked, granted and unknown together' => [['denied', 'not_asked', 'granted', null], 2],
        'two granted' => [['granted', 'granted'], 0],
        'two unknown' => [[null, null], 0],
    ]);

    it('still sends the push to a silent phone: delivery is not changed', function () {
        npFake();
        $customer = npCustomer();
        npDevice($customer, 'denied');

        $row = npSend($customer);

        expect($row->status)->toBe('sent')->and($row->meta['pushed'])->toBe(1);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'messages:send'));
    });

    it('does not count a phone Google refused, a signed-out phone, or another customer\'s phone', function () {
        $customer = npCustomer();
        $other = npCustomer();
        npDevice($customer, 'denied', 'refused-token');
        npDevice($customer, 'denied', 'signed-out-token', ['disabled_at' => now()]);
        npDevice($other, 'denied', 'someone-elses-token');
        npFake(fn () => Http::response(['error' => ['status' => 'INVALID_ARGUMENT', 'message' => 'bad']], 400));

        $meta = npSend($customer)->meta;

        expect($meta['devices'])->toBe(1)->and($meta['pushed'])->toBe(0)->and($meta['silent'])->toBe(0);
    });

    it('records no phone and silent 0 when the customer has none, and says a push was tried', function () {
        npFake();

        $meta = npSend(npCustomer())->meta;

        expect($meta)->toMatchArray(['in_app' => true, 'devices' => 0, 'pushed' => 0, 'silent' => 0, 'push_tried' => true]);
    });

    it('says no push was tried when the customer takes no push', function () {
        npFake();
        $customer = npCustomer();
        NotificationPreference::create(['user_id' => $customer->id, 'channel' => 'push', 'category' => 'marketing', 'is_enabled' => false]);
        npDevice($customer, 'denied');

        $meta = npSend($customer)->meta;

        expect($meta)->toMatchArray(['devices' => 0, 'pushed' => 0, 'silent' => 0, 'push_tried' => false]);
    });
});

// ── The helper the dashboard reads ───────────────────────────────────────────────────────────────────────────────────

describe('the reach helper', function () {
    it('counts a customer\'s phones by what they said, and ignores signed-out phones', function () {
        $user = User::factory()->create();
        foreach (['granted', 'granted', 'denied', 'not_asked', null, null, null] as $p) {
            npDevice($user, $p);
        }
        npDevice($user, 'denied', null, ['disabled_at' => now()]);

        $reach = app(DevicePushReach::class)->forUser($user->id);

        expect($reach)->toBe(['devices' => 7, 'can_show' => 2, 'off' => 1, 'not_asked' => 1, 'unknown' => 3])
            ->and($reach['devices'])->toBe($reach['can_show'] + $reach['off'] + $reach['not_asked'] + $reach['unknown']);
    });

    it('answers a whole page of customers with one grouped query and no phone as zeros', function () {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $none = User::factory()->create();
        npDevice($a, 'granted');
        npDevice($b, 'denied');
        npDevice($b, null);

        DB::enableQueryLog();
        $all = app(DevicePushReach::class)->forUsers([$a->id, $b->id, $none->id, $a->id]);
        $queries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'user_devices'));
        DB::disableQueryLog();

        expect($queries)->toHaveCount(1)->and(array_keys($all))->toBe([$a->id, $b->id, $none->id])
            ->and($all[$a->id])->toBe(['devices' => 1, 'can_show' => 1, 'off' => 0, 'not_asked' => 0, 'unknown' => 0])
            ->and($all[$b->id])->toBe(['devices' => 2, 'can_show' => 0, 'off' => 1, 'not_asked' => 0, 'unknown' => 1])
            ->and($all[$none->id])->toBe(DevicePushReach::empty())
            ->and(app(DevicePushReach::class)->forUsers([]))->toBe([]);
    });

    it('never mixes up two customers', function () {
        $a = User::factory()->create();
        $b = User::factory()->create();
        npDevice($a, 'denied');
        npDevice($b, 'granted');
        npDevice($b, 'granted');

        $all = app(DevicePushReach::class)->forUsers([$a->id, $b->id]);

        expect($all[$a->id]['devices'])->toBe(1)->and($all[$a->id]['off'])->toBe(1)->and($all[$b->id]['devices'])->toBe(2)->and($all[$b->id]['off'])->toBe(0);
    });

    it('names the state of a customer in the five ways the dashboard words it', function (array $reach, string $state, bool $warns) {
        expect(DevicePushReach::state($reach))->toBe($state)->and(DevicePushReach::pushWillNotShow($reach))->toBe($warns);
    })->with([
        'no phone' => [['devices' => 0, 'can_show' => 0, 'off' => 0, 'not_asked' => 0, 'unknown' => 0], 'none', true],
        'notifications on' => [['devices' => 1, 'can_show' => 1, 'off' => 0, 'not_asked' => 0, 'unknown' => 0], 'on', false],
        'one on and one off' => [['devices' => 2, 'can_show' => 1, 'off' => 1, 'not_asked' => 0, 'unknown' => 0], 'on', false],
        'not reported' => [['devices' => 1, 'can_show' => 0, 'off' => 0, 'not_asked' => 0, 'unknown' => 1], 'unknown', false],
        'off and not reported (may still show)' => [['devices' => 2, 'can_show' => 0, 'off' => 1, 'not_asked' => 0, 'unknown' => 1], 'unknown', false],
        'notifications off' => [['devices' => 1, 'can_show' => 0, 'off' => 1, 'not_asked' => 0, 'unknown' => 0], 'off', true],
        'not asked yet' => [['devices' => 1, 'can_show' => 0, 'off' => 0, 'not_asked' => 1, 'unknown' => 0], 'not_asked', true],
        'off and not asked' => [['devices' => 2, 'can_show' => 0, 'off' => 1, 'not_asked' => 1, 'unknown' => 0], 'off', true],
    ]);

    it('reads a finished delivery: nothing in the normal case, a note when notifications are off or there was no phone', function (?array $meta, ?array $note) {
        expect(DevicePushReach::deliveryNote($meta))->toBe($note);
    })->with([
        'no meta (a skipped or WhatsApp row)' => [null, null],
        'normal' => [['in_app' => true, 'devices' => 2, 'pushed' => 2, 'silent' => 0, 'push_tried' => true], null],
        'an older row without silent' => [['in_app' => true, 'devices' => 1, 'pushed' => 1], null],
        'some silent' => [['in_app' => true, 'devices' => 3, 'pushed' => 2, 'silent' => 1, 'push_tried' => true], ['kind' => 'silent', 'reached' => 2, 'silent' => 1]],
        'all silent' => [['in_app' => true, 'devices' => 1, 'pushed' => 1, 'silent' => 1, 'push_tried' => true], ['kind' => 'silent', 'reached' => 1, 'silent' => 1]],
        'no phone' => [['in_app' => true, 'devices' => 0, 'pushed' => 0, 'silent' => 0, 'push_tried' => true], ['kind' => 'no_phone', 'reached' => 0, 'silent' => 0]],
        'an older row with no phone' => [['in_app' => true, 'devices' => 0, 'pushed' => 0], ['kind' => 'no_phone', 'reached' => 0, 'silent' => 0]],
        'no push was tried: nothing to say about phones' => [['in_app' => true, 'devices' => 0, 'pushed' => 0, 'silent' => 0, 'push_tried' => false], null],
    ]);
});
