<?php

use App\Domain\Branches\Models\BranchEmployee;
use App\Domain\Fulfillment\Events\DispatchUpdated;
use App\Domain\Identity\Models\UserDevice;
use App\Domain\Notifications\Services\FcmClient;
use App\Domain\Notifications\Services\PushNotifier;
use App\Domain\Orders\Events\OrderUpdated;
use Illuminate\Support\Facades\Http;

function fakeFcm(): object
{
    $calls = new ArrayObject;
    $fcm = Mockery::mock(FcmClient::class);
    $fcm->shouldReceive('configured')->andReturn(true);
    $fcm->shouldReceive('sendProgress')->andReturnUsing(function (...$a) use ($calls) {
        $calls[] = ['progress', ...$a];

        return ['ok' => true];
    });
    $fcm->shouldReceive('send')->andReturnUsing(function (...$a) use ($calls) {
        $calls[] = ['plain', ...$a];

        return ['ok' => true];
    });
    app()->instance(FcmClient::class, $fcm);

    return $calls;
}

it('sends the customer a progress push in their language with the step and a deep link', function () {
    $w = commerceWorld();
    UserDevice::create(['user_id' => $w->customer->id, 'platform' => 'android', 'push_token' => 'tok-ar', 'locale' => 'ar']);
    $calls = fakeFcm();
    app(PushNotifier::class)->orderUpdated(new OrderUpdated($w->customer->id, 'ord-1', 'AB100', 'out_for_delivery', 'out_for_delivery'));

    expect($calls)->toHaveCount(1)->and($calls[0][0])->toBe('progress')->and($calls[0][1])->toBe('tok-ar')
        ->and($calls[0][3])->toContain('السائق')
        ->and($calls[0][4])->toMatchArray(['kind' => 'order_progress', 'order_id' => 'ord-1', 'step' => 2, 'total' => 4, 'deep_link' => '/orders/ord-1']);
});

it('maps statuses onto the four steps and marks delivered as done', function () {
    expect(PushNotifier::step('confirmed', null))->toBe(0)->and(PushNotifier::step('processing', null))->toBe(1)
        ->and(PushNotifier::step('out_for_delivery', 'picked_up'))->toBe(2)->and(PushNotifier::step('out_for_delivery', 'delivered'))->toBe(3)
        ->and(PushNotifier::step('completed', null))->toBe(3);
});

it('tells the driver about a new job and the branch about a ready order', function () {
    $w = commerceWorld();
    $driver = driverFor($w->a);
    $mgr = staffWithRole('order_manager', $w->a);
    BranchEmployee::firstOrCreate(['branch_id' => $w->a->id, 'user_id' => $mgr->id], ['is_active' => true]);
    UserDevice::create(['user_id' => $driver->id, 'platform' => 'android', 'push_token' => 'drv', 'locale' => 'en']);
    UserDevice::create(['user_id' => $mgr->id, 'platform' => 'android', 'push_token' => 'mgr', 'locale' => 'en']);
    $calls = fakeFcm();
    $n = app(PushNotifier::class);
    $n->dispatchUpdated(new DispatchUpdated($w->a->id, 'AB7', 'delivery', 'assigned', $driver->id));
    $n->dispatchUpdated(new DispatchUpdated($w->a->id, 'AB7', 'order', 'ready'));

    $tokens = collect($calls)->pluck(1)->all();
    expect($tokens)->toContain('drv')->toContain('mgr')->and(collect($calls)->firstWhere(1, 'drv')[3])->toContain('AB7');
});

it('does nothing when Firebase is not configured and never reaches the network', function () {
    $w = commerceWorld();
    UserDevice::create(['user_id' => $w->customer->id, 'platform' => 'android', 'push_token' => 't', 'locale' => 'en']);
    config(['services.fcm.project_id' => null]);
    Http::fake();
    app(PushNotifier::class)->orderUpdated(new OrderUpdated($w->customer->id, 'o', 'AB1', 'processing'));
    Http::assertNothingSent();
});

it('lets staff and drivers register a push token on the admin API', function () {
    $w = commerceWorld();
    $driver = driverFor($w->a);
    actingAsStaff($driver);
    $this->postJson('/api/v1/admin/devices', ['platform' => 'android', 'push_token' => 'abc123'])->assertSuccessful();
    expect(UserDevice::where('push_token', 'abc123')->value('user_id'))->toBe($driver->id);
});
