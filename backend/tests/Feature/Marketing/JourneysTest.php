<?php

use App\Domain\Marketing\Models\Coupon;
use App\Domain\Marketing\Models\Journey;
use App\Domain\Marketing\Models\JourneyDelivery;
use App\Domain\Marketing\Models\JourneyRun;
use App\Domain\Marketing\Models\JourneyStep;
use App\Domain\Marketing\Models\Promotion;
use App\Domain\Marketing\Services\JourneyService;
use App\Domain\Notifications\Models\Notification;
use App\Domain\Notifications\Models\NotificationPreference;
use App\Domain\Notifications\Services\N8nClient;
use App\Domain\Orders\Models\Cart;
use App\Domain\Orders\Models\CartItem;
use App\Domain\Settings\Services\Settings;
use App\Livewire\Admin\Marketing\JourneyIndex;
use App\Support\BusinessTime;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

function abandonedCart($w, int $minutesAgo = 45): Cart
{
    $cart = Cart::create(['user_id' => $w->customer->id, 'status' => 'active', 'last_activity_at' => now()->subMinutes($minutesAgo)]);
    CartItem::create(['cart_id' => $cart->id, 'product_variant_id' => $w->v1->id, 'quantity' => 2]);

    return $cart;
}

function enable(string $key): Journey
{
    app(JourneyService::class)->ensureDefaults();

    return tap(Journey::where('key', $key)->firstOrFail())->update(['is_enabled' => true, 'settings' => ['max_per_day' => 10]]);
}

beforeEach(function () {
    $this->w = commerceWorld();
    $this->w->customer->update(['marketing_consent_at' => now(), 'locale' => 'ar', 'phone' => '+97455123456', 'name' => 'Noor Ali']);
});

it('ships both journeys switched off with starter steps and bilingual templates', function () {
    app(JourneyService::class)->ensureDefaults();
    $cart = Journey::where('key', 'abandoned_cart')->with('steps.template')->first();
    expect($cart->is_enabled)->toBeFalse()->and($cart->steps)->toHaveCount(3)
        ->and($cart->steps->pluck('delay_minutes')->all())->toBe([30, 240, 4320])
        ->and($cart->steps[0]->template->title_ar)->not->toBe('');
    expect(Journey::where('key', 'birthday')->first()->steps->first()->send_time)->toBe('10:00');
});

it('sends the first abandoned-cart step in the customer app language, once', function () {
    enable('abandoned_cart');
    abandonedCart($this->w);
    app(JourneyService::class)->tick();
    app(JourneyService::class)->tick();

    $n = Notification::where('user_id', $this->w->customer->id)->get();
    expect($n)->toHaveCount(1)->and($n[0]->title)->toBe('سلتك بانتظارك')->and($n[0]->body)->toContain('Noor')->toContain('2');
    expect(JourneyRun::count())->toBe(1)->and(JourneyDelivery::where('channel', 'in_app')->count())->toBe(1);
});

it('does not message a customer who has not given marketing consent', function () {
    $this->w->customer->update(['marketing_consent_at' => null]);
    enable('abandoned_cart');
    abandonedCart($this->w);
    app(JourneyService::class)->tick();

    expect(Notification::count())->toBe(0)->and(JourneyDelivery::pluck('reason')->unique()->all())->toBe(['no_marketing_consent']);
});

it('waits for the step delay and stays silent during quiet hours', function () {
    $j = enable('abandoned_cart');
    abandonedCart($this->w, 10);
    app(JourneyService::class)->tick();
    expect(JourneyRun::count())->toBe(0); // first step is at 30 minutes

    Cart::query()->update(['last_activity_at' => now()->subMinutes(45)]);
    $quiet = BusinessTime::now()->subMinutes(5)->format('H:i');
    $j->update(['settings' => ['quiet_start' => $quiet, 'quiet_end' => BusinessTime::now()->addHour()->format('H:i'), 'max_per_day' => 10]]);
    app(JourneyService::class)->tick();
    expect(Notification::count())->toBe(0);
});

it('stops the run and counts the revenue when the customer orders', function () {
    enable('abandoned_cart');
    $this->postJson('/api/v1/cart/items', ['variant_id' => $this->w->v2->id, 'quantity' => 1]);
    Cart::query()->update(['last_activity_at' => now()->subMinutes(45)]);
    app(JourneyService::class)->tick();
    expect(JourneyRun::first()->status)->toBe('active');
    quoteAndPlace(['address_id' => $this->w->address->id, 'payment_method' => 'cod'])->assertCreated();
    $r = app(JourneyService::class)->tick();

    $run = JourneyRun::first();
    expect($r['converted'])->toBe(1)->and($run->status)->toBe('converted')->and($run->order_id)->not->toBeNull()->and((float) $run->revenue)->toBeGreaterThan(0.0);
});

it('stops when the customer comes back to the cart', function () {
    enable('abandoned_cart');
    $cart = abandonedCart($this->w);
    app(JourneyService::class)->tick();
    $cart->update(['last_activity_at' => now()]);
    app(JourneyService::class)->tick();

    expect(JourneyRun::first()->stop_reason)->toBe('customer_returned');
});

it('sends an offer on WhatsApp through n8n with a personal coupon, signed, in the right language', function () {
    Http::fake(['https://n8n.example.com/*' => Http::response(['id' => 'wamid.1'])]);
    app(Settings::class)->set('n8n.enabled', true);
    app(Settings::class)->set('n8n.webhook_url', 'https://n8n.example.com/webhook/bahar');
    app(Settings::class)->set('n8n.secret', 'a-very-long-shared-secret-123', secret: true);
    NotificationPreference::create(['user_id' => $this->w->customer->id, 'channel' => 'whatsapp', 'category' => 'marketing', 'is_enabled' => true]);
    $j = enable('abandoned_cart');
    $promo = Promotion::create(['name_en' => 'Cart 10', 'name_ar' => 'سلة', 'type' => 'percentage', 'value' => '10', 'scope' => 'order', 'requires_coupon' => true, 'is_active' => true]);
    $step = $j->steps()->where('position', 2)->first();
    $step->template->update(['promotion_id' => $promo->id, 'coupon_valid_hours' => 48]);
    abandonedCart($this->w, 300);
    app(JourneyService::class)->tick();

    $d = JourneyDelivery::where('channel', 'whatsapp')->first();
    expect($d->status)->toBe('sent')->and($d->coupon_code)->toStartWith('BAHAR-')->and($d->external_id)->toBe('wamid.1');
    expect(Coupon::where('code', $d->coupon_code)->first())->assigned_user_id->toBe($this->w->customer->id)->usage_limit->toBe(1);
    Http::assertSent(function ($req) use ($d) {
        $body = $req->body();
        $payload = json_decode($body, true);

        return $payload['to'] === '+97455123456' && $payload['locale'] === 'ar' && str_contains($payload['message'], $d->coupon_code) && $payload['delivery_id'] === $d->id
            && $req->header('X-Bahar-Signature')[0] === 'sha256='.N8nClient::sign($req->header('X-Bahar-Timestamp')[0], $body, 'a-very-long-shared-secret-123');
    });
});

it('skips WhatsApp when the customer has not opted in to it', function () {
    $j = enable('abandoned_cart');
    $j->steps()->where('position', 1)->update(['channels' => ['whatsapp']]);
    abandonedCart($this->w);
    app(JourneyService::class)->tick();

    expect(JourneyDelivery::first()->reason)->toBe('no_whatsapp_optin');
});

it('accepts n8n delivery reports only when signed', function () {
    app(Settings::class)->set('n8n.secret', 'a-very-long-shared-secret-123', secret: true);
    enable('abandoned_cart');
    $run = JourneyRun::create(['journey_id' => Journey::first()->id, 'user_id' => $this->w->customer->id, 'anchor_at' => now()]);
    $d = JourneyDelivery::create(['run_id' => $run->id, 'step_id' => JourneyStep::first()->id, 'user_id' => $this->w->customer->id, 'channel' => 'whatsapp', 'status' => 'sent']);
    $body = json_encode(['delivery_id' => $d->id, 'status' => 'delivered', 'external_id' => 'wamid.9']);
    $ts = (string) time();

    $this->call('POST', '/api/v1/integrations/n8n/delivery', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_BAHAR_TIMESTAMP' => $ts, 'HTTP_X_BAHAR_SIGNATURE' => 'sha256=bad'], $body)->assertStatus(401);
    $this->call('POST', '/api/v1/integrations/n8n/delivery', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_BAHAR_TIMESTAMP' => $ts,
        'HTTP_X_BAHAR_SIGNATURE' => 'sha256='.N8nClient::sign($ts, $body, 'a-very-long-shared-secret-123')], $body)->assertOk();

    expect($d->fresh()->status)->toBe('delivered')->and($d->fresh()->external_id)->toBe('wamid.9');
});

it('wishes the customer a happy birthday at the configured time and not twice', function () {
    enable('birthday');
    $now = BusinessTime::now()->setTime(10, 5);
    Carbon::setTestNow($now);
    $this->w->customer->update(['date_of_birth' => $now->subYears(30)->toDateString()]);
    app(JourneyService::class)->tick();
    app(JourneyService::class)->tick();

    $n = Notification::where('user_id', $this->w->customer->id)->get();
    expect($n)->toHaveCount(1)->and($n[0]->title)->toContain('كل عام');
    Carbon::setTestNow();
});

it('waits until the birthday send time', function () {
    enable('birthday');
    $now = BusinessTime::now()->setTime(8, 0);
    Carbon::setTestNow($now);
    $this->w->customer->update(['date_of_birth' => $now->subYears(30)->toDateString()]);
    app(JourneyService::class)->tick();

    expect(Notification::count())->toBe(0)->and(JourneyRun::where('status', 'active')->count())->toBe(1);
    Carbon::setTestNow();
});

it('credits an order to a finished run when it follows a sent message within the window', function () {
    enable('abandoned_cart');
    abandonedCart($this->w);
    app(JourneyService::class)->tick();
    $run = JourneyRun::firstOrFail();
    $run->update(['status' => 'completed']); // every step already ran
    quoteAndPlace(['address_id' => $this->w->address->id, 'payment_method' => 'cod'])->assertCreated();
    $r = app(JourneyService::class)->tick();

    $run->refresh();
    expect($r['converted'])->toBe(1)->and($run->status)->toBe('converted')->and($run->order_id)->not->toBeNull()->and($run->attributedDelivery->channel)->toBe('in_app')
        ->and($run->converted_at)->not->toBeNull();
    $stats = app(JourneyService::class)->recoveryStats(30);
    expect($stats['orders'])->toBe(1)->and($stats['revenue'])->toBeGreaterThan(0.0)->and($stats['by_channel'])->toHaveKey('in_app');
});

it('does not take credit for an order placed before any message reached the customer', function () {
    $j = enable('abandoned_cart');
    $j->update(['settings' => ['quiet_start' => BusinessTime::now()->subHour()->format('H:i'), 'quiet_end' => BusinessTime::now()->addHour()->format('H:i'), 'max_per_day' => 10]]);
    abandonedCart($this->w);
    app(JourneyService::class)->tick(); // run starts, nothing is sent in quiet hours
    quoteAndPlace(['address_id' => $this->w->address->id, 'payment_method' => 'cod'])->assertCreated();
    app(JourneyService::class)->tick();

    $run = JourneyRun::firstOrFail();
    expect($run->status)->toBe('stopped')->and($run->stop_reason)->toBe('ordered_on_their_own')->and((float) $run->revenue)->toBe(0.0)
        ->and(app(JourneyService::class)->recoveryStats(30)['orders'])->toBe(0);
});

it('does not credit an order placed after the attribution window', function () {
    $j = enable('abandoned_cart');
    abandonedCart($this->w);
    app(JourneyService::class)->tick();
    $run = JourneyRun::firstOrFail();
    $run->update(['status' => 'completed']);
    JourneyDelivery::where('run_id', $run->id)->update(['sent_at' => now()->subDays(10)]);
    quoteAndPlace(['address_id' => $this->w->address->id, 'payment_method' => 'cod'])->assertCreated();
    app(JourneyService::class)->tick();

    expect($run->fresh()->status)->toBe('completed')->and(JourneyRun::whereNotNull('order_id')->count())->toBe(0);
});

it('shows recovered orders to the admin and flags the order page', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    enable('abandoned_cart');
    abandonedCart($this->w);
    app(JourneyService::class)->tick();
    JourneyRun::firstOrFail()->update(['status' => 'completed']);
    $res = quoteAndPlace(['address_id' => $this->w->address->id, 'payment_method' => 'cod'])->assertCreated();
    app(JourneyService::class)->tick();
    $order = JourneyRun::firstOrFail()->order;

    $admin = staffWithRole('marketing_manager');
    $this->actingAs($admin);
    Livewire\Livewire::test(JourneyIndex::class, ['tab' => 'recovered'])->assertSee($order->number)->assertSee('Noor');
    $this->get(route('admin.customers.show', $this->w->customer->id))->assertOk()->assertSee(__('journeys_admin.touches_title'));
});

it('lists the messages that were sent, with the customer, on the activity tab', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    enable('abandoned_cart');
    abandonedCart($this->w);
    app(JourneyService::class)->tick();
    expect(JourneyDelivery::count())->toBeGreaterThan(0);

    $this->actingAs(staffWithRole('marketing_manager'));
    Livewire\Livewire::test(JourneyIndex::class, ['tab' => 'activity'])->assertOk()->assertSee('Noor');
});
