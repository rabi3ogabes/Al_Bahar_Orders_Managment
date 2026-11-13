<?php

use App\Domain\Cms\Services\ThemeService;
use App\Domain\Fulfillment\Enums\DeliveryStatus as DS;
use App\Domain\Fulfillment\Models\DriverProfile;
use App\Domain\Identity\Models\User;
use App\Domain\Orders\Enums\FulfillmentStatus as FS;
use App\Domain\Orders\Enums\OrderStatus as OS;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderFulfillment;
use App\Domain\Orders\Services\OrderProgress;
use App\Domain\Settings\Services\Settings;
use App\Livewire\Admin\Organization\SettingsIndex;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

require_once __DIR__.'/LiveOrderHelpers.php';

/* The live order card: stages, texts, arrival window, driver. The clock is frozen at Sat 14 Nov 2026 18:00 Qatar. */

beforeEach(function () {
    lopFreeze();
    $this->w = commerceWorld();
});

// ── Stages ───────────────────────────────────────────────────────────────────────────────────────────────────────────

it('shows every delivery stage on the four-step bar', function (FS $fulfillment, ?DS $delivery, int $step, string $phase, string $detail, string $done) {
    $order = lopMove(lopPlace($this->w), $fulfillment, delivery: $delivery, driver: $delivery ? driverFor($this->w->a) : null);
    $card = lopCard($order);

    expect($card)->toMatchArray(['kind' => 'order_progress', 'order_id' => $order->id, 'number' => $order->number, 'type' => 'delivery',
        'step' => $step, 'total' => 4, 'phase' => $phase, 'detail' => $detail, 'done' => $done, 'deep_link' => '/orders/'.$order->id]);
})->with([
    'placed' => [FS::Pending, null, 0, 'placed', 'placed', '0'],
    'accepted by the branch' => [FS::Accepted, null, 1, 'preparing', 'preparing', '0'],
    'being prepared' => [FS::Preparing, null, 1, 'preparing', 'preparing', '0'],
    'ready, no driver yet' => [FS::Ready, null, 1, 'preparing', 'preparing', '0'],
    'driver assigned' => [FS::Ready, DS::Assigned, 1, 'preparing', 'driver_assigned', '0'],
    'driver accepted' => [FS::Ready, DS::Accepted, 1, 'preparing', 'driver_accepted', '0'],
    'picked up' => [FS::Dispatched, DS::PickedUp, 2, 'on_the_way', 'picked_up', '0'],
    'out for delivery' => [FS::OutForDelivery, DS::OutForDelivery, 2, 'on_the_way', 'out_for_delivery', '0'],
    'delivery moved before the fulfilment' => [FS::Ready, DS::OutForDelivery, 2, 'on_the_way', 'out_for_delivery', '0'],
    'delivered' => [FS::Delivered, DS::Delivered, 3, 'delivered', 'delivered', '1'],
    'delivery failed' => [FS::Failed, DS::Failed, 2, 'failed', 'failed', '1'],
]);

it('shows every pickup stage: placed, preparing, ready, collected', function (FS $fulfillment, int $step, string $phase, string $done, string $label) {
    $order = lopMove(lopPlace($this->w, 'pickup'), $fulfillment);

    expect(lopCard($order))->toMatchArray(['type' => 'pickup', 'step' => $step, 'total' => 4, 'phase' => $phase, 'done' => $done, 'step_label' => $label]);
})->with([
    'placed' => [FS::Pending, 0, 'placed', '0', 'Placed'],
    'preparing' => [FS::Preparing, 1, 'preparing', '0', 'Preparing'],
    'ready for pickup' => [FS::PickupReady, 2, 'ready', '0', 'Ready for pickup'],
    'collected' => [FS::PickedUp, 3, 'collected', '1', 'Collected'],
]);

it('ends the card for a cancelled order and for a failed delivery, with a clear text', function () {
    $order = lopPlace($this->w);
    $order->forceFill(['status' => OS::Cancelled->value, 'cancelled_at' => now()])->save();
    $cancelled = lopCard($order);
    expect($cancelled)->toMatchArray(['phase' => 'cancelled', 'done' => '1', 'step' => 0, 'title' => 'Order cancelled', 'body' => 'Your order was cancelled.', 'step_label' => 'Order cancelled', 'eta_to' => null])
        ->and(lopCard($order, 'ar'))->toMatchArray(['title' => 'تم إلغاء الطلب', 'body' => 'تم إلغاء طلبك.']);

    $failed = lopMove(lopPlace($this->w), FS::Failed, delivery: DS::Failed, driver: driverFor($this->w->a));
    expect(lopCard($failed))->toMatchArray(['phase' => 'failed', 'done' => '1', 'title' => 'Delivery unsuccessful', 'body' => 'We could not deliver your order. We will contact you shortly.', 'eta_from' => null])
        ->and(lopCard($failed, 'ar')['body'])->toBe('تعذر توصيل طلبك. سنتواصل معك قريباً.');
});

it('waits for payment without promising an arrival time', function () {
    $order = lopPlace($this->w);
    $order->forceFill(['status' => OS::PendingPayment->value])->save();

    expect(lopCard($order))->toMatchArray(['phase' => 'placed', 'detail' => 'pending_payment', 'title' => 'Order placed', 'body' => 'Waiting for your payment.', 'eta_from' => null]);
});

it('says so when the customer has asked to cancel', function () {
    $order = lopMove(lopPlace($this->w), FS::Preparing);
    $order->forceFill(['status' => OS::CancellationRequested->value])->save();

    expect(lopCard($order))->toMatchArray(['phase' => 'preparing', 'body' => 'Your cancellation request is being reviewed.']);
});

it('has nothing to show for a returned order', function () {
    $order = lopMove(lopPlace($this->w), FS::Returned);
    $order->forceFill(['status' => OS::Returned->value])->save();

    expect(app(OrderProgress::class)->forOrder(Order::find($order->id)))->toBeNull();
});

it('shows the least advanced part of an order with several branches, and the latest arrival', function () {
    $order = lopPlace($this->w);
    $a = $order->fulfillments->first();
    $b = OrderFulfillment::create(['order_id' => $order->id, 'branch_id' => $this->w->b->id, 'number' => $a->number.'-B', 'type' => 'delivery', 'status' => FS::OutForDelivery->value]);
    lopSlot($b, '2026-11-15', '16:00', '18:00'); // part B is booked for tomorrow afternoon

    $card = lopCard($order);
    // Part A has only been placed (stage 0, arrival about 6:35-6:55pm); part B is already on the road but arrives last.
    expect($card)->toMatchArray(['step' => 0, 'phase' => 'placed', 'title' => 'Arrives tomorrow, 4:00pm-6:00pm', 'eta_to' => '2026-11-15T15:00:00Z']);

    $a->forceFill(['status' => FS::Delivered->value])->save(); // part A done: B decides
    expect(lopCard($order))->toMatchArray(['step' => 2, 'phase' => 'on_the_way']);
    $b->forceFill(['status' => FS::Delivered->value])->save();
    expect(lopCard($order))->toMatchArray(['step' => 3, 'phase' => 'delivered', 'done' => '1']);
});

it('ignores a cancelled part and lets a failed part win a tie', function () {
    $order = lopPlace($this->w);
    $a = $order->fulfillments->first()->forceFill(['status' => FS::Cancelled->value]);
    $a->save();
    $b = OrderFulfillment::create(['order_id' => $order->id, 'branch_id' => $this->w->b->id, 'number' => $a->number.'-B', 'type' => 'delivery', 'status' => FS::OutForDelivery->value]);
    expect(lopCard($order))->toMatchArray(['step' => 2, 'phase' => 'on_the_way']);

    $c = OrderFulfillment::create(['order_id' => $order->id, 'branch_id' => $this->w->a->id, 'number' => $a->number.'-C', 'type' => 'delivery', 'status' => FS::Failed->value]);
    expect(lopCard($order))->toMatchArray(['step' => 2, 'phase' => 'failed', 'done' => '1']);
    $c->delete();
    $b->delete();
});

// ── Wording and time ─────────────────────────────────────────────────────────────────────────────────────────────────

it('writes the headline and status line in English with Qatar time', function () {
    $order = lopMove(lopPlace($this->w), FS::Preparing);
    $card = lopCard($order);

    // Accepted at 18:00 → ready 18:25 → there 18:45, ± 7 minutes, widened to 5-minute marks.
    expect($card)->toMatchArray(['title' => 'Arrives at 6:35pm-6:55pm', 'eta_label' => '6:35pm-6:55pm', 'eta_from' => '2026-11-14T15:35:00Z', 'eta_to' => '2026-11-14T15:55:00Z',
        'body' => 'Being prepared at '.$order->fulfillments->first()->branch->name_en.'.', 'step_label' => 'Preparing']);
});

it('writes the headline and status line in Arabic with the same times', function () {
    $order = lopMove(lopPlace($this->w), FS::Preparing);
    $card = lopCard($order, 'ar');

    expect($card)->toMatchArray(['title' => 'تصل بين 6:35 م و 6:55 م', 'eta_label' => '6:35 م - 6:55 م', 'eta_from' => '2026-11-14T15:35:00Z', 'step_label' => 'قيد التجهيز'])
        ->and($card['body'])->toStartWith('قيد التجهيز في ');
});

it('names the pickup window "ready for pickup at", from the branch pickup preparation time', function () {
    $this->w->a->update(['pickup_preparation_minutes' => 30]);
    $card = lopCard(lopPlace($this->w, 'pickup'));

    // Placed 18:00 + 30 minutes = ready 18:30, ± 7 → 18:23 .. 18:37 → 18:20 .. 18:40.
    expect($card)->toMatchArray(['title' => 'Ready for pickup at 6:20pm-6:40pm', 'eta_label' => '6:20pm-6:40pm'])
        ->and(lopCard(lopPlace($this->w, 'pickup'), 'ar')['title'])->toBe('جاهز للاستلام بين 6:20 م و 6:40 م');
});

it('has no window once the pickup is ready or the order is done', function () {
    $ready = lopCard(lopMove(lopPlace($this->w, 'pickup'), FS::PickupReady));
    expect($ready)->toMatchArray(['title' => 'Ready for pickup', 'eta_from' => null, 'eta_to' => null, 'eta_label' => null])
        ->and($ready['body'])->toContain('Your order is ready for pickup at ')
        ->and($ready)->not->toHaveKey('sub');

    $done = lopCard(lopMove(lopPlace($this->w), FS::Delivered, delivery: DS::Delivered, driver: driverFor($this->w->a)));
    expect($done)->toMatchArray(['title' => 'Delivered', 'eta_to' => null, 'body' => 'Delivered. Enjoy, and thank you for shopping with Al Bahar.']);
});

it('uses 12-hour times, a day word after today and a short date after tomorrow', function () {
    $f = lopPlace($this->w)->fulfillments->first();
    lopSlot($f, '2026-11-14', '20:00', '21:30');
    expect(lopCard($f->order)['title'])->toBe('Arrives at 8:00pm-9:30pm');

    lopSlot($f, '2026-11-15', '09:00', '10:30');
    expect(lopCard($f->order)['title'])->toBe('Arrives tomorrow, 9:00am-10:30am')
        ->and(lopCard($f->order, 'ar')['title'])->toBe('تصل غداً بين 9:00 ص و 10:30 ص');

    lopSlot($f, '2026-11-17', '09:00', '10:30');
    $later = lopCard($f->order);
    expect($later['title'])->toBe('Arrives Tue 17 Nov, 9:00am-10:30am')->and($later['eta_label'])->toBe('Tue 17 Nov, 9:00am-10:30am')
        ->and(lopCard($f->order, 'ar')['title'])->toBe('تصل ثلاثاء 17 نوفمبر بين 9:00 ص و 10:30 ص');
});

it('reads a device language such as ar_QA, and falls back to English for an unknown one', function () {
    $order = lopPlace($this->w);
    $svc = app(OrderProgress::class);

    expect($svc->locale('ar_QA'))->toBe('ar')->and($svc->locale('AR'))->toBe('ar')->and($svc->locale('fr'))->toBe('en')->and($svc->locale(null))->toBe('en')
        ->and($svc->forOrder($order, 'ar_QA')['title'])->toStartWith('تصل');
    expect(app()->getLocale())->toBe('en'); // building a card never leaves the app in another language
});

it('uses the live theme colours for the card, with the house colours as fallback', function () {
    $order = lopPlace($this->w);
    expect(lopCard($order)['brand'])->toBe(['primary' => '#8A1538', 'accent' => '#B3254F']);

    Cache::put(ThemeService::CACHE_KEY, ['colors' => ['primary' => '#0a3d62', 'accent' => 'tomato']]);
    expect(lopCard($order)['brand'])->toBe(['primary' => '#0A3D62', 'accent' => '#B3254F']);
});

// ── Arrival window ───────────────────────────────────────────────────────────────────────────────────────────────────

it('uses the booked slot as the window, and the estimate once the slot has passed', function () {
    $order = lopPlace($this->w);
    lopSlot($order->fulfillments->first(), '2026-11-14', '20:00', '21:00');
    expect(lopCard($order))->toMatchArray(['title' => 'Arrives at 8:00pm-9:00pm', 'eta_from' => '2026-11-14T17:00:00Z', 'eta_to' => '2026-11-14T18:00:00Z'])
        ->and(lopCard($order))->not->toHaveKey('sub');

    $this->travelTo(now()->addHours(4)); // 22:00: the slot is over, the order is late
    expect(lopCard($order)['title'])->not->toContain('8:00pm');
});

it('estimates from the preparation and delivery times in the settings when there is no slot or driver position', function () {
    config(['platform.orders.default_prep_minutes' => 10, 'platform.orders.default_delivery_minutes' => 15]);
    $card = lopCard(lopMove(lopPlace($this->w), FS::Preparing));

    // 18:00 + 10 + 15 = 18:25, ± 7 → 18:18 .. 18:32 → 18:15 .. 18:35.
    expect($card)->toMatchArray(['title' => 'Arrives at 6:15pm-6:35pm', 'eta_from' => '2026-11-14T15:15:00Z', 'eta_to' => '2026-11-14T15:35:00Z']);
});

it('ships sensible defaults of 25 and 20 minutes', function () {
    expect(config('platform.orders.default_prep_minutes'))->toBe(25)->and(config('platform.orders.default_delivery_minutes'))->toBe(20);
});

it('lets the admin set the typical preparation and delivery times in Settings, in both languages', function () {
    $order = lopMove(lopPlace($this->w), FS::Preparing);
    $this->actingAs(User::factory()->superAdmin()->create());

    Livewire::test(SettingsIndex::class)->set('tab', 'checkout')
        ->set('values.checkout.orders__default_prep_minutes', '10')->set('values.checkout.orders__default_delivery_minutes', '15')->call('save')->assertHasNoErrors();
    expect(app(Settings::class)->get('orders.default_prep_minutes'))->toBe(10)->and(app(Settings::class)->get('orders.default_delivery_minutes'))->toBe(15)
        ->and(lopCard($order)['title'])->toBe('Arrives at 6:15pm-6:35pm');

    Livewire::test(SettingsIndex::class)->set('tab', 'checkout')->set('values.checkout.orders__default_prep_minutes', '0')->call('save')->assertHasErrors('values.checkout.orders__default_prep_minutes');
    Livewire::test(SettingsIndex::class)->set('tab', 'checkout')->set('values.checkout.orders__default_delivery_minutes', '241')->call('save')->assertHasErrors('values.checkout.orders__default_delivery_minutes');

    foreach (['en', 'ar'] as $locale) {
        foreach (['orders__default_prep_minutes', 'orders__default_delivery_minutes'] as $field) {
            expect(trans('org_admin.setting.'.$field, [], $locale))->not->toBe('org_admin.setting.'.$field)
                ->and(trans('org_admin.setting_hint.'.$field, [], $locale))->not->toBe('org_admin.setting_hint.'.$field);
        }
    }
});

it('reports how far through preparation the order is', function () {
    $order = lopMove(lopPlace($this->w), FS::Preparing);
    $this->travelTo(now()->addMinutes(10)); // 10 of the 25 preparation minutes

    expect(lopCard($order)['sub'])->toBe(0.4);
    $this->travelTo(now()->addMinutes(5));
    expect(lopCard($order)['sub'])->toBe(0.6);
});

it('leaves the share out while the order is only placed', function () {
    expect(lopCard(lopPlace($this->w)))->not->toHaveKey('sub');
});

it('estimates the remaining drive from the time since pickup when the driver has no fresh position', function () {
    $driver = driverFor($this->w->a);
    $order = lopMove(lopPlace($this->w), FS::OutForDelivery, delivery: DS::OutForDelivery, driver: $driver, deliveryAttrs: ['out_for_delivery_at' => now()->subMinutes(5)]);
    $card = lopCard($order);

    // 20 minutes typical − 5 gone = 15 left → 18:15, ± 5 → 18:10 .. 18:20; 5 of 20 minutes have passed.
    expect($card)->toMatchArray(['title' => 'Arrives at 6:10pm-6:20pm', 'sub' => 0.25]);
});

it('estimates from the driver position with straight lines when no routing server is set', function () {
    Http::fake();
    $driver = driverFor($this->w->a);
    DriverProfile::where('user_id', $driver->id)->update(['last_lat' => 25.30, 'last_lng' => 51.50, 'last_fix_at' => now()]);
    $order = lopMove(lopPlace($this->w), FS::OutForDelivery, delivery: DS::OutForDelivery, driver: $driver, deliveryAttrs: ['out_for_delivery_at' => now()->subMinute()]);
    $order->forceFill(['address_snapshot' => ['latitude' => 25.28, 'longitude' => 51.52] + (array) $order->address_snapshot])->save();
    $card = lopCard($order);

    // About 3 km as the crow flies × 1.35 at 28 km/h = 9 minutes → 18:09, ± 3 → 18:06 .. 18:12 → 18:05 .. 18:15.
    expect($card)->toMatchArray(['title' => 'Arrives at 6:05pm-6:15pm'])->and($card['sub'])->toBeBetween(0.0, 1.0);
    Http::assertNothingSent();
});

it('asks the routing server for the road time, and never waits on it when the caller must not block', function () {
    config(['platform.map.routing_url' => 'https://osrm.example.test']);
    Http::fake(['osrm.example.test/*' => Http::response(['code' => 'Ok', 'routes' => [['duration' => 600, 'distance' => 4200]]])]);
    $driver = driverFor($this->w->a);
    DriverProfile::where('user_id', $driver->id)->update(['last_lat' => 25.30, 'last_lng' => 51.50, 'last_fix_at' => now()]);
    $order = lopMove(lopPlace($this->w), FS::OutForDelivery, delivery: DS::OutForDelivery, driver: $driver, deliveryAttrs: ['out_for_delivery_at' => now()]);
    $order->forceFill(['address_snapshot' => ['latitude' => 25.28, 'longitude' => 51.52] + (array) $order->address_snapshot])->save();

    $quick = lopCard($order, remote: false);
    Http::assertNothingSent();                       // page loads use the straight-line estimate and wait for nobody
    expect($quick['title'])->toStartWith('Arrives at');

    $road = lopCard($order);
    Http::assertSentCount(1);                        // 10 road minutes → 18:10, ± 4 → 18:05 .. 18:15
    expect($road['title'])->toBe('Arrives at 6:05pm-6:15pm');

    lopCard($order);
    Http::assertSentCount(1);                        // answered from the 30-second cache
});

it('does not use a stale driver position', function () {
    $driver = driverFor($this->w->a);
    DriverProfile::where('user_id', $driver->id)->update(['last_lat' => 25.30, 'last_lng' => 51.50, 'last_fix_at' => now()->subMinutes(10)]);
    $order = lopMove(lopPlace($this->w), FS::OutForDelivery, delivery: DS::OutForDelivery, driver: $driver, deliveryAttrs: ['out_for_delivery_at' => now()->subMinutes(5)]);
    $order->forceFill(['address_snapshot' => ['latitude' => 25.28, 'longitude' => 51.52] + (array) $order->address_snapshot])->save();

    expect(lopCard($order)['title'])->toBe('Arrives at 6:10pm-6:20pm'); // the time-since-pickup estimate, not a 10-minute-old GPS point
});

it('keeps the estimate in the cache for 30 seconds per order', function () {
    $order = lopMove(lopPlace($this->w), FS::Preparing);
    $first = lopCard($order)['eta_to'];
    config(['platform.orders.default_delivery_minutes' => 60]);
    expect(lopCard($order)['eta_to'])->toBe($first);       // cached

    $this->travelTo(now()->addSeconds(31));
    expect(lopCard($order)['eta_to'])->not->toBe($first);  // recalculated
});

// ── Driver ───────────────────────────────────────────────────────────────────────────────────────────────────────────

it('shows the driver only once one is assigned, by first name, with the phone while the delivery is active', function () {
    $driver = driverFor($this->w->a);
    $driver->update(['name' => 'Ahmed Al Marri', 'phone' => '+97455123456']);
    DriverProfile::where('user_id', $driver->id)->update(['vehicle' => 'White Toyota Hilux']);

    $none = lopCard(lopMove(lopPlace($this->w), FS::Ready));
    expect($none)->toMatchArray(['driver_name' => null, 'driver_phone' => null, 'vehicle' => null]);

    $order = lopMove(lopPlace($this->w), FS::Ready, delivery: DS::Assigned, driver: $driver);
    $card = lopCard($order);
    expect($card)->toMatchArray(['driver_name' => 'Ahmed', 'driver_phone' => '+97455123456', 'vehicle' => 'White Toyota Hilux', 'body' => 'Ahmed has been assigned to your order.'])
        ->and(json_encode($card))->not->toContain('Marri');
});

it('names the driver in each stage of the road, in both languages', function (FS $fulfillment, DS $delivery, string $en, string $ar) {
    $driver = driverFor($this->w->a);
    $driver->update(['name' => 'Ahmed Al Marri', 'phone' => '+97455123456']);
    $order = lopMove(lopPlace($this->w), $fulfillment, delivery: $delivery, driver: $driver);

    expect(lopCard($order)['body'])->toBe(str_replace('{branch}', $order->fulfillments->first()->branch->name_en, $en))
        ->and(lopCard($order, 'ar')['body'])->toBe(str_replace('{branch}', $order->fulfillments->first()->branch->name_ar ?: $order->fulfillments->first()->branch->name_en, $ar));
})->with([
    'assigned' => [FS::Ready, DS::Assigned, 'Ahmed has been assigned to your order.', 'تم تعيين السائق Ahmed لطلبك.'],
    'heading to the branch' => [FS::Ready, DS::Accepted, 'Ahmed is heading to {branch} to pick up your order.', 'السائق Ahmed في طريقه إلى {branch} لاستلام طلبك.'],
    'picked up' => [FS::Dispatched, DS::PickedUp, 'Ahmed has picked up your order.', 'استلم السائق Ahmed طلبك.'],
    'out for delivery' => [FS::OutForDelivery, DS::OutForDelivery, 'Ahmed is on the way to you.', 'السائق Ahmed في الطريق إليك.'],
]);

it('takes the driver phone away once the delivery is over', function () {
    $driver = driverFor($this->w->a);
    $driver->update(['name' => 'Ahmed Al Marri', 'phone' => '+97455123456']);
    $order = lopMove(lopPlace($this->w), FS::Delivered, delivery: DS::Delivered, driver: $driver);

    expect(lopCard($order))->toMatchArray(['driver_name' => 'Ahmed', 'driver_phone' => null, 'vehicle' => null]);
});

it('keeps a driver without a valid phone number from breaking the card', function () {
    $driver = driverFor($this->w->a);
    $driver->update(['name' => 'Ahmed', 'phone' => null, 'email' => 'driver@example.test']);
    $order = lopMove(lopPlace($this->w), FS::OutForDelivery, delivery: DS::OutForDelivery, driver: $driver);

    expect(lopCard($order))->toMatchArray(['driver_name' => 'Ahmed', 'driver_phone' => null]);
});

it('says "out for delivery" without a name when a courier company, not a driver, carries the order', function () {
    $order = lopMove(lopPlace($this->w), FS::OutForDelivery, delivery: DS::OutForDelivery);

    expect(lopCard($order))->toMatchArray(['body' => 'Your order is out for delivery.', 'driver_name' => null])
        ->and(lopCard($order, 'ar')['body'])->toBe('طلبك في الطريق إليك.');
});

// ── seq ──────────────────────────────────────────────────────────────────────────────────────────────────────────────

it('stamps every card with the time in milliseconds so a client can drop an update that arrives late', function () {
    $order = lopMove(lopPlace($this->w), FS::Preparing);
    $first = lopCard($order);
    $this->travelTo(now()->addSecond());
    $second = lopCard($order);
    $this->travelTo(now()->addMinutes(3));
    $third = lopCard($order);

    expect($first['seq'])->toBe(now()->subSecond()->subMinutes(3)->getTimestamp() * 1000)
        ->and($second['seq'])->toBeGreaterThan($first['seq'])->and($third['seq'])->toBeGreaterThan($second['seq'])
        ->and($first['updated_at'])->toBe('2026-11-14T15:00:00Z')->and($third['updated_at'])->toBe('2026-11-14T15:03:01Z');
});

// ── Finished orders ──────────────────────────────────────────────────────────────────────────────────────────────────

it('stops showing a finished order 30 minutes after it finished', function () {
    $order = lopMove(lopPlace($this->w), FS::Delivered, ['delivered_at' => now()], DS::Delivered, driverFor($this->w->a));
    $order->forceFill(['status' => OS::Completed->value, 'completed_at' => now()])->save();
    $svc = app(OrderProgress::class);

    expect($svc->build(Order::find($order->id), ['en'])['expired'])->toBeFalse();
    $this->travelTo(now()->addMinutes(29));
    expect($svc->build(Order::find($order->id), ['en'])['expired'])->toBeFalse();
    $this->travelTo(now()->addMinutes(2));
    expect($svc->build(Order::find($order->id), ['en']))->toMatchArray(['done' => true, 'expired' => true]);
});
