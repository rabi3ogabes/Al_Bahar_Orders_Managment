<?php

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Models\UserDevice;
use App\Domain\Notifications\Models\Notification;
use App\Domain\Notifications\Services\NotificationCatalog;
use App\Domain\Notifications\Services\NotificationRules;
use App\Domain\Operations\Services\OrderAlerts;
use App\Domain\Orders\Events\OrderPlaced;
use App\Domain\Orders\Events\OrderStatusChanged;
use App\Domain\Orders\Models\Order;
use App\Domain\Payments\Gateways\SadadChecksum;
use App\Domain\Payments\Models\Payment;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

require_once __DIR__.'/OpsSupport.php';
require_once __DIR__.'/../Commerce/LiveOrderHelpers.php';

/**
 * "A new order for your branch": push + in-app to the staff who can accept orders there, once, never for an unpaid online order, never with customer data.
 * Real checkout through the API, Firebase and SADAD faked at the HTTP edge or by their signed callback.
 */
beforeEach(function () {
    lopFreeze();
    $this->w = commerceWorld();
    lopFcm();
    $this->rules = app(NotificationRules::class);
    $this->mgr = staffWithRole('branch_manager', $this->w->a);
    $this->desk = staffWithRole('order_manager', $this->w->a);
    $this->otherBranch = staffWithRole('order_manager', $this->w->b);
    $this->driverOnly = opsStaffWith($this->w->a, ['deliveries.view', 'deliveries.edit']);
    $this->admin = User::factory()->superAdmin()->create();
    foreach (['mgr', 'desk', 'otherBranch', 'driverOnly', 'admin'] as $who) {
        UserDevice::create(['user_id' => $this->{$who}->id, 'platform' => 'android', 'push_token' => 'tok-'.$who, 'locale' => 'en']);
    }
});

function opsPlace(string $type = 'delivery', string $method = 'cod', int $qty = 1): Order
{
    actingAsCustomer(test()->w->customer);
    test()->postJson('/api/v1/cart/items', ['variant_id' => test()->w->v2->id, 'quantity' => $qty])->assertCreated();
    $options = $type === 'pickup'
        ? ['fulfillment_type' => 'pickup', 'branch_id' => test()->w->a->id, 'payment_method' => 'pay_at_pickup']
        : ['address_id' => test()->w->address->id, 'payment_method' => $method];

    return Order::with('fulfillments')->findOrFail(quoteAndPlace($options)->assertCreated()->json('data.order.id'));
}

/** @return list<string> push tokens that got an alert of this event */
function opsPushed(string $event = 'staff.order_new'): array
{
    return collect(lopSent(all: true))->filter(fn (array $m) => ($m['data']['event'] ?? null) === $event)->pluck('token')->sort()->values()->all();
}

function opsInbox(string $event = 'staff.order_new'): array
{
    return Notification::where('event', $event)->orderBy('user_id')->get()->pluck('user_id')->all();
}

it('alerts the people who can accept orders at the branch, by push and in the inbox, the moment a cash order is placed', function () {
    $order = opsPlace('delivery', 'cod', 3);

    expect(opsPushed())->toBe(['tok-desk', 'tok-mgr'])
        ->and(collect(opsInbox())->sort()->values()->all())->toBe(collect([$this->mgr->id, $this->desk->id])->sort()->values()->all());
    $entry = Notification::where('user_id', $this->desk->id)->where('event', 'staff.order_new')->firstOrFail();
    expect($entry->title)->toBe('New order '.$order->number)->and($entry->body)->toBe('3 items to prepare at your branch. Open the app to accept it.')
        ->and($entry->category)->toBe('transactional')->and($entry->data['deep_link'])->toContain($order->id);
    $message = collect(lopSent(all: true))->firstWhere('token', 'tok-desk');
    expect($message['notification']['title'])->toBe('New order '.$order->number)->and($message['data'])->toMatchArray(['event' => 'staff.order_new', 'order_id' => $order->id,
        'number' => $order->number, 'branch_id' => $this->w->a->id, 'fulfillment_id' => $order->fulfillments->first()->id, 'notification_id' => $entry->id]);
});

it('never puts customer details in the push or the inbox text', function () {
    $order = opsPlace();
    $customer = $this->w->customer;
    $texts = collect(lopSent(all: true))->map(fn ($m) => json_encode($m))->implode(' ').Notification::where('event', 'staff.order_new')->get()->map(fn ($n) => $n->title.' '.$n->body.' '.json_encode($n->data))->implode(' ');
    foreach ([$customer->name, $customer->phone, $order->customer_name, $order->customer_phone, 'Noor', '+97455123456'] as $private) {
        if ($private) {
            expect($texts)->not->toContain((string) $private);
        }
    }
});

it('alerts for a pay-at-pickup order too, and each branch of a split order for its own part', function () {
    $pickup = opsPlace('pickup');
    expect(opsPushed())->toBe(['tok-desk', 'tok-mgr']);

    $order = opsPlace('delivery', 'cod');
    // a second branch part, as a split order has it: its team is told about its own part once
    $partB = (string) Str::uuid();
    DB::table('order_fulfillments')->insert(['id' => $partB, 'order_id' => $order->id, 'branch_id' => $this->w->b->id, 'number' => $order->number.'-2', 'type' => 'delivery', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('order_items')->insert(['id' => (string) Str::uuid(), 'order_id' => $order->id, 'order_fulfillment_id' => $partB, 'branch_id' => $this->w->b->id, 'sku' => 'X', 'name_en' => 'X', 'name_ar' => 'X',
        'unit_price' => '1.00', 'unit_final_price' => '1.00', 'quantity' => 2, 'line_subtotal' => '2.00', 'line_total' => '2.00', 'created_at' => now(), 'updated_at' => now()]);
    $before = count(opsInbox());
    expect(app(OrderAlerts::class)->announceNew($order->id))->toBe(1);       // branch A's part was already announced; B's is new
    expect(Notification::where('user_id', $this->otherBranch->id)->where('event', 'staff.order_new')->firstOrFail()->body)->toStartWith('2 items')
        ->and(count(opsInbox()))->toBe($before + 1);
});

it('does not alert twice for the same order, however often the event is replayed', function () {
    $order = opsPlace();
    $inbox = count(opsInbox());
    $pushes = count(opsPushed());
    event(new OrderPlaced($order->id));
    event(new OrderPlaced($order->id));
    app(OrderAlerts::class)->announceNew($order->id);
    expect(count(opsInbox()))->toBe($inbox)->and(count(opsPushed()))->toBe($pushes)
        ->and(DB::table('ops_reminders')->where('fulfillment_id', $order->fulfillments->first()->id)->where('level', 'new')->count())->toBe(1);
});

it('waits for an online payment: nothing for an unpaid order, one alert when it is paid, none for a repeated callback', function () {
    opsConfigureSadad();
    $order = opsPlace('delivery', 'sadad');
    expect($order->status->value)->toBe('pending_payment')->and(opsPushed())->toBe([])->and(opsInbox())->toBe([]);

    $payment = Payment::where('order_id', $order->id)->firstOrFail();
    $callback = fn () => opsSadadCallback(['ORDERID' => $payment->id, 'RESPCODE' => '1', 'RESPMSG' => 'Txn Success', 'TXNAMOUNT' => $payment->amount, 'transaction_number' => 'SD100']);
    $callback();
    expect(Order::findOrFail($order->id)->status->value)->toBe('confirmed')->and(opsPushed())->toBe(['tok-desk', 'tok-mgr']);
    $callback();                                                                                              // the gateway calls again
    event(new OrderStatusChanged($order->id, 'pending_payment', 'confirmed'));                                // and the event is replayed
    expect(opsPushed())->toBe(['tok-desk', 'tok-mgr'])->and(count(opsInbox()))->toBe(2);
});

it('ignores order status changes that are not "paid"', function () {
    $order = opsPlace();
    $inbox = count(opsInbox());
    DB::table('ops_reminders')->truncate();
    foreach ([['confirmed', 'processing'], ['pending_payment', 'cancelled'], ['processing', 'confirmed']] as [$from, $to]) {
        event(new OrderStatusChanged($order->id, $from, $to));
    }
    expect(DB::table('ops_reminders')->count())->toBe(0)->and(count(opsInbox()))->toBe($inbox);
});

it('stays silent for a part that is no longer waiting, and for an order that is gone', function () {
    $order = opsPlace();
    DB::table('ops_reminders')->truncate();
    DB::table('order_fulfillments')->where('order_id', $order->id)->update(['status' => 'cancelled']);
    $inbox = count(opsInbox());
    expect(app(OrderAlerts::class)->announceNew($order->id))->toBe(0)->and(count(opsInbox()))->toBe($inbox)->and(app(OrderAlerts::class)->announceNew((string) Str::uuid()))->toBe(0);
});

it('follows the admin\'s notification controls: the whole alert, push alone, or the inbox alone', function () {
    $this->rules->set('staff.order_new', false);
    opsPlace();
    expect(opsPushed())->toBe([])->and(opsInbox())->toBe([]);
    $this->rules->reset('staff.order_new');

    $this->rules->set('staff.order_new', true, ['push' => false]);
    opsPlace();
    expect(opsPushed())->toBe([])->and(count(opsInbox()))->toBe(2);
    $this->rules->reset('staff.order_new');

    $this->rules->set('staff.order_new', true, ['in_app' => false]);
    $before = count(opsInbox());
    opsPlace();
    expect(opsPushed())->toBe(['tok-desk', 'tok-mgr'])->and(count(opsInbox()))->toBe($before);
});

it('is on by default for push and the inbox, listed with the other staff alerts, and offers no other channel', function () {
    expect($this->rules->enabled('staff.order_new'))->toBeTrue()->and($this->rules->channelEnabled('staff.order_new', 'push'))->toBeTrue()
        ->and($this->rules->channelEnabled('staff.order_new', 'in_app'))->toBeTrue()->and($this->rules->channelEnabled('staff.order_new', 'sms'))->toBeFalse()
        ->and($this->rules->supportedChannels('staff.order_new'))->toBe(['push', 'in_app'])->and($this->rules->supportedChannels('staff.order_reminder'))->toBe(['push', 'in_app'])
        ->and($this->rules->supportedChannels('staff.order_ready'))->toBe(['push'])                         // the older staff pushes are unchanged
        ->and(NotificationCatalog::defaultChannels('staff.order_new'))->toBe(['push', 'in_app'])
        ->and(NotificationRules::GROUPS['staff'])->toContain('staff.order_new', 'staff.order_reminder');
    $this->rules->set('staff.order_new', true, ['sms' => true, 'whatsapp' => true]);       // a channel it does not have is ignored
    expect($this->rules->state('staff.order_new')['customized'])->toBeFalse();
});

it('writes the alert in the language of each phone and each person', function () {
    UserDevice::where('user_id', $this->desk->id)->update(['locale' => 'ar']);
    $this->mgr->update(['locale' => 'ar']);
    $order = opsPlace();
    $desk = collect(lopSent(all: true))->firstWhere('token', 'tok-desk');
    expect($desk['notification']['title'])->toBe('طلب جديد '.$order->number)->and($desk['notification']['body'])->toBe('قطعة واحدة للتجهيز في فرعك. افتح التطبيق لقبول الطلب.');
    expect(Notification::where('user_id', $this->mgr->id)->where('event', 'staff.order_new')->firstOrFail()->title)->toBe('طلب جديد '.$order->number)
        ->and(Notification::where('user_id', $this->desk->id)->where('event', 'staff.order_new')->firstOrFail()->title)->toBe('New order '.$order->number);
});

it('does not alert suspended staff, staff of another branch, or people who cannot accept orders', function () {
    $this->desk->update(['status' => 'suspended']);
    opsPlace();
    expect(opsPushed())->toBe(['tok-mgr'])->and(opsInbox())->toBe([$this->mgr->id]);
});

it('takes the claim back when sending fails, so a retry sends it', function () {
    $order = opsPlace();
    DB::table('ops_reminders')->truncate();
    Notification::where('event', 'staff.order_new')->delete();
    Event::listen('eloquent.creating: '.Notification::class, fn () => throw new RuntimeException('inbox down'));
    expect(fn () => app(OrderAlerts::class)->announceNew($order->id))->toThrow(RuntimeException::class);
    expect(DB::table('ops_reminders')->count())->toBe(0);
    Event::forget('eloquent.creating: '.Notification::class);
    app(OrderAlerts::class)->announceNew($order->id);
    expect(count(opsInbox()))->toBe(2)->and(DB::table('ops_reminders')->count())->toBe(1);
});

it('keeps the alert for each part once in the table, with the part removed when it is deleted', function () {
    $order = opsPlace();
    expect(DB::table('ops_reminders')->count())->toBe(1);
    expect(fn () => DB::transaction(fn () => DB::table('ops_reminders')->insert(['fulfillment_id' => $order->fulfillments->first()->id, 'level' => 'new', 'sent_at' => now()])))->toThrow(QueryException::class);
    DB::table('order_fulfillments')->where('id', $order->fulfillments->first()->id)->delete();
    expect(DB::table('ops_reminders')->count())->toBe(0);
});

// SADAD, as the existing notification tests drive it: configured, and the gateway's signed callback.
function opsConfigureSadad(): void
{
    config(['platform.payments.sadad' => array_merge(config('platform.payments.sadad'), [
        'enabled' => true, 'merchant_id' => '1234567', 'secret_key' => 'test-secret', 'website' => 'albahar.qa', 'checkout_url' => 'https://sandbox.sadad.example/webpurchase',
        'status_url' => null, 'capabilities' => ['hosted_checkout' => true, 'status_api' => false, 'refund_api' => false, 'void' => false, 'authorize_capture' => false],
    ])]);
}

function opsSadadCallback(array $data): void
{
    $key = urlencode('test-secret').'1234567';
    $data['checksumhash'] = SadadChecksum::generate(json_encode(['postData' => $data, 'secretKey' => urlencode('test-secret')]), $key);
    test()->post('/webhooks/sadad', $data, ['Accept' => 'application/json'])->assertOk();
}
