<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Notifications\Services\NotificationChannelStatus;
use App\Domain\Reports\Mail\OwnerPackMail;
use App\Domain\Reports\Models\OwnerPackDelivery;
use App\Domain\Reports\Models\OwnerPackSend;
use App\Domain\Reports\Services\OwnerPackSender;
use App\Domain\Reports\Services\OwnerPackSettings;
use App\Domain\Reports\Services\SchedulerHeartbeat;
use App\Domain\Settings\Services\Settings;
use App\Support\DomainException;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

require_once __DIR__.'/OwnerNumbersSupport.php';

beforeEach(function () {
    $this->world = onWorld();
    $this->settings = app(Settings::class);
    $this->sender = fn () => app(OwnerPackSender::class);
    $this->at = fn (string $local) => CarbonImmutable::parse($local, 'Asia/Qatar');
    $this->channels = function (array $ready) {
        $this->app->instance(NotificationChannelStatus::class, new class($ready) extends NotificationChannelStatus
        {
            public function __construct(private array $ready) {}

            public function ready(): array
            {
                return $this->ready;
            }
        });
        $this->app->forgetInstance(OwnerPackSettings::class);
    };
    ($this->channels)(['email' => true, 'whatsapp' => false]);
    $this->turnOn = function (array $emails = ['owner@albahar.test', 'partner@albahar.test'], array $more = []) {
        foreach (['owner_pack.enabled' => true, 'owner_pack.emails' => $emails, 'owner_pack.email_enabled' => true] + $more as $k => $v) {
            $this->settings->set($k, $v);
        }
    };
    $this->monday = fn (string $time = '08:00') => ($this->at)('2026-11-16 '.$time);        // Monday after week 46
    $this->sent = fn () => OwnerPackSend::where('kind', 'scheduled')->orderBy('created_at')->get();
    onOrder([[$this->world->a, '250.00']], ['placed_at' => ($this->at)('2026-11-10 12:00')->utc()]);
});

describe('when it is sent', function () {
    it('sends nothing while it is switched off, and says so', function () {
        Mail::fake();
        $this->travelTo(($this->monday)());

        expect(($this->sender)()->sendDue()['state'])->toBe('off');
        Mail::assertNothingSent();
        expect(OwnerPackSend::count())->toBe(0);
    });

    it('sends the pack of the week that ended once the chosen day and time has come, not a minute before', function () {
        Mail::fake();
        ($this->turnOn)();

        $this->travelTo(($this->monday)('07:59'));
        expect(($this->sender)()->sendDue()['state'])->toBe('not_due');
        $this->travelTo(($this->monday)('08:00'));
        $r = ($this->sender)()->sendDue();

        expect($r)->toBe(['state' => 'sent', 'week' => '2026-W46', 'sends' => ['email:sent']]);
        Mail::assertSent(OwnerPackMail::class, 2);
        $send = OwnerPackSend::first();
        expect([$send->iso_week, $send->channel, $send->kind, $send->status, $send->recipients_count, $send->sent_count, $send->failed_count])->toBe(['2026-W46', 'email', 'scheduled', 'sent', 2, 2, 0])
            ->and(AuditLog::where('action', 'owner_pack.sent')->count())->toBe(1);
    });

    it('follows the weekday and time the owner chose', function () {
        Mail::fake();
        ($this->turnOn)(['owner@albahar.test'], ['owner_pack.day' => 3, 'owner_pack.time' => '09:30']);        // Wednesday

        $this->travelTo(($this->monday)('12:00'));
        expect(($this->sender)()->sendDue()['state'])->toBe('not_due');
        $this->travelTo(($this->at)('2026-11-18 09:29'));
        expect(($this->sender)()->sendDue()['state'])->toBe('not_due');
        $this->travelTo(($this->at)('2026-11-18 09:30'));
        expect(($this->sender)()->sendDue()['state'])->toBe('sent');
    });

    it('is safe to run any number of times: the week is sent once, whoever asks and however often', function () {
        Mail::fake();
        ($this->turnOn)();
        $this->travelTo(($this->monday)());

        foreach (range(1, 4) as $i) {
            ($this->sender)()->sendDue();
        }
        $this->artisan('reports:send-owner-pack')->assertSuccessful();

        Mail::assertSent(OwnerPackMail::class, 2);                                          // two owners, once
        expect(OwnerPackSend::count())->toBe(1);
    });

    it('still sends if the server was down at the chosen time, but not days later, and never the same week twice', function () {
        Mail::fake();
        ($this->turnOn)();

        $this->travelTo(($this->monday)('08:00')->addHours(71));
        expect(($this->sender)()->sendDue()['state'])->toBe('sent');                        // back on Thursday morning: still in time
        OwnerPackSend::query()->delete();
        $this->travelTo(($this->monday)('08:00')->addHours(73));
        expect(($this->sender)()->sendDue()['state'])->toBe('not_due');                     // too late: next week's pack comes on its own
    });

    it('sends the next week\'s pack the week after, as a separate send', function () {
        Mail::fake();
        ($this->turnOn)();

        $this->travelTo(($this->monday)());
        ($this->sender)()->sendDue();
        $this->travelTo(($this->monday)()->addWeek());
        ($this->sender)()->sendDue();

        expect(($this->sent)()->pluck('iso_week')->all())->toBe(['2026-W46', '2026-W47']);
        Mail::assertSent(OwnerPackMail::class, 4);
    });

    it('lets the database refuse a second scheduled send of the same week and channel, even when two runs race', function () {
        $row = fn (string $channel, string $kind) => ['id' => (string) Str::uuid(), 'iso_week' => '2026-W46', 'channel' => $channel, 'kind' => $kind, 'created_at' => now(), 'updated_at' => now()];

        expect(DB::table('owner_pack_sends')->insertOrIgnore($row('email', 'scheduled')))->toBe(1)
            ->and(DB::table('owner_pack_sends')->insertOrIgnore($row('email', 'scheduled')))->toBe(0)             // the same week and channel: refused
            ->and(DB::table('owner_pack_sends')->insertOrIgnore($row('email', 'test')))->toBe(1)                  // tests are never limited
            ->and(DB::table('owner_pack_sends')->insertOrIgnore($row('whatsapp', 'scheduled')))->toBe(1)          // another channel is its own send
            ->and(OwnerPackSend::count())->toBe(3);
    });
});

describe('when something goes wrong', function () {
    it('logs a pack with nobody to send it to, and sends it as soon as someone is added', function () {
        Mail::fake();
        $this->settings->set('owner_pack.enabled', true);
        $this->travelTo(($this->monday)());

        ($this->sender)()->sendDue();
        $row = OwnerPackSend::first();
        expect([$row->status, $row->error, $row->recipients_count])->toBe(['failed', 'no_recipients', 0]);
        Mail::assertNothingSent();

        $this->settings->set('owner_pack.emails', ['late@albahar.test']);
        $this->travelTo(($this->monday)()->addMinutes(5));
        expect(($this->sender)()->sendDue()['sends'])->toBe([]);                              // not before the retry time
        $this->travelTo(($this->monday)()->addMinutes(11));
        ($this->sender)()->sendDue();

        Mail::assertSent(OwnerPackMail::class, 1);
        expect(OwnerPackSend::count())->toBe(1)->and(OwnerPackSend::first()->status)->toBe('sent')->and(OwnerPackSend::first()->attempts)->toBe(2);
    });

    it('never claims to have sent an e-mail a server that only writes to a log could not send, and sends it when the mail account is ready', function () {
        Mail::fake();
        ($this->turnOn)(['owner@albahar.test']);
        ($this->channels)(['email' => false, 'whatsapp' => false]);
        $this->travelTo(($this->monday)());

        ($this->sender)()->sendDue();
        $row = OwnerPackSend::first();
        expect([$row->status, $row->error, $row->sent_count])->toBe(['failed', 'mail_not_ready', 0]);
        Mail::assertNothingSent();

        ($this->channels)(['email' => true, 'whatsapp' => false]);
        $this->travelTo(($this->monday)()->addMinutes(11));
        ($this->sender)()->sendDue();

        Mail::assertSent(OwnerPackMail::class, 1);
        expect(OwnerPackSend::first()->status)->toBe('sent');
    });

    it('records who did not get it and tries again for them only, never sending twice to the others', function () {
        ($this->turnOn)(['good@albahar.test', 'bad@albahar.test']);
        config(['mail.default' => 'array']);
        $failing = true;
        Event::listen(MessageSending::class, function (MessageSending $e) use (&$failing) {
            if ($failing && collect($e->message->getTo())->contains(fn ($a) => $a->getAddress() === 'bad@albahar.test')) {
                throw new RuntimeException('Connection refused');
            }
        });
        $this->travelTo(($this->monday)());

        ($this->sender)()->sendDue();
        $send = OwnerPackSend::first();
        expect([$send->status, $send->sent_count, $send->failed_count, $send->error])->toBe(['partial', 1, 1, 'some_failed']);
        $bad = OwnerPackDelivery::where('recipient', 'bad@albahar.test')->first();
        expect($bad->status)->toBe('failed')->and($bad->error)->toContain('Connection refused');

        $this->travelTo(($this->monday)()->addMinutes(3));
        ($this->sender)()->sendDue();                                                          // too soon to try again
        expect(OwnerPackSend::first()->attempts)->toBe(1);

        $failing = false;
        $this->travelTo(($this->monday)()->addMinutes(11));
        ($this->sender)()->sendDue();

        $sentTo = fn (string $who) => collect(app('mailer')->getSymfonyTransport()->messages())->filter(fn ($m) => collect($m->getEnvelope()->getRecipients())->contains(fn ($r) => $r->getAddress() === $who))->count();
        expect(OwnerPackSend::first()->status)->toBe('sent')->and(OwnerPackSend::first()->sent_count)->toBe(2)->and($sentTo('good@albahar.test'))->toBe(1)->and($sentTo('bad@albahar.test'))->toBe(1);
    });

    it('takes back a send a dead run left "sending", without sending to the ones it already reached', function () {
        Mail::fake();
        ($this->turnOn)(['one@albahar.test', 'two@albahar.test']);
        $this->travelTo(($this->monday)());
        $left = OwnerPackSend::create(['iso_week' => '2026-W46', 'channel' => 'email', 'kind' => 'scheduled', 'status' => 'sending']);
        OwnerPackDelivery::create(['owner_pack_send_id' => $left->id, 'recipient' => 'one@albahar.test', 'status' => 'sent', 'sent_at' => now()]);

        $this->travelTo(($this->monday)()->addMinutes(5));
        expect(($this->sender)()->sendDue()['sends'])->toBe([]);                              // a run may still be working on it
        $this->travelTo(($this->monday)()->addMinutes(20));
        ($this->sender)()->sendDue();

        Mail::assertSent(OwnerPackMail::class, fn ($m) => $m->hasTo('two@albahar.test'));
        Mail::assertSent(OwnerPackMail::class, 1);
        expect($left->fresh()->status)->toBe('sent');
    });

    it('stops trying after a number of attempts', function () {
        ($this->turnOn)(['bad@albahar.test']);
        config(['mail.default' => 'array']);
        Event::listen(MessageSending::class, fn () => throw new RuntimeException('down'));
        $this->travelTo(($this->monday)());

        foreach (range(0, 20) as $i) {
            ($this->sender)()->sendDue(($this->monday)()->addMinutes(11 * $i));
        }

        expect(OwnerPackSend::first()->attempts)->toBe(OwnerPackSender::MAX_ATTEMPTS)->and(OwnerPackSend::first()->status)->toBe('failed');
    });

    it('says "not connected" for WhatsApp while no provider is connected, in the log, every week, and still sends the e-mail', function () {
        Mail::fake();
        ($this->turnOn)(['owner@albahar.test'], ['owner_pack.whatsapp_enabled' => true, 'owner_pack.whatsapp_numbers' => ['+97455123456']]);
        $this->travelTo(($this->monday)());

        $r = ($this->sender)()->sendDue();

        expect($r['sends'])->toBe(['email:sent', 'whatsapp:not_connected']);
        $wa = OwnerPackSend::where('channel', 'whatsapp')->first();
        expect([$wa->status, $wa->error, $wa->recipients_count, $wa->sent_count])->toBe(['not_connected', 'whatsapp_not_connected', 1, 0]);
        ($this->sender)()->sendDue();
        expect(OwnerPackSend::where('channel', 'whatsapp')->count())->toBe(1);               // logged once, not every five minutes
    });

    it('sends a short text to each number when a WhatsApp provider is connected, and records each result', function () {
        Mail::fake();
        config(['services.whatsapp.phone_number_id' => '123', 'services.whatsapp.access_token' => 'token', 'services.whatsapp.api_version' => 'v20.0']);
        Http::fake(['graph.facebook.com/*' => Http::sequence()->push(['messages' => [['id' => 'wamid.1']]])->push(['error' => ['message' => 'Re-engagement message']], 400)]);
        ($this->turnOn)(['owner@albahar.test'], ['owner_pack.whatsapp_enabled' => true, 'owner_pack.whatsapp_numbers' => ['+97455123456', '+97455654321'], 'owner_pack.email_enabled' => false]);
        ($this->channels)(['email' => true, 'whatsapp' => true]);
        $this->travelTo(($this->monday)());

        ($this->sender)()->sendDue();

        $wa = OwnerPackSend::where('channel', 'whatsapp')->first();
        expect([$wa->status, $wa->sent_count, $wa->failed_count])->toBe(['partial', 1, 1])->and(OwnerPackDelivery::where('status', 'failed')->value('error'))->toContain('RequestException');
        Http::assertSent(fn ($r) => str_contains($r['text']['body'] ?? '', 'QAR 250.00') && ! str_contains($r['text']['body'], 'Noor'));
    });
});

describe('the test to one person', function () {
    it('sends the chosen week to the person who asked, only, logs it, and never counts for the week', function () {
        Mail::fake();
        ($this->turnOn)();
        $me = staffWithRole('admin', null, ['email' => 'me@albahar.test']);

        $send = ($this->sender)()->sendTest($me, '2026-W46');

        Mail::assertSent(OwnerPackMail::class, fn ($m) => $m->hasTo('me@albahar.test') && count($m->to) === 1);
        Mail::assertSent(OwnerPackMail::class, 1);
        expect([$send->kind, $send->status, $send->recipients_count, $send->triggered_by])->toBe(['test', 'sent', 1, $me->id])->and(AuditLog::where('action', 'owner_pack.test_sent')->count())->toBe(1);
        $this->travelTo(($this->monday)());
        ($this->sender)()->sendDue();
        Mail::assertSent(OwnerPackMail::class, 3);                                          // the real pack still went to both owners
    });

    it('is limited to a few a minute-window per person, needs an address and a mailer that can send, and refuses a made-up week', function () {
        Mail::fake();
        RateLimiter::clear('owner-pack-test:x');
        $me = staffWithRole('admin', null, ['email' => 'me@albahar.test']);
        foreach (range(1, OwnerPackSender::TEST_LIMIT) as $i) {
            ($this->sender)()->sendTest($me, '2026-W46');
        }
        expect(fn () => ($this->sender)()->sendTest($me, '2026-W46'))->toThrow(DomainException::class);

        $noMail = staffWithRole('admin', null, ['email' => null]);
        expect(fn () => ($this->sender)()->sendTest($noMail, '2026-W46'))->toThrow(DomainException::class);
        expect(fn () => ($this->sender)()->sendTest(staffWithRole('admin', null, ['email' => 'b@albahar.test']), '2026-W99'))->toThrow(DomainException::class);
        ($this->channels)(['email' => false, 'whatsapp' => false]);
        expect(fn () => ($this->sender)()->sendTest(staffWithRole('admin', null, ['email' => 'c@albahar.test']), '2026-W46'))->toThrow(DomainException::class);
    });
});

describe('what leaves the dashboard', function () {
    it('holds figures and product names only: no customer, no phone, no e-mail, no order number', function () {
        $w = $this->world;
        $w->customer->update(['name' => 'Fatima Al-Kuwari', 'phone' => '+97455123999', 'email' => 'fatima.private@example.com']);
        $order = onOrder([[$w->a, '600.00']], ['user_id' => $w->customer->id, 'customer_name' => 'Fatima Al-Kuwari', 'customer_phone' => '+97455123999',
            'customer_email' => 'fatima.private@example.com', 'placed_at' => ($this->at)('2026-11-11 12:00')->utc()]);
        Mail::fake();
        ($this->turnOn)(['owner@albahar.test']);
        $this->travelTo(($this->monday)());

        ($this->sender)()->sendDue();

        Mail::assertSent(OwnerPackMail::class, function (OwnerPackMail $m) use ($order) {
            $html = $m->render();
            foreach ([$m->text, $html, json_encode($m->pack, JSON_UNESCAPED_UNICODE)] as $body) {
                foreach (['Fatima', 'Kuwari', '55123999', 'fatima.private', $order->number] as $private) {
                    expect($body)->not->toContain($private);
                }
            }

            return str_contains($html, 'QAR 850.00') && str_contains($html, 'dir="ltr"') && str_contains($m->text, 'Weekly owner pack');
        });
    });

    it('sends in the owner\'s language: Arabic right to left with a plain-text part', function () {
        Mail::fake();
        ($this->turnOn)(['owner@albahar.test'], ['owner_pack.language' => 'ar']);
        $this->travelTo(($this->monday)());

        ($this->sender)()->sendDue();

        Mail::assertSent(OwnerPackMail::class, function (OwnerPackMail $m) {
            return $m->subjectLine === 'حزمة المالك الأسبوعية، الأسبوع 46' && str_contains($m->render(), 'dir="rtl"') && str_contains($m->text, 'حزمة المالك الأسبوعية') && OwnerPackSend::first()->language === 'ar';
        });
    });
});

describe('the scheduler', function () {
    it('runs the command every five minutes and the customer figures every half hour, and shows both on the system health card', function () {
        $events = collect(app(Schedule::class)->events())->mapWithKeys(fn ($e) => [preg_match('/artisan[\'"]?\s+([A-Za-z0-9:_\-]+)/', (string) $e->command, $m) ? $m[1] : '' => $e->expression]);

        expect($events['reports:send-owner-pack'])->toBe('*/5 * * * *')->and($events['customers:refresh-value'])->toBe('*/30 * * * *')
            ->and(SchedulerHeartbeat::TASKS)->toHaveKeys(['reports:send-owner-pack', 'customers:refresh-value'])
            ->and(__('owner_numbers.task.reports:send-owner-pack'))->not->toBe('owner_numbers.task.reports:send-owner-pack')
            ->and(__('owner_numbers.task.customers:refresh-value'))->not->toBe('owner_numbers.task.customers:refresh-value');
        expect(Artisan::call('customers:refresh-value'))->toBe(0);
    });
});
