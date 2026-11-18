<?php

use App\Domain\Branches\Models\Branch;
use App\Domain\Marketing\Models\Coupon;
use App\Domain\Marketing\Models\JourneyTemplate;
use App\Domain\Messaging\Models\CustomerMessage;
use App\Domain\Messaging\Models\MessageBatch;
use App\Domain\Notifications\Models\Notification;
use App\Domain\Orders\Models\Cart;
use App\Domain\Orders\Models\Order;
use App\Domain\Settings\Services\Settings;
use App\Livewire\Admin\Messaging\MessageComposer;
use App\Livewire\Admin\Sales\CustomerIndex;
use App\Livewire\Admin\Sales\CustomerShow;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Livewire;

require_once __DIR__.'/MessagingSupport.php';

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->staff = staffWithRole('marketing_manager');
    $this->actingAs($this->staff);
});

/* ───────────────────────── who may use it ───────────────────────── */

describe('permission', function () {
    it('is refused to staff without notifications.create, and the buttons are not drawn', function () {
        $support = staffWithRole('customer_support');
        $c = mcCustomer();
        $this->actingAs($support);

        Livewire::test(MessageComposer::class)->assertForbidden();
        Livewire::test(CustomerIndex::class)->assertOk()->assertDontSeeHtml('data-testid="select-page"')->assertDontSeeHtml('data-testid="row-send"')
            ->assertDontSeeHtml('data-testid="bulk-bar"')->assertDontSeeHtml('message-composer')
            ->call('composeFor', $c->id)->assertForbidden();
        Livewire::test(CustomerIndex::class)->set('selected', [$c->id])->call('composeSelected')->assertForbidden();
        Livewire::test(CustomerShow::class, ['customer' => $c])->assertDontSeeHtml('data-testid="send-message"')->call('composeMessage')->assertForbidden();
        Livewire::test(CustomerShow::class, ['customer' => $c, 'drawer' => true])->assertDontSeeHtml('data-testid="send-message"');
    });

    it('is offered to staff with notifications.create', function () {
        $c = mcCustomer();

        Livewire::test(CustomerIndex::class)->assertSeeHtml('data-testid="select-page"')->assertSeeHtml('data-testid="row-send"')->assertSeeHtml('data-testid="bulk-bar"');
        Livewire::test(CustomerShow::class, ['customer' => $c])->assertSeeHtml('data-testid="send-message"');
        Livewire::test(CustomerShow::class, ['customer' => $c, 'drawer' => true])->assertSeeHtml('data-testid="send-message"');
        $this->get(route('admin.customers.show', $c))->assertOk()->assertSee(__('messaging_composer.title'));
    });

    it('refuses every action to someone who lost the right after opening it', function () {
        $c = mcCustomer();
        $support = staffWithRole('customer_support');

        foreach ([['requestSend', []], ['send', []], ['choose', ['custom']], ['pickSchedule', ['hour']], ['refreshPlan', []]] as [$action, $args]) {
            $comp = mcOpen([$c->id]);
            $this->actingAs($support);
            $comp->call($action, ...$args)->assertForbidden();
            $this->actingAs($this->staff);
        }
        expect(MessageBatch::count())->toBe(0);
    });
});

/* ───────────────────────── opening ───────────────────────── */

describe('opening', function () {
    it('opens for one customer with their name, language and a plain answer', function () {
        $c = mcCustomer(['name' => 'Sara Mansour', 'locale' => 'ar']);

        Livewire::test(MessageComposer::class)->dispatch('compose-message', mode: 'ids', ids: [$c->id], source: 'row')
            ->assertSet('open', true)->assertSet('count', 1)->assertSet('singleId', $c->id)->assertSet('singleName', 'Sara Mansour')->assertSet('defaultLang', 'ar')
            ->assertSet('source', 'row')->assertSet('step', 'compose')->assertSee('Sara Mansour')->assertSee(__('messaging_composer.what'))
            ->assertViewHas('canCoupon', true);
    });

    it('opens for the ticked customers', function () {
        $ids = collect(range(1, 3))->map(fn () => mcCustomer()->id)->all();

        Livewire::test(MessageComposer::class)->dispatch('compose-message', mode: 'ids', ids: $ids)
            ->assertSet('open', true)->assertSet('count', 3)->assertSet('singleId', null)->assertSee(trans_choice('messaging_composer.to_many', 3, ['count' => 3]));
    });

    it('opens for everyone matching the filters, minus the ones left out', function () {
        $in = collect(range(1, 4))->map(fn () => mcCustomer()->id);
        $out = mcCustomer(['marketing_consent_at' => null])->id;

        Livewire::test(MessageComposer::class)->dispatch('compose-message', mode: 'all', filters: ['consent' => 'yes'], except: [$in[0]])
            ->assertSet('count', 3)->assertSet('audience.mode', 'all')->assertSee(__('messaging_composer.to_all', ['count' => 3]));
        // left out ones are never part of it, and the un-consented customer is not matched by the filter
        $comp = Livewire::test(MessageComposer::class)->dispatch('compose-message', mode: 'all', filters: ['consent' => 'yes'], except: [])->assertSet('count', 4);
        expect($comp->get('audience')['filters'])->toBe(['consent' => 'yes']);
        expect($out)->not->toBeIn($comp->get('audience')['ids']);
    });

    it('cleans whatever the event carries: bad ids, unknown filters, a runaway list', function () {
        $c = mcCustomer();
        $junk = array_merge([$c->id, 'not-a-uuid', 123, ['x'], (string) Str::uuid()], array_fill(0, 25000, (string) Str::uuid()));

        $comp = Livewire::test(MessageComposer::class)->dispatch('compose-message', mode: 'ids', ids: $junk)->assertSet('open', true);
        expect($comp->get('count'))->toBe(1)->and(count($comp->get('audience')['ids']))->toBeLessThanOrEqual(20000);

        $all = Livewire::test(MessageComposer::class)->dispatch('compose-message', mode: 'all', filters: ['search' => 'Noor', 'evil' => "1' OR 1=1", 'status' => ['x']], except: ['zzz']);
        expect($all->get('audience')['filters'])->toBe(['search' => 'Noor'])->and($all->get('audience')['except'])->toBe([]);

        Livewire::test(MessageComposer::class)->dispatch('compose-message', mode: 'banana', ids: [$c->id])->assertSet('audience.mode', 'ids');
    });

    it('does not open for nobody, or for customers this staff member cannot see', function () {
        $c = mcCustomer();
        $branch = Branch::factory()->create();
        $scoped = mcStaff(['customers.view', 'notifications.create'], $branch); // never had an order at their branch
        Livewire::test(MessageComposer::class)->dispatch('compose-message', mode: 'ids', ids: [])->assertSet('open', false)->assertDispatched('toast', type: 'error');
        $this->actingAs($scoped);
        Livewire::test(MessageComposer::class)->dispatch('compose-message', mode: 'ids', ids: [$c->id])->assertSet('open', false)->assertSet('count', 0)->assertDispatched('toast', type: 'error');
        Livewire::test(MessageComposer::class)->dispatch('compose-message', mode: 'all', filters: [], except: [])->assertSet('open', false);
    });

    it('reaches only the customers of the staff member’s own branch', function () {
        $w = commerceWorld();
        actingAsCustomer($w->customer);
        $this->postJson('/api/v1/cart/items', ['variant_id' => $w->v1->id, 'quantity' => 1]);
        quoteAndPlace(['address_id' => $w->address->id, 'payment_method' => 'cod'])->assertCreated();
        $w->customer->update(['marketing_consent_at' => now()]);
        $other = mcCustomer();
        expect(Order::where('user_id', $w->customer->id)->count())->toBe(1);

        $mgrA = mcStaff(['customers.view', 'notifications.create'], $w->a);
        $mgrB = mcStaff(['customers.view', 'notifications.create'], $w->b);

        $this->actingAs($mgrA);
        Livewire::test(MessageComposer::class)->dispatch('compose-message', mode: 'ids', ids: [$w->customer->id, $other->id])->assertSet('open', true)->assertSet('count', 1)->assertSet('singleId', $w->customer->id);
        Livewire::test(MessageComposer::class)->dispatch('compose-message', mode: 'all', filters: [], except: [])->assertSet('count', 1);
        $this->actingAs($mgrB);
        Livewire::test(MessageComposer::class)->dispatch('compose-message', mode: 'ids', ids: [$w->customer->id])->assertSet('open', false);
        Livewire::test(MessageComposer::class)->dispatch('compose-message', mode: 'all', filters: [], except: [])->assertSet('open', false);

        // and a send never reaches beyond that, whatever was typed into the request
        $this->actingAs($mgrA);
        $comp = mcOpen([$w->customer->id, $other->id])->call('requestSend');
        expect(CustomerMessage::pluck('user_id')->unique()->all())->toBe([$w->customer->id]);
        $comp->assertSet('step', 'sent');
    });

    it('starts clean every time it opens', function () {
        $a = mcCustomer();
        $b = mcCustomer(['name' => 'Omar K', 'locale' => 'ar']);
        $comp = mcOpen([$a->id])->set('ignoreCooldown', true)->call('pickSchedule', 'hour')->call('requestSend');
        $comp->assertSet('step', 'sent');

        $comp->dispatch('compose-message', mode: 'ids', ids: [$b->id])->assertSet('step', 'compose')->assertSet('templateId', '')->assertSet('scheduling', false)->assertSet('scheduleAt', '')
            ->assertSet('ignoreCooldown', false)->assertSet('result', [])->assertSet('singleName', 'Omar K')->assertSet('defaultLang', 'ar');
        expect($comp->get('content')['title_en'])->toBe('');
    });

    it('lets the confirmation go away with the window', function () {
        $ids = [mcCustomer()->id, mcCustomer()->id];
        mcOpen($ids)->call('requestSend')->assertSet('confirming', true)->set('open', false)->assertSet('confirming', false);
    });
});

/* ───────────────────────── what to send ───────────────────────── */

describe('templates', function () {
    it('fills the editor from a template and takes its kind', function () {
        $promo = mcOffer();
        $t = JourneyTemplate::create(['name' => 'Spring offer', 'kind' => 'offer', 'title_en' => 'Spring is here', 'title_ar' => 'جاء الربيع', 'body_en' => 'Use {{coupon}} before {{expires}}',
            'body_ar' => 'استخدم {{coupon}} قبل {{expires}}', 'whatsapp_en' => 'WA {{coupon}}', 'deep_link' => 'albahar://categories', 'promotion_id' => $promo->id, 'coupon_prefix' => 'spr', 'coupon_valid_hours' => 48]);
        $c = mcCustomer();

        $comp = mcOpen([$c->id], '')->call('choose', $t->id)->assertSet('templateId', $t->id)->assertSet('kind', 'offer');
        expect($comp->get('content'))->toMatchArray(['title_en' => 'Spring is here', 'body_ar' => 'استخدم {{coupon}} قبل {{expires}}', 'whatsapp_en' => 'WA {{coupon}}', 'whatsapp_ar' => '', 'deep_link' => 'albahar://categories'])
            ->and($comp->get('content')['coupon'])->toBe(['enabled' => true, 'promotion_id' => $promo->id, 'prefix' => 'SPR', 'valid_hours' => 48]);
        $comp->assertSee('Spring is here');
    });

    it('turns the coupon on when the text needs one, and leaves the offer to be chosen', function () {
        $t = JourneyTemplate::create(['name' => 'Cart offer', 'kind' => 'offer', 'title_en' => 'A', 'title_ar' => 'ا', 'body_en' => 'Code {{coupon}}', 'body_ar' => 'الرمز {{coupon}}', 'promotion_id' => null]);
        mcOffer();

        $comp = mcOpen([mcCustomer()->id], '')->call('choose', $t->id);
        expect($comp->get('content')['coupon'])->toMatchArray(['enabled' => true, 'promotion_id' => ''])->and($comp->get('kind'))->toBe('offer');
        $comp->call('requestSend')->assertHasErrors(['content.coupon.promotion_id']);
    });

    it('forgets a template promotion that has since ended', function () {
        $promo = mcOffer();
        $promo->update(['is_active' => false]);
        $t = JourneyTemplate::create(['name' => 'Old', 'kind' => 'offer', 'title_en' => 'A', 'title_ar' => 'ا', 'body_en' => 'Code {{coupon}}', 'body_ar' => 'الرمز {{coupon}}', 'promotion_id' => $promo->id]);

        $comp = mcOpen([mcCustomer()->id], '')->call('choose', $t->id);
        expect($comp->get('content')['coupon'])->toMatchArray(['enabled' => true, 'promotion_id' => '']);
    });

    it('starts "write your own" empty and replaces a template', function () {
        $t = JourneyTemplate::create(['name' => 'R', 'kind' => 'reminder', 'title_en' => 'Cart', 'title_ar' => 'سلة', 'body_en' => 'Items {{items}}', 'body_ar' => 'عناصر {{items}}']);

        $comp = mcOpen([mcCustomer()->id], '')->call('choose', $t->id)->assertSet('kind', 'reminder')->call('choose', 'custom')->assertSet('kind', 'custom')->assertSet('templateId', 'custom');
        expect($comp->get('content')['title_en'])->toBe('')->and($comp->get('content')['deep_link'])->toBe('albahar://home');
    });

    it('refuses a template that is gone, switched off or not a template', function () {
        $off = JourneyTemplate::create(['name' => 'Off', 'kind' => 'reminder', 'title_en' => 'A', 'title_ar' => 'ا', 'body_en' => 'B', 'body_ar' => 'ب', 'is_active' => false]);

        $comp = mcOpen([mcCustomer()->id], '');
        foreach ([$off->id, (string) Str::uuid(), 'nonsense'] as $id) {
            $comp->call('choose', $id)->assertSet('templateId', '')->assertDispatched('toast', type: 'error');
        }
    });

    it('does not offer a coupon template to staff without marketing rights', function () {
        $promo = mcOffer();
        $t = JourneyTemplate::create(['name' => 'Offer', 'kind' => 'offer', 'title_en' => 'A', 'title_ar' => 'ا', 'body_en' => 'Code {{coupon}}', 'body_ar' => 'الرمز {{coupon}}', 'promotion_id' => $promo->id]);
        $plain = JourneyTemplate::create(['name' => 'Plain', 'kind' => 'reminder', 'title_en' => 'A', 'title_ar' => 'ا', 'body_en' => 'B', 'body_ar' => 'ب']);
        $this->actingAs(mcStaff(['customers.view', 'notifications.create']));
        $c = mcCustomer();

        Livewire::test(MessageComposer::class)->dispatch('compose-message', mode: 'ids', ids: [$c->id])
            ->assertViewHas('canCoupon', false)->assertSee(__('messaging_composer.needs_marketing'))
            ->call('choose', $t->id)->assertSet('templateId', '')->assertDispatched('toast', type: 'error')
            ->call('choose', $plain->id)->assertSet('templateId', $plain->id);
    });

    it('lists the active templates when the window opens', function () {
        JourneyTemplate::create(['name' => 'Shown one', 'kind' => 'reminder', 'title_en' => 'A', 'title_ar' => 'ا', 'body_en' => 'B', 'body_ar' => 'ب']);
        JourneyTemplate::create(['name' => 'Hidden one', 'kind' => 'reminder', 'title_en' => 'A', 'title_ar' => 'ا', 'body_en' => 'B', 'body_ar' => 'ب', 'is_active' => false]);

        mcOpen([mcCustomer()->id], '')->assertSee('Shown one')->assertDontSee('Hidden one')->assertSee(__('messaging_composer.own'));
    });
});

/* ───────────────────────── how to send ───────────────────────── */

describe('channels', function () {
    it('needs at least one way to send', function () {
        $comp = mcOpen([mcCustomer()->id])->set('channels', []);

        $comp->call('requestSend')->assertHasErrors(['channels'])->assertSet('step', 'compose');
        expect(MessageBatch::count())->toBe(0);
    });

    it('cannot tick WhatsApp until the automation is connected', function () {
        $c = mcCustomer();
        mcWhatsApp($c);

        mcOpen([$c->id])->assertViewHas('available', fn ($a) => $a['whatsapp']['available'] === false)->assertSeeHtml('data-testid="whatsapp-off"')
            ->set('channels', ['notification', 'whatsapp'])->assertSet('channels', ['notification']);
    });

    it('can tick WhatsApp once n8n is connected', function () {
        mcConnectN8n();
        $c = mcCustomer();
        mcWhatsApp($c);

        mcOpen([$c->id])->assertDontSeeHtml('data-testid="whatsapp-off"')->set('channels', ['notification', 'whatsapp'])->assertSet('channels', ['notification', 'whatsapp']);
    });

    it('drops values that are not channels', function () {
        mcOpen([mcCustomer()->id])->set('channels', ['notification', 'carrier-pigeon'])->assertSet('channels', ['notification']);
    });

    it('sends over both channels when both are on', function () {
        mcConnectN8n();
        $c = mcCustomer(['phone' => '+97455123123']);
        mcWhatsApp($c);

        mcOpen([$c->id])->set('channels', ['notification', 'whatsapp'])->set('content', mcContent(['whatsapp_en' => 'Hi {{name}} on WhatsApp']))->call('requestSend')->assertSet('step', 'sent');

        expect(CustomerMessage::where('user_id', $c->id)->orderBy('channel')->pluck('channel')->all())->toBe(['notification', 'whatsapp'])
            ->and(CustomerMessage::where('channel', 'whatsapp')->value('body'))->toBe('Hi Noor on WhatsApp');
        Http::assertSentCount(1);
    });
});

/* ───────────────────────── who will get it ───────────────────────── */

describe('plan', function () {
    it('says how many will receive it and why the others are skipped', function () {
        $ok = mcCustomer();
        $no = mcCustomer(['marketing_consent_at' => null, 'name' => 'Omar K']);
        $suspended = mcCustomer(['status' => 'suspended']);

        $comp = mcOpen([$ok->id, $no->id, $suspended->id]);
        expect($comp->get('plan'))->toMatchArray(['total' => 3, 'reachable' => 1, 'skipped' => 2, 'overCap' => false])
            ->and($comp->get('plan')['reasons'])->toBe(['no_marketing_consent' => 1, 'inactive_user' => 1]);
        $comp->assertSee(__('messaging_admin.reason.no_marketing_consent'))->assertSee('Omar K')->assertSeeHtml('data-testid="plan-bulk"');
    });

    it('shows each channel’s answer for one customer', function () {
        mcConnectN8n();
        $c = mcCustomer(); // no WhatsApp opt-in

        $comp = mcOpen([$c->id])->set('channels', ['notification', 'whatsapp']);
        expect($comp->get('plan')['single'])->toBe(['notification' => null, 'whatsapp' => 'no_whatsapp_optin']);
        $comp->assertSee(__('messaging_admin.reason.no_whatsapp_optin'))->assertSeeHtml('data-testid="plan-single"')->assertDontSeeHtml('data-testid="send-anyway"');
    });

    it('offers "send anyway" for one recently messaged customer, and never for several', function () {
        $c = mcCustomer();
        $batch = MessageBatch::create(['created_by' => $this->staff->id, 'template_name' => 'x', 'kind' => 'custom', 'source' => 'single', 'channels' => ['notification'], 'content' => [], 'audience' => [],
            'recipients_count' => 1, 'status' => 'done']);
        CustomerMessage::create(['batch_id' => $batch->id, 'user_id' => $c->id, 'channel' => 'notification', 'locale' => 'en', 'status' => 'sent', 'sent_at' => now()->subHour()]);

        $comp = mcOpen([$c->id]);
        expect($comp->get('plan')['reachable'])->toBe(0)->and($comp->get('plan')['single'])->toBe(['notification' => 'recently_contacted']);
        $comp->assertSeeHtml('data-testid="send-anyway"')->call('requestSend')->assertSet('step', 'compose')->assertSet('error', __('messaging_composer.nobody'));

        $comp->set('ignoreCooldown', true);
        expect($comp->get('plan')['reachable'])->toBe(1)->and($comp->get('plan')['single'])->toBe(['notification' => null]);
        $comp->call('requestSend')->assertSet('step', 'sent');
        expect(CustomerMessage::where('batch_id', '!=', $batch->id)->where('user_id', $c->id)->value('status'))->toBe('sent');

        // the override is for one customer only
        $other = mcCustomer();
        CustomerMessage::create(['batch_id' => $batch->id, 'user_id' => $other->id, 'channel' => 'notification', 'locale' => 'en', 'status' => 'sent', 'sent_at' => now()->subHour()]);
        $many = mcOpen([$other->id, mcCustomer()->id])->set('ignoreCooldown', true);
        expect($many->get('ignoreCooldown'))->toBeFalse()->and($many->get('plan')['reasons'])->toBe(['recently_contacted' => 1]);
    });

    it('skips customers without a cart for an order reminder, and says so', function () {
        $withCart = mcCustomer();
        mcCart($withCart);
        $without = mcCustomer();
        $t = JourneyTemplate::create(['name' => 'Cart reminder', 'kind' => 'reminder', 'title_en' => 'Cart', 'title_ar' => 'سلة', 'body_en' => 'Items {{items}}', 'body_ar' => 'عناصر {{items}}']);

        $comp = mcOpen([$withCart->id, $without->id], '')->call('choose', $t->id);
        expect($comp->get('plan'))->toMatchArray(['reachable' => 1, 'skipped' => 1])->and($comp->get('plan')['reasons'])->toBe(['no_open_cart' => 1]);
        $comp->assertSee(__('messaging_composer.reminder_note'))->assertSee(__('messaging_admin.reason.no_open_cart'));

        // for one customer without a cart the reason is plain
        $single = mcOpen([$without->id], '')->call('choose', $t->id);
        expect($single->get('plan')['single'])->toBe(['notification' => 'no_open_cart']);
    });

    it('refreshes when the way of sending changes', function () {
        mcConnectN8n();
        $both = mcCustomer(['phone' => '+97455777111']);
        mcWhatsApp($both);
        $onlyApp = mcCustomer();

        $comp = mcOpen([$both->id, $onlyApp->id]);
        expect($comp->get('plan')['channels'])->toBe(['notification' => ['ok' => 2, 'skipped' => 0]]);
        $comp->set('channels', ['notification', 'whatsapp']);
        expect($comp->get('plan')['channels']['whatsapp'])->toBe(['ok' => 1, 'skipped' => 1])->and($comp->get('plan')['reachable'])->toBe(2);
    });

    it('blocks a send that is over the cap and says how many are allowed', function () {
        app(Settings::class)->set('messaging.bulk_max', 2);
        $ids = collect(range(1, 3))->map(fn () => mcCustomer()->id)->all();

        $comp = mcOpen($ids);
        expect($comp->get('plan'))->toMatchArray(['total' => 3, 'max' => 2, 'overCap' => true]);
        $comp->assertSeeHtml('data-testid="over-cap"')->call('requestSend')->assertSet('confirming', false)->assertSet('step', 'compose')
            ->assertSet('error', __('messaging_admin.err.too_many', ['max' => 2, 'count' => 3]));
        $comp->call('send')->assertSet('step', 'compose')->assertDispatched('toast', type: 'error');
        expect(MessageBatch::count())->toBe(0);
    });
});

/* ───────────────────────── sending ───────────────────────── */

describe('sending now', function () {
    it('sends to one customer without asking, keeps the history and shows what happened', function () {
        $c = mcCustomer();

        $comp = mcOpen([$c->id], '')->call('choose', 'custom')->set('content', mcContent())->call('requestSend')
            ->assertSet('step', 'sent')->assertSet('confirming', false)->assertDispatched('toast', type: 'warning')->assertDispatched('messages-sent');
        $batch = MessageBatch::firstOrFail();
        expect($batch)->kind->toBe('custom')->source->toBe('single')->sent_count->toBe(1)->template_name->toBe('Custom message')->created_by->toBe($this->staff->id);
        expect($comp->get('result'))->toMatchArray(['id' => $batch->id, 'scheduled' => false, 'customers' => 1, 'reached' => 1, 'skipped' => 0, 'failed' => 0])
            ->and(CustomerMessage::where('user_id', $c->id)->value('title'))->toBe('Hello Noor')
            ->and(Notification::where('user_id', $c->id)->value('title'))->toBe('Hello Noor');
        // this customer has no phone: the window says so in plain words instead of a bare "sent"
        $comp->assertSee(__('messaging_composer.res_one.no_phone.title'))->assertSeeHtml('data-testid="result"')->assertSeeHtml('data-testid="see-history"');
    });

    it('says the message is on its way while the queue still holds it (several customers)', function () {
        Queue::fake();

        $comp = mcOpen([mcCustomer()->id, mcCustomer()->id])->call('requestSend')->call('send')->assertSet('step', 'sent');
        expect($comp->get('result'))->toMatchArray(['pending' => true, 'reached' => 2, 'scheduled' => false])->and(MessageBatch::firstOrFail()->status)->toBe('sending');
        $comp->assertSee(__('messaging_composer.done_sending'))->assertSee(__('messaging_composer.done_pending_hint'));
    });

    it('asks first before sending to several customers, then records skipped ones with their reason', function () {
        $ok = mcCustomer();
        $no = mcCustomer(['marketing_consent_at' => null]);

        $comp = mcOpen([$ok->id, $no->id])->call('requestSend')->assertSet('confirming', true)->assertSet('step', 'compose')->assertSeeHtml('data-testid="confirm"');
        expect(MessageBatch::count())->toBe(0);

        $comp->call('send')->assertSet('step', 'sent')->assertSet('confirming', false);
        $batch = MessageBatch::firstOrFail();
        expect($batch)->source->toBe('bulk')->recipients_count->toBe(2)
            ->and($comp->get('result'))->toMatchArray(['customers' => 2, 'reached' => 1, 'skipped' => 1, 'reasons' => ['no_marketing_consent' => 1]])
            ->and(CustomerMessage::where('user_id', $no->id)->first())->status->toBe('skipped')->reason->toBe('no_marketing_consent');
        $comp->assertSee(__('messaging_admin.reason.no_marketing_consent'));
    });

    it('takes the template into the history and a coupon along with it', function () {
        $promo = mcOffer();
        $t = JourneyTemplate::create(['name' => 'Spring offer', 'kind' => 'offer', 'title_en' => 'Spring', 'title_ar' => 'ربيع', 'body_en' => 'Use {{coupon}}', 'body_ar' => 'استخدم {{coupon}}', 'promotion_id' => $promo->id, 'coupon_prefix' => 'SPR']);
        $c = mcCustomer();

        mcOpen([$c->id], '')->call('choose', $t->id)->call('requestSend')->assertSet('step', 'sent');

        $batch = MessageBatch::firstOrFail();
        $code = CustomerMessage::where('user_id', $c->id)->value('coupon_code');
        expect($batch)->template_id->toBe($t->id)->template_name->toBe('Spring offer')->kind->toBe('offer')
            ->and($code)->toStartWith('SPR-')->and(Coupon::where('code', $code)->value('assigned_user_id'))->toBe($c->id)
            ->and(Notification::where('user_id', $c->id)->value('body'))->toBe('Use '.$code);
    });

    it('shows a customer who was reachable on none as skipped, and still records it for a schedule', function () {
        $no = mcCustomer(['marketing_consent_at' => null]);

        // not now: nobody can receive it, so the window explains instead of sending
        mcOpen([$no->id])->call('requestSend')->assertSet('step', 'compose')->assertSet('error', __('messaging_composer.nobody'));
        expect(MessageBatch::count())->toBe(0);
        // a schedule is checked again when it goes out, so it is allowed
        mcOpen([$no->id])->call('pickSchedule', 'tomorrow')->call('requestSend')->assertSet('step', 'sent');
        expect(MessageBatch::firstOrFail()->status)->toBe('scheduled');
    });

    it('keeps everything typed and shows the problem next to the field when the text is not valid', function () {
        $c = mcCustomer();
        $bad = mcContent(['title_ar' => '', 'body_en' => 'Hi {{nmae}}', 'title_en' => str_repeat('x', 66)]);

        $comp = mcOpen([$c->id])->set('content', $bad)->call('requestSend')
            ->assertHasErrors(['content.title_ar', 'content.body_en', 'content.title_en'])->assertSet('step', 'compose')->assertSet('confirming', false);
        expect($comp->get('content'))->toEqual($bad)->and(MessageBatch::count())->toBe(0);
        $comp->assertSee('{{nmae}}');
    });

    it('asks for the coupon to be shown, and for an offer to be chosen', function () {
        $c = mcCustomer();
        mcOffer();

        $comp = mcOpen([$c->id])->set('content', mcContent(['body_en' => 'Code {{coupon}}']))->call('requestSend')->assertHasErrors(['content.coupon.enabled']);
        $comp->set('content', mcContent(['coupon' => ['enabled' => true, 'promotion_id' => '', 'prefix' => 'BAHAR', 'valid_hours' => 72]]))->call('requestSend')
            ->assertHasErrors(['content.coupon.promotion_id']);
        expect(MessageBatch::count())->toBe(0);
    });

    it('sends a tampered request as plain text, never as a crash', function () {
        $c = mcCustomer();

        $comp = mcOpen([$c->id])->set('content', ['title_en' => ['x'], 'body_en' => '<b>Hello</b> {{name}}', 'title_ar' => 'ا', 'body_ar' => 'ب', 'coupon' => 'yes', 'deep_link' => ['x']]);
        $comp->call('requestSend')->assertHasErrors(['content.title_en']);
        $comp->set('content', mcContent(['body_en' => '<b>Hello</b> {{name}}']))->call('requestSend')->assertSet('step', 'sent');
        expect(CustomerMessage::value('body'))->toBe('Hello Noor');
    });

    it('survives a double click: only one send goes out', function () {
        $c = mcCustomer();

        $comp = mcOpen([$c->id])->call('requestSend')->call('requestSend')->call('send');
        $comp->assertSet('step', 'sent');
        expect(MessageBatch::count())->toBe(1)->and(CustomerMessage::count())->toBe(1);

        $many = mcOpen([mcCustomer()->id, mcCustomer()->id])->call('requestSend')->call('send')->call('send');
        expect(MessageBatch::count())->toBe(2);
    });

    it('keeps the window and the text when the service refuses, and says why', function () {
        $c = mcCustomer();
        $key = 'customer-messages:single:'.$this->staff->id;
        foreach (range(1, 40) as $_) {
            RateLimiter::hit($key, 60);
        }

        $comp = mcOpen([$c->id])->call('requestSend')->assertSet('step', 'compose')->assertDispatched('toast', type: 'error');
        expect($comp->get('error'))->not->toBe('')->and($comp->get('content')['title_en'])->toBe('Hello {{name}}')->and(MessageBatch::count())->toBe(0);
        $comp->assertSeeHtml('data-testid="error"');
    });

    it('refuses WhatsApp that is not connected even if the request was forged', function () {
        Http::fake();
        $c = mcCustomer();
        mcWhatsApp($c);

        $comp = mcOpen([$c->id]);
        $comp->set('channels', ['notification', 'whatsapp'])->call('requestSend')->assertSet('step', 'sent'); // the forged channel is simply not part of it
        expect(CustomerMessage::pluck('channel')->unique()->all())->toBe(['notification']);
        Http::assertNothingSent();
    });

    it('needs a message to be chosen first', function () {
        mcOpen([mcCustomer()->id], '')->call('requestSend')->assertSet('step', 'compose')->assertSet('error', __('messaging_composer.pick_first'));
    });
});

/* ───────────────────────── when ───────────────────────── */

describe('scheduling', function () {
    it('offers quick picks in Qatar time and shows the chosen moment', function () {
        $c = mcCustomer();
        $this->travelTo(CarbonImmutable::parse('2026-11-16 09:00', 'Asia/Qatar')); // Monday morning in Doha

        $comp = mcOpen([$c->id])->call('pickSchedule', 'tomorrow')->assertSet('scheduling', true)->assertSet('scheduleAt', '2026-11-17T10:00')->assertSee('Tue 17 Nov, 10:00');
        $comp->call('pickSchedule', 'tonight')->assertSet('scheduleAt', '2026-11-16T20:00');
        $comp->call('pickSchedule', 'hour')->assertSet('scheduleAt', '2026-11-16T10:00');
        $comp->call('pickSchedule', 'whenever')->assertSet('scheduleAt', '2026-11-16T10:00'); // unknown: unchanged
    });

    it('skips "today 8 pm" once it has passed', function () {
        $this->travelTo(CarbonImmutable::parse('2026-11-16 20:30', 'Asia/Qatar'));
        $comp = mcOpen([mcCustomer()->id])->call('pickSchedule', 'tonight')->assertSet('scheduleAt', '')->assertSet('scheduling', false);
        $comp->assertViewHas('tonightOk', false);
    });

    it('schedules the send for that moment, stored in UTC, and shows it', function () {
        $c = mcCustomer();
        $this->travelTo(CarbonImmutable::parse('2026-11-16 09:00', 'Asia/Qatar'));

        $comp = mcOpen([$c->id])->call('pickSchedule', 'tomorrow')->call('requestSend')->assertSet('step', 'sent');
        $batch = MessageBatch::firstOrFail();
        expect($batch)->status->toBe('scheduled')->and($batch->scheduled_at->utc()->format('Y-m-d H:i'))->toBe('2026-11-17 07:00')
            ->and($comp->get('result'))->toMatchArray(['scheduled' => true, 'at' => 'Tue 17 Nov, 10:00', 'customers' => 1])
            ->and(Notification::count())->toBe(0);
        $comp->assertSee(__('messaging_composer.done_scheduled'))->assertSee('Tue 17 Nov, 10:00')->assertDispatched('toast', type: 'success');
    });

    it('takes a custom time and checks it: at least a minute ahead, at most 60 days', function () {
        $c = mcCustomer();
        $this->travelTo(CarbonImmutable::parse('2026-11-16 09:00', 'Asia/Qatar'));

        $comp = mcOpen([$c->id])->call('startSchedule');
        $comp->set('scheduleAt', '2026-11-16T09:00')->assertHasErrors(['scheduleAt']);
        $comp->set('scheduleAt', '2026-11-16T09:00')->call('requestSend')->assertHasErrors(['scheduleAt'])->assertSet('step', 'compose');
        $comp->set('scheduleAt', '2027-02-01T10:00')->assertHasErrors(['scheduleAt']);
        $comp->set('scheduleAt', 'next week')->call('requestSend')->assertHasErrors(['scheduleAt']);
        $comp->set('scheduleAt', '')->call('requestSend')->assertHasErrors(['scheduleAt']);
        $comp->set('scheduleAt', '2026-11-16T09:02')->assertHasNoErrors()->call('requestSend')->assertSet('step', 'sent');
        expect(MessageBatch::count())->toBe(1);
    });

    it('can go back to sending now', function () {
        $comp = mcOpen([mcCustomer()->id])->call('pickSchedule', 'hour')->assertSet('scheduling', true)->call('cancelSchedule')->assertSet('scheduling', false)->assertSet('scheduleAt', '');
        $comp->call('requestSend')->assertSet('step', 'sent');
        expect(MessageBatch::firstOrFail()->status)->not->toBe('scheduled');
    });

    it('confirms a scheduled send to several customers with the time', function () {
        $this->travelTo(CarbonImmutable::parse('2026-11-16 09:00', 'Asia/Qatar'));
        $ids = [mcCustomer()->id, mcCustomer()->id];

        mcOpen($ids)->call('pickSchedule', 'tomorrow')->call('requestSend')->assertSet('confirming', true)->assertSee('Tue 17 Nov, 10:00')->assertSee(__('messaging_composer.confirm_scheduled_note'));
        expect(MessageBatch::count())->toBe(0);
    });
});

/* ───────────────────────── language ───────────────────────── */

describe('arabic', function () {
    it('opens, previews and sends in Arabic', function () {
        app()->setLocale('ar');
        $c = mcCustomer(['name' => 'نور العلي', 'locale' => 'ar']);

        $comp = mcOpen([$c->id], '')->assertSee(__('messaging_composer.title'))->assertSee(__('messaging_composer.what'))->assertSee('نور العلي')->assertSee(__('messaging_composer.own'))
            ->call('choose', 'custom')->assertSee(__('messaging_composer.customise'))->assertSee(__('messaging_admin.placeholder.name'));
        expect(__('messaging_composer.title'))->toBe('إرسال رسالة')->and($comp->get('defaultLang'))->toBe('ar');
        $comp->set('content', mcContent())->call('requestSend')->assertSet('step', 'sent')->assertSee(__('messaging_composer.res_one.no_phone.title'))->assertSee('حُفظت في صندوق التطبيق فقط');
        expect(Notification::where('user_id', $c->id)->value('title'))->toBe('مرحباً نور');
    });

    it('has every string in both languages', function () {
        $en = require lang_path('en/messaging_composer.php');
        $ar = require lang_path('ar/messaging_composer.php');
        expect(mcKeys($ar))->toBe(mcKeys($en));
        $enC = require lang_path('en/messaging_customers.php');
        $arC = require lang_path('ar/messaging_customers.php');
        expect(mcKeys($arC))->toBe(mcKeys($enC));
    });
});
