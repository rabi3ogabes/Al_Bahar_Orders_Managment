<?php

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Cms\Models\Banner;
use App\Domain\Cms\Models\HomepageSection;
use App\Domain\Identity\Models\User;
use App\Domain\Marketing\Models\Coupon;
use App\Domain\Marketing\Models\CouponUsage;
use App\Domain\Marketing\Models\LoyaltyTransaction;
use App\Domain\Marketing\Models\Promotion;
use App\Domain\Marketing\Models\Review;
use App\Domain\Marketing\Services\LoyaltyService;
use App\Domain\Marketing\Services\ReferralService;
use App\Domain\Marketing\Services\ReviewService;
use App\Domain\Notifications\Jobs\SendNotificationJob;
use App\Domain\Notifications\Models\Notification;
use App\Domain\Notifications\Models\NotificationLog;
use App\Domain\Notifications\Services\NotificationService;
use App\Domain\Orders\Models\Order;
use App\Domain\Support\Models\SupportTicket;
use App\Domain\Support\Services\SupportService;
use App\Support\DomainException;
use App\Support\Money;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

function promo(array $a = []): Promotion
{
    return Promotion::create(array_merge(['name_en' => 'Promo', 'name_ar' => 'عرض', 'type' => 'percentage', 'value' => '10', 'scope' => 'order', 'requires_coupon' => true, 'is_active' => true], $a));
}

beforeEach(function () {
    $this->w = commerceWorld();
    $this->postJson('/api/v1/cart/items', ['variant_id' => $this->w->v1->id, 'quantity' => 1]); // 1000
    $this->postJson('/api/v1/cart/items', ['variant_id' => $this->w->v2->id, 'quantity' => 2]); // 500
    $this->opts = ['address_id' => $this->w->address->id, 'payment_method' => 'cod'];
});

it('applies a percentage coupon with a maximum discount, allocated across lines', function () {
    Coupon::create(['promotion_id' => promo(['max_discount_amount' => '120.00'])->id, 'code' => 'WELCOME10']);
    $q = $this->postJson('/api/v1/checkout/quote', $this->opts + ['coupon_code' => 'welcome10'])->json('data');
    expect($q['totals']['discount']['amount'])->toBe('120.00')
        ->and(collect($q['lines'])->sum(fn ($l) => (float) $l['discount']['amount']))->toBe(120.0)
        ->and($q['discounts'][0]['code'])->toBe('WELCOME10');
});

it('scopes promotions to categories and branches, and enforces minimum basket', function () {
    $cat = Category::factory()->create();
    $this->w->p2->update(['category_id' => $cat->id]);
    $p = promo(['scope' => 'categories', 'requires_coupon' => false, 'value' => '50']);
    $p->categories()->attach($cat->id);
    $q = $this->postJson('/api/v1/checkout/quote', $this->opts)->json('data');
    expect($q['totals']['discount']['amount'])->toBe('250.00'); // 50% of the 500 in that category only

    $p->update(['min_basket_amount' => '600.00']);
    expect($this->postJson('/api/v1/checkout/quote', $this->opts)->json('data.totals.discount.amount'))->toBe('0.00');
});

it('picks the better of the best non-stackable vs all stackable promotions, and free delivery always combines', function () {
    promo(['requires_coupon' => false, 'type' => 'fixed', 'value' => '100', 'is_stackable' => true, 'priority' => 1]);
    promo(['requires_coupon' => false, 'type' => 'fixed', 'value' => '80', 'is_stackable' => true, 'priority' => 2]);
    promo(['requires_coupon' => false, 'type' => 'percentage', 'value' => '15', 'is_stackable' => false]); // 225 > 180
    promo(['requires_coupon' => false, 'type' => 'free_delivery', 'value' => '0']);
    $q = $this->postJson('/api/v1/checkout/quote', $this->opts)->json('data');
    expect($q['totals']['discount']['amount'])->toBe('240.00') // 225 item + 15 delivery
        ->and($q['totals']['grand_total']['amount'])->toBe('1285.00'); // 1500 - 225 + 15 - 15 + 10 cod
});

it('supports buy-one-get-one on the cheapest eligible units', function () {
    $p = promo(['requires_coupon' => false, 'type' => 'bogo', 'scope' => 'products', 'buy_quantity' => 1, 'get_quantity' => 1, 'get_discount_percent' => '100']);
    $p->products()->attach($this->w->p2->id);
    expect($this->postJson('/api/v1/checkout/quote', $this->opts)->json('data.totals.discount.amount'))->toBe('250.00');
});

it('enforces coupon usage limits per customer at placement and reverses usage on cancellation', function () {
    $c = Coupon::create(['promotion_id' => promo(['per_customer_limit' => 1])->id, 'code' => 'ONCE']);
    $orderId = quoteAndPlace($this->opts + ['coupon_code' => 'ONCE'])->assertCreated()->json('data.order.id');
    expect(CouponUsage::where('status', 'applied')->count())->toBe(1)->and($c->fresh()->times_used)->toBe(1);

    $this->postJson('/api/v1/cart/items', ['variant_id' => $this->w->v1->id, 'quantity' => 1]);
    $q = $this->postJson('/api/v1/checkout/quote', $this->opts + ['coupon_code' => 'ONCE'])->json('data');
    expect(collect($q['warnings'])->pluck('code'))->toContain('coupon_limit_reached')->and($q['totals']['discount']['amount'])->toBe('0.00');

    $this->postJson("/api/v1/orders/{$orderId}/cancel", ['reason_code' => 'changed_mind'])->assertOk();
    expect(CouponUsage::where('status', 'reversed')->count())->toBe(1)->and($c->fresh()->times_used)->toBe(0);
});

it('redeems loyalty points within the cap, earns on completion and reverses on cancellation', function () {
    $loyalty = app(LoyaltyService::class);
    $loyalty->award($this->w->customer, 10000, 'adjust', 'Opening points');
    $q = $this->postJson('/api/v1/checkout/quote', $this->opts + ['loyalty_points' => 99999])->json('data');
    expect($q['loyalty']['points_applied'])->toBe(9000) // 30% of 1500 = 450 QAR / 0.05
        ->and($q['totals']['loyalty_discount']['amount'])->toBe('450.00');

    $orderId = quoteAndPlace($this->opts + ['loyalty_points' => 2000])->assertCreated()->json('data.order.id');
    expect($loyalty->account($this->w->customer)->fresh()->balance)->toBe(8000);
    $this->postJson("/api/v1/orders/{$orderId}/cancel", ['reason_code' => 'changed_mind']);
    expect($loyalty->account($this->w->customer)->fresh()->balance)->toBe(10000);

    // Completion earns once.
    $this->postJson('/api/v1/cart/items', ['variant_id' => $this->w->v2->id, 'quantity' => 1]);
    $order = Order::find(quoteAndPlace($this->opts)->json('data.order.id'));
    $loyalty->earnForOrder($this->w->customer, Money::of('1500.00'), $order->id);
    $loyalty->earnForOrder($this->w->customer, Money::of('1500.00'), $order->id);
    expect(LoyaltyTransaction::where('order_id', $order->id)->where('type', 'earn')->count())->toBe(1);
});

it('rewards referrals only after the referee completes a first order, and blocks self/shared-device referrals', function () {
    app(\App\Domain\Settings\Services\Settings::class)->set('referrals.enabled', true);
    $referrer = User::factory()->create(['referral_code' => 'ABREFER1']);
    $svc = app(ReferralService::class);
    expect($svc->attach($referrer, 'ABREFER1'))->toBeNull(); // self
    $ref = $svc->attach($this->w->customer, 'abrefer1');
    expect($ref->status)->toBe('pending');

    $order = Order::find(quoteAndPlace($this->opts)->json('data.order.id'));
    $order->forceFill(['status' => 'completed'])->save();
    $svc->qualify($order);
    expect($ref->fresh()->status)->toBe('rewarded')
        ->and(app(LoyaltyService::class)->account($referrer)->fresh()->balance)->toBe(500)
        ->and(app(LoyaltyService::class)->account($this->w->customer)->fresh()->balance)->toBe(250);
});

it('respects marketing consent and channel opt-outs, while transactional in-app always works', function () {
    Queue::fake();
    $svc = app(NotificationService::class);
    $u = $this->w->customer;
    $u->update(['marketing_consent_at' => null]);
    expect($svc->notify($u, 'campaign', ['title' => 'Sale', 'body' => 'Up to 30%']))->toBe([]);
    $u->update(['marketing_consent_at' => now()]);
    expect($svc->notify($u->fresh(), 'campaign', ['title' => 'Sale', 'body' => 'Up to 30%']))->toBe(['push', 'in_app']);

    $this->putJson('/api/v1/me/notification-preferences', ['preferences' => [['channel' => 'push', 'category' => 'transactional', 'is_enabled' => false]]])->assertOk();
    expect($svc->notify($u->fresh(), 'order.out_for_delivery', ['order_number' => 'AB1']))->toBe(['in_app', 'sms']);
    Queue::assertPushed(SendNotificationJob::class);
});

it('renders bilingual templates and queues external channels with retry/backoff logging', function () {
    $u = $this->w->customer;
    $u->update(['locale' => 'ar']);
    app(NotificationService::class)->notify($u->fresh(), 'order.ready_for_pickup', ['order_number' => 'AB777']);
    $n = Notification::where('user_id', $u->id)->first();
    expect($n->body)->toContain('AB777')->toContain('جاهز');
    $log = NotificationLog::where('channel', 'sms')->first();
    expect($log->status)->toBe('skipped') // null SMS driver: never logs content
        ->and((new SendNotificationJob($log->id, 's', 'b'))->backoff())->toBe([10, 60, 300, 900]);

    $this->getJson('/api/v1/me/notifications')->assertOk()->assertJsonPath('meta.unread_count', 1);
    $this->postJson('/api/v1/me/notifications/read-all')->assertOk();
    $this->getJson('/api/v1/me/notifications')->assertJsonPath('meta.unread_count', 0);
});

it('notifies customers on order status changes', function () {
    $orderId = quoteAndPlace($this->opts)->json('data.order.id');
    expect(Notification::where('event', 'order.created')->where('user_id', $this->w->customer->id)->exists())->toBeTrue();
    $this->postJson("/api/v1/orders/{$orderId}/cancel", ['reason_code' => 'changed_mind']);
    expect(Notification::where('event', 'order.cancelled')->exists())->toBeTrue();
});

it('serves the admin-managed homepage with scheduled banners only within their window', function () {
    HomepageSection::create(['type' => 'banners', 'layout' => 'hero', 'status' => 'published', 'sort_order' => 0]);
    HomepageSection::create(['type' => 'featured_products', 'layout' => 'carousel', 'status' => 'published', 'sort_order' => 1, 'title_en' => 'Curated', 'title_ar' => 'مختارات']);
    HomepageSection::create(['type' => 'new_arrivals', 'status' => 'draft', 'sort_order' => 2]);
    $this->w->p1->update(['is_featured' => true]);
    Banner::create(['image_en_path' => 'https://cdn.example/b1.jpg', 'status' => 'published', 'starts_at' => now()->addDay(), 'title_en' => 'Eid']);

    $home = $this->getJson('/api/v1/home?branch_id='.$this->w->a->id, ['Accept-Language' => 'ar'])->assertOk()->json('data.sections');
    expect(collect($home)->pluck('type')->all())->toBe(['featured_products'])->and($home[0]['title'])->toBe('مختارات');

    Cache::flush();
    $this->travel(2)->days();
    expect(collect($this->getJson('/api/v1/home')->json('data.sections'))->pluck('type')->all())->toBe(['banners', 'featured_products']);
});

it('keeps internal support notes private and limits support actions to authorized staff', function () {
    $svc = app(SupportService::class);
    $t = $this->postJson('/api/v1/me/support/tickets', ['category' => 'delivery', 'subject' => 'Late delivery', 'message' => 'Where is my sofa?'])->assertCreated()->json('data');
    $ticket = SupportTicket::find($t['id']);
    expect($ticket->priority)->toBe('high');
    $agent = staffWithRole('customer_support');
    $svc->staffReply($ticket, $agent, 'Customer called twice', internal: true);
    $svc->staffReply($ticket, $agent, 'Your delivery is scheduled for tomorrow.');
    $messages = $this->getJson("/api/v1/me/support/tickets/{$t['id']}")->json('data.messages');
    expect(collect($messages)->pluck('body')->all())->not->toContain('Customer called twice')->and($ticket->fresh()->first_response_at)->not->toBeNull();
    expect(fn () => $svc->staffReply($ticket, staffWithRole('pickup_employee', $this->w->a), 'hi'))->toThrow(DomainException::class);
});

it('accepts verified-purchase reviews into moderation and updates ratings when approved', function () {
    $this->postJson("/api/v1/products/{$this->w->p1->id}/reviews", ['rating' => 5, 'title' => 'Beautiful <b>sofa</b>'])->assertCreated()->assertJsonPath('data.verified_purchase', false);
    $this->postJson("/api/v1/products/{$this->w->p1->id}/reviews", ['rating' => 4])->assertStatus(409);
    $review = Review::first();
    expect($review->title)->toBe('Beautiful sofa');
    $this->getJson("/api/v1/products/{$this->w->p1->id}/reviews")->assertJsonCount(0, 'data');
    app(ReviewService::class)->moderate($review, staffWithRole('marketing_manager'), 'approved');
    expect($this->w->p1->fresh()->rating_count)->toBe(1)->and((float) $this->w->p1->fresh()->rating_average)->toBe(5.0);
});

it('discounts only the targeted products, whatever else is in the basket', function () {
    $p = promo(['scope' => 'products', 'requires_coupon' => false, 'value' => '20']);
    $p->products()->attach($this->w->p1->id);
    $q = $this->postJson('/api/v1/checkout/quote', $this->opts)->json('data');
    $lines = collect($q['lines'])->keyBy('variant_id');

    expect($q['totals']['discount']['amount'])->toBe('200.00')                       // 20% of the 1000 sofa only
        ->and($lines[$this->w->v1->id]['discount']['amount'])->toBe('200.00')
        ->and($lines[$this->w->v2->id]['discount']['amount'])->toBe('0.00');

    $p->products()->attach($this->w->p2->id);                                          // add the lamp to the target list
    expect($this->postJson('/api/v1/checkout/quote', $this->opts)->json('data.totals.discount.amount'))->toBe('300.00');

    $p->products()->detach();                                                          // nothing targeted → nothing eligible
    expect($this->postJson('/api/v1/checkout/quote', $this->opts)->json('data.totals.discount.amount'))->toBe('0.00');
});

it('rejects a product-scoped coupon when the basket has none of its products, and caps fixed amounts at the eligible value', function () {
    $other = Product::factory()->create(['price' => '75.00']);
    $p = promo(['scope' => 'products', 'value' => '800', 'type' => 'fixed']);
    $p->products()->attach($other->id);
    Coupon::create(['promotion_id' => $p->id, 'code' => 'ONLYOTHER']);

    $q = $this->postJson('/api/v1/checkout/quote', $this->opts + ['coupon_code' => 'ONLYOTHER'])->json('data');
    expect($q['totals']['discount']['amount'])->toBe('0.00')->and(collect($q['warnings'])->pluck('code'))->toContain('coupon_not_eligible');

    $p->products()->sync([$this->w->p2->id]);                                          // the lamps (2 × 250 = 500) are in the basket
    $q = $this->postJson('/api/v1/checkout/quote', $this->opts + ['coupon_code' => 'ONLYOTHER'])->json('data');
    expect($q['totals']['discount']['amount'])->toBe('500.00');                        // QAR 800 off, but only 500 is eligible
});

it('combines product targeting with a branch restriction', function () {
    $p = promo(['scope' => 'products', 'requires_coupon' => false, 'value' => '20']);
    $p->products()->attach($this->w->p1->id);
    $p->branches()->attach($this->w->b->id);                                           // the basket is fulfilled from branch A
    expect($this->postJson('/api/v1/checkout/quote', $this->opts)->json('data.totals.discount.amount'))->toBe('0.00');

    $p->branches()->attach($this->w->a->id);
    expect($this->postJson('/api/v1/checkout/quote', $this->opts)->json('data.totals.discount.amount'))->toBe('200.00');
});
