<?php

use App\Domain\Auctions\Models\AuctionBid;
use App\Domain\Fulfillment\Models\Delivery;
use App\Domain\Fulfillment\Models\DeliveryProvider;
use App\Domain\Fulfillment\Services\DeliveryService;
use App\Domain\Identity\Models\User;
use App\Domain\Notifications\Models\Notification;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\ReturnRequest;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\Refund;
use App\Domain\Payments\Services\RefundService;
use App\Domain\Settings\Services\Settings;
use App\Support\DomainException;
use App\Support\Money;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/** API audit regressions: each test pins one finding of docs/qa/API_AUDIT.md. */

// ── Auctions ────────────────────────────────────────────────────────────────

it('answers a retried rejected bid with the same rejection, never a 200 replay', function () {
    $w = auctionWorld();
    actingAsCustomer($w->u1);
    $this->postJson("/api/v1/auctions/lots/{$w->lot->id}/bids", ['amount' => '100.00'], ['Idempotency-Key' => 'bid-key-aaaaaaaa-0001'])->assertCreated();
    actingAsCustomer($w->u2);
    $key = ['Idempotency-Key' => 'bid-key-bbbbbbbb-0002'];
    $this->postJson("/api/v1/auctions/lots/{$w->lot->id}/bids", ['amount' => '101.00'], $key)->assertStatus(422)->assertJsonPath('code', 'bid_outbid_min');
    $this->postJson("/api/v1/auctions/lots/{$w->lot->id}/bids", ['amount' => '101.00'], $key)->assertStatus(422)->assertJsonPath('code', 'bid_outbid_min');
    expect(AuctionBid::where('status', 'accepted')->count())->toBe(1);
});

it('rejects malformed or out-of-range money with 422 instead of crashing', function (string $amount) {
    $w = auctionWorld();
    actingAsCustomer($w->u1);
    $this->postJson("/api/v1/auctions/lots/{$w->lot->id}/bids", ['amount' => $amount], ['Idempotency-Key' => 'bid-key-cccccccc-0003'])
        ->assertStatus(422)->assertJsonStructure(['message', 'code', 'errors', 'request_id']);
    $this->postJson("/api/v1/auctions/lots/{$w->lot->id}/auto-bid", ['max_amount' => $amount])->assertStatus(422);
})->with(['1e3', '.5', '99999999999999999']);

// ── Staff list filters ──────────────────────────────────────────────────────

it('answers 422 for array, oversized or malformed staff query filters', function () {
    actingAsStaff(staffWithRole('super_admin'));
    $this->getJson('/api/v1/admin/orders?search[]=a')->assertStatus(422)->assertJsonValidationErrors('search');
    $this->getJson('/api/v1/admin/orders?search='.str_repeat('x', 500))->assertStatus(422);
    $this->getJson('/api/v1/admin/orders?branch_id=not-a-uuid')->assertStatus(422)->assertJsonValidationErrors('branch_id');
    $this->getJson('/api/v1/admin/inventory/stock?status[]=x&filter[]=y')->assertStatus(422);
    $this->getJson('/api/v1/admin/orders?search=Noor')->assertOk();
});

// ── Refunds ─────────────────────────────────────────────────────────────────

/** Places $n COD orders for one customer and marks each payment as captured; returns [[payment, order], …]. */
function capturedPayments(int $n = 1): array
{
    $w = commerceWorld();
    $out = [];
    for ($i = 0; $i < $n; $i++) {
        test()->postJson('/api/v1/cart/items', ['variant_id' => $w->v1->id, 'quantity' => 1]);
        $order = Order::find(quoteAndPlace(['address_id' => $w->address->id, 'payment_method' => 'cod'])->json('data.order.id'));
        $payment = Payment::where('order_id', $order->id)->firstOrFail();
        $payment->forceFill(['status' => 'paid', 'captured_amount' => $payment->amount, 'paid_at' => now()])->save();
        $out[] = [$payment->fresh(), $order];
    }

    return $out;
}

it('never lets a late rejection overwrite an approved refund', function () {
    [[$payment]] = capturedPayments();
    $svc = app(RefundService::class);
    $maker = staffWithRole('customer_support');
    $checker = staffWithRole('accountant');
    $refund = $svc->request($payment, Money::of('10.00'), 'damaged', null, $maker, 'refund-key-0000001');
    $stale = Refund::find($refund->id); // a second approver still looking at the "requested" copy
    $svc->approve($refund, $checker);
    expect(fn () => $svc->reject($stale, $checker, 'too late'))->toThrow(DomainException::class)
        ->and($refund->fresh()->status)->not->toBe('rejected');
});

it('does not leak or reuse a refund of another payment when an idempotency key is reused', function () {
    [[$p1], [$p2]] = capturedPayments(2);
    $svc = app(RefundService::class);
    $maker = staffWithRole('customer_support');
    $r1 = $svc->request($p1, Money::of('10.00'), 'damaged', null, $maker, 'refund-key-0000002');
    expect(fn () => $svc->request($p2, Money::of('5.00'), 'damaged', null, $maker, 'refund-key-0000002'))
        ->toThrow(fn (DomainException $e) => expect($e->errorCode)->toBe('idempotency_key_reused')->and($e->status)->toBe(409));
    expect($svc->request($p1, Money::of('10.00'), 'damaged', null, $maker, 'refund-key-0000002')->id)->toBe($r1->id);
});

it('refuses an over-long or malformed refund Idempotency-Key header with 400', function () {
    [[$payment]] = capturedPayments();
    actingAsStaff(staffWithRole('customer_support'));
    $this->postJson("/api/v1/admin/payments/{$payment->id}/refunds", ['amount' => '5.00', 'reason_code' => 'damaged'], ['Idempotency-Key' => str_repeat('k', 300)])->assertStatus(400);
    $this->postJson("/api/v1/admin/payments/{$payment->id}/refunds", ['amount' => '5.00', 'reason_code' => 'damaged'], ['Idempotency-Key' => 'refund-key-0000003'])->assertCreated();
});

// ── Orders: notes, cancellation, returns ────────────────────────────────────

it('only attaches an internal note to a fulfillment of the same order', function () {
    [[, $order], [, $other]] = capturedPayments(2);
    actingAsStaff(staffWithRole('super_admin'));
    $foreign = $other->fulfillments()->first();
    $this->postJson("/api/v1/admin/orders/{$order->id}/notes", ['body' => 'hello', 'fulfillment_id' => $foreign->id])->assertStatus(422)->assertJsonValidationErrors('fulfillment_id');
    $this->postJson("/api/v1/admin/orders/{$order->id}/notes", ['body' => 'hello', 'fulfillment_id' => $order->fulfillments()->first()->id])->assertCreated();
});

it('creates one cancellation request when the customer taps twice', function () {
    $w = commerceWorld();
    $this->postJson('/api/v1/cart/items', ['variant_id' => $w->v1->id, 'quantity' => 1]);
    $order = Order::find(quoteAndPlace(['address_id' => $w->address->id, 'payment_method' => 'cod'])->json('data.order.id'));
    $order->fulfillments()->update(['status' => 'preparing']); // past auto-approval: needs a staff decision
    $body = ['reason_code' => 'changed_mind'];
    $this->postJson("/api/v1/orders/{$order->id}/cancel", $body)->assertOk()->assertJsonPath('data.status', 'requested');
    $this->postJson("/api/v1/orders/{$order->id}/cancel", $body)->assertStatus(409)->assertJsonPath('code', 'cancellation_pending');
    expect($order->cancellations()->count())->toBe(1);
});

it('never lets return requests claim more than was handed over, in one request or across requests', function () {
    $w = commerceWorld();
    $this->postJson('/api/v1/cart/items', ['variant_id' => $w->v1->id, 'quantity' => 2]);
    $order = Order::find(quoteAndPlace(['address_id' => $w->address->id, 'payment_method' => 'cod'])->json('data.order.id'));
    $order->fulfillments()->update(['status' => 'delivered']);
    $item = $order->items()->first();
    $item->forceFill(['quantity_handed_over' => 2])->save();
    $line = fn (int $q) => ['order_item_id' => $item->id, 'quantity' => $q];
    $ok = ['reason_code' => 'damaged'];

    $this->postJson("/api/v1/orders/{$order->id}/returns", $ok + ['items' => [$line(1), $line(1), $line(1)]])->assertStatus(422); // duplicate lines
    $this->postJson("/api/v1/orders/{$order->id}/returns", $ok + ['items' => [$line(3)]])->assertStatus(422);
    $this->postJson("/api/v1/orders/{$order->id}/returns", $ok + ['items' => [$line(2)]])->assertCreated();
    $this->postJson("/api/v1/orders/{$order->id}/returns", $ok + ['items' => [$line(1)]])->assertStatus(422); // already claimed
    expect(ReturnRequest::where('order_id', $order->id)->count())->toBe(1);
});

// ── Addresses, accounts ─────────────────────────────────────────────────────

it('keeps exactly one default address when another is switched to default through an update', function () {
    $u = actingAsCustomer();
    $mk = fn (string $name, bool $default) => $this->postJson('/api/v1/me/addresses', ['recipient_name' => $name, 'recipient_phone' => '55123456', 'zone_number' => '66',
        'street_number' => '1', 'building_number' => '2', 'is_default' => $default])->assertCreated()->json('data.id');
    $a = $mk('A', true);
    $b = $mk('B', false);
    $this->patchJson("/api/v1/me/addresses/$b", ['is_default' => true])->assertOk();
    expect($u->addresses()->where('is_default', true)->pluck('id')->all())->toBe([$b])->and($a)->not->toBe($b);
});

it('caps the number of saved addresses', function () {
    $u = actingAsCustomer();
    for ($i = 0; $i < 25; $i++) {
        $u->addresses()->create(['recipient_name' => 'N', 'recipient_phone' => '+97455123456', 'zone_number' => '66', 'street_number' => '1', 'building_number' => '2']);
    }
    $this->postJson('/api/v1/me/addresses', ['recipient_name' => 'N', 'recipient_phone' => '55123456', 'zone_number' => '66', 'street_number' => '1', 'building_number' => '2'])->assertStatus(422);
});

it('treats e-mail addresses case-insensitively so one address cannot become two accounts', function () {
    User::factory()->staff()->create(['email' => 'Boss@Albahar.qa']);
    expect(User::where('email', 'boss@albahar.qa')->exists())->toBeTrue();
    $phone = '+97455123499';
    $this->postJson('/api/v1/auth/otp/request', ['phone' => $phone])->assertOk();
    $token = $this->postJson('/api/v1/auth/otp/verify', ['phone' => $phone, 'code' => config('platform.otp.test_code') ?: '000000']);
    config(['platform.otp.test_code' => '123456']);
    $this->postJson('/api/v1/auth/otp/request', ['phone' => '+97455123498'])->assertOk();
    $reg = $this->postJson('/api/v1/auth/otp/verify', ['phone' => '+97455123498', 'code' => '123456'])->assertOk()->json('data.registration_token');
    $this->postJson('/api/v1/auth/register', ['registration_token' => $reg, 'name' => 'Imposter', 'email' => 'BOSS@albahar.qa'])->assertStatus(422)->assertJsonValidationErrors('email');
    expect($token)->not->toBeNull();
});

// ── Rate limiting ───────────────────────────────────────────────────────────

it('counts every spelling of one phone number against the same login bucket', function () {
    RateLimiter::clear('x');
    $spellings = ['+974 5512 3456', '55123456', '0097455123456', '+97455123456', '٥٥١٢٣٤٥٦', '974-5512-3456'];
    foreach ($spellings as $i => $phone) {
        $status = $this->withServerVariables(['REMOTE_ADDR' => '10.9.8.7'])->postJson('/api/v1/auth/login', ['phone' => $phone, 'password' => 'wrong-pass-'.$i])->status();
        expect($status)->toBe($i < 5 ? 401 : 429);
    }
});

it('answers 422, not 500, for non-string identifiers on throttled auth routes', function () {
    $this->postJson('/api/v1/auth/login', ['phone' => ['x'], 'password' => 'abc12345'])->assertStatus(422);
    $this->postJson('/api/v1/auth/otp/request', ['phone' => ['x']])->assertStatus(422);
    $this->postJson('/api/v1/auth/password/reset', ['phone' => ['x'], 'code' => '123456', 'password' => 'abc12345', 'password_confirmation' => 'abc12345'])->assertStatus(422);
});

it('rate limits support tickets', function () {
    actingAsCustomer();
    for ($i = 0; $i < 10; $i++) {
        $this->postJson('/api/v1/me/support/tickets', ['category' => 'other', 'subject' => 'Hi '.$i, 'message' => 'Need help'])->assertCreated();
    }
    $this->postJson('/api/v1/me/support/tickets', ['category' => 'other', 'subject' => 'Spam', 'message' => 'Need help'])->assertStatus(429)->assertJsonPath('code', 'too_many_requests');
});

// ── Webhooks ────────────────────────────────────────────────────────────────

it('ignores a signed provider webhook that carries no delivery reference', function () {
    $w = commerceWorld();
    $this->postJson('/api/v1/cart/items', ['variant_id' => $w->v1->id, 'quantity' => 1]);
    $order = Order::find(quoteAndPlace(['address_id' => $w->address->id, 'payment_method' => 'cod'])->json('data.order.id'));
    $mgr = staffWithRole('order_manager', $w->a);
    $provider = DeliveryProvider::create(['code' => 'mockx', 'name' => 'Mock', 'driver' => 'mock', 'is_active' => true, 'webhook_secret' => 'whsec', 'settings' => ['timeout' => true]]);
    $d = app(DeliveryService::class)->create($order->fulfillments->first(), $mgr, 'provider', $provider->id); // booking timed out: no provider_reference yet
    $d->forceFill(['status' => 'accepted'])->save();
    expect($d->provider_reference)->toBeNull();

    $json = json_encode(['event_id' => 'ev-'.Str::random(6), 'status' => 'picked_up']);
    $this->call('POST', '/webhooks/delivery/mockx', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_MOCK_SIGNATURE' => hash_hmac('sha256', $json, 'whsec')], $json)->assertOk()->assertJsonPath('status', 'ignored');
    expect(Delivery::find($d->id)->status->value)->toBe('accepted');
});

it('rejects an n8n callback with a stale timestamp or a wrong signature', function () {
    app(Settings::class)->set('n8n.secret', 'topsecret', secret: true);
    $body = json_encode(['delivery_id' => (string) Str::uuid(), 'status' => 'sent']);
    $post = fn (string $ts, string $sig) => $this->call('POST', '/api/v1/integrations/n8n/delivery', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_BAHAR_TIMESTAMP' => $ts, 'HTTP_X_BAHAR_SIGNATURE' => $sig], $body);
    $now = (string) time();
    $post($now, 'sha256='.hash_hmac('sha256', "$now.$body", 'wrong'))->assertStatus(401);
    $old = (string) (time() - 4000);
    $post($old, 'sha256='.hash_hmac('sha256', "$old.$body", 'topsecret'))->assertStatus(401);
    $post($now, 'sha256='.hash_hmac('sha256', "$now.$body", 'topsecret'))->assertOk();
});

it('refuses returns after the return window and reports it in can_return', function () {
    $w = commerceWorld();
    $this->postJson('/api/v1/cart/items', ['variant_id' => $w->v1->id, 'quantity' => 1]);
    $order = Order::find(quoteAndPlace(['address_id' => $w->address->id, 'payment_method' => 'cod'])->json('data.order.id'));
    $order->fulfillments()->update(['status' => 'delivered', 'delivered_at' => now()->subDays(20)]);
    $order->items()->update(['quantity_handed_over' => 1]);
    $this->getJson("/api/v1/orders/{$order->id}")->assertJsonPath('data.can_return', false);
    $this->postJson("/api/v1/orders/{$order->id}/returns", ['reason_code' => 'damaged', 'items' => [['order_item_id' => $order->items()->first()->id, 'quantity' => 1]]])->assertStatus(422);
});

it('uses one stable error code for a missing Idempotency-Key on every money endpoint', function () {
    $w = auctionWorld();
    actingAsCustomer($w->u1);
    $this->postJson("/api/v1/auctions/lots/{$w->lot->id}/bids", ['amount' => '100.00'])->assertStatus(400)->assertJsonPath('code', 'idempotency_key_required');
    $this->postJson('/api/v1/orders/'.Str::uuid().'/payments')->assertStatus(400)->assertJsonPath('code', 'idempotency_key_required');
});

it('lists support tickets with the created_at and order_id the storefront and apps read', function () {
    $u = actingAsCustomer();
    $id = $this->postJson('/api/v1/me/support/tickets', ['category' => 'other', 'subject' => 'Hello', 'message' => 'Need help'])->assertCreated()->json('data.id');
    $this->getJson('/api/v1/me/support/tickets')->assertJsonStructure(['data' => [['id', 'number', 'subject', 'status', 'status_label', 'created_at', 'updated_at', 'order_id']]]);
    $this->getJson("/api/v1/me/support/tickets/$id")->assertJsonStructure(['data' => ['id', 'created_at', 'updated_at', 'status_label', 'messages']]);
    expect($u->id)->not->toBeNull();
});

it('allows a partial address update that touches one field only', function () {
    $u = actingAsCustomer();
    $a = $u->addresses()->create(['recipient_name' => 'N', 'recipient_phone' => '+97455123456', 'zone_number' => '66', 'street_number' => '1', 'building_number' => '2']);
    $this->patchJson("/api/v1/me/addresses/{$a->id}", ['label' => 'Home'])->assertOk()->assertJsonPath('data.label', 'Home');
});

// ── Tenant isolation: user A can never read or change user B's data ─────────

it('answers 404 for every customer-owned resource that belongs to someone else', function () {
    $w = commerceWorld();
    $this->postJson('/api/v1/cart/items', ['variant_id' => $w->v1->id, 'quantity' => 1])->assertCreated();
    $cartItem = $this->getJson('/api/v1/cart')->json('data.items.0.id');
    $order = Order::find(quoteAndPlace(['address_id' => $w->address->id, 'payment_method' => 'cod'])->json('data.order.id'));
    $payment = Payment::where('order_id', $order->id)->first();
    $ticket = $this->postJson('/api/v1/me/support/tickets', ['category' => 'other', 'subject' => 'Mine', 'message' => 'Private'])->json('data.id');
    $session = $this->getJson('/api/v1/me/sessions');
    $this->postJson('/api/v1/cart/items', ['variant_id' => $w->v1->id, 'quantity' => 1]);
    $notification = Notification::create(['user_id' => $w->customer->id, 'event' => 'x', 'title' => 't', 'body' => 'b']);

    actingAsCustomer(); // a different customer
    $this->getJson("/api/v1/orders/{$order->id}")->assertNotFound();
    $this->getJson("/api/v1/orders/{$order->id}/timeline")->assertNotFound();
    $this->getJson("/api/v1/orders/{$order->id}/tracking")->assertNotFound();
    $this->getJson("/api/v1/orders/{$order->id}/invoice")->assertNotFound();
    $this->postJson("/api/v1/orders/{$order->id}/cancel", ['reason_code' => 'changed_mind'])->assertNotFound();
    $this->postJson("/api/v1/orders/{$order->id}/reorder")->assertNotFound();
    $this->postJson("/api/v1/orders/{$order->id}/returns", ['reason_code' => 'damaged', 'items' => [['order_item_id' => (string) Str::uuid(), 'quantity' => 1]]])->assertNotFound();
    $this->postJson("/api/v1/orders/{$order->id}/payments", [], ['Idempotency-Key' => 'pay-key-0000000001'])->assertNotFound();
    $this->getJson("/api/v1/payments/{$payment->id}")->assertNotFound();
    $this->getJson("/api/v1/me/addresses/{$w->address->id}")->assertNotFound();
    $this->patchJson("/api/v1/me/addresses/{$w->address->id}", ['label' => 'x'])->assertNotFound();
    $this->deleteJson("/api/v1/me/addresses/{$w->address->id}")->assertNotFound();
    $this->postJson("/api/v1/me/addresses/{$w->address->id}/default")->assertNotFound();
    $this->patchJson("/api/v1/cart/items/$cartItem", ['quantity' => 5])->assertNotFound();
    $this->deleteJson("/api/v1/cart/items/$cartItem")->assertNotFound();
    $this->getJson("/api/v1/me/support/tickets/$ticket")->assertNotFound();
    $this->postJson("/api/v1/me/support/tickets/$ticket/messages", ['body' => 'hi'])->assertNotFound();
    $this->postJson("/api/v1/me/notifications/{$notification->id}/read")->assertOk();
    expect($notification->fresh()->read_at)->toBeNull();
    $this->getJson('/api/v1/me/notifications')->assertJsonCount(0, 'data');
    $this->deleteJson('/api/v1/me/sessions/'.Str::uuid())->assertNotFound();
    expect($session->status())->toBe(200);
});

it('keeps customer tokens out of the staff API and staff tokens out of the customer API', function () {
    actingAsCustomer();
    $this->getJson('/api/v1/admin/me')->assertForbidden();
    $this->getJson('/api/v1/admin/orders')->assertForbidden();
    actingAsStaff(staffWithRole('super_admin'));
    $this->getJson('/api/v1/cart')->assertForbidden();
    $this->getJson('/api/v1/me')->assertForbidden();
});

it('limits a branch manager to the orders of their own branch', function () {
    $w = commerceWorld();
    $this->postJson('/api/v1/cart/items', ['variant_id' => $w->v1->id, 'quantity' => 1]);
    $order = Order::find(quoteAndPlace(['address_id' => $w->address->id, 'payment_method' => 'cod'])->json('data.order.id'));
    $branchOfOrder = $order->fulfillments()->first()->branch_id;
    $other = $branchOfOrder === $w->a->id ? $w->b : $w->a;
    actingAsStaff(staffWithRole('order_manager', $other));
    $this->getJson("/api/v1/admin/orders/{$order->id}")->assertForbidden();
    $this->getJson('/api/v1/admin/orders')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson("/api/v1/admin/orders?branch_id=$branchOfOrder")->assertOk()->assertJsonCount(0, 'data');
    $this->postJson("/api/v1/admin/fulfillments/{$order->fulfillments()->first()->id}/transition", ['status' => 'accepted'])->assertForbidden();
    $this->postJson("/api/v1/admin/orders/{$order->id}/cancel", ['reason_code' => 'x', 'reason' => 'no'])->assertForbidden();
});
