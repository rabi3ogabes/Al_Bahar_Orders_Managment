<?php

use App\Domain\Fulfillment\Services\PickupService;
use App\Domain\Identity\Models\User;
use App\Domain\Orders\Models\Order;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\Refund;
use App\Livewire\Admin\Finance\PaymentShow;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->w = commerceWorld();
    $this->admin = User::factory()->superAdmin()->create();
});

function qaPaidOrder(object $w): Order
{
    actingAsCustomer($w->customer);
    test()->postJson('/api/v1/cart/items', ['variant_id' => $w->v1->id, 'quantity' => 1]);
    $order = Order::with('fulfillments')->findOrFail(quoteAndPlace(['fulfillment_type' => 'pickup', 'branch_id' => $w->a->id, 'payment_method' => 'pay_at_pickup'])->json('data.order.id'));
    $staff = staffWithRole('pickup_employee', $w->a);
    $svc = app(PickupService::class);
    $svc->handover($svc->markReady($order->fulfillments->first(), $staff), $staff, [], 'Noor', collection: ['tender' => 'cash', 'amount' => $order->grand_total, 'receipt_reference' => 'R-1']);

    return $order->fresh();
}

it('answers an unparseable refund amount with a toast, not a raw 422 error popup', function (string $amount) {
    $payment = Payment::where('order_id', qaPaidOrder($this->w)->id)->firstOrFail();
    $this->actingAs($this->admin);

    Livewire::test(PaymentShow::class, ['payment' => $payment])
        ->call('openRefund')
        ->set('refund.amount', $amount)->set('refund.reason_code', 'goodwill')
        ->call('requestRefund')
        ->assertDispatched('toast', type: 'error', message: __('errors.invalid_amount'));

    expect(Refund::count())->toBe(0);
})->with(['five decimals' => '12.34567', 'exponent' => '1e3']);

it('keeps a four-decimal point value and the settings limits when saving loyalty rules from the loyalty screen', function () {
    $this->actingAs($this->admin);
    $settings = app(App\Domain\Settings\Services\Settings::class);

    Livewire::test(App\Livewire\Admin\Marketing\LoyaltyIndex::class)
        ->set('rules.points_per_qar', '250')->set('rules.redeem_value_per_point', '0.0125')->set('rules.points_expiry_days', '730')
        ->call('saveRules')->assertHasNoErrors();

    expect($settings->get('loyalty.redeem_value_per_point'))->toBe('0.0125')->and($settings->get('loyalty.points_per_qar'))->toBe(250);
    Livewire::test(App\Livewire\Admin\Marketing\LoyaltyIndex::class)->set('rules.points_expiry_days', '0')->call('saveRules')->assertHasErrors(['rules.points_expiry_days']);
    Livewire::test(App\Livewire\Admin\Organization\SettingsIndex::class, ['tab' => 'loyalty'])->set('values.loyalty.loyalty__points_expiry_days', '0')->call('save')->assertHasErrors(['values.loyalty.loyalty__points_expiry_days']);
});
