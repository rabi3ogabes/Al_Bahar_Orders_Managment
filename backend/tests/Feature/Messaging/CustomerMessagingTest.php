<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Branches\Models\Branch;
use App\Domain\Identity\Models\User;
use App\Domain\Marketing\Models\Coupon;
use App\Domain\Marketing\Models\Journey;
use App\Domain\Marketing\Models\JourneyDelivery;
use App\Domain\Marketing\Models\JourneyRun;
use App\Domain\Marketing\Models\JourneyTemplate;
use App\Domain\Marketing\Models\Promotion;
use App\Domain\Marketing\Services\JourneyService;
use App\Domain\Messaging\Data\AudienceSelection;
use App\Domain\Messaging\Data\SendRequest;
use App\Domain\Messaging\Jobs\SendCustomerMessageJob;
use App\Domain\Messaging\Models\CustomerMessage;
use App\Domain\Messaging\Models\MessageBatch;
use App\Domain\Messaging\Services\ContactHistory;
use App\Domain\Messaging\Services\CustomerMessaging;
use App\Domain\Messaging\Services\Eligibility;
use App\Domain\Notifications\Models\Notification;
use App\Domain\Notifications\Models\NotificationPreference;
use App\Domain\Notifications\Services\N8nClient;
use App\Domain\Notifications\Services\NotificationService;
use App\Domain\Orders\Models\Cart;
use App\Domain\Orders\Models\CartItem;
use App\Domain\Settings\Services\Settings;
use App\Support\DomainException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

const N8N_SECRET = 'a-very-long-shared-secret-123';

function connectN8n(): void
{
    $s = app(Settings::class);
    $s->set('n8n.enabled', true);
    $s->set('n8n.webhook_url', 'https://n8n.example.com/webhook/bahar');
    $s->set('n8n.secret', N8N_SECRET, secret: true);
}

function optedInCustomer(array $attrs = []): User
{
    $u = User::factory()->create($attrs + ['marketing_consent_at' => now(), 'phone' => '+9745'.random_int(5000000, 5999999), 'locale' => 'en', 'name' => 'Noor Ali']);
    NotificationPreference::create(['user_id' => $u->id, 'channel' => 'whatsapp', 'category' => 'marketing', 'is_enabled' => true]);

    return $u;
}

function withCart(User $u): Cart
{
    $w = commerceWorld();
    $cart = Cart::create(['user_id' => $u->id, 'status' => 'active', 'last_activity_at' => now()->subHour()]);
    CartItem::create(['cart_id' => $cart->id, 'product_variant_id' => $w->v1->id, 'quantity' => 2]);

    return $cart;
}

function composed(array $ids, array $over = []): SendRequest
{
    $content = ['title_en' => 'Hello {{name}}', 'title_ar' => 'مرحباً {{name}}', 'body_en' => 'A note for {{name}}', 'body_ar' => 'رسالة إلى {{name}}',
        'whatsapp_en' => '', 'whatsapp_ar' => '', 'deep_link' => 'albahar://home', 'coupon' => ['enabled' => false]];

    return new SendRequest(AudienceSelection::ofIds($ids), $over['channels'] ?? ['notification'], $over['kind'] ?? 'custom', ($over['content'] ?? []) + $content,
        $over['template'] ?? null, $over['scheduleAt'] ?? null, $over['ignoreCooldown'] ?? false);
}

beforeEach(function () {
    $this->staff = staffWithRole('marketing_manager');
    $this->svc = app(CustomerMessaging::class);
});

it('sends a notification to one customer and keeps the history', function () {
    $c = optedInCustomer();
    $batch = $this->svc->send($this->staff, composed([$c->id]));

    $m = CustomerMessage::where('user_id', $c->id)->first();
    expect($batch->status)->toBe('done')->and($batch->source)->toBe('single')->and($batch->sent_count)->toBe(1)->and($batch->completed_at)->not->toBeNull()
        ->and($m->status)->toBe('sent')->and($m->title)->toBe('Hello Noor')->and($m->sent_at)->not->toBeNull()
        ->and($batch->created_by)->toBe($this->staff->id)->and($batch->template_name)->toBe('Custom message');
    $n = Notification::where('user_id', $c->id)->first();
    expect($n->title)->toBe('Hello Noor')->and($n->category)->toBe('marketing')->and($n->data['deep_link'])->toBe('albahar://home');
    expect(AuditLog::where('action', 'customer_messages.sent')->count())->toBe(1);
});

it('uses each customer app language', function () {
    $ar = optedInCustomer(['locale' => 'ar', 'name' => 'Sara Ali']);
    $this->svc->send($this->staff, composed([$ar->id]));

    expect(Notification::where('user_id', $ar->id)->value('title'))->toBe('مرحباً Sara');
});

it('saves customers who cannot be messaged as skipped, with the reason', function () {
    $no = optedInCustomer(['marketing_consent_at' => null]);
    $ok = optedInCustomer();
    $batch = $this->svc->send($this->staff, composed([$no->id, $ok->id]));

    expect(CustomerMessage::where('user_id', $no->id)->first())->status->toBe('skipped')->reason->toBe('no_marketing_consent');
    expect($batch->recipients_count)->toBe(2)->and($batch->sent_count)->toBe(1)->and($batch->skipped_count)->toBe(1)->and($batch->source)->toBe('bulk')
        ->and(Notification::where('user_id', $no->id)->exists())->toBeFalse();
});

it('shows a plan with the reasons before anything is sent', function () {
    $ok = optedInCustomer();
    $noConsent = optedInCustomer(['marketing_consent_at' => null, 'name' => 'Omar K']);
    $noWa = User::factory()->create(['marketing_consent_at' => now(), 'phone' => '+97455111222']);
    connectN8n();

    $plan = $this->svc->plan($this->staff, composed([$ok->id, $noConsent->id, $noWa->id], ['channels' => ['notification', 'whatsapp']]));

    expect($plan->total)->toBe(3)->and($plan->reachable)->toBe(2)->and($plan->channels['whatsapp'])->toBe(['ok' => 1, 'skipped' => 2])
        ->and($plan->channels['notification'])->toBe(['ok' => 2, 'skipped' => 1])->and($plan->reasons)->toBe(['no_marketing_consent' => 1])
        ->and($plan->examples[0]['name'])->toBe('Omar K')->and(CustomerMessage::count())->toBe(0)->and(MessageBatch::count())->toBe(0);
});

it('sends WhatsApp through n8n, signed, with the message id to report back on', function () {
    connectN8n();
    Http::fake(['https://n8n.example.com/*' => Http::response(['id' => 'wamid.9'])]);
    $c = optedInCustomer();
    $batch = $this->svc->send($this->staff, composed([$c->id], ['channels' => ['whatsapp'], 'content' => ['whatsapp_en' => "Hi {{name}} 👋\nOur store: {{app_link}}"]]));

    $m = CustomerMessage::first();
    expect($m->status)->toBe('sent')->and($m->external_id)->toBe('wamid.9')->and($m->body)->toStartWith("Hi Noor 👋\nOur store: http");
    Http::assertSent(function ($req) use ($m, $c) {
        $body = $req->body();
        $p = json_decode($body, true);

        return $p['event'] === 'direct.whatsapp' && $p['delivery_id'] === $m->id && $p['to'] === $c->phone && $p['customer']['name'] === 'Noor'
            && str_ends_with($p['callback_url'], '/api/v1/integrations/n8n/delivery') && $p['coupon'] === null
            && $req->header('X-Bahar-Signature')[0] === 'sha256='.N8nClient::sign($req->header('X-Bahar-Timestamp')[0], $body, N8N_SECRET);
    });
    expect($batch->fresh()->status)->toBe('done');
});

it('falls back to the title and message when no WhatsApp text is written', function () {
    connectN8n();
    Http::fake(['https://n8n.example.com/*' => Http::response(['id' => 'x'])]);
    $c = optedInCustomer();
    $this->svc->send($this->staff, composed([$c->id], ['channels' => ['whatsapp']]));

    expect(CustomerMessage::first()->body)->toBe("Hello Noor\nA note for Noor");
});

it('refuses WhatsApp until the automation is connected', function () {
    $c = optedInCustomer();

    expect(fn () => $this->svc->send($this->staff, composed([$c->id], ['channels' => ['whatsapp']])))->toThrow(DomainException::class, 'not connected');
    expect(MessageBatch::count())->toBe(0);
});

it('marks a WhatsApp message failed when n8n rejects it, and retries only the failed ones', function () {
    connectN8n();
    Http::fake(['https://n8n.example.com/*' => Http::sequence()->push([], 500)->push(['id' => 'wamid.2'])]);
    $c = optedInCustomer();
    $batch = $this->svc->send($this->staff, composed([$c->id], ['channels' => ['whatsapp']]));

    expect(CustomerMessage::first())->status->toBe('failed')->reason->toBe('n8n_http_500');
    expect($batch->fresh())->failed_count->toBe(1)->status->toBe('done');

    expect($this->svc->retryFailed($this->staff, $batch->fresh()))->toBe(1);
    expect(CustomerMessage::first())->status->toBe('sent')->reason->toBeNull();
    expect($batch->fresh())->failed_count->toBe(0)->sent_count->toBe(1)->status->toBe('done');
    expect($this->svc->retryFailed($this->staff, $batch->fresh()))->toBe(0);
});

it('records what n8n reports back, ignores late older reports, and rejects forged ones', function () {
    connectN8n();
    Http::fake(['https://n8n.example.com/*' => Http::response(['id' => 'wamid.3'])]);
    $c = optedInCustomer();
    $batch = $this->svc->send($this->staff, composed([$c->id], ['channels' => ['whatsapp']]));
    $m = CustomerMessage::first();

    $post = function (array $data, ?string $sig = null) {
        $body = json_encode($data);
        $ts = (string) time();

        return $this->call('POST', '/api/v1/integrations/n8n/delivery', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_BAHAR_TIMESTAMP' => $ts,
            'HTTP_X_BAHAR_SIGNATURE' => $sig ?? 'sha256='.N8nClient::sign($ts, $body, N8N_SECRET)], $body);
    };
    $post(['delivery_id' => $m->id, 'status' => 'delivered'], 'sha256=forged')->assertStatus(401);
    expect($m->fresh()->status)->toBe('sent');

    $post(['delivery_id' => $m->id, 'status' => 'delivered', 'external_id' => 'wamid.3'])->assertOk()->assertJson(['ok' => true]);
    expect($m->fresh())->status->toBe('delivered')->delivered_at->not->toBeNull();
    expect($batch->fresh())->delivered_count->toBe(1)->sent_count->toBe(1);

    $post(['delivery_id' => $m->id, 'status' => 'sent'])->assertOk(); // older report arriving late
    expect($m->fresh()->status)->toBe('delivered');

    $post(['delivery_id' => (string) Str::uuid(), 'status' => 'delivered'])->assertOk()->assertJson(['ok' => false]);
});

it('issues one personal coupon per customer, shared by both channels', function () {
    connectN8n();
    Http::fake(['https://n8n.example.com/*' => Http::response(['id' => 'w'])]);
    $promo = Promotion::create(['name_en' => 'Ten', 'name_ar' => 'عشرة', 'type' => 'percentage', 'value' => '10', 'scope' => 'order', 'requires_coupon' => true, 'is_active' => true]);
    $c = optedInCustomer();
    $batch = $this->svc->send($this->staff, composed([$c->id], ['channels' => ['notification', 'whatsapp'], 'kind' => 'offer', 'content' => [
        'body_en' => 'Use {{coupon}} before {{expires}}', 'body_ar' => 'استخدم {{coupon}} قبل {{expires}}',
        'coupon' => ['enabled' => true, 'promotion_id' => $promo->id, 'prefix' => 'EID', 'valid_hours' => 48]]]));

    $coupons = Coupon::where('assigned_user_id', $c->id)->get();
    expect($coupons)->toHaveCount(1)->and($coupons[0]->code)->toStartWith('EID-')->and($coupons[0]->usage_limit)->toBe(1)
        ->and($coupons[0]->ends_at->between(now()->addHours(47), now()->addHours(49)))->toBeTrue();
    $code = $coupons[0]->code;
    expect(CustomerMessage::pluck('coupon_code')->unique()->all())->toBe([$code]);
    expect(Notification::first()->body)->toContain($code)->and(Notification::first()->data['coupon'])->toBe($code);
    Http::assertSent(fn ($r) => str_contains(json_decode($r->body(), true)['message'], $code) && json_decode($r->body(), true)['coupon']['code'] === $code);

    // running the customer's job again must not issue a second coupon
    CustomerMessage::where('batch_id', $batch->id)->update(['status' => 'queued']);
    $batch->forceFill(['status' => 'sending'])->save();
    SendCustomerMessageJob::dispatchSync($batch->id, $c->id);
    expect(Coupon::where('assigned_user_id', $c->id)->count())->toBe(1);
});

it('validates the texts: unknown placeholders, coupon wording, length, markup', function () {
    $c = optedInCustomer();
    $send = fn (array $content, array $over = []) => fn () => $this->svc->send($this->staff, composed([$c->id], ['content' => $content] + $over));

    expect($send(['body_en' => 'Hi {{nmae}}']))->toThrow(ValidationException::class);
    expect($send(['body_en' => 'Use {{coupon}}']))->toThrow(ValidationException::class);          // placeholder but no coupon
    expect($send(['title_en' => str_repeat('a', 66)]))->toThrow(ValidationException::class);
    expect($send(['body_ar' => str_repeat('ب', 241)]))->toThrow(ValidationException::class);
    expect($send(['deep_link' => 'https://evil.example.com']))->toThrow(ValidationException::class);
    expect($send(['title_en' => '', 'title_ar' => '']))->toThrow(ValidationException::class);
    expect(MessageBatch::count())->toBe(0);

    $this->svc->send($this->staff, composed([$c->id], ['content' => ['title_en' => '<b>Hi</b><script>x()</script> {{name}}', 'deep_link' => 'albahar://products/123']]));
    expect(Notification::first()->title)->toBe('Hix() Noor')->and(Notification::first()->data['deep_link'])->toBe('albahar://products/123');
});

it('requires the coupon to be shown on an offer, and an active promotion', function () {
    $c = optedInCustomer();
    $off = Promotion::create(['name_en' => 'Old', 'name_ar' => 'قديم', 'type' => 'percentage', 'value' => '5', 'scope' => 'order', 'requires_coupon' => true, 'is_active' => false]);
    $on = Promotion::create(['name_en' => 'New', 'name_ar' => 'جديد', 'type' => 'percentage', 'value' => '5', 'scope' => 'order', 'requires_coupon' => true, 'is_active' => true]);
    $coupon = fn (Promotion $p) => ['enabled' => true, 'promotion_id' => $p->id, 'prefix' => 'BAHAR', 'valid_hours' => 24];

    expect(fn () => $this->svc->send($this->staff, composed([$c->id], ['kind' => 'offer', 'content' => ['coupon' => $coupon($on)]])))->toThrow(ValidationException::class);
    expect(fn () => $this->svc->send($this->staff, composed([$c->id], ['kind' => 'offer', 'content' => ['body_en' => 'Code {{coupon}}', 'body_ar' => 'الرمز {{coupon}}', 'coupon' => $coupon($off)]])))
        ->toThrow(ValidationException::class);
});

it('keeps the recently-contacted guard, and lets staff send anyway to one customer only', function () {
    $c = optedInCustomer();
    $d = optedInCustomer();
    $this->svc->send($this->staff, composed([$c->id, $d->id]));

    $again = $this->svc->send($this->staff, composed([$c->id, $d->id]));
    expect(CustomerMessage::where('batch_id', $again->id)->pluck('reason')->unique()->all())->toBe(['recently_contacted']);
    expect(Notification::count())->toBe(2);

    $forced = $this->svc->send($this->staff, composed([$c->id], ['ignoreCooldown' => true]));
    expect($forced->sent_count)->toBe(1)->and(Notification::where('user_id', $c->id)->count())->toBe(2);

    $bulk = $this->svc->send($this->staff, composed([$c->id, $d->id], ['ignoreCooldown' => true])); // ignored: bulk always keeps the guard
    expect($bulk->sent_count)->toBe(0)->and($bulk->skipped_count)->toBe(2);
});

it('counts automatic journey messages as contact, and the journeys step aside for manual ones', function () {
    $c = optedInCustomer();
    $journey = Journey::create(['key' => 'abandoned_cart', 'name' => 'x', 'is_enabled' => true]);
    $run = JourneyRun::create(['journey_id' => $journey->id, 'user_id' => $c->id, 'anchor_at' => now()->subHour()]);
    JourneyDelivery::create(['run_id' => $run->id, 'user_id' => $c->id, 'channel' => 'push', 'locale' => 'en', 'status' => 'sent', 'sent_at' => now()->subHours(2)]);
    expect($this->svc->plan($this->staff, composed([$c->id]))->reasons)->toBe(['recently_contacted' => 1]);

    JourneyDelivery::query()->delete();
    $this->svc->send($this->staff, composed([$c->id]));
    expect(app(ContactHistory::class)->recentManualMessage($c->id, 24))->toBeTrue()->and(app(ContactHistory::class)->recentManualMessage($c->id, 0))->toBeFalse();
});

it('the quiet period setting changes the guard, and 0 switches it off', function () {
    $c = optedInCustomer();
    $this->svc->send($this->staff, composed([$c->id]));
    CustomerMessage::query()->update(['sent_at' => now()->subHours(30)]);
    expect($this->svc->plan($this->staff, composed([$c->id]))->reachable)->toBe(1); // 30 h ago is outside the default 24 h

    app(Settings::class)->set('messaging.cooldown_hours', 48);
    expect($this->svc->plan($this->staff, composed([$c->id]))->reachable)->toBe(0);
    app(Settings::class)->set('messaging.cooldown_hours', 0);
    expect($this->svc->plan($this->staff, composed([$c->id]))->reachable)->toBe(1);
});

it('sends an order reminder only to customers with something in their cart', function () {
    $with = optedInCustomer();
    $without = optedInCustomer();
    withCart($with);
    $batch = $this->svc->send($this->staff, composed([$with->id, $without->id], ['kind' => 'reminder', 'content' => ['body_en' => 'You left {{items}} item(s): {{first_item}}', 'body_ar' => 'لديك {{items}}: {{first_item}}']]));

    expect(CustomerMessage::where('user_id', $without->id)->first())->status->toBe('skipped')->reason->toBe('no_open_cart');
    expect(Notification::where('user_id', $with->id)->first()->body)->toStartWith('You left 2 item(s): ');
    expect($batch->sent_count)->toBe(1);
});

it('honours the notification and WhatsApp preferences like the rest of the platform', function () {
    $notify = app(NotificationService::class);
    $elig = app(Eligibility::class);
    foreach ([null, true, false] as $pref) {
        foreach (['in_app', 'push', 'whatsapp'] as $channel) {
            foreach ([true, false] as $consent) {
                $u = User::factory()->create(['marketing_consent_at' => $consent ? now() : null]);
                if ($pref !== null) {
                    NotificationPreference::create(['user_id' => $u->id, 'channel' => $channel, 'category' => 'marketing', 'is_enabled' => $pref]);
                }
                $mine = $consent && $elig->allowed($elig->preferences([(string) $u->id]), (string) $u->id, $channel);
                expect($mine)->toBe($notify->allowed($u, $channel, 'marketing'), 'pref='.var_export($pref, true)." channel=$channel consent=".var_export($consent, true));
            }
        }
    }
});

it('skips a customer who turned notifications off, and one with only push and no phone to notify', function () {
    $off = optedInCustomer();
    foreach (['in_app', 'push'] as $ch) {
        NotificationPreference::create(['user_id' => $off->id, 'channel' => $ch, 'category' => 'marketing', 'is_enabled' => false]);
    }
    $pushOnly = optedInCustomer();
    NotificationPreference::create(['user_id' => $pushOnly->id, 'channel' => 'in_app', 'category' => 'marketing', 'is_enabled' => false]);
    $this->svc->send($this->staff, composed([$off->id, $pushOnly->id]));

    expect(CustomerMessage::where('user_id', $off->id)->first())->reason->toBe('opted_out');
    expect(CustomerMessage::where('user_id', $pushOnly->id)->first())->status->toBe('skipped')->reason->toBeIn(['no_devices', 'push_not_configured']);
});

it('skips WhatsApp for customers without opt-in or phone', function () {
    connectN8n();
    Http::fake(['https://n8n.example.com/*' => Http::response(['id' => 'x'])]);
    $noOptin = User::factory()->create(['marketing_consent_at' => now(), 'phone' => '+97455000111']);
    $noPhone = optedInCustomer(['phone' => null]);
    $this->svc->send($this->staff, composed([$noOptin->id, $noPhone->id], ['channels' => ['whatsapp']]));

    expect(CustomerMessage::where('user_id', $noOptin->id)->value('reason'))->toBe('no_whatsapp_optin')->and(CustomerMessage::where('user_id', $noPhone->id)->value('reason'))->toBe('no_phone');
    Http::assertNothingSent();
});

it('schedules a send, sends nothing early, then sends when due, checking consent again', function () {
    $c = optedInCustomer();
    $d = optedInCustomer();
    $at = CarbonImmutable::now()->addHours(2);
    $batch = $this->svc->send($this->staff, composed([$c->id, $d->id], ['scheduleAt' => $at]));

    expect($batch->status)->toBe('scheduled')->and($batch->scheduled_at->timestamp === $at->timestamp)->toBeTrue()->and(Notification::count())->toBe(0)
        ->and(CustomerMessage::where('status', 'queued')->count())->toBe(2)->and($this->svc->dispatchDue())->toBe(['started' => 0, 'resumed' => 0]);
    expect(app(ContactHistory::class)->recentlyContacted([$c->id], 24))->toBe([]); // a waiting schedule is not "contacted"

    $d->update(['marketing_consent_at' => null]); // withdrawn before it goes out
    $this->travel(3)->hours();
    expect($this->svc->dispatchDue())->toBe(['started' => 1, 'resumed' => 0]);

    $batch->refresh();
    expect($batch->status)->toBe('done')->and($batch->sent_count)->toBe(1)->and($batch->skipped_count)->toBe(1)->and($batch->started_at)->not->toBeNull();
    expect(CustomerMessage::where('user_id', $d->id)->value('reason'))->toBe('no_marketing_consent');
    expect($this->svc->dispatchDue())->toBe(['started' => 0, 'resumed' => 0]);
});

it('rejects a schedule in the past or too far ahead', function () {
    $c = optedInCustomer();

    expect(fn () => $this->svc->send($this->staff, composed([$c->id], ['scheduleAt' => CarbonImmutable::now()->subMinute()])))->toThrow(ValidationException::class);
    expect(fn () => $this->svc->send($this->staff, composed([$c->id], ['scheduleAt' => CarbonImmutable::now()->addDays(61)])))->toThrow(ValidationException::class);
});

it('cancels a scheduled send and never sends it', function () {
    $c = optedInCustomer();
    $batch = $this->svc->send($this->staff, composed([$c->id], ['scheduleAt' => CarbonImmutable::now()->addHour()]));

    $cancelled = $this->svc->cancel($this->staff, $batch);
    expect($cancelled->status)->toBe('cancelled')->and(CustomerMessage::first()->status)->toBe('cancelled');
    $this->travel(2)->hours();
    expect($this->svc->dispatchDue()['started'])->toBe(0)->and(Notification::count())->toBe(0);
    expect(fn () => $this->svc->cancel($this->staff, $cancelled))->toThrow(DomainException::class);
});

it('stops a bulk send above the limit and lets the limit be changed', function () {
    app(Settings::class)->set('messaging.bulk_max', 2);
    $ids = collect(range(1, 3))->map(fn () => optedInCustomer()->id)->all();

    expect(fn () => $this->svc->send($this->staff, composed($ids)))->toThrow(DomainException::class, 'at most 2');
    $plan = $this->svc->plan($this->staff, composed($ids));
    expect($plan->overCap())->toBeTrue()->and($plan->total)->toBe(3);
    expect(MessageBatch::count())->toBe(0);
});

it('resolves "everyone matching the filters" with the people un-ticked left out', function () {
    $a = optedInCustomer(['name' => 'Aisha Match']);
    $b = optedInCustomer(['name' => 'Bilal Match']);
    optedInCustomer(['name' => 'Other Person']);
    $sel = AudienceSelection::matching(['search' => 'Match'], [$b->id]);

    expect($this->svc->audienceQuery($this->staff, $sel)->pluck('id')->all())->toBe([$a->id]);
    expect($this->svc->audienceQuery($this->staff, AudienceSelection::matching(['consent' => 'yes']))->count())->toBe(3);
    expect($this->svc->audienceQuery($this->staff, AudienceSelection::matching(['cart' => 'yes']))->count())->toBe(0);
    withCart($a);
    expect($this->svc->audienceQuery($this->staff, AudienceSelection::matching(['cart' => 'yes']))->pluck('id')->all())->toBe([$a->id]);
    $this->svc->send($this->staff, composed([$a->id]));
    $others = User::where('type', 'customer')->count() - 1; // everyone except the one just messaged
    expect($this->svc->audienceQuery($this->staff, AudienceSelection::matching(['contact' => 'never']))->count())->toBe($others)
        ->and($this->svc->audienceQuery($this->staff, AudienceSelection::matching(['contact' => '24h']))->count())->toBe($others)
        ->and($this->svc->audienceQuery($this->staff, AudienceSelection::matching(['contact' => '30d']))->count())->toBe($others);
});

it('lets only team members with notification rights send, and only to customers they may see', function () {
    $c = optedInCustomer();
    $support = staffWithRole('customer_support');
    expect($this->svc->canSend($support))->toBeFalse();
    expect(fn () => $this->svc->send($support, composed([$c->id])))->toThrow(DomainException::class);
    expect(fn () => $this->svc->plan($support, composed([$c->id])))->toThrow(DomainException::class);

    $branch = Branch::factory()->create();
    $branchStaff = staffWithRole('order_manager', $branch);
    expect($this->svc->audienceQuery($branchStaff, AudienceSelection::ofIds([$c->id]))->count())->toBe(0); // never ordered from their branch
    expect($this->svc->audienceQuery($this->staff, AudienceSelection::ofIds([$c->id]))->count())->toBe(1);
    expect(fn () => $this->svc->send($this->staff, composed([(string) Str::uuid()])))->toThrow(DomainException::class, 'No customers');
});

it('does not let a coupon be attached without marketing rights', function () {
    $promo = Promotion::create(['name_en' => 'P', 'name_ar' => 'ع', 'type' => 'percentage', 'value' => '5', 'scope' => 'order', 'requires_coupon' => true, 'is_active' => true]);
    expect($this->svc->canAttachCoupon($this->staff))->toBeTrue();
    expect($this->svc->canAttachCoupon(staffWithRole('customer_support')))->toBeFalse();
});

it('slows down staff who send too fast', function () {
    $c = optedInCustomer();
    RateLimiter::clear('customer-messages:bulk:'.$this->staff->id);
    $d = optedInCustomer();
    foreach (range(1, 10) as $i) {
        $this->svc->send($this->staff, composed([$c->id, $d->id]));
    }
    expect(fn () => $this->svc->send($this->staff, composed([$c->id, $d->id])))->toThrow(DomainException::class, 'too fast');
});

it('shows each team member their own history unless they have company-wide notification rights', function () {
    $c = optedInCustomer();
    $this->svc->send($this->staff, composed([$c->id]));
    $other = staffWithRole('marketing_manager');
    expect($this->svc->visibleBatches($this->staff)->count())->toBe(1)->and($this->svc->visibleBatches($other)->count())->toBe(1); // global rights see everything
    expect(fn () => $this->svc->cancel(staffWithRole('customer_support'), MessageBatch::first()))->toThrow(DomainException::class);
});

it('puts the starter templates in place', function () {
    expect(JourneyTemplate::whereIn('name', ['Forgotten order reminder', 'Special offer for you'])->where('is_active', true)->count())->toBe(2);
});

it('spreads WhatsApp over minutes when sending in bulk', function () {
    Queue::fake();
    connectN8n();
    app(Settings::class)->set('messaging.whatsapp_per_minute', 2);
    $ids = collect(range(1, 5))->map(fn () => optedInCustomer()->id)->all();
    $this->svc->send($this->staff, composed($ids, ['channels' => ['whatsapp']]));

    Queue::assertPushed(SendCustomerMessageJob::class, 5);
    $delays = collect(Queue::pushedJobs()[SendCustomerMessageJob::class] ?? [])->pluck('job')->map(fn ($j) => $j->delay)
        ->map(fn ($d) => $d ? (int) round(now()->diffInMinutes($d, false)) : 0)->sort()->values()->all();
    expect($delays)->toBe([0, 0, 1, 1, 2]);
});

it('re-queues a send whose jobs were lost, once, and the scheduler command runs', function () {
    $c = optedInCustomer();
    $batch = $this->svc->send($this->staff, composed([$c->id]));
    // simulate lost jobs: the message is still waiting and the send started long ago
    CustomerMessage::query()->update(['status' => 'queued', 'sent_at' => null]);
    $batch->forceFill(['status' => 'sending', 'completed_at' => null, 'started_at' => now()->subHour()])->save();
    $batch->refreshCounts();
    Notification::query()->delete();

    $this->artisan('messaging:dispatch-due')->expectsOutputToContain('resumed: 1')->assertSuccessful();
    expect(CustomerMessage::first()->status)->toBe('sent')->and(Notification::count())->toBe(1);
    $this->artisan('messaging:dispatch-due')->expectsOutputToContain('resumed: 0')->assertSuccessful();
});

it('says "failed" instead of leaving a message waiting forever when the job gives up', function () {
    $c = optedInCustomer();
    $batch = $this->svc->send($this->staff, composed([$c->id]));
    CustomerMessage::query()->update(['status' => 'queued', 'sent_at' => null]);
    $batch->forceFill(['status' => 'sending', 'completed_at' => null])->save();

    (new SendCustomerMessageJob($batch->id, $c->id))->failed(new RuntimeException('boom'));

    expect(CustomerMessage::first())->status->toBe('failed')->reason->toBe('job_failed');
    expect($batch->fresh())->status->toBe('done')->failed_count->toBe(1);
});

it('makes the automatic journeys step aside for a customer the team just messaged', function () {
    $w = commerceWorld();
    $w->customer->update(['marketing_consent_at' => now(), 'locale' => 'en', 'phone' => '+97455123456']);
    $svc = app(JourneyService::class);
    $svc->ensureDefaults();
    Journey::where('key', 'abandoned_cart')->update(['is_enabled' => true, 'settings' => ['max_per_day' => 10]]);
    $cart = Cart::create(['user_id' => $w->customer->id, 'status' => 'active', 'last_activity_at' => now()->subMinutes(45)]);
    CartItem::create(['cart_id' => $cart->id, 'product_variant_id' => $w->v1->id, 'quantity' => 1]);

    $this->svc->send($this->staff, composed([$w->customer->id]));
    $before = Notification::where('user_id', $w->customer->id)->count();
    $svc->tick();

    expect(Notification::where('user_id', $w->customer->id)->count())->toBe($before)
        ->and(JourneyDelivery::pluck('reason')->unique()->all())->toBe(['recently_contacted']);
});
