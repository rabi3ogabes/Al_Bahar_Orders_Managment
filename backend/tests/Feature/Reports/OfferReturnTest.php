<?php

use App\Domain\Identity\Models\User;
use App\Domain\Marketing\Models\Coupon;
use App\Domain\Marketing\Models\CouponUsage;
use App\Domain\Marketing\Models\Journey;
use App\Domain\Marketing\Models\JourneyDelivery;
use App\Domain\Marketing\Models\JourneyRun;
use App\Domain\Marketing\Models\Promotion;
use App\Domain\Orders\Models\Order;
use App\Domain\Reports\Services\OfferReturn;
use App\Domain\Settings\Services\Settings;
use App\Livewire\Admin\Marketing\OfferReturnPage;
use App\Support\Money;
use Livewire\Livewire;

require_once __DIR__.'/OwnerNumbersSupport.php';

beforeEach(function () {
    $this->world = onWorld();                       // Thursday 19 Nov 2026, midday in Qatar
    $this->returns = app(OfferReturn::class);
    $this->from = '2026-10-01';
    $this->to = '2026-11-19';
    $this->promo = fn (string $name = 'Ten off', array $extra = []) => Promotion::create(['name_en' => $name, 'name_ar' => 'عرض '.$name, 'type' => 'percentage', 'value' => '10', 'scope' => 'order', 'requires_coupon' => true, 'is_active' => true] + $extra);
    $this->coupon = fn (Promotion $p, string $code, ?User $for = null) => Coupon::create(['promotion_id' => $p->id, 'code' => $code, 'assigned_user_id' => $for?->id, 'is_active' => true]);
    // An order of $amount (booked, after discount) that used a coupon worth $discount.
    $this->use = function (Promotion $p, ?Coupon $c, string $amount, string $discount, ?User $user = null, int $daysAgo = 3, array $attrs = []) {
        $user ??= User::factory()->create();
        $order = onOrder([[$this->world->a, $amount]], ['user_id' => $user->id, 'placed_at' => now()->subDays($daysAgo)] + $attrs);
        CouponUsage::create(['coupon_id' => $c?->id, 'promotion_id' => $p->id, 'user_id' => $user->id, 'order_id' => $order->id, 'discount_amount' => $discount, 'status' => 'applied']);

        return $order;
    };
    $this->report = fn () => $this->returns->report($this->from, $this->to);
    $this->offer = fn (string $kind, string $label) => collect(($this->report)()['offers'])->first(fn ($o) => $o['kind'] === $kind && $o['label_en'] === $label);
});

describe('the numbers of one coupon', function () {
    it('counts orders, sales after discount, discount given and the return, with every number defined', function () {
        $p = ($this->promo)();
        $c = ($this->coupon)($p, 'WELCOME10');
        foreach (range(1, 5) as $i) {
            ($this->use)($p, $c, '190.00', '10.00');                // 5 orders of 190.00 after 10.00 off
        }

        $o = ($this->offer)('coupon', 'WELCOME10');

        expect($o['orders'])->toBe(5)->and($o['booked'])->toBe('950.00')->and($o['discount'])->toBe('50.00')->and($o['before_discount'])->toBe('1000.00')
            ->and($o['multiple'])->toBe(19.0)->and($o['discount_rate'])->toBe(5.0)->and($o['verdict'])->toBe('strong')->and($o['new_customers'])->toBe(5)->and($o['returning_customers'])->toBe(0);
    });

    it('separates new customers from returning ones: a first order ever against one who had ordered before', function () {
        $p = ($this->promo)();
        $c = ($this->coupon)($p, 'MIX');
        $old = User::factory()->create();
        onOrder([[$this->world->a, '40.00']], ['user_id' => $old->id, 'placed_at' => now()->subDays(100)]);
        ($this->use)($p, $c, '100.00', '10.00', $old);
        foreach (range(1, 4) as $i) {
            ($this->use)($p, $c, '100.00', '10.00');
        }

        $o = ($this->offer)('coupon', 'MIX');

        expect($o['customers'])->toBe(5)->and($o['new_customers'])->toBe(4)->and($o['returning_customers'])->toBe(1);
    });

    it('does not judge an offer with too few orders: not enough data yet, never a number that means nothing', function () {
        $p = ($this->promo)();
        $c = ($this->coupon)($p, 'RARE');
        ($this->use)($p, $c, '300.00', '100.00');
        ($this->use)($p, $c, '300.00', '100.00');

        $o = ($this->offer)('coupon', 'RARE');

        expect($o['orders'])->toBe(2)->and($o['verdict'])->toBe('not_enough');
        app(Settings::class)->set('offer_return.min_orders', 2);
        expect(($this->offer)('coupon', 'RARE')['verdict'])->toBe('fair')->and(($this->offer)('coupon', 'RARE')['multiple'])->toBe(3.0);        // 600.00 of sales for 200.00 of discount
    });

    it('says "no discount given" when nothing was given away, and calls 2 fair, 5 strong and under 2 weak', function () {
        expect(OfferReturn::verdict(10, Money_zero(), null, 5))->toBe('no_cost')
            ->and(OfferReturn::verdict(10, Money_of('10'), 1.9, 5))->toBe('weak')->and(OfferReturn::verdict(10, Money_of('10'), 2.0, 5))->toBe('fair')
            ->and(OfferReturn::verdict(10, Money_of('10'), 4.9, 5))->toBe('fair')->and(OfferReturn::verdict(10, Money_of('10'), 5.0, 5))->toBe('strong')
            ->and(OfferReturn::verdict(4, Money_of('10'), 9.0, 5))->toBe('not_enough')->and(OfferReturn::verdict(5, Money_of('10'), 9.0, 5))->toBe('strong');
    });

    it('leaves out cancelled orders, orders waiting for online payment, reversed usage and orders outside the dates', function () {
        $p = ($this->promo)();
        $c = ($this->coupon)($p, 'ONLY');
        ($this->use)($p, $c, '100.00', '10.00');
        ($this->use)($p, $c, '100.00', '10.00', null, 3, ['status' => 'cancelled']);
        ($this->use)($p, $c, '100.00', '10.00', null, 3, ['status' => 'pending_payment', 'payment_method' => 'sadad']);
        ($this->use)($p, $c, '100.00', '10.00', null, 200);                                            // long before the window
        $reversed = ($this->use)($p, $c, '100.00', '10.00');
        CouponUsage::where('order_id', $reversed->id)->update(['status' => 'reversed']);

        $o = ($this->offer)('coupon', 'ONLY');

        expect($o['orders'])->toBe(1)->and($o['discount'])->toBe('10.00');
    });

    it('counts a shared code as a coupon and a personal code only under its promotion', function () {
        $p = ($this->promo)('Personal');
        $mine = ($this->coupon)($p, 'BAHAR-AAAAAA', User::factory()->create());
        ($this->use)($p, $mine, '100.00', '10.00');

        expect(($this->offer)('coupon', 'BAHAR-AAAAAA'))->toBeNull()->and(($this->offer)('promotion', 'Personal')['orders'])->toBe(1);
    });
});

describe('promotions, journeys and campaigns', function () {
    it('counts an automatic promotion (no coupon) and every coupon of a promotion together', function () {
        $p = ($this->promo)('Summer', ['requires_coupon' => false]);
        $c = ($this->coupon)($p, 'SUMMER1');
        ($this->use)($p, null, '100.00', '5.00');
        ($this->use)($p, $c, '100.00', '5.00');

        $o = ($this->offer)('promotion', 'Summer');

        expect($o['orders'])->toBe(2)->and($o['discount'])->toBe('10.00')->and($o['booked'])->toBe('200.00');
    });

    it('credits a customer journey with the orders it brought back, and the discount of the coupon its message carried', function () {
        $p = ($this->promo)('Cart 10');
        $c = ($this->coupon)($p, 'BAHAR-CART01');
        $journey = Journey::create(['key' => 'abandoned_cart', 'name' => 'Cart reminder', 'is_enabled' => true]);
        $user = User::factory()->create();
        $order = ($this->use)($p, $c, '90.00', '10.00', $user);
        $run = JourneyRun::create(['journey_id' => $journey->id, 'user_id' => $user->id, 'anchor_at' => now()->subDays(4), 'status' => 'converted', 'order_id' => $order->id, 'revenue' => '90.00']);
        $delivery = JourneyDelivery::create(['run_id' => $run->id, 'user_id' => $user->id, 'channel' => 'push', 'status' => 'sent', 'sent_at' => now()->subDays(4), 'coupon_code' => 'BAHAR-CART01']);
        $run->update(['attributed_delivery_id' => $delivery->id]);
        $noCoupon = User::factory()->create();
        $order2 = onOrder([[$this->world->a, '60.00']], ['user_id' => $noCoupon->id, 'placed_at' => now()->subDays(2)]);
        JourneyRun::create(['journey_id' => $journey->id, 'user_id' => $noCoupon->id, 'anchor_at' => now()->subDays(3), 'status' => 'converted', 'order_id' => $order2->id, 'revenue' => '60.00']);

        $o = ($this->offer)('journey', 'Cart reminder');

        expect($o['orders'])->toBe(2)->and($o['booked'])->toBe('150.00')->and($o['discount'])->toBe('10.00');
    });

    it('credits a campaign with the orders of the customers it was sent to within the window, to the latest campaign only, and records no discount', function () {
        $user = User::factory()->create();
        $first = DB::table('marketing_campaigns');
        $ids = [];
        foreach ([['First', 10], ['Second', 6]] as [$name, $ago]) {
            $id = (string) Str::uuid();
            DB::table('marketing_campaigns')->insert(['id' => $id, 'name' => $name, 'channels' => '["push"]', 'title_en' => 'T', 'title_ar' => 'ع', 'body_en' => 'B', 'body_ar' => 'ب', 'segment' => '{}',
                'status' => 'sent', 'sent_at' => now()->subDays($ago), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('marketing_campaign_recipients')->insert(['campaign_id' => $id, 'user_id' => $user->id, 'state' => 'sent', 'created_at' => now()]);
            $ids[$name] = $id;
        }
        onOrder([[$this->world->a, '120.00']], ['user_id' => $user->id, 'placed_at' => now()->subDays(4)]);               // 2 and 6 days after the two sends
        onOrder([[$this->world->a, '80.00']], ['user_id' => $user->id, 'placed_at' => now()->subDays(12)]);                // before either campaign

        $second = ($this->offer)('campaign', 'Second');

        expect($second['orders'])->toBe(1)->and($second['booked'])->toBe('120.00')->and($second['discount'])->toBe('0.00')->and($second['multiple'])->toBeNull()->and(($this->offer)('campaign', 'First'))->toBeNull();
        app(Settings::class)->set('offer_return.campaign_days', 1);
        expect(($this->offer)('campaign', 'Second'))->toBeNull();                                                          // the order came 2 days after the send
    });

    it('gives the overall return across every discount, with an order that used two offers counted once', function () {
        $p1 = ($this->promo)('One');
        $p2 = ($this->promo)('Two');
        $user = User::factory()->create();
        $order = ($this->use)($p1, null, '100.00', '5.00', $user);
        CouponUsage::create(['promotion_id' => $p2->id, 'user_id' => $user->id, 'order_id' => $order->id, 'discount_amount' => '5.00', 'status' => 'applied']);
        foreach (range(1, 4) as $i) {
            ($this->use)($p1, null, '100.00', '5.00');
        }

        $overall = ($this->report)()['overall'];

        expect($overall['orders'])->toBe(5)->and($overall['booked'])->toBe('500.00')->and($overall['discount'])->toBe('30.00')->and($overall['multiple'])->toBe(16.7);
    });
});

describe('the page', function () {
    it('answers the question first and lists each offer with its verdict, then spells one out', function () {
        $p = ($this->promo)();
        $c = ($this->coupon)($p, 'WELCOME10');
        foreach (range(1, 5) as $i) {
            ($this->use)($p, $c, '190.00', '10.00');
        }
        $this->actingAs($this->world->admin);

        Livewire::test(OfferReturnPage::class)->assertSee(__('offer_return.q'))->assertSeeHtml('data-testid="hero-multiple"')->assertSee('×19')->assertSee('WELCOME10')
            ->assertSee(__('offer_return.verdict_strong'))
            ->call('open', 'coupon:'.$c->id)->assertSet('showOffer', true)->assertSee(__('offer_return.d_net_note'))->assertSee('QAR 1,000.00');
    });

    it('says not enough data when too few orders used a discount', function () {
        $this->actingAs($this->world->admin);

        Livewire::test(OfferReturnPage::class)->assertSee(__('offer_return.not_enough'))->assertSeeHtml('data-verdict="not_enough"')->assertSeeHtml('data-empty="offers"');
    });

    it('opens only an offer the report lists, and is for marketing plus company-wide reports only', function () {
        $this->actingAs($this->world->admin);
        Livewire::test(OfferReturnPage::class)->call('open', 'coupon:not-there')->assertSet('showOffer', false)->assertSet('offer', '');

        $this->actingAs(staffWithRole('branch_manager', $this->world->a));
        Livewire::test(OfferReturnPage::class)->assertForbidden();
        $this->actingAs(staffWithRole('accountant'));
        Livewire::test(OfferReturnPage::class)->assertForbidden();                                        // reports, but no marketing right
        $this->actingAs(staffWithRole('marketing_manager'));
        Livewire::test(OfferReturnPage::class)->assertOk();
    });

    it('changes the minimum orders and the campaign days only with the settings right', function () {
        $this->actingAs($this->world->admin);
        Livewire::test(OfferReturnPage::class)->set('settingsForm.min_orders', '3')->set('settingsForm.campaign_days', '14')->call('saveSettings')->assertHasNoErrors();
        expect($this->returns->minOrders())->toBe(3)->and($this->returns->campaignDays())->toBe(14);
        Livewire::test(OfferReturnPage::class)->set('settingsForm.min_orders', '0')->call('saveSettings')->assertHasErrors('settingsForm.min_orders');

        $this->actingAs(staffWithRole('marketing_manager'));
        Livewire::test(OfferReturnPage::class)->call('saveSettings')->assertForbidden();
    });

    it('says in Arabic what it says in English', function () {
        app()->setLocale('ar');
        $this->actingAs($this->world->admin);

        Livewire::test(OfferReturnPage::class)->assertSee(__('offer_return.q', [], 'ar'))->assertSee(__('offer_return.not_enough', [], 'ar'));
    });
});

function Money_zero(): Money
{
    return Money::zero();
}

function Money_of(string $v): Money
{
    return Money::of($v);
}
