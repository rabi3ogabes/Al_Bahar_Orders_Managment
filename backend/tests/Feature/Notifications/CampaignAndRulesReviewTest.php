<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Identity\Models\User;
use App\Domain\Marketing\Services\CampaignService;
use App\Domain\Notifications\Models\MarketingCampaign;
use App\Domain\Notifications\Models\Notification;
use App\Domain\Notifications\Services\NotificationRules;
use App\Livewire\Admin\Marketing\CampaignIndex;
use App\Support\BusinessTime;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * Review fixes for campaigns and the notification controls: a campaign is "sent" only when something went out, a scheduled one that came due
 * too long ago is not sent late, and a switch applies at once even when a reader was already busy reading the old rules.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Cache::flush();
    $this->rules = app(NotificationRules::class);
    $this->svc = app(CampaignService::class);
    $this->audience = User::factory()->count(3)->create(['marketing_consent_at' => now()]);
});

function rvCampaign(array $attrs = []): MarketingCampaign
{
    return MarketingCampaign::create($attrs + ['name' => 'Eid', 'title_en' => 'Eid sale', 'title_ar' => 'تخفيضات العيد', 'body_en' => 'Up to 30% off', 'body_ar' => 'خصم حتى ٣٠٪',
        'deep_link' => null, 'channels' => ['in_app'], 'status' => 'draft', 'segment' => []]);
}

describe('11 · a campaign is sent only when something went out', function () {
    it('keeps its status and reports nothing sent when the master switch is on but every channel is off', function () {
        $draft = rvCampaign();
        $scheduled = rvCampaign(['name' => 'Later', 'status' => 'scheduled', 'scheduled_at' => now()->subHour()]);
        $this->rules->set('campaign', true, ['in_app' => false, 'push' => false, 'sms' => false, 'whatsapp' => false, 'email' => false]);

        expect($this->svc->send($draft))->toBe(0)->and($draft->fresh()->status)->toBe('draft')->and($draft->fresh()->recipients_count)->toBe(0)->and($draft->fresh()->sent_at)->toBeNull()
            ->and($this->svc->send($scheduled))->toBe(0)->and($scheduled->fresh()->status)->toBe('scheduled')
            ->and(Notification::where('event', 'campaign')->count())->toBe(0);

        // the admin is told, and nothing is audited as "sent"
        $this->actingAs(staffWithRole('marketing_manager'));
        Livewire::test(CampaignIndex::class)->call('sendNow', $draft->id)->assertDispatched('toast', type: 'error', message: __('marketing_admin.campaign_nothing_sent'));
        expect($draft->fresh()->status)->toBe('draft')->and(AuditLog::where('action', 'marketing.campaign_sent')->exists())->toBeFalse();

        // with a channel back on it goes out and counts the customers it reached
        $this->rules->set('campaign', true, ['in_app' => true, 'push' => false, 'sms' => false, 'whatsapp' => false, 'email' => false]);
        expect($this->svc->send($draft->fresh()))->toBe(3)->and($draft->fresh()->status)->toBe('sent')->and($draft->fresh()->recipients_count)->toBe(3)
            ->and(Notification::where('event', 'campaign')->count())->toBe(3);
    });

    it('counts only the customers it reached, not everyone in the audience', function () {
        $campaign = rvCampaign();
        // one customer opted out of marketing messages on every channel
        $quiet = $this->audience->first();
        DB::table('notification_preferences')->insert(collect(['push', 'in_app', 'sms', 'whatsapp', 'email'])->map(fn ($ch) => [
            'id' => (string) Str::uuid(), 'user_id' => $quiet->id, 'category' => 'marketing', 'channel' => $ch, 'is_enabled' => false, 'created_at' => now(), 'updated_at' => now(),
        ])->all());
        expect($this->svc->send($campaign))->toBe(2)->and($campaign->fresh()->recipients_count)->toBe(2);
    });
});

describe('11 · a scheduled campaign that came due too long ago is not sent late', function () {
    it('marks a stale one missed (cancelled, with a note and an audit record) and still sends a fresh one', function () {
        $stale = rvCampaign(['name' => 'Days old', 'status' => 'scheduled', 'scheduled_at' => now()->subDays(3)]);
        $fresh = rvCampaign(['name' => 'Fresh', 'status' => 'scheduled', 'scheduled_at' => now()->subHours(2)]);
        $future = rvCampaign(['name' => 'Tomorrow', 'status' => 'scheduled', 'scheduled_at' => now()->addDay()]);

        expect($this->svc->sendDue())->toBe(['sent' => 1, 'missed' => 1]);
        expect($stale->fresh()->status)->toBe('cancelled')->and($stale->fresh()->status_note)->toBe('missed_when_due')->and($stale->fresh()->sent_at)->toBeNull()
            ->and($fresh->fresh()->status)->toBe('sent')->and($future->fresh()->status)->toBe('scheduled');
        expect(AuditLog::where('action', 'marketing.campaign_missed')->where('auditable_id', $stale->id)->exists())->toBeTrue();
        expect(Notification::where('event', 'campaign')->count())->toBe(3); // only the fresh campaign's audience heard anything
    });

    it('does not send a days-old offer when campaign messages are switched back on', function () {
        $old = rvCampaign(['name' => 'Eid offer', 'status' => 'scheduled', 'scheduled_at' => now()->subHours(30)]);
        $recent = rvCampaign(['name' => 'Evening offer', 'status' => 'scheduled', 'scheduled_at' => now()->subHour()]);
        $this->rules->set('campaign', false);

        expect($this->svc->sendDue())->toBe(['sent' => 0, 'missed' => 1]);           // off: nothing goes out; the stale one is retired, the recent one waits
        expect($old->fresh()->status)->toBe('cancelled')->and($recent->fresh()->status)->toBe('scheduled')->and(Notification::where('event', 'campaign')->count())->toBe(0);

        $this->rules->reset('campaign');                                             // switched back on
        expect($this->svc->sendDue())->toBe(['sent' => 1, 'missed' => 0]);
        expect($recent->fresh()->status)->toBe('sent')->and($old->fresh()->status)->toBe('cancelled');
    });

    it('runs from the scheduler command and says what it did', function () {
        rvCampaign(['status' => 'scheduled', 'scheduled_at' => now()->subDays(2)]);
        $this->artisan('marketing:send-scheduled')->expectsOutput('Sent 0, missed 1')->assertSuccessful();
    });

    it('explains a missed campaign in the campaigns list', function () {
        $this->actingAs(staffWithRole('marketing_manager'));
        rvCampaign(['name' => 'Days old', 'status' => 'scheduled', 'scheduled_at' => now()->subDays(3)]);
        $this->svc->sendDue();
        $at = BusinessTime::display(MarketingCampaign::first()->scheduled_at);
        Livewire::test(CampaignIndex::class)->assertSee(__('marketing_admin.note_missed_when_due', ['at' => $at]));
    });
});

describe('14 · a switch applies at once even when a reader was already reading the old rules', function () {
    it('does not let a reader that started before the change store the old rules where new readers look', function () {
        $rules = $this->rules;
        $fired = false;
        // The reader's own SELECT of the rules is the moment the admin flips the switch (the reader already holds the old rows).
        DB::listen(function ($query) use (&$fired, $rules) {
            if (! $fired && str_starts_with(strtolower($query->sql), 'select') && str_contains($query->sql, 'notification_rules')) {
                $fired = true;
                $rules->set('campaign', false);
            }
        });

        expect($rules->enabled('campaign'))->toBeTrue();   // that one reader still saw the old rules
        expect($fired)->toBeTrue();
        expect($rules->enabled('campaign'))->toBeFalse();  // the next reader must see the switch, not five minutes of stale rules
    });

    it('changes its cache key on every change', function () {
        $first = $this->rules->cacheKey();
        $this->rules->set('campaign', false);
        $second = $this->rules->cacheKey();
        $this->rules->reset('campaign');
        expect($second)->not->toBe($first)->and($this->rules->cacheKey())->not->toBe($second);
    });
});
