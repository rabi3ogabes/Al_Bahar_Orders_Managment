<?php

use App\Domain\Identity\Models\User;
use App\Domain\Marketing\Models\Journey;
use App\Domain\Marketing\Models\JourneyDelivery;
use App\Domain\Marketing\Models\JourneyRun;
use App\Domain\Messaging\Data\AudienceSelection;
use App\Domain\Messaging\Data\SendRequest;
use App\Domain\Messaging\Models\CustomerMessage;
use App\Domain\Messaging\Models\MessageBatch;
use App\Domain\Messaging\Services\CustomerMessaging;
use App\Domain\Notifications\Models\NotificationPreference;
use App\Domain\Settings\Services\Settings;
use App\Livewire\Admin\Messaging\CustomerTimeline;
use App\Livewire\Admin\Sales\CustomerShow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

const TL_SECRET = 'a-very-long-shared-secret-tl-1';

function tlN8n(): void
{
    $s = app(Settings::class);
    $s->set('n8n.enabled', true);
    $s->set('n8n.webhook_url', 'https://n8n.example.com/webhook/bahar');
    $s->set('n8n.secret', TL_SECRET, secret: true);
}

function tlCustomer(string $name = 'Noor Ali'): User
{
    $u = User::factory()->create(['name' => $name, 'marketing_consent_at' => now(), 'phone' => '+9745'.random_int(5000000, 5999999), 'locale' => 'en']);
    NotificationPreference::create(['user_id' => $u->id, 'channel' => 'whatsapp', 'category' => 'marketing', 'is_enabled' => true]);

    return $u;
}

/** Sends a message for real; $over: channels, kind, content, scheduleAt. */
function tlSend(User $staff, User $to, array $over = []): MessageBatch
{
    RateLimiter::clear('customer-messages:single:'.$staff->id);
    $content = ['title_en' => 'Hello {{name}}', 'title_ar' => 'مرحباً {{name}}', 'body_en' => 'A note for {{name}}', 'body_ar' => 'رسالة إلى {{name}}', 'whatsapp_en' => '', 'whatsapp_ar' => '',
        'deep_link' => null, 'coupon' => ['enabled' => false]];

    return app(CustomerMessaging::class)->send($staff, new SendRequest(AudienceSelection::ofIds([$to->id]), $over['channels'] ?? ['notification'], $over['kind'] ?? 'custom',
        ($over['content'] ?? []) + $content, null, $over['scheduleAt'] ?? null, true));
}

/** Moves one send (and its messages) back in time. */
function tlAge(MessageBatch $batch, float $hours): void
{
    $at = CarbonImmutable::now()->subSeconds((int) ($hours * 3600));
    MessageBatch::whereKey($batch->id)->update(['created_at' => $at, 'started_at' => $at, 'completed_at' => $at]);
    CustomerMessage::where('batch_id', $batch->id)->update(['created_at' => $at, 'sent_at' => $at]);
}

function tlJourneyDelivery(User $customer, float $hoursAgo, string $channel = 'push', string $status = 'sent', ?string $coupon = null): JourneyDelivery
{
    $journey = Journey::firstOrCreate(['key' => 'abandoned_cart'], ['name' => 'Abandoned cart', 'is_enabled' => true]);
    $run = JourneyRun::create(['journey_id' => $journey->id, 'user_id' => $customer->id, 'anchor_at' => now()->subDay()]);
    $at = CarbonImmutable::now()->subSeconds((int) ($hoursAgo * 3600));

    return JourneyDelivery::create(['run_id' => $run->id, 'user_id' => $customer->id, 'channel' => $channel, 'locale' => 'en', 'status' => $status, 'sent_at' => $status === 'sent' ? $at : null,
        'coupon_code' => $coupon, 'created_at' => $at]);
}

/** The customer places a pay-at-pickup order at the branch, so branch staff may see them. */
function tlOrderAt(object $w, $branch, User $customer): void
{
    actingAsCustomer($customer);
    $customer->addresses()->create(['recipient_name' => $customer->name, 'recipient_phone' => '+97455123456', 'zone_number' => '66', 'street_number' => '850', 'building_number' => '12', 'is_default' => true]);
    test()->postJson('/api/v1/cart/items', ['variant_id' => $w->v1->id, 'quantity' => 1])->assertSuccessful();
    quoteAndPlace(['fulfillment_type' => 'pickup', 'branch_id' => $branch->id, 'payment_method' => 'pay_at_pickup'])->assertCreated();
}

beforeEach(function () {
    $this->staff = staffWithRole('marketing_manager', null, ['name' => 'Sara Team']);
    app(Settings::class)->set('messaging.cooldown_hours', 0);
    $this->customer = tlCustomer('Noor Ali');
    $this->actingAs($this->staff);
});

it('shows an empty state with no messages yet', function () {
    Livewire::test(CustomerTimeline::class, ['customer' => $this->customer])->assertSee(__('messaging_history.tl_empty_title'))->assertSee(__('messaging_history.tl_empty_hint'))
        ->assertDontSee(__('messaging_history.tl_more'));
});

it('lists what was sent by hand and by the journeys together, newest first, with who sent it and how it went', function () {
    tlN8n();
    Http::fake(['https://n8n.example.com/*' => Http::sequence()->push(['id' => 'w1'])->push([], 500)]);
    $old = tlSend($this->staff, $this->customer, ['channels' => ['whatsapp'], 'content' => ['whatsapp_en' => 'Salam {{name}}, your cart is waiting']]);
    MessageBatch::whereKey($old->id)->update(['template_name' => 'Cart reminder']);
    tlAge($old, 72);
    tlJourneyDelivery($this->customer, 30, 'push', 'sent', 'BAHAR-AB12');
    $failed = tlSend($this->staff, $this->customer, ['channels' => ['whatsapp'], 'kind' => 'offer']);
    MessageBatch::whereKey($failed->id)->update(['template_name' => 'Eid offer']);
    tlAge($failed, 2);

    $lw = Livewire::test(CustomerTimeline::class, ['customer' => $this->customer]);
    $entries = $lw->viewData('entries');
    expect($entries->pluck('source')->all())->toBe(['manual', 'journey', 'manual'])->and($entries->pluck('status')->all())->toBe(['failed', 'sent', 'sent']);
    $lw->assertSeeInOrder(['Eid offer', 'Automatic journey', 'Cart reminder'])->assertSee('Sara Team')->assertSee('BAHAR-AB12')
        ->assertSee(__('messaging_admin.reason.n8n_http', ['code' => 500]))->assertSee('Salam Noor, your cart is waiting')
        ->assertSee(__('journeys_admin.name_abandoned_cart'))->assertSee('2 hours ago')->assertSee('3 days ago')->assertSee(__('messaging_admin.kind.offer'));
});

it('summarises the last message that really reached the customer', function () {
    $sent = tlSend($this->staff, $this->customer);
    tlAge($sent, 50);
    Livewire::test(CustomerTimeline::class, ['customer' => $this->customer])->assertSee('Last message: 2 days ago via Notification');

    $other = tlCustomer('Nobody Reached');
    tlJourneyDelivery($other, 5, 'push', 'failed');
    Livewire::test(CustomerTimeline::class, ['customer' => $other])->assertSee(__('messaging_history.tl_none_reached'));
});

it('shows only this customer’s messages', function () {
    tlSend($this->staff, $this->customer, ['content' => ['body_en' => 'For Noor only']]);
    $other = tlCustomer('Someone Else');
    tlSend($this->staff, $other, ['content' => ['body_en' => 'For somebody else']]);

    Livewire::test(CustomerTimeline::class, ['customer' => $this->customer])->assertSee('For Noor only')->assertDontSee('For somebody else');
});

it('shows 15 and adds 15 more on request, until there are no more', function () {
    foreach (range(1, 37) as $i) {
        $b = tlSend($this->staff, $this->customer, ['content' => ['body_en' => 'Message number '.$i]]);
        tlAge($b, 100 - $i);
    }
    $lw = Livewire::test(CustomerTimeline::class, ['customer' => $this->customer]);
    expect($lw->viewData('entries'))->toHaveCount(15)->and($lw->viewData('entries')->first()['body'])->toContain('Message number 37');
    $lw->assertSee(__('messaging_history.tl_more'));
    $lw->call('showMore');
    expect($lw->viewData('entries'))->toHaveCount(30)->and($lw->viewData('entries')->pluck('key')->unique())->toHaveCount(30);
    $lw->call('showMore');
    expect($lw->viewData('entries'))->toHaveCount(37);
    $lw->assertDontSee(__('messaging_history.tl_more'));
    $lw->call('showMore');
    expect($lw->viewData('entries'))->toHaveCount(37);
});

it('shows a message sent from the message window at once, without a reload', function () {
    $lw = Livewire::test(CustomerTimeline::class, ['customer' => $this->customer])->assertSee(__('messaging_history.tl_empty_title'));
    tlSend($this->staff, $this->customer, ['content' => ['body_en' => 'Just sent from the window']]);
    $lw->dispatch('messages-sent', batch: 'x', source: 'customer')->assertSee('Just sent from the window')->assertDontSee(__('messaging_history.tl_empty_title'));
});

it('collapses a long text and offers to expand it, but leaves a short one alone', function () {
    tlSend($this->staff, $this->customer, ['content' => ['body_en' => str_repeat('A long sentence about the new collection. ', 5)]]);
    Livewire::test(CustomerTimeline::class, ['customer' => $this->customer])->assertSee(__('messaging_history.tl_expand'))->assertSeeHtml('line-clamp-2');
    CustomerMessage::query()->delete();
    tlSend($this->staff, $this->customer, ['content' => ['body_en' => 'Short one']]);
    Livewire::test(CustomerTimeline::class, ['customer' => $this->customer])->assertDontSee(__('messaging_history.tl_expand'))->assertSee('Short one');
});

it('links to the send only when the viewer may open it', function () {
    $batch = tlSend($this->staff, $this->customer);
    Livewire::test(CustomerTimeline::class, ['customer' => $this->customer])->assertSee(__('messaging_history.tl_open'))->assertSee(route('admin.messages.index', ['view' => $batch->id]), false);

    // a team member with rights on one branch only sees the customer's history, not the head office send behind it
    $w = commerceWorld();
    $branchStaff = staffWithRole('marketing_manager', $w->a);
    tlOrderAt($w, $w->a, $this->customer);
    $this->actingAs($branchStaff);
    Livewire::test(CustomerTimeline::class, ['customer' => $this->customer])->assertSee('A note for')->assertDontSee(__('messaging_history.tl_open'));
    $own = tlSend($branchStaff, $this->customer);
    Livewire::test(CustomerTimeline::class, ['customer' => $this->customer])->assertSee(route('admin.messages.index', ['view' => $own->id]), false)
        ->assertDontSee(route('admin.messages.index', ['view' => $batch->id]), false);
});

it('is closed to people without notification rights, and to customers outside a branch viewer’s reach', function () {
    $this->actingAs(staffWithRole('customer_support'));
    Livewire::test(CustomerTimeline::class, ['customer' => $this->customer])->assertForbidden();

    $w = commerceWorld();
    $branchStaff = staffWithRole('marketing_manager', $w->a);
    $this->actingAs($branchStaff);
    Livewire::test(CustomerTimeline::class, ['customer' => $this->customer])->assertForbidden(); // never ordered from their branch
    tlOrderAt($w, $w->a, $this->customer);
    $this->actingAs($branchStaff);
    Livewire::test(CustomerTimeline::class, ['customer' => $this->customer])->assertOk();
    // a staff account is not a customer
    Livewire::test(CustomerTimeline::class, ['customer' => $this->staff])->assertForbidden();
});

it('is a tab on the customer’s page, only for people who may see messages', function () {
    tlSend($this->staff, $this->customer, ['content' => ['body_en' => 'Visible in the tab']]);
    $url = route('admin.customers.show', ['customer' => $this->customer->id, 'tab' => 'messages']);
    $this->get($url)->assertOk()->assertSee(__('messaging_history.tl_title'))->assertSee('Visible in the tab')->assertSee(__('messaging_history.tl_hint'));
    $this->get(route('admin.customers.show', $this->customer->id))->assertOk()->assertSee(__('messaging_history.tl_title'))->assertDontSee('Visible in the tab'); // not read until the tab is opened

    $this->actingAs(staffWithRole('customer_support'));
    $this->get($url)->assertOk()->assertDontSee(__('messaging_history.tl_hint'))->assertDontSee('Visible in the tab');
});

it('opens inside the customer’s side window too', function () {
    tlSend($this->staff, $this->customer, ['content' => ['body_en' => 'Seen in the window']]);
    Livewire::test(CustomerShow::class, ['customer' => $this->customer, 'drawer' => true])->set('pane', 'messages')->assertSee('Seen in the window')->assertSee(__('messaging_history.tl_hint'));
});

it('reads in Arabic', function () {
    tlSend($this->staff, $this->customer, ['channels' => ['notification']]);
    tlJourneyDelivery($this->customer, 5);
    app()->setLocale('ar');
    Livewire::test(CustomerTimeline::class, ['customer' => $this->customer])->assertSee(__('messaging_history.tl_title'))->assertSee(__('messaging_history.tl_automatic'))
        ->assertSee(__('journeys_admin.name_abandoned_cart'))->assertSee('منذ');
});
