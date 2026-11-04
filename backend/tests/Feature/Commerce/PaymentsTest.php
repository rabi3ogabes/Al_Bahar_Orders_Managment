<?php

use App\Domain\Orders\Models\Order;
use App\Domain\Payments\Gateways\SadadChecksum;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\PaymentWebhookEvent;
use App\Domain\Payments\Services\PaymentService;
use App\Domain\Payments\Services\RefundService;
use App\Support\DomainException;
use App\Support\Money;

beforeEach(function () {
    config(['platform.payments.sadad' => array_merge(config('platform.payments.sadad'), [
        'enabled' => true, 'merchant_id' => '1234567', 'secret_key' => 'test-secret', 'website' => 'albahar.qa', 'checkout_url' => 'https://sandbox.sadad.example/webpurchase',
        'status_url' => null, 'capabilities' => ['hosted_checkout' => true, 'status_api' => false, 'refund_api' => false, 'void' => false, 'authorize_capture' => false],
    ])]);
    $this->w = commerceWorld();
    $this->postJson('/api/v1/cart/items', ['variant_id' => $this->w->v1->id, 'quantity' => 1]);
    $this->res = quoteAndPlace(['address_id' => $this->w->address->id, 'payment_method' => 'sadad'])->assertCreated();
    $this->order = Order::find($this->res->json('data.order.id'));
    $this->payment = Payment::find($this->res->json('data.payment.payment_id'));
});

function sadadCallback(array $data): \Illuminate\Testing\TestResponse
{
    $key = urlencode('test-secret').'1234567';
    $data['checksumhash'] = SadadChecksum::generate(json_encode(['postData' => $data, 'secretKey' => urlencode('test-secret')]), $key);

    return test()->post('/webhooks/sadad', $data, ['Accept' => 'application/json']);
}

it('round-trips the SADAD checksum and rejects tampering', function () {
    $key = 'k'.str_repeat('x', 20);
    $sum = SadadChecksum::generate('{"a":1}', $key);
    expect(SadadChecksum::verify('{"a":1}', $key, $sum))->toBeTrue()->and(SadadChecksum::verify('{"a":2}', $key, $sum))->toBeFalse();
});

it('keeps online orders pending and redirects through a signed page — the client cannot mark them paid', function () {
    expect($this->order->status->value)->toBe('pending_payment')->and($this->res->json('data.payment.action'))->toBe('redirect');
    $this->get($this->res->json('data.payment.redirect_url'))->assertOk()->assertSee('sandbox.sadad.example', false)->assertSee('checksumhash', false);
    $this->get('/payments/sadad/'.$this->payment->id.'/start')->assertStatus(410); // unsigned URL refused

    // The browser return URL never changes payment state.
    $this->get('/payments/sadad/return?ORDERID='.$this->payment->id.'&RESPCODE=1')->assertOk();
    expect($this->payment->fresh()->status->value)->toBe('pending');
    $this->getJson('/api/v1/payments/'.$this->payment->id)->assertJsonPath('data.status', 'pending');

    // Staff cannot move unpaid online orders forward either.
    $mgr = staffWithRole('order_manager', $this->w->a);
    expect(fn () => app(\App\Domain\Orders\Services\OrderService::class)->transition($this->order->fulfillments()->first(), \App\Domain\Orders\Enums\FulfillmentStatus::Accepted, $mgr))
        ->toThrow(DomainException::class);
});

it('marks paid from a verified callback, confirms the order and keeps stock reserved', function () {
    sadadCallback(['ORDERID' => $this->payment->id, 'RESPCODE' => '1', 'RESPMSG' => 'Txn Success', 'TXNAMOUNT' => $this->payment->amount, 'transaction_number' => 'SD100'])->assertOk();
    expect($this->payment->fresh()->status->value)->toBe('paid')->and($this->order->fresh()->status->value)->toBe('confirmed')
        ->and($this->order->fresh()->payment_status->value)->toBe('paid')
        ->and(\App\Domain\Inventory\Models\StockReservation::where('status', 'active')->whereNotNull('expires_at')->count())->toBe(0);
});

it('ignores duplicate and out-of-order callbacks', function () {
    $paid = ['ORDERID' => $this->payment->id, 'RESPCODE' => '1', 'TXNAMOUNT' => $this->payment->amount, 'transaction_number' => 'SD200'];
    sadadCallback($paid)->assertOk();
    sadadCallback($paid)->assertOk()->assertJsonPath('status', 'duplicate');
    sadadCallback(['ORDERID' => $this->payment->id, 'RESPCODE' => '227', 'RESPMSG' => 'Failed', 'TXNAMOUNT' => $this->payment->amount, 'transaction_number' => 'SD200'])
        ->assertOk()->assertJsonPath('status', 'ignored_out_of_order');
    expect($this->payment->fresh()->status->value)->toBe('paid')->and(PaymentWebhookEvent::where('signature_valid', true)->count())->toBe(2)
        ->and($this->order->fresh()->paid_total)->toBe($this->payment->amount);
});

it('rejects invalid signatures and amount mismatches', function () {
    $this->post('/webhooks/sadad', ['ORDERID' => $this->payment->id, 'RESPCODE' => '1', 'checksumhash' => 'forged'], ['Accept' => 'application/json'])->assertStatus(400);
    expect($this->payment->fresh()->status->value)->toBe('pending');

    sadadCallback(['ORDERID' => $this->payment->id, 'RESPCODE' => '1', 'TXNAMOUNT' => '1.00', 'transaction_number' => 'SD300'])->assertJsonPath('status', 'amount_mismatch');
    expect($this->payment->fresh()->status->value)->toBe('processing');
});

it('lets the customer retry after a failed payment without duplicating charges', function () {
    sadadCallback(['ORDERID' => $this->payment->id, 'RESPCODE' => '227', 'TXNAMOUNT' => $this->payment->amount, 'transaction_number' => 'SD400'])->assertOk();
    expect($this->payment->fresh()->status->value)->toBe('failed');

    $key = (string) Str::uuid();
    $retry = $this->postJson('/api/v1/orders/'.$this->order->id.'/payments', [], ['Idempotency-Key' => $key])->assertCreated();
    $again = $this->postJson('/api/v1/orders/'.$this->order->id.'/payments', [], ['Idempotency-Key' => $key])->assertCreated();
    expect($retry->json('data.payment_id'))->toBe($again->json('data.payment_id'))->and(Payment::where('order_id', $this->order->id)->count())->toBe(2);

    sadadCallback(['ORDERID' => $retry->json('data.payment_id'), 'RESPCODE' => '1', 'TXNAMOUNT' => $this->payment->amount, 'transaction_number' => 'SD401']);
    expect($this->order->fresh()->status->value)->toBe('confirmed');
});

it('expires unpaid orders after the payment window and releases stock (customer closed the app)', function () {
    $this->travel(31)->minutes();
    expect(app(PaymentService::class)->expireUnpaidOrders())->toBe(1);
    expect($this->order->fresh()->status->value)->toBe('cancelled')->and($this->order->fresh()->payment_status->value)->toBe('expired')
        ->and($this->payment->fresh()->status->value)->toBe('expired')
        ->and(stockOf($this->w->a, $this->w->v1)['reserved'])->toBe(0);
    // A late success callback after expiry is not applied to an expired payment.
    sadadCallback(['ORDERID' => $this->payment->id, 'RESPCODE' => '1', 'TXNAMOUNT' => $this->payment->amount, 'transaction_number' => 'SD500'])->assertOk();
    expect($this->payment->fresh()->status->value)->toBe('expired');
});

it('enforces refund limits with dual approval and manual processing when the provider has no refund API', function () {
    sadadCallback(['ORDERID' => $this->payment->id, 'RESPCODE' => '1', 'TXNAMOUNT' => $this->payment->amount, 'transaction_number' => 'SD600']);
    $requester = staffWithRole('customer_support');
    $approver = staffWithRole('accountant');
    $svc = app(RefundService::class);
    $p = $this->payment->fresh();

    $r1 = $svc->request($p, Money::of('400.00'), 'damaged', 'Scratch on leg', $requester, 'rf-1');
    expect($r1->method)->toBe('manual_bank_transfer');
    expect(fn () => $svc->request($p, Money::of('700.00'), 'other', null, $requester, 'rf-2'))->toThrow(DomainException::class); // 400 + 700 > 1015
    expect(fn () => $svc->approve($r1, $requester))->toThrow(DomainException::class); // maker ≠ checker
    $svc->approve($r1, $approver);
    $svc->markProcessed($r1->fresh(), $approver, 'BANK-REF-77');
    expect($p->fresh()->status->value)->toBe('partially_refunded')->and($this->order->fresh()->refunded_total)->toBe('400.00');

    $rest = Money::of($p->fresh()->captured_amount)->minus(Money::of('400.00'));
    $r2 = $svc->request($p->fresh(), $rest, 'other', null, $requester, 'rf-3');
    $svc->approve($r2, $approver);
    $svc->markProcessed($r2->fresh(), $approver, 'BANK-REF-78');
    expect($p->fresh()->status->value)->toBe('refunded')->and($svc->request($p->fresh(), $rest, 'other', null, $requester, 'rf-3')->id)->toBe($r2->id); // idempotent
});
