<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Identity\Models\User;
use App\Domain\Messaging\Data\AudienceSelection;
use App\Domain\Messaging\Data\SendRequest;
use App\Domain\Messaging\Jobs\SendCustomerMessageJob;
use App\Domain\Messaging\Models\CustomerMessage;
use App\Domain\Messaging\Models\MessageBatch;
use App\Domain\Messaging\Services\CustomerMessaging;
use App\Domain\Messaging\Services\MessageDelivery;
use App\Domain\Notifications\Models\Notification;
use App\Domain\Notifications\Models\NotificationPreference;
use App\Domain\Notifications\Services\DevicePushReach;
use App\Domain\Notifications\Services\NotificationCatalog;
use App\Domain\Notifications\Services\NotificationRules;
use App\Domain\Settings\Services\Settings;
use App\Livewire\Admin\Messaging\CustomerTimeline;
use App\Livewire\Admin\Messaging\MessageComposer;
use App\Livewire\Admin\Messaging\MessageHistory;
use App\Support\DomainException;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Sleep;
use Livewire\Livewire;

require_once __DIR__.'/MessagingSupport.php';
require_once __DIR__.'/../Push/PushSupport.php';
require_once __DIR__.'/../Push/PhoneStateSupport.php';

/*
 * "I send a notification to a customer from the dashboard and it is not sent" (reported twice). The real causes, each pinned here:
 *  1. Send was greyed out and the reason ("messaged recently", a missing Arabic title ...) sat in small grey text far from the button, so it read as
 *     "it did not send". The reason now travels with the plan (`plan.block`), is shown next to the button, and one customer gets "Send anyway".
 *  2. After Send the window said "sent" for everything (a phone with notifications off, no phone, a refused push, still in the queue). It now says what
 *     really happened (the inbox message is saved at once, the push and WhatsApp follow through the queue), and a send that is still going updates by itself.
 *  3. A push Google refused, or one that was never tried because the customer switched push off, was recorded as a plain "sent".
 */

/** What Google answers to a push in this test (a second Http::fake() would never win over the first, so the answer is swapped here instead). */
function nmFcmAnswer(?Closure $set = null, bool $reset = false): Closure
{
    static $answer = null;
    if ($reset) {
        $answer = null;
    }
    if ($set) {
        $answer = $set;
    }

    return $answer ?? fn () => Http::response(['name' => 'projects/al-bahar-home/messages/1']);
}

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->staff = staffWithRole('marketing_manager', null, ['name' => 'Sara Team']);
    configureFirebase();
    nmFcmAnswer(reset: true);
    npFake(fn ($request) => nmFcmAnswer()($request));
    $this->actingAs($this->staff);
});

/** The customer was messaged $hours hours ago (by hand), so the quiet time applies. */
function nmMessaged(User $c, int $hours = 3): void
{
    $batch = MessageBatch::create(['created_by' => test()->staff->id, 'template_name' => 'x', 'kind' => 'custom', 'source' => 'single', 'channels' => ['notification'], 'content' => [], 'audience' => [],
        'recipients_count' => 1, 'status' => 'done']);
    CustomerMessage::create(['batch_id' => $batch->id, 'user_id' => $c->id, 'channel' => 'notification', 'locale' => 'en', 'status' => 'sent', 'sent_at' => now()->subHours($hours)]);
}

/** The pushes Google was asked for, as decoded messages. @return list<array<string, mixed>> */
function nmPushes(): array
{
    $out = [];
    Http::assertSent(function (Request $r) use (&$out) {
        if (str_contains($r->url(), 'messages:send')) {
            $out[] = $r->data()['message'];
        }

        return true;
    });

    return $out;
}

/** The queue is a database table nobody works off (the background worker is down): jobs wait there, but a job run inline still runs. */
function nmNoWorker(): void
{
    config(['queue.default' => 'database']);
    DB::table('jobs')->delete();
}

describe('Send never goes quiet', function () {
    it('says why Send is not available as part of the plan, and offers "Send anyway" for one recently messaged customer', function () {
        $c = mcCustomer();
        npDevice($c, 'granted');
        nmMessaged($c, 3);

        $comp = mcOpen([$c->id]);

        expect($comp->get('plan')['block'])->toMatchArray(['code' => 'recently_contacted', 'override' => true])
            ->and($comp->get('plan')['block']['text'])->toContain('Noor was last messaged 3 hours ago')->toContain('every 24 hours')->toContain('send it to this one customer');
        // the footer shows it with the button, not only the list further down
        $comp->assertSeeHtml('data-testid="blocker"')->assertSeeHtml('data-testid="send-anyway-now"')->assertSeeHtml('@click="blocked ? showWhy() : submit(() => $wire.requestSend())"')
            ->assertDontSeeHtml(':disabled="blocked"'); // a pressed Send explains itself instead of doing nothing
        expect(MessageBatch::where('template_name', '!=', 'x')->count())->toBe(0);
    });

    it('sends in one press with "Send anyway", and every other rule still applies', function () {
        $c = mcCustomer();
        npDevice($c, 'granted');
        nmMessaged($c, 3);

        $comp = mcOpen([$c->id])->call('sendAnyway')->assertSet('step', 'sent')->assertSet('ignoreCooldown', true);

        expect($comp->get('result')['single']['state'])->toBe('shown')
            ->and(CustomerMessage::where('user_id', $c->id)->where('status', 'sent')->count())->toBe(2);

        // no marketing consent: not even "Send anyway" can send it
        $no = mcCustomer(['marketing_consent_at' => null]);
        $blocked = mcOpen([$no->id]);
        expect($blocked->get('plan')['block'])->toMatchArray(['code' => 'no_marketing_consent', 'override' => false])
            ->and($blocked->get('plan')['block']['text'])->toBe("Noor can't receive this right now: has not agreed to marketing messages.");
        $blocked->call('sendAnyway')->assertSet('step', 'compose');
        expect(CustomerMessage::where('user_id', $no->id)->count())->toBe(0);
    });

    it('never offers "Send anyway" for several customers, and names the most common reason', function () {
        $a = mcCustomer();
        $b = mcCustomer();
        nmMessaged($a);
        nmMessaged($b);

        $comp = mcOpen([$a->id, $b->id]);

        expect($comp->get('plan')['block'])->toMatchArray(['code' => 'recently_contacted', 'override' => false])
            ->and($comp->get('plan')['block']['text'])->toBe('Nobody can receive this right now. Most common reason: already messaged recently (2).');
        $comp->call('sendAnyway')->assertSet('step', 'compose')->assertSet('ignoreCooldown', false);
    });

    it('has no block when somebody can receive it, or when it is scheduled', function () {
        $c = mcCustomer();
        expect(mcOpen([$c->id])->get('plan')['block'])->toBeNull();

        $recent = mcCustomer();
        nmMessaged($recent);
        // a scheduled send is checked again when it goes out, so it is not held back now
        expect(mcOpen([$recent->id])->call('pickSchedule', 'tomorrow')->call('refreshPlan')->get('plan')['block'])->toBeNull();
    });

    it('tells the team before sending that a customer who switched push off gets the inbox only', function () {
        $c = mcCustomer();
        npDevice($c, 'granted');
        NotificationPreference::create(['user_id' => $c->id, 'channel' => 'push', 'category' => 'marketing', 'is_enabled' => false]);

        $comp = mcOpen([$c->id]);

        expect($comp->get('plan')['pushOff'])->toBeTrue();
        $comp->assertSeeHtml('data-testid="push-off-warning"')->assertSee('Noor switched off push for offers and news in the app');
        expect(mcOpen([mcCustomer()->id])->get('plan')['pushOff'])->toBeFalse();
    });
});

describe('the push that really goes out', function () {
    it('goes from the composer to Google with the channel, priority and ids the app needs', function () {
        $c = mcCustomer(['name' => 'Noor Ali']);
        $device = npDevice($c, 'granted', 'phone-token-1');

        mcOpen([$c->id])->set('content.deep_link', 'albahar://orders')->call('requestSend')->assertSet('step', 'sent');

        $row = CustomerMessage::where('user_id', $c->id)->where('channel', 'notification')->firstOrFail();
        $inbox = Notification::where('user_id', $c->id)->where('event', 'staff.message')->firstOrFail();
        $pushes = nmPushes();
        expect($pushes)->toHaveCount(1);
        $m = $pushes[0];
        expect($m['token'])->toBe('phone-token-1')
            ->and($m['notification'])->toBe(['title' => 'Hello Noor', 'body' => 'A note for Noor'])
            ->and($m['android']['priority'])->toBe('high')
            ->and($m['android']['notification']['channel_id'])->toBe('albahar_offers')
            ->and($m['data'])->toMatchArray(['event' => 'staff.message', 'category' => 'marketing', 'deep_link' => 'albahar://orders', 'delivery_id' => $row->id, 'notification_id' => $inbox->id])
            ->and($row->status)->toBe('sent')->and($row->meta)->toMatchArray(['devices' => 1, 'pushed' => 1, 'rejected' => 0])
            ->and($device->fresh()->disabled_at)->toBeNull();
    });

    it('saves the inbox message inside the request and leaves the push to the queue, so a slow Google can never hold the request', function () {
        nmNoWorker();
        $c = mcCustomer();
        npDevice($c, 'granted');

        $comp = mcOpen([$c->id])->call('requestSend')->assertSet('step', 'sent');

        // inside the request: the inbox message only, no call to Google at all
        Http::assertNothingSent();
        $row = CustomerMessage::where('user_id', $c->id)->firstOrFail();
        expect(Notification::where('user_id', $c->id)->where('event', 'staff.message')->count())->toBe(1)
            ->and($row->status)->toBe('queued')->and($row->meta['inbox_id'])->toBe(Notification::where('user_id', $c->id)->value('id'))
            ->and(DB::table('jobs')->count())->toBe(1)->and(MessageBatch::firstOrFail()->status)->toBe('sending')
            ->and($comp->get('result')['single']['state'])->toBe('inbox')->and($comp->get('result'))->toMatchArray(['pending' => true]);
        $comp->assertSee('Saved in the app inbox')->assertSeeHtml('data-state="inbox"')->assertSeeHtml('wire:poll.2s.visible="refreshResult"')->assertDontSee('Sent to Noor');
        $comp->assertDispatched('toast', type: 'success', message: __('messaging_composer.res_one.inbox.title'));

        // the worker runs the job: one push, the SAME inbox entry (never a second one), and only now "sent to the phone"
        SendCustomerMessageJob::dispatchSync(MessageBatch::firstOrFail()->id, $c->id);
        $comp->call('refreshResult');

        expect(nmPushes())->toHaveCount(1)->and(Notification::where('user_id', $c->id)->where('event', 'staff.message')->count())->toBe(1)
            ->and(nmPushes()[0]['data']['notification_id'])->toBe($row->meta['inbox_id'])
            ->and($comp->get('result')['single']['state'])->toBe('shown')->and($comp->get('result'))->toMatchArray(['pending' => false])
            ->and(CustomerMessage::where('user_id', $c->id)->value('status'))->toBe('sent');
        $comp->assertSee('Sent to Noor\'s phone');
    });

    it('does not call WhatsApp inside the request either', function () {
        nmNoWorker();
        mcConnectN8n();
        $c = mcCustomer();
        mcWhatsApp($c);
        $c->update(['phone' => '+97455123456']);

        $comp = mcOpen([$c->id])->set('channels', ['whatsapp'])->call('requestSend')->assertSet('step', 'sent');

        Http::assertNothingSent();
        expect($comp->get('result')['single']['state'])->toBe('waiting')->and(CustomerMessage::where('user_id', $c->id)->value('status'))->toBe('queued');
        SendCustomerMessageJob::dispatchSync(MessageBatch::firstOrFail()->id, $c->id);
        expect($comp->call('refreshResult')->get('result')['single']['state'])->toBe('sent');
    });

    it('still queues the whole delivery when saving the inbox in the request fails, so nothing is lost', function () {
        nmNoWorker();
        $c = mcCustomer();
        npDevice($c, 'granted');
        $this->mock(MessageDelivery::class)->shouldReceive('saveInbox')->andThrow(new RuntimeException('boom'));

        $comp = mcOpen([$c->id])->call('requestSend')->assertSet('step', 'sent');

        expect(DB::table('jobs')->count())->toBe(1)->and(MessageBatch::firstOrFail()->status)->toBe('sending')->and($comp->get('result')['single']['state'])->toBe('waiting')
            ->and(CustomerMessage::where('user_id', $c->id)->value('status'))->toBe('queued');
    });

    it('does not save an inbox message for a customer who may not get it', function () {
        $c = mcCustomer();
        $comp = mcOpen([$c->id]);
        $c->update(['marketing_consent_at' => null]); // consent withdrawn while the window was open

        $comp->call('send');

        expect(Notification::where('user_id', $c->id)->count())->toBe(0)->and(CustomerMessage::where('user_id', $c->id)->value('status'))->toBe('skipped');
    });

    it('retries a push once when Google is busy, and says "refused" with the error word when it stays down', function () {
        Sleep::fake();
        $c = mcCustomer();
        npDevice($c, 'granted');
        nmFcmAnswer(fn () => Http::response(['error' => ['status' => 'UNAVAILABLE', 'message' => 'try later']], 503));

        $comp = mcOpen([$c->id])->call('requestSend')->assertSet('step', 'sent');

        expect(nmPushes())->toHaveCount(2); // the second try
        Sleep::assertSleptTimes(1);
        $row = CustomerMessage::where('user_id', $c->id)->firstOrFail();
        expect($row->status)->toBe('sent')->and($row->meta)->toMatchArray(['devices' => 1, 'pushed' => 0, 'rejected' => 1, 'error' => 'UNAVAILABLE'])
            ->and($comp->get('result')['single']['state'])->toBe('rejected');
        $comp->assertSee('Google refused the push for Noor\'s phone (UNAVAILABLE)');
    });

    it('is saved as sent to the phone when the second try works', function () {
        Sleep::fake();
        $c = mcCustomer();
        npDevice($c, 'granted');
        $calls = 0;
        nmFcmAnswer(function () use (&$calls) {
            return ++$calls === 1 ? Http::response(['error' => ['status' => 'UNAVAILABLE', 'message' => 'busy']], 503) : Http::response(['name' => 'projects/p/messages/1']);
        });

        $comp = mcOpen([$c->id])->call('requestSend');

        expect($comp->get('result')['single']['state'])->toBe('shown')
            ->and(CustomerMessage::where('user_id', $c->id)->value('meta'))->toMatchArray(['pushed' => 1, 'rejected' => 0]);
    });

    it('switches a phone off when Google says its registration is dead, and says so', function () {
        $c = mcCustomer();
        $device = npDevice($c, 'granted');
        nmFcmAnswer(fn () => Http::response(['error' => ['status' => 'NOT_FOUND', 'message' => 'gone', 'details' => [['errorCode' => 'UNREGISTERED']]]], 404));

        $comp = mcOpen([$c->id])->call('requestSend');

        expect($comp->get('result')['single']['state'])->toBe('rejected')->and($device->fresh()->disabled_at)->not->toBeNull()
            ->and(CustomerMessage::where('user_id', $c->id)->value('meta'))->toMatchArray(['rejected' => 1, 'error' => 'UNREGISTERED']);
    });
});

describe('what did not cause it', function () {
    it('is not held back by the Notification controls: a message sent by hand is never switched there', function () {
        $rules = app(NotificationRules::class);
        foreach (array_keys(NotificationCatalog::EVENTS) as $event) {
            try {
                $rules->set($event, false, [], $this->staff);
            } catch (DomainException) {
                // sign-in codes can never be switched off
            }
        }
        $c = mcCustomer();
        npDevice($c, 'granted');

        $comp = mcOpen([$c->id])->call('requestSend')->assertSet('step', 'sent');

        expect($comp->get('result')['single']['state'])->toBe('shown')->and(nmPushes())->toHaveCount(1);
    });
});

describe('the result window tells what really happened', function () {
    it('says what each kind of phone did, never a bare "sent"', function (?string $permission, bool $device, string $state, string $title) {
        $c = mcCustomer();
        if ($device) {
            npDevice($c, $permission);
        }

        $comp = mcOpen([$c->id])->call('requestSend')->assertSet('step', 'sent');

        expect($comp->get('result')['single']['state'])->toBe($state)->and($comp->get('result')['single']['lines'][0])->toMatchArray(['channel' => 'notification', 'state' => $state]);
        $comp->assertSeeHtml('data-state="'.$state.'"')->assertSee($title)->assertDontSee(__('messaging_composer.done_sent'));
        expect(CustomerMessage::where('user_id', $c->id)->value('status'))->toBe('sent');
    })->with([
        'notifications on' => ['granted', true, 'shown', 'Sent to Noor\'s phone'],
        'an older app that never reports' => [null, true, 'shown', 'Sent to Noor\'s phone'],
        'notifications off' => ['denied', true, 'silent', 'Saved in the app inbox only'],
        'never asked' => ['not_asked', true, 'silent', 'Saved in the app inbox only'],
        'no phone' => [null, false, 'no_phone', 'Saved in the app inbox only'],
    ]);

    it('says the customer switched push off, and that nothing was pushed', function () {
        $c = mcCustomer();
        npDevice($c, 'granted');
        NotificationPreference::create(['user_id' => $c->id, 'channel' => 'push', 'category' => 'marketing', 'is_enabled' => false]);

        $comp = mcOpen([$c->id])->call('requestSend');

        expect($comp->get('result')['single']['state'])->toBe('customer_off')->and(CustomerMessage::where('user_id', $c->id)->value('meta'))->toMatchArray(['push_tried' => false, 'push_skip' => 'customer']);
        $comp->assertSee('Noor switched off push for offers and news in the app, so no push was sent.');
        Http::assertNothingSent();
    });

    it('says push is not set up when Firebase is off', function () {
        app(Settings::class)->set('push.enabled', false);
        $c = mcCustomer();
        npDevice($c, 'granted');

        $comp = mcOpen([$c->id])->call('requestSend');

        expect($comp->get('result')['single']['state'])->toBe('not_set_up')->and(CustomerMessage::where('user_id', $c->id)->value('meta'))->toMatchArray(['push_skip' => 'not_set_up']);
        $comp->assertSee('Push notifications are not switched on for the platform');
    });

    it('agrees with the toast: a send that reached the inbox only is a warning, never a plain "sent"', function () {
        $c = mcCustomer();

        mcOpen([$c->id])->call('requestSend')->assertDispatched('toast', type: 'warning', message: __('messaging_composer.res_one.no_phone.title'));
        $d = mcCustomer();
        npDevice($d, 'granted');
        mcOpen([$d->id])->call('requestSend')->assertDispatched('toast', type: 'success', message: __('messaging_composer.toast_sent'));
    });

    it('shows one line per channel for one customer who is sent a notification and WhatsApp', function () {
        mcConnectN8n();
        $c = mcCustomer();
        mcWhatsApp($c);
        $c->update(['phone' => '+97455123456']);
        npDevice($c, 'granted');

        $comp = mcOpen([$c->id])->set('channels', ['notification', 'whatsapp'])->call('requestSend')->assertSet('step', 'sent');

        expect(collect($comp->get('result')['single']['lines'])->pluck('state', 'channel')->all())->toBe(['notification' => 'shown', 'whatsapp' => 'sent']);
        $comp->assertSeeHtml('data-testid="done-lines"');
    });

    it('counts where the pushes went when several customers are sent to, and updates by itself while the queue works', function () {
        nmNoWorker();
        $on = mcCustomer();
        npDevice($on, 'granted');
        $off = mcCustomer();
        npDevice($off, 'denied');
        $none = mcCustomer();

        $comp = mcOpen([$on->id, $off->id, $none->id])->call('requestSend')->call('send')->assertSet('step', 'sent');

        // still in the queue: honest about it, and the window asks again by itself
        expect($comp->get('result'))->toMatchArray(['pending' => true, 'reached' => 3, 'waiting' => 3, 'customers' => 3]);
        $comp->assertSeeHtml('wire:poll.2s.visible="refreshResult"')->assertSeeHtml('data-state="waiting"')->assertSee('3 customers')->assertSeeHtml('data-testid="res-progress"');
        Http::assertNothingSent();

        $batch = MessageBatch::firstOrFail();
        foreach ([$on, $off, $none] as $c) {
            SendCustomerMessageJob::dispatchSync($batch->id, $c->id);
        }
        $comp->call('refreshResult');

        expect($comp->get('result'))->toMatchArray(['pending' => false, 'waiting' => 0, 'reached' => 3])
            ->and($comp->get('result')['phones'])->toBe(['shown' => 1, 'silent' => 1, 'no_phone' => 1, 'rejected' => 0, 'customer_off' => 0, 'not_set_up' => 0]);
        $comp->assertDontSeeHtml('wire:poll.2s')->assertSeeHtml('data-testid="result-phones"')->assertSee('Push sent to the phone')->assertSee('Inbox only: no phone registered');
    });

    it('stops asking after about 90 seconds and says it is taking longer than usual', function () {
        nmNoWorker();

        $comp = mcOpen([mcCustomer()->id, mcCustomer()->id])->call('requestSend')->call('send');
        foreach (range(1, 44) as $i) {
            $comp->call('refreshResult');
        }
        expect($comp->get('result')['slow'])->toBeFalse();
        $comp->assertSeeHtml('wire:poll.2s.visible="refreshResult"');

        $comp->call('refreshResult');

        expect($comp->get('result'))->toMatchArray(['pending' => true, 'slow' => true]);
        $comp->assertDontSeeHtml('wire:poll.2s')->assertSee(__('messaging_composer.res_one.slow.title'));
    });

    it('only reads the send it just made, and only for someone who may send', function () {
        nmNoWorker();
        $comp = mcOpen([mcCustomer()->id, mcCustomer()->id])->call('requestSend')->call('send');
        $this->actingAs(staffWithRole('customer_support'));

        $comp->call('refreshResult')->assertForbidden();
    });

    it('says a customer who was skipped was not sent, with the reason and nothing else', function () {
        $c = mcCustomer();
        $comp = mcOpen([$c->id]);
        $c->update(['marketing_consent_at' => null]); // consent withdrawn while the window was open

        $comp->call('send')->assertSet('step', 'sent');

        expect($comp->get('result')['single']['state'])->toBe('skipped')->and(CustomerMessage::where('user_id', $c->id)->first())->status->toBe('skipped')->reason->toBe('no_marketing_consent');
        $comp->assertSee(__('messaging_composer.res_one.skipped.title'))->assertSee('Has not agreed to marketing messages. Nothing was sent to this customer.');
        $comp->assertDispatched('toast', type: 'warning');
    });

    it('speaks Arabic for the block and the result', function () {
        app()->setLocale('ar');
        $c = mcCustomer(['locale' => 'ar']);
        npDevice($c, 'denied');
        nmMessaged($c, 2);

        $comp = mcOpen([$c->id]);
        expect($comp->get('plan')['block']['text'])->toContain('آخر رسالة وصلت إلى Noor')->toContain('24 ساعة');
        $comp->call('sendAnyway')->assertSee('حُفظت في صندوق التطبيق فقط')->assertSee('الإشعارات متوقفة على هاتف');
    });
});

describe('the headline follows the best outcome across the channels', function () {
    /** A customer with the app inbox and push switched off for marketing and WhatsApp on, so only WhatsApp can go out. */
    function nmWhatsAppOnly(): User
    {
        mcConnectN8n();
        $c = mcCustomer();
        $c->update(['phone' => '+97455123456']);
        mcWhatsApp($c);
        foreach (['in_app', 'push'] as $channel) {
            NotificationPreference::create(['user_id' => $c->id, 'channel' => $channel, 'category' => 'marketing', 'is_enabled' => false]);
        }

        return $c;
    }

    it('says "sent by WhatsApp" when WhatsApp went out and the notification was skipped, never "not sent"', function () {
        $c = nmWhatsAppOnly();

        $comp = mcOpen([$c->id])->set('channels', ['notification', 'whatsapp']);
        expect($comp->get('plan')['reachable'])->toBe(1);
        $comp->call('requestSend')->assertSet('step', 'sent');

        $one = $comp->get('result')['single'];
        expect($one['state'])->toBe('partly')->and($one['by'])->toBe('WhatsApp')
            ->and(collect($one['lines'])->pluck('state', 'channel')->all())->toBe(['notification' => 'skipped', 'whatsapp' => 'sent']);
        $comp->assertSeeHtml('data-state="partly"')->assertSee('Sent by WhatsApp')->assertSee('Not everything went out. Notification: Turned this channel off.')
            ->assertDontSee('Nothing was sent to this customer')->assertDontSee(__('messaging_composer.res_one.skipped.title'))
            ->assertDispatched('toast', type: 'warning', message: 'Sent by WhatsApp');
    });

    it('says "sent by notification" when the notification went out and WhatsApp was skipped', function () {
        mcConnectN8n();
        $c = mcCustomer(); // no WhatsApp opt-in
        npDevice($c, 'granted');

        $comp = mcOpen([$c->id])->set('channels', ['notification', 'whatsapp'])->call('requestSend');

        expect($comp->get('result')['single'])->toMatchArray(['state' => 'partly', 'by' => 'Notification']);
        $comp->assertSee('Sent by Notification')->assertSee('WhatsApp: Has not opted in to WhatsApp.');
    });

    it('keeps one plain headline when both channels agree', function () {
        mcConnectN8n();
        $c = mcCustomer();
        mcWhatsApp($c);
        $c->update(['phone' => '+97455123456']);
        npDevice($c, 'granted');

        $comp = mcOpen([$c->id])->set('channels', ['notification', 'whatsapp'])->call('requestSend');

        expect($comp->get('result')['single']['state'])->toBe('shown');
        $comp->assertSee('Sent to Noor\'s phone');
    });

    it('speaks Arabic when channels disagree', function () {
        app()->setLocale('ar');
        $c = nmWhatsAppOnly();

        $comp = mcOpen([$c->id])->set('channels', ['notification', 'whatsapp'])->call('requestSend');

        $comp->assertSee('أُرسلت عبر واتساب')->assertSee('لم يخرج كل شيء.');
    });

    it('picks the headline from every line: pending first, then what reached the customer, then the inbox, a failure, and skipped only when all were', function (array $states, string $headline) {
        $lines = [];
        foreach ($states as $channel => $state) {
            $lines[] = ['channel' => $channel, 'state' => $state, 'reason' => null, 'error' => null];
        }
        $method = new ReflectionMethod(MessageComposer::class, 'headline');

        expect($method->invoke(new MessageComposer, $lines))->toBe($headline);
    })->with([
        'one phone push' => [['notification' => 'shown'], 'shown'],
        'both out' => [['notification' => 'shown', 'whatsapp' => 'sent'], 'shown'],
        'only WhatsApp out' => [['notification' => 'skipped', 'whatsapp' => 'sent'], 'partly'],
        'WhatsApp out, notification failed' => [['notification' => 'failed', 'whatsapp' => 'sent'], 'partly'],
        'notification out, WhatsApp failed' => [['notification' => 'shown', 'whatsapp' => 'failed'], 'partly'],
        'WhatsApp out, inbox only' => [['notification' => 'no_phone', 'whatsapp' => 'sent'], 'partly'],
        'inbox only, WhatsApp skipped' => [['notification' => 'no_phone', 'whatsapp' => 'skipped'], 'no_phone'],
        'skipped and failed' => [['notification' => 'skipped', 'whatsapp' => 'failed'], 'failed'],
        'everything skipped' => [['notification' => 'skipped', 'whatsapp' => 'skipped'], 'skipped'],
        'cancelled' => [['notification' => 'cancelled'], 'cancelled'],
        'inbox saved, WhatsApp sent' => [['notification' => 'inbox', 'whatsapp' => 'sent'], 'inbox'],
        'one still waiting' => [['notification' => 'sent', 'whatsapp' => 'waiting'], 'waiting'],
    ]);
});

describe('a double press sends once', function () {
    /** A request exactly as the composer builds it, with a send key. */
    function nmRequest(User $customer, string $key, array $content = [], bool $anyway = false): SendRequest
    {
        return new SendRequest(AudienceSelection::ofIds([$customer->id]), ['notification'], 'custom', $content ?: mcContent(), null, null, $anyway, $key);
    }

    it('returns the first send for the same key and message: one inbox entry, one push, one set of rows', function () {
        $c = mcCustomer();
        npDevice($c, 'granted');
        $svc = app(CustomerMessaging::class);

        $first = $svc->send($this->staff, nmRequest($c, 'key-1'));
        $second = $svc->send($this->staff, nmRequest($c, 'key-1')); // the double click, or the second try after a timeout

        expect($second->id)->toBe($first->id)->and(MessageBatch::count())->toBe(1)->and(CustomerMessage::count())->toBe(1)
            ->and(Notification::where('user_id', $c->id)->count())->toBe(1)->and(nmPushes())->toHaveCount(1);
    });

    it('does not count a repeat against the limits or the "Send anyway" cap, and says nothing new in the audit log', function () {
        $c = mcCustomer();
        nmMessaged($c, 2);
        $svc = app(CustomerMessaging::class);

        $svc->send($this->staff, nmRequest($c, 'key-2', anyway: true));
        $svc->send($this->staff, nmRequest($c, 'key-2', anyway: true));
        $svc->send($this->staff, nmRequest($c, 'key-2', anyway: true));

        expect(RateLimiter::attempts('customer-messages:override:'.$c->id))->toBe(1)->and(RateLimiter::attempts('customer-messages:single:'.$this->staff->id))->toBe(1)
            ->and(AuditLog::where('action', 'customer_messages.sent')->count())->toBe(1);
    });

    it('sends again for a different key, for different text, and for a different staff member', function () {
        $c = mcCustomer();
        $other = staffWithRole('marketing_manager');
        $svc = app(CustomerMessaging::class);
        $svc->send($this->staff, nmRequest($c, 'key-3'));

        $svc->send($this->staff, nmRequest($c, 'key-4', anyway: true)); // another window
        $svc->send($this->staff, nmRequest($c, 'key-3', mcContent(['body_en' => 'A different note for {{name}}']), anyway: true)); // the text was changed after a lost answer
        $svc->send($other, nmRequest($c, 'key-3', anyway: true));

        expect(MessageBatch::count())->toBe(4);
    });

    it('gives every open window its own key, and sends twice from two windows', function () {
        $c = mcCustomer();
        npDevice($c, 'granted');

        $a = mcOpen([$c->id]);
        $b = mcOpen([$c->id]);
        expect($a->get('sendKey'))->not->toBe('')->and($a->get('sendKey'))->not->toBe($b->get('sendKey'));
        $a->call('requestSend')->assertSet('step', 'sent');
        $b->set('ignoreCooldown', true)->call('requestSend')->assertSet('step', 'sent');

        expect(MessageBatch::count())->toBe(2);
    });

    it('makes a second press of Send in the same window do nothing', function () {
        $c = mcCustomer();

        $comp = mcOpen([$c->id])->call('requestSend')->assertSet('step', 'sent')->call('requestSend')->call('send')->call('sendAnyway');

        expect(MessageBatch::count())->toBe(1);
    });

    it('guards every send button in the window against a second press while the first is still going', function () {
        $c = mcCustomer();
        nmMessaged($c);

        mcOpen([$c->id])->assertSeeHtml('submit(() => $wire.requestSend())')->assertSeeHtml('submit(() => $wire.sendAnyway())')->assertSeeHtml('submit(() => $wire.send())')
            ->assertSeeHtml(':disabled="sending"');
    });
});

describe('"Send anyway" leaves a trace and is limited', function () {
    it('writes the override and the customer into the audit entry of the send', function () {
        $c = mcCustomer();
        nmMessaged($c, 3);

        mcOpen([$c->id])->call('sendAnyway')->assertSet('step', 'sent');
        mcOpen([mcCustomer()->id])->call('requestSend')->assertSet('step', 'sent');

        $entries = AuditLog::where('action', 'customer_messages.sent')->orderBy('created_at')->get();
        expect($entries)->toHaveCount(2)->and($entries[0]->new_values)->toMatchArray(['override_cooldown' => true, 'recipient' => $c->id, 'recipients' => 1])
            ->and($entries[1]->new_values['override_cooldown'])->toBeFalse()->and($entries[0]->actor_id)->toBe($this->staff->id);
    });

    it('allows three overrides per customer per hour, then says so in plain words and sends nothing', function () {
        $c = mcCustomer();
        $svc = app(CustomerMessaging::class);

        foreach (range(1, 3) as $i) {
            nmMessaged($c, 1); // each time the customer was messaged recently, so the override is really needed
            mcOpen([$c->id])->call('sendAnyway')->assertSet('step', 'sent');
        }
        expect(MessageBatch::where('template_name', '!=', 'x')->count())->toBe(3)->and($svc->overrideAvailable($c->id))->toBeFalse();

        $comp = mcOpen([$c->id]);
        expect($comp->get('plan')['block'])->toMatchArray(['code' => 'recently_contacted', 'override' => false])
            ->and($comp->get('plan')['block']['text'])->toContain('"Send anyway" has already been used 3 times for this customer in the last hour');
        $comp->call('sendAnyway')->assertSet('step', 'compose');
        $comp->set('ignoreCooldown', true);
        $comp->call('requestSend')->assertSet('step', 'compose');
        expect(MessageBatch::where('template_name', '!=', 'x')->count())->toBe(3);
        // and the service itself refuses, whoever asks, with the reason
        try {
            $svc->send(staffWithRole('marketing_manager'), nmRequest($c, 'cap-key', anyway: true));
            $this->fail('the fourth override was allowed');
        } catch (DomainException $e) {
            expect($e->status)->toBe(429)->and($e->getMessage())->toBe('"Send anyway" has already been used 3 times for this customer in the last hour. Try again in about 60 minutes.');
        }
    });

    it('is counted per customer, not per staff member, and not when the quiet time was not in the way', function () {
        $a = mcCustomer();
        $b = mcCustomer();
        $svc = app(CustomerMessaging::class);
        foreach (range(1, 3) as $i) {
            nmMessaged($a, 1);
            $svc->send($this->staff, nmRequest($a, 'a-'.$i, anyway: true));
        }

        expect($svc->overrideAvailable($a->id))->toBeFalse()->and($svc->overrideAvailable($b->id))->toBeTrue();
        // "Send anyway" ticked for a customer nobody messaged recently skips nothing, so it is not counted
        $svc->send($this->staff, nmRequest($b, 'b-1', anyway: true));
        expect(RateLimiter::attempts('customer-messages:override:'.$b->id))->toBe(0);
    });

    it('says it in Arabic', function () {
        app()->setLocale('ar');
        $c = mcCustomer();
        $svc = app(CustomerMessaging::class);
        foreach (range(1, 3) as $i) {
            nmMessaged($c, 1);
            $svc->send($this->staff, nmRequest($c, 'ar-'.$i, anyway: true));
        }
        nmMessaged($c, 1);

        $comp = mcOpen([$c->id]);

        expect($comp->get('plan')['block']['override'])->toBeFalse()->and($comp->get('plan')['block']['text'])->toContain('استُخدم «أرسل رغم ذلك» 3 مرات');
        try {
            $svc->send($this->staff, nmRequest($c, 'ar-4', anyway: true));
            $this->fail('the fourth override was allowed');
        } catch (DomainException $e) {
            expect($e->getMessage())->toContain('استُخدم «أرسل رغم ذلك» 3 مرات');
        }
    });
});

describe('the history and the customer list say it too', function () {
    it('words what Google refused and what was never tried', function (array $meta, ?array $note) {
        expect(DevicePushReach::deliveryNote($meta))->toBe($note);
    })->with([
        'a phone tried and refused' => [['in_app' => true, 'devices' => 1, 'pushed' => 0, 'silent' => 0, 'rejected' => 1, 'error' => 'UNREGISTERED', 'push_tried' => true], ['kind' => 'rejected', 'reached' => 0, 'silent' => 0, 'error' => 'UNREGISTERED']],
        'refused, with no error word saved (an older row)' => [['in_app' => true, 'devices' => 2, 'pushed' => 0], ['kind' => 'rejected', 'reached' => 0, 'silent' => 0, 'error' => null]],
        'one of two refused is not worth a note' => [['in_app' => true, 'devices' => 2, 'pushed' => 1, 'silent' => 0, 'rejected' => 1, 'push_tried' => true], null],
        'the customer switched push off' => [['in_app' => true, 'devices' => 0, 'pushed' => 0, 'silent' => 0, 'push_tried' => false, 'push_skip' => 'customer'], ['kind' => 'customer_off', 'reached' => 0, 'silent' => 0]],
        'push is not set up' => [['in_app' => true, 'devices' => 0, 'pushed' => 0, 'silent' => 0, 'push_tried' => false, 'push_skip' => 'not_set_up'], ['kind' => 'not_set_up', 'reached' => 0, 'silent' => 0]],
        'not tried, no reason saved (an older row): nothing is guessed' => [['in_app' => true, 'devices' => 0, 'pushed' => 0, 'silent' => 0, 'push_tried' => false], null],
    ]);

    it('names the outcome of a finished notification', function (?array $meta, ?string $outcome) {
        expect(DevicePushReach::deliveryOutcome($meta))->toBe($outcome);
    })->with([
        'nothing saved' => [null, null],
        'shown' => [['devices' => 1, 'pushed' => 1, 'silent' => 0, 'push_tried' => true], 'shown'],
        'an older row' => [['devices' => 1, 'pushed' => 1], 'shown'],
        'one silent, one not' => [['devices' => 2, 'pushed' => 2, 'silent' => 1, 'push_tried' => true], 'shown'],
        'every accepted phone silent' => [['devices' => 2, 'pushed' => 2, 'silent' => 2, 'push_tried' => true], 'silent'],
        'no phone' => [['devices' => 0, 'pushed' => 0, 'silent' => 0, 'push_tried' => true], 'no_phone'],
        'refused' => [['devices' => 1, 'pushed' => 0, 'silent' => 0, 'push_tried' => true], 'rejected'],
        'customer off' => [['devices' => 0, 'pushed' => 0, 'push_tried' => false, 'push_skip' => 'customer'], 'customer_off'],
        'not set up' => [['devices' => 0, 'pushed' => 0, 'push_tried' => false, 'push_skip' => 'not_set_up'], 'not_set_up'],
        'not tried, no reason' => [['devices' => 0, 'pushed' => 0, 'push_tried' => false], null],
    ]);

    it('shows the refused push and the switched-off push on the customer\'s own list of messages', function () {
        $refused = mcCustomer();
        npDevice($refused, 'granted');
        nmFcmAnswer(fn () => Http::response(['error' => ['status' => 'INVALID_ARGUMENT', 'message' => 'bad']], 400));
        mcOpen([$refused->id])->call('requestSend');

        Livewire::test(CustomerTimeline::class, ['customer' => $refused])->assertSeeHtml('data-kind="rejected"')->assertSee(__('messaging_phones.note.rejected'));
    });

    it('shows what happened to every customer in the history of the send', function () {
        $refused = mcCustomer();
        npDevice($refused, 'granted');
        nmFcmAnswer(fn () => Http::response(['error' => ['status' => 'INVALID_ARGUMENT', 'message' => 'bad']], 400));
        mcOpen([$refused->id])->call('requestSend');
        $off = mcCustomer();
        NotificationPreference::create(['user_id' => $off->id, 'channel' => 'push', 'category' => 'marketing', 'is_enabled' => false]);
        mcOpen([$off->id])->call('requestSend');

        $kinds = [];
        foreach (MessageBatch::all() as $batch) {
            $html = Livewire::test(MessageHistory::class)->call('openView', $batch->id)->html();
            preg_match_all('/data-testid="phones-note" data-kind="([a-z_]+)"/', $html, $m);
            array_push($kinds, ...$m[1]);
        }
        sort($kinds);
        expect($kinds)->toBe(['customer_off', 'rejected']);
    });

    it('has every new string in both languages', function () {
        foreach (['messaging_composer', 'messaging_phones'] as $file) {
            expect(mcKeys(require lang_path("ar/{$file}.php")))->toBe(mcKeys(require lang_path("en/{$file}.php")));
        }
    });
});
