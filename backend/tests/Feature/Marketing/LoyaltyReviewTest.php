<?php

use App\Domain\Marketing\Models\LoyaltyAccount;
use App\Domain\Marketing\Services\LoyaltyService;
use App\Domain\Orders\Models\Order;
use App\Support\Money;

it('upgrades the tier when referral or staff credits pass a threshold, and takes lifetime points back on a reversal', function () {
    $w = commerceWorld();
    $user = $w->customer;
    $svc = app(LoyaltyService::class);
    $svc->award($user, 10000, 'referral', 'Referral reward');
    expect(LoyaltyAccount::where('user_id', $user->id)->value('tier'))->toBe('silver');

    $this->postJson('/api/v1/cart/items', ['variant_id' => $w->v1->id, 'quantity' => 1]);
    $order = Order::find(quoteAndPlace(['address_id' => $w->address->id, 'payment_method' => 'cod'])->json('data.order.id'));
    $svc->earnForOrder($user, Money::of('1000.00'), $order->id); // 1000 points: 11000 lifetime
    expect(LoyaltyAccount::where('user_id', $user->id)->value('lifetime_earned'))->toBe(11000);
    $svc->reverseForOrder($user, $order->id);
    $acc = LoyaltyAccount::where('user_id', $user->id)->first();
    expect($acc->lifetime_earned)->toBe(10000)->and($acc->tier)->toBe('silver')->and($acc->balance)->toBe(10000);

    $svc->adjust($user, 30000, 'Goodwill', staffWithRole('super_admin'));
    expect(LoyaltyAccount::where('user_id', $user->id)->value('tier'))->toBe('gold');
});
