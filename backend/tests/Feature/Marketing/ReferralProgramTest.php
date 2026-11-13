<?php

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Models\UserDevice;
use App\Domain\Marketing\Models\Coupon;
use App\Domain\Marketing\Models\Promotion;
use App\Domain\Marketing\Models\Referral;
use App\Domain\Marketing\Services\LoyaltyService;
use App\Domain\Marketing\Services\ReferralService;
use App\Domain\Notifications\Models\Notification;
use App\Domain\Orders\Models\Order;
use App\Domain\Settings\Services\Settings;
use App\Livewire\Admin\Marketing\ReferralIndex;
use Livewire\Livewire;

function refSet(array $s): void
{
    foreach ($s as $k => $v) {
        app(Settings::class)->set('referrals.'.$k, $v);
    }
}

function refOrder(User $u, array $attrs = []): Order
{
    $order = Order::find(quoteAndPlace(test()->opts)->json('data.order.id'));
    $order->forceFill(['status' => 'completed'] + $attrs)->save();

    return $order->fresh();
}

beforeEach(function () {
    $this->w = commerceWorld();
    $this->postJson('/api/v1/cart/items', ['variant_id' => $this->w->v1->id, 'quantity' => 1]);
    $this->opts = ['address_id' => $this->w->address->id, 'payment_method' => 'cod'];
    $this->referrer = User::factory()->create(['referral_code' => 'ABREFER1']);
    $this->svc = app(ReferralService::class);
});

it('does nothing while the programme is off', function () {
    expect($this->svc->attach($this->w->customer, 'ABREFER1'))->toBeNull()
        ->and($this->getJson('/api/v1/me/referral')->json('data'))->toBe(['enabled' => false]);
});

it('rewards balance to both sides, once, with the reward recorded', function () {
    refSet(['enabled' => true]);
    $ref = $this->svc->attach($this->w->customer, 'abrefer1');
    $order = refOrder($this->w->customer);
    $this->svc->qualify($order);
    $this->svc->qualify($order); // idempotent
    $ref->refresh();
    expect($ref->status)->toBe('rewarded')->and($ref->referrer_reward_type)->toBe('balance')->and($ref->referrer_reward_points)->toBe(500)->and($ref->referrer_reward_value)->toBe('25.00')
        ->and(app(LoyaltyService::class)->account($this->referrer)->fresh()->balance)->toBe(500)
        ->and(app(LoyaltyService::class)->account($this->w->customer)->fresh()->balance)->toBe(250)
        ->and(Notification::where('user_id', $this->referrer->id)->where('event', 'referral.rewarded')->count())->toBe(1);
});

it('issues a personal single-use coupon and a notification in the customer language', function () {
    $promo = Promotion::create(['name_en' => 'Friend 10%', 'name_ar' => 'صديق', 'type' => 'percentage', 'value' => '10', 'scope' => 'order', 'requires_coupon' => true, 'is_active' => true]);
    refSet(['enabled' => true, 'referrer_type' => 'coupon', 'referrer_promotion_id' => $promo->id, 'referrer_prefix' => 'FR', 'friend_type' => 'none']);
    $this->referrer->update(['locale' => 'ar']);
    $this->svc->attach($this->w->customer, 'ABREFER1');
    $this->svc->qualify(refOrder($this->w->customer));
    $coupon = Coupon::where('assigned_user_id', $this->referrer->id)->first();
    expect($coupon->code)->toStartWith('FR-')->and($coupon->usage_limit)->toBe(1)->and(Referral::first()->referrer_coupon_code)->toBe($coupon->code)
        ->and(app(LoyaltyService::class)->account($this->w->customer)->fresh()->balance)->toBe(0)
        ->and(Notification::where('user_id', $this->referrer->id)->value('body'))->toContain($coupon->code)->toContain('الكوبون');
});

it('rejects a first order below the minimum', function () {
    refSet(['enabled' => true, 'min_order_amount' => '99999.00']);
    $ref = $this->svc->attach($this->w->customer, 'ABREFER1');
    $this->svc->qualify(refOrder($this->w->customer));
    expect($ref->fresh())->status->toBe('rejected')->rejection_reason->toBe('below_minimum');
});

it('rejects an order placed after the validity window', function () {
    refSet(['enabled' => true, 'validity_days' => 1]);
    $ref = $this->svc->attach($this->w->customer, 'ABREFER1');
    $ref->forceFill(['created_at' => now()->subDays(5)])->save();
    $this->svc->qualify(refOrder($this->w->customer));
    expect($ref->fresh()->rejection_reason)->toBe('expired');
});

it('stops rewarding a referrer who reached the cap', function () {
    refSet(['enabled' => true, 'max_rewards_per_referrer' => 1]);
    Referral::create(['referrer_id' => $this->referrer->id, 'referee_id' => User::factory()->create()->id, 'code' => 'ABREFER1', 'status' => 'rewarded']);
    $ref = $this->svc->attach($this->w->customer, 'ABREFER1');
    $this->svc->qualify(refOrder($this->w->customer));
    expect($ref->fresh()->rejection_reason)->toBe('referrer_cap_reached');
});

it('blocks self referral, shared devices and refunded orders', function () {
    refSet(['enabled' => true]);
    expect($this->svc->attach($this->referrer, 'ABREFER1'))->toBeNull();
    UserDevice::create(['user_id' => $this->referrer->id, 'device_id' => 'dev-1', 'platform' => 'android', 'push_token' => 'tok-1']);
    $shared = $this->svc->attach(User::factory()->create(), 'ABREFER1', 'dev-1');
    expect($shared->status)->toBe('rejected')->and($shared->rejection_reason)->toBe('shared_device')->and($shared->fraud_flags)->toBe(['shared_device']);

    $ref = $this->svc->attach($this->w->customer, 'ABREFER1');
    $order = refOrder($this->w->customer, ['paid_total' => '1000.00', 'refunded_total' => '1000.00', 'payment_status' => 'refunded']);
    $this->svc->qualify($order);
    expect($ref->fresh()->status)->toBe('pending')->and(app(LoyaltyService::class)->account($this->referrer)->fresh()->balance)->toBe(0);
});

it('exposes the customer payload without personal data of friends', function () {
    refSet(['enabled' => true, 'share_link' => 'https://albahar.qa/app', 'min_order_amount' => '50.00']);
    \Laravel\Sanctum\Sanctum::actingAs($this->referrer, ['customer']);
    $friend = User::factory()->create(['name' => 'Salem Al Kuwari']);
    $this->svc->attach($friend, 'ABREFER1');
    $d = $this->getJson('/api/v1/me/referral')->assertOk()->json('data');
    expect($d['enabled'])->toBeTrue()->and($d['share_url'])->toBe('https://albahar.qa/app?ref=ABREFER1')
        ->and($d['share_message'])->toContain('ABREFER1')->and($d['referrer_reward']['text'])->toContain('first order')->and($d['pending_count'])->toBe(1)
        ->and($d['friends'][0])->toMatchArray(['name' => 'Salem', 'status' => 'pending'])->and(json_encode($d))->not->toContain('Kuwari');
});

it('lets staff toggle, save, validate and reject, and denies customers', function () {
    $admin = staffWithRole('marketing_manager');
    $this->actingAs($admin);
    Livewire::test(ReferralIndex::class)->call('toggle');
    expect($this->svc->enabled())->toBeTrue();
    Livewire::test(ReferralIndex::class)->set('cfg.referrer_amount', '40')->call('saveRewards')->assertHasNoErrors();
    expect($this->svc->reward('referrer')['amount']->toDecimal())->toBe('40.00');
    Livewire::test(ReferralIndex::class)->set('cfg.referrer_type', 'coupon')->set('cfg.referrer_promotion_id', '')->call('saveRewards')->assertHasErrors(['cfg.referrer_promotion_id']);
    Livewire::test(ReferralIndex::class)->set('share.share_link', 'http://insecure.test')->call('saveShare')->assertHasErrors(['share.share_link']);

    $ref = $this->svc->attach($this->w->customer, 'ABREFER1');
    Livewire::test(ReferralIndex::class)->call('askReject', $ref->id)->call('reject')->assertHasErrors(['rejectReason'])
        ->set('rejectReason', 'Suspicious')->call('reject')->assertHasNoErrors();
    expect($ref->fresh())->status->toBe('rejected')->rejected_by->toBe($admin->id);

    $this->actingAs(User::factory()->create());
    Livewire::test(ReferralIndex::class)->assertForbidden();
});
