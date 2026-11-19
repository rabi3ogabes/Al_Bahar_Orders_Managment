<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Identity\Models\User;
use App\Domain\Marketing\Jobs\DeliverCampaignJob;
use App\Domain\Marketing\Services\CampaignService;
use App\Domain\Notifications\Models\MarketingCampaign;
use App\Domain\Notifications\Models\Notification;
use App\Domain\Notifications\Services\NotificationRules;
use App\Domain\Notifications\Services\NotificationService;
use App\Domain\Notifications\Services\TemplateRenderer;
use App\Domain\Settings\Services\Settings;
use App\Livewire\Admin\Marketing\CampaignIndex;
use App\Support\BusinessTime;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/**
 * Sending a campaign is claimed atomically, delivered in the background in chunks, resumable, and never messages a customer twice (item D).
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Cache::flush();
    $this->audience = User::factory()->count(5)->create(['marketing_consent_at' => now()]);
    $this->svc = app(CampaignService::class);
    $this->admin = staffWithRole('marketing_manager');
    $this->heard = fn () => Notification::where('event', 'campaign')->count();
});

function csCampaign(array $attrs = []): MarketingCampaign
{
    return MarketingCampaign::create($attrs + ['name' => 'Eid', 'title_en' => 'Eid sale', 'title_ar' => 'تخفيضات العيد', 'body_en' => 'Up to 30% off', 'body_ar' => 'خصم حتى ٣٠٪',
        'deep_link' => null, 'channels' => ['in_app'], 'status' => 'draft', 'segment' => []]);
}

/** A notification service that fails for one customer, or runs something after a number of customers. */
function csFakeNotify(?string $failFor = null, ?Closure $after = null, int $afterCount = 0): void
{
    app()->bind(NotificationService::class, fn () => new class(app(TemplateRenderer::class), app(NotificationRules::class), $failFor, $after, $afterCount) extends NotificationService
    {
        private int $count = 0;

        public function __construct(TemplateRenderer $t, NotificationRules $r, private readonly ?string $failFor, private readonly ?Closure $after, private readonly int $afterCount)
        {
            parent::__construct($t, $r);
        }

        public function notify(User $user, string $event, array $data = [], ?array $channels = null, ?string $campaignId = null): array
        {
            if ($this->failFor === $user->id) {
                throw new RuntimeException('provider down');
            }
            $out = parent::notify($user, $event, $data, $channels, $campaignId);
            if ($this->after !== null && ++$this->count === $this->afterCount) {
                ($this->after)();
            }

            return $out;
        }
    });
    app()->forgetInstance(CampaignService::class);
}

function csSmallChunks(int $size): void
{
    app()->bind(CampaignService::class, fn () => new class(app(NotificationService::class), app(Settings::class), $size) extends CampaignService
    {
        public function __construct(NotificationService $n, Settings $s, int $size)
        {
            parent::__construct($n, $s);
            $this->chunk = $size;
        }
    });
}

describe('claiming a campaign', function () {
    it('lets exactly one of two people who press Send now at the same moment start it', function () {
        Queue::fake();
        $c = csCampaign();
        // two screens that both read the campaign as a draft before either pressed the button
        $first = MarketingCampaign::find($c->id);
        $second = MarketingCampaign::find($c->id);

        $a = $this->svc->start($first, $this->admin);
        $b = $this->svc->start($second, staffWithRole('marketing_manager'));

        expect([$a, $b])->toBe([CampaignService::STARTED, CampaignService::NOT_SENDABLE]);
        Queue::assertPushed(DeliverCampaignJob::class, 1);
        expect($c->fresh()->status)->toBe('sending')->and($c->fresh()->started_by)->toBe($this->admin->id);
    });

    it('does not let Send now race the scheduler: whoever comes second finds it taken', function () {
        Queue::fake();
        $c = csCampaign(['status' => 'scheduled', 'scheduled_at' => now()->subMinute()]);
        $stale = MarketingCampaign::find($c->id);                             // what the page read before the scheduler ran

        $this->svc->sendDue();
        expect($c->fresh()->status)->toBe('sending')->and($this->svc->start($stale, $this->admin))->toBe(CampaignService::NOT_SENDABLE);
        Queue::assertPushed(DeliverCampaignJob::class, 1);
    });

    it('messages every customer once when two starts overlap', function () {
        $c = csCampaign();
        $copy = MarketingCampaign::find($c->id);

        $this->svc->start($c, $this->admin);                                  // (the test queue runs the job straight away)
        $this->svc->start($copy, $this->admin);

        expect(($this->heard)())->toBe(5)->and($c->fresh()->status)->toBe('sent')->and($c->fresh()->recipients_count)->toBe(5);
    });
});

describe('delivery in the background', function () {
    it('returns at once from Send now and delivers when the worker runs the job', function () {
        Queue::fake();
        $this->actingAs($this->admin);
        $c = csCampaign();

        Livewire::test(CampaignIndex::class)->call('sendNow', $c->id)->assertDispatched('toast', type: 'success', message: __('owner_numbers.campaign_started'))
            ->assertSee(__('owner_numbers.campaign_progress_queued'));

        expect($c->fresh()->status)->toBe('sending')->and($c->fresh()->status_note)->toBe('queued')->and(($this->heard)())->toBe(0);   // nobody messaged inside the web request
        Queue::assertPushed(DeliverCampaignJob::class, fn ($j) => $j->campaignId === $c->id && $j->queue === 'notifications');

        (new DeliverCampaignJob($c->id))->handle($this->svc);                                                                            // the worker
        $done = $c->fresh();
        expect($done->status)->toBe('sent')->and($done->recipients_count)->toBe(5)->and($done->processed_count)->toBe(5)->and($done->sent_at)->not->toBeNull()
            ->and(($this->heard)())->toBe(5);
    });

    it('shows a campaign that is going out as sending, with its progress, and says so again once it has finished', function () {
        $this->actingAs($this->admin);
        $c = csCampaign(['status' => 'sending', 'status_note' => 'delivering', 'audience_total' => 40, 'processed_count' => 10, 'recipients_count' => 9]);

        Livewire::test(CampaignIndex::class)->assertSee(__('owner_numbers.campaign_progress', ['done' => 10, 'total' => 40, 'reached' => 9]))->assertSeeHtml('wire:poll.5s')
            ->assertDontSeeHtml("wire:click=\"sendNow('{$c->id}')\"");      // nobody can start it again from the page

        $c->update(['status' => 'sent', 'sent_at' => now(), 'recipients_count' => 38]);
        Livewire::test(CampaignIndex::class)->assertDontSeeHtml('wire:poll.5s')->assertSee(__('marketing_admin.sent_to', ['count' => 38, 'at' => BusinessTime::display($c->fresh()->sent_at)]));
    });

    it('tells a second admin that the campaign is already being sent', function () {
        Queue::fake();
        $this->actingAs($this->admin);
        $c = csCampaign();
        $page = Livewire::test(CampaignIndex::class)->call('sendNow', $c->id);
        $page->call('sendNow', $c->id)->assertDispatched('toast', type: 'error', message: __('owner_numbers.campaign_already_sending'));
        Queue::assertPushed(DeliverCampaignJob::class, 1);
    });

    it('does not turn a campaign that began sending back into a draft when a stale editor is saved, nor cancel it underneath its delivery', function () {
        Queue::fake();
        $this->actingAs($this->admin);
        $c = csCampaign();
        $editor = Livewire::test(CampaignIndex::class)->call('edit', $c->id)->set('form.name', 'Eid renamed')->set('form.when', 'draft');

        $this->svc->start($c, $this->admin);                                  // somebody else sends it while the editor is open
        $editor->call('save')->assertDispatched('toast', type: 'error', message: __('owner_numbers.campaign_already_sending'));
        expect($c->fresh()->status)->toBe('sending')->and($c->fresh()->name)->toBe('Eid');

        Livewire::test(CampaignIndex::class)->call('cancel', $c->id)->assertStatus(409);
        expect($c->fresh()->status)->toBe('sending');
    });
});

describe('resumable and never twice', function () {
    it('carries on where it stopped after a time budget and finishes without messaging anybody twice', function () {
        csSmallChunks(2);
        $c = csCampaign();
        $svc = app(CampaignService::class);
        Queue::fake();
        $svc->start($c, $this->admin);

        expect($svc->deliver($c->fresh(), 0))->toBeFalse()                    // no time left: one chunk of two customers, then it stops
            ->and($c->fresh()->status)->toBe('sending')->and($c->fresh()->processed_count)->toBe(2)->and(($this->heard)())->toBe(2);

        while (! $svc->deliver($c->fresh(), 0)) {
            // every pass handles a new chunk, never an old one
        }
        expect($c->fresh()->status)->toBe('sent')->and(($this->heard)())->toBe(5)
            ->and(DB::table('marketing_campaign_recipients')->where('campaign_id', $c->id)->count())->toBe(5)
            ->and(Notification::where('event', 'campaign')->distinct()->count('user_id'))->toBe(5);   // five customers, five messages
    });

    it('queues itself again when its time runs out, and that next job finishes the campaign', function () {
        Queue::fake();
        csSmallChunks(2);
        $c = csCampaign();
        $svc = app(CampaignService::class);
        $svc->start($c, $this->admin);                                       // job 1 is queued

        (new DeliverCampaignJob($c->id, 0))->handle($svc);                   // job 1 runs with no time left: one chunk, then it hands over
        expect($c->fresh()->status)->toBe('sending')->and($c->fresh()->processed_count)->toBe(2)->and(($this->heard)())->toBe(2);
        Queue::assertPushed(DeliverCampaignJob::class, 2);                    // the start, and the continuation

        (new DeliverCampaignJob($c->id))->handle($svc);                      // job 2 has the full budget
        expect($c->fresh()->status)->toBe('sent')->and(($this->heard)())->toBe(5);
        Queue::assertPushed(DeliverCampaignJob::class, 2);                    // and does not queue a third
    });

    it('skips customers that an earlier, interrupted run already messaged', function () {
        $c = csCampaign(['status' => 'sending', 'audience_total' => 5, 'status_note' => 'delivering']);
        foreach ($this->audience->take(2) as $user) {                          // these two were handled before the worker was killed
            DB::table('marketing_campaign_recipients')->insert(['campaign_id' => $c->id, 'user_id' => $user->id, 'state' => 'sent', 'channels' => '["in_app"]', 'created_at' => now()]);
        }

        $this->svc->deliver($c, 120);

        expect(($this->heard)())->toBe(3)                                      // only the other three
            ->and($c->fresh()->status)->toBe('sent')->and($c->fresh()->recipients_count)->toBe(5)
            ->and(Notification::where('event', 'campaign')->whereIn('user_id', $this->audience->take(2)->pluck('id'))->count())->toBe(0);
    });

    it('does not message a customer that a parallel run claimed after this run had already listed them', function () {
        $c = csCampaign(['status' => 'sending', 'audience_total' => 5]);
        $user = $this->audience[0];
        $deliverTo = new ReflectionMethod(CampaignService::class, 'deliverTo');

        $deliverTo->invoke($this->svc, $c, $user);                                // the first run claims and messages them
        $deliverTo->invoke($this->svc, $c, $user);                                // the second run, which had listed them before that, finds the claim

        expect(Notification::where('event', 'campaign')->where('user_id', $user->id)->count())->toBe(1)
            ->and(DB::table('marketing_campaign_recipients')->where('campaign_id', $c->id)->where('user_id', $user->id)->count())->toBe(1);
    });

    it('is safe to run the same delivery again after it finished', function () {
        $c = csCampaign();
        $this->svc->send($c);
        expect(($this->heard)())->toBe(5);

        $this->svc->deliver($c->fresh(), 120);
        (new DeliverCampaignJob($c->id))->handle($this->svc);
        $this->svc->sendDue();

        expect(($this->heard)())->toBe(5)->and($c->fresh()->status)->toBe('sent')->and(AuditLog::where('action', 'marketing.campaign_sent')->count())->toBe(1);
    });

    it('picks a sending campaign up again after 30 minutes without progress, and leaves a busy one alone', function () {
        $stuck = csCampaign(['status' => 'sending', 'audience_total' => 5, 'status_note' => 'delivering']);
        $busy = csCampaign(['name' => 'Busy', 'status' => 'sending', 'audience_total' => 5, 'status_note' => 'delivering']);
        DB::table('marketing_campaign_recipients')->insert(['campaign_id' => $stuck->id, 'user_id' => $this->audience[0]->id, 'state' => 'sent', 'channels' => '["in_app"]', 'created_at' => now()]);
        DB::table('marketing_campaigns')->where('id', $stuck->id)->update(['updated_at' => now()->subMinutes(31)]);
        DB::table('marketing_campaigns')->where('id', $busy->id)->update(['updated_at' => now()->subMinutes(5)]);
        Queue::fake();

        expect($this->svc->sendDue())->toBe(['sent' => 1, 'missed' => 0]);
        Queue::assertPushed(DeliverCampaignJob::class, fn ($j) => $j->campaignId === $stuck->id);
        Queue::assertNotPushed(DeliverCampaignJob::class, fn ($j) => $j->campaignId === $busy->id);
        expect($stuck->fresh()->status_note)->toBe('resumed');
        // a second scheduler tick a minute later does not take it over again
        expect($this->svc->sendDue())->toBe(['sent' => 0, 'missed' => 0]);

        Queue::fake([]);
        app()->forgetInstance('queue');
        (new DeliverCampaignJob($stuck->id))->handle($this->svc);
        expect($stuck->fresh()->status)->toBe('sent')->and($stuck->fresh()->recipients_count)->toBe(5)->and(($this->heard)())->toBe(4);   // the customer reached before the stop is not messaged again
    });
});

describe('what the campaign ends as', function () {
    it('ends partly sent, with the counts, when some customers’ messages fail, and keeps going for the rest', function () {
        $broken = $this->audience[2];
        csFakeNotify(failFor: $broken->id);
        $c = csCampaign();

        app(CampaignService::class)->send($c, $this->admin);

        $done = $c->fresh();
        expect($done->status)->toBe('partly_sent')->and($done->recipients_count)->toBe(4)->and($done->failed_count)->toBe(1)->and($done->processed_count)->toBe(5)
            ->and($done->audience_total)->toBe(5)->and($done->status_note)->toBe('some_not_sent')->and(($this->heard)())->toBe(4)
            ->and(DB::table('marketing_campaign_recipients')->where('campaign_id', $c->id)->where('user_id', $broken->id)->value('state'))->toBe('failed');
        $this->actingAs($this->admin);
        Livewire::test(CampaignIndex::class)->assertSee(__('owner_numbers.campaign_partly', ['reached' => 4, 'total' => 5, 'failed' => 1]))->assertSee(__('owner_numbers.campaign_status_partly_sent'));
    });

    it('stops where it is when campaign messages are switched off part-way, and says so', function () {
        csSmallChunks(2);
        csFakeNotify(after: fn () => app(NotificationRules::class)->set('campaign', false), afterCount: 2);
        $c = csCampaign();

        app(CampaignService::class)->send($c, $this->admin);

        $done = $c->fresh();
        expect($done->status)->toBe('partly_sent')->and($done->status_note)->toBe('switched_off')->and($done->recipients_count)->toBe(2)->and(($this->heard)())->toBe(2);
    });

    it('goes back to what it was, with the reason, when nobody could be reached, and can be sent again later', function () {
        $draft = csCampaign();
        $scheduled = csCampaign(['name' => 'Later', 'status' => 'scheduled', 'scheduled_at' => now()->addHour()]);
        app(NotificationRules::class)->set('campaign', true, ['in_app' => false, 'push' => false, 'sms' => false, 'whatsapp' => false, 'email' => false]);
        $scheduled->update(['scheduled_at' => now()->subMinute()]);

        expect($this->svc->send($draft))->toBe(0)->and($draft->fresh()->status)->toBe('draft')->and($draft->fresh()->status_note)->toBe('nothing_reached')
            ->and($draft->fresh()->recipients_count)->toBe(0)->and($draft->fresh()->audience_total)->toBeNull()
            ->and(DB::table('marketing_campaign_recipients')->where('campaign_id', $draft->id)->count())->toBe(0)
            ->and($this->svc->send($scheduled))->toBe(0)->and($scheduled->fresh()->status)->toBe('scheduled')->and(AuditLog::where('action', 'marketing.campaign_sent')->exists())->toBeFalse();

        app(NotificationRules::class)->set('campaign', true, ['in_app' => true, 'push' => false, 'sms' => false, 'whatsapp' => false, 'email' => false]);
        expect($this->svc->send($draft->fresh()))->toBe(5)->and($draft->fresh()->status)->toBe('sent')->and($draft->fresh()->status_note)->toBeNull();
    });
});

describe('the audit record', function () {
    it('is written for a campaign sent by the scheduler too, as the system', function () {
        $c = csCampaign(['status' => 'scheduled', 'scheduled_at' => now()->subHour()]);

        $this->svc->sendDue();

        $log = AuditLog::where('action', 'marketing.campaign_sent')->where('auditable_id', $c->id)->firstOrFail();
        expect($log->actor_id)->toBeNull()->and($log->actor_type)->toBe('system')->and($log->new_values['recipients'])->toBe(5)->and($log->metadata['via'])->toBe('scheduler');
    });

    it('is written for Send now under the name of the person who pressed it, once, when the sending ends', function () {
        $this->actingAs($this->admin);
        $c = csCampaign();

        Livewire::test(CampaignIndex::class)->call('sendNow', $c->id)->assertDispatched('toast', type: 'success');

        $logs = AuditLog::where('action', 'marketing.campaign_sent')->where('auditable_id', $c->id)->get();
        expect($logs)->toHaveCount(1)->and($logs[0]->actor_id)->toBe($this->admin->id)->and($logs[0]->metadata['via'])->toBe('dashboard');
    });

    it('is not written while the campaign is still going out', function () {
        Queue::fake();
        $this->actingAs($this->admin);
        $c = csCampaign();
        Livewire::test(CampaignIndex::class)->call('sendNow', $c->id);
        expect(AuditLog::where('action', 'marketing.campaign_sent')->exists())->toBeFalse();
    });
});
